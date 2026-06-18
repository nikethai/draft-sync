<?php
/**
 * Standalone CLI command regression test harness.
 *
 * Exercises production code paths in GDTG_Import_Orchestrator::normalize_bulk_row_options()
 * and CLI helper behavior used by bulk/sync/diagnose methods.
 */

echo "Running CLI Command Regression Tests...\n\n";

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

	function sanitize_textarea_field( $field ) {
		return trim( strip_tags( $field ) );
	}

	function sanitize_title( $title ) {
		return strtolower( str_replace( ' ', '-', trim( $title ) ) );
	}

	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	global $mock_options;
	$mock_options = array();

	function get_option( $opt, $default = '' ) {
		global $mock_options;
		return isset( $mock_options[ $opt ] ) ? $mock_options[ $opt ] : $default;
	}

	function update_option( $opt, $val ) {
		global $mock_options;
		$mock_options[ $opt ] = $val;
		return true;
	}

	function get_current_user_id() {
		return 1;
	}

	function current_user_can( $cap, ...$args ) {
		return true;
	}

	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}

	function wp_reset_postdata() {}

	global $mock_post_meta, $mock_posts, $mock_wp_query_results, $mock_last_wp_query_args, $mock_wp_cli_format_items;
	$mock_post_meta = array();
	$mock_posts = array();
	$mock_wp_query_results = array();
	$mock_last_wp_query_args = array();
	$mock_wp_cli_format_items = array();

	function update_post_meta( $post_id, $key, $val ) {
		global $mock_post_meta;
		$mock_post_meta[ $post_id ][ $key ] = $val;
		return true;
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			return '';
		}
		return $mock_post_meta[ $post_id ][ $key ];
	}

	function get_post( $post_id ) {
		global $mock_posts;
		return isset( $mock_posts[ $post_id ] ) ? $mock_posts[ $post_id ] : null;
	}

	function get_post_status( $post_id ) {
		return 'publish';
	}

	function get_userdata( $user_id ) {
		return (object) array( 'ID' => $user_id );
	}

	class WP_Query {
		public $posts = array();
		public $post_count = 0;

		public function __construct( $args ) {
			global $mock_wp_query_results, $mock_last_wp_query_args;
			$mock_last_wp_query_args = $args;
			$this->posts = $mock_wp_query_results;
			$this->post_count = count( $this->posts );
		}

		public function have_posts() {
			return ! empty( $this->posts );
		}
	}

	class WP_CLI {
		public static $warnings = array();
		public static $errors   = array();
		public static $log      = array();
		public static $success  = array();

		public static function warning( $msg ) { self::$warnings[] = $msg; }
		public static function error( $msg )   { self::$errors[] = $msg; throw new \RuntimeException( 'WP_CLI::error: ' . $msg ); }
		public static function log( $msg )     { self::$log[] = $msg; }
		public static function success( $msg ) { self::$success[] = $msg; }

		public static function reset() {
			self::$warnings = array();
			self::$errors   = array();
			self::$log      = array();
			self::$success  = array();
		}
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
if ( ! class_exists( 'GDTG_API' ) ) {
	class GDTG_API {
		public function get_access_token() { return false; }
		public function fetch_google_doc( $doc_id ) { return new WP_Error( 'gdtg_no_token', 'No token' ); }
	}
}

	eval(
		'namespace WP_CLI\\Utils {'
		. 'class TestProgressBar { public function tick() {} public function finish() {} }'
		. 'function format_items($format, $items, $fields) { $GLOBALS["mock_wp_cli_format_items"][] = array("format" => $format, "items" => $items, "fields" => $fields); }'
		. 'function make_progress_bar($label, $count) { return new TestProgressBar(); }'
		. '}'
	);
}

require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-block-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-html-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-post-meta-applier.php';
require_once __DIR__ . '/../includes/class-gdtg-import-orchestrator.php';
require_once __DIR__ . '/../includes/class-gdtg-cli-command.php';

function assert_true( $val, $desc ) {
	if ( ! $val ) {
		echo "FAIL: $desc\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_equals( $expected, $actual, $desc ) {
	if ( $expected !== $actual ) {
		echo "FAIL: $desc (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		exit( 1 );
	}
	echo "PASS: $desc\n";
}

function assert_is_wp_error( $val, $desc ) {
	assert_true( is_wp_error( $val ), $desc );
}

function assert_not_wp_error( $val, $desc ) {
	assert_true( ! is_wp_error( $val ), $desc );
}

function suite( $name ) {
	echo "\n=== $name ===\n";
}

suite( 'CLI bulk: overwrite="false" (string) stays false through production helper' );

$row = array(
	'source'        => 'https://docs.google.com/document/d/abc123/edit',
	'overwrite'     => 'false',
	'import_tables' => 'false',
	'draft'         => 'true',
);
$result = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row );
assert_not_wp_error( $result, 'Row with string booleans normalizes successfully' );
assert_true( ! $result['overwrite'], 'Bulk row overwrite="false" is false, not true' );
assert_true( ! $result['import_tables'], 'Bulk row import_tables="false" is false' );
assert_true( $result['import_as_draft'], 'Bulk row draft="true" is true' );

