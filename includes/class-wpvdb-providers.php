<?php
namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Provider registry for WPVDB
 *
 * Centralized registry of embedding providers with their capabilities and metadata
 */
class Providers {
	private const AUTOMATTIC_AI_PROXY_HOST        = 'public-api.wordpress.com';
	private const AUTOMATTIC_AI_PROXY_PATH_PREFIX = '/wpcom/v2/ai-api-proxy/v1/';
	private const AUTOMATTIC_AI_PROXY_API_BASE    = 'https://' . self::AUTOMATTIC_AI_PROXY_HOST . self::AUTOMATTIC_AI_PROXY_PATH_PREFIX;

	/**
	 * Get all registered providers
	 *
	 * @return array Providers with their details
	 */
	public static function get_available_providers() {
		$default_providers = array(
			'openai'     => array(
				'name'             => 'openai',
				'label'            => 'OpenAI',
				'api_base'         => 'https://api.openai.com/v1/',
				'api_key_constant' => 'WPVDB_OPENAI_API_KEY',
				'description'      => __( 'OpenAI provides state-of-the-art embedding models like text-embedding-3-small.', 'wpvdb' ),
			),
			'automattic' => array(
				'name'             => 'automattic',
				'label'            => 'Automattic AI',
				'api_base'         => self::AUTOMATTIC_AI_PROXY_API_BASE,
				'api_key_constant' => 'WPVDB_AUTOMATTIC_API_KEY',
				'description'      => __( 'Automattic AI offers embedding models optimized for WordPress content.', 'wpvdb' ),
			),
			'specter'    => array(
				'name'             => 'specter',
				'label'            => 'SPECTER',
				'api_base'         => 'http://localhost:8000/v1/',
				'api_key_constant' => '',  // No API key needed for local server.
				'description'      => __( 'SPECTER2 is a research model for scientific document embeddings, running locally.', 'wpvdb' ),
			),
		);

		// Allow plugins to register additional providers.
		return apply_filters( 'wpvdb_available_providers', $default_providers );
	}

	/**
	 * Get a specific provider by name
	 *
	 * @param string $provider_name Provider name.
	 * @return array|null Provider details or null if not found
	 */
	public static function get_provider( $provider_name ) {
		$providers = self::get_available_providers();
		return isset( $providers[ $provider_name ] ) ? $providers[ $provider_name ] : null;
	}

	/**
	 * Get provider name by its label
	 *
	 * @param string $label Provider label.
	 * @return string|null Provider name or null if not found
	 */
	public static function get_provider_by_label( $label ) {
		$providers = self::get_available_providers();
		foreach ( $providers as $name => $provider ) {
			if ( isset( $provider['label'] ) && $provider['label'] === $label ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Get API base URL for a provider
	 *
	 * @param string $provider_name Provider name.
	 * @return string API base URL or empty string if not found
	 */
	public static function get_api_base( $provider_name ) {
		$provider = self::get_provider( $provider_name );
		return $provider && isset( $provider['api_base'] ) ? $provider['api_base'] : '';
	}

	/**
	 * Check whether a URL points at the Automattic AI proxy.
	 *
	 * @param string $url URL to check.
	 * @return bool Whether the URL uses the Automattic AI proxy endpoint
	 */
	public static function is_automattic_ai_proxy_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return false;
		}

		$proxy_base_path = rtrim( self::AUTOMATTIC_AI_PROXY_PATH_PREFIX, '/' );

		return strtolower( $parts['scheme'] ?? '' ) === 'https'
			&& strtolower( $parts['host'] ) === self::AUTOMATTIC_AI_PROXY_HOST
			&& (
				$parts['path'] === $proxy_base_path
				|| strpos( $parts['path'], self::AUTOMATTIC_AI_PROXY_PATH_PREFIX ) === 0
			);
	}
}
