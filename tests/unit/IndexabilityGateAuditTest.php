<?php
/**
 * Adversarial edge-case audit for WPVDB\Indexability::is_indexable():
 * ill-typed inputs, the $fresh cache-bust, and the filter arg contract.
 * Complements IndexabilityTest.php.
 *
 * @package WPVDB
 */

namespace WPVDB {

	// Namespaced spy for wp_cache_delete() so this audit can observe the $fresh
	// cache-bust without touching bootstrap. PHP resolves the unqualified call in
	// is_indexable() to this before the global stub. Records only when armed.
	if ( ! function_exists( 'WPVDB\\wp_cache_delete' ) ) {
		function wp_cache_delete( $key, $group = '' ) {
			if ( ! empty( $GLOBALS['wpvdb_audit_spy_armed'] ) ) {
				$GLOBALS['wpvdb_audit_cache_deletes'][] = array( $key, $group );
			}
			return true;
		}
	}
}

namespace WPVDB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use WPVDB\Indexability;

	/**
	 * @covers \WPVDB\Indexability
	 */
	class IndexabilityGateAuditTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['wpvdb_test_posts']             = array();
			$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish' );
			$GLOBALS['_wp_filters']                  = array();
			$GLOBALS['wpvdb_audit_cache_deletes']    = array();
			$GLOBALS['wpvdb_audit_spy_armed']        = true;
		}

		protected function tearDown(): void {
			$GLOBALS['wpvdb_test_posts']          = array();
			$GLOBALS['_wp_filters']               = array();
			$GLOBALS['wpvdb_audit_cache_deletes'] = array();
			$GLOBALS['wpvdb_audit_spy_armed']     = false;
			parent::tearDown();
		}

		private function make_post( $id, $status = 'publish', $password = '', $type = 'post' ) {
			$post = new \WP_Post(
				array(
					'ID'            => $id,
					'post_status'   => $status,
					'post_password' => $password,
					'post_type'     => $type,
				)
			);
			$GLOBALS['wpvdb_test_posts'][ $id ] = $post;
			return $post;
		}

		/**
		 * Register a filter that records the exact arguments it is invoked with.
		 *
		 * @param array $store Filled with [ $indexable, $post_obj, $post_id ].
		 */
		private function capture_filter_args( &$store ) {
			add_filter(
				'wpvdb_is_post_indexable',
				static function ( $indexable, $post_obj, $post_id ) use ( &$store ) {
					$store = array( $indexable, $post_obj, $post_id );
					return $indexable;
				},
				10,
				3
			);
		}

		/* ---- null / zero / negative / unbacked ------------------------------- */

		public function test_null_is_not_indexable_and_normalizes_to_zero() {
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertFalse( Indexability::is_indexable( null ) );
			$this->assertSame( array( false, null, 0 ), $captured );
		}

		public function test_zero_is_not_indexable() {
			$this->assertFalse( Indexability::is_indexable( 0 ) );
		}

		public function test_negative_id_normalizes_to_zero_for_the_filter() {
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertFalse( Indexability::is_indexable( -5 ) );
			// A negative id carries no valid identity; it is normalized to 0 so
			// the filter's third arg is always a non-negative doc id.
			$this->assertSame( array( false, null, 0 ), $captured );
		}

		public function test_unbacked_positive_id_is_not_indexable() {
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertFalse( Indexability::is_indexable( 4242 ) );
			$this->assertSame( array( false, null, 4242 ), $captured );
		}

		/* ---- ill-typed inputs: no warning, never coerced to post #1 ---------- */

		public function test_wp_error_is_not_indexable_and_not_coerced_to_post_one() {
			// Post #1 is public: if a WP_Error were coerced to int (== 1), the
			// gate would wrongly return true. It must resolve to id 0 instead.
			$this->make_post( 1, 'publish' );
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertFalse( Indexability::is_indexable( new \WP_Error( 'x', 'y' ) ) );
			$this->assertSame( array( false, null, 0 ), $captured );
		}

		public function test_idless_object_is_not_indexable_and_not_coerced() {
			$this->make_post( 1, 'publish' );
			$obj = new \stdClass();
			$this->assertFalse( Indexability::is_indexable( $obj ) );
		}

		public function test_array_input_is_not_indexable_and_not_coerced() {
			// Original ((int) $array) would resolve a non-empty array to 1.
			$this->make_post( 1, 'publish' );
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertFalse( Indexability::is_indexable( array( 'ID' => 1 ) ) );
			$this->assertFalse( Indexability::is_indexable( array( 1, 2 ) ) );
			$this->assertSame( array( false, null, 0 ), $captured );
		}

		public function test_non_numeric_string_is_not_indexable() {
			$this->make_post( 1, 'publish' );
			$this->assertFalse( Indexability::is_indexable( 'abc' ) );
			$this->assertFalse( Indexability::is_indexable( '' ) );
		}

		public function test_leading_numeric_string_and_booleans_not_coerced_to_one() {
			// (int) '1abc' and (int) true both resolve to 1; the gate must not
			// treat them as post ID 1.
			$this->make_post( 1, 'publish' );
			$this->assertFalse( Indexability::is_indexable( '1abc' ) );
			$this->assertFalse( Indexability::is_indexable( true ) );
			$this->assertFalse( Indexability::is_indexable( false ) );
		}

		public function test_digit_string_resolves_like_an_int() {
			$this->make_post( 5, 'publish' );
			$this->assertTrue( Indexability::is_indexable( '5' ) );
		}

		public function test_false_boolean_is_not_indexable() {
			$this->make_post( 1, 'publish' );
			// (int) false === 0 -> no backing post.
			$this->assertFalse( Indexability::is_indexable( false ) );
		}

		/**
		 * Battery assertion: none of the odd inputs emit a PHP diagnostic.
		 *
		 * Guards the core regression: casting an ID-less object to int emitted an
		 * E_WARNING and silently resolved to 1.
		 */
		public function test_odd_inputs_emit_no_php_diagnostic() {
			$this->make_post( 1, 'publish' );

			$diagnostics = array();
			set_error_handler(
				static function ( $errno, $errstr ) use ( &$diagnostics ) {
					$diagnostics[] = $errstr;
					return true;
				}
			);

			try {
				$inputs = array(
					null,
					0,
					-5,
					'',
					'abc',
					true,
					false,
					3.7,
					array(),
					array( 1, 2 ),
					array( 'ID' => 1 ),
					new \WP_Error( 'c', 'm' ),
					new \stdClass(),
					(object) array( 'foo' => 'bar' ),
					(object) array( 'ID' => 'not-a-number' ),
				);
				foreach ( $inputs as $input ) {
					Indexability::is_indexable( $input );
					Indexability::is_indexable( $input, true );
				}
			} finally {
				restore_error_handler();
			}

			$this->assertSame(
				array(),
				$diagnostics,
				'is_indexable() must not emit warnings/notices for ill-typed input.'
			);
		}

		/* ---- inputs that legitimately resolve -------------------------------- */

		public function test_numeric_string_resolves_to_public_post() {
			$this->make_post( 1, 'publish' );
			$this->assertTrue( Indexability::is_indexable( '1' ) );
			$this->assertFalse( Indexability::is_indexable( '999' ) );
		}

		public function test_duck_typed_object_with_id_resolves() {
			$this->make_post( 1, 'publish' );
			// A partial post-like object (exposes ID) still resolves.
			$this->assertTrue( Indexability::is_indexable( (object) array( 'ID' => 1 ) ) );
		}

		/* ---- must not false-negative legitimately public content ------------- */

		public function test_public_post_is_indexable_by_id_and_object() {
			$post = $this->make_post( 10, 'publish' );
			$this->assertTrue( Indexability::is_indexable( 10 ) );
			$this->assertTrue( Indexability::is_indexable( $post ) );
		}

		public function test_custom_public_status_is_indexable() {
			$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish', 'newsletter' );
			$this->make_post( 11, 'newsletter' );
			$this->assertTrue( Indexability::is_indexable( 11 ) );
		}

		/* ---- non-public truth table (statuses beyond IndexabilityTest) ------- */

		public function test_pending_future_trash_autodraft_are_not_indexable() {
			foreach ( array( 'pending', 'future', 'trash', 'auto-draft' ) as $i => $status ) {
				$id = 20 + $i;
				$this->make_post( $id, $status );
				$this->assertFalse(
					Indexability::is_indexable( $id ),
					"Status '$status' must not be indexable."
				);
			}
		}

		public function test_password_protected_public_post_is_not_indexable() {
			$this->make_post( 30, 'publish', 'secret' );
			$this->assertFalse( Indexability::is_indexable( 30 ) );
		}

		/* ---- filter argument contract --------------------------------------- */

		public function test_filter_receives_wp_post_for_backed_public_post() {
			$this->make_post( 40, 'publish' );
			$captured = null;
			$this->capture_filter_args( $captured );
			$this->assertTrue( Indexability::is_indexable( 40 ) );
			$this->assertTrue( $captured[0] );
			$this->assertInstanceOf( \WP_Post::class, $captured[1] );
			$this->assertSame( 40, $captured[2] );
		}

		public function test_filter_can_opt_in_an_unbacked_id() {
			add_filter(
				'wpvdb_is_post_indexable',
				static function ( $indexable, $post_obj, $post_id ) {
					return 777 === $post_id ? true : $indexable;
				},
				10,
				3
			);
			$this->assertTrue( Indexability::is_indexable( 777 ) );
			$this->assertFalse( Indexability::is_indexable( 778 ) );
		}

		public function test_filter_can_veto_a_public_post() {
			$this->make_post( 41, 'publish' );
			add_filter( 'wpvdb_is_post_indexable', '__return_false' );
			$this->assertFalse( Indexability::is_indexable( 41 ) );
		}

		/* ---- $fresh cache-bust contract ------------------------------------- */

		public function test_fresh_busts_the_posts_cache_by_id() {
			$this->make_post( 50, 'publish' );
			Indexability::is_indexable( 50, true );
			$this->assertSame(
				array( array( 50, 'posts' ) ),
				$GLOBALS['wpvdb_audit_cache_deletes']
			);
		}

		public function test_non_fresh_does_not_bust_the_cache() {
			$this->make_post( 51, 'publish' );
			Indexability::is_indexable( 51 );
			$this->assertSame( array(), $GLOBALS['wpvdb_audit_cache_deletes'] );
		}

		public function test_fresh_skips_cache_bust_for_invalid_ids() {
			// Guard: only bust when the normalized id is > 0. None of these
			// should touch the cache or fatal.
			$this->assertFalse( Indexability::is_indexable( 0, true ) );
			$this->assertFalse( Indexability::is_indexable( -1, true ) );
			$this->assertFalse( Indexability::is_indexable( null, true ) );
			$this->assertFalse( Indexability::is_indexable( new \WP_Error( 'x', 'y' ), true ) );
			$this->assertFalse( Indexability::is_indexable( array( 1 ), true ) );
			$this->assertSame( array(), $GLOBALS['wpvdb_audit_cache_deletes'] );
		}

		public function test_fresh_observes_a_mutated_password() {
			$post = $this->make_post( 52, 'publish' );
			$this->assertTrue( Indexability::is_indexable( 52 ) );

			// Simulate a concurrent "add password" edit landing between checks.
			$post->post_password = 'secret';
			$this->assertFalse( Indexability::is_indexable( 52, true ) );
		}

		public function test_fresh_object_input_rereads_by_id_not_stale_object() {
			// Pass a stale object (public) while the backing store now says
			// private; the gate must re-resolve by id and observe the change.
			$stale = new \WP_Post(
				array(
					'ID'          => 53,
					'post_status' => 'publish',
				)
			);
			$this->make_post( 53, 'private' );
			$this->assertFalse( Indexability::is_indexable( $stale, true ) );
			$this->assertSame( array( array( 53, 'posts' ) ), $GLOBALS['wpvdb_audit_cache_deletes'] );
		}
	}
}