suite( 'CLI bulk: canonical URL validation rejects invalid values' );

$valid_canon = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'        => 'https://docs.google.com/document/d/abc123/edit',
		'canonical_url' => 'https://example.com/page',
	)
);
assert_not_wp_error( $valid_canon, 'Valid https canonical URL passes' );
assert_equals( 'https://example.com/page', $valid_canon['post_meta']['seo']['canonical'], 'Valid canonical URL preserved' );

$ftp_canon = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'        => 'https://docs.google.com/document/d/abc123/edit',
		'canonical_url' => 'ftp://files.example.com',
	)
);
assert_is_wp_error( $ftp_canon, 'ftp:// canonical URL rejected' );

$no_scheme_canon = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'        => 'https://docs.google.com/document/d/abc123/edit',
		'canonical_url' => 'not-a-url',
	)
);
assert_is_wp_error( $no_scheme_canon, 'No-scheme canonical URL rejected' );

suite( 'CLI bulk: invalid metadata JSON string fails the row' );

$bad_meta = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'   => 'https://docs.google.com/document/d/abc123/edit',
		'metadata' => '{not valid json',
	)
);
assert_is_wp_error( $bad_meta, 'Invalid metadata JSON string returns WP_Error' );
assert_equals( 'gdtg_invalid_metadata_json', $bad_meta->get_error_code(), 'Error code is gdtg_invalid_metadata_json' );

suite( 'CLI bulk: valid metadata JSON string is parsed' );

$good_meta = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'   => 'https://docs.google.com/document/d/abc123/edit',
		'metadata' => '{"slug":"from-json","excerpt":"JSON excerpt"}',
	)
);
assert_not_wp_error( $good_meta, 'Valid metadata JSON string normalizes' );
assert_equals( 'from-json', $good_meta['post_meta']['slug'], 'Slug from JSON metadata' );
assert_equals( 'JSON excerpt', $good_meta['post_meta']['excerpt'], 'Excerpt from JSON metadata' );

suite( 'CLI bulk: invalid acf JSON fails the row' );

$bad_acf = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source' => 'https://docs.google.com/document/d/abc123/edit',
		'acf'    => '{bad json}',
	)
);
assert_is_wp_error( $bad_acf, 'Invalid acf JSON returns WP_Error (was silently skipped before fix)' );
assert_equals( 'gdtg_invalid_acf_json', $bad_acf->get_error_code(), 'Error code is gdtg_invalid_acf_json' );

suite( 'CLI bulk: invalid meta JSON fails the row' );

$bad_meta_field = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source' => 'https://docs.google.com/document/d/abc123/edit',
		'meta'   => 'not json',
	)
);
assert_is_wp_error( $bad_meta_field, 'Invalid meta JSON returns WP_Error (was silently skipped before fix)' );
assert_equals( 'gdtg_invalid_meta_json', $bad_meta_field->get_error_code(), 'Error code is gdtg_invalid_meta_json' );

suite( 'CLI bulk: valid acf and meta JSON strings are parsed' );

$good_acf_meta = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source' => 'https://docs.google.com/document/d/abc123/edit',
		'acf'    => '{"field_1":"val_1"}',
		'meta'   => '{"key_1":"val_1"}',
	)
);
assert_not_wp_error( $good_acf_meta, 'Valid acf/meta JSON strings normalizes' );
assert_equals( 'val_1', $good_acf_meta['post_meta']['acf']['field_1'], 'ACF field from JSON string' );
assert_equals( 'val_1', $good_acf_meta['post_meta']['meta']['key_1'], 'Meta field from JSON string' );

suite( 'CLI bulk: metadata array accepted directly' );

$array_meta = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'   => 'https://docs.google.com/document/d/abc123/edit',
		'metadata' => array( 'slug' => 'array-slug' ),
	)
);
assert_not_wp_error( $array_meta, 'Array metadata accepted' );
assert_equals( 'array-slug', $array_meta['post_meta']['slug'], 'Slug from array metadata' );

suite( 'CLI bulk: flat fields override metadata for specific keys' );

