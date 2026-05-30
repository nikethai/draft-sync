<?php
/**
 * Import Orchestrator: Shared import logic for REST endpoints and WP-CLI.
 *
 * Extracts the core parse → render → write flow from GDTG_REST_Endpoints
 * so both REST handlers and CLI commands use the same code path.
 * Returns structured arrays or WP_Error; response formatting stays in
 * the calling layer (REST responses or WP_CLI output).
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard: define constants if not already set (test compatibility).
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'GDTG_LARGE_DOC_BYTE_THRESHOLD' ) ) {
	define( 'GDTG_LARGE_DOC_BYTE_THRESHOLD', 10 * MB_IN_BYTES );
}

/**
 * Class GDTG_Import_Orchestrator
 */
class GDTG_Import_Orchestrator {
	/**
	 * Strict boolean parser for CLI/REST string inputs.
	 *
	 * Accepts: true/false (bool), 1/0, 'true'/'false', 'yes'/'no', '' (returns default).
	 *
	 * @param mixed $value    Input value.
	 * @param bool  $default  Default when value is empty/null.
	 * @return bool
	 */
	public static function parse_bool_strict( $value, $default = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value || '' === $value ) {
			return $default;
		}
		$lower = strtolower( trim( (string) $value ) );
		if ( in_array( $lower, array( 'true', '1', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $lower, array( 'false', '0', 'no' ), true ) ) {
			return false;
		}
		return $default;
	}
	/**
	 * Validate and sanitize a canonical URL.
	 *
	 * Returns the sanitized URL for valid http/https values,
	 * empty string for empty/null input, or WP_Error for invalid values.
	 *
	 * @param mixed $value Input value.
	 * @return string|WP_Error Sanitized URL string or WP_Error.
	 */
	public static function normalize_canonical_url( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		$str = trim( (string) $value );
		if ( '' === $str ) {
			return '';
		}
		$parsed = wp_parse_url( $str );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'gdtg_invalid_canonical_url', __( 'Canonical URL must use http or https scheme.', 'draftsync' ) );
		}
		$sanitized = esc_url_raw( $str );
		if ( '' === $sanitized ) {
			return new WP_Error( 'gdtg_invalid_canonical_url', __( 'Canonical URL is not valid.', 'draftsync' ) );
		}
		return $sanitized;
	}
	/**
	 * Normalize a bulk import row into canonical options.
	 *
	 * Parses metadata JSON strings, validates canonical URLs, and builds
	 * the options array used by orchestrator import methods.
	 *
	 * @param array $row Raw row data from REST or CLI bulk input.
	 * @return array|WP_Error Normalized options array or WP_Error on invalid row data.
	 */
	public static function normalize_bulk_row_options( $row ) {
		$post_meta = array();

		// Parse metadata field: accept array directly or JSON string.
		if ( isset( $row['metadata'] ) ) {
			if ( is_array( $row['metadata'] ) ) {
				$post_meta = $row['metadata'];
			} elseif ( is_string( $row['metadata'] ) && '' !== trim( $row['metadata'] ) ) {
				$decoded = json_decode( $row['metadata'], true );
				if ( ! is_array( $decoded ) ) {
					return new WP_Error( 'gdtg_invalid_metadata_json', __( 'Invalid JSON in "metadata" field.', 'draftsync' ) );
				}
				$post_meta = $decoded;
			}
		}

		// Flat metadata fields override metadata object for their specific keys.
		if ( isset( $row['slug'] ) && '' !== trim( $row['slug'] ) ) {
			$post_meta['slug'] = sanitize_title( $row['slug'] );
		}
		if ( isset( $row['excerpt'] ) && '' !== trim( $row['excerpt'] ) ) {
			$post_meta['excerpt'] = sanitize_textarea_field( $row['excerpt'] );
		}

		$seo = isset( $post_meta['seo'] ) && is_array( $post_meta['seo'] ) ? $post_meta['seo'] : array();
		if ( isset( $row['seo_title'] ) && '' !== trim( $row['seo_title'] ) ) {
			$seo['title'] = sanitize_text_field( $row['seo_title'] );
		}
		if ( isset( $row['seo_description'] ) && '' !== trim( $row['seo_description'] ) ) {
			$seo['description'] = sanitize_text_field( $row['seo_description'] );
		}
		if ( isset( $row['focus_keyword'] ) && '' !== trim( $row['focus_keyword'] ) ) {
			$seo['focus_keyword'] = sanitize_text_field( $row['focus_keyword'] );
		}
		if ( isset( $row['canonical_url'] ) && '' !== trim( $row['canonical_url'] ) ) {
			$canon_result = static::normalize_canonical_url( $row['canonical_url'] );
			if ( is_wp_error( $canon_result ) ) {
				return $canon_result;
			}
			$seo['canonical'] = $canon_result;
		}
		if ( ! empty( $seo ) ) {
			$post_meta['seo'] = $seo;
		}

		// Taxonomy mapping — supports both arrays and comma-separated strings.
		if ( isset( $row['categories'] ) ) {
			$post_meta['categories'] = is_array( $row['categories'] )
				? array_filter( array_map( 'trim', $row['categories'] ) )
				: array_filter( array_map( 'trim', explode( ',', (string) $row['categories'] ) ) );
		}
		if ( isset( $row['tags'] ) ) {
			$post_meta['tags'] = is_array( $row['tags'] )
				? array_filter( array_map( 'trim', $row['tags'] ) )
				: array_filter( array_map( 'trim', explode( ',', (string) $row['tags'] ) ) );
		}

		// Featured image.
		if ( isset( $row['featured_image'] ) ) {
			$post_meta['featured_image'] = sanitize_text_field( $row['featured_image'] );
		}

		// ACF fields: accept both 'acf' and 'acf_json' (canonical key wins).
		$acf_source = null;
		if ( isset( $row['acf'] ) ) {
			$acf_source = $row['acf'];
		} elseif ( isset( $row['acf_json'] ) ) {
			$acf_source = $row['acf_json'];
		}
		if ( null !== $acf_source ) {
			$acf_parsed = is_array( $acf_source ) ? $acf_source : json_decode( $acf_source, true );
			if ( is_array( $acf_parsed ) ) {
				$post_meta['acf'] = $acf_parsed;
			} else {
				return new WP_Error( 'gdtg_invalid_acf_json', __( 'Invalid JSON in "acf" field.', 'draftsync' ) );
			}
		}

		// Custom meta: accept both 'meta' and 'meta_json' (canonical key wins).
		$meta_source = null;
		if ( isset( $row['meta'] ) ) {
			$meta_source = $row['meta'];
		} elseif ( isset( $row['meta_json'] ) ) {
			$meta_source = $row['meta_json'];
		}
		if ( null !== $meta_source ) {
			$meta_parsed = is_array( $meta_source ) ? $meta_source : json_decode( $meta_source, true );
			if ( is_array( $meta_parsed ) ) {
				$post_meta['meta'] = $meta_parsed;
			} else {
				return new WP_Error( 'gdtg_invalid_meta_json', __( 'Invalid JSON in "meta" field.', 'draftsync' ) );
			}
		}

		$options = array(
			'import_images'   => true,
			'import_tables'   => static::parse_bool_strict( isset( $row['import_tables'] ) ? $row['import_tables'] : null, true ),
			'overwrite'       => static::parse_bool_strict( isset( $row['overwrite'] ) ? $row['overwrite'] : null, false ),
			'import_as_draft' => static::parse_bool_strict( isset( $row['draft'] ) ? $row['draft'] : null, true ),
			'output_mode'     => in_array( isset( $row['output_mode'] ) ? $row['output_mode'] : 'gutenberg', array( 'gutenberg', 'classic' ), true )
				? ( isset( $row['output_mode'] ) ? $row['output_mode'] : 'gutenberg' )
				: 'gutenberg',
			'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
			'overrides'       => array(),
			'post_meta'       => $post_meta,
		);

		return $options;
	}

	/**
	 * Import a native Google Doc by ID.
	 *
	 * @param string $doc_id  Google Doc ID.
	 * @param array  $options Normalized import options.
	 * @param int    $post_id Target post ID (0 for new).
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 *
	 * Success array keys:
	 *   'post_id'  => int
	 *   'title'    => string
	 *   'status'   => string (post status)
	 *   'is_new'   => bool
	 *   'batch'    => bool (true if batch job created)
	 *   'job_id'   => string (only when batch=true)
	 */
	public function import_google_doc( $doc_id, $options, $post_id = 0 ) {
		$api      = new GDTG_API();

		// Determine target post for logging.
		$target_post_id = absint( $post_id );

		// Record import start event.
		if ( class_exists( 'GDTG_Sync_Log' ) ) {
			GDTG_Sync_Log::record(
				$target_post_id,
				'info',
				sprintf(
					/* translators: %s: Google Doc ID */
					__( 'Import started for Google Doc: %s.', 'draftsync' ),
					$doc_id
				),
				array( 'step' => 'google_doc_start' )
			);
		}

		$doc_json = $api->fetch_google_doc( $doc_id );
		if ( is_wp_error( $doc_json ) ) {
			// Auto-detect Office files stored in Drive that have a docs.google.com URL.
			// Google gives uploaded .docx files a Docs URL, but the Docs API rejects them.
			if ( false !== strpos( $doc_json->get_error_message(), 'not supported for this document' )
				|| false !== strpos( $doc_json->get_error_message(), 'Office file' )
			) {
				// _gdtg_migrating guard: prevents infinite recursion when import_drive_file()
				// detected a gdoc and handed off, but the Docs API rejected it as an Office
				// file. Without this guard, import_drive_file → import_google_doc →
				// import_drive_file → … would loop forever.
				if ( ! empty( $options['_gdtg_migrating'] ) ) {
					return $doc_json;
				}
				return $this->import_drive_file( $doc_id, $options, $post_id );
			}
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'error',
					sprintf(
						/* translators: %s: error message */
						__( 'Import failed: %s', 'draftsync' ),
						$doc_json->get_error_message()
					),
					array( 'step' => 'google_doc_fetch', 'error_code' => $doc_json->get_error_code() )
				);
			}
			return $doc_json;
		}

		// Large-document fallback: prefer the Drive HTML export over deferral.
		// Drive export returns a complete HTML rendering that we can wrap in
		// a wp:html block and commit immediately. If export is unavailable
		// (scope, error, or empty body), we fall back to the original
		// deferred_size behavior so the user can queue manually.
		$doc_size = strlen( $doc_json );
		if ( $doc_size > GDTG_LARGE_DOC_BYTE_THRESHOLD ) {
			$exported_html = $api->export_google_doc_as_html( $doc_id );
			if ( ! is_wp_error( $exported_html ) && ! empty( $exported_html ) ) {
				$rendered_html = "<!-- wp:html -->\n" . $exported_html . "\n<!-- /wp:html -->";
				// Avoid decoding the entire oversized JSON just to read the
				// title: instead, pull the <title> from the already-fetched
				// HTML export. Fall back to the generic label if the
				// export HTML doesn't carry one.
				$doc_title = __( 'Google Doc Import', 'draftsync' );
				if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $exported_html, $title_matches ) ) {
					$maybe_title = trim( $title_matches[1] );
					if ( '' !== $maybe_title ) {
						$doc_title = sanitize_text_field( $maybe_title );
					}
				}

				$target_post_id = absint( $post_id );
				$result         = $this->commit_rendered_content( $rendered_html, $options, $target_post_id, $doc_title );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$this->record_sync_metadata( $result['post_id'], 'gdoc', $doc_id, $doc_title, $options );
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$result['post_id'],
						'info',
						__( 'Large document imported via Drive HTML export fallback.', 'draftsync' ),
						array( 'step' => 'export_fallback' )
					);
				}
				$result['export_fallback'] = true;
				return $result;
			}

			// Export failed or returned empty: chunked streaming import.
			$target_post_id = absint( $post_id );

			// Decode and parse using the same metadata-extraction path as normal-size docs.
			$doc_data = json_decode( $doc_json, true );
			if ( ! is_array( $doc_data ) ) {
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$target_post_id,
						'error',
						__( 'Failed to parse Google Doc data.', 'draftsync' ),
						array( 'step' => 'large_doc_partial', 'error_code' => 'gdtg_parse_error' )
					);
				}
				return new WP_Error( 'gdtg_parse_error', __( 'Failed to parse Google Doc data.', 'draftsync' ) );
			}

			$doc_title = isset( $doc_data['title'] )
				? sanitize_text_field( $doc_data['title'] )
				: __( 'Google Doc Import', 'draftsync' );

			// Parse with images forced off for streaming path.
			$parser_options = $options;
			$parser_options['import_images'] = false;
			$parser_options['defer_images']  = false;

			$parser   = new GDTG_Parser( $doc_json, 0, $parser_options );
			$applier  = new GDTG_Post_Meta_Applier();
			$extracted = $applier->extract_metadata_table( $parser->parse_nodes() );
			$nodes     = $extracted['nodes'];
			$table_meta = $extracted['metadata'];

			// Merge metadata: explicit overrides win.
			$explicit_post_meta = isset( $options['post_meta'] ) && is_array( $options['post_meta'] ) ? $options['post_meta'] : array();
			$options['post_meta'] = array_replace_recursive( $table_meta, $explicit_post_meta );

			if ( empty( $nodes ) ) {
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$target_post_id,
						'error',
						__( 'The Google Doc is empty or could not be parsed.', 'draftsync' ),
						array( 'step' => 'large_doc_partial', 'error_code' => 'gdtg_empty_doc' )
					);
				}
				return new WP_Error( 'gdtg_empty_doc', __( 'The Google Doc is empty or could not be parsed.', 'draftsync' ) );
			}

			// Create the post shell if needed (must exist before streaming).
			if ( 0 === $target_post_id ) {
				$target_post_id = $this->create_post_shell( $doc_title, $options );
				if ( is_wp_error( $target_post_id ) ) {
					return $target_post_id;
				}
			}

			// Set sync status fields before first batch.
			update_post_meta( $target_post_id, '_gdtg_last_sync_status', 'syncing' );
			delete_post_meta( $target_post_id, '_gdtg_last_sync_error' );
			update_post_meta( $target_post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
			update_post_meta( $target_post_id, '_gdtg_sync_progress', 55 );

			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'info',
					__( 'Large document streaming import started.', 'draftsync' ),
					array( 'step' => 'large_doc_partial', 'progress' => 55 )
				);
			}

			// Progress callback: updates post meta + logs per batch.
			$on_progress = function ( $rendered, $total, $percent ) use ( $target_post_id ) {
				update_post_meta( $target_post_id, '_gdtg_last_sync_status', 'syncing' );
				update_post_meta( $target_post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$target_post_id,
						'info',
						sprintf(
							/* translators: 1: number rendered, 2: total */
							__( 'Imported %1$d of %2$d sections.', 'draftsync' ),
							$rendered,
							$total
						),
						array( 'step' => 'large_doc_partial', 'progress' => (int) $percent )
					);
				}
			};

			$result = GDTG_Large_Doc_Streamer::stream(
				$nodes,
				$target_post_id,
				$this->resolve_overrides( isset( $options['overrides'] ) ? $options['overrides'] : array() ),
				$on_progress
			);

			if ( is_wp_error( $result ) ) {
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$target_post_id,
						'error',
						$result->get_error_message(),
						array( 'step' => 'large_doc_partial' )
					);
				}
				update_post_meta( $target_post_id, '_gdtg_last_sync_status', 'error' );
				update_post_meta( $target_post_id, '_gdtg_last_sync_error', sanitize_text_field( $result->get_error_message() ) );
				update_post_meta( $target_post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
				delete_post_meta( $target_post_id, '_gdtg_sync_progress' );
				return $result;
			}

			// Success: finalize.
			delete_post_meta( $target_post_id, '_gdtg_sync_progress' );
			update_post_meta( $target_post_id, '_gdtg_last_sync_status', 'success' );
			delete_post_meta( $target_post_id, '_gdtg_last_sync_error' );
			update_post_meta( $target_post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
			$this->record_sync_metadata( $target_post_id, 'gdoc', $doc_id, $doc_title, $options );

			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'info',
					__( 'Large document streaming import completed.', 'draftsync' ),
					array( 'step' => 'large_doc_partial', 'progress' => 100 )
				);
			}

			return array(
				'post_id'  => $target_post_id,
				'title'    => $doc_title,
				'status'   => get_post_status( $target_post_id ),
				'is_new'   => empty( $post_id ),
				'streamed' => true,
			);
		}

		$doc_data = json_decode( $doc_json, true );
		if ( ! is_array( $doc_data ) ) {
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'error',
					__( 'Failed to parse Google Doc data.', 'draftsync' ),
					array( 'step' => 'google_doc_parse', 'error_code' => 'gdtg_parse_error' )
				);
			}
			return new WP_Error( 'gdtg_parse_error', __( 'Failed to parse Google Doc data.', 'draftsync' ) );
		}

		$doc_title = isset( $doc_data['title'] )
			? sanitize_text_field( $doc_data['title'] )
			: __( 'Google Doc Import', 'draftsync' );

		$target_post_id = absint( $post_id );

		// Parse with deferred images.
		$parser_options = $options;
		if ( ! empty( $options['import_images'] ) ) {
			$parser_options['defer_images'] = true;
		}
		$parser = new GDTG_Parser( $doc_json, $target_post_id, $parser_options );
		$applier = new GDTG_Post_Meta_Applier();
		$extracted = $applier->extract_metadata_table( $parser->parse_nodes() );
		$nodes = $extracted['nodes'];
		$table_meta = $extracted['metadata'];

		// Merge metadata: explicit passes override table metadata.
		$explicit_post_meta = isset( $options['post_meta'] ) && is_array( $options['post_meta'] ) ? $options['post_meta'] : array();
		$options['post_meta'] = array_replace_recursive( $table_meta, $explicit_post_meta );

		if ( empty( $nodes ) ) {
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'error',
					__( 'The Google Doc is empty or could not be parsed.', 'draftsync' ),
					array( 'step' => 'google_doc_empty', 'error_code' => 'gdtg_empty_doc' )
				);
			}
			return new WP_Error( 'gdtg_empty_doc', __( 'The Google Doc is empty or could not be parsed.', 'draftsync' ) );
		}

		// Batch image detection.
		$image_count     = $parser->get_image_count();
		$batch_threshold = 3;

		if ( ! empty( $options['import_images'] ) && $image_count > $batch_threshold ) {
			$created_shell = false;
			if ( 0 === $target_post_id ) {
				$target_post_id = $this->create_post_shell( $doc_title, $options );
				if ( is_wp_error( $target_post_id ) ) {
					return $target_post_id;
				}
				$created_shell = true;
			}

			$source = array( 'type' => 'gdoc', 'id' => $doc_id, 'name' => $doc_title );
			$job_id = $this->create_import_job( $doc_json, $target_post_id, $options, $nodes, $doc_title, get_current_user_id(), $created_shell, $source );

			return array(
				'post_id'  => $target_post_id,
				'title'    => $doc_title,
				'status'   => get_post_status( $target_post_id ),
				'is_new'   => $created_shell,
				'batch'    => true,
				'job_id'   => $job_id,
				'image_count' => $image_count,
			);
		}

		// Synchronous image processing.
		$created_shell = false;
		if ( ! empty( $options['import_images'] ) && $image_count > 0 ) {
			if ( 0 === $target_post_id ) {
				$target_post_id = $this->create_post_shell( $doc_title, $options );
				if ( is_wp_error( $target_post_id ) ) {
					return $target_post_id;
				}
				$created_shell = true;
			}
			$img_result = $this->process_image_placeholders( $nodes, $target_post_id, 0, $options );

			// All-images-failed policy: do not commit stripped content.
			if ( $image_count > 0 && 0 === $img_result['processed'] && $img_result['failed'] >= $image_count ) {
				if ( $created_shell ) {
					wp_delete_post( $target_post_id, true );
				}
				if ( class_exists( 'GDTG_Sync_Log' ) ) {
					GDTG_Sync_Log::record(
						$target_post_id,
						'error',
						sprintf(
							/* translators: %d: total image count */
							__( 'Import failed: all %d image(s) could not be imported.', 'draftsync' ),
							$image_count
						),
						array( 'step' => 'google_doc_images', 'error_code' => 'gdtg_all_images_failed' )
					);
				}
				return new WP_Error(
					'gdtg_all_images_failed',
					sprintf(
						/* translators: %d: total image count */
						__( 'Import failed: all %d image(s) could not be imported. Content was not committed.', 'draftsync' ),
						$image_count
					)
				);
			}
		}

		$result = $this->commit_import( $nodes, $options, $target_post_id, $doc_title );

		if ( is_wp_error( $result ) ) {
			if ( $created_shell ) {
				wp_delete_post( $target_post_id, true );
			}
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_post_id,
					'error',
					sprintf(
						/* translators: %s: error message */
						__( 'Import commit failed: %s', 'draftsync' ),
						$result->get_error_message()
					),
					array( 'step' => 'google_doc_commit', 'error_code' => $result->get_error_code() )
				);
			}
			return $result;
		}

		// Apply post metadata publishing.
		if ( isset( $result['post_id'] ) && ! empty( $options['post_meta'] ) ) {
			$result['warnings'] = $applier->apply( $result['post_id'], $nodes, $options['post_meta'] );
		}

		$this->record_sync_metadata( $result['post_id'], 'gdoc', $doc_id, $doc_title, $options );

		if ( class_exists( 'GDTG_Sync_Log' ) ) {
			GDTG_Sync_Log::record(
				$result['post_id'],
				'info',
				sprintf(
					/* translators: %d: post ID */
					__( 'Import succeeded for post #%d.', 'draftsync' ),
					$result['post_id']
				),
				array( 'step' => 'google_doc_success' )
			);
		}

		$result['is_new'] = $created_shell || empty( $post_id );
		return $result;
	}

	/**
	 * Import a .docx file from a local file path.
	 *
	 * @param string $file_path Absolute path to the .docx file.
	 * @param string $file_name Original file name (for title).
	 * @param array  $options   Normalized import options.
	 * @param int    $post_id   Target post ID (0 for new).
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function import_docx_file( $file_path, $file_name, $options, $post_id = 0 ) {
		// Validate ZIP structure.
		$validation = GDTG_Zip_Validator::validate( $file_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$target_post_id = absint( $post_id );

		$parser = new GDTG_Docx_Parser( $file_path, $target_post_id, $options );
		$applier = new GDTG_Post_Meta_Applier();
		$extracted = $applier->extract_metadata_table( $parser->parse_nodes() );
		$nodes = $extracted['nodes'];
		$table_meta = $extracted['metadata'];

		// Merge metadata: explicit passes override table metadata.
		$explicit_post_meta = isset( $options['post_meta'] ) && is_array( $options['post_meta'] ) ? $options['post_meta'] : array();
		$options['post_meta'] = array_replace_recursive( $table_meta, $explicit_post_meta );

		if ( empty( $nodes ) ) {
			return new WP_Error( 'gdtg_empty_doc', __( 'The document is empty or could not be parsed.', 'draftsync' ) );
		}

		$doc_title    = sanitize_file_name( pathinfo( $file_name, PATHINFO_FILENAME ) );
		$image_count  = $parser->get_image_count();
		$created_shell = false;

		if ( $image_count > 0 ) {
			if ( 0 === $target_post_id ) {
				$target_post_id = $this->create_post_shell( $doc_title, $options );
				if ( is_wp_error( $target_post_id ) ) {
					return $target_post_id;
				}
				$created_shell = true;
			}
			$docx_img_result = $this->process_docx_images( $nodes, $file_path, $target_post_id, $options );

			// All-images-failed policy for DOCX.
			if ( is_wp_error( $docx_img_result ) || ( $image_count > 0 && 0 === $docx_img_result['processed'] && $docx_img_result['failed'] >= $image_count ) ) {
				if ( $created_shell ) {
					wp_delete_post( $target_post_id, true );
				}
				return new WP_Error(
					'gdtg_all_images_failed',
					sprintf(
						/* translators: %d: total image count */
						__( 'Import failed: all %d image(s) could not be imported. Content was not committed.', 'draftsync' ),
						$image_count
					)
				);
			}
		}

		$result = $this->commit_import( $nodes, $options, $target_post_id, $doc_title );

		if ( is_wp_error( $result ) ) {
			if ( $created_shell ) {
				wp_delete_post( $target_post_id, true );
			}
			return $result;
		}

		// Apply post metadata publishing.
		if ( isset( $result['post_id'] ) && ! empty( $options['post_meta'] ) ) {
			$result['warnings'] = $applier->apply( $result['post_id'], $nodes, $options['post_meta'] );
		}

		// Use source context from options when available (e.g. from import_drive_file).
		$source_type = ! empty( $options['_gdtg_source'] ) ? $options['_gdtg_source'] : 'docx_upload';
		$source_id   = ! empty( $options['_gdtg_source_id'] ) ? $options['_gdtg_source_id'] : '';
		$source_name = ! empty( $options['_gdtg_source_name'] ) ? $options['_gdtg_source_name'] : $file_name;
		$this->record_sync_metadata( $result['post_id'], $source_type, $source_id, $source_name, $options );

		$result['is_new'] = $created_shell || empty( $post_id );
		return $result;
	}

	/**
	 * Commit parsed nodes to the database.
	 *
	 * @param GDTG_Doc_Node[] $nodes        Parsed AST nodes.
	 * @param array           $options       Import options.
	 * @param int             $target_post_id Target post ID (0 for new).
	 * @param string          $doc_title     Document title.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	private function commit_import( $nodes, $options, $target_post_id, $doc_title ) {
		// Render nodes using selected output mode.
		if ( 'classic' === $options['output_mode'] ) {
			$renderer = new GDTG_HTML_Renderer();
		} else {
			$renderer = new GDTG_Block_Renderer();
		}
		$overrides = $this->resolve_overrides( isset( $options['overrides'] ) ? $options['overrides'] : array() );
		$rendered_html = $renderer->render( $nodes, $overrides );

		return $this->commit_rendered_content( $rendered_html, $options, $target_post_id, $doc_title );
	}

	/**
	 * Commit pre-rendered HTML to the database, creating or updating a post.
	 *
	 * Used both by commit_import() (which renders nodes) and by the
	 * Phase 3 large-document Drive HTML export fallback (which
	 * already has HTML bytes from Google's Drive export endpoint).
	 *
	 * @param string $rendered_html  Already-rendered HTML payload.
	 * @param array  $options        Import options.
	 * @param int    $target_post_id Target post ID (0 for new).
	 * @param string $doc_title      Document title.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	private function commit_rendered_content( $rendered_html, $options, $target_post_id, $doc_title ) {
		if ( empty( $rendered_html ) ) {
			return new WP_Error( 'gdtg_empty_render', __( 'The document is empty or could not be rendered.', 'draftsync' ) );
		}

		if ( ! empty( $target_post_id ) ) {
			$final_post_id = $this->write_to_existing_post( $target_post_id, $rendered_html, $options, $doc_title );
		} else {
			$final_post_id = $this->create_new_post( $rendered_html, $options, $doc_title );
		}

		if ( is_wp_error( $final_post_id ) ) {
			return $final_post_id;
		}

		return array(
			'post_id' => $final_post_id,
			'title'   => $doc_title,
			'status'  => get_post_status( $final_post_id ),
		);
	}
	/**
	 * Write rendered content to an existing post.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $rendered_html Rendered content.
	 * @param array  $options       Import options.
	 * @param string $doc_title     Document title.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function write_to_existing_post( $post_id, $rendered_html, $options, $doc_title ) {
		$overwrite = ! empty( $options['overwrite'] );
		if ( $overwrite ) {
			$post_data = array(
				'ID'           => $post_id,
				'post_content' => $rendered_html,
			);
		} else {
			$current_post = get_post( $post_id );
			$existing     = $current_post ? $current_post->post_content : '';
			$post_data    = array(
				'ID'           => $post_id,
				'post_content' => $existing . "\n\n" . $rendered_html,
			);
		}

		$current_post = get_post( $post_id );
		if ( $current_post && ( empty( $current_post->post_title ) || __( 'Auto Draft', 'draftsync' ) === $current_post->post_title ) ) {
			$post_data['post_title'] = $doc_title;
		}

		if ( $current_post && 'draft' === $current_post->post_status && empty( $options['import_as_draft'] ) && current_user_can( 'publish_posts' ) ) {
			$post_data['post_status'] = 'publish';
		}

		// Incorporate custom metadata slug & excerpt attributes into existing post updates.
		if ( ! empty( $options['post_meta'] ) ) {
			$applier = new GDTG_Post_Meta_Applier();
			$extra_post_data = $applier->build_post_data( $options['post_meta'] );
			$post_data = array_merge( $post_data, $extra_post_data );
		}

		$result = wp_update_post( $post_data, true );
		return is_wp_error( $result ) ? $result : $post_id;
	}

	/**
	 * Create a new post with rendered content.
	 *
	 * @param string $rendered_html Rendered content.
	 * @param array  $options       Import options.
	 * @param string $doc_title     Document title.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_new_post( $rendered_html, $options, $doc_title ) {
		$post_status = 'draft';
		if ( empty( $options['import_as_draft'] ) && current_user_can( 'publish_posts' ) ) {
			$post_status = 'publish';
		}

		$new_post = array(
			'post_title'   => $doc_title,
			'post_content' => $rendered_html,
			'post_status'  => $post_status,
			'post_type'    => 'post',
		);

		// Incorporate custom metadata slug & excerpt attributes into new post writes.
		if ( ! empty( $options['post_meta'] ) ) {
			$applier = new GDTG_Post_Meta_Applier();
			$extra_post_data = $applier->build_post_data( $options['post_meta'] );
			$new_post = array_merge( $new_post, $extra_post_data );
		}

		$default_cat    = get_option( 'gdtg_default_category', 0 );
		$default_author = get_option( 'gdtg_default_author', 0 );

		if ( $default_cat > 0 ) {
			$new_post['post_category'] = array( absint( $default_cat ) );
		}

		if ( $default_author > 0 && get_userdata( absint( $default_author ) ) ) {
			$new_post['post_author'] = absint( $default_author );
		}

		return wp_insert_post( $new_post, true );
	}

	/**
	 * Create a draft post shell for batch jobs or image attachment.
	 *
	 * @param string $doc_title Document title.
	 * @param array  $options   Import options.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_post_shell( $doc_title, $options ) {
		$new_post = array(
			'post_title'   => $doc_title,
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		);

		$default_cat    = get_option( 'gdtg_default_category', 0 );
		$default_author = get_option( 'gdtg_default_author', 0 );

		if ( $default_cat > 0 ) {
			$new_post['post_category'] = array( absint( $default_cat ) );
		}

		if ( $default_author > 0 && get_userdata( absint( $default_author ) ) ) {
			$new_post['post_author'] = absint( $default_author );
		}

		return wp_insert_post( $new_post, true );
	}

	// ─── Batch Import Job Methods ───────────────────────────────────

	/**
	 * Create an import job transient for batch image processing.
	 *
	 * @param string          $doc_json     Raw Google Doc JSON.
	 * @param int             $post_id      Target post ID.
	 * @param array           $options      Normalized import options.
	 * @param GDTG_Doc_Node[] $nodes        Parsed AST nodes.
	 * @param string          $doc_title    Document title.
	 * @param int             $user_id      Current user ID.
	 * @param bool            $created_shell Whether the job owns a new shell post.
	 * @param array           $source       Source context: {type, id, name}.
	 * @return string Job ID.
	 */
	public function create_import_job( $doc_json, $post_id, $options, $nodes, $doc_title, $user_id, $created_shell = false, $source = array() ) {
		$job_id  = bin2hex( random_bytes( 16 ) );
		$job_key = 'gdtg_import_job_' . $job_id;

		$job_data = array(
			'job_id'        => $job_id,
			'created_shell' => (bool) $created_shell,
			'doc_json'      => $doc_json,
			'post_id'       => $post_id,
			'options'       => $options,
			'nodes'         => $this->serialize_nodes( $nodes ),
			'doc_title'     => $doc_title,
			'user_id'       => $user_id,
			'source'        => $source,
			'image_total'   => $this->count_images_in_nodes( $nodes ),
			'image_done'    => 0,
			'image_failed'  => 0,
			'status'        => 'pending',
			'created_at'    => time(),
		);

		set_transient( $job_key, $job_data, HOUR_IN_SECONDS );

		return $job_id;
	}


	/**
	 * Retrieve a stored import job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null if expired/missing.
	 */
	public function get_import_job( $job_id ) {
		$job_key  = 'gdtg_import_job_' . $job_id;
		$job_data = get_transient( $job_key );
		return is_array( $job_data ) ? $job_data : null;
	}

	/**
	 * Update a stored import job.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $job_data Updated job data.
	 */
	public function update_import_job( $job_id, $job_data ) {
		$job_key = 'gdtg_import_job_' . $job_id;
		set_transient( $job_key, $job_data, HOUR_IN_SECONDS );
	}

	// ─── Node Serialization ─────────────────────────────────────────

	/**
	 * Serialize AST nodes to a JSON-safe array for transient storage.
	 *
	 * @param GDTG_Doc_Node[] $nodes AST nodes.
	 * @return array JSON-serializable array.
	 */
	public function serialize_nodes( $nodes ) {
		$result = array();
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			$result[] = array(
				'type'     => $node->type,
				'content'  => $node->content,
				'attrs'    => $node->attrs,
				'children' => $this->serialize_nodes( $node->children ),
			);
		}
		return $result;
	}

	/**
	 * Deserialize stored node arrays back to GDTG_Doc_Node objects.
	 *
	 * @param array $data Serialized node arrays.
	 * @return GDTG_Doc_Node[] AST nodes.
	 */
	public function deserialize_nodes( $data ) {
		$nodes = array();
		foreach ( $data as $item ) {
			$children = isset( $item['children'] ) ? $this->deserialize_nodes( $item['children'] ) : array();
			$nodes[]  = new GDTG_Doc_Node(
				isset( $item['type'] ) ? $item['type'] : 'paragraph',
				isset( $item['content'] ) ? $item['content'] : '',
				isset( $item['attrs'] ) ? $item['attrs'] : array(),
				$children
			);
		}
		return $nodes;
	}

	// ─── Image Processing ───────────────────────────────────────────

	/**
	 * Count total image nodes in the AST.
	 *
	 * @param GDTG_Doc_Node[] $nodes AST nodes.
	 * @return int Image count.
	 */
	private function count_images_in_nodes( $nodes ) {
		$count = 0;
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			if ( 'image' === $node->type ) {
				$count++;
			}
			if ( ! empty( $node->children ) ) {
				$count += $this->count_images_in_nodes( $node->children );
			}
		}
		return $count;
	}

	/**
	 * Walk the AST and collect image placeholder references for sideloading.
	 *
	 * Only collects images that have `source_url` (deferred placeholders) and
	 * have not yet been processed.
	 *
	 * @param GDTG_Doc_Node[] $nodes     AST nodes.
	 * @param array           &$collector Flat array of image info with node refs.
	 */
	public function collect_image_placeholders( $nodes, &$collector ) {
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			if ( 'image' === $node->type && ! empty( $node->attrs['source_url'] ) ) {
				$collector[] = array(
					'node'       => $node,
					'source_url' => $node->attrs['source_url'],
					'alt'        => isset( $node->attrs['alt'] ) ? $node->attrs['alt'] : '',
				);
			}
			if ( ! empty( $node->children ) ) {
				$this->collect_image_placeholders( $node->children, $collector );
			}
		}
	}

	/**
	 * Process image placeholder nodes in-place: sideload and fill in id/url.
	 *
	 * @param GDTG_Doc_Node[] $nodes   AST nodes (mutated).
	 * @param int             $post_id Post ID to attach images to.
	 * @param int             $limit   Max number of images to process (0 = all).
	 * @param array           $options Import options.
	 * @return array{processed: int, failed: int} Counts of processed and failed images.
	 */
	public function process_image_placeholders( $nodes, $post_id, $limit = 0, $options = array() ) {
		$placeholders = array();
		$this->collect_image_placeholders( $nodes, $placeholders );

		$processed = 0;
		$failed    = 0;

		$batch = $limit > 0 ? array_slice( $placeholders, 0, $limit ) : $placeholders;

		foreach ( $batch as $img_info ) {
			$node = $img_info['node'];
			$sideload_options = array();
			if ( is_array( $options ) && isset( $options['optimize_images'] ) ) {
				$sideload_options['optimize_images'] = $options['optimize_images'];
			}
			$wp_id = GDTG_Sideloader::sideload( $img_info['source_url'], $post_id, $img_info['alt'], $sideload_options );
			if ( $wp_id ) {
				$uploaded_url            = wp_get_attachment_url( $wp_id );
				$node->attrs['id']       = $wp_id;
				$node->attrs['url']      = $uploaded_url;
				unset( $node->attrs['source_url'] );
				$processed++;
			} else {
				$node->attrs['source_url'] = '';
				$node->attrs['alt']        = $img_info['alt'] . ' [import failed]';
				$failed++;
			}
		}

		return array( 'processed' => $processed, 'failed' => $failed );
	}

	/**
	 * Process embedded image placeholders in .docx-parsed nodes.
	 *
	 * Opens the ZIP archive exactly once and delegates to a recursive
	 * helper for AST traversal.
	 *
	 * @param GDTG_Doc_Node[] $nodes     AST nodes.
	 * @param string          $file_path Path to the .docx file.
	 * @param int             $post_id   Target post ID.
	 * @param array           $options   Import options.
	 * @return array{processed: int, failed: int} Counts of processed and failed images.
	 */
	public function process_docx_images( $nodes, $file_path, $post_id, $options ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'gdtg_docx_zip_open_failed', __( 'Could not reopen DOCX file to read embedded images.', 'draftsync' ) );
		}

		$counts = $this->process_docx_images_recursive( $zip, $nodes, $post_id, $options );
		$zip->close();
		return $counts;
	}

	/**
	 * Recursive helper for processing DOCX image nodes.
	 *
	 * @param ZipArchive      $zip     Already-open ZIP archive.
	 * @param GDTG_Doc_Node[] $nodes   AST nodes.
	 * @param int             $post_id Target post ID.
	 * @param array           $options Import options.
	 * @return array{processed: int, failed: int}
	 */
	private function process_docx_images_recursive( $zip, $nodes, $post_id, $options ) {
		$processed = 0;
		$failed    = 0;

		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}

			if ( 'image' === $node->type && ! empty( $node->attrs['source_name'] ) ) {
				$source_name = $node->attrs['source_name'];
				$bytes = $zip->getFromName( $source_name );
				if ( false !== $bytes ) {
					$filename = basename( $source_name );
					$alt = isset( $node->attrs['alt'] ) ? $node->attrs['alt'] : '';
					$attachment_id = GDTG_Sideloader::sideload_from_bytes( $bytes, $filename, $post_id, $alt, $options );
					if ( $attachment_id ) {
						$url = wp_get_attachment_url( $attachment_id );
						$node->attrs['id']  = $attachment_id;
						$node->attrs['url'] = $url;
						unset( $node->attrs['source_name'] );
						$processed++;
					} else {
						$failed++;
					}
				} else {
					$failed++;
				}
			}

			if ( ! empty( $node->children ) ) {
				$child_counts = $this->process_docx_images_recursive( $zip, $node->children, $post_id, $options );
				$processed += $child_counts['processed'];
				$failed    += $child_counts['failed'];
			}
		}

		return array( 'processed' => $processed, 'failed' => $failed );
	}

	// ─── Rendering ──────────────────────────────────────────────────

	/**
	 * Render nodes using the selected output mode.
	 *
	 * @param GDTG_Doc_Node[] $nodes   AST nodes.
	 * @param array           $options Import options.
	 * @return string Rendered HTML.
	 */
	public function render_nodes( $nodes, $options ) {
		if ( 'classic' === $options['output_mode'] ) {
			$renderer = new GDTG_HTML_Renderer();
		} else {
			$renderer = new GDTG_Block_Renderer();
		}
		$overrides = $this->resolve_overrides( isset( $options['overrides'] ) ? $options['overrides'] : array() );
		return $renderer->render( $nodes, $overrides );
	}

	/**
	 * Fill any missing override key from the global default option.
	 *
	 * Callers (REST, CLI, bulk, re-sync) pass explicit overrides only;
	 * this helper supplies the admin-configured defaults for keys the
	 * caller did not set, so every import path honors global style
	 * defaults without duplicating the fallback logic.
	 *
	 * @param array $overrides Explicit overrides from caller.
	 * @return array Merged overrides with globals for missing keys.
	 */
	private function resolve_overrides( $overrides ) {
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}
		if ( ! array_key_exists( 'heading_demotion', $overrides ) ) {
			$overrides['heading_demotion'] = max( 0, min( 5, (int) get_option( 'gdtg_default_heading_demotion', 0 ) ) );
		}
		if ( ! array_key_exists( 'min_heading_level', $overrides ) ) {
			$overrides['min_heading_level'] = max( 1, min( 6, (int) get_option( 'gdtg_default_min_heading_level', 1 ) ) );
		}
		if ( ! array_key_exists( 'default_alignment', $overrides ) ) {
			$align = (string) get_option( 'gdtg_default_alignment', '' );
			$overrides['default_alignment'] = in_array( $align, array( '', 'left', 'center', 'right' ), true ) ? $align : '';
		}
		return $overrides;
	}

	// ─── Response Formatting ────────────────────────────────────────

	/**
	 * Format an orchestrator result array or WP_Error into a structured result.
	 *
	 * @param array|WP_Error $result Orchestrator result.
	 * @return array Response data ready for WP_REST_Response.
	 */
	public static function result_to_response_data( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$data = array(
			'success' => true,
			'post_id' => isset( $result['post_id'] ) ? $result['post_id'] : 0,
			'title'   => isset( $result['title'] ) ? $result['title'] : '',
			'status'  => isset( $result['status'] ) ? $result['status'] : '',
		);

		if ( isset( $result['post_id'] ) && $result['post_id'] ) {
			$data['edit_url'] = get_edit_post_link( $result['post_id'], 'raw' );
		}
		if ( ! empty( $result['migrated'] ) ) {
			$data['migrated']      = true;
			$data['migrated_from'] = isset( $result['migrated_from'] ) ? $result['migrated_from'] : '';
			$data['migrated_to']   = isset( $result['migrated_to'] ) ? $result['migrated_to'] : '';
		}

		if ( ! empty( $result['is_new'] ) ) {
			$data['message'] = 'publish' === ( isset( $result['status'] ) ? $result['status'] : '' )
				? __( 'Google Doc imported and published successfully.', 'draftsync' )
				: __( 'Google Doc imported as a new draft successfully.', 'draftsync' );
		} else {
			$data['message'] = __( 'Post content synchronized successfully.', 'draftsync' );
		}

		if ( ! empty( $result['warnings'] ) ) {
			$data['warnings'] = $result['warnings'];
		}

		if ( ! empty( $result['batch'] ) ) {
			$data['batch']        = true;
			$data['job_id']       = $result['job_id'];
			$data['image_count']  = $result['image_count'];
			$data['batch_size']   = 3;
		}

		return $data;
	}

	/**
	 * Classify a WP_Error into a small actionable category.
	 *
	 * @param mixed $error Error candidate.
	 * @return string One of: rate_limited, network, api_unavailable, auth, unsupported, unknown.
	 */
	public static function classify_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return 'unknown';
		}

		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		if ( 'gdtg_rate_limited' === $code || false !== stripos( $message, 'rate limit' ) ) {
			return 'rate_limited';
		}

		if ( 'gdtg_no_token' === $code || false !== stripos( $message, 'auth' ) || false !== stripos( $message, 'token' ) ) {
			return 'auth';
		}

		if ( false !== stripos( $code, 'http_request' ) || false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'curl' ) ) {
			return 'network';
		}

		if ( false !== stripos( $code, 'unsupported' ) || false !== stripos( $message, 'unsupported' ) ) {
			return 'unsupported';
		}

		if ( false !== stripos( $code, 'drive' ) || false !== stripos( $message, 'unavailable' ) || false !== stripos( $message, 'server error' ) ) {
			return 'api_unavailable';
		}

		return 'unknown';
	}

	/**
	 * Import a Google Drive file by ID.
	 *
	 * Fetches Drive metadata, validates MIME type, downloads .docx bytes to a
	 * temp file, and delegates to import_docx_file().
	 *
	 * Auto-migration: if the file's mimeType is
	 * application/vnd.google-apps.document (i.e. the .docx was converted to
	 * a native Google Doc in Drive), the import is transparently handed off
	 * to import_google_doc(). On success, the post's _gdtg_source_type is
	 * updated to 'gdoc' and the result includes migration metadata.
	 *
	 * @param string $file_id  Drive file ID.
	 * @param array  $options  Normalized import options. May include internal
	 *                         keys: _gdtg_migrating (recursion guard, not persisted).
	 * @param int    $post_id  Target post ID (0 for new).
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function import_drive_file( $file_id, $options, $post_id = 0 ) {
		$api      = new GDTG_API();
		$metadata = $api->get_drive_file_metadata( $file_id );
		$degraded = false;

		if ( ! is_wp_error( $metadata ) && $post_id > 0 ) {
			$fresh_mime = isset( $metadata['mimeType'] ) ? $metadata['mimeType'] : '';
			update_post_meta( $post_id, '_gdtg_drive_mime_type', sanitize_text_field( $fresh_mime ) );
			update_post_meta( $post_id, '_gdtg_drive_mime_cached_at', current_time( 'mysql' ) );
		}

		if ( is_wp_error( $metadata ) && $post_id > 0 ) {
			$cached_mime = get_post_meta( $post_id, '_gdtg_drive_mime_type', true );
			if ( ! empty( $cached_mime ) ) {
				$metadata = array(
					'name'     => get_post_meta( $post_id, '_gdtg_source_name', true ),
					'mimeType' => $cached_mime,
				);
				$degraded = true;
			} else {
				return $metadata;
			}
		}

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$mime_type = isset( $metadata['mimeType'] ) ? $metadata['mimeType'] : '';
		$file_name = isset( $metadata['name'] ) ? sanitize_text_field( $metadata['name'] ) : 'Drive File';

		if ( 'application/vnd.google-apps.document' === $mime_type ) {
			if ( $post_id > 0 ) {
				update_post_meta( $post_id, '_gdtg_migration_pending', '1' );
			}

			// Record migration warning.
			$target_pid = absint( $post_id );
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				GDTG_Sync_Log::record(
					$target_pid,
					'warning',
					sprintf(
						/* translators: %s: file name */
						__( 'Drive .docx file "%s" was converted to a native Google Doc in Drive. Migrating import to Google Doc API.', 'draftsync' ),
						$file_name
					),
					array( 'step' => 'drive_migration' )
				);
			}

			$options['_gdtg_migrating'] = true;
			$result = $this->import_google_doc( $file_id, $options, $post_id );

			if ( is_wp_error( $result ) && $post_id > 0 ) {
				delete_post_meta( $post_id, '_gdtg_migration_pending' );
			}

			if ( ! is_wp_error( $result ) ) {
				$result['migrated']      = true;
				$result['migrated_from'] = 'drive_file';
				$result['migrated_to']   = 'gdoc';
			}

			if ( $degraded ) {
				if ( is_wp_error( $result ) ) {
					$result->add( 'gdtg_degraded', __( 'Metadata fetch failed; used cached mimeType.', 'draftsync' ) );
				} else {
					$result['degraded'] = true;
					if ( ! isset( $result['warnings'] ) ) {
						$result['warnings'] = array();
					}
					$result['warnings'][] = __( 'Used cached mimeType for migration because metadata fetch failed.', 'draftsync' );
				}
			}

			return $result;
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $mime_type ) {
			return new WP_Error(
				'gdtg_unsupported_drive_mime',
				sprintf(
					/* translators: %s: file name */
					__( 'Unsupported file type: %s. Only .docx files from Google Drive are supported.', 'draftsync' ),
					$file_name
				)
			);
		}

		$bytes = $api->fetch_drive_file( $file_id );
		if ( is_wp_error( $bytes ) ) {
			if ( $degraded ) {
				$bytes->add( 'gdtg_degraded', __( 'Metadata fetch failed; used cached mimeType for Drive download routing.', 'draftsync' ) );
			}
			return $bytes;
		}

		$temp_file = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'gdtg-drive-docx-' . $file_id ) : tempnam( sys_get_temp_dir(), 'gdtg-' );
		if ( ! $temp_file ) {
			return new WP_Error( 'gdtg_temp_file', __( 'Could not create temporary file.', 'draftsync' ) );
		}

		$written = file_put_contents( $temp_file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		unset( $bytes );
		if ( false === $written ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'gdtg_temp_write', __( 'Could not write file to disk.', 'draftsync' ) );
		}

		$drive_options = $options;
		$drive_options['_gdtg_source']      = 'drive_file';
		$drive_options['_gdtg_source_id']   = $file_id;
		$drive_options['_gdtg_source_name'] = $file_name;
		$result = $this->import_docx_file( $temp_file, $file_name, $drive_options, $post_id );
		wp_delete_file( $temp_file );

		if ( $degraded ) {
			if ( is_wp_error( $result ) ) {
				$result->add( 'gdtg_degraded', __( 'Metadata fetch failed; used cached mimeType for Drive download routing.', 'draftsync' ) );
			} else {
				$result['degraded'] = true;
				if ( ! isset( $result['warnings'] ) ) {
					$result['warnings'] = array();
				}
				$result['warnings'][] = __( 'Used cached mimeType for Drive download routing because metadata fetch failed.', 'draftsync' );
			}
		}

		return $result;
	}

	/**
	 * Finalize an import: apply metadata and record sync info.
	 *
	 * Called after render + write have already happened (e.g. batch job completion).
	 * Does NOT render or write content — only applies metadata and sync recording.
	 *
	 * @param int   $post_id   The post ID that was written to.
	 * @param array $nodes     The parsed AST nodes (for featured image resolution).
	 * @param array $options   Import options (must include post_meta if applicable).
	 * @param array $source    Source context: {type, id, name}.
	 * @return array Warnings from metadata application.
	 */
	public function finalize_import( $post_id, $nodes, $options, $source ) {
		$warnings = array();
		if ( ! empty( $options['post_meta'] ) ) {
			$applier = new GDTG_Post_Meta_Applier();
			$warnings = $applier->apply( $post_id, $nodes, $options['post_meta'] );
		}

		$source_type = isset( $source['type'] ) ? $source['type'] : 'unknown';
		$source_id   = isset( $source['id'] ) ? $source['id'] : '';
		$source_name = isset( $source['name'] ) ? $source['name'] : '';
		$this->record_sync_metadata( $post_id, $source_type, $source_id, $source_name, $options );
		return $warnings;
	}

	/**
	 * Records the linked re-sync details as post meta on success.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $source_type Source type (gdoc, drive_file, docx_upload).
	 * @param string $source_id   Source document/file ID.
	 * @param string $source_name Source title or file name.
	 * @param array  $options     Sanitized import options to persist for replay (optional).
	 */
	private function record_sync_metadata( $post_id, $source_type, $source_id, $source_name, $options = array() ) {
		delete_post_meta( $post_id, '_gdtg_migration_pending' );
		update_post_meta( $post_id, '_gdtg_source_type', $source_type );
		update_post_meta( $post_id, '_gdtg_source_id', $source_id );
		update_post_meta( $post_id, '_gdtg_source_name', $source_name );
		update_post_meta( $post_id, '_gdtg_last_imported_at', current_time( 'mysql' ) );
		$post    = get_post( $post_id );
		$content = $post ? $post->post_content : '';
		update_post_meta( $post_id, '_gdtg_last_content_hash', md5( $content ) );
		if ( ! empty( $options ) ) {
			$safe_options = $this->sanitize_options_for_persistence( $options );
			update_post_meta( $post_id, '_gdtg_import_options', wp_json_encode( $safe_options ) );
		}
		$user_id = get_current_user_id();
		if ( 0 !== $user_id ) {
			update_post_meta( $post_id, '_gdtg_sync_user_id', $user_id );
		}
	}
	/**
	 * Sanitize import options for safe persistence and replay.
	 *
	 * Stores only allowlisted values: booleans, output mode, overrides,
	 * post_meta, optimize flag, overwrite policy.
	 *
	 * @param array $options Raw import options.
	 * @return array Sanitized options safe for JSON persistence.
	 */
	private function sanitize_options_for_persistence( $options ) {
		$safe = array();
		$bool_keys = array( 'import_images', 'import_tables', 'overwrite', 'import_as_draft', 'optimize_images' );
		foreach ( $bool_keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$safe[ $key ] = (bool) $options[ $key ];
			}
		}
		if ( isset( $options['output_mode'] ) && in_array( $options['output_mode'], array( 'gutenberg', 'classic' ), true ) ) {
			$safe['output_mode'] = $options['output_mode'];
		}
		if ( isset( $options['overrides'] ) && is_array( $options['overrides'] ) ) {
			$safe['overrides'] = $options['overrides'];
		}
		if ( isset( $options['post_meta'] ) && is_array( $options['post_meta'] ) ) {
			$safe['post_meta'] = $options['post_meta'];
		}
		return $safe;
	}

} // End of class GDTG_Import_Orchestrator
