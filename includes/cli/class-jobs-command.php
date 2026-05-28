<?php
/**
 * WP-CLI `wpvdb embeddings job` command.
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
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
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
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
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
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
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
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
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

\WP_CLI::add_command( 'wpvdb embeddings job', __NAMESPACE__ . '\\Jobs_Command' );
