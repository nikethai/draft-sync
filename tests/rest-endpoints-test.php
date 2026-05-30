<?php
/**
 * Standalone REST endpoints regression test harness.
 *
 * Exercises production code paths in GDTG_REST_Endpoints and
 * GDTG_Import_Orchestrator::normalize_bulk_row_options().
 */

echo "Running REST Endpoints Regression Tests...\n\n";

// ─── Minimal WordPress stubs ──────────────────────────────────────

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

	function get_option( $opt, $default = '' ) {
		global $mock_options;
		return isset( $mock_options[ $opt ] ) ? $mock_options[ $opt ] : $default;
	}
	function update_option( $opt, $val ) {
		global $mock_options;
		$mock_options[ $opt ] = $val;
		return true;
	}
	function add_option( $opt, $value = '', $deprecated = '', $autoload = 'yes' ) {
		global $mock_options;
		if ( isset( $mock_options[ $opt ] ) ) {
			return false;
		}
		$mock_options[ $opt ] = $value;
		return true;
	}
	function delete_option( $opt ) {
		global $mock_options;
		unset( $mock_options[ $opt ] );
		return true;
	}

	global $mock_options;
	$mock_options = array();

	// Cron stubs.
	global $mock_scheduled_single_events;
	$mock_scheduled_single_events = array();

	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		global $mock_scheduled_single_events;
		$mock_scheduled_single_events[] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);
		return true;
	}

	function wp_next_scheduled( $hook, $args = array() ) {
		global $mock_scheduled_single_events;
		if ( ! empty( $mock_scheduled_single_events ) ) {
			foreach ( $mock_scheduled_single_events as $event ) {
				if ( $event['hook'] === $hook && ( empty( $args ) || $event['args'] == $args ) ) {
					return $event['timestamp'];
				}
			}
		}
		return false;
	}

	function wp_clear_scheduled_hook( $hook ) {
		global $mock_scheduled_single_events;
		$mock_scheduled_single_events = array_filter(
			$mock_scheduled_single_events,
			function ( $e ) use ( $hook ) {
				return $e['hook'] !== $hook;
			}
		);
		return true;
	}

	if ( ! function_exists( 'wp_set_current_user' ) ) {
		function wp_set_current_user( $user_id ) {
			// No-op in test harness.
		}
	}


	global $mock_post_meta;
	$mock_post_meta = array();

	function update_post_meta( $post_id, $key, $val ) {
		global $mock_post_meta;
		$mock_post_meta[ $post_id ][ $key ] = $val;
		return true;
	}

	function delete_post_meta( $post_id, $key ) {
		global $mock_post_meta;
		if ( isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			unset( $mock_post_meta[ $post_id ][ $key ] );
		}
		return true;
	}

	function get_post_meta( $post_id, $key, $single = false ) {
		global $mock_post_meta;
		if ( ! isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
			return '';
		}
		return $mock_post_meta[ $post_id ][ $key ];
	}

	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}

	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_array( $args ) ) {
			$parsed_args = array_merge( $defaults, $args );
		} else {
			parse_str( $args, $parsed_args );
			$parsed_args = array_merge( $defaults, $parsed_args );
		}
		return $parsed_args;
	}
}
function wp_kses_post( $text ) { return strip_tags( $text ); }

// Load necessary files.
require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-block-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-html-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-post-meta-applier.php';
require_once __DIR__ . '/../includes/class-gdtg-import-orchestrator.php';
require_once __DIR__ . '/../includes/class-gdtg-parser.php';
require_once __DIR__ . '/../includes/class-gdtg-rest-endpoints.php';
require_once __DIR__ . '/../includes/class-gdtg-sync-lock.php';

// Helper assertion functions.
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

// ═══════════════════════════════════════════════════════════════════
// Tests exercising GDTG_Import_Orchestrator::normalize_bulk_row_options()
// This is the production helper shared by REST and CLI bulk handlers.
// ═══════════════════════════════════════════════════════════════════

suite( 'normalize_bulk_row_options: parses metadata as JSON string' );

$row_with_json_meta = array(
	'source'   => 'https://docs.google.com/document/d/abc123/edit',
	'metadata' => '{"slug":"my-post","excerpt":"Hello world"}',
);
$result = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_with_json_meta );
assert_not_wp_error( $result, 'JSON-string metadata is accepted' );
assert_equals( 'my-post', $result['post_meta']['slug'], 'Slug from JSON metadata preserved' );
assert_equals( 'Hello world', $result['post_meta']['excerpt'], 'Excerpt from JSON metadata preserved' );

suite( 'normalize_bulk_row_options: parses metadata as array' );

$row_with_array_meta = array(
	'source'   => 'https://docs.google.com/document/d/abc123/edit',
	'metadata' => array( 'slug' => 'array-slug' ),
);
$result2 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_with_array_meta );
assert_not_wp_error( $result2, 'Array metadata is accepted' );
assert_equals( 'array-slug', $result2['post_meta']['slug'], 'Slug from array metadata preserved' );

suite( 'normalize_bulk_row_options: rejects invalid metadata JSON string' );

$row_bad_meta_json = array(
	'source'   => 'https://docs.google.com/document/d/abc123/edit',
	'metadata' => '{not valid json',
);
$result3 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_bad_meta_json );
assert_is_wp_error( $result3, 'Invalid metadata JSON returns WP_Error' );
assert_equals( 'gdtg_invalid_metadata_json', $result3->get_error_code(), 'Error code is gdtg_invalid_metadata_json' );

suite( 'normalize_bulk_row_options: rejects invalid acf JSON string' );

$row_bad_acf = array(
	'source' => 'https://docs.google.com/document/d/abc123/edit',
	'acf'    => '{bad json}',
);
$result4 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_bad_acf );
assert_is_wp_error( $result4, 'Invalid acf JSON returns WP_Error' );
assert_equals( 'gdtg_invalid_acf_json', $result4->get_error_code(), 'Error code is gdtg_invalid_acf_json' );

suite( 'normalize_bulk_row_options: rejects invalid meta JSON string' );

$row_bad_meta = array(
	'source' => 'https://docs.google.com/document/d/abc123/edit',
	'meta'   => 'not-json-at-all',
);
$result5 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_bad_meta );
assert_is_wp_error( $result5, 'Invalid meta JSON returns WP_Error' );
assert_equals( 'gdtg_invalid_meta_json', $result5->get_error_code(), 'Error code is gdtg_invalid_meta_json' );

suite( 'normalize_bulk_row_options: flat fields override decoded metadata for specific keys' );

$row_override = array(
	'source'       => 'https://docs.google.com/document/d/abc123/edit',
	'metadata'     => '{"slug":"json-slug","excerpt":"json-excerpt"}',
	'slug'         => 'flat-slug',
);
$result6 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_override );
assert_not_wp_error( $result6, 'Row with overrides normalizes successfully' );
assert_equals( 'flat-slug', $result6['post_meta']['slug'], 'Flat slug overrides JSON metadata slug' );
assert_equals( 'json-excerpt', $result6['post_meta']['excerpt'], 'JSON excerpt preserved when flat field absent' );

suite( 'normalize_bulk_row_options: rejects invalid canonical URL' );

$row_bad_canon = array(
	'source'        => 'https://docs.google.com/document/d/abc123/edit',
	'canonical_url' => 'ftp://bad-scheme.com',
);
$result7 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_bad_canon );
assert_is_wp_error( $result7, 'Invalid canonical URL returns WP_Error' );

suite( 'normalize_bulk_row_options: accepts valid canonical URL' );

$row_good_canon = array(
	'source'        => 'https://docs.google.com/document/d/abc123/edit',
	'canonical_url' => 'https://example.com/canonical',
);
$result8 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_good_canon );
assert_not_wp_error( $result8, 'Valid canonical URL normalizes successfully' );
assert_equals( 'https://example.com/canonical', $result8['post_meta']['seo']['canonical'], 'Canonical URL stored in seo' );

suite( 'normalize_bulk_row_options: parses acf and meta as valid JSON strings' );

$row_good_acf_meta = array(
	'source' => 'https://docs.google.com/document/d/abc123/edit',
	'acf'    => '{"field_123":"value_a"}',
	'meta'   => '{"custom_key":"custom_val"}',
);
$result9 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_good_acf_meta );
assert_not_wp_error( $result9, 'Valid acf/meta JSON strings normalizes successfully' );
assert_equals( 'value_a', $result9['post_meta']['acf']['field_123'], 'ACF field parsed from JSON string' );
assert_equals( 'custom_val', $result9['post_meta']['meta']['custom_key'], 'Meta field parsed from JSON string' );

suite( 'normalize_bulk_row_options: empty/missing metadata returns empty post_meta' );

$row_no_meta = array(
	'source' => 'https://docs.google.com/document/d/abc123/edit',
);
$result10 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_no_meta );
assert_not_wp_error( $result10, 'Row without metadata normalizes successfully' );
assert_equals( array(), $result10['post_meta'], 'Empty metadata yields empty post_meta' );

suite( 'normalize_bulk_row_options: parse_bool_strict prevents string "false" becoming true' );

$row_bool_false = array(
	'source'        => 'https://docs.google.com/document/d/abc123/edit',
	'overwrite'     => 'false',
	'import_tables' => 'false',
	'draft'         => 'true',
);
$result11 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_bool_false );
assert_not_wp_error( $result11, 'Row with string booleans normalizes successfully' );
assert_true( ! $result11['overwrite'], 'String "false" for overwrite stays false' );
assert_true( ! $result11['import_tables'], 'String "false" for import_tables stays false' );
assert_true( $result11['import_as_draft'], 'String "true" for draft is true' );

