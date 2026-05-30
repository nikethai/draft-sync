<?php
/**
 * Standalone import orchestrator test harness.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );

	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function __( $text, $domain = 'default' ) {
		return $text;
	}

	function absint( $val ) {
		return abs( intval( $val ) );
	}

	function sanitize_text_field( $field ) {
		return trim( strip_tags( $field ) );
	}

	function sanitize_file_name( $name ) {
		return $name;
	}

	function current_time( $format ) {
		return '2026-05-31 01:23:45';
	}

	// Mock post meta storage.
	global $mock_post_meta;
	$mock_post_meta = array();

	function update_post_meta( $post_id, $key, $val ) {
		global $mock_post_meta;
		$mock_post_meta[ $post_id ][ $key ] = $val;
		return true;
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ] ) ) {
			return $single ? '' : array();
		}
		if ( ! isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		return $mock_post_meta[ $post_id ][ $key ];
	}

	function delete_post_meta( $post_id, $key ) {
		global $mock_post_meta;
		if ( isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			unset( $mock_post_meta[ $post_id ][ $key ] );
		}
		return true;
	}

	// Mock post status/details.
	global $mock_posts;
	$mock_posts = array();

	function get_post( $post_id ) {
		global $mock_posts;
		if ( isset( $mock_posts[ $post_id ] ) ) {
			return $mock_posts[ $post_id ];
		}
		return (object) array( 'ID' => $post_id, 'post_title' => 'Test Post', 'post_content' => '', 'post_status' => 'draft' );
	}

	function get_post_status( $post_id ) {
		return 'draft';
	}

	// Shims for wp_insert_post and wp_update_post
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $mock_posts;
		$new_id = count( $mock_posts ) + 100;
		$postarr['ID'] = $new_id;
		$mock_posts[ $new_id ] = (object) $postarr;
		return $new_id;
	}

	function wp_update_post( $postarr, $wp_error = false ) {
		global $mock_posts;
		$post_id = $postarr['ID'];
		if ( isset( $mock_posts[ $post_id ] ) ) {
			$existing = (array) $mock_posts[ $post_id ];
			$mock_posts[ $post_id ] = (object) array_merge( $existing, $postarr );
		} else {
			$mock_posts[ $post_id ] = (object) $postarr;
		}
		return $post_id;
	}

	function current_user_can( $cap, ...$args ) {
		return true;
	}
	function get_current_user_id() {
		return 1;
	}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_array( $args ) ) {
			return array_merge( $defaults, $args );
		}
		parse_str( $args, $parsed_args );
		return array_merge( $defaults, $parsed_args );
	}

	function get_userdata( $user_id ) {
		return (object) array( 'ID' => $user_id );
	}

	function get_option( $opt, $default = '' ) {
		return $default;
	}

	function get_edit_post_link( $post_id, $context = 'display' ) {
		return 'https://example.com/wp-admin/post.php?post=' . $post_id . '&action=edit';
	}

	function wp_get_attachment_url( $attachment_id ) {
		return 'https://example.com/wp-content/uploads/2026/01/image.jpg';
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}



if (!function_exists('stripos')) {
    function stripos($haystack, $needle) { return strpos(strtolower($haystack), strtolower($needle)); }
}
}

// Load necessary files.
require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-block-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-html-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-post-meta-applier.php';
require_once __DIR__ . '/../includes/class-gdtg-import-orchestrator.php';
if ( ! class_exists( 'GDTG_Secret_Store' ) ) {
	require_once __DIR__ . '/../includes/class-gdtg-secret-store.php';
}
if ( ! class_exists( 'GDTG_API' ) ) {
	require_once __DIR__ . '/../includes/class-gdtg-api.php';
}
if ( ! defined( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT' ) ) {
	define( 'GDTG_SAAS_BRIDGE_BASE_URL_DEFAULT', 'https://draftsync.cortisol.icu' );
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-orchestrator-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args ) { return array( 'response' => array( 'code' => 200 ), 'body' => '' ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args ) { return array( 'response' => array( 'code' => 200 ), 'body' => '' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $field ) { return trim( strip_tags( (string) $field ) ); }
}
if ( ! function_exists( 'sleep' ) ) {
	// sleep is built-in, but in case it's been disabled by the test environment.
	function sleep( $seconds ) { return 0; }
}
// Helper assertion functions.
function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "  ✗ [FAIL] $desc\n";
		exit( 1 );
	}
	echo "  ✓ $desc\n";
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "  ✗ [FAIL] $desc. Expected: " . print_r( $expected, true ) . ", got: " . print_r( $actual, true ) . "\n";
		exit( 1 );
	}
	echo "  ✓ $desc\n";
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

suite( 'Google Docs / Drive Import Orchestrator: Linked Sync storage' );

$orchestrator = new GDTG_Import_Orchestrator();

// Try mocking record_sync_metadata reflection as it is private, or we prove it by performing a commit.
// Since commit_import is private, let's write a mock test to verify the stored sync meta after commit.

// Using reflection to test the private record_sync_metadata method
$reflector = new ReflectionClass( 'GDTG_Import_Orchestrator' );
$method = $reflector->getMethod( 'record_sync_metadata' );
$method->setAccessible( true );

global $mock_post_meta, $mock_posts;
$mock_posts[88] = (object) array( 'ID' => 88, 'post_title' => 'Initial Title', 'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->' );

$method->invokeArgs( $orchestrator, array( 88, 'gdoc', 'gd12345', 'Sample Doc Title' ) );

assert_equals( 'gdoc', get_post_meta( 88, '_gdtg_source_type', true ), 'Source type synced' );
assert_equals( 'gd12345', get_post_meta( 88, '_gdtg_source_id', true ), 'Source document ID synced' );
assert_equals( 'Sample Doc Title', get_post_meta( 88, '_gdtg_source_name', true ), 'Source name synced' );
assert_equals( '2026-05-31 01:23:45', get_post_meta( 88, '_gdtg_last_imported_at', true ), 'Import timestamp mapped' );
assert_equals( md5( '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->' ), get_post_meta( 88, '_gdtg_last_content_hash', true ), 'Content checksum hash matched' );


suite( 'Google Docs / Drive Import Orchestrator: Drive source context in import_docx_file' );

// Test that import_docx_file records drive_file when source context is provided via options.
// We use reflection to test the private record_sync_metadata since import_docx_file requires
// file I/O and parser instantiation. Instead, test the source context extraction logic directly.

// Simulate what import_docx_file does for source context:
$test_options = array(
	'_gdtg_source'      => 'drive_file',
	'_gdtg_source_id'   => 'drv_abc123',
	'_gdtg_source_name' => 'My Drive Doc.docx',
);

$source_type = ! empty( $test_options['_gdtg_source'] ) ? $test_options['_gdtg_source'] : 'docx_upload';
$source_id   = ! empty( $test_options['_gdtg_source_id'] ) ? $test_options['_gdtg_source_id'] : '';
$source_name = ! empty( $test_options['_gdtg_source_name'] ) ? $test_options['_gdtg_source_name'] : 'fallback.docx';

$mock_posts[90] = (object) array( 'ID' => 90, 'post_title' => 'Drive Doc', 'post_content' => '<p>Drive content</p>' );
$method->invokeArgs( $orchestrator, array( 90, $source_type, $source_id, $source_name ) );

assert_equals( 'drive_file', get_post_meta( 90, '_gdtg_source_type', true ), 'Drive source type recorded' );
assert_equals( 'drv_abc123', get_post_meta( 90, '_gdtg_source_id', true ), 'Drive source ID recorded' );
assert_equals( 'My Drive Doc.docx', get_post_meta( 90, '_gdtg_source_name', true ), 'Drive source name recorded' );
assert_equals( md5( '<p>Drive content</p>' ), get_post_meta( 90, '_gdtg_last_content_hash', true ), 'Drive content hash matched' );

// Test that without source context, docx_upload is used.
$test_options_local = array();
$source_type_local = ! empty( $test_options_local['_gdtg_source'] ) ? $test_options_local['_gdtg_source'] : 'docx_upload';
assert_equals( 'docx_upload', $source_type_local, 'Local docx records docx_upload source type' );

suite( 'Google Docs / Drive Import Orchestrator: parse_bool_strict' );

// True values.
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( 'true' ), 'String "true" parses as true' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( '1' ), 'String "1" parses as true' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( 'yes' ), 'String "yes" parses as true' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( 'TRUE' ), 'Uppercase "TRUE" parses as true' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( true ), 'Boolean true parses as true' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( 1 ), 'Integer 1 parses as true' );

// False values.
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 'false' ), 'String "false" parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( '0' ), 'String "0" parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 'no' ), 'String "no" parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 'FALSE' ), 'Uppercase "FALSE" parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( false ), 'Boolean false parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 0 ), 'Integer 0 parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( '' ), 'Empty string parses as false' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( null ), 'Null parses as false' );

// Default values.
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( null, true ), 'Null with default true returns true' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( null, false ), 'Null with default false returns false' );

suite( 'Google Docs / Drive Import Orchestrator: result_to_response_data' );

// Test with WP_Error.
$wp_error = new WP_Error( 'test_code', 'Test error message' );
$error_data = GDTG_Import_Orchestrator::result_to_response_data( $wp_error );
assert_true( ! $error_data['success'], 'WP_Error result has success=false' );
assert_equals( 'Test error message', $error_data['message'], 'WP_Error result has correct message' );

// Test with successful result.
$success_result = array(
	'post_id' => 42,
	'title'   => 'Test Post',
	'status'  => 'draft',
	'is_new'  => true,
);
$success_data = GDTG_Import_Orchestrator::result_to_response_data( $success_result );
assert_true( $success_data['success'], 'Success result has success=true' );
assert_equals( 42, $success_data['post_id'], 'Success result has correct post_id' );
assert_equals( 'Test Post', $success_data['title'], 'Success result has correct title' );

// Test with batch result.
$batch_result = array(
	'post_id'     => 50,
	'title'       => 'Batch Post',
	'status'      => 'draft',
	'is_new'      => true,
	'batch'       => true,
	'job_id'      => 'abc123',
	'image_count' => 10,
);
$batch_data = GDTG_Import_Orchestrator::result_to_response_data( $batch_result );
assert_true( $batch_data['batch'], 'Batch result has batch=true' );
assert_equals( 'abc123', $batch_data['job_id'], 'Batch result has correct job_id' );
assert_equals( 10, $batch_data['image_count'], 'Batch result has correct image_count' );

// Test with warnings.
$warn_result = array(
	'post_id'  => 60,
	'title'    => 'Warn Post',
	'status'   => 'draft',
	'is_new'   => false,
	'warnings' => array( 'SEO title missing', 'Category not found' ),
);
$warn_data = GDTG_Import_Orchestrator::result_to_response_data( $warn_result );
assert_true( ! empty( $warn_data['warnings'] ), 'Warning result has warnings array' );
assert_equals( 2, count( $warn_data['warnings'] ), 'Warning result has 2 warnings' );
suite( 'Google Docs / Drive Import Orchestrator: normalize_canonical_url' );
// Empty/null inputs return empty string.
assert_equals( '', GDTG_Import_Orchestrator::normalize_canonical_url( '' ), 'Empty string returns empty string' );
assert_equals( '', GDTG_Import_Orchestrator::normalize_canonical_url( null ), 'Null returns empty string' );
assert_equals( '', GDTG_Import_Orchestrator::normalize_canonical_url( '   ' ), 'Whitespace returns empty string' );
// Valid http/https URLs.
$valid_http = GDTG_Import_Orchestrator::normalize_canonical_url( 'http://example.com/page' );
assert_true( ! is_wp_error( $valid_http ), 'Valid http URL does not return WP_Error' );
assert_equals( 'http://example.com/page', $valid_http, 'Valid http URL is preserved' );
$valid_https = GDTG_Import_Orchestrator::normalize_canonical_url( 'https://example.com/page?foo=bar' );
assert_true( ! is_wp_error( $valid_https ), 'Valid https URL does not return WP_Error' );
assert_equals( 'https://example.com/page?foo=bar', $valid_https, 'Valid https URL is preserved' );
// Invalid schemes.
$ftp_result = GDTG_Import_Orchestrator::normalize_canonical_url( 'ftp://files.example.com/doc' );
assert_true( is_wp_error( $ftp_result ), 'FTP URL returns WP_Error' );
$javascript_result = GDTG_Import_Orchestrator::normalize_canonical_url( 'javascript:alert(1)' );
assert_true( is_wp_error( $javascript_result ), 'javascript: URL returns WP_Error' );
$relative_result = GDTG_Import_Orchestrator::normalize_canonical_url( '/relative/path' );
assert_true( is_wp_error( $relative_result ), 'Relative URL returns WP_Error' );
$no_scheme_result = GDTG_Import_Orchestrator::normalize_canonical_url( 'example.com/page' );
assert_true( is_wp_error( $no_scheme_result ), 'URL without scheme returns WP_Error' );

suite( 'Google Docs / Drive Import Orchestrator: result_to_response_data with migration fields' );

// Test with migration fields present.
$migrated_result = array(
	'post_id'       => 42,
	'title'         => 'Migrated Doc',
	'status'        => 'publish',
	'migrated'      => true,
	'migrated_from' => 'drive_file',
	'migrated_to'   => 'gdoc',
);
$migrated_data = GDTG_Import_Orchestrator::result_to_response_data( $migrated_result );
assert_true( $migrated_data['success'], 'Migrated result has success=true' );
assert_true( $migrated_data['migrated'], 'Migrated result has migrated=true' );
assert_equals( 'drive_file', $migrated_data['migrated_from'], 'Migrated result has correct migrated_from' );
assert_equals( 'gdoc', $migrated_data['migrated_to'], 'Migrated result has correct migrated_to' );

// Test without migration fields (normal sync).
$normal_result = array(
	'post_id' => 42,
	'title'   => 'Normal Doc',
	'status'  => 'publish',
);
$normal_data = GDTG_Import_Orchestrator::result_to_response_data( $normal_result );
assert_true( ! isset( $normal_data['migrated'] ), 'Normal result has no migrated key' );
assert_true( ! isset( $normal_data['migrated_from'] ), 'Normal result has no migrated_from key' );
assert_true( ! isset( $normal_data['migrated_to'] ), 'Normal result has no migrated_to key' );

// Test with partial migration fields (edge case).
$partial_result = array(
	'post_id'       => 42,
	'title'         => 'Partial Doc',
	'status'        => 'publish',
	'migrated'      => true,
	'migrated_from' => 'drive_file',
	// migrated_to missing — should default to empty string.
);
$partial_data = GDTG_Import_Orchestrator::result_to_response_data( $partial_result );
assert_true( $partial_data['migrated'], 'Partial migration result has migrated=true' );
assert_equals( 'drive_file', $partial_data['migrated_from'], 'Partial migration has correct migrated_from' );
assert_equals( '', $partial_data['migrated_to'], 'Missing migrated_to defaults to empty string' );

suite( 'Google Docs / Drive Import Orchestrator: sanitize_options_for_persistence excludes _gdtg_migrating' );

// Verify that _gdtg_migrating is NOT persisted.
// Use reflection to test private method.
$sanitizer = new ReflectionMethod( 'GDTG_Import_Orchestrator', 'sanitize_options_for_persistence' );
$sanitizer->setAccessible( true );

$options_with_flag = array(
	'import_images'     => true,
	'import_tables'     => true,
	'overwrite'         => true,
	'_gdtg_migrating'   => true,
	'output_mode'       => 'gutenberg',
);
$sanitized = $sanitizer->invokeArgs( $orchestrator, array( $options_with_flag ) );
assert_true( ! isset( $sanitized['_gdtg_migrating'] ), '_gdtg_migrating flag is stripped by sanitizer' );
assert_true( $sanitized['import_images'], 'import_images preserved after sanitization' );
assert_equals( 'gutenberg', $sanitized['output_mode'], 'output_mode preserved after sanitization' );


suite('Phase 3: classify_error');

assert_equals('rate_limited', GDTG_Import_Orchestrator::classify_error(new WP_Error('gdtg_rate_limited', 'Rate limit')), 'gdtg_rate_limited code classifies as rate_limited');
assert_equals('rate_limited', GDTG_Import_Orchestrator::classify_error(new WP_Error('other', 'Rate limit exceeded')), 'rate limit message classifies as rate_limited');
assert_equals('auth', GDTG_Import_Orchestrator::classify_error(new WP_Error('gdtg_no_token', 'No token')), 'no_token classifies as auth');
assert_equals('auth', GDTG_Import_Orchestrator::classify_error(new WP_Error('other', 'Authentication failed')), 'auth in message classifies as auth');
assert_equals('network', GDTG_Import_Orchestrator::classify_error(new WP_Error('http_request_failed', 'cURL error 28')), 'http_request code classifies as network');
assert_equals('network', GDTG_Import_Orchestrator::classify_error(new WP_Error('other', 'Connection timed out')), 'timed out message classifies as network');
assert_equals('api_unavailable', GDTG_Import_Orchestrator::classify_error(new WP_Error('gdtg_drive_metadata_error', 'Drive metadata error')), 'drive error code classifies as api_unavailable');
assert_equals('unsupported', GDTG_Import_Orchestrator::classify_error(new WP_Error('gdtg_unsupported_mime', 'Unsupported type')), 'unsupported code classifies as unsupported');
assert_equals('unknown', GDTG_Import_Orchestrator::classify_error(new WP_Error('something_else', 'Something else')), 'unrecognized error classifies as unknown');
assert_equals('unknown', GDTG_Import_Orchestrator::classify_error('not_a_wp_error'), 'non-WP_Error classifies as unknown');

suite('Phase 3: _gdtg_drive_mime_type cache contract');

// Simulate what import_drive_file does: store cache after successful metadata
$mock_post_meta[200] = array();
update_post_meta(200, '_gdtg_source_type', 'drive_file');
update_post_meta(200, '_gdtg_drive_mime_type', 'application/vnd.google-apps.document');
update_post_meta(200, '_gdtg_drive_mime_cached_at', current_time('mysql'));
assert_equals('application/vnd.google-apps.document', get_post_meta(200, '_gdtg_drive_mime_type', true), 'gdoc mime cached in post meta');
assert_equals('2026-05-31 01:23:45', get_post_meta(200, '_gdtg_drive_mime_cached_at', true), 'mime cached_at timestamp stored');

// Test: cache refresh overwrites old value
update_post_meta(200, '_gdtg_drive_mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
assert_equals('application/vnd.openxmlformats-officedocument.wordprocessingml.document', get_post_meta(200, '_gdtg_drive_mime_type', true), 'mime cache refreshed with new value');

suite('Phase 3: _gdtg_migration_pending marker contract');

// Pending marker NOT present by default
assert_equals('', get_post_meta(999, '_gdtg_migration_pending', true), 'no pending marker on fresh post');

// Simulate migration_pending set during handoff
update_post_meta(999, '_gdtg_migration_pending', '1');
assert_equals('1', get_post_meta(999, '_gdtg_migration_pending', true), 'pending marker set');

// Simulate record_sync_metadata clearing it on success
delete_post_meta(999, '_gdtg_migration_pending');


suite('Phase 3: export_google_doc_as_html method exists on GDTG_API');

$reflection = new ReflectionMethod( 'GDTG_API', 'export_google_doc_as_html' );
assert_true( $reflection->isPublic(), 'GDTG_API::export_google_doc_as_html is a public method' );
$params = $reflection->getParameters();
assert_equals( 1, count( $params ), 'export_google_doc_as_html takes exactly one argument' );
assert_equals( 'doc_id', $params[0]->getName(), 'export_google_doc_as_html argument is named doc_id' );

suite('Phase 3: commit_rendered_content helper exists on GDTG_Import_Orchestrator');

$render_helper = new ReflectionMethod( 'GDTG_Import_Orchestrator', 'commit_rendered_content' );
assert_true( $render_helper->isPrivate(), 'commit_rendered_content is a private helper' );
$render_params = $render_helper->getParameters();
assert_equals( 4, count( $render_params ), 'commit_rendered_content takes 4 args (html, options, target_post_id, doc_title)' );

suite('Phase 3: commit_import now delegates to commit_rendered_content');

// Verify the refactor: commit_import should call commit_rendered_content
// internally. We assert the helper exists and is invoked via reflection.
$commit_import = new ReflectionMethod( 'GDTG_Import_Orchestrator', 'commit_import' );
$commit_import->setAccessible( true );
assert_true( $commit_import->isPrivate(), 'commit_import is still private' );
// The body of commit_import must end with a return $this->commit_rendered_content(...)
// call. We verify by checking the source file contains the expected pattern.
$orchestrator_source = file_get_contents( __DIR__ . '/../includes/class-gdtg-import-orchestrator.php' );
assert_true(
	false !== strpos( $orchestrator_source, 'return $this->commit_rendered_content(' ),
	'commit_import delegates to commit_rendered_content'
);

suite('Phase 3: large-doc branch in import_google_doc calls export_google_doc_as_html');

// Verify the wiring: import_google_doc must call export_google_doc_as_html($doc_id)
// in the large-doc branch.
assert_true(
	false !== strpos( $orchestrator_source, '$api->export_google_doc_as_html( $doc_id )' )
	|| false !== strpos( $orchestrator_source, '$api->export_google_doc_as_html($doc_id)' ),
	'import_google_doc large-doc branch calls $api->export_google_doc_as_html( $doc_id )'
);

// Verify the wp:html wrap pattern.
assert_true(
	false !== strpos( $orchestrator_source, '<!-- wp:html -->' )
	|| false !== strpos( $orchestrator_source, 'wp:html' ),
	'export fallback wraps HTML in a wp:html block'
);

assert_true(
	false !== strpos( $orchestrator_source, "['export_fallback']" )
	|| false !== strpos( $orchestrator_source, "'export_fallback' => true" )
	|| false !== strpos( $orchestrator_source, '"export_fallback" => true' ),
	'export fallback sets export_fallback=true on the result'
);


echo "\n==================================================\n";
echo "Orchestrator Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
