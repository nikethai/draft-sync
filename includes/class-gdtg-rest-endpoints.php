<?php
/**
 * Custom WordPress REST API Endpoints for Google Docs Importer
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_REST_Endpoints
 *
 * Registers and handles REST API routes for Google Docs import.
 */
class GDTG_REST_Endpoints {

	/**
	 * Loader instance.
	 *
	 * @var GDTG_Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param GDTG_Loader $loader The loader instance.
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		$this->loader->add_action( 'rest_api_init', $this, 'register_routes' );
		$this->loader->add_action( 'gdtg_run_queued_sync', $this, 'run_queued_sync' );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			'gdtg/v1',
			'/import',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_import' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'doc_id'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && '' !== $value;
						},
					],
					'post_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'import_images' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
					],
					'import_tables' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
					],
					'overwrite' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
					'import_as_draft' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
					],
					'output_mode' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'gutenberg',
						'enum'              => [ 'gutenberg', 'classic' ],
					],
				'optimize_images' => [
						'required'          => false,
						'type'              => 'boolean',
					],
					'heading_demotion' => [
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 0,
						'maximum'           => 5,
						'sanitize_callback' => 'absint',
					],
					'min_heading_level' => [
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 6,
						'sanitize_callback' => 'absint',
					],
					'default_alignment' => [
						'required'          => false,
						'type'              => 'string',
						'enum'              => [ '', 'left', 'center', 'right' ],
					],
					'post_meta' => [
						'required'          => false,
						'type'              => [ 'object', 'string' ],
						'validate_callback' => function( $value ) {
							return is_array( $value ) || is_string( $value );
						},
						'description'       => __( 'Metadata structure containing SEO fields, terms, slug/excerpt overrides, ACF fields, and generic custom meta mappings.', 'draftsync' ),
					],
				],
			]
		);

		register_rest_route(
			'gdtg/v1',
			'/import/(?P<job_id>[a-f0-9]+)/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_job_status' ],
				'permission_callback' => [ $this, 'check_job_permissions' ],
				'args'                => [
					'job_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'gdtg/v1',
			'/import/(?P<job_id>[a-f0-9]+)/continue',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_job_continue' ],
				'permission_callback' => [ $this, 'check_job_permissions' ],
				'args'                => [
					'job_id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'gdtg/v1',
			'/upload-docx',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_upload_docx' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'import_tables' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
					],
					'overwrite' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
					'import_as_draft' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
					],
					'output_mode' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'gutenberg',
						'enum'              => [ 'gutenberg', 'classic' ],
					],
					'heading_demotion' => [
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 0,
						'maximum'           => 5,
						'sanitize_callback' => 'absint',
					],
					'min_heading_level' => [
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 6,
						'sanitize_callback' => 'absint',
					],
					'default_alignment' => [
						'required'          => false,
						'type'              => 'string',
						'enum'              => [ '', 'left', 'center', 'right' ],
					],
					'post_meta' => [
						'required'          => false,
						'type'              => [ 'object', 'string' ],
						'validate_callback' => function( $value ) {
							return is_array( $value ) || is_string( $value );
						},
						'description'       => __( 'Metadata structure containing SEO fields, terms, slug/excerpt overrides, ACF fields, and generic custom meta mappings.', 'draftsync' ),
					],
				],
			]
		);

		register_rest_route(
			'gdtg/v1',
			'/import-bulk',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_import_bulk' ],
				'permission_callback' => [ $this, 'check_bulk_permissions' ],
				'args'                => [
					'rows' => [
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => function( $value ) {
							return is_array( $value ) && ! empty( $value );
						},
					],
					'dry_run' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_sync' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'force' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_sync_status' ],
				'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
				'args'                => [
					'post_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/settings/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_sync_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'post_id'       => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'auto_sync'     => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => null,
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_sync_run' ],
				'permission_callback' => function() { return current_user_can( 'manage_options' ); },
				'args'                => [
					'limit'   => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					],
					'force'   => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
					'dry_run' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/imported-docs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_imported_docs_list' ],
				'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			]
		);

		// ── Sync events (Phase 3) ──
		register_rest_route(
			'gdtg/v1',
			'/sync/(?P<post_id>\d+)/events',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_sync_events' ],
				'permission_callback' => [ $this, 'check_events_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/(?P<post_id>\d+)/events/clear',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_sync_events_clear' ],
				'permission_callback' => [ $this, 'check_events_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			'gdtg/v1',
			'/sync/(?P<post_id>\d+)/queue',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_sync_queue' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// ── Picker config (Phase 2) ──
		register_rest_route(
			'gdtg/v1',
			'/picker/config',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_picker_config' ],
				'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
			]
		);

		// ── Picker-scoped access token (Phase 2) ──
		register_rest_route(
			'gdtg/v1',
			'/auth/token',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_picker_token' ],
				'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
				'args'                => [
					'purpose' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return 'picker' === $value;
						},
					],
				],
			]
		);
	}

	/**
	 * Permission callback — enforces post-level (IDOR-safe) capability checks.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_permissions( $request ) {
		$post_id = $request->get_param( 'post_id' );


		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'gdtg_post_not_found',
					__( 'The specified post was not found.', 'draftsync' ),
					[ 'status' => 404 ]
				);
			}

			if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
				return new WP_Error(
					'gdtg_unsupported_post_type',
					__( 'This post type is not supported for Google Doc import.', 'draftsync' ),
					[ 'status' => 403 ]
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'gdtg_forbidden',
					__( 'You do not have permission to edit this post.', 'draftsync' ),
					[ 'status' => 403 ]
				);
			}

			return true;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'gdtg_forbidden',
				__( 'You do not have permission to create posts.', 'draftsync' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 */
	public function check_bulk_permissions( $request ) {


		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'gdtg_forbidden',
				__( 'You do not have permission to perform bulk imports.', 'draftsync' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Process POST /gdtg/v1/import-bulk REST endpoint.
	 */
	public function handle_import_bulk( $request ) {
		$this->raise_execution_budget();
		$rows = $request->get_param( 'rows' );
		$dry_run = GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'dry_run' ), false );

		// Cap bulk requests to 100 rows.
		if ( is_array( $rows ) && count( $rows ) > 100 ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Bulk import is limited to 100 rows per request.', 'draftsync' ) ),
				400
			);
		}

		$summary = array(
			'success' => 0,
			'failed'  => 0,
		);
		$results = array();
		$orchestrator = new GDTG_Import_Orchestrator();