suite( 'normalize_bulk_row_options: SEO fields populated from flat fields' );

$row_seo = array(
	'source'          => 'https://docs.google.com/document/d/abc123/edit',
	'seo_title'       => 'My SEO Title',
	'seo_description' => 'My SEO Description',
	'focus_keyword'   => 'keyword1',
);
$result12 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_seo );
assert_not_wp_error( $result12, 'Row with SEO fields normalizes successfully' );
assert_equals( 'My SEO Title', $result12['post_meta']['seo']['title'], 'SEO title set' );
assert_equals( 'My SEO Description', $result12['post_meta']['seo']['description'], 'SEO description set' );
assert_equals( 'keyword1', $result12['post_meta']['seo']['focus_keyword'], 'SEO focus keyword set' );

suite( 'normalize_bulk_row_options: categories and tags parsed from comma-separated strings' );

$row_tax = array(
	'source'     => 'https://docs.google.com/document/d/abc123/edit',
	'categories' => 'cat1, cat2, cat3',
	'tags'       => 'tag1,tag2',
);
$result13 = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_tax );
assert_not_wp_error( $result13, 'Row with taxonomy fields normalizes successfully' );
assert_equals( 3, count( $result13['post_meta']['categories'] ), '3 categories parsed' );
assert_equals( 2, count( $result13['post_meta']['tags'] ), '2 tags parsed' );

// ═══════════════════════════════════════════════════════════════════
// Tests for parse_bool_strict (REST input parsing).
// ═══════════════════════════════════════════════════════════════════

suite( 'parse_bool_strict: covers REST string "false" becoming true' );

assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 'false' ), 'String "false" parses as false (not true)' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( 'false', false ), '"false" with default false stays false' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( 'true' ), 'String "true" parses as true' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( '' ), 'Empty string defaults to false' );
assert_true( GDTG_Import_Orchestrator::parse_bool_strict( '', true ), 'Empty string with default true returns true' );
assert_true( ! GDTG_Import_Orchestrator::parse_bool_strict( null ), 'Null defaults to false' );

// Demonstrate the bug this replaces:
assert_true( (bool) 'false' === true, 'NOTE: (bool) "false" is true - the bug parse_bool_strict fixes' );

// ═══════════════════════════════════════════════════════════════════
// Tests for normalize_canonical_url (REST input parsing).
// ═══════════════════════════════════════════════════════════════════

suite( 'normalize_canonical_url: rejects invalid URLs' );

assert_equals( '', GDTG_Import_Orchestrator::normalize_canonical_url( '' ), 'Empty string returns empty' );
assert_equals( '', GDTG_Import_Orchestrator::normalize_canonical_url( null ), 'Null returns empty' );

$http = GDTG_Import_Orchestrator::normalize_canonical_url( 'http://example.com/page' );
assert_not_wp_error( $http, 'http URL is valid' );
assert_equals( 'http://example.com/page', $http, 'http URL preserved' );

$https = GDTG_Import_Orchestrator::normalize_canonical_url( 'https://example.com/page?q=1' );
assert_not_wp_error( $https, 'https URL is valid' );

assert_is_wp_error( GDTG_Import_Orchestrator::normalize_canonical_url( 'ftp://example.com' ), 'ftp:// rejected' );
assert_is_wp_error( GDTG_Import_Orchestrator::normalize_canonical_url( 'javascript:alert(1)' ), 'javascript: rejected' );
assert_is_wp_error( GDTG_Import_Orchestrator::normalize_canonical_url( '/relative/path' ), 'Relative path rejected' );
assert_is_wp_error( GDTG_Import_Orchestrator::normalize_canonical_url( 'example.com' ), 'No-scheme rejected' );
assert_is_wp_error( GDTG_Import_Orchestrator::normalize_canonical_url( 'data:text/html,<h1>hi</h1>' ), 'data: rejected' );


suite( 'normalize_bulk_row_options: flat SEO fields preserve sibling metadata' );

$row_seo_merge = array(
	'source'          => 'https://docs.google.com/document/d/abc123/edit',
	'metadata'        => '{"seo":{"description":"keep","canonical":"https://example.com"}}',
	'seo_title'       => 'New Title',
);
$result_seo_merge = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_seo_merge );
assert_not_wp_error( $result_seo_merge, 'Bulk row with mixed SEO data normalizes successfully' );
assert_equals( 'New Title', $result_seo_merge['post_meta']['seo']['title'], 'Flat SEO title applied' );
assert_equals( 'keep', $result_seo_merge['post_meta']['seo']['description'], 'Existing SEO description preserved' );
assert_equals( 'https://example.com', $result_seo_merge['post_meta']['seo']['canonical'], 'Existing SEO canonical preserved' );

suite( 'REST normalize_import_options: flat SEO fields preserve sibling metadata and can clear taxonomies' );

if ( ! class_exists( 'GDTG_Test_REST_Request' ) ) {
	class GDTG_Test_REST_Request {
		private $params;
		public function __construct( $params ) { $this->params = $params; }
		public function has_param( $key ) { return array_key_exists( $key, $this->params ); }
		public function get_param( $key ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null; }
		public function get_header( $key ) { return null; }
	}
}

$rest = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_import_options' );
$method->setAccessible( true );
$rest_result = $method->invoke( $rest, new GDTG_Test_REST_Request( array(
	'post_meta'        => array( 'seo' => array( 'description' => 'keep', 'canonical' => 'https://example.com' ), 'categories' => array( 'old' ), 'tags' => array( 'oldtag' ) ),
	'seo_title'        => 'New REST Title',
	'categories'       => '',
	'tags'             => array(),
) ) );
assert_equals( 'New REST Title', $rest_result['post_meta']['seo']['title'], 'REST SEO title applied' );
assert_equals( 'keep', $rest_result['post_meta']['seo']['description'], 'REST SEO description preserved' );
assert_equals( array(), $rest_result['post_meta']['categories'], 'REST empty categories clears existing taxonomy' );
assert_equals( array(), $rest_result['post_meta']['tags'], 'REST empty tags clears existing taxonomy' );


suite( 'REST normalize_import_options: promotes flat SEO keys from within post_meta' );

$rest_flat = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_flat = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_import_options' );
$method_flat->setAccessible( true );
$flat_result = $method_flat->invoke( $rest_flat, new GDTG_Test_REST_Request( array(
	'post_meta'        => array(
		'seo_title'       => 'Sidebar Title',
		'seo_description' => 'Sidebar Description',
		'focus_keyword'   => 'sidebar keyword',
		'canonical_url'   => 'https://example.com/sidebar',
		'excerpt'         => 'Sidebar excerpt',
	),
) ) );
assert_not_wp_error( $flat_result, 'Sidebar-shaped post_meta normalizes successfully' );
assert_equals( 'Sidebar Title', $flat_result['post_meta']['seo']['title'], 'Flat seo_title promoted to seo.title' );
assert_equals( 'Sidebar Description', $flat_result['post_meta']['seo']['description'], 'Flat seo_description promoted to seo.description' );
assert_equals( 'sidebar keyword', $flat_result['post_meta']['seo']['focus_keyword'], 'Flat focus_keyword promoted to seo.focus_keyword' );
assert_equals( 'https://example.com/sidebar', $flat_result['post_meta']['seo']['canonical'], 'Flat canonical_url promoted to seo.canonical' );
assert_true( ! isset( $flat_result['post_meta']['seo_title'] ), 'Flat seo_title removed from post_meta' );
assert_true( ! isset( $flat_result['post_meta']['canonical_url'] ), 'Flat canonical_url removed from post_meta' );

suite( 'REST normalize_import_options: accepts post_meta as JSON string' );

$rest_json = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_json = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_import_options' );
$method_json->setAccessible( true );
$json_result = $method_json->invoke( $rest_json, new GDTG_Test_REST_Request( array(
	'post_meta' => '{"excerpt":"JSON excerpt","seo_title":"JSON SEO Title"}',
) ) );
assert_not_wp_error( $json_result, 'JSON-string post_meta normalizes successfully' );
assert_equals( 'JSON excerpt', $json_result['post_meta']['excerpt'], 'Excerpt from JSON post_meta preserved' );
assert_equals( 'JSON SEO Title', $json_result['post_meta']['seo']['title'], 'SEO title from JSON post_meta promoted' );

suite( 'REST normalize_import_options: rejects invalid JSON string post_meta' );

$rest_bad_json = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_bad = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_import_options' );
$method_bad->setAccessible( true );
$bad_json_result = $method_bad->invoke( $rest_bad_json, new GDTG_Test_REST_Request( array(
	'post_meta' => 'not-valid-json{',
) ) );
assert_is_wp_error( $bad_json_result, 'Invalid JSON post_meta returns WP_Error' );
assert_equals( 'gdtg_invalid_metadata_json', $bad_json_result->get_error_code(), 'Error code is gdtg_invalid_metadata_json' );

suite( 'REST normalize_overrides: explicit heading_demotion=0 survives presence check' );

$rest_zero = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_zero = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_overrides' );
$method_zero->setAccessible( true );
$zero_result = $method_zero->invoke( $rest_zero, new GDTG_Test_REST_Request( array(
	'heading_demotion' => 0,
) ) );
assert_true( array_key_exists( 'heading_demotion', $zero_result ), 'Explicit heading_demotion=0 key is present' );
assert_equals( 0, $zero_result['heading_demotion'], 'Explicit heading_demotion=0 has value 0' );

suite( 'REST normalize_overrides: absent params produce empty overrides' );

$rest_absent = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_absent = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_overrides' );
$method_absent->setAccessible( true );
$absent_result = $method_absent->invoke( $rest_absent, new GDTG_Test_REST_Request( array() ) );
assert_equals( array(), $absent_result, 'No override params produces empty array' );