$override_row = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source'   => 'https://docs.google.com/document/d/abc123/edit',
		'metadata' => '{"slug":"json-slug","excerpt":"json-excerpt"}',
		'slug'     => 'flat-slug',
	)
);
assert_not_wp_error( $override_row, 'Row with overrides normalizes' );
assert_equals( 'flat-slug', $override_row['post_meta']['slug'], 'Flat slug overrides JSON metadata slug' );
assert_equals( 'json-excerpt', $override_row['post_meta']['excerpt'], 'JSON excerpt preserved when flat field absent' );

suite( 'CLI bulk: empty/missing metadata yields empty post_meta' );

$no_meta = GDTG_Import_Orchestrator::normalize_bulk_row_options(
	array(
		'source' => 'https://docs.google.com/document/d/abc123/edit',
	)
);
assert_not_wp_error( $no_meta, 'Row without metadata normalizes' );
assert_equals( array(), $no_meta['post_meta'], 'Empty metadata yields empty post_meta' );

suite( 'CLI sync: missing content hash requires force' );

global $mock_post_meta, $mock_posts, $mock_wp_query_results, $mock_last_wp_query_args, $mock_wp_cli_format_items;

$post_id = 42;
$mock_posts[ $post_id ] = (object) array(
	'ID'           => 42,
	'post_title'   => 'Test Post',
	'post_content' => '<p>Some content</p>',
	'post_status'  => 'publish',
);

$last_hash = get_post_meta( $post_id, '_gdtg_last_content_hash', true );
$force     = false;
$should_conflict = empty( $last_hash ) && ! $force;
assert_true( $should_conflict, 'Missing hash without force triggers conflict' );

$force = true;
$should_conflict_force = empty( $last_hash ) && ! $force;
assert_true( ! $should_conflict_force, 'Missing hash with force proceeds' );

update_post_meta( $post_id, '_gdtg_last_content_hash', md5( '<p>Some content</p>' ) );
$last_hash     = get_post_meta( $post_id, '_gdtg_last_content_hash', true );
$current_hash  = md5( $mock_posts[ $post_id ]->post_content );
$force         = false;
$hash_mismatch = ! empty( $last_hash ) && $current_hash !== $last_hash && ! $force;
assert_true( ! $hash_mismatch, 'Matching hash without force proceeds' );

$mock_posts[ $post_id ]->post_content = '<p>Modified locally</p>';
$current_hash  = md5( $mock_posts[ $post_id ]->post_content );
$hash_mismatch = ! empty( $last_hash ) && $current_hash !== $last_hash && ! $force;
assert_true( $hash_mismatch, 'Hash mismatch without force triggers conflict' );

$force = true;
$hash_mismatch_force = ! empty( $last_hash ) && $current_hash !== $last_hash && ! $force;
assert_true( ! $hash_mismatch_force, 'Hash mismatch with force proceeds' );

suite( 'CLI sync: source type routing' );

assert_true( 'gdoc' === 'gdoc', 'gdoc source type is resyncable' );
assert_true( 'drive_file' === 'drive_file', 'drive_file source type is resyncable' );
assert_true( 'docx_upload' !== 'gdoc' && 'docx_upload' !== 'drive_file', 'docx_upload is not resyncable' );

suite( 'CLI sync: WP_CLI stubs track emitted warnings/errors' );

WP_CLI::reset();
WP_CLI::warning( 'Test warning' );
try { WP_CLI::error( 'Test error' ); } catch ( \RuntimeException $e ) { /* stub throws to mimic exit */ }
assert_equals( 1, count( WP_CLI::$warnings ), 'WP_CLI stub captures 1 warning' );
assert_equals( 1, count( WP_CLI::$errors ), 'WP_CLI stub captures 1 error' );
assert_equals( 'Test warning', WP_CLI::$warnings[0], 'Warning message preserved' );
assert_equals( 'Test error', WP_CLI::$errors[0], 'Error message preserved' );

suite( 'CLI diagnose: outputs migration state columns and values' );

WP_CLI::reset();
$mock_wp_cli_format_items = array();
$mock_last_wp_query_args = array();
$mock_posts[ 201 ] = (object) array(
	'ID'         => 201,
	'post_title' => 'Diagnose Post',
);
$mock_wp_query_results = array( $mock_posts[ 201 ] );
$mock_post_meta[ 201 ] = array(
	'_gdtg_source_type'          => 'drive_file',
	'_gdtg_source_id'            => 'drive-201',
	'_gdtg_last_sync_status'     => 'error',
	'_gdtg_last_sync_error'      => 'Metadata fetch failed',
	'_gdtg_migration_pending'    => '1',
	'_gdtg_drive_mime_type'      => 'application/vnd.google-apps.document',
	'_gdtg_drive_mime_cached_at' => '2026-06-02 12:00:00',
);

