<?php
/**
 * WP-CLI `wpvdb embeddings` command.
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
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
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
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return array Scope args.
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

\WP_CLI::add_command( 'wpvdb embeddings', __NAMESPACE__ . '\\Embeddings_Command' );