suite( 'REST normalize_overrides: explicit min_heading_level=1 survives' );

$rest_mhl = new GDTG_REST_Endpoints( new class {
	public function add_action() {}
} );
$method_mhl = new ReflectionMethod( 'GDTG_REST_Endpoints', 'normalize_overrides' );
$method_mhl->setAccessible( true );
$mhl_result = $method_mhl->invoke( $rest_mhl, new GDTG_Test_REST_Request( array(
	'min_heading_level' => 1,
) ) );
assert_true( array_key_exists( 'min_heading_level', $mhl_result ), 'Explicit min_heading_level=1 key is present' );
assert_equals( 1, $mhl_result['min_heading_level'], 'Explicit min_heading_level=1 has value 1' );
suite( 'write_to_existing_post: batch commit applies post meta fields' );

global $mock_posts;
$mock_posts = array(
	99 => (object) array( 'ID' => 99, 'post_title' => 'Auto Draft', 'post_status' => 'draft', 'post_content' => 'old' ),
);
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		global $mock_posts;
		return isset( $mock_posts[ $post_id ] ) ? $mock_posts[ $post_id ] : null;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr, $wp_error = false ) {
		global $mock_posts;
		$mock_posts[ $postarr['ID'] ] = (object) array_merge( (array) $mock_posts[ $postarr['ID'] ], $postarr );
		return $postarr['ID'];
	}
}
global $mock_user_caps;
$mock_user_caps = array();

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$args ) {
		global $mock_user_caps;
		// Check post-specific key first (e.g., 'edit_post:10').
		if ( ! empty( $args ) ) {
			$specific = $cap . ':' . $args[0];
			if ( array_key_exists( $specific, $mock_user_caps ) ) {
				return $mock_user_caps[ $specific ];
			}
		}
		// Then check capability key.
		if ( array_key_exists( $cap, $mock_user_caps ) ) {
			return $mock_user_caps[ $cap ];
		}
		// Default: allow.
		return true;
	}
}
$write_method = new ReflectionMethod( 'GDTG_REST_Endpoints', 'write_to_existing_post' );
$write_method->setAccessible( true );
$write_method->invoke( $rest, 99, '<p>new</p>', array( 'overwrite' => true, 'import_as_draft' => false, 'post_meta' => array( 'slug' => 'batch-slug', 'excerpt' => 'batch excerpt' ) ), 'Batch Title' );
assert_equals( 'batch-slug', $mock_posts[99]->post_name, 'Batch commit writes slug' );
assert_equals( 'batch excerpt', $mock_posts[99]->post_excerpt, 'Batch commit writes excerpt' );


suite( 'normalize_bulk_row_options: accepts acf_json and meta_json aliases' );

$row_aliases = array(
	'source'    => 'https://docs.google.com/document/d/abc123/edit',
	'acf_json'  => '{"field_alias":"val_a"}',
	'meta_json' => '{"custom_alias":"val_m"}',
);
$result_alias = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_aliases );
assert_not_wp_error( $result_alias, 'acf_json and meta_json aliases normalize successfully' );
assert_equals( 'val_a', $result_alias['post_meta']['acf']['field_alias'], 'ACF field from acf_json alias' );
assert_equals( 'val_m', $result_alias['post_meta']['meta']['custom_alias'], 'Meta field from meta_json alias' );

suite( 'normalize_bulk_row_options: canonical acf/meta wins over acf_json/meta_json' );

$row_both = array(
	'source'    => 'https://docs.google.com/document/d/abc123/edit',
	'acf'       => '{"field_canon":"wins"}',
	'acf_json'  => '{"field_alias":"loses"}',
	'meta'      => '{"key_canon":"wins"}',
	'meta_json' => '{"key_alias":"loses"}',
);
$result_both = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_both );
assert_not_wp_error( $result_both, 'Both canonical and alias fields normalize successfully' );
assert_equals( 'wins', $result_both['post_meta']['acf']['field_canon'], 'Canonical acf wins over acf_json' );
assert_equals( 'wins', $result_both['post_meta']['meta']['key_canon'], 'Canonical meta wins over meta_json' );

suite( 'normalize_bulk_row_options: array categories and tags accepted' );

$row_arr_tax = array(
	'source'     => 'https://docs.google.com/document/d/abc123/edit',
	'categories' => array( 'cat-arr-1', 'cat-arr-2' ),
	'tags'       => array( 'tag-arr-1' ),
);
$result_arr_tax = GDTG_Import_Orchestrator::normalize_bulk_row_options( $row_arr_tax );
assert_not_wp_error( $result_arr_tax, 'Array categories and tags normalize successfully' );
assert_equals( 2, count( $result_arr_tax['post_meta']['categories'] ), '2 categories from array input' );
assert_equals( 1, count( $result_arr_tax['post_meta']['tags'] ), '1 tag from array input' );

// ═══════════════════════════════════════════════════════════════════
// Additional WP stubs needed for handler tests.
// ═══════════════════════════════════════════════════════════════════

if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
	define( 'UPLOAD_ERR_NO_FILE', 4 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const CREATABLE = 'POST';
		const READABLE   = 'GET';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

// Mock transient storage for job tests.
global $mock_transients;
$mock_transients = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration ) {
		global $mock_transients;
		$mock_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $mock_transients;
		return isset( $mock_transients[ $key ] ) ? $mock_transients[ $key ] : false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return date( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		global $mock_posts;
		return isset( $mock_posts[ $post_id ] ) ? $mock_posts[ $post_id ]->post_status : 'draft';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, $context = 'display' ) {
		return 'https://example.com/wp-admin/post.php?post=' . intval( $post_id ) . '&action=edit';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public $args  = array();

		public function __construct( $args = array() ) {
			global $mock_linked_post_ids;
			$this->args  = $args;
			$this->posts = isset( $mock_linked_post_ids ) ? $mock_linked_post_ids : array();
		}
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		global $mock_imported_posts;
		return isset( $mock_imported_posts ) ? $mock_imported_posts : array();
	}
}

if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata() {}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $postid = 0, $force_delete = false ) {
		global $mock_posts;
		if ( isset( $mock_posts[ $postid ] ) ) {
			unset( $mock_posts[ $postid ] );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		return 'https://example.com/wp-content/uploads/2026/01/image-' . intval( $attachment_id ) . '.jpg';
	}
}

if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
	function wp_raise_memory_limit( $context ) {}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $mock_posts;
		$id = max( array_keys( $mock_posts ) ) + 1;
		$mock_posts[ $id ] = (object) array_merge(
			array( 'ID' => $id, 'post_title' => '', 'post_status' => 'draft', 'post_content' => '', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' ),
			$postarr
		);
		return $id;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '' ) {
		return tempnam( sys_get_temp_dir(), $filename );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) { return false; }
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		return array( 'ext' => $ext, 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
		return array( 'ext' => 'docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'proper_filename' => $filename );
	}
}

// ─── Mock GDTG_API to intercept Google API calls ─────────────────
// Must be defined before orchestrator loads it.

global $mock_gdtg_api_doc;
$mock_gdtg_api_doc = null;

global $mock_gdtg_api_error;
$mock_gdtg_api_error = null;

global $mock_gdtg_api_drive_meta;
$mock_gdtg_api_drive_meta = null;

global $mock_gdtg_api_drive_bytes;
$mock_gdtg_api_drive_bytes = null;

if ( ! class_exists( 'GDTG_API' ) ) {
	class GDTG_API {
		public function fetch_google_doc( $doc_id ) {
			global $mock_gdtg_api_doc, $mock_gdtg_api_error;
			if ( $mock_gdtg_api_error ) {
				return $mock_gdtg_api_error;
			}
			return $mock_gdtg_api_doc ? json_encode( $mock_gdtg_api_doc ) : null;
		}
		public function get_drive_file_metadata( $file_id ) {
			global $mock_gdtg_api_drive_meta, $mock_gdtg_api_error;
			if ( $mock_gdtg_api_error ) {
				return $mock_gdtg_api_error;
			}
			return $mock_gdtg_api_drive_meta;
		}
		public function fetch_drive_file( $file_id ) {
			global $mock_gdtg_api_drive_bytes, $mock_gdtg_api_error;
			if ( $mock_gdtg_api_error ) {
				return $mock_gdtg_api_error;
			}
			return $mock_gdtg_api_drive_bytes;
		}
	}
}

// ─── Mock GDTG_Sideloader to avoid real HTTP calls ────────────────

if ( ! class_exists( 'GDTG_Sideloader' ) ) {
	class GDTG_Sideloader {
		public static function sideload( $url, $post_id, $alt = '', $options = array() ) {
			return 100; // Fake attachment ID.
		}
		public static function sideload_from_bytes( $bytes, $filename, $post_id, $alt = '', $options = array() ) {
			return 100;
		}
	}
}


// ─── Mock GDTG_Docx_Parser ────────────────────────────────────────

if ( ! class_exists( 'GDTG_Docx_Parser' ) ) {
	class GDTG_Docx_Parser {
		private $nodes = array();
		private $image_count = 0;
		public function __construct( $file_path, $post_id = 0, $options = array() ) {
			// Minimal parse: produce a single paragraph node from the docx.
			$this->nodes = array( new GDTG_Doc_Node( 'paragraph', 'Parsed docx content', array() ) );
		}
		public function parse_nodes() {
			return $this->nodes;
		}
		public function get_image_count() {
			return $this->image_count;
		}
	}
}

// ─── Mock GDTG_Zip_Validator ──────────────────────────────────────

