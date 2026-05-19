<?php
/**
 * Security utilities for WPVDB.
 *
 * @package WPVDB
 */

namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Security utilities for WPVDB
 */
class Security {

	/**
	 * Rate limiting cache key prefix
	 */
	const RATE_LIMIT_PREFIX = 'wpvdb_rate_limit_';

	/**
	 * Default rate limits (requests per minute)
	 */
	const DEFAULT_LIMITS = array(
		'embed'   => 60,    // 60 embedding requests per minute
		'query'   => 120,   // 120 queries per minute
		'vectors' => 60,  // 60 vector inserts per minute
	);

	/**
	 * Check rate limit for current user/IP
	 *
	 * @param string $endpoint Endpoint name (embed, query, vectors).
	 * @param int    $limit Custom limit override.
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited
	 */
	public static function check_rate_limit( $endpoint, $limit = null ) {
		$endpoint = sanitize_key( $endpoint );

		// Get limit for this endpoint.
		if ( null === $limit ) {
			$limit = isset( self::DEFAULT_LIMITS[ $endpoint ] ) ? self::DEFAULT_LIMITS[ $endpoint ] : 60;
		}

		// Apply filter to allow customization.
		$limit = apply_filters( 'wpvdb_rate_limit', $limit, $endpoint );

		// Skip rate limiting if disabled.
		if ( $limit <= 0 ) {
			return true;
		}

		// Get identifier for rate limiting (user ID or IP).
		$identifier = self::get_rate_limit_identifier();
		$cache_key  = self::RATE_LIMIT_PREFIX . $endpoint . '_' . $identifier;

		// Get current count.
		$current_count = get_transient( $cache_key );

		if ( false === $current_count ) {
			// First request in this minute.
			set_transient( $cache_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $limit ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					__( 'Rate limit exceeded. Maximum %1$d requests per minute allowed for %2$s endpoint.', 'wpvdb' ),
					$limit,
					$endpoint
				),
				array( 'status' => 429 )
			);
		}

		// Increment counter.
		set_transient( $cache_key, $current_count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Get identifier for rate limiting
	 *
	 * @return string
	 */
	private static function get_rate_limit_identifier() {
		// Use user ID if logged in.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user_' . $user_id;
		}

		// Fall back to IP address (with privacy considerations).
		$ip = self::get_client_ip();
		return 'ip_' . hash( 'sha256', $ip . wp_salt( 'nonce' ) );
	}

	/**
	 * Get client IP address safely
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		// Check for IP from headers (be cautious of spoofing).
		$ip_headers = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $ip_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $header ] );

				// Handle comma-separated IPs (X-Forwarded-For).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				// Validate IP.
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0'; // Fallback.
	}

	/**
	 * Validate nonce for admin requests
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $action Nonce action.
	 * @return bool|\WP_Error
	 */
	public static function verify_nonce( $request, $action = 'wpvdb_nonce' ) {
		// Skip nonce check for non-admin requests if auth is disabled.
		$require_auth = get_option( 'wpvdb_require_auth', 1 );
		if ( empty( $require_auth ) && ! is_admin() ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			// Try to get from query param as fallback.
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $action ) ) {
			return new \WP_Error(
				'invalid_nonce',
				__( 'Security check failed. Please refresh the page and try again.', 'wpvdb' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Log security events
	 *
	 * @param string $event Event type.
	 * @param array  $data Event data.
	 */
	public static function log_security_event( $event, $data = array() ) {
		$log_data = array(
			'event'      => $event,
			'timestamp'  => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
			'ip'         => self::get_client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'data'       => $data,
		);

		// Log to WordPress error log if debug is enabled.
		if ( \wpvdb_should_log_to_error_log( 'debug', 'security_event', $log_data ) ) {
			Logger::debug( 'Security event', $log_data );
		}

		// Allow plugins to hook into security logging.
		do_action( 'wpvdb_security_event', $event, $log_data );
	}

	/**
	 * Sanitize and validate vector embedding array
	 *
	 * @param array $embedding Raw embedding array.
	 * @param int   $max_dimensions Maximum allowed dimensions.
	 * @return array|\WP_Error Sanitized embedding or error
	 */
	public static function validate_embedding( $embedding, $max_dimensions = 8192 ) {
		if ( ! is_array( $embedding ) ) {
			return new \WP_Error( 'invalid_embedding', __( 'Embedding must be an array', 'wpvdb' ), array( 'status' => 400 ) );
		}

		if ( empty( $embedding ) ) {
			return new \WP_Error( 'empty_embedding', __( 'Embedding array cannot be empty', 'wpvdb' ), array( 'status' => 400 ) );
		}

		if ( count( $embedding ) > $max_dimensions ) {
			return new \WP_Error( 'embedding_too_large', sprintf( __( 'Embedding has too many dimensions. Maximum %d allowed.', 'wpvdb' ), $max_dimensions ), array( 'status' => 400 ) );
		}

		$sanitized = array();
		foreach ( $embedding as $i => $value ) {
			if ( ! is_numeric( $value ) ) {
				return new \WP_Error( 'invalid_embedding_value', sprintf( __( 'Embedding value at index %d is not numeric', 'wpvdb' ), $i ), array( 'status' => 400 ) );
			}

			$float_val = floatval( $value );

			// Check for valid float (not NaN or infinite).
			if ( ! is_finite( $float_val ) ) {
				return new \WP_Error( 'invalid_embedding_value', sprintf( __( 'Embedding value at index %d is not a valid number', 'wpvdb' ), $i ), array( 'status' => 400 ) );
			}

			$sanitized[] = $float_val;
		}

		return $sanitized;
	}

	/**
	 * Check if current user can manage WPVDB
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Escape SQL for vector database operations
	 * Additional escaping for vector-specific SQL
	 *
	 * @param string $value Value to escape.
	 * @return string Escaped value
	 */
	public static function escape_vector_sql( $value ) {
		// Use WordPress's esc_sql and add additional vector-specific escaping.
		$escaped = esc_sql( $value );

		// Remove any potential SQL injection patterns specific to vector operations.
		$escaped = preg_replace( '/[\'";\\\\]/', '', $escaped );

		return $escaped;
	}
}
