<?php
/**
 * Embedding model registry for WPVDB.
 *
 * @package WPVDB
 */

namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Model registry for WPVDB
 *
 * Centralized registry of embedding models with their capabilities and metadata
 */
class Models {
	/**
	 * Get all registered embedding models
	 *
	 * @return array Array of models by provider
	 */
	public static function get_available_models() {
		$default_models = array(
			'openai'     => array(
				'text-embedding-3-small' => array(
					'label'               => 'text-embedding-3-small (1536 dimensions)',
					'dimensions'          => 1536,
					'default'             => true,
					'selectable'          => true,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'supports_dimensions' => true,
				),
				'text-embedding-3-large' => array(
					'label'               => 'text-embedding-3-large (3072 dimensions)',
					'dimensions'          => 3072,
					'selectable'          => true,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'supports_dimensions' => true,
				),
				'text-embedding-ada-002' => array(
					'label'               => 'Ada 2 (Legacy)',
					'dimensions'          => 1536,
					'selectable'          => true,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'supports_dimensions' => false,
				),
			),
			'automattic' => array(
				'nomic-embed-text-v2-moe'    => array(
					'label'               => 'Nomic Embed Text v2 MoE (768 dim, native)',
					'dimensions'          => 768,
					'default'             => true,
					'selectable'          => true,
					'endpoint'            => 'embeddings/text',
					'request_format'      => 'a8c_nomic_native',
					'response_format'     => 'a8c_nomic_native',
					'model_query_arg'     => 'model',
					'supports_dimensions' => false,
				),
				// Kept for explicit callers; hidden because it returns 256d and native storage is VECTOR(768).
				'nomic-embed-text-formatted' => array(
					'label'               => 'Nomic Embed Text Formatted (256 dim, OpenAI response)',
					'dimensions'          => 256,
					'selectable'          => false,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'query_args'          => array(
						'format' => 'openai',
					),
					'supports_dimensions' => false,
				),
				'text-embedding-3-small'     => array(
					'label'               => 'OpenAI 3 Small via Automattic proxy (configurable dim)',
					'dimensions'          => 1536,
					'selectable'          => true,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'supports_dimensions' => true,
				),
			),
			'specter'    => array(
				'specter2' => array(
					'label'               => 'SPECTER2',
					'dimensions'          => 768,
					'default'             => true,
					'selectable'          => true,
					'endpoint'            => 'embeddings',
					'request_format'      => 'openai',
					'response_format'     => 'openai',
					'supports_dimensions' => false,
				),
			),
		);

		// Allow plugins to register additional models or modify existing ones.
		return self::normalize_models( apply_filters( 'wpvdb_available_models', self::normalize_models( $default_models ) ) );
	}

	/**
	 * Get selectable models grouped by provider.
	 *
	 * Models with selectable=false remain available for request handling but
	 * are omitted from admin dropdowns. Models that cannot fit the configured
	 * embedding column are also omitted.
	 *
	 * @return array Array of selectable models by provider
	 */
	public static function get_selectable_models() {
		$models     = self::get_available_models();
		$target_dim = self::get_storage_dimension();
		foreach ( $models as $provider => $provider_models ) {
			$models[ $provider ] = array_filter(
				$provider_models,
				static function ( $model ) use ( $target_dim ) {
					return ( ! isset( $model['selectable'] ) || false !== $model['selectable'] )
						&& self::is_model_storage_compatible( $model, $target_dim );
				}
			);
		}
		return $models;
	}

	/**
	 * Get models for a specific provider
	 *
	 * @param string $provider Provider name.
	 * @param bool   $selectable_only Whether to exclude non-selectable internal models.
	 * @return array Models for the provider
	 */
	public static function get_provider_models( $provider, $selectable_only = false ) {
		$models = $selectable_only ? self::get_selectable_models() : self::get_available_models();
		return isset( $models[ $provider ] ) ? $models[ $provider ] : array();
	}

	/**
	 * Get selectable models for a specific provider.
	 *
	 * @param string $provider Provider name.
	 * @return array Selectable models for the provider
	 */
	public static function get_selectable_provider_models( $provider ) {
		return self::get_provider_models( $provider, true );
	}

	/**
	 * Get a specific model by provider and name
	 *
	 * @param string $provider Provider name.
	 * @param string $model_name Model name.
	 * @return array|null Model details or null if not found
	 */
	public static function get_model( $provider, $model_name ) {
		$provider_models = self::get_provider_models( $provider );
		return isset( $provider_models[ $model_name ] ) ? $provider_models[ $model_name ] : null;
	}

	/**
	 * Get model metadata for a request when only the model and API base are known.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return array|null Model details or null if not found
	 */
	public static function get_model_for_request( $model_name, $api_base = '' ) {
		$provider = self::guess_provider_from_api_base( $api_base );
		if ( $provider ) {
			$model = self::get_model( $provider, $model_name );
			if ( $model ) {
				return $model;
			}
			return null;
		}

		foreach ( self::get_available_models() as $provider_models ) {
			if ( isset( $provider_models[ $model_name ] ) ) {
				return $provider_models[ $model_name ];
			}
		}

		return null;
	}

