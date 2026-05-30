<?php
/**
 * Standalone GDTG_Sideloader unit/integration tests with stubs.
 *
 * Uses a temporary ABSPATH so no repo files are mutated.
 * Shutdown callback guarantees cleanup even on fatal errors.
 */

// Use a temp directory for ABSPATH so wp-admin/ stubs never touch the repo.
$gdtg_test_abspath = sys_get_temp_dir() . '/gdtg-test-' . getmypid() . '/';
@mkdir( $gdtg_test_abspath . 'wp-admin/includes', 0755, true );
@touch( $gdtg_test_abspath . 'wp-admin/includes/media.php' );
@touch( $gdtg_test_abspath . 'wp-admin/includes/file.php' );
@touch( $gdtg_test_abspath . 'wp-admin/includes/image.php' );

define( 'ABSPATH', $gdtg_test_abspath );

// Register shutdown cleanup so temp files are removed even on fatal errors.
register_shutdown_function( function () use ( $gdtg_test_abspath ) {
	$files = [
		'wp-admin/includes/media.php',
		'wp-admin/includes/file.php',
		'wp-admin/includes/image.php',
	];
	foreach ( $files as $f ) {
		@unlink( $gdtg_test_abspath . $f );
	}
	@rmdir( $gdtg_test_abspath . 'wp-admin/includes' );
	@rmdir( $gdtg_test_abspath . 'wp-admin' );
	@rmdir( $gdtg_test_abspath );
} );

// Mock WordPress functions
$mock_options = [];

function get_option( $name, $default = false ) {
	global $mock_options;
	return isset( $mock_options[ $name ] ) ? $mock_options[ $name ] : $default;
}

function update_option( $name, $value ) {
	global $mock_options;
	$mock_options[ $name ] = $value;
}

