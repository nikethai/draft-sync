<?php
/**
 * WP-CLI Commands for DraftSync.
 *
 * Registers `wp draftsync import`, `wp draftsync import-docx`, and
 * `wp draftsync status` commands. Only loaded when WP-CLI is active.
 *
 * ## EXAMPLES
 *
 *     # Import a native Google Doc
 *     wp draftsync import https://docs.google.com/document/d/ABC123/edit --user=1
 *
 *     # Import a local .docx file
 *     wp draftsync import-docx /path/to/file.docx --user=1
 *
 *     # Check batch job status
 *     wp draftsync status abc123def456
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_CLI_Command
 */
class GDTG_CLI_Command {

	/** @var GDTG_Import_Orchestrator */
	private $orchestrator;

	public function __construct() {
		$this->orchestrator = new GDTG_Import_Orchestrator();
	}

	/**
	 * Import a Google Doc or Google Drive file URL.
	 *
	 * ## OPTIONS
	 *
	 * <url_or_id>
	 * : Google Doc URL, Drive file URL, or raw document ID.
	 *
	 * [--post_id=<id>]
	 * : Target post ID to overwrite/append. Default: create new post.
	 *
	 * [--output_mode=<mode>]
	 * : Output mode: gutenberg or classic. Default: gutenberg.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the import. Required if no current user.
	 *
	 * [--overwrite]
	 * : Overwrite existing post content instead of appending.
	 *
	 * [--draft]
	 * : Import as draft (default behavior).
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync import https://docs.google.com/document/d/ABC123/edit --user=1
	 *     wp draftsync import ABC123 --post_id=42 --output_mode=classic --user=1
	 *
	 * @subcommand import
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );

		$url_or_id = $args[0];
		$post_id   = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;

		$options = array(
			'import_images'   => true,
			'import_tables'   => true,
			'overwrite'       => ! empty( $assoc_args['overwrite'] ),
			'import_as_draft' => ! empty( $assoc_args['draft'] ) || ! isset( $assoc_args['draft'] ),
			'output_mode'     => in_array( isset( $assoc_args['output_mode'] ) ? $assoc_args['output_mode'] : 'gutenberg', array( 'gutenberg', 'classic' ), true )
				? $assoc_args['output_mode']
				: 'gutenberg',
			'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
			'overrides'       => array(),
		);

		// Custom post_meta mapping from CLI argv.
		$post_meta = array();
		if ( isset( $assoc_args['slug'] ) ) {
			$post_meta['slug'] = sanitize_title( $assoc_args['slug'] );
		}
		if ( isset( $assoc_args['excerpt'] ) ) {
			$post_meta['excerpt'] = sanitize_textarea_field( $assoc_args['excerpt'] );
		}
		
		// SEO mapping
		$seo = array();
		if ( isset( $assoc_args['seo-title'] ) ) {
			$seo['title'] = sanitize_text_field( $assoc_args['seo-title'] );
		}
		if ( isset( $assoc_args['seo-description'] ) ) {
			$seo['description'] = sanitize_text_field( $assoc_args['seo-description'] );
		}
		if ( isset( $assoc_args['focus-keyword'] ) ) {
			$seo['focus_keyword'] = sanitize_text_field( $assoc_args['focus-keyword'] );
		}
		if ( isset( $assoc_args['canonical-url'] ) ) {
			$canon_result = GDTG_Import_Orchestrator::normalize_canonical_url( $assoc_args['canonical-url'] );
			if ( is_wp_error( $canon_result ) ) {
				WP_CLI::error( $canon_result->get_error_message() );
			}
			$seo['canonical'] = $canon_result;
		}
		if ( ! empty( $seo ) ) {
			$post_meta['seo'] = $seo;
		}

		// Taxonomy mapping
		if ( isset( $assoc_args['categories'] ) ) {
			$post_meta['categories'] = array_filter( array_map( 'trim', explode( ',', $assoc_args['categories'] ) ) );
		}
		if ( isset( $assoc_args['tags'] ) ) {
			$post_meta['tags'] = array_filter( array_map( 'trim', explode( ',', $assoc_args['tags'] ) ) );
		}

		// Featured Image
		if ( isset( $assoc_args['featured-image'] ) ) {
			$post_meta['featured_image'] = sanitize_text_field( $assoc_args['featured-image'] );
		}

		// Custom Meta & ACF JSON parsing
		if ( isset( $assoc_args['acf'] ) ) {
			$acf_parsed = json_decode( $assoc_args['acf'], true );
			if ( is_array( $acf_parsed ) ) {
				$post_meta['acf'] = $acf_parsed;
			} else {
				WP_CLI::warning( 'Invalid JSON format for ACF mapping. Skipping.' );
			}
		}
		if ( isset( $assoc_args['meta'] ) ) {
			$meta_parsed = json_decode( $assoc_args['meta'], true );
			if ( is_array( $meta_parsed ) ) {
				$post_meta['meta'] = $meta_parsed;
			} else {
				WP_CLI::warning( 'Invalid JSON format for custom meta mapping. Skipping.' );
			}
		}
		
		$options['post_meta'] = $post_meta;

		// Parse source reference.
		$source_ref = $this->parse_source_cli( $url_or_id );

		if ( is_wp_error( $source_ref ) ) {
			WP_CLI::error( $source_ref->get_error_message() );
		}

		WP_CLI::log( 'Importing...' );

		if ( 'drive_file' === $source_ref['type'] ) {
			$result = $this->orchestrator->import_drive_file( $source_ref['id'], $options, $post_id );
		} else {
			$result = $this->orchestrator->import_google_doc( $source_ref['id'], $options, $post_id );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['batch'] ) ) {
			WP_CLI::success( sprintf(
				'Batch import started. Job ID: %s | Post ID: %d | Images: %d',
				$result['job_id'],
				$result['post_id'],
				$result['image_count']
			) );
			return;
		}

		$is_new = ! empty( $result['is_new'] );
		$msg = $is_new
			? sprintf( 'Created post #%d (%s) — status: %s', $result['post_id'], $result['title'], $result['status'] )
			: sprintf( 'Updated post #%d (%s) — status: %s', $result['post_id'], $result['title'], $result['status'] );

		if ( ! empty( $result['warnings'] ) ) {
			foreach ( $result['warnings'] as $warning ) {
				WP_CLI::warning( $warning );
			}
		}

		WP_CLI::success( $msg );
	}

	/**
	 * Import a local .docx file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the .docx file.
	 *
	 * [--post_id=<id>]
	 * : Target post ID to overwrite/append. Default: create new post.
	 *
	 * [--output_mode=<mode>]
	 * : Output mode: gutenberg or classic. Default: gutenberg.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the import. Required if no current user.
	 *
	 * [--overwrite]
	 * : Overwrite existing post content instead of appending.
	 *
	 * [--draft]
	 * : Import as draft (default behavior).
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync import-docx /path/to/document.docx --user=1
	 *     wp draftsync import-docx ./report.docx --post_id=42 --output_mode=classic --user=1
	 *
	 * @subcommand import-docx
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import_docx( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );

		$file_path = $args[0];

		if ( ! is_file( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'docx' !== $ext ) {
			WP_CLI::error( 'Only .docx files are supported.' );
		}

		$post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;

		$options = array(
			'import_images'   => true,
			'import_tables'   => true,
			'overwrite'       => ! empty( $assoc_args['overwrite'] ),
			'import_as_draft' => ! empty( $assoc_args['draft'] ) || ! isset( $assoc_args['draft'] ),
			'output_mode'     => in_array( isset( $assoc_args['output_mode'] ) ? $assoc_args['output_mode'] : 'gutenberg', array( 'gutenberg', 'classic' ), true )
				? $assoc_args['output_mode']
				: 'gutenberg',
			'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
			'overrides'       => array(),
		);

		// Custom post_meta mapping from CLI argv.
		$post_meta = array();
		if ( isset( $assoc_args['slug'] ) ) {
			$post_meta['slug'] = sanitize_title( $assoc_args['slug'] );
		}
		if ( isset( $assoc_args['excerpt'] ) ) {
			$post_meta['excerpt'] = sanitize_textarea_field( $assoc_args['excerpt'] );
		}
		
		// SEO mapping
		$seo = array();
		if ( isset( $assoc_args['seo-title'] ) ) {
			$seo['title'] = sanitize_text_field( $assoc_args['seo-title'] );
		}
		if ( isset( $assoc_args['seo-description'] ) ) {
			$seo['description'] = sanitize_text_field( $assoc_args['seo-description'] );
		}
		if ( isset( $assoc_args['focus-keyword'] ) ) {
			$seo['focus_keyword'] = sanitize_text_field( $assoc_args['focus-keyword'] );
		}
		if ( isset( $assoc_args['canonical-url'] ) ) {
			$canon_result = GDTG_Import_Orchestrator::normalize_canonical_url( $assoc_args['canonical-url'] );
			if ( is_wp_error( $canon_result ) ) {
				WP_CLI::error( $canon_result->get_error_message() );
			}
			$seo['canonical'] = $canon_result;
		}
		if ( ! empty( $seo ) ) {
			$post_meta['seo'] = $seo;
		}

		// Taxonomy mapping
		if ( isset( $assoc_args['categories'] ) ) {
			$post_meta['categories'] = array_filter( array_map( 'trim', explode( ',', $assoc_args['categories'] ) ) );
		}
		if ( isset( $assoc_args['tags'] ) ) {
			$post_meta['tags'] = array_filter( array_map( 'trim', explode( ',', $assoc_args['tags'] ) ) );
		}

		// Featured Image
		if ( isset( $assoc_args['featured-image'] ) ) {
			$post_meta['featured_image'] = sanitize_text_field( $assoc_args['featured-image'] );
		}

		// Custom Meta & ACF JSON parsing
		if ( isset( $assoc_args['acf'] ) ) {
			$acf_parsed = json_decode( $assoc_args['acf'], true );
			if ( is_array( $acf_parsed ) ) {
				$post_meta['acf'] = $acf_parsed;
			} else {
				WP_CLI::warning( 'Invalid JSON format for ACF mapping. Skipping.' );
			}
		}
		if ( isset( $assoc_args['meta'] ) ) {
			$meta_parsed = json_decode( $assoc_args['meta'], true );
			if ( is_array( $meta_parsed ) ) {
				$post_meta['meta'] = $meta_parsed;
			} else {
				WP_CLI::warning( 'Invalid JSON format for custom meta mapping. Skipping.' );
			}
		}
		
		$options['post_meta'] = $post_meta;

		WP_CLI::log( sprintf( 'Importing %s...', basename( $file_path ) ) );

		$result = $this->orchestrator->import_docx_file( $file_path, basename( $file_path ), $options, $post_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$is_new = ! empty( $result['is_new'] );
		$msg = $is_new
			? sprintf( 'Created post #%d (%s) — status: %s', $result['post_id'], $result['title'], $result['status'] )
			: sprintf( 'Updated post #%d (%s) — status: %s', $result['post_id'], $result['title'], $result['status'] );

		if ( ! empty( $result['warnings'] ) ) {
			foreach ( $result['warnings'] as $warning ) {
				WP_CLI::warning( $warning );
			}
		}

		WP_CLI::success( $msg );
	}

	/**
	 * Import multiple documents using a CSV or JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV or JSON file.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the import. Required if no current user.
	 *
	 * [--dry-run]
	 * : Validate the rows and print planned actions without making writes.
	 *
	 * [--stop-on-error]
	 * : Abort the bulk import process immediately if any row encounters a failure.
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync import-bulk ./bulk.csv --user=1
	 *     wp draftsync import-bulk /path/to/bulk.json --user=1 --dry-run
	 *
	 * @subcommand import-bulk
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import_bulk( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );
		$file_path = $args[0];

		if ( ! is_file( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Unsupported file extension. Only CSV and JSON are accepted for bulk import.' );
		}

		// Stream or parse rows safely.
		$rows = array();
		if ( 'json' === $ext ) {
			$json_raw = file_get_contents( $file_path );
			if ( strlen( $json_raw ) > 10 * 1024 * 1024 ) {
				WP_CLI::error( 'JSON file exceeds the maximum allowed size of 10MB.' );
			}
			$decoded = json_decode( $json_raw, true );
			if ( ! is_array( $decoded ) ) {
				WP_CLI::error( 'Invalid JSON array content.' );
			}
			$rows = $decoded;
		} else {
			// Stream CSV with validation
			$raw = file_get_contents( $file_path );
			if ( false === $raw ) {
				WP_CLI::error( 'Could not open CSV file.' );
			}

			$lines = explode( "\n", $raw );
			$headers = str_getcsv( array_shift( $lines ) );
			if ( ! $headers ) {
				WP_CLI::error( 'Empty CSV or invalid headers.' );
			}

			$line_num = 2;
			foreach ( $lines as $csv_line ) {
				$csv_line = trim( $csv_line );
				if ( '' === $csv_line ) {
					continue;
				}
				$data = str_getcsv( $csv_line );
				if ( count( $data ) !== count( $headers ) ) {
					WP_CLI::warning( sprintf( 'CSV row count mismatch on row %d. Skipping.', $line_num ) );
					$line_num++;
					continue;
				}
				$rows[] = array_combine( $headers, $data );
				$line_num++;
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::error( 'No valid rows found to process.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$stop_on_error = ! empty( $assoc_args['stop-on-error'] );

		WP_CLI::log( sprintf( 'Processing bulk file contains %d rows...', count( $rows ) ) );

		$summary = array(
			'success' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);
		
		$errors = array();

		foreach ( $rows as $idx => $row ) {
			$row_num = $idx + 1;
			$source = isset( $row['source'] ) ? trim( $row['source'] ) : '';
			if ( empty( $source ) ) {
				WP_CLI::warning( sprintf( 'Row %d skipped: "source" field is empty.', $row_num ) );
				$summary['skipped']++;
				continue;
			}

			// Verify permissions for target post_id.
			$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
			if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
				$err_msg = sprintf( 'User lacks permission to edit target post #%d.', $post_id );
				$errors[] = sprintf( 'Row %d (%s): %s', $row_num, $source, $err_msg );
				$summary['failed']++;
				WP_CLI::warning( sprintf( 'Row %d failed: %s', $row_num, $err_msg ) );
				if ( $stop_on_error ) {
					WP_CLI::error( 'Bulk processing aborted due to error.' );
				}
				continue;
			}

			// Validate and normalize options (includes metadata JSON parsing, canonical URL, acf/meta).
			$options = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row );
			if ( is_wp_error( $options ) ) {
				$errors[] = sprintf( 'Row %d (%s): %s', $row_num, $source, $options->get_error_message() );
				$summary['failed']++;
				WP_CLI::warning( sprintf( 'Row %d failed: %s', $row_num, $options->get_error_message() ) );
				if ( $stop_on_error ) {
					WP_CLI::error( 'Bulk processing aborted due to error.' );
				}
				continue;
			}

			// Resolve source type (CLI allows local .docx files as a fallback).
			$source_ref = $this->parse_source_cli( $source );
			if ( is_wp_error( $source_ref ) ) {
				if ( is_file( $source ) && 'docx' === strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ) ) {
					// Local .docx is valid for CLI; do not treat as error.
				} else {
					$errors[] = sprintf( 'Row %d (%s): %s', $row_num, $source, $source_ref->get_error_message() );
					$summary['failed']++;
					WP_CLI::warning( sprintf( 'Row %d failed: %s', $row_num, $source_ref->get_error_message() ) );
					if ( $stop_on_error ) {
						WP_CLI::error( 'Bulk processing aborted due to error.' );
					}
					continue;
				}
			}

			// Dry-run: validated successfully without importing.
			if ( $dry_run ) {
				WP_CLI::log( sprintf( 'Dry Run - Row %d (%s): Validated successfully.', $row_num, $source ) );
				$summary['success']++;
				continue;
			}

			// Perform the actual import.
			if ( is_wp_error( $source_ref ) ) {
				// Local .docx file fallback (source_ref was WP_Error but file exists).
				$res = $this->orchestrator->import_docx_file( $source, basename( $source ), $options, $post_id );
			} elseif ( 'drive_file' === $source_ref['type'] ) {
				$res = $this->orchestrator->import_drive_file( $source_ref['id'], $options, $post_id );
			} else {
				$res = $this->orchestrator->import_google_doc( $source_ref['id'], $options, $post_id );
			}

			if ( is_wp_error( $res ) ) {
				$errors[] = sprintf( 'Row %d (%s): %s', $row_num, $source, $res->get_error_message() );
				$summary['failed']++;
				WP_CLI::warning( sprintf( 'Row %d failed: %s', $row_num, $res->get_error_message() ) );
				if ( $stop_on_error ) {
					WP_CLI::error( 'Bulk processing aborted due to error.' );
				}
			} else {
				$summary['success']++;
				$msg = ! empty( $res['is_new'] )
					? sprintf( 'Created post #%d (%s)', $res['post_id'], $res['title'] )
					: sprintf( 'Updated post #%d (%s)', $res['post_id'], $res['title'] );
				WP_CLI::log( sprintf( 'Row %d (%s): %s', $row_num, $source, $msg ) );
				if ( ! empty( $res['warnings'] ) ) {
					foreach ( $res['warnings'] as $warning ) {
						WP_CLI::warning( sprintf( 'Row %d: %s', $row_num, $warning ) );
					}
				}
			}
		}

		// Print summary.
		WP_CLI::log( "\n==================== BULK STATUS SUMMARY ====================" );
		WP_CLI::log( sprintf( 'Successfully processed: %d', $summary['success'] ) );
		WP_CLI::log( sprintf( 'Failed attempts:        %d', $summary['failed'] ) );
		WP_CLI::log( sprintf( 'Skipped empty rows:     %d', $summary['skipped'] ) );

		if ( ! empty( $errors ) ) {
			WP_CLI::log( "\nDetailed list of failed items:" );
			foreach ( $errors as $err ) {
				WP_CLI::log( " - $err" );
			}
			WP_CLI::error( 'Some bulk items could not be imported.' );
		} else {
			WP_CLI::success( 'Bulk import completed without any failures.' );
		}
	}

	/**
	 * Synchronize a linked post from its original source.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Target post ID to sync.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the sync. Required if no current user.
	 *
	 * [--force]
	 * : Override safe local conflict detection hashes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync sync 42 --user=1
	 *     wp draftsync sync 42 --user=1 --force
	 *
	 * @subcommand sync
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );
		$post_id = absint( $args[0] );

		$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
		$source_id   = get_post_meta( $post_id, '_gdtg_source_id', true );
		$source_name = get_post_meta( $post_id, '_gdtg_source_name', true );
		$last_hash   = get_post_meta( $post_id, '_gdtg_last_content_hash', true );

		if ( empty( $source_type ) ) {
			WP_CLI::error( sprintf( 'Post #%d is not linked to any source document. Cannot re-sync.', $post_id ) );
		}

		if ( 'docx_upload' === $source_type ) {
			WP_CLI::error( sprintf( 'Post #%d was imported via local docx upload (%s). Drag-and-drop uploads cannot re-sync dynamically.', $post_id, $source_name ) );
		}

		// Local conflict check.
		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( sprintf( 'Post #%d not found.', $post_id ) );
		}

		$current_hash = md5( $post->post_content );
		$force = ! empty( $assoc_args['force'] );

		// Missing content hash means we cannot detect local edits — require explicit force.
		if ( empty( $last_hash ) && ! $force ) {
			WP_CLI::error( 'Conflict: No baseline content hash recorded for this post. Cannot detect local edits safely. Use --force to proceed anyway.' );
		}

		if ( ! empty( $last_hash ) && $current_hash !== $last_hash && ! $force ) {
			WP_CLI::error( 'Conflict Detected: The post content was modified locally in WordPress since the last import. Use --force to discard local changes and sync anyway.' );
		}

		// Reconstruct import options — prefer persisted options.
		$persisted = get_post_meta( $post_id, '_gdtg_import_options', true );
		$options   = is_string( $persisted ) ? json_decode( $persisted, true ) : $persisted;
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		// Fill missing keys with safe defaults.
		$options = wp_parse_args(
			$options,
			array(
				'import_images'   => true,
				'import_tables'   => true,
				'overwrite'       => true,
				'import_as_draft' => 'draft' === $post->post_status,
				'output_mode'     => strpos( $post->post_content, '<!-- wp:' ) !== false ? 'gutenberg' : 'classic',
				'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
				'overrides'       => array(),
			)
		);

		WP_CLI::log( sprintf( 'Synchronizing post #%d (%s) from %s ID %s...', $post_id, $post->post_title, $source_type, $source_id ) );

		if ( 'gdoc' === $source_type ) {
			$res = $this->orchestrator->import_google_doc( $source_id, $options, $post_id );
		} elseif ( 'drive_file' === $source_type ) {
			$res = $this->orchestrator->import_drive_file( $source_id, $options, $post_id );
		} else {
			WP_CLI::error( sprintf( 'Post #%d has an unsupported source type "%s". Only gdoc and drive_file can re-sync.', $post_id, $source_type ) );
		}

		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}

		if ( ! empty( $res['warnings'] ) ) {
			foreach ( $res['warnings'] as $warning ) {
				WP_CLI::warning( $warning );
			}
		}

		WP_CLI::success( sprintf( 'Post #%d (%s) refreshed successfully.', $post_id, $res['title'] ) );
	}

	/**
	 * Check the status of a batch import job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID returned by a batch import.
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync status abc123def456
	 *
	 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$job_id  = $args[0];
		$job_key = 'gdtg_import_job_' . $job_id;
		$job     = get_transient( $job_key );

		if ( false === $job ) {
			WP_CLI::error( sprintf( 'Job %s not found or expired.', $job_id ) );
		}

		if ( ! is_array( $job ) ) {
			WP_CLI::error( 'Invalid job data.' );
		}

		$progress   = isset( $job['progress'] ) ? $job['progress'] : array();
		$done       = isset( $progress['done'] ) ? (int) $progress['done'] : 0;
		$total      = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
		$post_id    = isset( $job['post_id'] ) ? $job['post_id'] : 0;
		$doc_title  = isset( $job['doc_title'] ) ? $job['doc_title'] : 'Unknown';

		if ( $done >= $total && $total > 0 ) {
			WP_CLI::success( sprintf(
				'Job %s complete. %d/%d images processed. Post #%d (%s)',
				$job_id, $done, $total, $post_id, $doc_title
			) );
		} else {
			WP_CLI::log( sprintf(
				'Job %s: %d/%d images processed. Post #%d (%s)',
				$job_id, $done, $total, $post_id, $doc_title
			) );
		}
	}

	/**
	 * Ensure a valid WordPress user is set for the import.
	 *
	 * @param array $assoc_args CLI arguments (may contain 'user').
	 */
	private function ensure_user( $assoc_args ) {
		$current = get_current_user_id();

		if ( $current > 0 ) {
			return;
		}

		if ( isset( $assoc_args['user'] ) ) {
			$user_id = absint( $assoc_args['user'] );
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User #%d does not exist.', $user_id ) );
			}
			wp_set_current_user( $user_id );
			return;
		}

