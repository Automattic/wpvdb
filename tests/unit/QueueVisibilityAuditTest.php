<?php
/**
 * Regression coverage for the visibility enforcement on the queue path:
 * process_item()'s gate/purge branches and gate-before-api-key ordering, the
 * wpvdb_is_post_indexable opt-in/opt-out, and process_post()'s fresh in-flight
 * re-check + insert-time backstop (abort + purge, provider never called).
 * Fixtures live in $GLOBALS['wpvdb_test_posts']; one extra global stub
 * (wp_strip_all_tags) is defined here, guarded.
 *
 * @package WPVDB
 */

namespace {
	// process_post() strips tags to build the embed text; bootstrap lacks this.
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text, $remove_breaks = false ) {
			$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
			$text = strip_tags( $text );
			if ( $remove_breaks ) {
				$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
			}
			return trim( $text );
		}
	}

	// process_post() checks the embedding result with is_wp_error(); bootstrap.php
	// does not stub it. Guarded so it coexists with the sibling audit test file.
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return $thing instanceof \WP_Error;
		}
	}
}

namespace WPVDB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use WPVDB\Indexability;
	use WPVDB\WPVDB_Queue;

	class QueueVisibilityAuditTest extends TestCase {

		/**
		 * The real global $wpdb, restored in tearDown.
		 *
		 * @var mixed
		 */
		private $original_wpdb;

		protected function setUp(): void {
			parent::setUp();

			global $_wp_options, $_wp_filters;
			$_wp_options = array();
			$_wp_filters = array();

			$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish' );
			$GLOBALS['wpvdb_test_posts']             = array();

			$this->original_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		}

		protected function tearDown(): void {
			if ( $this->original_wpdb ) {
				$GLOBALS['wpdb'] = $this->original_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}

			global $_wp_options, $_wp_filters;
			$_wp_options = array();
			$_wp_filters = array();

			$GLOBALS['wpvdb_test_posts'] = array();
			unset( $GLOBALS['wpvdb_test_viewable_statuses'] );
			unset( $GLOBALS['wpvdb_audit_embedding_called'] );

			parent::tearDown();
		}

		/**
		 * Spy wpdb: reports the embeddings table exists (so delete_post_embeddings
		 * proceeds) and records every delete() call so purges can be asserted.
		 * Mirrors the pattern in QueueTest.
		 *
		 * @return \wpdb
		 */
		private function make_spy_wpdb() {
			return new class() extends \wpdb {
				public $deleted = array();
				public function get_var( $query = null, $x = 0, $y = 0 ) {
					if ( is_string( $query ) && false !== strpos( $query, 'SHOW TABLES' ) ) {
						return $this->prefix . 'wpvdb_embeddings';
					}
					return parent::get_var( $query, $x, $y );
				}
				public function delete( $table, $where, $format = null ) {
					$this->deleted[] = $where;
					return 1;
				}
			};
		}

		/**
		 * Install a spy wpdb as the global and return it.
		 *
		 * @return \wpdb
		 */
		private function install_spy_wpdb() {
			$spy             = $this->make_spy_wpdb();
			$GLOBALS['wpdb'] = $spy;
			return $spy;
		}

		/**
		 * Register a post fixture keyed by ID with sensible indexable defaults.
		 *
		 * @param int   $id        Post ID.
		 * @param array $overrides Field overrides.
		 */
		private function set_post( $id, array $overrides = array() ) {
			$GLOBALS['wpvdb_test_posts'][ $id ] = new \WP_Post(
				array_merge(
					array(
						'ID'           => $id,
						'post_status'  => 'publish',
						'post_type'    => 'post',
						'post_title'   => 'Title',
						'post_content' => 'Body',
					),
					$overrides
				)
			);
		}

		/**
		 * Configure a usable API key + provider so process_item()/process_post()
		 * clear their api-key preflights and reach the embedding path.
		 */
		private function configure_api_key() {
			global $_wp_options;
			$_wp_options['wpvdb_settings'] = array(
				'active_provider' => 'openai',
				'openai'          => array( 'api_key' => 'test-key' ),
			);
		}

		// ----------------------------------------------------------------
		// process_item() visibility gate — purge branches.
		// ----------------------------------------------------------------

		/**
		 * Password-protected content is never indexable even when its status is a
		 * public one, so the queue must purge and skip it.
		 */
		public function test_process_item_purges_password_protected_post() {
			$spy = $this->install_spy_wpdb();
			$this->set_post( 21, array( 'post_status' => 'publish', 'post_password' => 'secret' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 21, 'model' => 'm' ) );

			$this->assertFalse( $result );
			$this->assertNotEmpty( $spy->deleted, 'Password-protected post must be purged.' );
			$this->assertSame( 21, $spy->deleted[0]['doc_id'] );
		}

		/**
		 * Draft (non-public status) content is purged and skipped.
		 */
		public function test_process_item_purges_draft_post() {
			$spy = $this->install_spy_wpdb();
			$this->set_post( 22, array( 'post_status' => 'draft' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 22, 'model' => 'm' ) );

			$this->assertFalse( $result );
			$this->assertNotEmpty( $spy->deleted, 'Draft post must be purged.' );
			$this->assertSame( 22, $spy->deleted[0]['doc_id'] );
		}

		/**
		 * The gate must run BEFORE the api-key preflight. With a valid api key
		 * configured, a non-indexable post is still purged and skipped, proving the
		 * gate is not gated behind (and does not depend on) credential resolution.
		 */
		public function test_process_item_gate_runs_even_with_api_key_configured() {
			$this->configure_api_key();
			$spy = $this->install_spy_wpdb();
			$this->set_post( 23, array( 'post_status' => 'private' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 23, 'model' => 'm' ) );

			$this->assertFalse( $result );
			$this->assertNotEmpty( $spy->deleted, 'Non-indexable post must be purged regardless of api-key state.' );
			$this->assertSame( 23, $spy->deleted[0]['doc_id'] );
		}

		// ----------------------------------------------------------------
		// process_item() — wpvdb_is_post_indexable filter is honored.
		// ----------------------------------------------------------------

		/**
		 * An operator opting a normally-indexable post OUT via the filter must be
		 * honored on the queue path: treat as non-indexable and purge.
		 */
		public function test_process_item_honors_opt_out_filter_for_public_post() {
			$spy = $this->install_spy_wpdb();
			$this->set_post( 24, array( 'post_status' => 'publish' ) );
			add_filter( 'wpvdb_is_post_indexable', '__return_false' );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 24, 'model' => 'm' ) );

			$this->assertFalse( $result );
			$this->assertNotEmpty( $spy->deleted, 'Filter opt-out must be honored and the post purged.' );
			$this->assertSame( 24, $spy->deleted[0]['doc_id'] );
		}

		/**
		 * An operator opting a normally NON-indexable post IN via the filter must
		 * NOT be purged at the gate; it proceeds past the gate (here it then stops
		 * at the api-key preflight because no key is configured). This guards
		 * against over-blocking operator-approved content.
		 */
		public function test_process_item_honors_opt_in_filter_for_private_post() {
			$spy = $this->install_spy_wpdb();
			$this->set_post( 25, array( 'post_status' => 'private' ) );
			add_filter( 'wpvdb_is_post_indexable', '__return_true' );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 25, 'model' => 'm' ) );

			// No api key configured => bails at the preflight, NOT at the gate.
			$this->assertFalse( $result );
			$this->assertEmpty( $spy->deleted, 'Opted-in content must not be purged by the gate.' );
		}

		// ----------------------------------------------------------------
		// process_item() — do not over-block a normal public post.
		// ----------------------------------------------------------------

		/**
		 * A normal public post must pass the gate untouched (no purge). With no api
		 * key configured it still returns false at the api-key preflight, but the
		 * gate must not treat it as non-indexable.
		 */
		public function test_process_item_does_not_over_block_public_post() {
			$spy = $this->install_spy_wpdb();
			$this->set_post( 26, array( 'post_status' => 'publish' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 26, 'model' => 'm' ) );

			$this->assertFalse( $result, 'Stopped by the missing api key, not the visibility gate.' );
			$this->assertEmpty( $spy->deleted, 'Public post must not be purged by the gate.' );
			$this->assertTrue(
				Indexability::is_indexable( $GLOBALS['wpvdb_test_posts'][26] ),
				'Sanity: the fixture is genuinely indexable.'
			);
		}

		// ----------------------------------------------------------------
		// process_post() — mid-run FRESH in-flight re-check (before provider).
		// ----------------------------------------------------------------

		/**
		 * If a post is indexable at the process_item() gate but flips to
		 * non-indexable by the time process_post() reaches its per-chunk re-check,
		 * the run must abort and purge BEFORE the embedding provider is contacted.
		 *
		 * Driven with a call-counting wpvdb_is_post_indexable filter:
		 *   call #1 = process_item() gate            => true  (let it through)
		 *   call #2 = process_post() per-chunk gate  => false (mid-run flip)
		 */
		public function test_process_post_aborts_and_purges_on_midrun_flip_before_provider() {
			$this->configure_api_key();
			$spy = $this->install_spy_wpdb();
			$this->set_post( 30, array( 'post_status' => 'publish' ) );

			$calls = 0;
			add_filter(
				'wpvdb_is_post_indexable',
				function ( $indexable ) use ( &$calls ) {
					++$calls;
					return $calls <= 1;
				}
			);

			// Provide chunks so process_post() enters its per-chunk loop.
			add_filter( 'wpvdb_chunk_text', array( $this, 'filter_return_one_chunk' ) );

			// Sentinel: the provider (embedding) call must NOT happen once flipped.
			$GLOBALS['wpvdb_audit_embedding_called'] = false;
			add_filter( 'wpvdb_generate_embedding', array( $this, 'filter_record_embedding_call' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 30, 'model' => 'test-model' ) );

			$this->assertFalse( $result );
			$this->assertFalse(
				$GLOBALS['wpvdb_audit_embedding_called'],
				'Embedding provider must not be called after a mid-run flip.'
			);
			$this->assertNotEmpty( $spy->deleted, 'Mid-run flip must purge any partial rows.' );
			$this->assertSame( 30, $spy->deleted[0]['doc_id'] );
		}

		// ----------------------------------------------------------------
		// process_post() — insert-time 'wpvdb_not_indexable' backstop.
		// ----------------------------------------------------------------

		/**
		 * If the post stays indexable through the per-chunk gate and the provider
		 * returns an embedding, but flips to non-indexable at storage time,
		 * REST::insert_embedding_row returns 'wpvdb_not_indexable'. process_post()
		 * must purge everything for the post and abort, leaving no partial rows.
		 *
		 * Call-counting filter:
		 *   call #1 = process_item() gate                => true
		 *   call #2 = process_post() per-chunk gate       => true  (reach provider)
		 *   call #3 = insert_embedding_row() storage gate => false (flip at insert)
		 */
		public function test_process_post_aborts_and_purges_on_insert_not_indexable() {
			$this->configure_api_key();
			$spy = $this->install_spy_wpdb();
			$this->set_post( 31, array( 'post_status' => 'publish' ) );

			$calls = 0;
			add_filter(
				'wpvdb_is_post_indexable',
				function ( $indexable ) use ( &$calls ) {
					++$calls;
					return $calls <= 2;
				}
			);

			add_filter( 'wpvdb_chunk_text', array( $this, 'filter_return_one_chunk' ) );

			$GLOBALS['wpvdb_audit_embedding_called'] = false;
			add_filter( 'wpvdb_generate_embedding', array( $this, 'filter_record_embedding_call' ) );

			$result = WPVDB_Queue::process_item( array( 'post_id' => 31, 'model' => 'test-model' ) );

			$this->assertFalse( $result );
			$this->assertTrue(
				$GLOBALS['wpvdb_audit_embedding_called'],
				'Provider should have been reached before the insert-time flip.'
			);
			$this->assertNotEmpty( $spy->deleted, 'Insert-time flip must purge any partial rows.' );
			$this->assertSame( 31, $spy->deleted[0]['doc_id'] );
		}

		// ----------------------------------------------------------------
		// Filter callbacks (referenced by the tests above).
		// ----------------------------------------------------------------

		/**
		 * wpvdb_chunk_text callback: return a single chunk so process_post() enters
		 * its per-chunk loop.
		 *
		 * @param mixed $chunks Incoming (ignored) default value.
		 * @return array
		 */
		public function filter_return_one_chunk( $chunks ) {
			return array( 'chunk one' );
		}

		/**
		 * wpvdb_generate_embedding callback: record that the provider path was
		 * reached and return a valid non-zero vector so Core::get_embedding()
		 * short-circuits before any network call.
		 *
		 * @param mixed $value Incoming (ignored) default value.
		 * @return array
		 */
		public function filter_record_embedding_call( $value ) {
			$GLOBALS['wpvdb_audit_embedding_called'] = true;
			return array( 0.1, 0.2, 0.3 );
		}
	}
}
