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
	 * Scalar and list forms of the same filter canonicalize identically.
	 */
	public function test_canonical_filters_normalizes_scalars_and_order() {
		$a = Search::canonical_filters( array( 'post_type' => 'post' ) );
		$b = Search::canonical_filters( array( 'post_type' => array( 'post' ) ) );

		$this->assertSame( $a, $b );
		$this->assertSame( array( 'post_type' => array( 'post' ) ), $a );

		$c = Search::canonical_filters( array( 'post_type' => array( 'page', 'post' ) ) );
		$d = Search::canonical_filters( array( 'post_type' => array( 'post', 'page' ) ) );

		$this->assertSame( $c, $d );
	}

	/**
	 * Empty values never reach the canonical form.
	 */
	public function test_canonical_filters_drops_empties() {
		$filters = Search::canonical_filters(
			array(
				'post_type'  => array( '', null ),
				'post__in'   => array(),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filter key name, not a WP_Query argument.
				'tax_query'  => array(),
				'date_query' => array(),
			)
		);

		$this->assertSame( array(), $filters );
	}

	/**
	 * The `author` key is an alias for `author__in`.
	 */
	public function test_canonical_filters_aliases_author() {
		$filters = Search::canonical_filters( array( 'author' => '7' ) );

		$this->assertSame( array( 'author__in' => array( 7 ) ), $filters );
	}

	/**
	 * Different filters on identical text must not share a cache seed.
	 */
	public function test_filter_cache_seeds_differ_by_filter() {
		$none = Search::filters_cache_seed( array() );
		$news = Search::filters_cache_seed( array( 'post_type' => 'post' ) );
		$page = Search::filters_cache_seed( array( 'post_type' => 'page' ) );

		$this->assertSame( '', $none );
		$this->assertNotSame( $news, $page );
		$this->assertNotSame( '', $news );
	}

	/**
	 * Equivalent filters expressed differently share one cache seed.
	 */
	public function test_filter_cache_seed_is_stable_across_equivalent_input() {
		$a = Search::filters_cache_seed( array( 'post_type' => array( 'page', 'post' ) ) );
		$b = Search::filters_cache_seed( array( 'post_type' => array( 'post', 'page' ) ) );

		$this->assertSame( $a, $b );
	}

	/**
	 * Post-derived filters add the posts join and drop rows with no post.
	 */
	public function test_post_filters_force_the_posts_join() {
		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => false,
					'filters'            => array( 'post_type' => 'post' ),
				)
			)
		);

		$this->assertStringContainsString( 'LEFT JOIN', $clauses['join'] );

		$where = implode( ' AND ', $clauses['where'] );
		$this->assertStringContainsString( 'p.ID IS NOT NULL', $where );
		$this->assertStringContainsString( 'p.post_type IN (%s)', $where );
		$this->assertSame( array( 'm', 'post' ), $clauses['params'] );
	}

	/**
	 * The doc_type key filters the embeddings table directly, with no posts join.
	 */
	public function test_doc_type_filter_needs_no_posts_join() {
		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => false,
					'filters'            => array( 'doc_type' => 'product' ),
				)
			)
		);

		$this->assertSame( '', $clauses['join'] );

		$where = implode( ' AND ', $clauses['where'] );
		$this->assertStringContainsString( 'e.doc_type IN (%s)', $where );
		$this->assertStringNotContainsString( 'p.ID IS NOT NULL', $where );
		$this->assertSame( array( 'm', 'product' ), $clauses['params'] );
	}

	/**
	 * Exclusions bind as NOT IN with one placeholder per value.
	 */
	public function test_not_in_filters_bind_every_value() {
		$clauses = Search::build_clauses(
			$this->args(
				array(
					'model'              => 'm',
					'respect_visibility' => false,
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Filter key name, not a get_posts() argument.
					'filters'            => array( 'post__not_in' => array( 3, 1 ) ),
				)
			)
		);

		$where = implode( ' AND ', $clauses['where'] );
		$this->assertStringContainsString( 'p.ID NOT IN (%d, %d)', $where );
		$this->assertSame( array( 'm', 1, 3 ), $clauses['params'] );
	}

	/**
	 * Unknown strategies fall back to the safe pre-filter default.
	 */
	public function test_resolve_strategy_rejects_unknown_values() {
		$this->assertSame( 'prefilter', Search::resolve_strategy( 'nonsense' ) );
		$this->assertSame( 'prefilter', Search::resolve_strategy( '' ) );
		$this->assertSame( 'auto', Search::resolve_strategy( 'auto' ) );
		$this->assertSame( 'postfilter', Search::resolve_strategy( 'POSTFILTER' ) );
	}

	/**
	 * The override filter wins over the caller's strategy.
	 */
	public function test_strategy_filter_overrides_the_caller() {
		$filter = function () {
			return 'postfilter';
		};

		add_filter( 'wpvdb_search_strategy', $filter );

		try {
			$this->assertSame( 'postfilter', Search::resolve_strategy( 'prefilter' ) );
		} finally {
			remove_filter( 'wpvdb_search_strategy', $filter );
		}
	}

	/**
	 * The default strategy is the always-correct pre-filter.
	 */
	public function test_default_strategy_is_prefilter() {
		$defaults = Search::default_args();

		$this->assertSame( 'prefilter', $defaults['strategy'] );
		$this->assertSame( array(), $defaults['filters'] );
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
