<?php
/**
 * Class SearchTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Search;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the WPVDB Search service.
 */
class SearchTest extends TestCase {

	/**
	 * Merge caller arguments over the service defaults the way query() does.
	 *
	 * @param array $args Partial arguments.
	 * @return array
	 */
	private function args( array $args = array() ) {
		return array_merge( Search::default_args(), $args );
	}

	/**
	 * Every result column is selected except the vector blob.
	 */
	public function test_result_columns_exclude_the_embedding_blob() {
		$this->assertNotContains( 'embedding', Search::RESULT_COLUMNS );
		$this->assertContains( 'doc_id', Search::RESULT_COLUMNS );
		$this->assertContains( 'chunk_content', Search::RESULT_COLUMNS );
		$this->assertContains( 'summary', Search::RESULT_COLUMNS );
	}

	/**
	 * Defaults gate results to publicly visible posts.
	 */
	public function test_visibility_is_respected_by_default() {
		$defaults = Search::default_args();

		$this->assertTrue( $defaults['respect_visibility'] );
		$this->assertNull( $defaults['distance_threshold'] );
		$this->assertSame( 1, $defaults['over_fetch'] );
	}

	/**
	 * Every search is scoped to a single model.
	 */
	public function test_clauses_always_scope_to_the_model() {
		$clauses = Search::build_clauses( $this->args( array( 'model' => 'text-embedding-3-small' ) ) );

		$this->assertContains( 'e.model = %s', $clauses['where'] );
		$this->assertSame( array( 'text-embedding-3-small' ), $clauses['params'] );
	}

	/**
	 * The visibility gate adds a posts join and a status condition.
	 */
	public function test_clauses_join_posts_when_visibility_is_respected() {
		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => true,
				)
			)
		);

		$this->assertStringContainsString( 'LEFT JOIN', $clauses['join'] );
		$this->assertStringContainsString( 'p.ID = e.doc_id', $clauses['join'] );

		$where = implode( ' AND ', $clauses['where'] );
		$this->assertStringContainsString( "p.post_status = 'publish'", $where );
		$this->assertStringContainsString( "p.post_password = ''", $where );

		// Rows for non-post documents survive the gate.
		$this->assertStringContainsString( 'p.ID IS NULL', $where );
	}

	/**
	 * Opting out of the gate leaves the query unjoined.
	 */
	public function test_clauses_skip_the_join_when_visibility_is_not_respected() {
		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => false,
				)
			)
		);

		$this->assertSame( '', $clauses['join'] );
		$this->assertSame( array( 'e.model = %s' ), $clauses['where'] );
	}

	/**
	 * Clauses are filterable so callers can constrain the candidate set.
	 */
	public function test_clauses_are_filterable() {
		add_filter(
			'wpvdb_search_clauses',
			function ( $clauses ) {
				$clauses['where'][]  = 'e.doc_type = %s';
				$clauses['params'][] = 'product';
				return $clauses;
			}
		);

		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => false,
				)
			)
		);

		$this->assertSame( array( 'e.model = %s', 'e.doc_type = %s' ), $clauses['where'] );
		$this->assertSame( array( 'm', 'product' ), $clauses['params'] );
	}

	/**
	 * A search with neither text nor a vector is rejected before any API call.
	 */
	public function test_query_requires_text_or_a_vector() {
		$result = Search::query( array( 'text' => '   ' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wpvdb_search_no_input', $result->get_error_code() );
	}
}