/**
 * Faithful wp_parse_url shim that supports the $component parameter.
 * Production code calls wp_parse_url( $url, PHP_URL_PATH ).
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_tempnam( $url ) {
	return tempnam( sys_get_temp_dir(), 'gdtg' );
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^a-zA-Z0-9._-]/', '_', $name );
}

function sanitize_text_field( $text ) {
	return strip_tags( $text );
}

function wp_safe_remote_get( $url, $args ) {
	global $mock_streamed_response;
	if ( isset( $mock_streamed_response ) ) {
		return $mock_streamed_response;
	}
	return new WP_Error( 'http_error', 'Failed' );
}

function get_attached_file( $attachment_id ) {
	return false; // Return false so optimize_attachment exits early
}

function get_post_mime_type( $attachment_id ) {
	return 'image/jpeg';
}

// Stub for media_sideload_image — the fallback path.
$media_sideload_called = false;
$media_sideload_url    = '';

function media_sideload_image( $url, $post_id, $desc, $return_type ) {
	global $media_sideload_called, $media_sideload_url, $mock_sideload_fail;
	$media_sideload_called = true;
	$media_sideload_url    = $url;
	if ( ! empty( $mock_sideload_fail ) ) {
		return new WP_Error( 'sideload_failed', 'WP Sideload failed' );
	}
	return 999;
}

// Stub for media_handle_sideload — the primary streamed path.
$media_handle_sideload_called = false;
$media_handle_sideload_file   = [];

function media_handle_sideload( $file_array, $post_id, $desc, $post_data ) {
	global $media_handle_sideload_called, $media_handle_sideload_file;
	$media_handle_sideload_called = true;
	$media_handle_sideload_file   = $file_array;
	return 888; // Distinct from media_sideload_image's 999.
}

// Simple WP_Error stub
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// Http stub functions
$mock_http_response = null;

function wp_remote_post( $url, $args ) {
	global $mock_http_response;
	if ( $mock_http_response ) {
		return $mock_http_response;
	}
	return new WP_Error( 'http_error', 'Failed' );
}

function wp_remote_get( $url, $args ) {
	global $mock_http_response;
	if ( $mock_http_response ) {
		return $mock_http_response;
	}
	return new WP_Error( 'http_error', 'Failed' );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'] ?? 200;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

// Load real classes that we want to test
require_once __DIR__ . '/../includes/class-gdtg-api.php';
require_once __DIR__ . '/../includes/class-gdtg-sideloader.php';

// Assertions & Simple Test framework
$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function it( $label, $fn ) {
	global $test_count, $pass_count, $fail_count,
		$media_sideload_called, $media_sideload_url,
		$media_handle_sideload_called, $media_handle_sideload_file,
		$mock_options, $mock_http_response, $mock_sideload_fail, $mock_streamed_response;

	$test_count++;
	$media_sideload_called       = false;
	$media_sideload_url          = '';
	$media_handle_sideload_called = false;
	$media_handle_sideload_file   = [];
	$mock_options                 = [];
	$mock_http_response           = null;
	$mock_streamed_response       = new WP_Error( 'stream_error', 'Failed streamed download' ); // Default: fail streamed → trigger fallback
	$mock_sideload_fail           = false;

	try {
		$fn();
		$pass_count++;
		echo "  ✓ {$label}\n";
	} catch ( Exception $e ) {
		$fail_count++;
		echo "  ✗ {$label} FAIL: " . $e->getMessage() . "\n";
	}
}

function assert_equals( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new Exception( "Expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) );
	}
}

function assert_true( $val ) {
	if ( ! $val ) {
		throw new Exception( "Expected expression to be true" );
	}
}

// ── Tests ──────────────────────────────────────────────────────

echo "=== GDTG_Sideloader + Optimization Tests ===\n";

// --- Existing fallback-path tests ---

it( 'uses original URL when optimize_images is disabled', function () {
	global $media_sideload_called, $media_sideload_url;
	$url = 'https://example.com/original.jpg';
	$res = GDTG_Sideloader::sideload( $url, 12, 'Alt Text', [ 'optimize_images' => false ] );
	assert_true( $media_sideload_called );
	assert_equals( $url, $media_sideload_url );
	assert_equals( 999, $res );
} );

it( 'uses optimized URL when optimize_images is enabled and SaaS bridge succeeds', function () {
	global $media_sideload_called, $media_sideload_url, $mock_http_response;
	update_option( 'gdtg_connection_mode', 'saas' );
	update_option( 'gdtg_saas_bridge_base_url', 'https://saas-bridge.com' );
	update_option( 'gdtg_saas_access_token', 'mock_token' );
	update_option( 'gdtg_saas_token_expires', time() + 3600 );
	$mock_http_response = [
		'code' => 200,
		'body' => json_encode( [ 'optimized_url' => 'https://saas-bridge.com/optimized.webp' ] ),
	];
	$url = 'https://example.com/original.jpg';
	$res = GDTG_Sideloader::sideload( $url, 12, 'Alt Text', [ 'optimize_images' => true ] );
	assert_true( $media_sideload_called );
	assert_equals( 'https://saas-bridge.com/optimized.webp', $media_sideload_url );
	assert_equals( 999, $res );
} );

it( 'falls back to original URL when optimization fails (bridge HTTP error)', function () {
	global $media_sideload_called, $media_sideload_url, $mock_http_response;
	update_option( 'gdtg_connection_mode', 'saas' );
	update_option( 'gdtg_saas_bridge_base_url', 'https://saas-bridge.com' );
	update_option( 'gdtg_saas_access_token', 'mock_token' );
	update_option( 'gdtg_saas_token_expires', time() + 3600 );
	$mock_http_response = [
		'code' => 500,
		'body' => 'SaaS Internal Error',
	];
	$url = 'https://example.com/original.jpg';
	$res = GDTG_Sideloader::sideload( $url, 12, 'Alt Text', [ 'optimize_images' => true ] );
	assert_true( $media_sideload_called );
	assert_equals( $url, $media_sideload_url );
	assert_equals( 999, $res );
} );

it( 'falls back to class default option when optimize_images is not explicitly provided in options', function () {
	global $media_sideload_called, $media_sideload_url;
	update_option( 'gdtg_optimize_images', '0' );
	$url = 'https://example.com/original.jpg';
	$res = GDTG_Sideloader::sideload( $url, 12, 'Alt Text' );
	assert_true( $media_sideload_called );
	assert_equals( $url, $media_sideload_url );
	assert_equals( 999, $res );
} );

// --- New: streamed-success path test ---

it( 'streamed sideload uses media_handle_sideload with URL-derived filename', function () {
	global $mock_streamed_response, $media_handle_sideload_called, $media_handle_sideload_file, $media_sideload_called;

	// Disable optimization so no bridge call happens.
	update_option( 'gdtg_optimize_images', '0' );

	// Create a real temp file to simulate a successful streamed download.
	$tmp = tempnam( sys_get_temp_dir(), 'gdtg-stream-test-' );
	file_put_contents( $tmp, 'fake-image-data' );

	// wp_safe_remote_get returns HTTP 200 with body = temp file path.
	$mock_streamed_response = [
		'code' => 200,
		'body' => $tmp,
	];

	$url = 'https://cdn.example.com/photos/vacation.jpg';
	$res = GDTG_Sideloader::sideload( $url, 42, 'Vacation' );

	// The primary streamed path should have been used, not the fallback.
	assert_true( $media_handle_sideload_called );
	assert_true( ! $media_sideload_called );
	assert_equals( 888, $res );

	// media_handle_sideload should receive the URL-derived filename.
	assert_equals( 'vacation.jpg', $media_handle_sideload_file['name'] );
	// And the temp file path as tmp_name.
	assert_equals( $tmp, $media_handle_sideload_file['tmp_name'] );

	// Cleanup.
	@unlink( $tmp );
} );

it( 'skips bridge optimization in non-SaaS mode even when optimize_images is true', function () {
	global $media_sideload_called, $media_sideload_url, $mock_http_response;
	update_option( 'gdtg_connection_mode', 'enterprise' );
	update_option( 'gdtg_optimize_images', '1' );
	// No $mock_http_response set → bridge would return WP_Error if called.
	$url = 'https://example.com/photo.jpg';
	$res = GDTG_Sideloader::sideload( $url, 10, 'Photo', [ 'optimize_images' => true ] );
	assert_true( $media_sideload_called );
	// Should still use original URL (not optimized), and succeed.
	assert_equals( $url, $media_sideload_url );
	assert_equals( 999, $res );
} );

// No explicit cleanup needed — shutdown function handles it.

echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Results: {$pass_count} passed, {$fail_count} failed, {$test_count} total\n";
echo str_repeat( '=', 50 ) . "\n";

exit( $fail_count > 0 ? 1 : 0 );
