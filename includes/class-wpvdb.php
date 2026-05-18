<?php

namespace WPVDB;

class WPVDB {
	public function init() {
		// All includes are handled in plugin.php, so we don't need the includes method

		// Initialize classes
		Admin::init();
		Settings::init();

		// Register REST routes
		add_action( 'rest_api_init', array( REST::class, 'register_routes' ) );

		// Register actions to process embeddings
		add_action( 'wpvdb_process_embedding', array( WPVDB_Queue::class, 'process_item' ), 10, 1 );
		add_action( 'wpvdb_process_embedding_batch', array( WPVDB_Queue::class, 'process_batch' ), 10, 1 );

		// Add action to run queue immediately
		add_action( 'wpvdb_run_queue_now', array( $this, 'run_queue_immediately' ) );
	}

	/**
	 * Run the embedding queue immediately
	 *
	 * This is used when we want to process the queue right away rather than waiting for cron
	 *
	 * @param int $limit Maximum number of items to process (0 for unlimited)
	 * @return int Number of items processed
	 */
	public function run_queue_immediately( $limit = 0 ) {
		$processed = 0;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			// First check for batch actions
			$batch_actions = as_get_scheduled_actions(
				array(
					'hook'     => 'wpvdb_process_embedding_batch',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);

			if ( ! empty( $batch_actions ) ) {
				$action    = reset( $batch_actions );
				$action_id = $action->get_id();
				$args      = $action->get_args();

				// Remove this action from the queue to avoid duplicate processing
				as_unschedule_action( 'wpvdb_process_embedding_batch', $args, 'wpvdb' );

				// Process the batch
				$batch_results = WPVDB_Queue::process_batch( $args[0] );
				$processed    += count( array_filter( $batch_results ) );

				// Continue processing more batches if needed
				if ( $limit === 0 || $processed < $limit ) {
					WPVDB_Queue::maybe_process_next_batch();
				}

				return $processed;
			}

			// If no batch actions, check for individual actions
			$batch_size = WPVDB_Queue::get_batch_size();
			if ( $limit > 0 && $batch_size > $limit ) {
				$batch_size = $limit;
			}

			$actions = as_get_scheduled_actions(
				array(
					'hook'     => 'wpvdb_process_embedding',
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => $batch_size,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);

			if ( ! empty( $actions ) ) {
				$batch_items = array();

				foreach ( $actions as $action ) {
					$action_id = $action->get_id();
					$args      = $action->get_args();

					// Add to our batch
					if ( ! empty( $args[0] ) ) {
						$batch_items[] = $args[0];
					}

					// Remove this action from the queue to avoid duplicate processing
					as_unschedule_action( 'wpvdb_process_embedding', $args, 'wpvdb' );

					// If we've reached our limit, stop
					if ( $limit > 0 && count( $batch_items ) >= $limit ) {
						break;
					}
				}

				// Process the collected items as a batch
				if ( ! empty( $batch_items ) ) {
					$batch_results = WPVDB_Queue::process_batch( $batch_items );
					$processed    += count( array_filter( $batch_results ) );
				}
			}
		}

		return $processed;
	}
}
