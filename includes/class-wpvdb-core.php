<?php
/**
 * Core embedding and content processing routines.
 *
 * @package WPVDB
 */

namespace WPVDB;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\ProviderImplementations\OpenAi\OpenAiProvider;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Handles core hooks, embedding requests, and default text processing.
 */
class Core {

	/**
	 * Initialize core hooks or filters.
	 */
	public function init() {
		// Show an admin notice if DB vector support is missing.
		add_action( 'admin_notices', array( $this, 'maybe_show_db_warning_notice' ) );

		// (Optional) Provide a filter for chunking text.
		add_filter( 'wpvdb_chunk_text', array( $this, 'default_chunking' ), 10, 2 );

		// Provide a filter to process or summarize chunks.
		add_filter( 'wpvdb_ai_summarize_chunk', array( $this, 'default_summary' ), 10, 2 );
	}

	/**
	 * If the DB doesn't support native vectors, show a warning (if user is admin).
	 */
	public function maybe_show_db_warning_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$warning = get_option( 'wpvdb_db_vector_support_warning', 0 );
		if ( $warning ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'WPVDB: Your database does not support native vector columns (MariaDB 11.7+ or MySQL 9+). Fallback storage is used, which may reduce performance.', 'wpvdb' );
			echo '</p></div>';
		}
	}

	/**
	 * Simple default chunking approach: split text into word-based chunks.
	 * Developers can override this via the 'wpvdb_chunk_text' filter.
	 *
	 * @since 1.0.13
	 * @param array  $chunks Existing array of chunks if any (often empty).
	 * @param string $text   The text to chunk.
	 * @return array Array of chunk strings.
	 */
	public function default_chunking( $chunks, $text ) {
		if ( ! empty( $chunks ) ) {
			// If some other filter added chunks, just return them.
			return $chunks;
		}

		// Check for null or empty text.
		if ( null === $text || '' === $text ) {
			return array();
		}

		// Ensure text is a string.
		if ( ! is_string( $text ) ) {
			if ( is_array( $text ) || is_object( $text ) ) {
				$text = json_encode( $text );
			} else {
				$text = strval( $text );
			}
		}

		// Basic approach: split on whitespace, group by word count.
		$words   = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$current = array();
		$limit   = apply_filters( 'wpvdb_default_chunk_words', 200 );
		$out     = array();

		foreach ( $words as $word ) {
			$current[] = $word;
			if ( count( $current ) >= $limit ) {
				$out[]   = implode( ' ', $current );
				$current = array();
			}
		}
		if ( ! empty( $current ) ) {
			$out[] = implode( ' ', $current );
		}
		return $out;
	}

	/**
	 * Default summarization approach using OpenAI or does nothing if no API configured.
	 * Called via filter 'wpvdb_ai_summarize_chunk'.
	 *
	 * @param string $summary existing summary if any.
	 * @param string $text    chunk text.
	 * @return string summarization
	 */
	public function default_summary( $summary, $text ) {
		if ( ! empty( $summary ) ) {
			return $summary;
		}

		// Check for null or empty text.
		if ( null === $text || '' === $text ) {
			return '';
		}

		// Ensure text is a string.
		if ( ! is_string( $text ) ) {
			if ( is_array( $text ) || is_object( $text ) ) {
				$text = json_encode( $text );
			} else {
				$text = strval( $text );
			}
		}

		// In a real environment, you might call the OpenAI Chat or Completions API to summarize.
		// For demonstration, we do a placeholder. If you want a real summary, implement it here.
		// Or remove if you don't want auto-summaries.
		return '[AI Summary placeholder]';
	}

	/**
	 * Utility function: calls the external embedding API (e.g. OpenAI) for a single chunk of text.
	 * Returns array of floats, or WP_Error on failure.
	 *
	 * @since 1.0.0
	 * @param string $text The text to embed.
	 * @param string $model The embedding model name (e.g. 'text-embedding-3-small').
	 * @param string $api_base OpenAI-compatible endpoint base URL.
	 * @param string $api_key Your embedding provider API key.
	 * @return array|WP_Error Array of float values representing the embedding, or WP_Error on failure.
	 */
	public static function get_embedding( $text, $model, $api_base, $api_key ) {
		// Check for null or empty text.
		if ( null === $text || '' === $text ) {
			return new \WP_Error( 'embedding_error', 'Empty or null text cannot be embedded.' );
		}

		$custom_options = self::get_embedding_custom_options( $model, $api_base );

		// Check cache first. A poisoned cache entry falls through to the fresh path,
		// which will overwrite it on success via Cache::set_embedding().
		$cached_embedding = Cache::get_embedding( $text, $model );
		if ( false !== $cached_embedding && is_array( $cached_embedding ) && self::is_valid_embedding( $cached_embedding ) ) {
			Logger::debug(
				'Using cached embedding',
				array(
					'text_length' => strlen( $text ),
					'model'       => $model,
				)
			);
			return $cached_embedding;
		}

		// Allow plugins to provide custom embedding generation.
		$custom_embedding = apply_filters( 'wpvdb_generate_embedding', null, $text, $model, $api_base, $api_key );
		if ( null !== $custom_embedding ) {
			if ( ! is_array( $custom_embedding ) || ! self::is_valid_embedding( $custom_embedding ) ) {
				return new \WP_Error( 'embedding_error', 'wpvdb_generate_embedding filter returned an invalid embedding.' );
			}
			Cache::set_embedding( $text, $model, $custom_embedding );
			return $custom_embedding;
		}

		// Remove newlines (as recommended in many embedding docs).
		$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', $text );

		$request_format  = Models::get_request_format( $model, $api_base );
		$response_format = Models::get_response_format( $model, $api_base );
		$endpoint        = Models::get_endpoint( $model, $api_base );
		$query_args      = Models::get_request_query_args( $model, $api_base );
		$url             = trailingslashit( $api_base ) . ltrim( $endpoint, '/' );
		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		if ( 'a8c_nomic_native' === $request_format ) {
			// Bare list at body root; model routing is described by Models metadata.
			$body = array( $text );
		} else {
			$body = array(
				'model' => $model,
				'input' => $text,
			);
			$body = self::merge_custom_options( $body, $custom_options );
		}

		// Validate required parameters.
		if ( empty( $api_key ) || ! is_string( $api_key ) ) {
			return new \WP_Error( 'embedding_error', __( 'API key is required for embedding.', 'wpvdb' ) );
		}

		if ( empty( $model ) || ! is_string( $model ) ) {
			return new \WP_Error( 'embedding_error', __( 'Model name is required for embedding.', 'wpvdb' ) );
		}

		if ( empty( $api_base ) || ! is_string( $api_base ) ) {
			return new \WP_Error( 'embedding_error', __( 'API base URL is required for embedding.', 'wpvdb' ) );
		}

		$skip_sdk = \wpvdb_is_playground_runtime();

		// Prefer PHP AI Client embeddings when using the default OpenAI endpoint.
		if ( ! $skip_sdk ) {
			$ai_client_embedding = self::maybe_get_embedding_via_ai_client( $text, $model, $api_base, $api_key, $custom_options );
			if ( is_array( $ai_client_embedding ) ) {
				if ( ! self::is_valid_embedding( $ai_client_embedding ) ) {
					return new \WP_Error( 'embedding_error', 'AI Client returned an invalid embedding.' );
				}
				Cache::set_embedding( $text, $model, $ai_client_embedding );
				return $ai_client_embedding;
			} elseif ( is_wp_error( $ai_client_embedding ) ) {
				Logger::debug(
					'AI Client embedding failed, falling back to HTTP request.',
					array(
						'error' => $ai_client_embedding->get_error_message(),
						'model' => $model,
					)
				);
			}
		}

		$extra_headers = array();
		if ( Providers::is_automattic_ai_proxy_url( $url ) ) {
			$extra_headers['X-WPCOM-AI-Feature'] = apply_filters( 'wpvdb_a8c_ai_feature', 'wpcloud-vector-search', $model, $api_base );
		}

		// Try AI Client transporter first for consistency with the WP AI stack.
		try {
			if ( $skip_sdk ) {
				throw new \RuntimeException( 'wpvdb Playground runtime uses wp_remote_post for embedding requests.' );
			}

			$transporter = HttpTransporterFactory::createTransporter();
			$request     = new Request(
				HttpMethodEnum::POST(),
				$url,
				array_merge( array( 'Content-Type' => 'application/json' ), $extra_headers ),
				wp_json_encode( $body )
			);

			$auth    = new ApiKeyRequestAuthentication( $api_key );
			$request = $auth->authenticate( $request );

			$response = $transporter->send( $request );
			$code     = $response->getStatusCode();
			$data     = $response->getData();
		} catch ( \Throwable $e ) {
			// Fallback to wp_remote_post if transporter or SDK pieces are unavailable.
			$args = array(
				'headers' => array_merge(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					$extra_headers
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			);

			$response = wp_remote_post( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		if ( 200 !== $code ) {
			return new \WP_Error( 'embedding_error', 'Failed to get embedding: ' . $code . ' ' . ( is_string( $data ) ? $data : wp_json_encode( $data ) ) );
		}

		if ( 'a8c_nomic_native' === $response_format ) {
			// Native Nomic. Treat any non-"ok" status (or missing status) as a soft
			// signal but still try the embeddings array first since Ray Serve has
			// returned successful payloads without a status field in the past.
			if ( isset( $data['status'] ) && 'ok' !== $data['status'] ) {
				return new \WP_Error( 'embedding_error', 'Nomic upstream returned status: ' . wp_json_encode( $data['status'] ) );
			}
			if ( ! isset( $data['embeddings'][0] ) || ! is_array( $data['embeddings'][0] ) ) {
				return new \WP_Error( 'embedding_error', 'Invalid native Nomic embedding response structure.' );
			}
			$embedding = $data['embeddings'][0];
		} else {
			if ( ! isset( $data['data'][0]['embedding'] ) || ! is_array( $data['data'][0]['embedding'] ) ) {
				return new \WP_Error( 'embedding_error', 'Invalid OpenAI embedding response structure.' );
			}
			$embedding = $data['data'][0]['embedding'];
		}

		if ( ! self::is_valid_embedding( $embedding ) ) {
			return new \WP_Error( 'embedding_error', 'Provider returned an empty or zero-magnitude embedding.' );
		}

		// Cache the successful embedding.
		Cache::set_embedding( $text, $model, $embedding );

		return $embedding;
	}

	/**
	 * True when $embedding is a non-empty zero-indexed list of finite int|float values
	 * whose L2 norm is comfortably non-zero.
	 *
	 * Numeric strings and associative arrays are rejected so the value JSON-encodes
	 * as a number array, not a string or object (VEC_FromText rejects both).
	 * The 1e-30 squared-norm floor is a semantic near-zero guard, well above float32's
	 * smallest normal (~1.18e-38).
	 *
	 * @param mixed $embedding Candidate embedding. Expected to be array<int, int|float>.
	 * @return bool True if the candidate is a usable embedding; false otherwise.
	 */
	public static function is_valid_embedding( $embedding ) {
		if ( ! is_array( $embedding ) || count( $embedding ) === 0 ) {
			return false;
		}
		$expected_key = 0;
		$sum_sq       = 0.0;
		foreach ( $embedding as $k => $v ) {
			if ( $k !== $expected_key ) {
				return false;
			}
			++$expected_key;
			if ( ! is_int( $v ) && ! is_float( $v ) ) {
				return false;
			}
			$f = (float) $v;
			if ( ! is_finite( $f ) ) {
				return false;
			}
			$sum_sq += $f * $f;
		}
		return $sum_sq > 1e-30;
	}

	/**
	 * Get embedding using registered model and provider details
	 *
	 * @param string $text The text to embed.
	 * @param string $model_name The model name.
	 * @param string $provider_name The provider name.
	 * @return array|WP_Error Embedding vector or error
	 */
	public static function get_embedding_for_model( $text, $model_name, $provider_name ) {
		// Get provider and model details.
		$provider = Providers::get_provider( $provider_name );
		$model    = Models::get_model( $provider_name, $model_name );

		if ( ! $provider || ! $model ) {
			return new \WP_Error( 'invalid_model', 'Invalid provider or model specified' );
		}

		// Get API key.
		$api_key = Settings::get_api_key_for_provider( $provider_name );

		// Get the API base URL.
		$api_base = Providers::get_api_base( $provider_name );

		// Call the embedding function.
		return self::get_embedding( $text, $model_name, $api_base, $api_key );
	}

	/**
	 * Attempt to generate embeddings using the PHP AI Client SDK when available.
	 *
	 * Uses the OpenAI provider when the API base matches the default OpenAI endpoint.
	 * Falls back to null when the SDK is unavailable or when a non-default base is configured.
	 *
	 * @param string $text The text to embed.
	 * @param string $model The model identifier.
	 * @param string $api_base The API base URL.
	 * @param string $api_key The API key.
	 * @return array|\WP_Error|null Embedding vector, error, or null to continue with fallback.
	 */
	private static function maybe_get_embedding_via_ai_client( $text, $model, $api_base, $api_key, $custom_options = array() ) {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return null;
		}

		$normalized_base = untrailingslashit( $api_base );
		if ( 'https://api.openai.com/v1' !== $normalized_base ) {
			return null;
		}

		try {
			$registry = AiClient::defaultRegistry();
			$registry->setProviderRequestAuthentication(
				OpenAiProvider::class,
				new ApiKeyRequestAuthentication( $api_key )
			);

			$model_instance = $registry->getProviderModel( OpenAiProvider::class, $model );
			if ( ! empty( $custom_options ) ) {
				$config = $model_instance->getConfig();
				$config->setCustomOptions( $custom_options );
				$model_instance->setConfig( $config );
			}

			$vectors = AiClient::generateEmbeddingsResult( $text, $model_instance, $registry )->toVectors();

			if ( empty( $vectors ) || ! isset( $vectors[0] ) || ! is_array( $vectors[0] ) ) {
				return new \WP_Error( 'embedding_error', __( 'Empty embedding response from AI Client.', 'wpvdb' ) );
			}

			return $vectors[0];
		} catch ( \Throwable $e ) {
			return self::map_embedding_exception( $e );
		}
	}

	/**
	 * Normalize and validate custom embedding options.
	 *
	 * @param string $model The model identifier.
	 * @param string $api_base The API base URL.
	 * @return array<string, mixed>
	 */
	private static function get_embedding_custom_options( $model, $api_base ) {
		$options = apply_filters( 'wpvdb_embedding_custom_options', array(), $model, $api_base );
		if ( ! is_array( $options ) ) {
			return array();
		}

		// Only send dimensions to models that explicitly support it.
		if (
			! isset( $options['dimensions'] ) &&
			defined( 'WPVDB_DEFAULT_EMBED_DIM' ) &&
			Models::supports_dimensions( $model, $api_base )
		) {
			$options['dimensions'] = (int) WPVDB_DEFAULT_EMBED_DIM;
		}

		return array_filter(
			$options,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Merge custom options into the request body without overwriting required keys.
	 *
	 * @param array $body Base request payload.
	 * @param array $custom_options Custom options from filters.
	 * @return array
	 */
	private static function merge_custom_options( array $body, array $custom_options ) {
		foreach ( $custom_options as $key => $value ) {
			if ( isset( $body[ $key ] ) ) {
				continue;
			}
			$body[ $key ] = $value;
		}
		return $body;
	}

	/**
	 * Map SDK exceptions to structured WP_Error codes.
	 *
	 * @param \Throwable $e Exception thrown by the SDK.
	 * @return \WP_Error
	 */
	private static function map_embedding_exception( \Throwable $e ) {
		$code    = $e->getCode();
		$message = $e->getMessage();

		if ( 401 === $code ) {
			return new \WP_Error( 'embedding_auth_error', $message );
		}
		if ( 403 === $code ) {
			return new \WP_Error( 'embedding_forbidden', $message );
		}
		if ( 404 === $code ) {
			return new \WP_Error( 'embedding_model_not_found', $message );
		}
		if ( 429 === $code ) {
			return new \WP_Error( 'embedding_rate_limited', $message );
		}
		if ( $code >= 500 && $code < 600 ) {
			return new \WP_Error( 'embedding_provider_error', $message );
		}

		return new \WP_Error( 'embedding_error', $message );
	}

	// Add a logging function (deprecated - use Logger class directly).
	public static function log_error( $message, $context = array() ) {
		Logger::error( $message, $context );
	}

	/**
	 * Enhanced chunking that respects semantic boundaries
	 *
	 * @param array  $chunks Existing chunks.
	 * @param string $text Text to chunk.
	 * @param int    $chunk_size Optional chunk size override.
	 * @return array
	 */
	public static function enhanced_chunking( $chunks, $text, $chunk_size = null ) {
		if ( ! empty( $chunks ) ) {
			// If some other filter added chunks, just return them.
			return $chunks;
		}

		// Check for null or empty text.
		if ( null === $text || '' === $text ) {
			return array();
		}

		// Ensure text is a string.
		if ( ! is_string( $text ) ) {
			if ( is_array( $text ) || is_object( $text ) ) {
				$text = json_encode( $text );
			} else {
				$text = strval( $text );
			}
		}

		// Get chunk size from settings or use default.
		if ( null === $chunk_size ) {
			$chunk_size = Settings::get_chunk_size();
		}

		// Split into paragraphs first.
		$paragraphs    = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$current_chunk = '';
		$current_words = 0;
		$chunks        = array();

		foreach ( $paragraphs as $paragraph ) {
			// Clean whitespace.
			$paragraph = trim( $paragraph );
			if ( empty( $paragraph ) ) {
				continue;
			}

			// Count words in this paragraph.
			$paragraph_words = str_word_count( $paragraph );

			// If adding this paragraph would exceed chunk size and we already have content,
			// save current chunk and start a new one.
			if ( $current_words > 0 && ( $current_words + $paragraph_words ) > $chunk_size ) {
				$chunks[]      = $current_chunk;
				$current_chunk = $paragraph;
				$current_words = $paragraph_words;
			} elseif ( $paragraph_words > $chunk_size ) {
				// If this single paragraph exceeds chunk size, we need to split it.
				// If we have a current chunk, save it first.
				if ( $current_words > 0 ) {
					$chunks[]      = $current_chunk;
					$current_chunk = '';
					$current_words = 0;
				}

				// Split paragraph into sentences.
				$sentences      = preg_split( '/(?<=[.!?])\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
				$sentence_chunk = '';
				$sentence_words = 0;

				foreach ( $sentences as $sentence ) {
					$sentence_word_count = str_word_count( $sentence );

					// If adding this sentence would exceed chunk size and we have content,
					// save current sentence chunk and start a new one.
					if ( $sentence_words > 0 && ( $sentence_words + $sentence_word_count ) > $chunk_size ) {
						$chunks[]       = $sentence_chunk;
						$sentence_chunk = $sentence;
						$sentence_words = $sentence_word_count;
					} else {
						// Add to current sentence chunk.
						if ( ! empty( $sentence_chunk ) ) {
							$sentence_chunk .= ' ';
						}
						$sentence_chunk .= $sentence;
						$sentence_words += $sentence_word_count;
					}
				}

				// Add any remaining sentence chunk.
				if ( ! empty( $sentence_chunk ) ) {
					$chunks[] = $sentence_chunk;
				}
			} else {
				// Otherwise, add to current chunk.
				if ( ! empty( $current_chunk ) ) {
					$current_chunk .= "\n\n";
				}
				$current_chunk .= $paragraph;
				$current_words += $paragraph_words;
			}
		}

		// Add any remaining content.
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = $current_chunk;
		}

		return $chunks;
	}

	/**
	 * Auto-embed a post when it's published or updated.
	 *
	 * @param int    $post_id Post ID.
	 * @param object $post    Post object.
	 * @param bool   $update  Whether the post is being updated.
	 */
	public static function auto_embed_post( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Validate inputs.
		if ( empty( $post_id ) || ! is_numeric( $post_id ) || ! is_object( $post ) ) {
			return;
		}

		// Skip revisions, auto-drafts, etc.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only process published posts.
		if ( ! isset( $post->post_status ) || 'publish' !== $post->post_status ) {
			return;
		}

		// Check post type property exists.
		if ( ! isset( $post->post_type ) || empty( $post->post_type ) ) {
			return;
		}

		// Check if this post type should be auto-embedded.
		$auto_embed_types = Settings::get_auto_embed_post_types();
		if ( ! is_array( $auto_embed_types ) || ! in_array( $post->post_type, $auto_embed_types ) ) {
			return;
		}

		// Queue for background processing with validation.
		$queue = new WPVDB_Queue();
		$queue->push_to_queue( WPVDB_Queue::build_item( $post_id ) );

		// Try to run the queue immediately if we're in the admin.
		if ( is_admin() && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wpvdb_run_queue_now', array(), 'wpvdb' );
		}
	}

	/**
	 * Get the active provider from settings
	 *
	 * @return string The active provider (openai or automattic)
	 */
	private static function get_active_provider() {
		return Settings::get_active_provider();
	}
}
