<?php
/**
 * Standalone ZIP validator regression harness.
 *
 * Exercises security-critical guards in GDTG_Zip_Validator without a full
 * WordPress bootstrap. Run with: php tests/zip-validator-test.php
 */

echo "Running ZIP Validator Regression Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! class_exists( 'WP_Error' ) ) {
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
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function size_format( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$index = 0;
	while ( $bytes >= 1024 && $index < count( $units ) - 1 ) {
		$bytes /= 1024;
		$index++;
	}
	return round( $bytes, 1 ) . ' ' . $units[ $index ];
}

function apply_filters( $tag, $value ) {
	return $value;
}

require_once __DIR__ . '/../includes/class-gdtg-zip-validator.php';

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assert_true( $condition, $message ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $condition ) {
		$pass_count++;
		echo "PASS: {$message}\n";
		return;
	}
	$fail_count++;
	echo "FAIL: {$message}\n";
}

function assert_same( $expected, $actual, $message ) {
	assert_true( $expected === $actual, $message . " (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ')' );
}

function assert_wp_error_code( $result, $code, $message ) {
	assert_true( is_wp_error( $result ), $message . ' returns WP_Error' );
	if ( is_wp_error( $result ) ) {
		assert_same( $code, $result->get_error_code(), $message . ' uses expected code' );
	}
}

function create_zip_fixture( $entries ) {
	$path = tempnam( sys_get_temp_dir(), 'gdtg-zip-' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		echo "FAIL: unable to create zip fixture\n";
		exit( 1 );
	}
	foreach ( $entries as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	return $path;
}

function create_minimal_docx( $extra_entries = array() ) {
	$entries = array(
		'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
		'_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
		'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>',
	);
	foreach ( $extra_entries as $name => $content ) {
		$entries[ $name ] = $content;
	}
	return create_zip_fixture( $entries );
}

function cleanup_files( $paths ) {
	foreach ( $paths as $path ) {
		if ( $path && file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

$cleanup = array();

assert_true( true === GDTG_Zip_Validator::validate( __DIR__ . '/fixtures/docx/basic.docx' ), 'valid fixture docx passes validation' );

$missing = GDTG_Zip_Validator::validate( __DIR__ . '/fixtures/docx/does-not-exist.docx' );
assert_wp_error_code( $missing, 'gdtg_docx_not_found', 'missing file' );

$bad_magic = tempnam( sys_get_temp_dir(), 'gdtg-bad-magic-' );
$cleanup[] = $bad_magic;
file_put_contents( $bad_magic, 'NOT_A_ZIP_FILE' );
$bad_magic_result = GDTG_Zip_Validator::validate( $bad_magic );
assert_wp_error_code( $bad_magic_result, 'gdtg_docx_bad_magic', 'bad magic bytes' );

$traversal_relative = create_minimal_docx( array( '../../evil.txt' => 'malicious' ) );
$cleanup[] = $traversal_relative;
assert_wp_error_code( GDTG_Zip_Validator::validate( $traversal_relative ), 'gdtg_docx_path_traversal', 'relative traversal entry' );

$traversal_backslash = create_minimal_docx( array( '..\\evil.txt' => 'malicious' ) );
$cleanup[] = $traversal_backslash;
assert_wp_error_code( GDTG_Zip_Validator::validate( $traversal_backslash ), 'gdtg_docx_path_traversal', 'backslash traversal entry' );

$traversal_absolute = create_minimal_docx( array( '/etc/passwd' => 'malicious' ) );
$cleanup[] = $traversal_absolute;
assert_wp_error_code( GDTG_Zip_Validator::validate( $traversal_absolute ), 'gdtg_docx_path_traversal', 'absolute traversal entry' );

$nested = create_minimal_docx( array( 'nested/archive.zip' => 'fake zip body' ) );
$cleanup[] = $nested;
assert_wp_error_code( GDTG_Zip_Validator::validate( $nested ), 'gdtg_docx_nested_zip', 'nested zip entry by extension' );

$max_entries_ok = array();
for ( $i = 0; $i < 9997; $i++ ) {
	$max_entries_ok[ sprintf( 'word/media/file-%05d.txt', $i ) ] = 'a';
}
$entry_cap_ok = create_minimal_docx( $max_entries_ok );
$cleanup[] = $entry_cap_ok;
assert_true( true === GDTG_Zip_Validator::validate( $entry_cap_ok ), 'zip with exactly 10000 entries passes validation' );

$max_entries_fail = $max_entries_ok;
$max_entries_fail['word/media/file-overflow.txt'] = 'a';
$entry_cap_fail = create_minimal_docx( $max_entries_fail );
$cleanup[] = $entry_cap_fail;
assert_wp_error_code( GDTG_Zip_Validator::validate( $entry_cap_fail ), 'gdtg_docx_too_many_entries', 'zip with 10001 entries fails validation' );

$ratio_ok = create_minimal_docx( array( 'word/media/ratio-ok.bin' => str_repeat( 'A', 1000 ) ) );
$cleanup[] = $ratio_ok;
assert_true( true === GDTG_Zip_Validator::validate( $ratio_ok ), 'compression ratio at or below threshold passes validation' );

$ratio_fail = create_minimal_docx( array( 'word/media/ratio-fail.bin' => str_repeat( 'A', 5000 ) ) );
$cleanup[] = $ratio_fail;
assert_wp_error_code( GDTG_Zip_Validator::validate( $ratio_fail ), 'gdtg_docx_zip_bomb', 'compression ratio above threshold fails validation' );

$no_document = create_zip_fixture(
	array(
		'[Content_Types].xml' => '<Types/>',
		'_rels/.rels' => '<Relationships/>',
		'word/other.xml' => '<other/>',
	)
);
$cleanup[] = $no_document;
assert_wp_error_code( GDTG_Zip_Validator::validate( $no_document ), 'gdtg_docx_no_document', 'missing word/document.xml' );

cleanup_files( $cleanup );

echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nZIP validator regression tests passed.\n";
