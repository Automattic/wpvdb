<?php
/**
 * Drives handle_embed() / handle_vectors() end to end (which RESTTest never did)
 * with a stubbed WP_REST_Request, spying on the provider seam
 * (wpvdb_generate_embedding) to prove it's never reached when the gate rejects.
 *
 * @package WPVDB
 */

namespace {
	// Test-only global stubs, guarded so bootstrap.php stays untouched.
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/**
		 * Minimal WP_REST_Request stand-in exposing only what the REST callbacks read.
		 */
		class WP_REST_Request {
			/** @var array<string,mixed> */
			private $params;

			/**
			 * @param array<string,mixed> $params Request params.
			 */
			public function __construct( array $params = array() ) {
				$this->params = $params;
			}

			/**
			 * @param string $key Param name.
			 * @return mixed
			 */
			public function get_param( $key ) {
				return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
			}

			/**
			 * @param string $key   Param name.
			 * @param mixed  $value Param value.
			 */
			public function set_param( $key, $value ) {
				$this->params[ $key ] = $value;
			}

			/**
			 * @return array<string,mixed>
			 */
			public function get_json_params() {
				return $this->params;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		/**
		 * @param mixed $thing Value to test.
		 * @return bool
		 */
		function is_wp_error( $thing ) {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		/**
		 * Mirror of the bootstrap sanitize_text_field mock; textarea variant used by
		 * handle_embed()/handle_vectors(). Newline preservation is irrelevant to these
		 * tests, so the simple form is sufficient.
		 *
		 * @param mixed $str Raw value.
		 * @return mixed
		 */
		function sanitize_textarea_field( $str ) {
			return is_string( $str ) ? trim( strip_tags( $str ) ) : $str;
		}
	}
}

namespace WPVDB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use WPVDB\REST;

	/**
	 * @covers \WPVDB\REST::handle_embed
	 * @covers \WPVDB\REST::handle_vectors
	 */
	class RESTVisibilityAuditTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['wpvdb_test_posts']             = array();
			$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish' );
			$GLOBALS['_wp_filters']                  = array();
		}

		protected function tearDown(): void {
			$GLOBALS['wpvdb_test_posts'] = array();
			$GLOBALS['_wp_filters']      = array();
			parent::tearDown();
		}

		/**
		 * Register a spy on the provider seam. Returns a counter array whose ['n']
		 * increments each time Core::get_embedding would call the embedding provider.
		 * The spy short-circuits with a valid embedding so the real HTTP path is never
		 * taken when it does fire.
		 *
		 * @return array{n:int}
		 */
		private function &install_provider_spy() {
			$calls = array( 'n' => 0 );
			add_filter(
				'wpvdb_generate_embedding',
				static function ( $value, $text, $model, $api_base, $api_key ) use ( &$calls ) {
					++$calls['n'];
					return array( 0.1, 0.2, 0.3 );
				},
				10,
				5
			);
			return $calls;
		}

		/**
		 * Make Settings::get_api_key() return a non-empty value without touching
		 * options, so handle_embed() proceeds past its api-key guard to the provider.
		 */
		private function supply_api_key() {
			add_filter(
				'wpvdb_default_api_key',
				static function ( $key ) {
					return 'test-key';
				},
				10,
				1
			);
		}

		/**
		 * Give handle_embed() something to iterate so the provider seam is reachable
		 * on the positive-control paths.
		 */
		private function supply_single_chunk() {
			add_filter(
				'wpvdb_chunk_text',
				static function ( $chunks, $text ) {
					return array( 'hello world' );
				},
				10,
				2
			);
		}

		private function make_post( $id, $status = 'publish', $password = '', $type = 'post' ) {
			$GLOBALS['wpvdb_test_posts'][ $id ] = new \WP_Post(
				array(
					'ID'            => $id,
					'post_status'   => $status,
					'post_password' => $password,
					'post_type'     => $type,
				)
			);
		}

