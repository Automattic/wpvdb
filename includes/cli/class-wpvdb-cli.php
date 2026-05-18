<?php
/**
 * WP CLI embedding and re-embed job commands for WPVDB.
 *
 * @package WPVDB
 */

namespace WPVDB\CLI;

use WPVDB\Embedding_Enqueuer;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage wpvdb embedding jobs.
 */
class Embeddings_Command extends \WP_CLI_Command {

	/**
	 * Start a new re-embed job.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Comma-separated list of post types. Defaults to the auto-embed
	 *   post types from Settings, or 'post'.
	 *
	 * [--post-status=<status>]
	 * : Comma-separated list of post statuses. Default: publish.
	 *
	 * [--since=<date>]
	 * : Only posts whose post_modified_gmt is >= this date. Accepts
	 *   YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.
	 *
	 * [--provider=<provider>]
	 * : Override the embedding provider for this job.
	 *
	 * [--model=<model>]
	 * : Override the embedding model for this job.
	 *
	 * [--only-missing]
	 * : Skip posts that already have any embedding rows.
	 *
	 * [--only-mismatched-model]
	 * : Skip posts that already have rows with the target model.
	 *
	 * [--limit=<n>]
	 * : Cap on total posts to enqueue across all pages.
	 *
	 * [--page-size=<n>]
	 * : Number of posts per AS enqueue page. Default 1000.
	 *
	 * [--dry-run]
	 * : Print the matching post count, do not create a job.
	 *
	 * [--force]
	 * : Bypass the scope-fingerprint dedup check.
	 *
	 * [--paused]
	 * : Create the job in paused state. Use `job resume` to start it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvdb embeddings enqueue --post-type=post --only-mismatched-model
	 *     wp wpvdb embeddings enqueue --dry-run --since=2026-05-01
	 */
	public function enqueue( $args, $assoc_args ) {
		$scope = self::scope_args_from_assoc( $assoc_args );

		$opts = array(
			'dry_run'  => isset( $assoc_args['dry-run'] ),
			'force'    => isset( $assoc_args['force'] ),
			'paused'   => isset( $assoc_args['paused'] ),
			'provider' => isset( $assoc_args['provider'] ) ? (string) $assoc_args['provider'] : '',
			'model'    => isset( $assoc_args['model'] ) ? (string) $assoc_args['model'] : '',
		);

		$result = Embedding_Enqueuer::start_job( $scope, $opts );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( $opts['dry_run'] ) {
			\WP_CLI::log( 'Dry run.' );
			\WP_CLI::log( "  provider: {$result['provider']}" );
			\WP_CLI::log( "  model:    {$result['model']}" );
			\WP_CLI::log( "  estimate: {$result['estimate']} posts match scope (pre only-* filters)" );
			return;
		}

		if ( ! empty( $result['dedup'] ) ) {
			\WP_CLI::warning( "An active job with the same scope already exists (job_id={$result['job_id']}). Use --force to bypass." );
			return;
		}

		\WP_CLI::success( "Job {$result['job_id']} created. provider={$result['provider']} model={$result['model']}" );
		if ( ! empty( $opts['paused'] ) ) {
			\WP_CLI::log( "Status: paused. Resume with: wp wpvdb embeddings job resume {$result['job_id']}" );
		} else {
			\WP_CLI::log( "First AS page scheduled. Check progress with: wp wpvdb embeddings job status {$result['job_id']}" );
		}
	}

	/**
	 * Build the scope args array from CLI flags.
	 */
	private static function scope_args_from_assoc( $assoc_args ) {
		$scope = array();
		if ( isset( $assoc_args['post-type'] ) ) {
			$scope['post_type'] = $assoc_args['post-type'];
		}
		if ( isset( $assoc_args['post-status'] ) ) {
			$scope['post_status'] = $assoc_args['post-status'];
		}
		if ( isset( $assoc_args['since'] ) ) {
			$scope['since'] = (string) $assoc_args['since'];
		}
		if ( isset( $assoc_args['only-missing'] ) ) {
			$scope['only_missing'] = true;
		}
		if ( isset( $assoc_args['only-mismatched-model'] ) ) {
			$scope['only_mismatched_model'] = true;
		}
		if ( isset( $assoc_args['limit'] ) ) {
			$scope['limit'] = (int) $assoc_args['limit'];
		}
		if ( isset( $assoc_args['page-size'] ) ) {
			$scope['page_size'] = (int) $assoc_args['page-size'];
		}
		return $scope;
	}
}

/**
 * Manage wpvdb embedding jobs (status, resume, cancel, list).
 */
class Jobs_Command extends \WP_CLI_Command {

	/**
	 * List recent jobs (newest first).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of jobs to list. Default 20.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function list( $args, $assoc_args ) {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$rows   = Embedding_Enqueuer::list_jobs( $limit );
		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No jobs found.' );
			return;
		}
		$fields = array( 'job_id', 'status', 'provider', 'model', 'queued_count', 'scanned_count', 'skipped_count', 'last_seen_id', 'upper_bound_id', 'updated_at' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Print a job's current state.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID.
	 */
	public function status( $args, $assoc_args ) {
		$job_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Missing job_id argument.' );
		}
		$job = Embedding_Enqueuer::get_job( $job_id );
		if ( ! $job ) {
			\WP_CLI::error( "Job {$job_id} not found." );
		}
		foreach ( $job as $key => $value ) {
			if ( 'scope_args' === $key && is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$value   = wp_json_encode( $decoded );
			}
			\WP_CLI::log( str_pad( (string) $key, 18 ) . ' : ' . (string) $value );
		}
	}

	/**
	 * Cancel a job. Already-scheduled AS pages will exit early on their next firing.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID.
	 */
	public function cancel( $args, $assoc_args ) {
		$job_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Missing job_id argument.' );
		}
		if ( Embedding_Enqueuer::cancel_job( $job_id ) ) {
			\WP_CLI::success( "Job {$job_id} canceled." );
		} else {
			\WP_CLI::error( "Failed to cancel job {$job_id}." );
		}
	}

	/**
	 * Resume a paused job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID.
	 */
	public function resume( $args, $assoc_args ) {
		$job_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Missing job_id argument.' );
		}
		$result = Embedding_Enqueuer::resume_job( $job_id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( $result ) {
			\WP_CLI::success( "Job {$job_id} resumed." );
		} else {
			\WP_CLI::error( "Job {$job_id} is not in a paused state." );
		}
	}
}

\WP_CLI::add_command( 'wpvdb embeddings', __NAMESPACE__ . '\\Embeddings_Command' );
\WP_CLI::add_command( 'wpvdb embeddings job', __NAMESPACE__ . '\\Jobs_Command' );
