<?php
/**
 * Regression guards for the visibility hardening: Core::auto_embed_post()
 * purge-on-non-indexable-save, and Embedding_Enqueuer's scope password clause /
 * $params alignment. DB surfaces use a spy wpdb; WP shims are guard-defined in
 * the global namespace.
 *
 * @package WPVDB
 */

namespace {
	// auto_embed_post() consults these; none are provided by tests/bootstrap.php.
	// They are fixture-driven so individual tests can flip a post into a revision
	// / autosave. wp_next_scheduled() returns a truthy value so the fallback queue
	// in WPVDB_Queue never tries to schedule a (also unstubbed) cron event.
	if ( ! function_exists( 'wp_is_post_revision' ) ) {
		function wp_is_post_revision( $post ) {
			$id  = is_object( $post ) ? ( isset( $post->ID ) ? (int) $post->ID : 0 ) : (int) $post;
			$ids = isset( $GLOBALS['wpvdb_test_revision_ids'] ) ? $GLOBALS['wpvdb_test_revision_ids'] : array();
			return in_array( $id, $ids, true );
		}
	}

	if ( ! function_exists( 'wp_is_post_autosave' ) ) {
		function wp_is_post_autosave( $post ) {
			$id  = is_object( $post ) ? ( isset( $post->ID ) ? (int) $post->ID : 0 ) : (int) $post;
			$ids = isset( $GLOBALS['wpvdb_test_autosave_ids'] ) ? $GLOBALS['wpvdb_test_autosave_ids'] : array();
			return in_array( $id, $ids, true );
		}
	}

	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		function wp_next_scheduled( $hook, $args = array() ) {
			return time(); // Truthy: suppresses wp_schedule_event() in the fallback path.
		}
	}

	if ( ! function_exists( 'wp_schedule_event' ) ) {
		function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
			return true;
		}
	}
}