	/**
	 * Get the request format for a model.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return string Request format identifier
	 */
	public static function get_request_format( $model_name, $api_base = '' ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		return isset( $model['request_format'] ) ? $model['request_format'] : 'openai';
	}

	/**
	 * Get the response format for a model.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return string Response format identifier
	 */
	public static function get_response_format( $model_name, $api_base = '' ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		return isset( $model['response_format'] ) ? $model['response_format'] : 'openai';
	}

	/**
	 * Get the relative API endpoint for a model.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return string Relative endpoint
	 */
	public static function get_endpoint( $model_name, $api_base = '' ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		return isset( $model['endpoint'] ) ? $model['endpoint'] : 'embeddings';
	}

	/**
	 * Get model-level query args for the embedding request URL.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return array Query args
	 */
	public static function get_request_query_args( $model_name, $api_base = '' ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		if ( ! $model ) {
			return array();
		}

		$query_args = isset( $model['query_args'] ) && is_array( $model['query_args'] ) ? $model['query_args'] : array();
		if ( ! empty( $model['model_query_arg'] ) ) {
			$query_args[ $model['model_query_arg'] ] = $model_name;
		}

		return $query_args;
	}

	/**
	 * Check whether a model supports a dimensions request parameter.
	 *
	 * @param string $model_name Model name.
	 * @param string $api_base API base URL.
	 * @return bool
	 */
	public static function supports_dimensions( $model_name, $api_base = '' ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		return $model && ! empty( $model['supports_dimensions'] );
	}

	/**
	 * Check whether a model can produce embeddings for the storage dimension.
	 *
	 * @param string   $model_name Model name.
	 * @param string   $api_base API base URL.
	 * @param int|null $target_dim Storage dimension, defaults to WPVDB_DEFAULT_EMBED_DIM.
	 * @return bool Whether the model can fit the configured embedding column
	 */
	public static function is_storage_compatible( $model_name, $api_base = '', $target_dim = null ) {
		$model = self::get_model_for_request( $model_name, $api_base );
		if ( ! $model ) {
			return false;
		}
		return self::is_model_storage_compatible( $model, self::get_storage_dimension( $target_dim ) );
	}

	/**
	 * Get default model for a provider
	 *
	 * @param string $provider Provider name.
	 * @return string Default model name
	 */
	public static function get_default_model_for_provider( $provider ) {
		$provider_models = self::get_selectable_provider_models( $provider );
		foreach ( $provider_models as $model_name => $model ) {
			if ( ! empty( $model['default'] ) ) {
				return $model_name;
			}
		}

		if ( ! empty( $provider_models ) ) {
			return array_key_first( $provider_models );
		}

		return '';
	}

	/**
	 * Guess provider from a known API base URL.
	 *
	 * @param string $api_base API base URL.
	 * @return string Provider name or empty string
	 */
	private static function guess_provider_from_api_base( $api_base ) {
		if ( ! is_string( $api_base ) ) {
			return '';
		}

		if ( Providers::is_automattic_ai_proxy_url( $api_base ) ) {
			return 'automattic';
		}

		if ( strpos( $api_base, 'api.openai.com' ) !== false ) {
			return 'openai';
		}

		return '';
	}

	/**
	 * Get the configured native embedding column dimension.
	 *
	 * @param int|null $target_dim Explicit dimension override.
	 * @return int Storage dimension
	 */
	private static function get_storage_dimension( $target_dim = null ) {
		if ( is_int( $target_dim ) && $target_dim > 0 ) {
			return $target_dim;
		}
		return defined( 'WPVDB_DEFAULT_EMBED_DIM' ) ? (int) WPVDB_DEFAULT_EMBED_DIM : 768;
	}

	/**
	 * Check model metadata against the storage dimension.
	 *
	 * @param array $model Model metadata.
	 * @param int   $target_dim Storage dimension.
	 * @return bool Whether the model can fit the configured embedding column
	 */
	private static function is_model_storage_compatible( $model, $target_dim ) {
		if ( ! empty( $model['supports_dimensions'] ) ) {
			return true;
		}
		return isset( $model['dimensions'] ) && (int) $model['dimensions'] === $target_dim;
	}

	/**
	 * Ensure each model has fields derived from its registry position.
	 *
	 * @param array $models Models grouped by provider.
	 * @return array Normalized models
	 */
	private static function normalize_models( $models ) {
		if ( ! is_array( $models ) ) {
			return array();
		}

		foreach ( $models as $provider => $provider_models ) {
			if ( ! is_array( $provider_models ) ) {
				$models[ $provider ] = array();
				continue;
			}

			foreach ( $provider_models as $model_name => $model ) {
				if ( ! is_array( $model ) ) {
					unset( $models[ $provider ][ $model_name ] );
					continue;
				}

				if ( ! isset( $model['name'] ) ) {
					$model['name'] = $model_name;
				}
				if ( ! isset( $model['provider'] ) ) {
					$model['provider'] = $provider;
				}

				$models[ $provider ][ $model_name ] = $model;
			}
		}

		return $models;
	}
}
