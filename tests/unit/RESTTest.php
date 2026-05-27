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

	/**
	 * Test strict chunk index mode rejects missing and non-integer values.
	 *
	 * @dataProvider invalid_chunk_indexes
	 *
	 * @param mixed $chunk_index Invalid chunk index.
	 */
	public function test_strict_chunk_index_rejects_invalid_values( $chunk_index ) {
		$strict = function () {
			return true;
		};
		add_filter( 'wpvdb_strict_chunk_index', $strict );

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
		$this->assertSame( $chunk_index, $result->get_error_data()['chunk_index'] );
	}

	/**
	 * Test strict chunk index mode accepts integer values.
	 *
	 * @dataProvider valid_chunk_indexes
	 *
	 * @param mixed $chunk_index Valid chunk index.
	 */
	public function test_strict_chunk_index_allows_valid_integer_values( $chunk_index ) {
		$strict = function () {
			return true;
		};
		add_filter( 'wpvdb_strict_chunk_index', $strict );

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
		);
	}
}
