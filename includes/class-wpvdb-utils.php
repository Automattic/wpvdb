<?php
namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Utility functions for WPVDB
 *
 * Provides common utility functions that follow WordPress coding standards
 * and best practices.
 *
 * @since 1.0.13
 */
class Utils {

	/**
	 * Validate and sanitize a positive integer
	 *
	 * @since 1.0.13
	 * @param mixed $value         Value to validate.
	 * @param int   $min           Minimum allowed value.
	 * @param int   $max           Maximum allowed value.
	 * @param int   $default_value Default value if validation fails.
	 * @return int Validated integer
	 */
	public static function validate_positive_int( $value, $min = 1, $max = PHP_INT_MAX, $default_value = 1 ) {
		$int_value = absint( $value );

		if ( $int_value < $min ) {
			return $default_value;
		}

		if ( $int_value > $max ) {
			return $max;
		}

		return $int_value;
	}

	/**
	 * Validate and sanitize a floating point number
	 *
	 * @since 1.0.13
	 * @param mixed $value         Value to validate.
	 * @param float $min           Minimum allowed value.
	 * @param float $max           Maximum allowed value.
	 * @param float $default_value Default value if validation fails.
	 * @return float Validated float
	 */
	public static function validate_float( $value, $min = -PHP_FLOAT_MAX, $max = PHP_FLOAT_MAX, $default_value = 0.0 ) {
		if ( ! is_numeric( $value ) ) {
			return $default_value;
		}

		$float_value = floatval( $value );

		if ( ! is_finite( $float_value ) ) {
			return $default_value;
		}

		if ( $float_value < $min ) {
			return $default_value;
		}

		if ( $float_value > $max ) {
			return $max;
		}

		return $float_value;
	}

	/**
	 * Validate a model name against allowed patterns
	 *
	 * @since 1.0.13
	 * @param string $model_name Model name to validate.
	 * @return string|false Valid model name or false if invalid
	 */
	public static function validate_model_name( $model_name ) {
		if ( ! is_string( $model_name ) ) {
			return false;
		}

		$sanitized = sanitize_text_field( $model_name );

		// Model names should only contain letters, numbers, hyphens, and underscores.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $sanitized ) ) {
			return false;
		}

		// Reasonable length limits.
		if ( strlen( $sanitized ) < 1 || strlen( $sanitized ) > 100 ) {
			return false;
		}