if ( ! class_exists( 'GDTG_Zip_Validator' ) ) {
	class GDTG_Zip_Validator {
		public static function validate( $file_path ) {
			global $mock_zip_validation_error;
			if ( ! empty( $mock_zip_validation_error ) ) {
				return $mock_zip_validation_error;
			}
			return true;
		}
	}
}

global $mock_zip_validation_error;
$mock_zip_validation_error = null;

// ─── Permission tests ─────────────────────────────────────────────

suite( 'check_permissions: returns true when no post_id (new post creation)' );

global $mock_user_caps;
$mock_user_caps = array( 'edit_posts' => true );

$perm_rest = new GDTG_REST_Endpoints( new class { public function add_action() {} } );
$perm_req  = new GDTG_Test_REST_Request( array( 'post_id' => 0 ) );
$perm_result = $perm_rest->check_permissions( $perm_req );
assert_true( $perm_result === true, 'No post_id with edit_posts capability returns true' );

suite( 'check_bulk_permissions: requires edit_posts capability' );

$bulk_result = $perm_rest->check_bulk_permissions( new GDTG_Test_REST_Request( array() ) );
assert_true( $bulk_result === true, 'User with edit_posts can bulk import' );

suite( 'check_permissions: post not found returns 404 WP_Error' );

global $mock_posts;
$mock_posts = array(
	10 => (object) array( 'ID' => 10, 'post_title' => 'Test Post', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => 'test-post', 'post_excerpt' => '' ),
);
$not_found_req  = new GDTG_Test_REST_Request( array( 'post_id' => 999 ) );
$not_found_result = $perm_rest->check_permissions( $not_found_req );
assert_is_wp_error( $not_found_result, 'Non-existent post_id returns WP_Error' );
assert_equals( 'gdtg_post_not_found', $not_found_result->get_error_code(), 'Error code is gdtg_post_not_found' );

suite( 'check_permissions: unsupported post type returns 403' );

$mock_posts[ 20 ] = (object) array( 'ID' => 20, 'post_title' => 'Product', 'post_status' => 'publish', 'post_content' => '', 'post_type' => 'product', 'post_name' => '', 'post_excerpt' => '' );
$bad_type_req    = new GDTG_Test_REST_Request( array( 'post_id' => 20 ) );
$bad_type_result = $perm_rest->check_permissions( $bad_type_req );
assert_is_wp_error( $bad_type_result, 'Unsupported post type returns WP_Error' );
assert_equals( 'gdtg_unsupported_post_type', $bad_type_result->get_error_code(), 'Error code is gdtg_unsupported_post_type' );

suite( 'check_permissions: user without edit_post cap for specific post returns 403' );

$mock_user_caps = array( 'edit_post:10' => false );
$no_cap_req    = new GDTG_Test_REST_Request( array( 'post_id' => 10 ) );
$no_cap_result = $perm_rest->check_permissions( $no_cap_req );
assert_is_wp_error( $no_cap_result, 'User without edit_post for post returns WP_Error' );
assert_equals( 'gdtg_forbidden', $no_cap_result->get_error_code(), 'Error code is gdtg_forbidden' );

suite( 'check_permissions: user without edit_posts cap cannot create new post' );

$mock_user_caps = array( 'edit_posts' => false );
$no_create_req    = new GDTG_Test_REST_Request( array( 'post_id' => 0 ) );
$no_create_result = $perm_rest->check_permissions( $no_create_req );
assert_is_wp_error( $no_create_result, 'User without edit_posts cannot create post' );
assert_equals( 'gdtg_forbidden', $no_create_result->get_error_code(), 'Error code is gdtg_forbidden' );

suite( 'check_bulk_permissions: user without edit_posts cannot bulk import' );

$no_bulk_result = $perm_rest->check_bulk_permissions( new GDTG_Test_REST_Request( array() ) );
assert_is_wp_error( $no_bulk_result, 'User without edit_posts cannot bulk import' );
assert_equals( 'gdtg_forbidden', $no_bulk_result->get_error_code(), 'Error code is gdtg_forbidden' );

suite( 'check_job_permissions: job owned by another user returns 403' );

global $mock_transients;
$mock_transients[ 'gdtg_import_job_other_user' ] = array(
	'job_id'      => 'other_user',
	'status'      => 'pending',
	'post_id'     => 10,
	'user_id'     => 999,
	'image_done'  => 0,
	'image_total' => 0,
);
$other_user_req    = new GDTG_Test_REST_Request( array( 'job_id' => 'other_user' ) );
$other_user_result = $perm_rest->check_job_permissions( $other_user_req );
assert_is_wp_error( $other_user_result, 'Job owned by another user returns WP_Error' );
assert_equals( 'gdtg_forbidden', $other_user_result->get_error_code(), 'Error code is gdtg_forbidden' );

suite( 'check_permissions: valid post with capability returns true (reset)' );

$mock_user_caps = array();
$valid_req    = new GDTG_Test_REST_Request( array( 'post_id' => 10 ) );
$valid_result = $perm_rest->check_permissions( $valid_req );
assert_true( $valid_result === true, 'Valid post_id with capability returns true' );

// ═══════════════════════════════════════════════════════════════════
// handle_import tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_import: invalid doc_id returns 400' );

$import_rest = new GDTG_REST_Endpoints( new class { public function add_action() {} } );
$bad_doc_req = new GDTG_Test_REST_Request( array( 'doc_id' => '' ) );
// Empty doc_id — but the route validation requires non-empty, so we test
// the parse_source_reference path with an invalid URL.
$bad_url_req = new GDTG_Test_REST_Request( array( 'doc_id' => 'ftp://not-valid' ) );
$bad_url_resp = $import_rest->handle_import( $bad_url_req );
assert_true( $bad_url_resp instanceof WP_REST_Response, 'Invalid source returns WP_REST_Response' );
assert_equals( 400, $bad_url_resp->status, 'Invalid source returns 400' );

suite( 'handle_import: sheets URL returns 400' );

$sheets_req  = new GDTG_Test_REST_Request( array( 'doc_id' => 'https://docs.google.com/spreadsheets/d/abc123/edit' ) );
$sheets_resp = $import_rest->handle_import( $sheets_req );
assert_equals( 400, $sheets_resp->status, 'Sheets URL returns 400' );

suite( 'handle_import: valid Google Doc URL returns 200 with mock data' );

global $mock_gdtg_api_doc;
$mock_gdtg_api_doc = array(
	'title'   => 'Test Doc',
	'body'    => array( 'content' => array(
		array( 'paragraph' => array( 'elements' => array( array( 'textRun' => array( 'content' => 'Hello world' ) ) ) ) ),
	) ),
);
$good_req  = new GDTG_Test_REST_Request( array( 'doc_id' => 'https://docs.google.com/document/d/abc123/edit' ) );
$good_resp = $import_rest->handle_import( $good_req );
assert_true( $good_resp instanceof WP_REST_Response, 'Valid import returns WP_REST_Response' );
assert_equals( 200, $good_resp->status, 'Valid import returns 200' );
assert_true( $good_resp->data['success'], 'Import response has success=true' );
assert_true( $good_resp->data['post_id'] > 0, 'Import response has a post_id' );
assert_equals( 'Test Doc', $good_resp->data['title'], 'Import response has correct title' );

suite( 'handle_import: orchestrator error returns 400' );

global $mock_gdtg_api_error;
$mock_gdtg_api_error = new WP_Error( 'gdtg_api_error', 'API quota exceeded' );
$err_req  = new GDTG_Test_REST_Request( array( 'doc_id' => 'rawDocId123' ) );
$err_resp = $import_rest->handle_import( $err_req );
assert_equals( 400, $err_resp->status, 'Orchestrator error returns 400' );
assert_true( ! $err_resp->data['success'], 'Error response has success=false' );
$mock_gdtg_api_error = null;

suite( 'handle_import: raw doc ID works like a URL' );

$mock_gdtg_api_doc = array(
	'title' => 'Raw ID Doc',
	'body'  => array( 'content' => array(
		array( 'paragraph' => array( 'elements' => array( array( 'textRun' => array( 'content' => 'Content' ) ) ) ) ),
	) ),
);
$raw_id_req  = new GDTG_Test_REST_Request( array( 'doc_id' => 'aBcDeFg123' ) );
$raw_id_resp = $import_rest->handle_import( $raw_id_req );
assert_equals( 200, $raw_id_resp->status, 'Raw doc ID returns 200' );
assert_true( $raw_id_resp->data['success'], 'Raw doc ID import succeeds' );

// ═══════════════════════════════════════════════════════════════════
// handle_upload_docx tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_upload_docx: no file uploaded returns 400' );

// Stub get_file_params on GDTG_Test_REST_Request.
class GDTG_Test_REST_Request_With_Files extends GDTG_Test_REST_Request {
	private $files;
	public function __construct( $params, $files = array() ) {
		parent::__construct( $params );
		$this->files = $files;
	}
	public function get_file_params() {
		return $this->files;
	}
}

$no_file_req  = new GDTG_Test_REST_Request_With_Files( array(), array() );
$no_file_resp = $import_rest->handle_upload_docx( $no_file_req );
assert_equals( 400, $no_file_resp->status, 'No file returns 400' );
assert_equals( 'No file uploaded.', $no_file_resp->data['message'], 'No file message is correct' );

suite( 'handle_upload_docx: wrong extension returns 400' );

