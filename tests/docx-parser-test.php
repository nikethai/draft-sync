<?php
/**
 * Standalone DOCX parser regression harness.
 *
 * Focuses on parser-only AST behavior and defensive branches in
 * GDTG_Docx_Parser. Run with: php tests/docx-parser-test.php
 */

echo "Running DOCX Parser Regression Tests...\n\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_url( $url ) {
	$url = preg_replace( '/[^a-zA-Z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', '', (string) $url );
	return $url;
}

function absint( $value ) {
	return abs( (int) $value );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-docx-parser.php';

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

function assert_contains( $haystack, $needle, $message ) {
	assert_true( false !== strpos( $haystack, $needle ), $message . " (missing {$needle})" );
}

function assert_not_contains( $haystack, $needle, $message ) {
	assert_true( false === strpos( $haystack, $needle ), $message . " (unexpected {$needle})" );
}

function create_docx_fixture( $body_xml, $numbering_xml = null, $rels_xml = null ) {
	$path = tempnam( sys_get_temp_dir(), 'gdtg-docx-' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		echo "FAIL: unable to create docx fixture\n";
		exit( 1 );
	}

	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
	if ( null !== $rels_xml ) {
		$zip->addFromString( 'word/_rels/document.xml.rels', $rels_xml );
	}
	if ( null !== $numbering_xml ) {
		$zip->addFromString( 'word/numbering.xml', $numbering_xml );
	}

	$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body_xml . '</w:body></w:document>';
	$zip->addFromString( 'word/document.xml', $document_xml );
	$zip->close();

	return $path;
}

function create_broken_document_fixture( $document_xml ) {
	$path = tempnam( sys_get_temp_dir(), 'gdtg-docx-' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		echo "FAIL: unable to create broken docx fixture\n";
		exit( 1 );
	}
	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
	$zip->addFromString( 'word/document.xml', $document_xml );
	$zip->close();
	return $path;
}

function create_no_document_fixture() {
	$path = tempnam( sys_get_temp_dir(), 'gdtg-docx-' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		echo "FAIL: unable to create no-document fixture\n";
		exit( 1 );
	}
	$zip->addFromString( '[Content_Types].xml', '<Types/>' );
	$zip->addFromString( '_rels/.rels', '<Relationships/>' );
	$zip->close();
	return $path;
}

function numbering_xml( $formats, $num_map ) {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
	foreach ( $formats as $abstract_id => $levels ) {
		$xml .= '<w:abstractNum w:abstractNumId="' . $abstract_id . '">';
		foreach ( $levels as $level => $format ) {
			$xml .= '<w:lvl w:ilvl="' . $level . '"><w:numFmt w:val="' . $format . '"/></w:lvl>';
		}
		$xml .= '</w:abstractNum>';
	}
	foreach ( $num_map as $num_id => $abstract_id ) {
		$xml .= '<w:num w:numId="' . $num_id . '"><w:abstractNumId w:val="' . $abstract_id . '"/></w:num>';
	}
	$xml .= '</w:numbering>';
	return $xml;
}

function rels_xml( $rels ) {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	foreach ( $rels as $id => $target ) {
		$xml .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . htmlspecialchars( $target, ENT_QUOTES, 'UTF-8' ) . '" TargetMode="External"/>';
	}
	$xml .= '</Relationships>';
	return $xml;
}

function para( $inner, $ppr = '' ) {
	return '<w:p>' . $ppr . $inner . '</w:p>';
}

function paragraph_props( $style = '', $num_id = null, $level = null, $jc = '' ) {
	$parts = array();
	if ( '' !== $style ) {
		$parts[] = '<w:pStyle w:val="' . $style . '"/>';
	}
	if ( null !== $num_id ) {
		$ilvl = null === $level ? '0' : (string) $level;
		$parts[] = '<w:numPr><w:ilvl w:val="' . $ilvl . '"/><w:numId w:val="' . $num_id . '"/></w:numPr>';
	}
	if ( '' !== $jc ) {
		$parts[] = '<w:jc w:val="' . $jc . '"/>';
	}
	return empty( $parts ) ? '' : '<w:pPr>' . implode( '', $parts ) . '</w:pPr>';
}

function text_run( $text, $rpr = '' ) {
	return '<w:r>' . $rpr . '<w:t>' . htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</w:t></w:r>';
}

function run_props( $opts = array() ) {
	$parts = array();
	if ( ! empty( $opts['bold'] ) ) {
		$parts[] = '<w:b/>';
	}
	if ( ! empty( $opts['italic'] ) ) {
		$parts[] = '<w:i/>';
	}
	if ( ! empty( $opts['underline'] ) ) {
		$parts[] = '<w:u w:val="single"/>';
	}
	if ( ! empty( $opts['strike'] ) ) {
		$parts[] = '<w:strike/>';
	}
	if ( isset( $opts['color'] ) ) {
		$parts[] = '<w:color w:val="' . $opts['color'] . '"/>';
	}
	return empty( $parts ) ? '' : '<w:rPr>' . implode( '', $parts ) . '</w:rPr>';
}

function hyperlink( $rid, $text ) {
	return '<w:hyperlink r:id="' . $rid . '">' . text_run( $text ) . '</w:hyperlink>';
}

function page_break_para() {
	return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

function cleanup_files( $paths ) {
	foreach ( $paths as $path ) {
		if ( $path && file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

$cleanup = array();

$missing_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/does-not-exist.docx' );
assert_same( array(), $missing_parser->parse_nodes(), 'missing file returns empty node list' );

$no_document = create_no_document_fixture();
$cleanup[] = $no_document;
$no_document_parser = new GDTG_Docx_Parser( $no_document );
assert_same( array(), $no_document_parser->parse_nodes(), 'missing word/document.xml returns empty node list' );

$no_body = create_broken_document_fixture( '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"></w:document>' );
$cleanup[] = $no_body;
$no_body_parser = new GDTG_Docx_Parser( $no_body );
assert_same( array(), $no_body_parser->parse_nodes(), 'missing w:body returns empty node list' );

$malformed = create_broken_document_fixture( '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>broken</w:t></w:r>' );
$cleanup[] = $malformed;
$malformed_parser = new GDTG_Docx_Parser( $malformed );
assert_same( array(), $malformed_parser->parse_nodes(), 'malformed XML returns empty node list' );

$list_numbering = numbering_xml(
	array(
		'10' => array( '0' => 'bullet' ),
		'11' => array( '0' => 'decimal' ),
	),
	array( '1' => '10', '2' => '11' )
);
$list_fixture = create_docx_fixture(
	para( text_run( 'Bullet item' ), paragraph_props( '', '1', '0' ) ) .
	para( text_run( 'Number item' ), paragraph_props( '', '2', '0' ) ),
	$list_numbering
);
$cleanup[] = $list_fixture;
$list_nodes = ( new GDTG_Docx_Parser( $list_fixture ) )->parse_nodes();
assert_same( 2, count( $list_nodes ), 'mixed list types create two top-level list nodes' );
assert_same( 'list', $list_nodes[0]->type, 'first mixed list node is list' );
assert_true( empty( $list_nodes[0]->attrs['ordered'] ), 'bullet numFmt renders unordered list' );
assert_true( ! empty( $list_nodes[1]->attrs['ordered'] ), 'decimal numFmt renders ordered list' );

$fallback_numbering = numbering_xml(
	array( '21' => array( '0' => 'decimal' ) ),
	array( '3' => '21' )
);
$fallback_fixture = create_docx_fixture(
	para( text_run( 'Fallback item' ), paragraph_props( '', '3', '1' ) ),
	$fallback_numbering
);
$cleanup[] = $fallback_fixture;
$fallback_nodes = ( new GDTG_Docx_Parser( $fallback_fixture ) )->parse_nodes();
assert_same( 1, count( $fallback_nodes ), 'level fallback fixture creates one top-level list' );
assert_true( ! empty( $fallback_nodes[0]->attrs['ordered'] ), 'missing ilvl format falls back to level 0 ordered format' );

$decimal_fallback_fixture = create_docx_fixture(
	para( text_run( 'Implicit decimal item' ), paragraph_props( '', '4', '0' ) ),
	numbering_xml( array(), array() )
);
$cleanup[] = $decimal_fallback_fixture;
$decimal_fallback_nodes = ( new GDTG_Docx_Parser( $decimal_fallback_fixture ) )->parse_nodes();
assert_same( 1, count( $decimal_fallback_nodes ), 'missing numbering metadata still yields one list' );
assert_true( ! empty( $decimal_fallback_nodes[0]->attrs['ordered'] ), 'numId > 1 with missing format falls back to decimal ordering' );

$nested_numbering = numbering_xml( array( '30' => array( '0' => 'bullet', '1' => 'bullet' ) ), array( '1' => '30' ) );
$nested_fixture = create_docx_fixture(
	para( text_run( 'Top item' ), paragraph_props( '', '1', '0' ) ) .
	para( text_run( 'Nested item' ), paragraph_props( '', '1', '1' ) ) .
	para( text_run( 'Top sibling' ), paragraph_props( '', '1', '0' ) ),
	$nested_numbering
);
$cleanup[] = $nested_fixture;
$nested_nodes = ( new GDTG_Docx_Parser( $nested_fixture ) )->parse_nodes();
assert_same( 1, count( $nested_nodes ), 'nested list fixture creates one root list' );
assert_same( 2, count( $nested_nodes[0]->children ), 'root list keeps only top-level items' );
assert_true( ! empty( $nested_nodes[0]->children[0]->children ), 'nested item attaches under first top-level item' );
assert_same( 'list', $nested_nodes[0]->children[0]->children[0]->type, 'nested child is represented as nested list node' );

$interrupted_fixture = create_docx_fixture(
	para( text_run( 'List item one' ), paragraph_props( '', '1', '0' ) ) .
	para( text_run( 'Interruption paragraph' ) ) .
	para( text_run( 'List item two' ), paragraph_props( '', '1', '0' ) ),
	$nested_numbering
);
$cleanup[] = $interrupted_fixture;
$interrupted_nodes = ( new GDTG_Docx_Parser( $interrupted_fixture ) )->parse_nodes();
assert_same( 3, count( $interrupted_nodes ), 'paragraph interruption resets list stack' );
assert_same( 'list', $interrupted_nodes[0]->type, 'first interrupted node is list' );
assert_same( 'paragraph', $interrupted_nodes[1]->type, 'second interrupted node is paragraph' );
assert_same( 'list', $interrupted_nodes[2]->type, 'third interrupted node is new list' );

$heading_fixture = create_docx_fixture(
	para( text_run( 'Huge heading' ), paragraph_props( 'Heading9' ) ) .
	para( text_run( 'Lower heading' ), paragraph_props( 'heading2' ) )
);
$cleanup[] = $heading_fixture;
$heading_nodes = ( new GDTG_Docx_Parser( $heading_fixture ) )->parse_nodes();
assert_same( 'heading', $heading_nodes[0]->type, 'Heading9 paragraph becomes heading node' );
assert_same( 6, $heading_nodes[0]->attrs['level'], 'Heading9 clamps to level 6' );
assert_same( 2, $heading_nodes[1]->attrs['level'], 'case-insensitive heading style is supported' );

$heading_list_fixture = create_docx_fixture(
	para( text_run( 'Styled list item' ), paragraph_props( 'Heading3', '1', '0' ) ),
	$nested_numbering
);
$cleanup[] = $heading_list_fixture;
$heading_list_nodes = ( new GDTG_Docx_Parser( $heading_list_fixture ) )->parse_nodes();
assert_same( 'list', $heading_list_nodes[0]->type, 'list numbering takes precedence over heading style' );
assert_same( 'list_item', $heading_list_nodes[0]->children[0]->type, 'styled numbered paragraph becomes list item' );

$alignment_fixture = create_docx_fixture(
	para( text_run( 'Centered' ), paragraph_props( '', null, null, 'center' ) ) .
	para( text_run( 'Justified' ), paragraph_props( '', null, null, 'both' ) ) .
	para( text_run( 'Distributed' ), paragraph_props( '', null, null, 'distribute' ) )
);
$cleanup[] = $alignment_fixture;
$alignment_nodes = ( new GDTG_Docx_Parser( $alignment_fixture ) )->parse_nodes();
assert_same( 'center', $alignment_nodes[0]->attrs['align'], 'center maps to center align' );
assert_same( 'left', $alignment_nodes[1]->attrs['align'], 'both maps to left align' );
assert_true( empty( $alignment_nodes[2]->attrs['align'] ), 'unknown alignment value is ignored' );

$empty_para_fixture = create_docx_fixture(
	para( text_run( '   ' ) ) .
	para( text_run( 'Visible text' ) )
);
$cleanup[] = $empty_para_fixture;
$empty_para_nodes = ( new GDTG_Docx_Parser( $empty_para_fixture ) )->parse_nodes();
assert_same( 1, count( $empty_para_nodes ), 'whitespace-only paragraph is skipped' );
assert_contains( $empty_para_nodes[0]->content, 'Visible text', 'visible paragraph remains after whitespace paragraph skip' );

$page_break_fixture = create_docx_fixture( page_break_para() );
$cleanup[] = $page_break_fixture;
$page_break_nodes = ( new GDTG_Docx_Parser( $page_break_fixture ) )->parse_nodes();
assert_same( 1, count( $page_break_nodes ), 'page break fixture produces one node' );
assert_same( 'nextpage', $page_break_nodes[0]->type, 'page break paragraph becomes nextpage node' );

$link_fixture = create_docx_fixture(
	para( hyperlink( 'rIdGood', 'Allowed link' ) . hyperlink( 'rIdBad', 'Blocked link' ) . hyperlink( 'rIdMissing', 'Missing link' ) ),
	null,
	rels_xml( array( 'rIdGood' => 'https://example.com/path', 'rIdBad' => 'javascript:alert(1)' ) )
);
$cleanup[] = $link_fixture;
$link_nodes = ( new GDTG_Docx_Parser( $link_fixture ) )->parse_nodes();
assert_contains( $link_nodes[0]->content, '<a href="https://example.com/path">Allowed link</a>', 'http/https hyperlink renders anchor' );
assert_contains( $link_nodes[0]->content, 'Blocked link', 'blocked hyperlink keeps text' );
assert_contains( $link_nodes[0]->content, 'Missing link', 'missing relationship keeps text' );
assert_not_contains( $link_nodes[0]->content, 'javascript:alert(1)', 'non-http hyperlink target is not emitted' );

$color_fixture = create_docx_fixture(
	para(
		text_run( 'Red text', run_props( array( 'color' => 'FF0000' ) ) ) .
		text_run( ' short hex', run_props( array( 'color' => 'FFF' ) ) ) .
		text_run( ' plain', run_props( array( 'bold' => true, 'italic' => true ) ) )
	)
);
$cleanup[] = $color_fixture;
$color_nodes = ( new GDTG_Docx_Parser( $color_fixture ) )->parse_nodes();
assert_contains( $color_nodes[0]->content, 'color:#FF0000', 'valid 6-digit color creates span style' );
assert_not_contains( $color_nodes[0]->content, 'color:#FFF', 'invalid short hex color is ignored' );
assert_contains( $color_nodes[0]->content, '<strong><em> plain</em></strong>', 'formatting tags wrap escaped text in stable order' );

$escaped_fixture = create_docx_fixture(
	para( text_run( '<b> & "quoted"', run_props( array( 'bold' => true ) ) ) )
);
$cleanup[] = $escaped_fixture;
$escaped_nodes = ( new GDTG_Docx_Parser( $escaped_fixture ) )->parse_nodes();
assert_contains( $escaped_nodes[0]->content, '&lt;b&gt; &amp; &quot;quoted&quot;', 'run text is HTML-escaped' );
assert_contains( $escaped_nodes[0]->content, '<strong>', 'formatting tags remain literal around escaped content' );

$table_fixture = create_docx_fixture(
	'<w:tbl><w:tr><w:tc>' . para( text_run( 'Cell line one' ) ) . para( text_run( 'Cell line two' ) ) . '</w:tc><w:tc>' . para( text_run( 'Other cell' ) ) . '</w:tc></w:tr></w:tbl>'
);
$cleanup[] = $table_fixture;
$table_nodes = ( new GDTG_Docx_Parser( $table_fixture ) )->parse_nodes();
assert_same( 1, count( $table_nodes ), 'table-only fixture produces one table node' );
assert_same( 'table', $table_nodes[0]->type, 'table fixture returns table node' );
assert_same( 'table_row', $table_nodes[0]->children[0]->type, 'table row node created' );
assert_same( 'table_cell', $table_nodes[0]->children[0]->children[0]->type, 'table cell node created' );
assert_contains( $table_nodes[0]->children[0]->children[0]->content, 'Cell line one', 'first paragraph content preserved in cell' );
assert_contains( $table_nodes[0]->children[0]->children[0]->content, 'Cell line two', 'second paragraph content concatenated in cell' );

cleanup_files( $cleanup );

echo "\nTests run: {$test_count}\n";
echo "Passed: {$pass_count}\n";
echo "Failed: {$fail_count}\n";

if ( $fail_count > 0 ) {
	exit( 1 );
}

echo "\nDOCX parser regression tests passed.\n";