		/**
		 * An unbacked doc_id is rejected by handle_embed() before the provider is
		 * ever called (no data leaves the site for content that has no public post).
		 */
		public function test_handle_embed_rejects_unbacked_doc_id_without_provider_call() {
			$calls   = &$this->install_provider_spy();
			$request = new \WP_REST_Request(
				array(
					'doc_id' => 999999,
					'text'   => 'secret text that must not reach the provider',
				)
			);

			$result = REST::handle_embed( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wpvdb_not_indexable', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( 0, $calls['n'], 'Provider must not be called for a non-indexable doc_id.' );
		}

		/**
		 * A private (non-publicly-viewable) post is rejected by handle_embed() before
		 * the provider is called.
		 */
		public function test_handle_embed_rejects_private_post_without_provider_call() {
			$this->make_post( 555, 'private' );
			$calls   = &$this->install_provider_spy();
			$request = new \WP_REST_Request(
				array(
					'doc_id' => 555,
					'text'   => 'draft-ish private content',
				)
			);

			$result = REST::handle_embed( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wpvdb_not_indexable', $result->get_error_code() );
			$this->assertSame( 0, $calls['n'], 'Provider must not be called for a private post.' );
		}

		/**
		 * Positive control: an indexable public post passes the gate and the provider
		 * seam IS reached. This proves the spy is wired correctly, so the "provider not
		 * called" assertions above are meaningful rather than vacuously true.
		 */
		public function test_handle_embed_calls_provider_for_indexable_public_post() {
			$this->make_post( 123, 'publish' );
			$this->supply_api_key();
			$this->supply_single_chunk();
			$calls   = &$this->install_provider_spy();
			$request = new \WP_REST_Request(
				array(
					'doc_id' => 123,
					'text'   => 'public content that may be embedded',
				)
			);

			$result = REST::handle_embed( $request );

			$this->assertSame( 1, $calls['n'], 'Provider should be called once for an indexable post.' );
			// Downstream the mock DB has no table, so the insert backstop returns an
			// error; the point here is only that the gate did not reject.
			if ( $result instanceof \WP_Error ) {
				$this->assertNotSame( 'wpvdb_not_indexable', $result->get_error_code() );
			}
		}

		/**
		 * The wpvdb_is_post_indexable filter can opt an unbacked doc_id in, and when it
		 * does the REST path runs end to end (provider reached). Mirrors the arbitrary
		 * document bypass documented on the filter.
		 */
		public function test_handle_embed_filter_opt_in_reaches_provider_for_unbacked_doc() {
			add_filter(
				'wpvdb_is_post_indexable',
				static function ( $indexable, $post_obj, $post_id ) {
					return 424242 === $post_id ? true : $indexable;
				},
				10,
				3
			);
			$this->supply_api_key();
			$this->supply_single_chunk();
			$calls   = &$this->install_provider_spy();
			$request = new \WP_REST_Request(
				array(
					'doc_id' => 424242,
					'text'   => 'arbitrary opted-in document',
				)
			);

			$result = REST::handle_embed( $request );

			$this->assertSame( 1, $calls['n'], 'Opted-in doc_id should reach the provider.' );
			if ( $result instanceof \WP_Error ) {
				$this->assertNotSame( 'wpvdb_not_indexable', $result->get_error_code() );
			}
		}

		/**
		 * handle_vectors() preflights visibility and rejects a non-indexable doc_id
		 * BEFORE any storage attempt. A reject surfaces as wpvdb_not_indexable rather
		 * than the downstream embedding_table_missing the mock DB would otherwise
		 * produce, proving the preflight fires ahead of the insert.
		 */
		public function test_handle_vectors_rejects_non_indexable_before_storing() {
			$this->make_post( 556, 'draft' );
			$request = new \WP_REST_Request(
				array(
					'doc_id'    => 556,
					'chunk_id'  => 'chunk-0',
					'model'     => 'test-model',
					'embedding' => array( 0.1, 0.2, 0.3 ),
				)
			);

			$result = REST::handle_vectors( $request );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wpvdb_not_indexable', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
	}
}