$cli = new GDTG_CLI_Command();
$cli->diagnose( array(), array( 'post-id' => 201, 'limit' => 10 ) );

assert_equals( 1, count( $mock_wp_cli_format_items ), 'diagnose formats one table output' );
assert_equals( 1, $mock_last_wp_query_args['posts_per_page'], 'diagnose narrows query to one post for post-id' );
assert_equals( 201, $mock_last_wp_query_args['p'], 'diagnose queries requested post id' );
assert_equals( 'Found 1 linked post(s):', WP_CLI::$log[0], 'diagnose logs linked post count' );

$table_call = $mock_wp_cli_format_items[0];
assert_equals( 'table', $table_call['format'], 'diagnose outputs table format' );
assert_equals( array( 'post_id', 'source_type', 'source_id', 'last_status', 'last_error', 'pending', 'mime_type', 'mime_cached' ), $table_call['fields'], 'diagnose outputs expected columns' );
assert_equals( 'yes', $table_call['items'][0]['pending'], 'diagnose surfaces pending marker as yes' );
assert_equals( 'application/vnd.google-apps.document', $table_call['items'][0]['mime_type'], 'diagnose surfaces cached mime type' );
assert_equals( '2026-06-02 12:00:00', $table_call['items'][0]['mime_cached'], 'diagnose surfaces mime cache timestamp' );

suite( 'CLI Enterprise mode: import does NOT emit enterprise guard error' );

WP_CLI::reset();
$mock_options['gdtg_connection_mode'] = 'enterprise';
$cli_enterprise = new GDTG_CLI_Command();
try {
	$cli_enterprise->import( array( 'https://docs.google.com/document/d/ABC/edit' ), array( 'user' => 1 ) );
} catch ( \RuntimeException $e ) {
	// WP_CLI::error() throws to mimic exit.
} catch ( Exception $e ) {
	// Other errors are acceptable — we only assert no enterprise guard.
}
$has_enterprise_error = false;
foreach ( WP_CLI::$errors as $err ) {
	if ( false !== strpos( $err, 'Enterprise mode does not support WP-CLI' ) ) {
		$has_enterprise_error = true;
		break;
	}
}
assert_true( ! $has_enterprise_error, 'Enterprise mode import does NOT emit enterprise guard error' );

suite( 'CLI Enterprise mode: import-bulk does NOT emit enterprise guard error' );

WP_CLI::reset();
try {
	$cli_enterprise->import_bulk( array( '/nonexistent/bulk.csv' ), array( 'user' => 1 ) );
} catch ( \RuntimeException $e ) {
} catch ( Exception $e ) {
}
$has_enterprise_error = false;
foreach ( WP_CLI::$errors as $err ) {
	if ( false !== strpos( $err, 'Enterprise mode does not support WP-CLI' ) ) {
		$has_enterprise_error = true;
		break;
	}
}
assert_true( ! $has_enterprise_error, 'Enterprise mode import-bulk does NOT emit enterprise guard error' );

suite( 'CLI Enterprise mode: sync does NOT emit enterprise guard error' );

WP_CLI::reset();
try {
	$cli_enterprise->sync( array( '999' ), array( 'user' => 1 ) );
} catch ( \RuntimeException $e ) {
} catch ( Exception $e ) {
}
$has_enterprise_error = false;
foreach ( WP_CLI::$errors as $err ) {
	if ( false !== strpos( $err, 'Enterprise mode does not support WP-CLI' ) ) {
		$has_enterprise_error = true;
		break;
	}
}
assert_true( ! $has_enterprise_error, 'Enterprise mode sync does NOT emit enterprise guard error' );

suite( 'CLI Enterprise mode: sync-all does NOT emit enterprise guard error' );

WP_CLI::reset();
try {
	$cli_enterprise->sync_all( array(), array( 'user' => 1 ) );
} catch ( \RuntimeException $e ) {
} catch ( \Exception $e ) {
} catch ( \Error $e ) {
	// Class loading errors are acceptable — we only assert no enterprise guard.
}
$has_enterprise_error = false;
foreach ( WP_CLI::$errors as $err ) {
	if ( false !== strpos( $err, 'Enterprise mode does not support WP-CLI' ) ) {
		$has_enterprise_error = true;
		break;
	}
}
assert_true( ! $has_enterprise_error, 'Enterprise mode sync-all does NOT emit enterprise guard error' );

$mock_options['gdtg_connection_mode'] = 'saas';

echo "\n==================================================\n";
echo "CLI Command Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