		WP_CLI::error( 'No current user. Use --user=<id> to specify a WordPress user.' );
	}

	/**
	 * Parse a URL or ID for CLI context (replicates parse_source_reference logic).
	 *
	 * @param string $input URL or raw ID.
	 * @return array|WP_Error Source reference.
	 */
	private function parse_source_cli( $input ) {
		$input = trim( $input );

		// Unsupported Google services.
		if ( preg_match( '#^https?://docs\.google\.com/spreadsheets/#', $input )
			|| preg_match( '#^https?://docs\.google\.com/presentation/#', $input )
		) {
			return new WP_Error( 'gdtg_unsupported', 'Sheets and Slides are not supported.' );
		}

		// Native Google Docs.
		if ( preg_match( '#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $input, $m ) ) {
			if ( '' !== $m[1] && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $m[1] ) ) {
				return array( 'type' => 'gdoc', 'id' => $m[1] );
			}
		}

		// Drive file URLs.
		if ( preg_match( '#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $input, $m ) ) {
			if ( '' !== $m[1] && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $m[1] ) ) {
				return array( 'type' => 'drive_file', 'id' => $m[1] );
			}
		}
		if ( preg_match( '#drive\.google\.com/(?:open|uc)\?#', $input ) ) {
			$parsed = wp_parse_url( $input );
			if ( isset( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $params );
				if ( ! empty( $params['id'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $params['id'] ) ) {
					return array( 'type' => 'drive_file', 'id' => $params['id'] );
				}
			}
		}

		// Raw ID.
		if ( 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $input ) ) {
			return array( 'type' => 'gdoc', 'id' => $input );
		}

		return new WP_Error( 'gdtg_invalid_input', 'Invalid Google Doc ID or URL.' );
	}

	/**
	 * Synchronize all linked posts that have auto-sync enabled.
	 *
	 * Uses the same runner as WP Cron for deterministic, server-cron
	 * friendly operation.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report candidates without importing.
	 *
	 * [--limit=<number>]
	 * : Max posts to process. Default: value of gdtg_auto_sync_limit option (10).
	 *
	 * [--force]
	 * : Force re-import even on conflict.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the import. Required if no current user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync sync-all --user=1
	 *     wp draftsync sync-all --dry-run --limit=5 --user=1
	 *
	 * @subcommand sync-all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync_all( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );
		$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$force   = ! empty( $assoc_args['force'] );
		$dry_run = ! empty( $assoc_args['dry-run'] ) || ! empty( $assoc_args['dry_run'] );
		$scheduler = new GDTG_Sync_Scheduler( new GDTG_Loader() );
		$summary   = $scheduler->run_scheduled_sync( $limit, $force, $dry_run );
		if ( 0 === $summary['checked'] ) {
			WP_CLI::success( 'No linked posts with auto-sync enabled found.' );
			return;
		}
		$label = $dry_run ? 'Dry-run' : 'Sync';
		WP_CLI::log( sprintf( '%s summary: checked=%d synced=%d skipped=%d conflicts=%d failed=%d migrated=%d', $label, $summary['checked'], $summary['synced'], $summary['skipped'], $summary['conflicts'], $summary['failed'], isset( $summary['migrated'] ) ? $summary['migrated'] : 0 ) );
		if ( ! empty( $summary['details'] ) ) {
			$table = array();
			foreach ( $summary['details'] as $detail ) {
				$table[] = array(
					'post_id'     => $detail['post_id'],
					'source_type' => isset( $detail['source_type'] ) ? $detail['source_type'] : '',
					'status'      => isset( $detail['status'] ) ? $detail['status'] : '',
					'error'       => isset( $detail['error'] ) ? $detail['error'] : '',
				);
			}
			WP_CLI\Utils\format_items( 'table', $table, array( 'post_id', 'source_type', 'status', 'error' ) );
		}
		if ( $summary['failed'] > 0 ) {
			WP_CLI::warning( sprintf( '%d post(s) failed to sync.', $summary['failed'] ) );
		} else {
			WP_CLI::success( 'All eligible posts synchronized.' );
		}
	}
	/**
	 * Detect and migrate Drive-linked posts whose .docx has been converted
	 * to a native Google Doc.
	 *
	 * Queries all posts with source_type=drive_file, fetches metadata for
	 * each, and migrates any whose mimeType is now
	 * application/vnd.google-apps.document.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report eligible posts without migrating.
	 *
	 * [--limit=<number>]
	 * : Max posts to check. Default: 50.
	 *
	 * [--post-id=<id>]
	 * : Check a specific post ID only.
	 *
	 * [--user=<id>]
	 * : WordPress user ID for the import. Required if no current user.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run: find eligible posts
	 *     wp draftsync migrate-drive-sources --dry-run --user=1
	 *
	 *     # Migrate all eligible posts
	 *     wp draftsync migrate-drive-sources --user=1
	 *
	 *     # Check a single post
	 *     wp draftsync migrate-drive-sources --post-id=42 --user=1
	 *
	 * @subcommand migrate-drive-sources
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate_drive_sources( $args, $assoc_args ) {
		$this->ensure_user( $assoc_args );

		$dry_run = ! empty( $assoc_args['dry-run'] ) || ! empty( $assoc_args['dry_run'] );
		$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
		$post_id = isset( $assoc_args['post-id'] ) ? absint( $assoc_args['post-id'] ) : 0;

		$limit = max( 1, min( 100, (int) $limit ) );

		$meta_query = array(
			array(
				'key'     => '_gdtg_source_type',
				'value'   => 'drive_file',
				'compare' => '=',
			),
		);

		$query_args = array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'modified',
			'order'          => 'ASC',
		);

		if ( $post_id > 0 ) {
			$query_args['p'] = $post_id;
			$query_args['posts_per_page'] = 1;
		}

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			WP_CLI::success( 'No drive_file-linked posts found.' );
			wp_reset_postdata();
			return;
		}

		$checked   = 0;
		$migrated  = 0;
		$skipped   = 0;
		$failed    = 0;
		$details   = array();

		$orchestrator = new GDTG_Import_Orchestrator();
		$api          = new GDTG_API();

		$progress = \WP_CLI\Utils\make_progress_bar(
			$dry_run ? 'Checking Drive sources…' : 'Migrating Drive sources…',
			$query->post_count
		);

		foreach ( $query->posts as $post ) {
			$checked++;
			$source_id = get_post_meta( $post->ID, '_gdtg_source_id', true );
			$detail    = array(
				'post_id'    => $post->ID,
				'post_title' => $post->post_title,
				'source_id'  => $source_id,
			);

			if ( empty( $source_id ) ) {
				$skipped++;
				$detail['status'] = 'skipped';
				$detail['reason'] = 'Missing source ID';
				$details[] = $detail;
				$progress->tick();
				continue;
			}

			$metadata = $api->get_drive_file_metadata( $source_id );

			if ( is_wp_error( $metadata ) ) {
				$failed++;
				$detail['status'] = 'error';
				$detail['reason'] = $metadata->get_error_message();
				$details[] = $detail;
				$progress->tick();
				continue;
			}

			$mime_type = isset( $metadata['mimeType'] ) ? $metadata['mimeType'] : '';

			if ( 'application/vnd.google-apps.document' !== $mime_type ) {
				$skipped++;
				$detail['status'] = 'skipped';
				$detail['reason'] = 'Not a Google Doc (still .docx)';
				$details[] = $detail;
				$progress->tick();
				continue;
			}

			if ( $dry_run ) {
				$migrated++;
				$detail['status'] = 'eligible';
				$detail['reason'] = 'Would migrate to gdoc';
				$details[] = $detail;
				$progress->tick();
				continue;
			}

			// Perform the migration using the same code path as handle_sync.
			$persisted = get_post_meta( $post->ID, '_gdtg_import_options', true );
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
					'import_as_draft' => 'draft' === $post->post_status,
					'output_mode'     => strpos( $post->post_content, '<!-- wp:' ) !== false ? 'gutenberg' : 'classic',
					'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
					'overrides'       => array(),
				)
			);

			// import_drive_file handles the migration detection internally.
			$result = $orchestrator->import_drive_file( $source_id, $options, $post->ID );

			if ( is_wp_error( $result ) ) {
				$failed++;
				$detail['status'] = 'error';
				$detail['reason'] = $result->get_error_message();
			} elseif ( ! empty( $result['migrated'] ) ) {
				$migrated++;
				$detail['status'] = 'migrated';
			} else {
				// .docx imported normally (no migration needed).
				$skipped++;
				$detail['status'] = 'synced';
				$detail['reason'] = 'Normal .docx sync (no migration)';
			}

			$details[] = $detail;
			$progress->tick();
		}

		$progress->finish();
		wp_reset_postdata();

		// Summary.
		$label = $dry_run ? 'Dry-run' : 'Migration';
		WP_CLI::log( sprintf(
			'%s summary: checked=%d migrated=%d skipped=%d failed=%d',
			$label, $checked, $migrated, $skipped, $failed
		) );

		if ( ! empty( $details ) ) {
			$table = array();
			foreach ( $details as $d ) {
				$table[] = array(
					'post_id'    => $d['post_id'],
					'post_title' => isset( $d['post_title'] ) ? $d['post_title'] : '',
					'status'     => $d['status'],
					'reason'     => isset( $d['reason'] ) ? $d['reason'] : '',
				);
			}
			WP_CLI\Utils\format_items( 'table', $table, array( 'post_id', 'post_title', 'status', 'reason' ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d post(s) failed.', $failed ) );
		} elseif ( $migrated > 0 ) {
			WP_CLI::success( sprintf( '%d post(s) migrated to Google Doc source.', $migrated ) );
		} else {
			WP_CLI::success( 'No posts need migration.' );
		}
	}

	/**
	 * Diagnose linked posts — surface migration state, cached mimeType,
	 * and pending markers.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Limit diagnosis to a single post.
	 *
	 * [--limit=<n>]
	 * : Max posts to show (default 50).
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp draftsync diagnose
	 *     wp draftsync diagnose --post-id=42
	 *     wp draftsync diagnose --limit=10
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function diagnose( $args, $assoc_args ) {
		$post_id = isset( $assoc_args['post-id'] ) ? absint( $assoc_args['post-id'] ) : 0;
		$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
		$limit   = max( 1, min( 100, $limit ) );

		$query_args = array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array(
					'key'     => '_gdtg_source_type',
					'compare' => 'EXISTS',
				),
			),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( $post_id > 0 ) {
			$query_args['p'] = $post_id;
			$query_args['posts_per_page'] = 1;
		}

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			WP_CLI::success( 'No linked posts found.' );
			wp_reset_postdata();
			return;
		}

		WP_CLI::log( sprintf( 'Found %d linked post(s):', $query->post_count ) );

		$table = array();
		foreach ( $query->posts as $post ) {
			$source_type = get_post_meta( $post->ID, '_gdtg_source_type', true );
			$source_id   = get_post_meta( $post->ID, '_gdtg_source_id', true );
			$last_status = get_post_meta( $post->ID, '_gdtg_last_sync_status', true );
			$last_error  = get_post_meta( $post->ID, '_gdtg_last_sync_error', true );
			$pending     = get_post_meta( $post->ID, '_gdtg_migration_pending', true );
			$mime_type   = get_post_meta( $post->ID, '_gdtg_drive_mime_type', true );
			$mime_cached = get_post_meta( $post->ID, '_gdtg_drive_mime_cached_at', true );

			$table[] = array(
				'post_id'     => $post->ID,
				'source_type' => $source_type ? $source_type : '',
				'source_id'   => $source_id ? $source_id : '',
				'last_status' => $last_status ? $last_status : '',
				'last_error'  => $last_error ? $last_error : '',
				'pending'     => $pending ? 'yes' : '',
				'mime_type'   => $mime_type ? $mime_type : '',
				'mime_cached' => $mime_cached ? $mime_cached : '',
			);
		}

		wp_reset_postdata();

		WP_CLI\Utils\format_items(
			'table',
			$table,
			array( 'post_id', 'source_type', 'source_id', 'last_status', 'last_error', 'pending', 'mime_type', 'mime_cached' )
		);
	}

}
