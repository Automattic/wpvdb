<?php
namespace WPVDB;

defined( 'ABSPATH' ) || exit;

/**
 * Resumable re-embed job primitive.
 *
 * Pages through matching posts via keyset cursor, schedules embedding work
 * through the existing queue. State persists in wp_wpvdb_reindex_jobs.
 */
class Embedding_Enqueuer {

	const AS_HOOK  = 'wpvdb_enqueue_reembed_page';
	const AS_GROUP = 'wpvdb';

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELED  = 'canceled';

	const DEFAULT_PAGE_SIZE = 1000;
	const MIN_PAGE_SIZE     = 1;
	const MAX_PAGE_SIZE     = 5000;
	const LOCK_TTL_SECONDS  = 120;

	/**
	 * Get the jobs table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wpvdb_reindex_jobs';
	}

	/**
	 * Get the embeddings table name.
	 */
	private static function embeddings_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpvdb_embeddings';
	}

	/**
	 * Normalize and validate raw scope args into the canonical shape stored
	 * with the job. Returned array is suitable for fingerprinting.
	 *
	 * @param array $args
	 * @return array|\WP_Error
	 */
	public static function normalize_args( $args ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$defaults = array(
			'post_type'             => self::default_post_types(),
			'post_status'           => array( 'publish' ),
			'since'                 => '',
			'only_missing'          => false,
			'only_mismatched_model' => false,
			'limit'                 => 0,
			'page_size'             => self::DEFAULT_PAGE_SIZE,
		);

		$merged = array_merge( $defaults, $args );

		$post_type = self::ensure_string_list( $merged['post_type'] );
		if ( empty( $post_type ) ) {
			return new \WP_Error( 'wpvdb_enqueuer_bad_args', 'post_type must include at least one value.' );
		}
		sort( $post_type );

		$post_status = self::ensure_string_list( $merged['post_status'] );
		if ( empty( $post_status ) ) {
			return new \WP_Error( 'wpvdb_enqueuer_bad_args', 'post_status must include at least one value.' );
		}
		sort( $post_status );

		$since = is_string( $merged['since'] ) ? trim( $merged['since'] ) : '';
		if ( $since !== '' && ! self::looks_like_date( $since ) ) {
			return new \WP_Error( 'wpvdb_enqueuer_bad_args', 'since must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.' );
		}

		$page_size = (int) $merged['page_size'];
		if ( $page_size < self::MIN_PAGE_SIZE ) {
			$page_size = self::DEFAULT_PAGE_SIZE;
		}
		if ( $page_size > self::MAX_PAGE_SIZE ) {
			$page_size = self::MAX_PAGE_SIZE;
		}

		$limit = max( 0, (int) $merged['limit'] );

		return array(
			'post_type'             => $post_type,
			'post_status'           => $post_status,
			'since'                 => $since,
			'only_missing'          => (bool) $merged['only_missing'],
			'only_mismatched_model' => (bool) $merged['only_mismatched_model'],
			'limit'                 => $limit,
			'page_size'             => $page_size,
		);
	}

	/**
	 * Resolve default post types from settings, falling back to ['post'].
	 */
	private static function default_post_types() {
		if ( class_exists( __NAMESPACE__ . '\\Settings' ) && method_exists( Settings::class, 'get_auto_embed_post_types' ) ) {
			$types = Settings::get_auto_embed_post_types();
			if ( is_array( $types ) && ! empty( $types ) ) {
				return array_values( array_unique( array_map( 'strval', $types ) ) );
			}
		}
		return array( 'post' );
	}

	private static function ensure_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		} elseif ( ! is_array( $value ) ) {
			return array();
		}
		$value = array_map( 'strval', $value );
		$value = array_filter(
			$value,
			static function ( $v ) {
				return $v !== '';
			}
		);
		return array_values( array_unique( $value ) );
	}

	private static function looks_like_date( $value ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value );
	}

	/**
	 * Compute deterministic fingerprint from canonical args + provider + model.
	 */
	public static function compute_fingerprint( $args, $provider, $model ) {
		$payload = array(
			'args'     => $args,
			'provider' => (string) $provider,
			'model'    => (string) $model,
		);
		return hash( 'sha256', wp_json_encode( $payload ) );
	}

	/**
	 * Resolve the provider + model snapshot at job-creation time.
	 *
	 * When only --provider is given, the model defaults to that provider's
	 * registry default rather than the active provider's default. Otherwise
	 * we'd pair the override provider with the wrong model.
	 */
	private static function resolve_provider_model( $override_provider, $override_model ) {
		$has_provider_override = is_string( $override_provider ) && $override_provider !== '';
		$has_model_override    = is_string( $override_model ) && $override_model !== '';

		if ( $has_provider_override ) {
			$provider = $override_provider;
		} else {
			$provider = Settings::get_active_provider();
		}
		if ( empty( $provider ) ) {
			$provider = 'openai';
		}

		if ( $has_model_override ) {
			$model = $override_model;
		} elseif ( $has_provider_override ) {
			$model = Models::get_default_model_for_provider( $provider );
		} else {
			$model = Settings::get_default_model();
		}

		return array( (string) $provider, (string) $model );
	}

	/**
	 * Snapshot the current maximum post ID matching the scope. Used as the
	 * upper bound so a long-running job does not chase newly-created posts.
	 */
	private static function snapshot_upper_bound( $args ) {
		global $wpdb;
		$where            = self::build_scope_where_sql( $args, $params );
		$params_with_zero = array_merge( array( 0 ), $params );
		$sql              = "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE ID > %d {$where}";
		$prepared         = $wpdb->prepare( $sql, $params_with_zero );
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Build the WHERE fragment shared by snapshot + page queries.
	 * Always appended to a query that already has a "WHERE ID > %d" clause;
	 * caller supplies the leading column.
	 *
	 * Populates $params (by reference) with placeholder values in order.
	 */
	private static function build_scope_where_sql( $args, &$params ) {
		$params  = array();
		$clauses = array();

		$post_types   = $args['post_type'];
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$clauses[]    = "post_type IN ({$placeholders})";
		foreach ( $post_types as $pt ) {
			$params[] = $pt;
		}

		$post_status  = $args['post_status'];
		$placeholders = implode( ',', array_fill( 0, count( $post_status ), '%s' ) );
		$clauses[]    = "post_status IN ({$placeholders})";
		foreach ( $post_status as $ps ) {
			$params[] = $ps;
		}

		if ( ! empty( $args['since'] ) ) {
			$since     = strlen( $args['since'] ) === 10 ? $args['since'] . ' 00:00:00' : $args['since'];
			$clauses[] = 'post_modified_gmt >= %s';
			$params[]  = $since;
		}

		return 'AND ' . implode( ' AND ', $clauses );
	}

	/**
	 * Start a new job. Returns ['job_id' => N, 'dedup' => bool, 'estimate' => int]
	 * or WP_Error on validation failure.
	 *
	 * Options accepted in $opts:
	 *   - dry_run:  bool. If true, returns estimate only, no row created.
	 *   - force:    bool. If true, bypass fingerprint dedup.
	 *   - provider: string override.
	 *   - model:    string override.
	 *   - paused:   bool. If true, create row but do not schedule first page.
	 */
	public static function start_job( $args, $opts = array() ) {
		if ( \wpvdb_is_playground_runtime() ) {
			return new \WP_Error(
				'wpvdb_enqueuer_playground_runtime',
				'Re-embed jobs are disabled in the Playground runtime.'
			);
		}

		global $wpdb;

		$normalized = self::normalize_args( $args );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		// The enqueuer's paged self-rescheduling depends on Action Scheduler.
		// Refuse to create a non-dry-run job if AS is unavailable, otherwise
		// the job row would sit in 'pending' forever with no page ever firing.
		if ( empty( $opts['dry_run'] ) && ! self::action_scheduler_available() ) {
			return new \WP_Error(
				'wpvdb_enqueuer_no_action_scheduler',
				'Action Scheduler is not available; cannot create a re-embed job. ' .
				'Re-activate the plugin or ensure vendor/woocommerce/action-scheduler is loaded.'
			);
		}

		list($provider, $model) = self::resolve_provider_model(
			isset( $opts['provider'] ) ? $opts['provider'] : '',
			isset( $opts['model'] ) ? $opts['model'] : ''
		);

		$fingerprint = self::compute_fingerprint( $normalized, $provider, $model );

		if ( ! empty( $opts['dry_run'] ) ) {
			$estimate = self::estimate_total( $normalized );
			return array(
				'job_id'      => 0,
				'dedup'       => false,
				'estimate'    => $estimate,
				'provider'    => $provider,
				'model'       => $model,
				'fingerprint' => $fingerprint,
			);
		}

		if ( empty( $opts['force'] ) ) {
			$existing = self::find_active_by_fingerprint( $fingerprint );
			if ( $existing ) {
				return array(
					'job_id'      => (int) $existing['job_id'],
					'dedup'       => true,
					'estimate'    => null,
					'provider'    => $provider,
					'model'       => $model,
					'fingerprint' => $fingerprint,
				);
			}
		}

		$upper_bound = self::snapshot_upper_bound( $normalized );

		// created_at + updated_at use NOW() so the timestamp column shares the
		// DB clock that release_lock/acquire_lock use. Avoids the WP-timezone
		// vs DB-timezone skew that a current_time('mysql') write would produce.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table_name() . '
             (status, provider, model, scope_args, fingerprint,
              last_seen_id, upper_bound_id, scanned_count, queued_count, skipped_count,
              lock_until, last_error, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, 0, %d, 0, 0, 0, NULL, NULL, NOW(), NOW())',
				self::STATUS_PENDING,
				$provider,
				$model,
				wp_json_encode( $normalized ),
				$fingerprint,
				$upper_bound
			)
		);

		if ( $inserted === false ) {
			return new \WP_Error( 'wpvdb_enqueuer_insert_failed', $wpdb->last_error );
		}

		$job_id = (int) $wpdb->insert_id;

		if ( empty( $opts['paused'] ) ) {
			self::schedule_next_page( $job_id, 0 );
		} else {
			self::set_status( $job_id, self::STATUS_PAUSED );
		}

		return array(
			'job_id'      => $job_id,
			'dedup'       => false,
			'estimate'    => null,
			'provider'    => $provider,
			'model'       => $model,
			'fingerprint' => $fingerprint,
		);
	}

	/**
	 * Rough count of posts that match the scope, ignoring only_* filters.
	 * Cheap upper bound for dry-run.
	 */
	public static function estimate_total( $args ) {
		global $wpdb;
		$where            = self::build_scope_where_sql( $args, $params );
		$params_with_zero = array_merge( array( 0 ), $params );
		$sql              = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d {$where}";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params_with_zero ) );
	}

	/**
	 * Look up an active (pending / running / paused) job by fingerprint.
	 */
	private static function find_active_by_fingerprint( $fingerprint ) {
		global $wpdb;
		$active       = array( self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED );
		$placeholders = implode( ',', array_fill( 0, count( $active ), '%s' ) );
		$params       = array_merge( array( $fingerprint ), $active );
		$sql          = $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . " WHERE fingerprint = %s AND status IN ({$placeholders}) ORDER BY job_id DESC LIMIT 1",
			$params
		);
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Acquire the per-job page lock atomically and mark status=running.
	 *
	 * Uses the DB clock (NOW()) for both the write and the expiry comparison so
	 * site-timezone vs DB-timezone mismatches cannot make a lock either expire
	 * instantly or stay stuck for hours. Generates a per-acquisition lock_token
	 * so release_lock can refuse stale workers whose lock has been taken over.
	 *
	 * Returns [job_row, token] on success, or null if another worker owns the
	 * page or the job is no longer in an active state.
	 *
	 * @param int $job_id
	 * @return array|null
	 */
	private static function acquire_lock( $job_id ) {
		global $wpdb;
		$token = self::generate_lock_token();

		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . '
             SET lock_token = %s,
                 lock_until = DATE_ADD(NOW(), INTERVAL %d SECOND),
                 status = %s,
                 updated_at = NOW()
             WHERE job_id = %d
               AND status IN (%s, %s)
               AND (lock_until IS NULL OR lock_until < NOW())',
				$token,
				self::LOCK_TTL_SECONDS,
				self::STATUS_RUNNING,
				$job_id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING
			)
		);

		if ( $affected === false || $affected === 0 ) {
			return null;
		}

		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return null;
		}

		return array( $job, $token );
	}

	/**
	 * Release the lock and apply page-result deltas only if we still own it
	 * AND the job is still in the running state.
	 *
	 * The WHERE guard on lock_token = %s ensures a stale worker whose lock
	 * already expired cannot overwrite the cursor or status of the new owner.
	 * The additional guard on status = 'running' ensures that a cancel issued
	 * mid-page cannot be overwritten back to pending/completed by the worker.
	 *
	 * @return int|false Number of rows affected, or false on DB error.
	 */
	private static function release_lock( $job_id, $token, $cursor_advance = null, $scanned_delta = 0, $queued_delta = 0, $skipped_delta = 0, $finalize_status = null ) {
		global $wpdb;

		$sets   = array(
			'lock_until = NULL',
			'lock_token = NULL',
			'scanned_count = scanned_count + ' . (int) $scanned_delta,
			'queued_count = queued_count + ' . (int) $queued_delta,
			'skipped_count = skipped_count + ' . (int) $skipped_delta,
			'updated_at = NOW()',
		);
		$params = array();

		if ( $cursor_advance !== null ) {
			$sets[]   = 'last_seen_id = %d';
			$params[] = (int) $cursor_advance;
		}

		$sets[]   = 'status = %s';
		$params[] = $finalize_status !== null ? $finalize_status : self::STATUS_PENDING;

		$params[] = (int) $job_id;
		$params[] = (string) $token;
		$params[] = self::STATUS_RUNNING;

		$sql = 'UPDATE ' . self::table_name() . ' SET ' . implode( ', ', $sets ) . '
                WHERE job_id = %d AND lock_token = %s AND status = %s';

		return $wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	private static function generate_lock_token() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return uniqid( 'wpvdb_', true );
	}

	/**
	 * AS callback: process one enqueue page for the given job.
	 */
	public static function process_page( $job_id ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return;
		}

		$acquired = self::acquire_lock( $job_id );
		if ( ! $acquired ) {
			return;
		}
		list($job, $token) = $acquired;

		try {
			$args = json_decode( $job['scope_args'], true );
			if ( ! is_array( $args ) ) {
				self::release_lock( $job_id, $token, null, 0, 0, 0, self::STATUS_FAILED );
				self::record_error( $job_id, 'scope_args is not valid JSON' );
				return;
			}

			$cursor         = (int) $job['last_seen_id'];
			$upper_bound    = (int) $job['upper_bound_id'];
			$page_size      = (int) $args['page_size'];
			$limit_total    = (int) $args['limit'];
			$already_queued = (int) $job['queued_count'];

			$effective_page_size = $page_size;
			if ( $limit_total > 0 ) {
				$remaining = $limit_total - $already_queued;
				if ( $remaining <= 0 ) {
					self::release_lock( $job_id, $token, $cursor, 0, 0, 0, self::STATUS_COMPLETED );
					return;
				}
				$effective_page_size = min( $page_size, $remaining );
			}

			$start_time = microtime( true );
			// Clamp the budget to >= 1s so a filter that returns 0 or a negative
			// value cannot starve the loop and cause an infinite reschedule with
			// no cursor progress.
			$budget = max( 1, (int) apply_filters( 'wpvdb_enqueue_page_budget_seconds', 20 ) );

			$posts = self::fetch_page_posts( $cursor, $upper_bound, $args, $effective_page_size );

			if ( empty( $posts ) ) {
				self::release_lock( $job_id, $token, $cursor, 0, 0, 0, self::STATUS_COMPLETED );
				return;
			}

			$last_examined = $cursor;
			$scanned       = 0;
			$queued        = 0;
			$skipped       = 0;

			$skip_ids = self::compute_skip_set( $posts, $args, $job['model'] );

			$batch_items = array();
			foreach ( $posts as $pid => $ptype ) {
				$pid           = (int) $pid;
				$last_examined = $pid;
				++$scanned;

				if ( isset( $skip_ids[ $pid ] ) ) {
					++$skipped;
				} else {
					$batch_items[] = array(
						'post_id'  => $pid,
						'model'    => $job['model'],
						'provider' => $job['provider'],
					);
					++$queued;
				}

				// Budget check after processing at least one item so each page
				// always makes forward progress, even with a tight budget.
				if ( ( microtime( true ) - $start_time ) > $budget ) {
					break;
				}
			}

			if ( ! empty( $batch_items ) ) {
				$queue = new \WPVDB\WPVDB_Queue();
				$queue->push_batch_to_queue( $batch_items );
			}

			$more_remaining = $last_examined < $upper_bound;
			if ( $limit_total > 0 && ( $already_queued + $queued ) >= $limit_total ) {
				$more_remaining = false;
			}

			$finalize = $more_remaining ? self::STATUS_PENDING : self::STATUS_COMPLETED;
			$released = self::release_lock( $job_id, $token, $last_examined, $scanned, $queued, $skipped, $finalize );

			// Only reschedule if we still owned the lock at release time. Otherwise
			// another worker took over after our TTL expired and is driving the job.
			if ( $more_remaining && $released ) {
				self::schedule_next_page( $job_id, 1 );
			}
		} catch ( \Throwable $e ) {
			self::release_lock( $job_id, $token, null, 0, 0, 0, self::STATUS_FAILED );
			self::record_error( $job_id, $e->getMessage() );
		}
	}

	/**
	 * Stash an error message on the job row for visibility via status.
	 */
	private static function record_error( $job_id, $message ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET last_error = %s, updated_at = NOW() WHERE job_id = %d',
				$message,
				(int) $job_id
			)
		);
	}

	/**
	 * Page of posts above the cursor within the scope.
	 *
	 * Returns a map [post_id => post_type] so the skip-set query can match
	 * the actual stored doc_type for each post (the queue worker writes
	 * post->post_type, not a fixed 'post' string).
	 *
	 * @return array<int, string>
	 */
	private static function fetch_page_posts( $cursor, $upper_bound, $args, $page_size ) {
		global $wpdb;

		if ( $upper_bound > 0 && $cursor >= $upper_bound ) {
			return array();
		}

		$where      = self::build_scope_where_sql( $args, $params );
		$sql_params = array_merge( array( (int) $cursor, (int) $upper_bound ), $params, array( (int) $page_size ) );

		$sql = "SELECT ID, post_type FROM {$wpdb->posts}
                WHERE ID > %d AND ID <= %d {$where}
                ORDER BY ID ASC
                LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['ID'] ] = (string) $row['post_type'];
		}
		return $map;
	}

	/**
	 * For a page of [post_id => post_type] pairs, return a map [post_id => true]
	 * for posts that should be skipped due to only_missing or
	 * only_mismatched_model filters. Queries are grouped by post_type so the
	 * doc_type column lookup matches the value the queue worker actually wrote.
	 *
	 * @param array<int, string> $posts
	 */
	private static function compute_skip_set( $posts, $args, $model ) {
		$skip = array();
		if ( empty( $posts ) ) {
			return $skip;
		}

		$only_missing    = ! empty( $args['only_missing'] );
		$only_mismatched = ! empty( $args['only_mismatched_model'] );
		if ( ! $only_missing && ! $only_mismatched ) {
			return $skip;
		}

		$by_type = array();
		foreach ( $posts as $pid => $ptype ) {
			$by_type[ $ptype ][] = (int) $pid;
		}

		global $wpdb;

		foreach ( $by_type as $ptype => $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			if ( $only_missing ) {
				$params  = array_merge( $ids, array( (string) $ptype ) );
				$sql     = 'SELECT DISTINCT doc_id FROM ' . self::embeddings_table() . "
                        WHERE doc_id IN ({$placeholders}) AND doc_type = %s";
				$present = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
				foreach ( $present as $pid ) {
					$skip[ (int) $pid ] = true;
				}
			}

			if ( $only_mismatched ) {
				$params   = array_merge( $ids, array( (string) $ptype, (string) $model ) );
				$sql      = 'SELECT DISTINCT doc_id FROM ' . self::embeddings_table() . "
                        WHERE doc_id IN ({$placeholders}) AND doc_type = %s AND model = %s";
				$matching = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
				foreach ( $matching as $pid ) {
					$skip[ (int) $pid ] = true;
				}
			}
		}

		return $skip;
	}

	/**
	 * Schedule the next page action for a job.
	 */
	private static function schedule_next_page( $job_id, $delay_seconds = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + (int) $delay_seconds,
				self::AS_HOOK,
				array( (int) $job_id ),
				self::AS_GROUP
			);
		}
	}

	/**
	 * Whether Action Scheduler is available to drive the paged enqueue.
	 *
	 * Prefers the plugin's global helper when present, so behavior tracks the
	 * same check used by WPVDB_Queue.
	 */
	private static function action_scheduler_available() {
		if ( function_exists( 'wpvdb_has_action_scheduler' ) ) {
			return (bool) wpvdb_has_action_scheduler();
		}
		return function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler' );
	}

	/**
	 * Get a job row as an associative array.
	 */
	public static function get_job( $job_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE job_id = %d', (int) $job_id ),
			ARRAY_A
		);
	}

	/**
	 * List recent jobs, newest first.
	 */
	public static function list_jobs( $limit = 20 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY job_id DESC LIMIT %d', $limit ),
			ARRAY_A
		);
	}

	/**
	 * List active model migration jobs, optionally scoped to a target.
	 *
	 * @param string $provider Optional provider target.
	 * @param string $model Optional model target.
	 * @return array Job rows, newest first.
	 */
	public static function list_active_model_migration_jobs( $provider = '', $model = '' ) {
		global $wpdb;

		$provider = is_string( $provider ) ? $provider : '';
		$model    = is_string( $model ) ? $model : '';
		$active   = array( self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED );

		$where  = array( 'status IN (%s, %s, %s)' );
		$params = $active;

		if ( $provider !== '' ) {
			$where[]  = 'provider = %s';
			$params[] = $provider;
		}
		if ( $model !== '' ) {
			$where[]  = 'model = %s';
			$params[] = $model;
		}

		$sql = $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . '
             WHERE ' . implode( ' AND ', $where ) . '
             ORDER BY job_id DESC',
			$params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$jobs = array();
		foreach ( (array) $rows as $row ) {
			if ( ! in_array( isset( $row['status'] ) ? $row['status'] : '', $active, true ) ) {
				continue;
			}
			if ( $provider !== '' && (string) ( isset( $row['provider'] ) ? $row['provider'] : '' ) !== $provider ) {
				continue;
			}
			if ( $model !== '' && (string) ( isset( $row['model'] ) ? $row['model'] : '' ) !== $model ) {
				continue;
			}

			$scope = isset( $row['scope_args'] ) ? json_decode( $row['scope_args'], true ) : null;
			if ( is_array( $scope ) && ! empty( $scope['only_mismatched_model'] ) ) {
				$jobs[] = $row;
			}
		}

		return $jobs;
	}

	/**
	 * Find the newest active model migration job, optionally scoped to a target.
	 *
	 * @param string $provider Optional provider target.
	 * @param string $model Optional model target.
	 * @return array|null Job row, or null when none matches.
	 */
	public static function find_active_model_migration_job( $provider = '', $model = '' ) {
		$jobs = self::list_active_model_migration_jobs( $provider, $model );
		return empty( $jobs ) ? null : $jobs[0];
	}

	/**
	 * Mark a job canceled. Already-scheduled AS pages will exit early because
	 * the lock acquisition checks status. Also clears the lock token and
	 * expiry so an in-flight worker cannot match its release_lock guard and
	 * overwrite the canceled status back to pending or completed.
	 */
	public static function cancel_job( $job_id ) {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . '
             SET status = %s, lock_token = NULL, lock_until = NULL, updated_at = NOW()
             WHERE job_id = %d',
				self::STATUS_CANCELED,
				(int) $job_id
			)
		);
		// Treat 0 affected rows as "not found" so callers can distinguish that
		// from a successful cancel. $wpdb->query returns false on DB error.
		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Resume a paused job by setting it back to pending and scheduling the
	 * next page.
	 *
	 * @return bool|\WP_Error
	 */
	public static function resume_job( $job_id ) {
		if ( ! self::action_scheduler_available() ) {
			return new \WP_Error(
				'wpvdb_enqueuer_no_action_scheduler',
				'Action Scheduler is not available; cannot resume a re-embed job. ' .
				'Re-activate the plugin or ensure vendor/woocommerce/action-scheduler is loaded.'
			);
		}

		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . '
             SET status = %s, lock_until = NULL, updated_at = NOW()
             WHERE job_id = %d AND status = %s',
				self::STATUS_PENDING,
				(int) $job_id,
				self::STATUS_PAUSED
			)
		);
		if ( $affected ) {
			self::schedule_next_page( (int) $job_id, 0 );
			return true;
		}
		return false;
	}

	/**
	 * Internal status setter.
	 */
	private static function set_status( $job_id, $status ) {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . '
             SET status = %s, updated_at = NOW()
             WHERE job_id = %d',
				$status,
				(int) $job_id
			)
		);
		return $affected !== false;
	}
}