namespace WPVDB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use WPVDB\Core;
	use WPVDB\Embedding_Enqueuer;

	class AutoEmbedEnqueuerAuditTest extends TestCase {

		/** @var mixed */
		private $original_wpdb;

		protected function setUp(): void {
			parent::setUp();

			$this->original_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;

			global $_wp_options;
			$_wp_options = array(
				// Only 'post' is a managed (auto-embed) type for these tests.
				'wpvdb_settings' => array(
					'auto_embed_post_types' => array( 'post' ),
					'active_provider'       => 'openai',
					'active_model'          => 'text-embedding-3-small',
				),
			);

			$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish' );
			$GLOBALS['wpvdb_test_posts']             = array();
			$GLOBALS['wpvdb_test_revision_ids']      = array();
			$GLOBALS['wpvdb_test_autosave_ids']      = array();
		}

		protected function tearDown(): void {
			if ( $this->original_wpdb ) {
				$GLOBALS['wpdb'] = $this->original_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}

			$GLOBALS['wpvdb_test_posts']        = array();
			$GLOBALS['wpvdb_test_revision_ids'] = array();
			$GLOBALS['wpvdb_test_autosave_ids'] = array();

			global $_wp_options;
			$_wp_options = array();

			parent::tearDown();
		}

		/**
		 * Spy wpdb: reports the embeddings table exists and records every delete()
		 * so tests can assert whether a purge happened. Mirrors QueueTest's spy.
		 */
		private function install_spy_wpdb() {
			$spy = new class() extends \wpdb {
				public $deleted = array();
				public function __construct() {}
				public function get_var( $query = null, $x = 0, $y = 0 ) {
					if ( is_string( $query ) && false !== strpos( $query, 'SHOW TABLES' ) ) {
						return $this->prefix . 'wpvdb_embeddings';
					}
					return '';
				}
				public function delete( $table, $where, $format = null ) {
					$this->deleted[] = $where;
					return 1;
				}
			};

			$GLOBALS['wpdb'] = $spy;
			return $spy;
		}

		private function make_post( $id, array $overrides = array() ) {
			$data = array_merge(
				array(
					'ID'            => $id,
					'post_status'   => 'publish',
					'post_type'     => 'post',
					'post_password' => '',
					'post_title'    => 'T',
					'post_content'  => 'Body',
				),
				$overrides
			);
			$post                               = new \WP_Post( $data );
			$GLOBALS['wpvdb_test_posts'][ $id ] = $post;
			return $post;
		}

		private function queue_option() {
			return isset( $GLOBALS['_wp_options']['wpvdb_embedding_queue'] )
				? $GLOBALS['_wp_options']['wpvdb_embedding_queue']
				: array();
		}

		// --- (a) auto_embed_post: purge on non-indexable managed save ----------

		public function test_privatized_managed_post_is_purged() {
			$spy  = $this->install_spy_wpdb();
			$post = $this->make_post( 10, array( 'post_status' => 'private' ) );

			Core::auto_embed_post( 10, $post, true );

			$this->assertNotEmpty( $spy->deleted, 'A privatized managed post must have its rows purged.' );
			$this->assertSame( 10, $spy->deleted[0]['doc_id'] );
			$this->assertEmpty( $this->queue_option(), 'A non-indexable post must not be enqueued.' );
		}

		public function test_drafted_managed_post_is_purged() {
			$spy  = $this->install_spy_wpdb();
			$post = $this->make_post( 11, array( 'post_status' => 'draft' ) );

			Core::auto_embed_post( 11, $post, true );

			$this->assertNotEmpty( $spy->deleted, 'A drafted managed post must be purged.' );
			$this->assertSame( 11, $spy->deleted[0]['doc_id'] );
		}

		public function test_password_protected_managed_post_is_purged() {
			$spy  = $this->install_spy_wpdb();
			// Published but password-protected: viewable status yet NOT indexable.
			$post = $this->make_post( 12, array( 'post_password' => 'secret' ) );

			Core::auto_embed_post( 12, $post, true );

			$this->assertNotEmpty( $spy->deleted, 'A password-protected managed post must be purged.' );
			$this->assertSame( 12, $spy->deleted[0]['doc_id'] );
		}

		// --- (a) auto_embed_post: skip revisions / autosaves without purging ---

		public function test_revision_is_skipped_without_purge() {
			$spy                                = $this->install_spy_wpdb();
			$post                               = $this->make_post( 20, array( 'post_status' => 'private' ) );
			$GLOBALS['wpvdb_test_revision_ids'] = array( 20 );

			Core::auto_embed_post( 20, $post, true );

			$this->assertEmpty( $spy->deleted, 'Revisions must be skipped before the visibility gate.' );
			$this->assertEmpty( $this->queue_option() );
		}

		public function test_autosave_is_skipped_without_purge() {
			$spy                                = $this->install_spy_wpdb();
			$post                               = $this->make_post( 21, array( 'post_status' => 'private' ) );
			$GLOBALS['wpvdb_test_autosave_ids'] = array( 21 );

			Core::auto_embed_post( 21, $post, true );

			$this->assertEmpty( $spy->deleted, 'Autosaves must be skipped before the visibility gate.' );
		}

		// --- (a) auto_embed_post: non-indexable purge is NOT scoped to managed --

		public function test_non_managed_non_indexable_post_is_purged() {
			$spy = $this->install_spy_wpdb();
			// 'attachment' is not in auto_embed_post_types (['post']), but a
			// non-managed post can still carry rows (REST/bulk inserts, or a type
			// later removed from the set). The visibility gate runs BEFORE the
			// type gate, so a non-indexable non-managed post is still purged.
			$post = $this->make_post(
				30,
				array(
					'post_type'   => 'attachment',
					'post_status' => 'private',
				)
			);

			Core::auto_embed_post( 30, $post, true );

			$this->assertNotEmpty( $spy->deleted, 'A non-indexable post must be purged regardless of type.' );
			$this->assertSame( 30, $spy->deleted[0]['doc_id'] );
		}

		public function test_non_managed_indexable_post_is_neither_purged_nor_enqueued() {
			$spy = $this->install_spy_wpdb();
			// A publicly-viewable non-managed post is left alone: not purged
			// (indexable) and not enqueued (type not in the auto-embed set).
			$post = $this->make_post(
				31,
				array(
					'post_type'   => 'attachment',
					'post_status' => 'publish',
				)
			);

			Core::auto_embed_post( 31, $post, true );

			$this->assertEmpty( $spy->deleted, 'An indexable post must not be purged.' );
			$this->assertEmpty( $this->queue_option(), 'A non-managed type must not be enqueued.' );
		}

		// --- (a) auto_embed_post: indexable managed post enqueues, no purge ----

		public function test_indexable_managed_post_enqueues_and_does_not_purge() {
			$spy  = $this->install_spy_wpdb();
			$post = $this->make_post( 40 ); // publish, no password, type post.

			Core::auto_embed_post( 40, $post, true );

			$this->assertEmpty( $spy->deleted, 'An indexable post must not be purged.' );

			$queue = $this->queue_option();
			$this->assertNotEmpty( $queue, 'An indexable managed post must be enqueued.' );
			$this->assertSame( 40, $queue[0]['post_id'] );
		}

		public function test_opt_in_filter_keeps_private_post_enqueued_and_unpurged() {
			$spy  = $this->install_spy_wpdb();
			$post = $this->make_post( 50, array( 'post_status' => 'private' ) );

			// Operator opts private content back in via the documented filter.
			add_filter( 'wpvdb_is_post_indexable', '__return_true' );
			try {
				Core::auto_embed_post( 50, $post, true );
			} finally {
				remove_filter( 'wpvdb_is_post_indexable', '__return_true' );
			}

			$this->assertEmpty( $spy->deleted, 'Opted-in content must not be purged.' );
			$queue = $this->queue_option();
			$this->assertNotEmpty( $queue, 'Opted-in content must be enqueued.' );
			$this->assertSame( 50, $queue[0]['post_id'] );
		}

		// --- (b) build_scope_where_sql: password clause + param alignment ------

		/**
		 * Invoke the private static build_scope_where_sql via reflection, returning
		 * [ $where_sql, $params ]. PHP 8.1+ reflection needs no setAccessible().
		 */
		private function invoke_build_scope_where_sql( array $args ) {
			$ref    = new \ReflectionMethod( Embedding_Enqueuer::class, 'build_scope_where_sql' );
			$params = array();
			$where  = $ref->invokeArgs( null, array( $args, &$params ) );
			return array( $where, $params );
		}

		public function test_scope_where_emits_password_clause() {
			list( $where ) = $this->invoke_build_scope_where_sql(
				array(
					'post_type'   => array( 'post', 'page' ),
					'post_status' => array( 'publish' ),
				)
			);

			$this->assertStringContainsString( "post_password = ''", $where );
		}

		public function test_password_clause_adds_no_placeholder_and_params_align() {
			list( $where, $params ) = $this->invoke_build_scope_where_sql(
				array(
					'post_type'   => array( 'post', 'page' ),
					'post_status' => array( 'publish' ),
				)
			);

			// 2 post_type + 1 post_status = 3 %s placeholders; the password clause
			// is a literal and must contribute ZERO placeholders and ZERO params.
			$this->assertSame( 3, substr_count( $where, '%s' ), 'Password clause must not add a placeholder.' );
			$this->assertSame( 0, substr_count( $where, '%d' ), 'The fragment owns no %d placeholders.' );
			$this->assertCount( 3, $params, 'Params must stay aligned with the %s placeholders.' );
			$this->assertSame( array( 'post', 'page', 'publish' ), $params );
		}

		public function test_params_align_with_since_clause() {
			list( $where, $params ) = $this->invoke_build_scope_where_sql(
				array(
					'post_type'   => array( 'post' ),
					'post_status' => array( 'publish', 'private' ),
					'since'       => '2026-01-02',
				)
			);

			// 1 post_type + 2 post_status + 1 since = 4 %s; password clause still 0.
			$this->assertStringContainsString( "post_password = ''", $where );
			$this->assertSame( 4, substr_count( $where, '%s' ) );
			$this->assertCount( 4, $params );
			// A YYYY-MM-DD since is expanded to a full datetime and appended LAST.
			$this->assertSame( array( 'post', 'publish', 'private', '2026-01-02 00:00:00' ), $params );
		}

		public function test_fragment_starts_with_and_conjunction() {
			list( $where ) = $this->invoke_build_scope_where_sql(
				array(
					'post_type'   => array( 'post' ),
					'post_status' => array( 'publish' ),
				)
			);

			// The fragment is appended to a caller-owned "WHERE ID > %d ...".
			$this->assertStringStartsWith( 'AND ', $where );
		}
	}
}