$tmp = tempnam( sys_get_temp_dir(), 'gdtg-test' );
file_put_contents( $tmp, 'fake content' );
$wrong_ext_req  = new GDTG_Test_REST_Request_With_Files(
	array(),
	array( 'file' => array(
		'name'     => 'document.pdf',
		'type'     => 'application/pdf',
		'tmp_name' => $tmp,
		'error'    => UPLOAD_ERR_OK,
		'size'     => 12,
	) )
);
$wrong_ext_resp = $import_rest->handle_upload_docx( $wrong_ext_req );
assert_equals( 400, $wrong_ext_resp->status, 'Wrong extension returns 400' );
assert_equals( 'Only .docx files are supported.', $wrong_ext_resp->data['message'], 'Wrong extension message' );
@unlink( $tmp );

suite( 'handle_upload_docx: upload error returns 400' );

$upload_err_req  = new GDTG_Test_REST_Request_With_Files(
	array(),
	array( 'file' => array(
		'name'     => 'document.docx',
		'type'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'tmp_name' => '',
		'error'    => UPLOAD_ERR_NO_FILE,
		'size'     => 0,
	) )
);
$upload_err_resp = $import_rest->handle_upload_docx( $upload_err_req );
assert_equals( 400, $upload_err_resp->status, 'Upload error returns 400' );

suite( 'handle_upload_docx: invalid ZIP structure returns 400' );

global $mock_zip_validation_error;
$mock_zip_validation_error = new WP_Error( 'gdtg_invalid_zip', 'Not a valid ZIP archive.' );
$tmp_bad_zip = tempnam( sys_get_temp_dir(), 'gdtg-test' );
$bad_zip = new ZipArchive();
if ( $bad_zip->open( $tmp_bad_zip, ZipArchive::OVERWRITE ) === true ) {
	$bad_zip->addFromString( 'garbage.txt', 'not a valid docx' );
	$bad_zip->close();
}
$bad_zip_req  = new GDTG_Test_REST_Request_With_Files(
	array(),
	array( 'file' => array(
		'name'     => 'document.docx',
		'type'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'tmp_name' => $tmp_bad_zip,
		'error'    => UPLOAD_ERR_OK,
		'size'     => 9,
	) )
);
$bad_zip_resp = $import_rest->handle_upload_docx( $bad_zip_req );
assert_equals( 400, $bad_zip_resp->status, 'Invalid ZIP returns 400' );
assert_equals( 'Not a valid ZIP archive.', $bad_zip_resp->data['message'], 'Invalid ZIP message' );
$mock_zip_validation_error = null;
// temp file already cleaned up by handler.

suite( 'handle_upload_docx: valid docx orchestrator error returns error status' );

// Create a minimal valid .docx (ZIP with word/document.xml).
$valid_docx = tempnam( sys_get_temp_dir(), 'gdtg-test-docx' );
$zip = new ZipArchive();
if ( $zip->open( $valid_docx, ZipArchive::OVERWRITE ) === true ) {
	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
	$zip->addFromString( 'word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>' );
	$zip->addFromString( 'word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>' );
	$zip->close();
}
$valid_docx_req = new GDTG_Test_REST_Request_With_Files(
	array(),
	array( 'file' => array(
		'name'     => 'test.docx',
		'type'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'tmp_name' => $valid_docx,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $valid_docx ),
	) )
);
$valid_docx_resp = $import_rest->handle_upload_docx( $valid_docx_req );
assert_true( $valid_docx_resp instanceof WP_REST_Response, 'Valid docx returns WP_REST_Response' );
assert_true( in_array( $valid_docx_resp->status, array( 200, 400 ), true ), 'Valid docx returns 200 or 400 (orchestrator result)' );
if ( 200 === $valid_docx_resp->status ) {
	assert_true( $valid_docx_resp->data['success'], 'Successful docx import has success=true' );
}
// Clean up if handler didn't.
if ( file_exists( $valid_docx ) ) {
	@unlink( $valid_docx );
}

// ═══════════════════════════════════════════════════════════════════
// handle_job_status tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_job_status: unknown job returns 404' );

global $mock_transients;
$mock_transients = array();

$job_rest     = new GDTG_REST_Endpoints( new class { public function add_action() {} } );
$unknown_job  = new GDTG_Test_REST_Request( array( 'job_id' => 'deadbeef' ) );
$unknown_resp = $job_rest->handle_job_status( $unknown_job );
assert_equals( 404, $unknown_resp->status, 'Unknown job returns 404' );

suite( 'handle_job_status: pending job returns progress info' );

$mock_transients[ 'gdtg_import_job_abc123' ] = array(
	'job_id'       => 'abc123',
	'status'       => 'pending',
	'image_done'   => 0,
	'image_total'  => 5,
	'post_id'      => 10,
	'message'      => '',
	'user_id'      => 1,
);
$pending_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'abc123' ) );
$pending_resp = $job_rest->handle_job_status( $pending_req );
assert_equals( 200, $pending_resp->status, 'Pending job returns 200' );
assert_equals( 'pending', $pending_resp->data['status'], 'Job status is pending' );
assert_equals( 0, $pending_resp->data['image_done'], 'No images done yet' );
assert_equals( 5, $pending_resp->data['image_total'], 'Total images is 5' );
assert_equals( 10, $pending_resp->data['post_id'], 'Post ID is 10' );

suite( 'handle_job_status: complete job returns edit_url' );

$mock_transients[ 'gdtg_import_job_complete1' ] = array(
	'job_id'       => 'complete1',
	'status'       => 'complete',
	'image_done'   => 3,
	'image_total'  => 3,
	'post_id'      => 10,
	'message'      => ' Import complete.',
	'user_id'      => 1,
);
$complete_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'complete1' ) );
$complete_resp = $job_rest->handle_job_status( $complete_req );
assert_equals( 200, $complete_resp->status, 'Complete job returns 200' );
assert_equals( 'complete', $complete_resp->data['status'], 'Job status is complete' );
assert_true( ! empty( $complete_resp->data['edit_url'] ), 'Complete job has edit_url' );

suite( 'handle_job_status: error job returns message' );

$mock_transients[ 'gdtg_import_job_err1' ] = array(
	'job_id'       => 'err1',
	'status'       => 'error',
	'image_done'   => 2,
	'image_total'  => 3,
	'post_id'      => 10,
	'message'      => 'Import failed: all images failed.',
	'user_id'      => 1,
);
$err_job_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'err1' ) );
$err_job_resp = $job_rest->handle_job_status( $err_job_req );
assert_equals( 200, $err_job_resp->status, 'Error job returns 200' );
assert_equals( 'error', $err_job_resp->data['status'], 'Job status is error' );
assert_equals( 'Import failed: all images failed.', $err_job_resp->data['message'], 'Error message preserved' );

suite( 'handle_job_status: job with meta_warnings includes them in response' );

$mock_transients[ 'gdtg_import_job_warn1' ] = array(
	'job_id'        => 'warn1',
	'status'        => 'complete',
	'image_done'    => 1,
	'image_total'   => 1,
	'post_id'       => 10,
	'message'       => ' Import complete.',
	'user_id'       => 1,
	'meta_warnings' => array( 'SEO title was empty', 'No featured image set' ),
);
$warn_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'warn1' ) );
$warn_resp = $job_rest->handle_job_status( $warn_req );
assert_true( isset( $warn_resp->data['warnings'] ), 'Warnings field is present' );
assert_equals( 2, count( $warn_resp->data['warnings'] ), 'Two warnings returned' );

// ═══════════════════════════════════════════════════════════════════
// handle_job_continue tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_job_continue: unknown job returns 404' );

$mock_transients = array();
$continue_unknown_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'nonexistent' ) );
$continue_unknown_resp = $job_rest->handle_job_continue( $continue_unknown_req );
assert_equals( 404, $continue_unknown_resp->status, 'Unknown job continue returns 404' );

suite( 'handle_job_continue: processing job with no remaining images completes' );

// Job with no image placeholders (empty nodes = no images to process).
$orch = new GDTG_Import_Orchestrator();
$empty_nodes = array( new GDTG_Doc_Node( 'paragraph', 'Hello', array() ) );
$mock_transients[ 'gdtg_import_job_noimgs' ] = array(
	'job_id'        => 'noimgs',
	'created_shell' => true,
	'doc_json'      => '{}',
	'post_id'       => 10,
	'options'       => array( 'output_mode' => 'gutenberg', 'overwrite' => true, 'import_as_draft' => false, 'import_images' => true, 'import_tables' => true, 'post_meta' => array() ),
	'nodes'         => $orch->serialize_nodes( $empty_nodes ),
	'doc_title'     => 'No Images Doc',
	'user_id'       => 1,
	'image_total'   => 0,
	'image_done'    => 0,
	'image_failed'  => 0,
	'status'        => 'processing',
	'created_at'    => time(),
);
$continue_noimgs_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'noimgs' ) );
$continue_noimgs_resp = $job_rest->handle_job_continue( $continue_noimgs_req );
assert_equals( 200, $continue_noimgs_resp->status, 'No-images job continue returns 200' );
assert_equals( 'complete', $continue_noimgs_resp->data['status'], 'No-images job completes immediately' );
assert_true( $continue_noimgs_resp->data['post_id'] > 0, 'Completed job has post_id' );

suite( 'handle_job_continue: job with source_url images returns processing status' );

