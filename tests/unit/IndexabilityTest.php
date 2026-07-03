<?php
/**
 * Unit tests for WPVDB\Indexability (the content visibility gate).
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\Indexability;

/**
 * @covers \WPVDB\Indexability
 */
class IndexabilityTest extends TestCase {

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

	public function test_public_post_is_indexable() {
		$this->make_post( 1, 'publish' );
		$this->assertTrue( Indexability::is_indexable( 1 ) );
	}

	public function test_password_protected_post_is_not_indexable() {
		$this->make_post( 2, 'publish', 'secret' );
		$this->assertFalse( Indexability::is_indexable( 2 ) );
	}

	public function test_private_post_is_not_indexable() {
		$this->make_post( 3, 'private' );
		$this->assertFalse( Indexability::is_indexable( 3 ) );
	}

	public function test_draft_post_is_not_indexable() {
		$this->make_post( 4, 'draft' );
		$this->assertFalse( Indexability::is_indexable( 4 ) );
	}

	public function test_unbacked_id_is_not_indexable_by_default() {
		$this->assertFalse( Indexability::is_indexable( 999 ) );
	}

	public function test_accepts_a_post_object() {
		$post = $this->make_post( 5, 'publish' );
		$this->assertTrue( Indexability::is_indexable( $post ) );
	}

	public function test_filter_can_force_an_unbacked_id_indexable() {
		// Targeted opt-in: only doc_id 777, mirroring the arbitrary-document bypass.
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

	public function test_filter_can_force_a_public_post_non_indexable() {
		$this->make_post( 6, 'publish' );
		add_filter( 'wpvdb_is_post_indexable', '__return_false' );
		$this->assertFalse( Indexability::is_indexable( 6 ) );
	}

	public function test_filter_receives_normalized_post_id_even_when_unbacked() {
		$received = null;
		add_filter(
			'wpvdb_is_post_indexable',
			static function ( $indexable, $post_obj, $post_id ) use ( &$received ) {
				$received = array( $indexable, $post_obj, $post_id );
				return $indexable;
			},
			10,
			3
		);
		Indexability::is_indexable( 4242 );
		$this->assertSame( array( false, null, 4242 ), $received );
	}

	public function test_fresh_recheck_observes_a_mutated_status() {
		$post = $this->make_post( 7, 'publish' );
		$this->assertTrue( Indexability::is_indexable( 7 ) );

		// Simulate a concurrent visibility change.
		$post->post_status = 'private';
		$this->assertFalse( Indexability::is_indexable( 7, true ) );
	}
}