		return $sanitized;
	}

	/**
	 * Validate a provider name
	 *
	 * @since 1.0.13
	 * @param string $provider_name Provider name to validate.
	 * @return string|false Valid provider name or false if invalid
	 */
	public static function validate_provider_name( $provider_name ) {
		if ( ! is_string( $provider_name ) ) {
			return false;
		}

		$sanitized = sanitize_key( $provider_name );

		// Provider names should be alphanumeric with underscores.
		if ( ! preg_match( '/^[a-z0-9_]+$/', $sanitized ) ) {
			return false;
		}

		return $sanitized;
	}

	/**
	 * Format bytes into human readable format
	 *
	 * @since 1.0.13
	 * @param int $bytes Bytes to format.
	 * @param int $precision Number of decimal places.
	 * @return string Formatted string
	 */
	public static function format_bytes( $bytes, $precision = 2 ) {
		if ( ! is_numeric( $bytes ) || $bytes < 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );

		for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
			$bytes /= 1024;
		}

		return round( $bytes, $precision ) . ' ' . $units[ $i ];
	}

	/**
	 * Get a truncated version of text for display
	 *
	 * @since 1.0.13
	 * @param string $text   Text to truncate.
	 * @param int    $length Maximum length.
	 * @param string $suffix Suffix to add if truncated.
	 * @return string Truncated text
	 */
	public static function truncate_text( $text, $length = 100, $suffix = '...' ) {
		$text = sanitize_text_field( $text );

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Check if current request is AJAX
	 *
	 * @since 1.0.13
	 * @return bool True if AJAX request
	 */
	public static function is_ajax() {
		return wp_doing_ajax();
	}

	/**
	 * Check if current request is REST API
	 *
	 * @since 1.0.13
	 * @return bool True if REST API request
	 */
	public static function is_rest() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Check if current request is admin
	 *
	 * @since 1.0.13
	 * @return bool True if admin request
	 */
	public static function is_admin() {
		return is_admin() && ! self::is_ajax();
	}

	/**
	 * Generate a secure hash for caching keys
	 *
	 * @since 1.0.13
	 * @param string $data Data to hash.
	 * @return string Hash string
	 */
	public static function generate_cache_key( $data ) {
		return hash( 'sha256', $data . wp_salt( 'nonce' ) );
	}

	/**
	 * Validate URL format
	 *
	 * @since 1.0.13
	 * @param string $url URL to validate.
	 * @return string|false Valid URL or false if invalid
	 */
	public static function validate_url( $url ) {
		$url = esc_url_raw( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Only allow HTTP and HTTPS.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		return $url;
	}

	/**
	 * Get WordPress timezone string
	 *
	 * @since 1.0.13
	 * @return string Timezone string
	 */
	public static function get_timezone_string() {
		$timezone_string = get_option( 'timezone_string' );

		if ( $timezone_string ) {
			return $timezone_string;
		}

		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = ( $offset - $hours );

		$sign     = ( $offset < 0 ) ? '-' : '+';
		$abs_hour = abs( $hours );
		$abs_mins = abs( $minutes * 60 );

		return sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );
	}

	/**
	 * Check if a WordPress feature is available
	 *
	 * @since 1.0.13
	 * @param string $feature Feature to check.
	 * @return bool True if feature is available
	 */
	public static function has_feature( $feature ) {
		switch ( $feature ) {
			case 'application_passwords':
				return function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

			case 'action_scheduler':
				return function_exists( 'as_schedule_single_action' );

			case 'object_cache':
				return wp_using_ext_object_cache();

			default:
				return apply_filters( 'wpvdb_has_feature', false, $feature );
		}
	}

	/**
	 * Get plugin information
	 *
	 * @since 1.0.13
	 * @return array Plugin information
	 */
	public static function get_plugin_info() {
		return array(
			'name'        => 'WPVDB - WordPress Vector Database',
			'version'     => WPVDB_VERSION,
			'dir'         => WPVDB_PLUGIN_DIR,
			'url'         => WPVDB_PLUGIN_URL,
			'file'        => WPVDB_PLUGIN_FILE,
			'text_domain' => 'wpvdb',
		);
	}

	/**
	 * Check minimum WordPress and PHP requirements
	 *
	 * @since 1.0.13
	 * @return array Array with 'wp' and 'php' keys indicating if requirements are met
	 */
	public static function check_requirements() {
		global $wp_version;

		return array(
			'wp'  => version_compare( $wp_version, '6.0', '>=' ),
			'php' => version_compare( PHP_VERSION, '7.4', '>=' ),
		);
	}

	/**
	 * Escape output for HTML context with proper encoding
	 *
	 * @since 1.0.13
	 * @param string $text Text to escape.
	 * @return string Escaped text
	 */
	public static function esc_html( $text ) {
		return esc_html( $text );
	}

	/**
	 * Escape output for HTML attribute context
	 *
	 * @since 1.0.13
	 * @param string $text Text to escape.
	 * @return string Escaped text
	 */
	public static function esc_attr( $text ) {
		return esc_attr( $text );
	}
}

// Note: the global helper `wpvdb_has_action_scheduler()` lives in the main
// bootstrap (wpvdb.php) because it must be in the global namespace. Defining
// it here (inside `namespace WPVDB;`) would put it in `WPVDB\` and callers
// using unqualified `function_exists('wpvdb_has_action_scheduler')` would
// never find it, silently forcing the queue into its wp_options fallback.