$img_node = new GDTG_Doc_Node( 'image', '', array( 'source_url' => 'https://example.com/img.jpg', 'alt' => 'test' ) );
$nodes_with_img = array( $img_node );
$mock_transients[ 'gdtg_import_job_withimgs' ] = array(
	'job_id'        => 'withimgs',
	'created_shell' => false,
	'doc_json'      => '{}',
	'post_id'       => 10,
	'options'       => array( 'output_mode' => 'gutenberg', 'overwrite' => true, 'import_as_draft' => false, 'import_images' => true, 'import_tables' => true ),
	'nodes'         => $orch->serialize_nodes( $nodes_with_img ),
	'doc_title'     => 'Images Doc',
	'user_id'       => 1,
	'image_total'   => 1,
	'image_done'    => 0,
	'image_failed'  => 0,
	'status'        => 'processing',
	'created_at'    => time(),
);
// After sideloading, source_url is removed so image is "processed".
// process_image_placeholders calls GDTG_Sideloader::sideload which is mocked to return 100.
// wp_get_attachment_url is stubbed to return a URL.
// After processing, the image node's source_url is unset → collect_image_placeholders finds none → job completes.
$continue_img_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'withimgs' ) );
$continue_img_resp = $job_rest->handle_job_continue( $continue_img_req );
assert_equals( 200, $continue_img_resp->status, 'Image job continue returns 200' );
// Since sideload mock returns truthy (100), the image is processed, source_url removed,
// no remaining placeholders → job should complete.
assert_equals( 'complete', $continue_img_resp->data['status'], 'Image job completes after processing' );

suite( 'handle_job_continue: all images failed aborts import' );

// Override sideloader to return falsy (simulating all failures).
// We can't redefine the class, but we can make the mock sideload return 0.
// Since GDTG_Sideloader::sideload is already defined, we need a different approach.
// For this test, we create nodes where images have empty source_url (already failed),
// so collect_image_placeholders finds nothing and job completes.
$already_failed_node = new GDTG_Doc_Node( 'image', '', array( 'source_url' => '', 'alt' => 'failed [import failed]' ) );
$mock_transients[ 'gdtg_import_job_allfail' ] = array(
	'job_id'        => 'allfail',
	'created_shell' => true,
	'doc_json'      => '{}',
	'post_id'       => 10,
	'options'       => array( 'output_mode' => 'gutenberg', 'overwrite' => true, 'import_as_draft' => false, 'import_images' => true, 'import_tables' => true ),
	'nodes'         => $orch->serialize_nodes( array( $already_failed_node ) ),
	'doc_title'     => 'All Fail Doc',
	'user_id'       => 1,
	'image_total'   => 1,
	'image_done'    => 1,
	'image_failed'  => 1,
	'status'        => 'processing',
	'created_at'    => time(),
);
$allfail_req  = new GDTG_Test_REST_Request( array( 'job_id' => 'allfail' ) );
$allfail_resp = $job_rest->handle_job_continue( $allfail_req );
assert_equals( 500, $allfail_resp->status, 'All-failed images returns 500' );
assert_equals( 'error', $allfail_resp->data['status'], 'All-failed job status is error' );

// ═══════════════════════════════════════════════════════════════════
// handle_import_bulk tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_import_bulk: oversized list returns 400' );

$oversized_rows = array();
for ( $i = 0; $i < 101; $i++ ) {
	$oversized_rows[] = array( 'source' => 'https://docs.google.com/document/d/abc' . $i . '/edit' );
}
$oversized_req  = new GDTG_Test_REST_Request( array( 'rows' => $oversized_rows, 'dry_run' => false ) );
$oversized_resp = $import_rest->handle_import_bulk( $oversized_req );
assert_equals( 400, $oversized_resp->status, 'Over 100 rows returns 400' );
assert_true( false !== strpos( $oversized_resp->data['message'], '100' ), 'Oversized message mentions 100 row limit' );

suite( 'handle_import_bulk: empty source rows are flagged' );

$empty_source_rows = array(
	array( 'source' => '' ),
	array( 'source' => 'https://docs.google.com/document/d/abc123/edit' ),
);
$mock_gdtg_api_doc = array(
	'title' => 'Bulk Doc',
	'body'  => array( 'content' => array(
		array( 'paragraph' => array( 'elements' => array( array( 'textRun' => array( 'content' => 'Bulk content' ) ) ) ) ),
	) ),
);
$mock_gdtg_api_error = null;
$empty_src_req  = new GDTG_Test_REST_Request( array( 'rows' => $empty_source_rows, 'dry_run' => false ) );
$empty_src_resp = $import_rest->handle_import_bulk( $empty_src_req );
assert_equals( 200, $empty_src_resp->status, 'Mixed rows returns 200' );
assert_true( ! $empty_src_resp->data['success'], 'Response indicates partial failure' );
assert_equals( 1, $empty_src_resp->data['summary']['failed'], '1 failed row (empty source)' );
assert_equals( 1, $empty_src_resp->data['summary']['success'], '1 successful row' );
assert_equals( 'Empty or missing "source" field.', $empty_src_resp->data['results'][0]['message'], 'Empty source message correct' );

suite( 'handle_import_bulk: dry_run validates without importing' );

$dry_run_rows = array(
	array( 'source' => 'https://docs.google.com/document/d/abc123/edit' ),
	array( 'source' => 'rawDocId456' ),
);
$dry_run_req  = new GDTG_Test_REST_Request( array( 'rows' => $dry_run_rows, 'dry_run' => true ) );
$dry_run_resp = $import_rest->handle_import_bulk( $dry_run_req );
assert_equals( 200, $dry_run_resp->status, 'Dry run returns 200' );
assert_true( $dry_run_resp->data['success'], 'Dry run succeeds' );
assert_equals( 2, $dry_run_resp->data['summary']['success'], 'Both rows validated' );
assert_true( false !== strpos( $dry_run_resp->data['results'][0]['message'], 'Dry Run' ), 'Dry run message correct' );

suite( 'handle_import_bulk: invalid metadata JSON in row fails that row' );

$bad_meta_rows = array(
	array( 'source' => 'https://docs.google.com/document/d/abc123/edit', 'metadata' => 'not-json{' ),
);
$bad_meta_req  = new GDTG_Test_REST_Request( array( 'rows' => $bad_meta_rows, 'dry_run' => false ) );
$bad_meta_resp = $import_rest->handle_import_bulk( $bad_meta_req );
assert_equals( 200, $bad_meta_resp->status, 'Bad metadata returns 200 (row-level failure)' );
assert_true( ! $bad_meta_resp->data['success'], 'Bad metadata row fails' );
assert_equals( 1, $bad_meta_resp->data['summary']['failed'], '1 failed row' );

suite( 'handle_import_bulk: local docx source rejected by REST' );

$local_rows = array(
	array( 'source' => '/path/to/local.docx' ),
);
$local_req  = new GDTG_Test_REST_Request( array( 'rows' => $local_rows, 'dry_run' => false ) );
$local_resp = $import_rest->handle_import_bulk( $local_req );
assert_equals( 200, $local_resp->status, 'Local docx row returns 200 (row-level failure)' );
assert_true( ! $local_resp->data['success'], 'Local docx row fails' );
assert_true( false !== strpos( $local_resp->data['results'][0]['message'], 'REST bulk accepts' ), 'Local docx rejection message' );

// ═══════════════════════════════════════════════════════════════════
// handle_sync tests.
// ═══════════════════════════════════════════════════════════════════

suite( 'handle_sync: post not linked returns 400' );

$sync_rest = new GDTG_REST_Endpoints( new class { public function add_action() {} } );
$mock_posts[ 50 ] = (object) array( 'ID' => 50, 'post_title' => 'Unlinked', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => 'unlinked', 'post_excerpt' => '' );
global $mock_post_meta;
$mock_post_meta = array(); // Clear all post meta.
$unlinked_req  = new GDTG_Test_REST_Request( array( 'post_id' => 50 ) );
$unlinked_resp = $sync_rest->handle_sync( $unlinked_req );
assert_equals( 400, $unlinked_resp->status, 'Unlinked post returns 400' );
assert_true( false !== strpos( $unlinked_resp->data['message'], 'not linked' ), 'Unlinked message correct' );

suite( 'handle_sync: docx_upload source type returns 400' );

