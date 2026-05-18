<?php
/**
 * Class EmbeddingEnqueuerTest
 *
 * Unit tests for WPVDB\Embedding_Enqueuer covering pure helpers:
 * scope arg normalization and fingerprint computation. DB-dependent
 * surfaces (start_job, process_page, lock acquisition) live in the
 * integration suite where a real wpdb is available.
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\Embedding_Enqueuer;

class EmbeddingEnqueuerTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	}

	protected function tearDown(): void {
		if ( $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	public function test_normalize_args_returns_defaults_for_empty_input() {
		$normalized = Embedding_Enqueuer::normalize_args( [] );

		$this->assertIsArray( $normalized );
		$this->assertEquals( [ 'publish' ], $normalized['post_status'] );
		$this->assertIsArray( $normalized['post_type'] );
		$this->assertNotEmpty( $normalized['post_type'] );
		$this->assertEquals( '', $normalized['since'] );
		$this->assertFalse( $normalized['only_missing'] );
		$this->assertFalse( $normalized['only_mismatched_model'] );
		$this->assertEquals( 0, $normalized['limit'] );
		$this->assertEquals( Embedding_Enqueuer::DEFAULT_PAGE_SIZE, $normalized['page_size'] );
	}

	public function test_normalize_args_accepts_csv_string_lists() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'post_type'   => 'post,page,custom',
				'post_status' => 'publish,draft',
			]
		);

		$this->assertEquals( [ 'custom', 'page', 'post' ], $normalized['post_type'] );
		$this->assertEquals( [ 'draft', 'publish' ], $normalized['post_status'] );
	}

	public function test_normalize_args_dedupes_and_sorts() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'post_type'   => [ 'page', 'post', 'page', 'post' ],
				'post_status' => [ 'publish', 'publish' ],
			]
		);

		$this->assertEquals( [ 'page', 'post' ], $normalized['post_type'] );
		$this->assertEquals( [ 'publish' ], $normalized['post_status'] );
	}

	public function test_normalize_args_rejects_empty_post_type() {
		$result = Embedding_Enqueuer::normalize_args(
			[
				'post_type' => [],
			]
		);

		$this->assertInstanceOf( '\WP_Error', $result );
	}

	public function test_normalize_args_rejects_invalid_since() {
		$result = Embedding_Enqueuer::normalize_args(
			[
				'since' => 'last tuesday',
			]
		);

		$this->assertInstanceOf( '\WP_Error', $result );
	}

	public function test_normalize_args_accepts_yyyy_mm_dd_since() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'since' => '2026-05-12',
			]
		);

		$this->assertEquals( '2026-05-12', $normalized['since'] );
	}

	public function test_normalize_args_accepts_full_datetime_since() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'since' => '2026-05-12 17:30:00',
			]
		);

		$this->assertEquals( '2026-05-12 17:30:00', $normalized['since'] );
	}

	public function test_normalize_args_clamps_page_size_to_max() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'page_size' => 999999,
			]
		);

		$this->assertEquals( Embedding_Enqueuer::MAX_PAGE_SIZE, $normalized['page_size'] );
	}

	public function test_normalize_args_uses_default_when_page_size_invalid() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'page_size' => -5,
			]
		);

		$this->assertEquals( Embedding_Enqueuer::DEFAULT_PAGE_SIZE, $normalized['page_size'] );
	}

	public function test_normalize_args_clamps_limit_to_zero() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'limit' => -100,
			]
		);

		$this->assertEquals( 0, $normalized['limit'] );
	}

	public function test_normalize_args_coerces_only_flags_to_bool() {
		$normalized = Embedding_Enqueuer::normalize_args(
			[
				'only_missing'          => 1,
				'only_mismatched_model' => 'yes',
			]
		);

		$this->assertTrue( $normalized['only_missing'] );
		$this->assertTrue( $normalized['only_mismatched_model'] );
	}

	public function test_compute_fingerprint_is_deterministic() {
		$args = Embedding_Enqueuer::normalize_args( [ 'post_type' => 'post' ] );

		$f1 = Embedding_Enqueuer::compute_fingerprint( $args, 'automattic', 'nomic-embed-text-v2-moe' );
		$f2 = Embedding_Enqueuer::compute_fingerprint( $args, 'automattic', 'nomic-embed-text-v2-moe' );

		$this->assertEquals( $f1, $f2 );
		$this->assertEquals( 64, strlen( $f1 ), 'Fingerprint should be a 64-char SHA-256 hex digest.' );
	}

	public function test_compute_fingerprint_differs_when_model_changes() {
		$args = Embedding_Enqueuer::normalize_args( [] );

		$f1 = Embedding_Enqueuer::compute_fingerprint( $args, 'automattic', 'nomic-embed-text-v2-moe' );
		$f2 = Embedding_Enqueuer::compute_fingerprint( $args, 'automattic', 'text-embedding-3-small' );

		$this->assertNotEquals( $f1, $f2 );
	}

	public function test_compute_fingerprint_differs_when_provider_changes() {
		$args = Embedding_Enqueuer::normalize_args( [] );

		$f1 = Embedding_Enqueuer::compute_fingerprint( $args, 'automattic', 'text-embedding-3-small' );
		$f2 = Embedding_Enqueuer::compute_fingerprint( $args, 'openai', 'text-embedding-3-small' );

		$this->assertNotEquals( $f1, $f2 );
	}

	public function test_compute_fingerprint_differs_when_args_change() {
		$base = Embedding_Enqueuer::normalize_args( [] );
		$with_since = Embedding_Enqueuer::normalize_args( [ 'since' => '2026-05-12' ] );

		$f1 = Embedding_Enqueuer::compute_fingerprint( $base, 'openai', 'text-embedding-3-small' );
		$f2 = Embedding_Enqueuer::compute_fingerprint( $with_since, 'openai', 'text-embedding-3-small' );

		$this->assertNotEquals( $f1, $f2 );
	}

	public function test_compute_fingerprint_stable_across_input_orderings() {
		// Same scope, different input orderings should produce identical fingerprints
		// because normalize_args sorts list values.
		$args_a = Embedding_Enqueuer::normalize_args(
			[
				'post_type'   => [ 'page', 'post' ],
				'post_status' => [ 'draft', 'publish' ],
			]
		);
		$args_b = Embedding_Enqueuer::normalize_args(
			[
				'post_type'   => 'post,page',
				'post_status' => 'publish,draft',
			]
		);

		$f1 = Embedding_Enqueuer::compute_fingerprint( $args_a, 'openai', 'text-embedding-3-small' );
		$f2 = Embedding_Enqueuer::compute_fingerprint( $args_b, 'openai', 'text-embedding-3-small' );

		$this->assertEquals( $f1, $f2 );
	}

	public function test_find_active_model_migration_job_returns_matching_target() {
		$this->use_jobs_wpdb(
			[
				$this->job_row( 9, Embedding_Enqueuer::STATUS_RUNNING, 'openai', 'text-embedding-3-small', [ 'only_mismatched_model' => true ] ),
				$this->job_row( 8, Embedding_Enqueuer::STATUS_RUNNING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_missing' => true ] ),
				$this->job_row( 7, Embedding_Enqueuer::STATUS_COMPLETED, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
				$this->job_row( 6, Embedding_Enqueuer::STATUS_PENDING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
			]
		);

		$job = Embedding_Enqueuer::find_active_model_migration_job( 'automattic', 'nomic-embed-text-v2-moe' );

		$this->assertIsArray( $job );
		$this->assertSame( 6, $job['job_id'] );
	}

	public function test_list_active_model_migration_jobs_returns_all_matching_targets() {
		$this->use_jobs_wpdb(
			[
				$this->job_row( 13, Embedding_Enqueuer::STATUS_RUNNING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
				$this->job_row( 12, Embedding_Enqueuer::STATUS_PAUSED, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
				$this->job_row( 11, Embedding_Enqueuer::STATUS_PENDING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_missing' => true ] ),
			]
		);

		$jobs = Embedding_Enqueuer::list_active_model_migration_jobs( 'automattic', 'nomic-embed-text-v2-moe' );

		$this->assertCount( 2, $jobs );
		$this->assertSame( [ 13, 12 ], array_column( $jobs, 'job_id' ) );
	}

	public function test_find_active_model_migration_job_can_find_any_target() {
		$this->use_jobs_wpdb(
			[
				$this->job_row( 11, Embedding_Enqueuer::STATUS_PAUSED, 'openai', 'text-embedding-3-small', [ 'only_mismatched_model' => true ] ),
				$this->job_row( 10, Embedding_Enqueuer::STATUS_RUNNING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
			]
		);

		$job = Embedding_Enqueuer::find_active_model_migration_job();

		$this->assertIsArray( $job );
		$this->assertSame( 11, $job['job_id'] );
	}

	public function test_find_active_model_migration_job_returns_null_without_matching_scope() {
		$this->use_jobs_wpdb(
			[
				$this->job_row( 12, Embedding_Enqueuer::STATUS_RUNNING, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_missing' => true ] ),
				$this->job_row( 11, Embedding_Enqueuer::STATUS_COMPLETED, 'automattic', 'nomic-embed-text-v2-moe', [ 'only_mismatched_model' => true ] ),
			]
		);

		$this->assertNull( Embedding_Enqueuer::find_active_model_migration_job( 'automattic', 'nomic-embed-text-v2-moe' ) );
	}

	private function use_jobs_wpdb( $rows ) {
		$GLOBALS['wpdb'] = new class( $rows ) {
			public $prefix = 'wp_';
			public $last_query = '';
			public $last_args = [];
			private $rows;

			public function __construct( $rows ) {
				$this->rows = $rows;
			}

			public function prepare( $query, ...$args ) {
				if ( count( $args ) === 1 && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->last_query = $query;
				$this->last_args = $args;
				return $query;
			}

			public function get_results( $query = null, $output = OBJECT ) {
				$this->last_query = $query;
				return $this->rows;
			}
		};
	}

	private function job_row( $job_id, $status, $provider, $model, $scope_args ) {
		return [
			'job_id' => $job_id,
			'status' => $status,
			'provider' => $provider,
			'model' => $model,
			'scope_args' => wp_json_encode( $scope_args ),
		];
	}
}
