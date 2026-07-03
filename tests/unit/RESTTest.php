<?php
/**
 * Class RESTTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\REST;

/**
 * Test case for WPVDB REST helpers.
 */
class RESTTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// These tests exercise chunk_index / embedding validation downstream of
		// the visibility gate in insert_embedding_row, so doc_id 123 must be an
		// indexable (public) post for the gate to pass through to that logic.
		$GLOBALS['wpvdb_test_posts']             = array(
			123 => new \WP_Post(
				array(
					'ID'          => 123,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			),
		);
		$GLOBALS['wpvdb_test_viewable_statuses'] = array( 'publish' );
	}

	protected function tearDown(): void {
		$GLOBALS['wpvdb_test_posts'] = array();
		$GLOBALS['_wp_filters']      = array();
		parent::tearDown();
	}

	/**
	 * The storage gate rejects an unbacked doc_id by default (no such post).
	 */
	public function test_insert_embedding_row_rejects_unbacked_doc_id() {
		$result = REST::insert_embedding_row( 999999, 'chunk-0', 'content', '', array( 0.1, 0.2 ), 'm', 'post', 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wpvdb_not_indexable', $result->get_error_code() );
	}

	/**
	 * The storage gate rejects a non-public (private) post.
	 */
	public function test_insert_embedding_row_rejects_private_post() {
		$GLOBALS['wpvdb_test_posts'][555] = new \WP_Post(
			array(
				'ID'          => 555,
				'post_status' => 'private',
				'post_type'   => 'post',
			)
		);
		$result = REST::insert_embedding_row( 555, 'chunk-0', 'content', '', array( 0.1, 0.2 ), 'm', 'post', 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wpvdb_not_indexable', $result->get_error_code() );
	}

	/**
	 * The wpvdb_is_post_indexable filter can opt a specific unbacked doc_id in,
	 * bypassing the gate so downstream validation runs (proven by getting the
	 * embedding_invalid error, not wpvdb_not_indexable).
	 */
	public function test_insert_embedding_row_filter_opt_in_bypasses_gate() {
		add_filter(
			'wpvdb_is_post_indexable',
			static function ( $indexable, $post_obj, $post_id ) {
				return 424242 === $post_id ? true : $indexable;
			},
			10,
			3
		);
		$result = REST::insert_embedding_row( 424242, 'chunk-0', 'content', '', array( 0.0 ), 'm', 'post', 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_invalid', $result->get_error_code() );
	}

	/**
	 * Test strict chunk index mode rejects invalid chunk index values.
	 *
	 * @dataProvider invalid_chunk_indexes
	 *
	 * @param mixed $chunk_index Invalid chunk index.
	 */
	public function test_strict_chunk_index_rejects_invalid_values( $chunk_index ) {
		$strict = function () {
			return true;
		};
		add_filter( 'wpvdb_strict_chunk_index', $strict, 10, 0 );

		try {
			$result = REST::insert_embedding_row(
				123,
				'chunk-0',
				'Chunk content',
				'',
				array( 0.0 ),
				'test-model',
				'post',
				$chunk_index
			);
		} finally {
			remove_filter( 'wpvdb_strict_chunk_index', $strict );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'chunk_index_invalid', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertSame( 123, $error_data['doc_id'] );
		$this->assertSame( $chunk_index, $error_data['chunk_index'] );
		$this->assertSame( 400, $error_data['status'] );
	}

	/**
	 * Test strict chunk index mode accepts valid chunk index values.
	 *
	 * @dataProvider valid_chunk_indexes
	 *
	 * @param mixed $chunk_index Valid chunk index.
	 */
	public function test_strict_chunk_index_allows_valid_integer_values( $chunk_index ) {
		$strict = function () {
			return true;
		};
		add_filter( 'wpvdb_strict_chunk_index', $strict, 10, 0 );

		try {
			$result = REST::insert_embedding_row(
				123,
				'chunk-0',
				'Chunk content',
				'',
				array( 0.0 ),
				'test-model',
				'post',
				$chunk_index
			);
		} finally {
			remove_filter( 'wpvdb_strict_chunk_index', $strict );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_invalid', $result->get_error_code() );
		$this->assertSame( (int) $chunk_index, $result->get_error_data()['chunk_index'] );
	}

	/**
	 * Test default mode does not reject invalid chunk index values.
	 *
	 * @dataProvider invalid_chunk_indexes
	 *
	 * @param mixed $chunk_index Invalid chunk index.
	 */
	public function test_default_chunk_index_mode_does_not_reject_invalid_values( $chunk_index ) {
		$result = REST::insert_embedding_row(
			123,
			'chunk-0',
			'Chunk content',
			'',
			array( 0.0 ),
			'test-model',
			'post',
			$chunk_index
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_invalid', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertSame( 123, $error_data['doc_id'] );
		$this->assertSame( 400, $error_data['status'] );
	}

	/**
	 * Test default mode keeps the legacy null-to-zero fallback.
	 */
	public function test_default_chunk_index_mode_preserves_null_fallback() {
		$result = REST::insert_embedding_row(
			123,
			'chunk-0',
			'Chunk content',
			'',
			array( 0.0 ),
			'test-model',
			'post',
			null
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_invalid', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data()['chunk_index'] );
	}

	/**
	 * Invalid chunk index provider.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public function invalid_chunk_indexes() {
		return array(
			'null'           => array( null ),
			'text'           => array( 'abc' ),
			'negative int'   => array( -1 ),
			'negative text'  => array( '-1' ),
			'fraction float' => array( 1.5 ),
			'fraction text'  => array( '1.5' ),
			'boolean'        => array( true ),
			'false'          => array( false ),
			'empty string'   => array( '' ),
			'spaced number'  => array( ' 1' ),
			'float one'      => array( 1.0 ),
			'float zero'     => array( 0.0 ),
			'plus one'       => array( '+1' ),
			'exponent'       => array( '1e3' ),
			'oversized'      => array( PHP_INT_MAX . '0' ),
		);
	}

	/**
	 * Valid chunk index provider.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public function valid_chunk_indexes() {
		return array(
			'zero int'     => array( 0 ),
			'positive int' => array( 3 ),
			'zero text'    => array( '0' ),
			'number text'  => array( '3' ),
			'max text'     => array( (string) PHP_INT_MAX ),
		);
	}
}