$mock_post_meta[ 51 ] = array(
	'_gdtg_source_type' => 'docx_upload',
	'_gdtg_source_id'   => '',
	'_gdtg_source_name' => 'report.docx',
);
$mock_posts[ 51 ] = (object) array( 'ID' => 51, 'post_title' => 'Docx Upload', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$docx_sync_req  = new GDTG_Test_REST_Request( array( 'post_id' => 51 ) );
$docx_sync_resp = $sync_rest->handle_sync( $docx_sync_req );
assert_equals( 400, $docx_sync_resp->status, 'Docx upload source returns 400' );
assert_true( false !== strpos( $docx_sync_resp->data['message'], 'local docx upload' ), 'Docx upload message correct' );

suite( 'handle_sync: missing baseline hash without force returns 409' );

$mock_post_meta[ 52 ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'abc123',
	'_gdtg_source_name' => 'My Doc',
	'_gdtg_last_content_hash' => '',
);
$mock_posts[ 52 ] = (object) array( 'ID' => 52, 'post_title' => 'No Hash', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$no_hash_req  = new GDTG_Test_REST_Request( array( 'post_id' => 52, 'force' => false ) );
$no_hash_resp = $sync_rest->handle_sync( $no_hash_req );
assert_equals( 409, $no_hash_resp->status, 'Missing baseline hash returns 409' );
assert_true( false !== strpos( $no_hash_resp->data['message'], 'No baseline content hash' ), 'Missing hash message correct' );

suite( 'handle_sync: content changed without force returns 409' );

$mock_post_meta[ 53 ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'abc123',
	'_gdtg_source_name' => 'My Doc',
	'_gdtg_last_content_hash' => md5( '<p>old content</p>' ),
);
$mock_posts[ 53 ] = (object) array( 'ID' => 53, 'post_title' => 'Changed', 'post_status' => 'publish', 'post_content' => '<p>new content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$changed_req  = new GDTG_Test_REST_Request( array( 'post_id' => 53, 'force' => false ) );
$changed_resp = $sync_rest->handle_sync( $changed_req );
assert_equals( 409, $changed_resp->status, 'Content changed without force returns 409' );
assert_true( false !== strpos( $changed_resp->data['message'], 'Conflict Detected' ), 'Conflict message correct' );

suite( 'handle_sync: missing hash with force proceeds' );

$mock_post_meta[ 54 ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'abc123',
	'_gdtg_source_name' => 'My Doc',
	'_gdtg_last_content_hash' => '',
);
$mock_posts[ 54 ] = (object) array( 'ID' => 54, 'post_title' => 'Force Sync', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_gdtg_api_doc = array(
	'title' => 'Force Sync Doc',
	'body'  => array( 'content' => array(
		array( 'paragraph' => array( 'elements' => array( array( 'textRun' => array( 'content' => 'Synced' ) ) ) ) ),
	) ),
);
$mock_gdtg_api_error = null;
$force_req  = new GDTG_Test_REST_Request( array( 'post_id' => 54, 'force' => true ) );
$force_resp = $sync_rest->handle_sync( $force_req );
assert_true( $force_resp instanceof WP_REST_Response, 'Force sync returns WP_REST_Response' );
assert_equals( 200, $force_resp->status, 'Force sync returns 200' );
assert_true( $force_resp->data['success'], 'Force sync succeeds' );

suite( 'handle_sync: content unchanged proceeds without force' );

$unchanged_content = '<p>unchanged</p>';
$mock_post_meta[ 55 ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'abc123',
	'_gdtg_source_name' => 'Unchanged Doc',
	'_gdtg_last_content_hash' => md5( $unchanged_content ),
);
$mock_posts[ 55 ] = (object) array( 'ID' => 55, 'post_title' => 'Unchanged', 'post_status' => 'publish', 'post_content' => $unchanged_content, 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_gdtg_api_doc = array(
	'title' => 'Unchanged Doc',
	'body'  => array( 'content' => array(
		array( 'paragraph' => array( 'elements' => array( array( 'textRun' => array( 'content' => 'Same' ) ) ) ) ),
	) ),
);
$unchanged_req  = new GDTG_Test_REST_Request( array( 'post_id' => 55, 'force' => false ) );
$unchanged_resp = $sync_rest->handle_sync( $unchanged_req );
assert_equals( 200, $unchanged_resp->status, 'Unchanged content sync returns 200' );
assert_true( $unchanged_resp->data['success'], 'Unchanged content sync succeeds' );

suite( 'handle_sync: docx source returns 400' );

$mock_post_meta[ 56 ] = array(
	'_gdtg_source_type' => 'docx',
	'_gdtg_source_id'   => 'abc123',
	'_gdtg_source_name' => 'Some Doc',
	'_gdtg_last_content_hash' => md5( '<p>content</p>' ),
);
$mock_posts[ 56 ] = (object) array( 'ID' => 56, 'post_title' => 'Docx Source', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$docx_src_req  = new GDTG_Test_REST_Request( array( 'post_id' => 56 ) );
$docx_src_resp = $sync_rest->handle_sync( $docx_src_req );
assert_equals( 400, $docx_src_resp->status, 'Unsupported source type returns 400' );
assert_true( false !== strpos( $docx_src_resp->data['message'], 'Unsupported source type' ), 'Unsupported source message' );


suite( 'handle_sync_status: permission denied for specific post returns 403' );

$mock_user_caps = array( 'edit_post:57' => false );
$mock_posts[ 57 ] = (object) array( 'ID' => 57, 'post_title' => 'Denied', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$sync_status_denied = $sync_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 57 ) ) );
assert_equals( 403, $sync_status_denied->status, 'Permission denied sync status returns 403' );
assert_equals( 'Permission denied.', $sync_status_denied->data['message'], 'Permission denied sync status message' );

suite( 'handle_sync_status: unlinked post returns 404' );

$mock_user_caps = array( 'edit_post:58' => true );
$mock_posts[ 58 ] = (object) array( 'ID' => 58, 'post_title' => 'Unlinked Status', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_post_meta[ 58 ] = array();
$sync_status_unlinked = $sync_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 58 ) ) );
assert_equals( 404, $sync_status_unlinked->status, 'Unlinked sync status returns 404' );
assert_true( false !== strpos( $sync_status_unlinked->data['message'], 'not linked' ), 'Unlinked sync status message' );

suite( 'handle_sync_status: linked Google Doc post returns syncable payload' );

$mock_user_caps = array( 'edit_post:59' => true );
$mock_posts[ 59 ] = (object) array( 'ID' => 59, 'post_title' => 'Linked Status', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_post_meta[ 59 ] = array(
	'_gdtg_source_type'          => 'gdoc',
	'_gdtg_source_id'            => 'doc-59',
	'_gdtg_source_name'          => 'Source 59',
	'_gdtg_auto_sync'            => '1',
	'_gdtg_last_imported_at'     => '2026-06-07 10:00:00',
	'_gdtg_last_sync_status'     => 'ok',
	'_gdtg_last_sync_checked_at' => '2026-06-07 10:05:00',
	'_gdtg_last_sync_error'      => '',
	'_gdtg_source_modified_at'   => '2026-06-07 09:55:00',
);
$sync_status_linked = $sync_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 59 ) ) );
assert_equals( 200, $sync_status_linked->status, 'Linked sync status returns 200' );
assert_equals( 'gdoc', $sync_status_linked->data['source_type'], 'Linked sync status exposes source type' );
assert_equals( 'doc-59', $sync_status_linked->data['source_id'], 'Linked sync status exposes source id' );
assert_true( $sync_status_linked->data['auto_sync'], 'Linked sync status exposes auto_sync boolean' );
assert_true( $sync_status_linked->data['syncable'], 'Google Doc source is syncable' );

suite( 'handle_sync_status: docx upload payload is not syncable' );

$mock_user_caps = array( 'edit_post:60' => true );
$mock_posts[ 60 ] = (object) array( 'ID' => 60, 'post_title' => 'Docx Local', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_post_meta[ 60 ] = array(
	'_gdtg_source_type'      => 'docx_upload',
	'_gdtg_source_id'        => '',
	'_gdtg_source_name'      => 'upload.docx',
	'_gdtg_auto_sync'        => '0',
	'_gdtg_last_imported_at' => '2026-06-07 11:00:00',
);
$sync_status_docx = $sync_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 60 ) ) );
assert_equals( 200, $sync_status_docx->status, 'Docx sync status returns 200' );
assert_true( ! $sync_status_docx->data['syncable'], 'Docx upload source is not syncable' );
assert_true( ! $sync_status_docx->data['auto_sync'], 'Docx upload auto_sync false is exposed' );

suite( 'handle_sync_status: linked posts list filters by edit_post capability' );

$mock_linked_post_ids = array( 59, 60, 61 );
$mock_posts[ 61 ] = (object) array( 'ID' => 61, 'post_title' => 'Hidden Linked', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_post_meta[ 61 ] = array(
	'_gdtg_source_type'      => 'drive_file',
	'_gdtg_source_name'      => 'Drive Source',
	'_gdtg_last_imported_at' => '2026-06-07 12:00:00',
	'_gdtg_auto_sync'        => '1',
);
$mock_user_caps = array(
	'edit_post:59' => true,
	'edit_post:60' => true,
	'edit_post:61' => false,
);
$sync_status_list = $sync_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 0 ) ) );
assert_equals( 200, $sync_status_list->status, 'Linked posts list returns 200' );
assert_equals( 2, $sync_status_list->data['total'], 'Linked posts list excludes forbidden posts' );
assert_equals( 59, $sync_status_list->data['posts'][0]['post_id'], 'Linked posts list keeps first permitted post' );
assert_equals( 60, $sync_status_list->data['posts'][1]['post_id'], 'Linked posts list keeps second permitted post' );

suite( 'handle_sync_settings: auto_sync true stores string 1' );

$mock_post_meta[ 62 ] = array( '_gdtg_auto_sync' => '0' );
$sync_settings_true = $sync_rest->handle_sync_settings( new GDTG_Test_REST_Request( array( 'post_id' => 62, 'auto_sync' => true ) ) );
assert_equals( 200, $sync_settings_true->status, 'Sync settings true returns 200' );
assert_equals( '1', get_post_meta( 62, '_gdtg_auto_sync', true ), 'Sync settings true stores string 1' );
assert_true( $sync_settings_true->data['auto_sync'], 'Sync settings true response returns boolean true' );

suite( 'handle_sync_settings: auto_sync false stores string 0' );

$sync_settings_false = $sync_rest->handle_sync_settings( new GDTG_Test_REST_Request( array( 'post_id' => 62, 'auto_sync' => false ) ) );
assert_equals( 200, $sync_settings_false->status, 'Sync settings false returns 200' );
assert_equals( '0', get_post_meta( 62, '_gdtg_auto_sync', true ), 'Sync settings false stores string 0' );
assert_true( ! $sync_settings_false->data['auto_sync'], 'Sync settings false response returns boolean false' );

suite( 'handle_sync_settings: null auto_sync leaves existing value unchanged' );

$mock_post_meta[ 63 ] = array( '_gdtg_auto_sync' => '1' );
$sync_settings_null = $sync_rest->handle_sync_settings( new GDTG_Test_REST_Request( array( 'post_id' => 63, 'auto_sync' => null ) ) );
assert_equals( 200, $sync_settings_null->status, 'Sync settings null returns 200' );
assert_equals( '1', get_post_meta( 63, '_gdtg_auto_sync', true ), 'Sync settings null keeps stored value' );
assert_true( $sync_settings_null->data['auto_sync'], 'Sync settings null response reflects unchanged true value' );