		foreach ( $rows as $idx => $row ) {
			$source  = isset( $row['source'] ) ? trim( $row['source'] ) : '';
			$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;

			if ( empty( $source ) ) {
				$results[] = array(
					'row'     => $idx + 1,
					'success' => false,
					'message' => __( 'Empty or missing "source" field.', 'draftsync' ),
				);
				$summary['failed']++;
				continue;
			}

			// Verify capability checks on target post.
			if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => false,
					'message' => __( 'Lacks permission to edit target post.', 'draftsync' ),
				);
				$summary['failed']++;
				continue;
			}

			// Validate and normalize options (includes metadata JSON parsing, canonical URL, acf/meta).
			$options = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row );
			if ( is_wp_error( $options ) ) {
				$results[] = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => false,
					'message' => $options->get_error_message(),
				);
				$summary['failed']++;
				continue;
			}

			// Validate source reference (REST rejects local .docx).
			$source_ref = $this->parse_source_reference( $source );
			if ( is_wp_error( $source_ref ) ) {
				$results[] = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => false,
					'message' => __( 'Invalid source. REST bulk accepts Google Docs URLs/IDs or Drive file URLs only. Use CLI for local .docx files.', 'draftsync' ),
				);
				$summary['failed']++;
				continue;
			}

			// Dry-run: validated successfully without importing.
			if ( $dry_run ) {
				$results[] = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => true,
					'message' => __( 'Dry Run - Validated successfully.', 'draftsync' ),
				);
				$summary['success']++;
				continue;
			}

			// Perform the actual import.
			if ( 'drive_file' === $source_ref['type'] ) {
				$res = $orchestrator->import_drive_file( $source_ref['id'], $options, $post_id );
			} else {
				$res = $orchestrator->import_google_doc( $source_ref['id'], $options, $post_id );
			}

			if ( is_wp_error( $res ) ) {
				$results[] = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => false,
					'message' => $res->get_error_message(),
				);
				$summary['failed']++;
			} else {
				$entry = array(
					'row'     => $idx + 1,
					'source'  => $source,
					'success' => true,
					'post_id' => $res['post_id'],
					'title'   => $res['title'],
					'message' => ! empty( $res['is_new'] )
						? __( 'Post created successfully.', 'draftsync' )
						: __( 'Post updated successfully.', 'draftsync' ),
				);
				if ( ! empty( $res['warnings'] ) ) {
					$entry['warnings'] = $res['warnings'];
				}
				$results[] = $entry;
				$summary['success']++;
			}
		}

		return new WP_REST_Response(
			array(
				'success' => $summary['failed'] === 0,
				'summary' => $summary,
				'results' => $results,
			),
			200
		);
	}



	/**
	 * Handle POST /gdtg/v1/sync/{post_id} – synchronizes linked posts.
	 */
	public function handle_sync( $request ) {
		$this->raise_execution_budget();
		$post_id = absint( $request->get_param( 'post_id' ) );

		$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
		$source_id   = get_post_meta( $post_id, '_gdtg_source_id', true );
		$source_name = get_post_meta( $post_id, '_gdtg_source_name', true );
		$last_hash   = get_post_meta( $post_id, '_gdtg_last_content_hash', true );

		if ( empty( $source_type ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Post is not linked to any source document. Cannot re-sync.', 'draftsync' ) ],
				400
			);
		}

		if ( 'docx_upload' === $source_type ) {
			return new WP_REST_Response(
				/* translators: %s: the source filename from the original import */
				[ 'message' => sprintf( __( 'Post was imported via local docx upload (%s). Drag-and-drop uploads cannot re-sync dynamically.', 'draftsync' ), $source_name ) ],
				400
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Post not found.', 'draftsync' ) ],
				404
			);
		}

		$current_hash = md5( $post->post_content );
		$force = GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'force' ), false );

		// Missing content hash means we cannot detect local edits — require explicit force.
		if ( empty( $last_hash ) && ! $force ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Conflict: No baseline content hash recorded for this post. Cannot detect local edits safely. Enable force sync to proceed anyway.', 'draftsync' ) ],
				409
			);
		}

		if ( ! empty( $last_hash ) && $current_hash !== $last_hash && ! $force ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Conflict Detected: The post content was modified locally in WordPress since the last import. Enable force sync to override.', 'draftsync' ) ],
				409
			);
		}

		// Acquire per-post sync lock to prevent duplicate concurrent work.
		if ( ! GDTG_Sync_Lock::acquire( $post_id ) ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %d: post ID */
						__( 'Sync already in progress for post #%d', 'draftsync' ),
						$post_id
					),
					'batch'  => false,
					'locked' => true,
				),
				423
			);
		}

		// Prefer persisted import options over hardcoded defaults.
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

		$orchestrator = new GDTG_Import_Orchestrator();

		try {
			GDTG_Sync_Lock::heartbeat( $post_id, 300 );
			if ( 'gdoc' === $source_type ) {
				$result = $orchestrator->import_google_doc( $source_id, $options, $post_id );
			} elseif ( 'drive_file' === $source_type ) {
				$result = $orchestrator->import_drive_file( $source_id, $options, $post_id );
			} else {
				return new WP_REST_Response(
				/* translators: %s: the source type value */
					array( 'message' => sprintf( __( 'Unsupported source type "%s". Only gdoc and drive_file can re-sync.', 'draftsync' ), $source_type ) ),
					400
				);
			}
		} finally {
			GDTG_Sync_Lock::release( $post_id );
		}

		return $this->orchestrator_result_to_response( $result );
	}

	/**
	 * Convert an orchestrator result (array or WP_Error) into a WP_REST_Response.
	 *
	 * @param array|WP_Error $result Orchestrator result.
	 * @return WP_REST_Response
	 */
	private function orchestrator_result_to_response( $result ) {
		$data = GDTG_Import_Orchestrator::result_to_response_data( $result );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$status = 400;
			if ( 'gdtg_empty_doc' === $error_code || 'gdtg_empty_render' === $error_code ) {
				$status = 400;
			}
			return new WP_REST_Response( $data, $status );
		}

		return new WP_REST_Response( $data, 200 );
	}


	/**
	 * Normalize import options from request params to a canonical array.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return array Normalized options with defaults.
	 */
	private function normalize_import_options( $request ) {
		$optimize_images = $request->has_param( 'optimize_images' )
			? GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'optimize_images' ), (bool) get_option( 'gdtg_optimize_images', '1' ) )
			: (bool) get_option( 'gdtg_optimize_images', '1' );

		$options = array(
			'import_images'   => GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'import_images' ), true ),
			'import_tables'   => GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'import_tables' ), true ),
			'overwrite'       => GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'overwrite' ), false ),
			'import_as_draft' => GDTG_Import_Orchestrator::parse_bool_strict( $request->get_param( 'import_as_draft' ), false ),
			'output_mode'     => in_array( $request->get_param( 'output_mode' ), array( 'gutenberg', 'classic' ), true )
				? $request->get_param( 'output_mode' )
				: 'gutenberg',
			'optimize_images' => $optimize_images,
			'overrides'       => $this->normalize_overrides( $request ),
		);

		// Start with post_meta object if provided via REST body, then flat fields override specific keys.
		$post_meta = array();
		$post_meta_obj = $request->get_param( 'post_meta' );
		if ( is_array( $post_meta_obj ) ) {
			$post_meta = $post_meta_obj;
		} elseif ( is_string( $post_meta_obj ) && '' !== $post_meta_obj ) {
			$decoded = json_decode( $post_meta_obj, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'gdtg_invalid_metadata_json',
					__( 'The post_meta field contains invalid JSON.', 'draftsync' ),
					[ 'status' => 400 ]
				);
			}
			$post_meta = $decoded;
		}

		$slug = $request->get_param( 'slug' );
		if ( ! empty( $slug ) ) {
			$post_meta['slug'] = sanitize_title( $slug );
		}
		$excerpt = $request->get_param( 'excerpt' );
		if ( ! empty( $excerpt ) ) {
			$post_meta['excerpt'] = sanitize_textarea_field( $excerpt );
		}

		// Promote sidebar-style flat SEO keys from within post_meta into nested seo structure.
		$seo = isset( $post_meta['seo'] ) && is_array( $post_meta['seo'] ) ? $post_meta['seo'] : array();
		$flat_seo_map = [
			'seo_title'       => 'title',
			'seo_description' => 'description',
			'focus_keyword'   => 'focus_keyword',
		];
		foreach ( $flat_seo_map as $flat_key => $seo_key ) {
			if ( ! empty( $post_meta[ $flat_key ] ) && ! isset( $seo[ $seo_key ] ) ) {
				$seo[ $seo_key ] = sanitize_text_field( $post_meta[ $flat_key ] );
			}
			unset( $post_meta[ $flat_key ] );
		}
		if ( isset( $post_meta['canonical_url'] ) ) {
			if ( '' !== $post_meta['canonical_url'] && ! isset( $seo['canonical'] ) ) {
				$canon_from_meta = GDTG_Import_Orchestrator::normalize_canonical_url( $post_meta['canonical_url'] );
				if ( is_wp_error( $canon_from_meta ) ) {
					return $canon_from_meta;
				}
				$seo['canonical'] = $canon_from_meta;
			}
			unset( $post_meta['canonical_url'] );
		}

		// Top-level request params override post_meta values (backward compatibility).
		$seo_title = $request->get_param( 'seo_title' );
		if ( ! empty( $seo_title ) ) {
			$seo['title'] = sanitize_text_field( $seo_title );
		}
		$seo_desc = $request->get_param( 'seo_description' );
		if ( ! empty( $seo_desc ) ) {
			$seo['description'] = sanitize_text_field( $seo_desc );
		}
		$focus_kw = $request->get_param( 'focus_keyword' );
		if ( ! empty( $focus_kw ) ) {
			$seo['focus_keyword'] = sanitize_text_field( $focus_kw );
		}
		$canonical = $request->get_param( 'canonical_url' );
		if ( ! empty( $canonical ) ) {
			$canon_result = GDTG_Import_Orchestrator::normalize_canonical_url( $canonical );
			if ( is_wp_error( $canon_result ) ) {
				return $canon_result;
			}
			$seo['canonical'] = $canon_result;
		}
		if ( ! empty( $seo ) ) {
			$post_meta['seo'] = $seo;
		}
		if ( $request->has_param( 'categories' ) ) {
			$categories = $request->get_param( 'categories' );
			if ( is_array( $categories ) ) {
				$cat_arr = array_values( array_filter( array_map( 'trim', $categories ), static function( $v ) { return '' !== $v; } ) );
			} else {
				$cat_arr = array_filter( array_map( 'trim', explode( ',', (string) $categories ) ) );
			}
			$post_meta['categories'] = $cat_arr;
		}
		if ( $request->has_param( 'tags' ) ) {
			$tags = $request->get_param( 'tags' );
			if ( is_array( $tags ) ) {
				$tag_arr = array_values( array_filter( array_map( 'trim', $tags ), static function( $v ) { return '' !== $v; } ) );
			} else {
				$tag_arr = array_filter( array_map( 'trim', explode( ',', (string) $tags ) ) );
			}
			$post_meta['tags'] = $tag_arr;
		}
		$featured_image = $request->get_param( 'featured_image' );
		if ( ! empty( $featured_image ) ) {
			$post_meta['featured_image'] = sanitize_text_field( $featured_image );
		}

		if ( ! empty( $post_meta ) ) {
			$options['post_meta'] = $post_meta;
		}

		return $options;
	}

	/**
	 * Extract style overrides from request params.
	 *
	 * Presence-based: only keys explicitly sent by the client are included.
	 * The orchestrator's resolve_overrides() fills any missing keys from
	 * global options, so callers who omit a param get the global default.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return array Override key/value pairs.
	 */
	private function normalize_overrides( $request ) {
		$overrides = array();

		$hd = $request->get_param( 'heading_demotion' );
		if ( null !== $hd ) {
			$overrides['heading_demotion'] = max( 0, min( 5, absint( $hd ) ) );
		}

		$mhl = $request->get_param( 'min_heading_level' );
		if ( null !== $mhl ) {
			$overrides['min_heading_level'] = max( 1, min( 6, absint( $mhl ) ) );
		}

		$align = $request->get_param( 'default_alignment' );
		if ( null !== $align && in_array( $align, array( '', 'left', 'center', 'right' ), true ) ) {
			$overrides['default_alignment'] = $align;
		}

		return $overrides;
	}
	/**
	 * Handle sync status request.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_sync_status( $request ) {
		$post_id = $request->get_param( 'post_id' );
		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Permission denied.', 'draftsync' ) ), 403 );
			}
			$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
			if ( empty( $source_type ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Post is not linked to a source document.', 'draftsync' ) ), 404 );
			}
			$locked      = class_exists( 'GDTG_Sync_Lock' ) && GDTG_Sync_Lock::is_locked( $post_id );
			$last_status = get_post_meta( $post_id, '_gdtg_last_sync_status', true );
			$last_error  = get_post_meta( $post_id, '_gdtg_last_sync_error', true );

			$events = array();
			if ( class_exists( 'GDTG_Sync_Log' ) ) {
				$events = GDTG_Sync_Log::read( $post_id, 5 );
			}

			return new WP_REST_Response( array(
				'post_id'               => $post_id,
				'source_type'           => $source_type,
				'source_id'             => get_post_meta( $post_id, '_gdtg_source_id', true ),
				'source_name'           => get_post_meta( $post_id, '_gdtg_source_name', true ),
				'auto_sync'             => get_post_meta( $post_id, '_gdtg_auto_sync', true ) === '1',
				'last_imported_at'      => get_post_meta( $post_id, '_gdtg_last_imported_at', true ),
				'last_sync_status'      => $last_status,
				'last_sync_checked_at'  => get_post_meta( $post_id, '_gdtg_last_sync_checked_at', true ),
				'last_sync_error'       => $last_error,
				'source_modified_at'    => get_post_meta( $post_id, '_gdtg_source_modified_at', true ),
				'sync_progress'         => max( 0, (int) get_post_meta( $post_id, '_gdtg_sync_progress', true ) ),
				'syncable'              => in_array( $source_type, array( 'gdoc', 'drive_file' ), true ),
				'events'                => $events,
				'health'                => array(
					'status'            => $last_status ?: 'unknown',
					'last_error'        => $last_error ?: '',
					'last_checked_at'   => get_post_meta( $post_id, '_gdtg_last_sync_checked_at', true ) ?: '',
					'last_imported_at'  => get_post_meta( $post_id, '_gdtg_last_imported_at', true ) ?: '',
					'source_modified_at' => get_post_meta( $post_id, '_gdtg_source_modified_at', true ) ?: '',
					'locked'            => $locked,
				),
			), 200 );
		}
		// List all linked posts visible to current user.
		$query = new WP_Query( array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_gdtg_source_type',
					'value'   => '',
					'compare' => '!=',
				),
			),
			'fields' => 'ids',
		) );
		$posts = array();
		foreach ( $query->posts as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			$posts[] = array(
				'post_id'          => $pid,
				'source_type'      => get_post_meta( $pid, '_gdtg_source_type', true ),
				'source_name'      => get_post_meta( $pid, '_gdtg_source_name', true ),
				'auto_sync'        => get_post_meta( $pid, '_gdtg_auto_sync', true ) === '1',
				'last_imported_at' => get_post_meta( $pid, '_gdtg_last_imported_at', true ),
				'last_sync_status' => get_post_meta( $pid, '_gdtg_last_sync_status', true ),
			);
		}
		wp_reset_postdata();
		return new WP_REST_Response( array( 'posts' => $posts, 'total' => count( $posts ) ), 200 );
	}
	/**
	 * Handle per-post sync settings update.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_sync_settings( $request ) {
		$post_id    = absint( $request->get_param( 'post_id' ) );
		$auto_sync  = $request->get_param( 'auto_sync' );
		if ( null !== $auto_sync ) {
			update_post_meta( $post_id, '_gdtg_auto_sync', $auto_sync ? '1' : '0' );
		}
		return new WP_REST_Response( array(
			'post_id'   => $post_id,
			'auto_sync' => get_post_meta( $post_id, '_gdtg_auto_sync', true ) === '1',
		), 200 );
	}
	/**
	 * Handle admin-only manual sync run trigger.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_sync_run( $request ) {
		$limit   = absint( $request->get_param( 'limit' ) );
		$force   = (bool) $request->get_param( 'force' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$scheduler = new GDTG_Sync_Scheduler( $this->loader );
		$summary   = $scheduler->run_scheduled_sync( $limit, $force, $dry_run );
		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * Raise execution limits safely.
	 */
	private function raise_execution_budget() {
		// Parse disable_functions as a comma-separated list.
		$disabled = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
		if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) {
			set_error_handler( function () { return true; } );
			try {
				set_time_limit( 300 );
			} catch ( \Throwable $e ) {
				// Host blocked the call; continue with default limit.
			} finally {
				restore_error_handler();
			}
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
	}

	/**
	 * Handle the import request.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_import( $request ) {
		$doc_id     = $request->get_param( 'doc_id' );
		$post_id    = $request->get_param( 'post_id' );
		$source_ref = $this->parse_source_reference( $doc_id );

		if ( is_wp_error( $source_ref ) ) {
			return new WP_REST_Response(
				array( 'message' => $source_ref->get_error_message() ),
				400
			);
		}

		$this->raise_execution_budget();

		$options = $this->normalize_import_options( $request );
		if ( is_wp_error( $options ) ) {
			return new WP_REST_Response(
				array( 'message' => $options->get_error_message() ),
				400
			);
		}
		$target_post_id = ! empty( $post_id ) ? absint( $post_id ) : 0;
		// Acquire lock if updating an existing post.
		if ( $target_post_id > 0 ) {
			if ( ! GDTG_Sync_Lock::acquire( $target_post_id ) ) {
				return new WP_REST_Response(
					array(
						'message' => sprintf(
							/* translators: %d: post ID */
							__( 'Sync already in progress for post #%d', 'draftsync' ),
							$target_post_id
						),
						'batch'  => false,
						'locked' => true,
					),
					423
				);
			}
			GDTG_Sync_Lock::heartbeat( $target_post_id, 300 );
		}
		$orchestrator = new GDTG_Import_Orchestrator();

		try {
			if ( 'drive_file' === $source_ref['type'] ) {
				$result = $orchestrator->import_drive_file( $source_ref['id'], $options, $target_post_id );
			} else {
				$result = $orchestrator->import_google_doc( $source_ref['id'], $options, $target_post_id );
			}
		} finally {
			if ( $target_post_id > 0 ) {
				GDTG_Sync_Lock::release( $target_post_id );
			}
		}

		return $this->orchestrator_result_to_response( $result );
	}


	/**
	 * Handle .docx file upload and import.
	 *
	 * Accepts a multipart file upload, validates via GDTG_Zip_Validator,
	 * parses with GDTG_Docx_Parser, and commits through the same
	 * render_nodes()/commit_import() flow as Google Docs imports.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_upload_docx( $request ) {
		// Validate file upload.
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'No file uploaded.', 'draftsync' ) ],
				400
			);
		}

		$uploaded = $files['file'];

		// Check for upload errors.
		if ( $uploaded['error'] !== UPLOAD_ERR_OK ) {
			return new WP_REST_Response(
				[ 'message' => __( 'File upload failed.', 'draftsync' ) ],
				400
			);
		}

		// Extension check.
		$ext = strtolower( pathinfo( $uploaded['name'], PATHINFO_EXTENSION ) );
		if ( 'docx' !== $ext ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Only .docx files are supported.', 'draftsync' ) ],
				400
			);
		}

		// MIME type check (WordPress sets this from the upload; also verify manually).
		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		if ( $finfo ) {
			$detected_mime = finfo_file( $finfo, $uploaded['tmp_name'] );
			finfo_close( $finfo );
			$allowed_mimes = array(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/zip',
				'application/octet-stream', // Some browsers send generic MIME.
			);
			if ( ! in_array( $detected_mime, $allowed_mimes, true ) ) {
				wp_delete_file( $uploaded['tmp_name'] );
				return new WP_REST_Response(
					[ 'message' => __( 'Invalid file type. Only .docx files are accepted.', 'draftsync' ) ],
					400
				);
			}
		}

		// Validate ZIP structure.
		$validation = GDTG_Zip_Validator::validate( $uploaded['tmp_name'] );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $uploaded['tmp_name'] );
			return new WP_REST_Response(
				[ 'message' => $validation->get_error_message() ],
				400
			);
		}

		$this->raise_execution_budget();

		$post_id = $request->get_param( 'post_id' );
		$post_id = ! empty( $post_id ) ? absint( $post_id ) : 0;

		$options = $this->normalize_import_options( $request );
		if ( is_wp_error( $options ) ) {
			wp_delete_file( $uploaded['tmp_name'] );
			return new WP_REST_Response(
				array( 'message' => $options->get_error_message() ),
				400
			);
		}
		$options['import_images'] = true; // Docx images are always imported.
		// Acquire lock if updating an existing post.
		if ( $post_id > 0 && ! GDTG_Sync_Lock::acquire( $post_id ) ) {
			wp_delete_file( $uploaded['tmp_name'] );
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %d: post ID */
						__( 'Sync already in progress for post #%d', 'draftsync' ),
						$post_id
					),
					'batch'  => false,
					'locked' => true,
				),
				423
			);
		}

		$orchestrator = new GDTG_Import_Orchestrator();

		try {
			$result = $orchestrator->import_docx_file( $uploaded['tmp_name'], $uploaded['name'], $options, $post_id );
		} finally {
			// Clean up temp file.
			wp_delete_file( $uploaded['tmp_name'] );

			if ( $post_id > 0 ) {
				GDTG_Sync_Lock::release( $post_id );
			}
		}

		return $this->orchestrator_result_to_response( $result );
	}


	/**
	 * Handle import of a Google Drive file (thin wrapper around orchestrator).
	 *
	 * @param string          $file_id Drive file ID.
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	private function handle_drive_file_import( $file_id, $request ) {
		$this->raise_execution_budget();

		$options = $this->normalize_import_options( $request );
		if ( is_wp_error( $options ) ) {
			return new WP_REST_Response(
				array( 'message' => $options->get_error_message() ),
				400
			);
		}
		$post_id        = $request->get_param( 'post_id' );
		$target_post_id = ! empty( $post_id ) ? absint( $post_id ) : 0;

		if ( $target_post_id > 0 && ! GDTG_Sync_Lock::acquire( $target_post_id ) ) {
			return new WP_REST_Response(
				array(
					/* translators: %d: post ID */
					'message' => sprintf( __( 'Sync already in progress for post #%d', 'draftsync' ), $target_post_id ),
					'batch'   => false,
					'locked'  => true,
				),
				423
			);
		}

		$orchestrator = new GDTG_Import_Orchestrator();

		try {
			if ( $target_post_id > 0 ) {
				GDTG_Sync_Lock::heartbeat( $target_post_id, 300 );
			}
			$result = $orchestrator->import_drive_file( $file_id, $options, $target_post_id );
		} finally {
			if ( $target_post_id > 0 ) {
				GDTG_Sync_Lock::release( $target_post_id );
			}
		}

		return $this->orchestrator_result_to_response( $result );
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
		$overwrite = $options['overwrite'];
		if ( $overwrite ) {
			$post_data = [
				'ID'           => $post_id,
				'post_content' => $rendered_html,
			];
		} else {
			$current_post = get_post( $post_id );
			$existing     = $current_post ? $current_post->post_content : '';
			$post_data    = [
				'ID'           => $post_id,
				'post_content' => $existing . "\n\n" . $rendered_html,
			];
		}

		$current_post = get_post( $post_id );
		if ( $current_post && ( empty( $current_post->post_title ) || __( 'Auto Draft', 'draftsync' ) === $current_post->post_title ) ) {
			$post_data['post_title'] = $doc_title;
		}

		if ( ! empty( $options['post_meta'] ) ) {
			$applier = new GDTG_Post_Meta_Applier();
			$extra_post_data = $applier->build_post_data( $options['post_meta'] );
			$post_data = array_merge( $post_data, $extra_post_data );
		}

		// Promote shell posts to the desired final status at commit time.
		if ( $current_post && 'draft' === $current_post->post_status && ! $options['import_as_draft'] && current_user_can( 'publish_posts' ) ) {
			$post_data['post_status'] = 'publish';
		}

		$result = wp_update_post( $post_data, true );
		return is_wp_error( $result ) ? $result : $post_id;
	}

	/**
	 * Parse a URL or raw ID into a source reference.
	 *
	 * Returns an associative array with:
	 *   'type' => 'gdoc' | 'drive_file'
	 *   'id'   => validated string ID
	 *
	 * Sheets, Slides, and other unsupported formats return WP_Error.
	 *
	 * @param string $input The URL or raw document/file ID.
	 * @return array|WP_Error Source reference or WP_Error for unsupported input.
	 */
	private function parse_source_reference( $input ) {
		$input = trim( $input );

		// Unsupported Google service URLs.
		if ( preg_match( '#^https?://docs\.google\.com/spreadsheets/#', $input )
			|| preg_match( '#^https?://docs\.google\.com/presentation/#', $input )
		) {
			return new WP_Error(
				'gdtg_unsupported_format',
				__( 'Only native Google Docs and Drive .docx files are supported. Sheets and Slides are not supported.', 'draftsync' )
			);
		}

		// Native Google Docs URL.
		if ( preg_match( '#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
			$id = $matches[1];
			if ( '' !== $id && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
				return array( 'type' => 'gdoc', 'id' => $id );
			}
			return new WP_Error( 'gdtg_invalid_id', __( 'Invalid Google Doc ID.', 'draftsync' ) );
		}

		// Drive file URL patterns.
		// https://drive.google.com/file/d/{id}/view
		if ( preg_match( '#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
			$id = $matches[1];
			if ( '' !== $id && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
				return array( 'type' => 'drive_file', 'id' => $id );
			}
		}
		// https://drive.google.com/open?id={id}
		if ( preg_match( '#drive\.google\.com/open\?#', $input ) ) {
			$parsed = wp_parse_url( $input );
			if ( isset( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $params );
				if ( ! empty( $params['id'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $params['id'] ) ) {
					return array( 'type' => 'drive_file', 'id' => $params['id'] );
				}
			}
		}
		// https://drive.google.com/uc?id={id}
		if ( preg_match( '#drive\.google\.com/uc\?#', $input ) ) {
			$parsed = wp_parse_url( $input );
			if ( isset( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $params );
				if ( ! empty( $params['id'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $params['id'] ) ) {
					return array( 'type' => 'drive_file', 'id' => $params['id'] );
				}
			}
		}

		// Raw Google Doc ID (no URL structure).
		if ( 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $input ) ) {
			return array( 'type' => 'gdoc', 'id' => $input );
		}

		return new WP_Error( 'gdtg_invalid_input', __( 'Invalid Google Doc ID or URL.', 'draftsync' ) );
	}

	// ─── Batch Import Job Methods ───────────────────────────────────

	/**
	 * Create an import job transient for batch image processing.
	 *
	 * @param string          $doc_json   Raw Google Doc JSON.
	 * @param int             $post_id    Target post ID.
	 * @param array           $options    Normalized import options.
	 * @param GDTG_Doc_Node[] $nodes      Parsed AST nodes (image placeholders).
	 * @param string          $doc_title  Document title.
	 * @param int             $user_id       Current user ID.
	 * @param bool            $created_shell Whether the job owns a new shell post.
	 * @return string Job ID.
	 */
	private function create_import_job( $doc_json, $post_id, $options, $nodes, $doc_title, $user_id, $created_shell = false ) {
		$job_id  = bin2hex( random_bytes( 16 ) );
		$job_key = 'gdtg_import_job_' . $job_id;

		$job_data = [
			'job_id'       => $job_id,
			'created_shell' => (bool) $created_shell,
			'doc_json'     => $doc_json,
			'post_id'      => $post_id,
			'options'      => $options,
			'nodes'        => $this->serialize_nodes( $nodes ),
			'doc_title'    => $doc_title,
			'user_id'      => $user_id,
			'image_total'  => $this->count_images_in_nodes( $nodes ),
			'image_done'   => 0,
			'image_failed' => 0,
			'status'       => 'pending',
			'created_at'   => time(),
		];

		set_transient( $job_key, $job_data, HOUR_IN_SECONDS );

		return $job_id;
	}

	/**
	 * Retrieve a stored import job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null if expired/missing.
	 */
	private function get_import_job( $job_id ) {
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
	private function update_import_job( $job_id, $job_data ) {
		$job_key = 'gdtg_import_job_' . $job_id;
		set_transient( $job_key, $job_data, HOUR_IN_SECONDS );
	}

	/**
	 * Serialize AST nodes to a JSON-safe array for transient storage.
	 *
	 * @param GDTG_Doc_Node[] $nodes AST nodes.
	 * @return array JSON-serializable array.
	 */
	private function serialize_nodes( $nodes ) {
		$result = [];
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			$result[] = [
				'type'     => $node->type,
				'content'  => $node->content,
				'attrs'    => $node->attrs,
				'children' => $this->serialize_nodes( $node->children ),
			];
		}
		return $result;
	}

	/**
	 * Deserialize stored node arrays back to GDTG_Doc_Node objects.
	 *
	 * @param array $data Serialized node arrays.
	 * @return GDTG_Doc_Node[] AST nodes.
	 */
	private function deserialize_nodes( $data ) {
		$nodes = [];
		foreach ( $data as $item ) {
			$children = isset( $item['children'] ) ? $this->deserialize_nodes( $item['children'] ) : [];
			$nodes[]  = new GDTG_Doc_Node(
				$item['type'] ?? 'paragraph',
				$item['content'] ?? '',
				$item['attrs'] ?? [],
				$children
			);
		}
		return $nodes;
	}

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
	 * @param GDTG_Doc_Node[] $nodes     AST nodes (mutated in-place).
	 * @param array           &$collector Flat array of image info with node refs.
	 */
	private function collect_image_placeholders( $nodes, &$collector ) {
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			if ( 'image' === $node->type && ! empty( $node->attrs['source_url'] ) ) {
				$collector[] = [
					'node'        => $node,
					'source_url'  => $node->attrs['source_url'],
					'alt'         => $node->attrs['alt'] ?? '',
				];
			}
			if ( ! empty( $node->children ) ) {
				$this->collect_image_placeholders( $node->children, $collector );
			}
		}
	}

	/**
	 * Process image placeholder nodes in-place: sideload and fill in id/url.
	 *
	 * @param GDTG_Doc_Node[] $nodes    AST nodes (mutated).
	 * @param int             $post_id  Post ID to attach images to.
	 * @param int             $limit    Max number of images to process (0 = all).
	 * @return array{processed: int, failed: int} Counts of processed and failed images.
	 */
	private function process_image_placeholders( $nodes, $post_id, $limit = 0, $options = [] ) {
		$placeholders = [];
		$this->collect_image_placeholders( $nodes, $placeholders );

		$processed = 0;
		$failed    = 0;

		// Process from the start of the remaining list, up to $limit.
		$batch = $limit > 0 ? array_slice( $placeholders, 0, $limit ) : $placeholders;

		foreach ( $batch as $img_info ) {
			$node = $img_info['node'];
			$sideload_options = [];
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
				// Mark as failed so we don't retry forever.
				$node->attrs['source_url'] = '';
				$node->attrs['alt']        = $img_info['alt'] . ' [import failed]';
				$failed++;
			}
		}

		return [ 'processed' => $processed, 'failed' => $failed ];
	}

	/**
	 * Permission callback for job endpoints — validates job exists and belongs to current user.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_job_permissions( $request ) {
		$job_id      = $request->get_param( 'job_id' );
		$orchestrator = new GDTG_Import_Orchestrator();
		$job_data    = $orchestrator->get_import_job( $job_id );

		if ( null === $job_data ) {
			return new WP_Error(
				'gdtg_job_not_found',
				__( 'Import job not found or expired.', 'draftsync' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $job_data['user_id'] !== get_current_user_id() ) {
			return new WP_Error(
				'gdtg_forbidden',
				__( 'You do not have permission to access this import job.', 'draftsync' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}


	/**
	 * Handle job status request — returns progress metadata.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_job_status( $request ) {
		$job_id      = $request->get_param( 'job_id' );
		$orchestrator = new GDTG_Import_Orchestrator();
		$job_data    = $orchestrator->get_import_job( $job_id );

		if ( null === $job_data ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Import job not found or expired.', 'draftsync' ) ),
				404
			);
		}

		$response_data = array(
			'job_id'      => $job_data['job_id'],
			'status'      => $job_data['status'],
			'image_done'  => $job_data['image_done'],
			'image_total' => $job_data['image_total'],
			'post_id'     => $job_data['post_id'],
			'edit_url'    => $job_data['post_id'] ? get_edit_post_link( $job_data['post_id'], 'raw' ) : null,
			'message'     => isset( $job_data['message'] ) ? $job_data['message'] : '',
		);
		if ( ! empty( $job_data['meta_warnings'] ) ) {
			$response_data['warnings'] = $job_data['meta_warnings'];
		}
		return new WP_REST_Response( $response_data, 200 );
	}


	/**
	 * Handle job continue request — processes the next batch of images.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_job_continue( $request ) {
		$job_id      = $request->get_param( 'job_id' );
		$orchestrator = new GDTG_Import_Orchestrator();
		$job_data    = $orchestrator->get_import_job( $job_id );

		if ( null === $job_data ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Import job not found or expired.', 'draftsync' ) ),
				404
			);
		}

		// Normalize counters for job transients created before image_failed existed.
		$job_data['image_done']   = isset( $job_data['image_done'] ) ? (int) $job_data['image_done'] : 0;
		$job_data['image_failed'] = isset( $job_data['image_failed'] ) ? (int) $job_data['image_failed'] : 0;
		$job_data['image_total']  = isset( $job_data['image_total'] ) ? (int) $job_data['image_total'] : 0;

		// This path can sideload multiple images; reuse the shared budget helper.
		// It safely attempts to raise both time and memory limits without corrupting JSON output.
		$this->raise_execution_budget();

		try {
			$nodes = $orchestrator->deserialize_nodes( $job_data['nodes'] );
		} catch ( \Exception $e ) {
			$job_data['status']  = 'error';
			$job_data['message'] = __( 'Failed to restore import job state.', 'draftsync' );
			$orchestrator->update_import_job( $job_id, $job_data );
			return new WP_REST_Response(
				array( 'message' => $job_data['message'] ),
				500
			);
		}

		$batch_size     = 3;
		$post_id_target = $job_data['post_id'];
		$image_total    = $job_data['image_total'];

		// Process the first batch_size remaining placeholders.
		$result     = $orchestrator->process_image_placeholders( $nodes, $post_id_target, $batch_size, $job_data['options'] );
		$processed  = $result['processed'];
		$failed     = $result['failed'];

		$job_data['image_done']   += $processed + $failed;
		$job_data['image_failed'] += $failed;
		$job_data['image_done']    = min( $job_data['image_done'], $image_total );

		// Check if all images are resolved (no more source_url placeholders).
		$remaining = array();
		$orchestrator->collect_image_placeholders( $nodes, $remaining );
		$all_done = empty( $remaining );

		if ( $all_done ) {
			// Check if all images failed — if so, do not commit image-stripped content.
			$total_failed = isset( $job_data['image_failed'] ) ? $job_data['image_failed'] : 0;
			if ( $total_failed > 0 && $total_failed >= $image_total ) {
				// All images failed — abort the import and clean up only shell posts owned by this job.
				if ( ! empty( $job_data['created_shell'] ) ) {
					wp_delete_post( $post_id_target, true );
				}
				$job_data['status'] = 'error';
				if ( ! empty( $job_data['created_shell'] ) ) {
					$job_data['message'] = sprintf(
						/* translators: %d: total image count */
						__( 'Import failed: all %d image(s) could not be imported. The draft post has been removed.', 'draftsync' ),
						$image_total
					);
				} else {
					$job_data['message'] = sprintf(
						/* translators: %d: total image count */
						__( 'Import failed: all %d image(s) could not be imported. Existing content was left unchanged.', 'draftsync' ),
						$image_total
					);
				}
				$job_data['nodes'] = $orchestrator->serialize_nodes( $nodes );
				$orchestrator->update_import_job( $job_id, $job_data );
				return new WP_REST_Response(
					array(
						'job_id'  => $job_id,
						'status'  => 'error',
						'message' => $job_data['message'],
					),
					500
				);
			}

			// At least some images succeeded — render and commit.
			$rendered_html = $orchestrator->render_nodes( $nodes, $job_data['options'] );
			$final_post_id = $this->write_to_existing_post( $post_id_target, $rendered_html, $job_data['options'], $job_data['doc_title'] );

			if ( is_wp_error( $final_post_id ) ) {
				if ( ! empty( $job_data['created_shell'] ) ) {
					wp_delete_post( $post_id_target, true );
				}
				$job_data['status']  = 'error';
				$job_data['message'] = $final_post_id->get_error_message();
				$job_data['nodes']   = $orchestrator->serialize_nodes( $nodes );
				$orchestrator->update_import_job( $job_id, $job_data );
				return new WP_REST_Response( array( 'message' => $final_post_id->get_error_message() ), 500 );
			}

			// Finalize: apply metadata and record sync info.
			$source = isset( $job_data['source'] ) ? $job_data['source'] : array(
				'type' => 'gdoc',
				'id'   => '',
				'name' => $job_data['doc_title'],
			);
			$finalize_warnings = $orchestrator->finalize_import( $final_post_id, $nodes, $job_data['options'], $source );

			$job_data['status']   = 'complete';
			$job_data['post_id']  = $final_post_id;
			$job_data['nodes']    = $orchestrator->serialize_nodes( $nodes );
			$warning              = $total_failed > 0
				? sprintf(
					/* translators: %d: number of failed images */
					__( ' Import complete with warnings: %d image(s) failed to import.', 'draftsync' ),
					$total_failed
				)
				: __( ' Import complete. All images processed successfully.', 'draftsync' );
			$job_data['message']  = trim( $warning );
			$job_data['edit_url'] = get_edit_post_link( $final_post_id, 'raw' );
			if ( ! empty( $finalize_warnings ) ) {
				$job_data['meta_warnings'] = $finalize_warnings;
			}
			$orchestrator->update_import_job( $job_id, $job_data );

			$complete_response = array(
				'job_id'      => $job_id,
				'status'      => 'complete',
				'image_done'  => $job_data['image_done'],
				'image_total' => $image_total,
				'post_id'     => $final_post_id,
				'edit_url'    => $job_data['edit_url'],
				'message'     => $job_data['message'],
			);
			if ( ! empty( $job_data['meta_warnings'] ) ) {
				$complete_response['warnings'] = $job_data['meta_warnings'];
			}
			return new WP_REST_Response( $complete_response, 200 );
		}

		// More images remain — persist updated nodes and return progress.
		$job_data['status']  = 'processing';
		$job_data['nodes']   = $orchestrator->serialize_nodes( $nodes );
		$job_data['message'] = sprintf(
			/* translators: 1: done count, 2: total count */
			__( 'Processing images: %1$d of %2$d.', 'draftsync' ),
			$job_data['image_done'],
			$image_total
		);
		$orchestrator->update_import_job( $job_id, $job_data );

		return new WP_REST_Response(
			array(
				'job_id'      => $job_id,
				'status'      => 'processing',
				'image_done'  => $job_data['image_done'],
				'image_total' => $image_total,
				'message'     => $job_data['message'],
			),
			200
		);
	}

	/**
	 * List Imported Docs manager rows — groups remote sources by source id, shows local uploads.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_imported_docs_list( $request ) {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => 100,
			'meta_query'     => array(
				array(
					'key'     => '_gdtg_source_type',
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'key'     => '_gdtg_last_imported_at',
					'compare' => 'EXISTS',
				),
			),
			'post_status'    => 'any',
		) );

		$grouped = array();
		$local   = array();

		foreach ( $posts as $post ) {
			$pid          = $post->ID;
			$source_type  = get_post_meta( $pid, '_gdtg_source_type', true );
			$source_id    = get_post_meta( $pid, '_gdtg_source_id', true );
			$source_name  = get_post_meta( $pid, '_gdtg_source_name', true );
			$last_import  = get_post_meta( $pid, '_gdtg_last_imported_at', true );
			$resyncable   = in_array( $source_type, array( 'gdoc', 'drive_file' ), true ) && ! empty( $source_id );

			$entry = array(
				'post_id'          => $pid,
				'post_title'       => $post->post_title,
				'edit_url'         => get_edit_post_link( $pid, 'raw' ),
				'last_imported_at' => $last_import,
				'resyncable'       => $resyncable,
				'last_sync_status' => get_post_meta( $pid, '_gdtg_last_sync_status', true ) ?: 'unknown',
			);

			if ( $resyncable ) {
				$key = $source_type . '|' . $source_id;
				if ( ! isset( $grouped[ $key ] ) ) {
					$grouped[ $key ] = array(
						'source_type'        => $source_type,
						'source_id'          => $source_id,
						'source_name'        => $source_name ? $source_name : '',
						'latest_imported_at' => $last_import,
						'posts'              => array(),
					);
				}
				$grouped[ $key ]['posts'][] = $entry;
				if ( $last_import > $grouped[ $key ]['latest_imported_at'] ) {
					$grouped[ $key ]['latest_imported_at'] = $last_import;
				}
			} else {
				$local[] = $entry;
			}
		}

		return new WP_REST_Response(
			array(
				'sources'       => array_values( $grouped ),
				'local_uploads' => array_values( $local ),
			),
			200
		);
	}


	/**
	 * Permission callback for sync events endpoints — requires edit_post for the post.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_events_permissions( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( 0 === $post_id ) {
			return new WP_Error(
				'gdtg_invalid_post',
				__( 'Invalid post ID.', 'draftsync' ),
				array( 'status' => 400 )
			);
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'gdtg_post_not_found',
				__( 'The specified post was not found.', 'draftsync' ),
				array( 'status' => 404 )
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'gdtg_forbidden',
				__( 'You do not have permission to edit this post.', 'draftsync' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Handle GET /gdtg/v1/sync/{post_id}/events — read recent sync events.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_sync_events( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! class_exists( 'GDTG_Sync_Log' ) ) {
			return new WP_REST_Response(
				array( 'post_id' => $post_id, 'events' => array() ),
				200
			);
		}

		$events = GDTG_Sync_Log::read( $post_id, 20 );
		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'events'  => $events,
			),
			200
		);
	}

	/**
	 * Handle POST /gdtg/v1/sync/{post_id}/events/clear — clear all sync events.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_sync_events_clear( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( class_exists( 'GDTG_Sync_Log' ) ) {
			GDTG_Sync_Log::clear( $post_id );
		}

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'cleared' => true,
			),
			200
		);
	}
	/**
	 * Handle POST /gdtg/v1/sync/{post_id}/queue — queue a single-post sync.
	 *
	 * Schedules a one-shot WP Cron event instead of running synchronously.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_sync_queue( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
		if ( empty( $source_type ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Post is not linked to any source document.', 'draftsync' ) ),
				400
			);
		}

		// Check lock before queuing.
		if ( GDTG_Sync_Lock::is_locked( $post_id ) ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %d: post ID */
						__( 'Sync already in progress for post #%d', 'draftsync' ),
						$post_id
					),
					'batch'  => false,
					'locked' => true,
				),
				423
			);
		}

		// Prevent duplicate scheduling: check for existing queued event.
		$user_id   = get_current_user_id();
		$next_sync = wp_next_scheduled( 'gdtg_run_queued_sync', array( $post_id, $user_id ) );
		if ( false !== $next_sync ) {
			return new WP_REST_Response(
				array(
					'queued'   => true,
					'post_id'  => $post_id,
					'existing' => true,
					'scheduled_at' => gmdate( 'c', $next_sync ),
				),
				200
			);
		}

		wp_schedule_single_event(
			time() + 5,
			'gdtg_run_queued_sync',
			array( $post_id, $user_id )
		);

		return new WP_REST_Response(
			array(
				'queued'  => true,
				'post_id' => $post_id,
			),
			200
		);
	}

	/**
	 * WP Cron action handler for queued single-post sync.
	 *
	 * Loads the post, acquires the lock, calls into the orchestrator,
	 * and releases the lock.
	 *
	 * @param int $post_id The post ID to sync.
	 * @param int $user_id The user ID that queued the sync.
	 * @return void
	 */
	public function run_queued_sync( $post_id, $user_id = 0 ) {
		$post_id = absint( $post_id );
		if ( 0 === $post_id ) {
			return;
		}

		$source_type = get_post_meta( $post_id, '_gdtg_source_type', true );
		$source_id   = get_post_meta( $post_id, '_gdtg_source_id', true );

		if ( empty( $source_type ) || empty( $source_id ) ) {
			return;
		}

		if ( ! in_array( $source_type, array( 'gdoc', 'drive_file' ), true ) ) {
			return;
		}

		try {
			if ( ! GDTG_Sync_Lock::acquire( $post_id ) ) {
				return;
			}

			// Set the user context for the sync.
			if ( $user_id > 0 ) {
				wp_set_current_user( $user_id );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
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
					'import_as_draft' => 'draft' === $post->post_status,
					'output_mode'     => strpos( $post->post_content, '<!-- wp:' ) !== false ? 'gutenberg' : 'classic',
					'optimize_images' => (bool) get_option( 'gdtg_optimize_images', '1' ),
					'overrides'       => array(),
				)
			);

			$orchestrator = new GDTG_Import_Orchestrator();

			GDTG_Sync_Lock::heartbeat( $post_id, 300 );
			if ( 'gdoc' === $source_type ) {
				$result = $orchestrator->import_google_doc( $source_id, $options, $post_id );
			} else {
				$result = $orchestrator->import_drive_file( $source_id, $options, $post_id );
			}

			if ( is_wp_error( $result ) ) {
				update_post_meta( $post_id, '_gdtg_last_sync_status', 'error' );
				update_post_meta( $post_id, '_gdtg_last_sync_error', sanitize_text_field( $result->get_error_message() ) );
				update_post_meta( $post_id, '_gdtg_last_sync_checked_at', current_time( 'mysql' ) );
			}
		} finally {
			GDTG_Sync_Lock::release( $post_id );
		}
	}

	/**
	 * Handle GET /gdtg/v1/picker/config — return non-sensitive Picker configuration.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_picker_config() {
	$client_id = get_option( 'gdtg_enterprise_client_id', '' );

		if ( empty( $client_id ) ) {
			return new WP_REST_Response(
				array(
					'enabled' => false,
					'reason'  => 'missing_keys',
				),
				200
			);
		}

		$app_id        = sanitize_text_field( get_option( 'gdtg_picker_app_id', '' ) );
		$developer_key = sanitize_text_field( get_option( 'gdtg_picker_developer_key', '' ) );

		if ( empty( $app_id ) || empty( $developer_key ) ) {
			return new WP_REST_Response(
				array(
					'enabled' => false,
					'reason'  => 'missing_keys',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'enabled'       => true,
				'app_id'        => $app_id,
				'scopes'        => array(
					'https://www.googleapis.com/auth/documents.readonly',
					'https://www.googleapis.com/auth/drive.readonly',
				),
				'developer_key' => $developer_key,
			),
			200
		);
	}

	/**
	 * Handle GET /gdtg/v1/auth/token?purpose=picker — return a picker-scoped access token.
	 *
	 * Throttled to 5 requests per minute per user via transient.
	 * Never returns the refresh token.
	 *
	 * @param WP_REST_Request $request The current request.
	 * @return WP_REST_Response
	 */
	public function handle_picker_token( $request ) {
		// Throttle: 5 requests per minute per user.
		$user_id       = get_current_user_id();
		$throttle_key  = 'gdtg_picker_token_throttle_' . $user_id;
		$throttle_val  = get_transient( $throttle_key );

		if ( false !== $throttle_val && (int) $throttle_val >= 5 ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Too many token requests. Please wait a moment.', 'draftsync' ) ),
				429
			);
		}

		$new_count = false === $throttle_val ? 1 : (int) $throttle_val + 1;
		set_transient( $throttle_key, $new_count, MINUTE_IN_SECONDS );

		$api     = new GDTG_API();
		$token   = $api->get_access_token();

		if ( empty( $token ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Not connected to Google. Please reconnect your Enterprise account.', 'draftsync' ) ),
				401
			);
		}

		return new WP_REST_Response(
			array( 'token' => $token ),
			200
		);
	}
}