<?php
/**
 * Scheduled one-way auto-sync scheduler.
 *
 * Uses WP Cron to periodically re-import linked posts whose sources
 * have changed. CLI `wp draftsync sync-all` calls the same runner
 * for deterministic, server-cron friendly operation.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDTG_Sync_Scheduler {

	/**
	 * WP Cron hook name.
	 */
	const HOOK = 'gdtg_auto_sync_event';

	/**
	 * Per-post sync timeout threshold in seconds.
	 *
	 * @var int
	 */
	const PER_POST_TIMEOUT = 90;

	/**
	 * Loader instance.
	 *
	 * @var GDTG_Loader
	 */
	protected $loader;

	/**
	 * Constructor — register hooks via loader.
	 *
	 * @param GDTG_Loader $loader The loader instance.
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;
		$this->init_hooks();
	}

	/**
	 * Build the orchestrator dependency.
	 *
	 * Isolated for testability.
	 *
	 * @return GDTG_Import_Orchestrator
	 */
	protected function create_orchestrator() {
		return new GDTG_Import_Orchestrator();
	}

	/**
	 * Current timestamp helper isolated for testability.
	 *
	 * @return float
	 */
	protected function now() {
		return microtime( true );
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		$this->loader->add_filter( 'cron_schedules', $this, 'add_cron_schedules' );
		$this->loader->add_action( self::HOOK, $this, 'run_scheduled_sync' );
		$this->loader->add_action( 'admin_init', $this, 'maybe_reschedule' );
	}

	/**
	 * Add custom cron intervals if needed.
	 *
	 * @param array $schedules Existing WP cron schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['gdtg_twicedaily'] ) ) {
			$schedules['gdtg_twicedaily'] = array(
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Twice Daily (DraftSync)', 'draftsync' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure the scheduled event is present when auto-sync is enabled.
	 */
	public function maybe_reschedule() {
		$enabled   = get_option( 'gdtg_auto_sync_enabled', '0' );
		$frequency = get_option( 'gdtg_auto_sync_frequency', 'daily' );

		if ( '1' !== $enabled || 'off' === $frequency ) {
			$this->clear_scheduled();
			return;
		}

		$freq_map = array(
			'hourly'     => 'hourly',
			'twicedaily' => 'gdtg_twicedaily',
			'daily'      => 'daily',
		);
		$wp_frequency = isset( $freq_map[ $frequency ] ) ? $freq_map[ $frequency ] : 'daily';

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), $wp_frequency, self::HOOK );
		}
	}

	/**
	 * Schedule the recurring event. Idempotent — will not duplicate.
	 */
	public function ensure_scheduled() {
		$frequency = get_option( 'gdtg_auto_sync_frequency', 'daily' );
		$freq_map  = array(
			'hourly'     => 'hourly',
			'twicedaily' => 'gdtg_twicedaily',
			'daily'      => 'daily',
		);
		$wp_frequency = isset( $freq_map[ $frequency ] ) ? $freq_map[ $frequency ] : 'daily';

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), $wp_frequency, self::HOOK );
		}
	}

	/**
	 * Clear the scheduled event.
	 */
	public function clear_scheduled() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run the scheduled sync. Shared by WP Cron callback and CLI.
	 *
	 * @param int  $limit   Max posts to process per run.
	 * @param bool $force   Force re-import even on conflict.
	 * @param bool $dry_run If true, report candidates without importing.
	 * @return array Summary with keys: checked, synced, skipped, conflicts, failed, migrated, timeouts, dry_run, details.
	 */
	public function run_scheduled_sync( $limit = 0, $force = false, $dry_run = false ) {
		if ( 0 === $limit ) {
			$limit = (int) get_option( 'gdtg_auto_sync_limit', 10 );
		}
		$limit = max( 1, min( 50, (int) $limit ) );

		$supported_types = array( 'gdoc', 'drive_file' );

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_gdtg_auto_sync',
				'value'   => '1',
				'compare' => '=',
			),
			array(
				'key'     => '_gdtg_source_type',
				'value'   => $supported_types,
				'compare' => 'IN',
			),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);

		$summary = array(
			'checked'   => 0,
			'synced'    => 0,
			'skipped'   => 0,
			'conflicts' => 0,
			'failed'    => 0,
			'migrated'  => 0,
			'timeouts'  => 0,
			'dry_run'   => $dry_run,
			'details'   => array(),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return $summary;
		}

		$orchestrator = $this->create_orchestrator();

		// Record scheduler start event.
		if ( class_exists( 'GDTG_Sync_Log' ) ) {
			GDTG_Sync_Log::record( 0, 'info', __( 'Scheduled sync started.', 'draftsync' ), array( 'step' => 'scheduler_start' ) );
		}

		foreach ( $query->posts as $post ) {
			$summary['checked']++;
			$post_start  = $this->now();
			$post_id     = $post->ID;
			$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
			$source_id   = get_post_meta( $post_id, '_gdtg_source_id', true );
			$detail      = array(
				'post_id'     => $post_id,
				'source_type' => $source_type,
				'source_id'   => $source_id,
			);

			$stored_hash = get_post_meta( $post_id, '_gdtg_last_content_hash', true );
			if ( '' !== $stored_hash && $stored_hash !== md5( $post->post_content ) ) {
				if ( ! $force ) {
					update_post_meta( $post_id, '_gdtg_last_sync_status', 'conflict' );
					update_post_meta( $post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
					$summary['conflicts']++;
					$detail['status'] = 'conflict';
					$summary['details'][] = $detail;
					if ( class_exists( 'GDTG_Sync_Log' ) ) {
						GDTG_Sync_Log::record( $post_id, 'warning', __( 'Skipped: local content conflict detected.', 'draftsync' ), array( 'step' => 'scheduler_conflict' ) );
					}
					continue;
				}
			}

			if ( 'drive_file' === $source_type ) {
				$remote_modified = $this->get_remote_modified_at( $source_id );
				$local_modified  = get_post_meta( $post_id, '_gdtg_source_modified_at', true );
				if ( '' !== $local_modified && '' !== $remote_modified && $remote_modified <= $local_modified ) {
					update_post_meta( $post_id, '_gdtg_last_sync_status', 'skipped' );
					update_post_meta( $post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
					$summary['skipped']++;
					$detail['status'] = 'skipped_unchanged';
					$summary['details'][] = $detail;
					if ( class_exists( 'GDTG_Sync_Log' ) ) {
						GDTG_Sync_Log::record( $post_id, 'warning', __( 'Skipped: Drive file unchanged.', 'draftsync' ), array( 'step' => 'scheduler_skipped_unchanged', 'source_modified_at' => $remote_modified ) );
					}
					continue;
				}
			}

			if ( $dry_run ) {
				$summary['synced']++;
				$detail['status'] = 'would_sync';
				$summary['details'][] = $detail;
				continue;
			}


			// Acquire per-post sync lock to prevent duplicate concurrent work.
			$locked = GDTG_Sync_Lock::acquire( $post_id );
			if ( ! $locked ) {
				$summary['skipped']++;
				$detail['status'] = 'skipped_locked';
				$summary['details'][] = $detail;
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record( $post_id, 'warning', __( 'Skipped: sync lock held by another process.', 'draftsync' ), array( 'step' => 'scheduler_locked' ) );
				}
				continue;
			}
			$persisted = get_post_meta( $post_id, '_gdtg_import_options', true );
			$options   = is_string( $persisted ) ? json_decode( $persisted, true ) : $persisted;
			if ( ! is_array( $options ) ) {
				$options = array();
			}

			$options = wp_parse_args(
				$options,
				array(
					'import_images'   => true,
					'import_tables'   => true,
					'overwrite'       => true,
					'import_as_draft' => false,
					'output_mode'     => 'gutenberg',
					'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
				)
			);

			$result = null;
			try {
				GDTG_Sync_Lock::heartbeat( $post_id, 300 );
				if ( 'gdoc' === $source_type ) {
					$result = $orchestrator->import_google_doc( $source_id, $options, $post_id );
				} elseif ( 'drive_file' === $source_type ) {
					$result = $orchestrator->import_drive_file( $source_id, $options, $post_id );
				}
			} finally {
				GDTG_Sync_Lock::release( $post_id );
			}

			if ( is_wp_error( $result ) ) {
				update_post_meta( $post_id, '_gdtg_last_sync_status', 'error' );
				update_post_meta( $post_id, '_gdtg_last_sync_error', sanitize_text_field( $result->get_error_message() ) );
				update_post_meta( $post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
				$summary['failed']++;
				$detail['status'] = 'error';
				$detail['error']  = $result->get_error_message();
				$elapsed = $this->now() - $post_start;
				if ( $elapsed > self::PER_POST_TIMEOUT ) {
					$summary['timeouts']++;
					$detail['timed_out'] = true;
					$detail['timeout']   = round( $elapsed, 1 );
				}
				$summary['details'][] = $detail;
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record( $post_id, 'error', sanitize_text_field( $result->get_error_message() ), array( 'step' => 'scheduler_import', 'error_code' => $result->get_error_code() ) );
				}
				continue;
			}

			if ( 'drive_file' === $source_type ) {
				$remote_modified = $this->get_remote_modified_at( $source_id );
				if ( '' !== $remote_modified ) {
					update_post_meta( $post_id, '_gdtg_source_modified_at', $remote_modified );
				}
			}

			update_post_meta( $post_id, '_gdtg_last_sync_status', 'success' );
			update_post_meta( $post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
			delete_post_meta( $post_id, '_gdtg_last_sync_error' );
			$summary['synced']++;
			if ( ! empty( $result['migrated'] ) ) {
				$summary['migrated']++;
			}
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record( $post_id, 'info', __( 'Auto-sync succeeded.', 'draftsync' ), array( 'step' => 'scheduler_success' ) );
			}
			$detail['status'] = 'synced';
			$elapsed = $this->now() - $post_start;
			if ( $elapsed > self::PER_POST_TIMEOUT ) {
				$summary['timeouts']++;
				$detail['timed_out'] = true;
				$detail['timeout']   = round( $elapsed, 1 );
			}
			$summary['details'][] = $detail;
		}

		wp_reset_postdata();
		return $summary;
	}

	/**
	 * Get the remote modifiedTime for a Drive file.
	 *
	 * @param string $file_id The Drive file ID.
	 * @return string ISO 8601 modifiedTime or empty string on failure.
	 */
	private function get_remote_modified_at( $file_id ) {
		$api  = new GDTG_API();
		$meta = $api->get_drive_file_metadata( $file_id );
		if ( is_wp_error( $meta ) || empty( $meta['modifiedTime'] ) ) {
			return '';
		}
		return $meta['modifiedTime'];
	}
}