suite( 'handle_imported_docs_list: groups resyncable sources and separates local uploads' );

$mock_imported_posts = array(
	(object) array( 'ID' => 70, 'post_title' => 'Grouped A' ),
	(object) array( 'ID' => 71, 'post_title' => 'Grouped B' ),
	(object) array( 'ID' => 72, 'post_title' => 'Local Upload' ),
	(object) array( 'ID' => 73, 'post_title' => 'Missing Source ID' ),
);
$mock_post_meta[ 70 ] = array(
	'_gdtg_source_type'      => 'gdoc',
	'_gdtg_source_id'        => 'shared-doc',
	'_gdtg_source_name'      => 'Shared Doc',
	'_gdtg_last_imported_at' => '2026-06-07 08:00:00',
);
$mock_post_meta[ 71 ] = array(
	'_gdtg_source_type'      => 'gdoc',
	'_gdtg_source_id'        => 'shared-doc',
	'_gdtg_source_name'      => 'Shared Doc',
	'_gdtg_last_imported_at' => '2026-06-07 09:00:00',
);
$mock_post_meta[ 72 ] = array(
	'_gdtg_source_type'      => 'docx_upload',
	'_gdtg_source_id'        => '',
	'_gdtg_source_name'      => 'Local File',
	'_gdtg_last_imported_at' => '2026-06-07 07:00:00',
);
$mock_post_meta[ 73 ] = array(
	'_gdtg_source_type'      => 'gdoc',
	'_gdtg_source_id'        => '',
	'_gdtg_source_name'      => 'Incomplete Link',
	'_gdtg_last_imported_at' => '2026-06-07 06:00:00',
);
$imported_docs_resp = $sync_rest->handle_imported_docs_list( new GDTG_Test_REST_Request( array() ) );
assert_equals( 200, $imported_docs_resp->status, 'Imported docs list returns 200' );
assert_equals( 1, count( $imported_docs_resp->data['sources'] ), 'Imported docs list groups shared resyncable source once' );
assert_equals( 'shared-doc', $imported_docs_resp->data['sources'][0]['source_id'], 'Grouped source keeps shared source id' );
assert_equals( '2026-06-07 09:00:00', $imported_docs_resp->data['sources'][0]['latest_imported_at'], 'Grouped source keeps latest import timestamp' );
assert_equals( 2, count( $imported_docs_resp->data['sources'][0]['posts'] ), 'Grouped source includes both linked posts' );
assert_equals( 2, count( $imported_docs_resp->data['local_uploads'] ), 'Imported docs list separates non-resyncable entries' );
assert_true( ! $imported_docs_resp->data['local_uploads'][0]['resyncable'], 'Local uploads list marks entries as non-resyncable' );

// ═══════════════════════════════════════════════════════════════════
// Phase 3: Sync events endpoint tests.
// ═══════════════════════════════════════════════════════════════════

// Load GDTG_Sync_Log.
require_once __DIR__ . '/../includes/class-gdtg-sync-log.php';

suite( 'check_events_permissions: subscriber on existing post returns 403' );

$events_rest = new GDTG_REST_Endpoints( new class { public function add_action() {} } );
$mock_user_caps = array( 'edit_post:64' => false );
$mock_posts[ 64 ] = (object) array( 'ID' => 64, 'post_title' => 'Events Test', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$events_perm_req  = new GDTG_Test_REST_Request( array( 'post_id' => 64 ) );
$events_perm_resp = $events_rest->check_events_permissions( $events_perm_req );
assert_is_wp_error( $events_perm_resp, 'Subscriber cannot access events' );
assert_equals( 'gdtg_forbidden', $events_perm_resp->get_error_code(), 'Error code is gdtg_forbidden for events' );
assert_true( false !== strpos( $events_perm_resp->get_error_message(), 'permission' ), 'Error message mentions permission' );

suite( 'check_events_permissions: editor on existing post returns true' );

$mock_user_caps = array( 'edit_post:64' => true );
$events_perm_ok = $events_rest->check_events_permissions( new GDTG_Test_REST_Request( array( 'post_id' => 64 ) ) );
assert_true( $events_perm_ok === true, 'Editor can access events' );

suite( 'check_events_permissions: non-existent post returns 404' );

$events_perm_404 = $events_rest->check_events_permissions( new GDTG_Test_REST_Request( array( 'post_id' => 99999 ) ) );
assert_is_wp_error( $events_perm_404, 'Non-existent post returns WP_Error' );
assert_equals( 'gdtg_post_not_found', $events_perm_404->get_error_code(), 'Error code is gdtg_post_not_found' );

suite( 'check_events_permissions: post_id 0 returns 400' );

$events_perm_400 = $events_rest->check_events_permissions( new GDTG_Test_REST_Request( array( 'post_id' => 0 ) ) );
assert_is_wp_error( $events_perm_400, 'Post ID 0 returns WP_Error' );
assert_equals( 'gdtg_invalid_post', $events_perm_400->get_error_code(), 'Error code is gdtg_invalid_post' );

suite( 'handle_sync_events: returns empty events for post without events' );

$mock_post_meta[ 64 ] = array(
	'_gdtg_source_type' => 'gdoc',
	'_gdtg_source_id'   => 'doc-64',
);
$events_get_req  = new GDTG_Test_REST_Request( array( 'post_id' => 64 ) );
$events_get_resp = $events_rest->handle_sync_events( $events_get_req );
assert_equals( 200, $events_get_resp->status, 'Events GET returns 200' );
assert_equals( 64, $events_get_resp->data['post_id'], 'Response includes post_id' );
assert_equals( array(), $events_get_resp->data['events'], 'Empty events array for post with no events' );

suite( 'handle_sync_events: returns events after record' );

GDTG_Sync_Log::record( 64, 'info', 'Test event', array( 'step' => 'test' ) );
$events_get_resp2 = $events_rest->handle_sync_events( new GDTG_Test_REST_Request( array( 'post_id' => 64 ) ) );
assert_equals( 1, count( $events_get_resp2->data['events'] ), 'One event returned' );
assert_equals( 'Test event', $events_get_resp2->data['events'][0]['message'], 'Event message matches' );

suite( 'handle_sync_events_clear: clears events' );

$events_clear_req  = new GDTG_Test_REST_Request( array( 'post_id' => 64 ) );
$events_clear_resp = $events_rest->handle_sync_events_clear( $events_clear_req );
assert_equals( 200, $events_clear_resp->status, 'Clear returns 200' );
assert_equals( 64, $events_clear_resp->data['post_id'], 'Clear response includes post_id' );
assert_true( $events_clear_resp->data['cleared'], 'Clear response has cleared=true' );

// Verify cleared.
$events_get_resp3 = $events_rest->handle_sync_events( new GDTG_Test_REST_Request( array( 'post_id' => 64 ) ) );
assert_equals( array(), $events_get_resp3->data['events'], 'Events empty after clear' );

suite( 'handle_sync_status: extended response contains health and events keys' );

$mock_user_caps = array( 'edit_post:65' => true );
$mock_posts[ 65 ] = (object) array( 'ID' => 65, 'post_title' => 'Health Test', 'post_status' => 'publish', 'post_content' => '<p>content</p>', 'post_type' => 'post', 'post_name' => '', 'post_excerpt' => '' );
$mock_post_meta[ 65 ] = array(
	'_gdtg_source_type'        => 'gdoc',
	'_gdtg_source_id'          => 'doc-65',
	'_gdtg_source_name'        => 'Health Doc',
	'_gdtg_auto_sync'          => '1',
	'_gdtg_last_imported_at'   => '2026-06-15 10:00:00',
	'_gdtg_last_sync_status'   => 'success',
	'_gdtg_last_sync_checked_at' => '2026-06-15 10:05:00',
	'_gdtg_last_sync_error'    => '',
	'_gdtg_source_modified_at' => '',
);
$health_resp = $events_rest->handle_sync_status( new GDTG_Test_REST_Request( array( 'post_id' => 65 ) ) );
assert_equals( 200, $health_resp->status, 'Health status returns 200' );
assert_true( isset( $health_resp->data['health'] ), 'Response has health key' );
assert_true( isset( $health_resp->data['events'] ), 'Response has events key' );
assert_true( is_array( $health_resp->data['health'] ), 'Health is an array' );
assert_true( is_array( $health_resp->data['events'] ), 'Events is an array' );
assert_equals( 'success', $health_resp->data['health']['status'], 'Health status is success' );
assert_equals( '', $health_resp->data['health']['last_error'], 'Health has empty last_error' );
assert_true( isset( $health_resp->data['health']['locked'] ), 'Health has locked key' );
assert_equals( false, $health_resp->data['health']['locked'], 'Health locked is false' );

// Verify backward compatibility: existing fields still present.
assert_equals( 'gdoc', $health_resp->data['source_type'], 'Backward compatible: source_type present' );
assert_equals( 'doc-65', $health_resp->data['source_id'], 'Backward compatible: source_id present' );
assert_equals( 'Health Doc', $health_resp->data['source_name'], 'Backward compatible: source_name present' );
assert_true( $health_resp->data['auto_sync'], 'Backward compatible: auto_sync present' );
assert_true( $health_resp->data['syncable'], 'Backward compatible: syncable present' );

suite( 'handle_sync_events_clear: safe for non-existent post' );

$events_clear_safe = $events_rest->handle_sync_events_clear( new GDTG_Test_REST_Request( array( 'post_id' => 99999 ) ) );
assert_equals( 200, $events_clear_safe->status, 'Clear on non-existent post returns 200' );
assert_true( $events_clear_safe->data['cleared'], 'Clear returns cleared=true even for non-existent post' );
echo "\n==================================================\n";
echo "REST Endpoints Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
