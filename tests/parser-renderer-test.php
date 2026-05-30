<?php
/**
 * Standalone parser/renderer test harness.
 *
 * Shims minimal WordPress helpers so we can verify AST parsing and block
 * rendering without a full WP bootstrap. Run with: php tests/parser-renderer-test.php
 *
 * @package GoogleDocsToGutenberg
 */

// Define ABSPATH so the plugin's direct-access guards don't exit.
define( 'ABSPATH', __DIR__ . '/../' );

// ─── WP function shims ──────────────────────────────────────────────

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

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_get_attachment_url( $id ) {
	return "https://example.com/wp-content/uploads/image-{$id}.jpg";
}

// ─── WP mocks for ZIP validator ──────────────────────────────────

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
	return ( $thing instanceof WP_Error );
}

function size_format( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i = 0;
	while ( $bytes >= 1024 && $i < 3 ) {
		$bytes /= 1024;
		$i++;
	}
	return round( $bytes, 1 ) . ' ' . $units[ $i ];
}

function apply_filters( $tag, $value ) {
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

// ─── Mock GDTG_Sideloader ───────────────────────────────────────────

class GDTG_Sideloader {
	public static $last_sideload_url = '';
	public static $mock_attachment_id = 42;

	public static function sideload( $url, $post_id = 0, $alt = '', $options = [] ) {
		self::$last_sideload_url = $url;
		return self::$mock_attachment_id;
	}
}

// ─── Load classes ───────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-block-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-html-renderer.php';
require_once __DIR__ . '/../includes/class-gdtg-parser.php';
require_once __DIR__ . '/../includes/class-gdtg-zip-validator.php';
require_once __DIR__ . '/../includes/class-gdtg-docx-parser.php';

// ─── Test framework ─────────────────────────────────────────────────

$test_count    = 0;
$pass_count    = 0;
$fail_count    = 0;
$current_suite = '';

function suite( $name ) {
	global $current_suite;
	$current_suite = $name;
	echo "\n=== {$name} ===\n";
}

function assert_contains( $haystack, $needle, $label = '' ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( false !== strpos( $haystack, $needle ) ) {
		$pass_count++;
		echo "  ✓ {$label}\n";
	} else {
		$fail_count++;
		echo "  ✗ {$label}\n";
		echo "    Expected to contain: {$needle}\n";
		$preview = substr( $haystack, 0, 300 );
		echo "    Got: {$preview}\n";
	}
}

function assert_not_contains( $haystack, $needle, $label = '' ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( false === strpos( $haystack, $needle ) ) {
		$pass_count++;
		echo "  ✓ {$label}\n";
	} else {
		$fail_count++;
		echo "  ✗ {$label}\n";
		echo "    Expected NOT to contain: {$needle}\n";
	}
}

function assert_count( $expected, $array, $label = '' ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	$actual = is_array( $array ) ? count( $array ) : 0;
	if ( $expected === $actual ) {
		$pass_count++;
		echo "  ✓ {$label}\n";
	} else {
		$fail_count++;
		echo "  ✗ {$label}\n";
		echo "    Expected count: {$expected}, got: {$actual}\n";
	}
}

function assert_true( $value, $label = '' ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $value ) {
		$pass_count++;
		echo "  ✓ {$label}\n";
	} else {
		$fail_count++;
		echo "  ✗ {$label}\n";
	}
}

function assert_type( $expected, $node, $label = '' ) {
	global $test_count, $pass_count, $fail_count;
	$test_count++;
	if ( $node instanceof GDTG_Doc_Node && $expected === $node->type ) {
		$pass_count++;
		echo "  ✓ {$label}\n";
	} else {
		$actual = ( $node instanceof GDTG_Doc_Node ) ? $node->type : gettype( $node );
		$fail_count++;
		echo "  ✗ {$label}\n";
		echo "    Expected type: {$expected}, got: {$actual}\n";
	}
}

// ─── Basic fixture tests ────────────────────────────────────────────

suite( 'Basic fixture — parse_nodes() AST' );

$basic_json = file_get_contents( __DIR__ . '/fixtures/google-docs/basic.json' );
$parser     = new GDTG_Parser( $basic_json, 0 );
$nodes      = $parser->parse_nodes();

assert_count( 8, $nodes, 'basic fixture produces 8 top-level nodes' );
assert_type( 'paragraph', $nodes[0], 'node 0 is paragraph' );
assert_type( 'heading', $nodes[1], 'node 1 is heading' );
assert_type( 'paragraph', $nodes[2], 'node 2 is bold+italic paragraph' );
assert_type( 'paragraph', $nodes[3], 'node 3 is centered paragraph' );
assert_type( 'paragraph', $nodes[4], 'node 4 is link paragraph' );
assert_type( 'paragraph', $nodes[5], 'node 5 is colored paragraph' );
assert_type( 'paragraph', $nodes[6], 'node 6 is subscript paragraph' );
assert_type( 'paragraph', $nodes[7], 'node 7 is superscript paragraph' );

// Check heading attrs.
assert_true( isset( $nodes[1]->attrs['level'] ) && 2 === $nodes[1]->attrs['level'], 'heading level is 2' );

// Check alignment.
assert_true( isset( $nodes[3]->attrs['align'] ) && 'center' === $nodes[3]->attrs['align'], 'paragraph alignment is center' );

suite( 'Basic fixture — parse() Gutenberg markup' );

$markup = ( new GDTG_Parser( $basic_json, 0 ) )->parse();

assert_contains( $markup, '<!-- wp:paragraph -->', 'contains paragraph block comment' );
assert_contains( $markup, '<p>Hello world</p>', 'contains paragraph content' );
assert_contains( $markup, '<!-- wp:heading', 'contains heading block comment' );
assert_contains( $markup, '"level":2', 'heading level 2 in attrs' );
assert_contains( $markup, '<h2', 'heading h2 tag' );
assert_contains( $markup, 'Heading Two', 'heading content' );
assert_contains( $markup, '<strong>', 'bold tag present' );
assert_contains( $markup, '<em>', 'italic tag present' );
assert_contains( $markup, 'Bold and italic', 'bold+italic content' );
assert_contains( $markup, '{"align":"center"}', 'center alignment in block attrs' );
assert_contains( $markup, 'has-text-align-center', 'center alignment class' );
assert_contains( $markup, 'Centered paragraph', 'centered content' );
assert_contains( $markup, '<a href="https://example.com"', 'link with URL' );
assert_contains( $markup, 'Link text', 'link text content' );
assert_contains( $markup, 'color: #ff0000', 'foreground color hex' );
assert_contains( $markup, 'has-inline-color', 'inline color class' );
assert_contains( $markup, '<sub>', 'subscript tag' );
assert_contains( $markup, 'Subscript', 'subscript content' );
assert_contains( $markup, '<sup>', 'superscript tag' );
assert_contains( $markup, 'Superscript', 'superscript content' );

// ─── List/Table/Pagebreak fixture tests ─────────────────────────────

suite( 'List fixture — parse_nodes() AST' );

$list_json = file_get_contents( __DIR__ . '/fixtures/google-docs/list-table-pagebreak.json' );
$parser    = new GDTG_Parser( $list_json, 0 );
$nodes     = $parser->parse_nodes();

// Expected top-level nodes: list, paragraph, list, table, nextpage
assert_count( 5, $nodes, 'list fixture produces 5 top-level nodes' );
assert_type( 'list', $nodes[0], 'node 0 is unordered list' );
assert_type( 'paragraph', $nodes[1], 'node 1 is paragraph between lists' );
assert_type( 'list', $nodes[2], 'node 2 is ordered list' );
assert_type( 'table', $nodes[3], 'node 3 is table' );
assert_type( 'nextpage', $nodes[4], 'node 4 is nextpage' );

// Unordered list structure.
assert_true( empty( $nodes[0]->attrs['ordered'] ), 'first list is unordered' );
assert_count( 3, $nodes[0]->children, 'first list has 3 top-level items' );

// First item.
assert_type( 'list_item', $nodes[0]->children[0], 'first list item is list_item' );
assert_contains( $nodes[0]->children[0]->content, 'Unordered item one', 'first item content' );

// Second item has a nested list containing "Nested item".
assert_type( 'list_item', $nodes[0]->children[1], 'second item is list_item' );
assert_true( ! empty( $nodes[0]->children[1]->children ), 'second item has nested children' );
assert_type( 'list', $nodes[0]->children[1]->children[0], 'nested child is a list' );
assert_count( 1, $nodes[0]->children[1]->children[0]->children, 'nested list has 1 item' );
assert_contains( $nodes[0]->children[1]->children[0]->children[0]->content, 'Nested item', 'nested item content' );

// Third item is back to level zero.
assert_type( 'list_item', $nodes[0]->children[2], 'third item is list_item' );
assert_contains( $nodes[0]->children[2]->content, 'Back to level zero', 'third item content' );

// Ordered list.
assert_true( ! empty( $nodes[2]->attrs['ordered'] ), 'second list is ordered' );
assert_count( 2, $nodes[2]->children, 'ordered list has 2 items' );

// Table structure.
assert_count( 2, $nodes[3]->children, 'table has 2 rows' );
assert_type( 'table_row', $nodes[3]->children[0], 'first table child is table_row' );
assert_count( 2, $nodes[3]->children[0]->children, 'first row has 2 cells' );
assert_type( 'table_cell', $nodes[3]->children[0]->children[0], 'first cell is table_cell' );
assert_contains( $nodes[3]->children[0]->children[0]->content, 'Cell A1', 'cell A1 content' );
assert_contains( $nodes[3]->children[1]->children[0]->content, '<strong>', 'cell A2 has bold' );

suite( 'List fixture — parse() Gutenberg markup' );

$list_markup = ( new GDTG_Parser( $list_json, 0 ) )->parse();

assert_contains( $list_markup, '<!-- wp:list -->', 'contains unordered list block' );
assert_contains( $list_markup, '<ul class="wp-block-list">', 'contains ul tag' );
assert_contains( $list_markup, '<!-- wp:list {"ordered":true} -->', 'contains ordered list block' );
assert_contains( $list_markup, '<ol class="wp-block-list">', 'contains ol tag' );
assert_contains( $list_markup, 'Unordered item one', 'list item content' );
assert_contains( $list_markup, 'Ordered item one', 'ordered list item content' );
assert_contains( $list_markup, 'Paragraph between lists', 'paragraph between lists' );
assert_contains( $list_markup, '<!-- wp:table', 'contains table block' );
assert_contains( $list_markup, '{"hasFixedLayout":false}', 'table block delimiter has hasFixedLayout false' );
assert_contains( $list_markup, '<table>', 'contains table tag' );
assert_contains( $list_markup, 'Cell A1', 'table cell content' );
assert_contains( $list_markup, '<!-- /wp:table -->', 'contains closing table block' );
assert_contains( $list_markup, '<!-- wp:nextpage -->', 'contains nextpage block' );
assert_contains( $list_markup, '<!--nextpage-->', 'contains nextpage content' );
assert_contains( $list_markup, '<!-- /wp:nextpage -->', 'contains closing nextpage block' );

// ─── Node class tests ───────────────────────────────────────────────

suite( 'GDTG_Doc_Node value object' );

$node = new GDTG_Doc_Node( 'paragraph', 'hello', [ 'align' => 'center' ] );
assert_type( 'paragraph', $node, 'node type is paragraph' );
assert_true( 'hello' === $node->content, 'node content is hello' );
assert_true( 'center' === $node->attrs['align'], 'node attr align is center' );
assert_count( 0, $node->children, 'node has no children' );

$child = new GDTG_Doc_Node( 'list_item', 'item' );
$parent = new GDTG_Doc_Node( 'list', '', [], [ $child ] );
assert_count( 1, $parent->children, 'parent has 1 child' );
assert_type( 'list_item', $parent->children[0], 'child is list_item' );

// ─── Renderer tests ─────────────────────────────────────────────────

suite( 'GDTG_Block_Renderer edge cases' );

$renderer = new GDTG_Block_Renderer();

// Empty input.
assert_true( '' === $renderer->render( [] ), 'empty nodes renders empty string' );
assert_true( '' === $renderer->render( null ), 'null nodes renders empty string' );

// Heading level clamping.
$heading = new GDTG_Doc_Node( 'heading', 'test', [ 'level' => 9 ] );
$result  = $renderer->render( [ $heading ] );
assert_contains( $result, '"level":6', 'heading level clamped to 6' );

// Image with missing attrs.
$bad_image = new GDTG_Doc_Node( 'image' );
$result    = $renderer->render( [ $bad_image ] );
assert_true( '' === $result, 'image with missing attrs renders empty' );

// Nextpage.
$np     = new GDTG_Doc_Node( 'nextpage' );
$result = $renderer->render( [ $np ] );
assert_contains( $result, '<!-- wp:nextpage -->', 'nextpage block comment' );
assert_contains( $result, '<!--nextpage-->', 'nextpage content' );
assert_contains( $result, '<!-- /wp:nextpage -->', 'nextpage closing block comment' );

// ─── Phase 1.5: Parser options tests ────────────────────────────────

suite( 'Phase 1.5 — Parser import_tables=false skips tables' );

$list_json = file_get_contents( __DIR__ . '/fixtures/google-docs/list-table-pagebreak.json' );
$parser    = new GDTG_Parser( $list_json, 0, [ 'import_tables' => false ] );
$nodes     = $parser->parse_nodes();

// Without the table, expected: unordered list, paragraph, ordered list, nextpage
assert_count( 4, $nodes, 'skip-tables produces 4 top-level nodes (table omitted)' );
assert_type( 'list', $nodes[0], 'node 0 is unordered list' );
assert_type( 'paragraph', $nodes[1], 'node 1 is paragraph' );
assert_type( 'list', $nodes[2], 'node 2 is ordered list' );
assert_type( 'nextpage', $nodes[3], 'node 3 is nextpage' );

suite( 'Phase 1.5 — Parser import_images=false omits image nodes' );

// Even with images disabled, the basic fixture has no images, so AST is same.
$basic_json = file_get_contents( __DIR__ . '/fixtures/google-docs/basic.json' );
$parser     = new GDTG_Parser( $basic_json, 0, [ 'import_images' => false ] );
$nodes      = $parser->parse_nodes();

assert_count( 8, $nodes, 'skip-images on basic fixture still produces 8 nodes' );
assert_true( 0 === $parser->get_image_count(), 'image count is 0 on basic fixture' );

suite( 'Phase 1.5 — Parser backward compatibility (no options)' );

$parser = new GDTG_Parser( $basic_json, 0 );
$nodes  = $parser->parse_nodes();
assert_count( 8, $nodes, 'no-options parser produces 8 nodes (backward compat)' );

$parser = new GDTG_Parser( $list_json, 0 );
$nodes  = $parser->parse_nodes();
assert_count( 5, $nodes, 'no-options parser produces 5 nodes with table (backward compat)' );

// ─── Phase 1.5: Classic HTML renderer tests ─────────────────────────

suite( 'Phase 1.5 — Classic HTML renderer' );

$html_renderer = new GDTG_HTML_Renderer();

// Paragraph.
$p_node   = new GDTG_Doc_Node( 'paragraph', 'Hello world' );
$result   = $html_renderer->render( [ $p_node ] );
assert_contains( $result, '<p>Hello world</p>', 'classic paragraph renders p tag' );
assert_not_contains( $result, '<!-- wp:paragraph -->', 'classic paragraph has no block comment' );

// Heading.
$h_node   = new GDTG_Doc_Node( 'heading', 'Title', [ 'level' => 2 ] );
$result   = $html_renderer->render( [ $h_node ] );
assert_contains( $result, '<h2>Title</h2>', 'classic heading renders h2 tag' );
assert_not_contains( $result, '<!-- wp:heading', 'classic heading has no block comment' );

// Centered paragraph.
$cp_node  = new GDTG_Doc_Node( 'paragraph', 'Centered', [ 'align' => 'center' ] );
$result   = $html_renderer->render( [ $cp_node ] );
assert_contains( $result, '<p style="text-align:center;">', 'classic centered paragraph has inline style' );

// Image.
$img_node = new GDTG_Doc_Node( 'image', '', [
	'id'  => 5,
	'url' => 'https://example.com/img.jpg',
	'alt' => 'alt text',
] );
$result   = $html_renderer->render( [ $img_node ] );
assert_contains( $result, '<img src="https://example.com/img.jpg"', 'classic image renders img tag' );
assert_contains( $result, 'alt="alt text"', 'classic image has alt text' );
assert_contains( $result, 'wp-image-5', 'classic image has wp-image class' );
assert_not_contains( $result, '<!-- wp:image', 'classic image has no block comment' );

// List (unordered).
$list_node = new GDTG_Doc_Node( 'list', '', [], [
	new GDTG_Doc_Node( 'list_item', 'Item 1' ),
	new GDTG_Doc_Node( 'list_item', 'Item 2' ),
] );
$result = $html_renderer->render( [ $list_node ] );
assert_contains( $result, '<ul><li>Item 1</li><li>Item 2</li></ul>', 'classic unordered list renders correctly' );
assert_not_contains( $result, '<!-- wp:list', 'classic list has no block comment' );

// List (ordered).
$ol_node = new GDTG_Doc_Node( 'list', '', [ 'ordered' => true ], [
	new GDTG_Doc_Node( 'list_item', 'First' ),
] );
$result = $html_renderer->render( [ $ol_node ] );
assert_contains( $result, '<ol>', 'classic ordered list uses ol tag' );

// Table.
$table_node = new GDTG_Doc_Node( 'table', '', [], [
	new GDTG_Doc_Node( 'table_row', '', [], [
		new GDTG_Doc_Node( 'table_cell', 'A1' ),
		new GDTG_Doc_Node( 'table_cell', 'B1' ),
	] ),
] );
$result = $html_renderer->render( [ $table_node ] );
assert_contains( $result, '<table><tbody><tr><td>A1</td><td>B1</td></tr></tbody></table>', 'classic table renders correctly' );
assert_not_contains( $result, '<!-- wp:table', 'classic table has no block comment' );

// Nextpage.
$np_node = new GDTG_Doc_Node( 'nextpage' );
$result  = $html_renderer->render( [ $np_node ] );
assert_contains( $result, '<!--nextpage-->', 'classic nextpage renders comment' );
assert_not_contains( $result, '<!-- wp:nextpage -->', 'classic nextpage has no wp block comment' );

// Full document via Classic renderer — list fixture.
$list_json    = file_get_contents( __DIR__ . '/fixtures/google-docs/list-table-pagebreak.json' );
$parser       = new GDTG_Parser( $list_json, 0 );
$nodes        = $parser->parse_nodes();
$classic_html = $html_renderer->render( $nodes );
assert_not_contains( $classic_html, '<!-- wp:', 'classic render of full fixture has zero Gutenberg block comments' );
assert_contains( $classic_html, '<ul>', 'classic fixture contains ul' );
assert_contains( $classic_html, '<ol>', 'classic fixture contains ol' );
assert_contains( $classic_html, '<table>', 'classic fixture contains table' );
assert_contains( $classic_html, '<!--nextpage-->', 'classic fixture contains nextpage' );
// ─── Phase 1.5: Image fixture tests ────────────────────────────────
suite( 'Phase 1.5 — Parser import_images=false skips image nodes' );
$image_json = file_get_contents( __DIR__ . '/fixtures/google-docs/image.json' );
$parser     = new GDTG_Parser( $image_json, 0, [ 'import_images' => false ] );
$nodes      = $parser->parse_nodes();
// import_images=false: only text paragraphs remain (images are skipped).
assert_count( 2, $nodes, 'skip-images produces 2 text paragraph nodes' );
assert_type( 'paragraph', $nodes[0], 'node 0 is text paragraph' );
assert_contains( $nodes[0]->content, 'Before image', 'first paragraph content' );
assert_type( 'paragraph', $nodes[1], 'node 1 is text paragraph' );
assert_contains( $nodes[1]->content, 'After image', 'second paragraph content' );
// Image count still tracks encountered images.
assert_true( 2 === $parser->get_image_count(), 'image count is 2 even when skipped' );
suite( 'Phase 1.5 — Parser defer_images=true returns placeholder image nodes' );
$parser = new GDTG_Parser( $image_json, 0, [ 'import_images' => true, 'defer_images' => true ] );
$nodes  = $parser->parse_nodes();
// Should have: paragraph, image, paragraph, image
assert_count( 4, $nodes, 'defer-images produces 4 nodes (text + image + text + image)' );
assert_type( 'paragraph', $nodes[0], 'node 0 is paragraph' );
assert_type( 'image', $nodes[1], 'node 1 is image placeholder' );
assert_type( 'paragraph', $nodes[2], 'node 2 is paragraph' );
assert_type( 'image', $nodes[3], 'node 3 is image placeholder' );
// Placeholder nodes have source_url and alt, but no id or url.
assert_true( isset( $nodes[1]->attrs['source_url'] ), 'placeholder image has source_url' );
assert_true( 'https://example.com/image1.jpg' === $nodes[1]->attrs['source_url'], 'source_url matches expected URL' );
assert_true( isset( $nodes[1]->attrs['alt'] ), 'placeholder image has alt' );
assert_contains( $nodes[1]->attrs['alt'], 'First test image', 'alt text from description' );
assert_true( ! isset( $nodes[1]->attrs['id'] ), 'placeholder has no id' );
assert_true( ! isset( $nodes[1]->attrs['url'] ), 'placeholder has no url' );
// Second image uses title when description is missing.
assert_contains( $nodes[3]->attrs['alt'], 'Second image title', 'alt text falls back to title' );
assert_true( 2 === $parser->get_image_count(), 'deferred image count is 2' );
suite( 'Phase 1.5 — Parser normal mode includes id, url, alt on image nodes' );
$parser = new GDTG_Parser( $image_json, 0, [ 'import_images' => true ] );
$nodes  = $parser->parse_nodes();
assert_count( 4, $nodes, 'normal mode produces 4 nodes' );
assert_type( 'image', $nodes[1], 'node 1 is image' );
assert_true( isset( $nodes[1]->attrs['id'] ), 'normal image has id' );
assert_true( 42 === $nodes[1]->attrs['id'], 'image id matches mock sideloader id' );
assert_true( isset( $nodes[1]->attrs['url'] ), 'normal image has url' );
assert_true( isset( $nodes[1]->attrs['alt'] ), 'normal image has alt' );
assert_true( ! isset( $nodes[1]->attrs['source_url'] ), 'normal image has no source_url' );
suite( 'Phase 1.5 — Gutenberg renderer requires id for image block' );
$renderer = new GDTG_Block_Renderer();
// Placeholder image (no id) should render empty in Gutenberg mode.
$placeholder = new GDTG_Doc_Node( 'image', '', [
	'source_url' => 'https://example.com/img.jpg',
	'alt'        => 'test',
] );
$result = $renderer->render( [ $placeholder ] );
assert_true( '' === $result, 'gutenberg renderer returns empty for image without id/url' );
// Complete image (with id) renders correctly.
$complete = new GDTG_Doc_Node( 'image', '', [
	'id'  => 7,
	'url' => 'https://example.com/wp-content/uploads/img.jpg',
	'alt' => 'complete image',
] );
$result = $renderer->render( [ $complete ] );
assert_contains( $result, 'wp-image-7', 'gutenberg image has wp-image class' );
assert_contains( $result, '<!-- wp:image', 'gutenberg image has block comment' );
suite( 'Phase 1.5 — Classic renderer handles placeholder images gracefully' );
$classic = new GDTG_HTML_Renderer();
// Placeholder image (no url/id): should be safely omitted.
$placeholder = new GDTG_Doc_Node( 'image', '', [
	'source_url' => 'https://example.com/img.jpg',
	'alt'        => 'test',
] );
$result = $classic->render( [ $placeholder ] );
assert_true( '' === $result, 'classic renderer omits placeholder without url' );
// Complete image renders normally.
$complete = new GDTG_Doc_Node( 'image', '', [
	'id'  => 5,
	'url' => 'https://example.com/wp-content/uploads/image-5.jpg',
	'alt' => 'alt text',
] );
$result = $classic->render( [ $complete ] );
assert_contains( $result, '<img src="https://example.com/wp-content/uploads/image-5.jpg"', 'classic image renders img tag' );
assert_contains( $result, 'wp-image-5', 'classic image has wp-image class' );

// ─── Phase 2 — Style Override Tests (Block Renderer) ─────────────

suite( 'Phase 2 — Style overrides: default no-op' );
$overrides_renderer = new GDTG_Block_Renderer();
$h2_node = new GDTG_Doc_Node( 'heading', 'Test heading', [ 'level' => 2 ] );
$noop = $overrides_renderer->render( [ $h2_node ], [] );
$no_override = $renderer->render( [ $h2_node ] );
assert_true( $noop === $no_override, 'empty overrides produce same output as no overrides' );

suite( 'Phase 2 — Style overrides: heading demotion' );
$h1_node = new GDTG_Doc_Node( 'heading', 'Doc Title', [ 'level' => 1 ] );
$demoted = $renderer->render( [ $h1_node ], [ 'heading_demotion' => 2 ] );
assert_contains( $demoted, '"level":3', 'H1 demoted by 2 becomes level 3 in block attrs' );
assert_contains( $demoted, '<h3', 'H1 demoted by 2 becomes h3 tag' );
assert_not_contains( $demoted, '<h1', 'demoted output has no h1 tag' );

suite( 'Phase 2 — Style overrides: min heading level' );
$h2_node = new GDTG_Doc_Node( 'heading', 'Section', [ 'level' => 2 ] );
$min_clamped = $renderer->render( [ $h2_node ], [ 'min_heading_level' => 3 ] );
assert_contains( $min_clamped, '"level":3', 'H2 with min 3 becomes level 3 in block attrs' );
assert_contains( $min_clamped, '<h3', 'H2 with min 3 becomes h3 tag' );
$h4_node = new GDTG_Doc_Node( 'heading', 'Sub', [ 'level' => 4 ] );
$h4_min = $renderer->render( [ $h4_node ], [ 'min_heading_level' => 3 ] );
assert_contains( $h4_min, '"level":4', 'H4 with min 3 stays at level 4' );

suite( 'Phase 2 — Style overrides: combined demotion + min level' );
$combo = $renderer->render( [ $h1_node ], [ 'heading_demotion' => 2, 'min_heading_level' => 4 ] );
assert_contains( $combo, '"level":4', 'H1 demoted by 2 = 3, clamped to min 4' );
assert_contains( $combo, '<h4', 'combined override produces h4 tag' );

suite( 'Phase 2 — Style overrides: default alignment on paragraph' );
$plain_para = new GDTG_Doc_Node( 'paragraph', 'Body text' );
$centered = $renderer->render( [ $plain_para ], [ 'default_alignment' => 'center' ] );
assert_contains( $centered, '"align":"center"', 'default alignment adds center to block attrs' );
assert_contains( $centered, 'has-text-align-center', 'default alignment adds center class' );
$right_para = new GDTG_Doc_Node( 'paragraph', 'Right text', [ 'align' => 'right' ] );
$kept_right = $renderer->render( [ $right_para ], [ 'default_alignment' => 'center' ] );
assert_contains( $kept_right, '"align":"right"', 'explicit right alignment preserved over default center' );
assert_contains( $kept_right, 'has-text-align-right', 'explicit right class preserved' );

// ─── Phase 2 — Style Override Tests (Classic HTML Renderer) ───────

suite( 'Phase 2 — Style overrides: classic heading demotion' );
$classic = new GDTG_HTML_Renderer();
$h1_classic = new GDTG_Doc_Node( 'heading', 'Title', [ 'level' => 1 ] );
$c_demoted = $classic->render( [ $h1_classic ], [ 'heading_demotion' => 2 ] );
assert_contains( $c_demoted, '<h3', 'classic: H1 demoted by 2 becomes h3' );
assert_not_contains( $c_demoted, '<h1', 'classic: demoted has no h1' );

suite( 'Phase 2 — Style overrides: classic min heading level' );
$h2_classic = new GDTG_Doc_Node( 'heading', 'Section', [ 'level' => 2 ] );
$c_min = $classic->render( [ $h2_classic ], [ 'min_heading_level' => 3 ] );
assert_contains( $c_min, '<h3', 'classic: H2 with min 3 becomes h3' );

suite( 'Phase 2 — Style overrides: classic default alignment' );
$plain_classic = new GDTG_Doc_Node( 'paragraph', 'Body text' );
$c_centered = $classic->render( [ $plain_classic ], [ 'default_alignment' => 'center' ] );
assert_contains( $c_centered, 'text-align:center', 'classic: default center alignment adds inline style' );
$right_classic = new GDTG_Doc_Node( 'paragraph', 'Right text', [ 'align' => 'right' ] );
$c_kept = $classic->render( [ $right_classic ], [ 'default_alignment' => 'center' ] );
assert_contains( $c_kept, 'text-align:right', 'classic: explicit right preserved over default center' );


// ─── Phase 2 — ZIP Validator Tests ───────────────────────────────

suite( 'Phase 2 — ZIP Validator: valid .docx' );
$valid = GDTG_Zip_Validator::validate( __DIR__ . '/fixtures/docx/basic.docx' );
assert_true( true === $valid, 'valid .docx passes ZIP validation' );

suite( 'Phase 2 — ZIP Validator: nonexistent file' );
$missing = GDTG_Zip_Validator::validate( __DIR__ . '/fixtures/docx/nonexistent.docx' );
assert_true( is_wp_error( $missing ), 'nonexistent file returns WP_Error' );
assert_contains( $missing->get_error_code(), 'not_found', 'error code is gdtg_docx_not_found' );

suite( 'Phase 2 — ZIP Validator: bad magic bytes' );
$bad_magic = tempnam( sys_get_temp_dir(), 'gdtg-test-' );
file_put_contents( $bad_magic, 'NOT_A_ZIP_FILE' );
$result = GDTG_Zip_Validator::validate( $bad_magic );
assert_true( is_wp_error( $result ), 'bad magic bytes returns WP_Error' );
assert_contains( $result->get_error_code(), 'bad_magic', 'error code is gdtg_docx_bad_magic' );
unlink( $bad_magic );

suite( 'Phase 2 — ZIP Validator: path traversal' );
$traversal = tempnam( sys_get_temp_dir(), 'gdtg-test-' );
$zip = new ZipArchive();
$zip->open( $traversal, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '[Content_Types].xml', '<Types/>' );
$zip->addFromString( '_rels/.rels', '<Relationships/>' );
$zip->addFromString( 'word/document.xml', '<document><body/></document>' );
$zip->addFromString( '../../evil.txt', 'malicious' );
$zip->close();
$result = GDTG_Zip_Validator::validate( $traversal );
assert_true( is_wp_error( $result ), 'path traversal entry returns WP_Error' );
assert_contains( $result->get_error_code(), 'traversal', 'error code is path traversal' );
unlink( $traversal );

suite( 'Phase 2 — ZIP Validator: nested zip' );
$nested = tempnam( sys_get_temp_dir(), 'gdtg-test-' );
$zip = new ZipArchive();
$zip->open( $nested, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '[Content_Types].xml', '<Types/>' );
$zip->addFromString( '_rels/.rels', '<Relationships/>' );
$zip->addFromString( 'word/document.xml', '<document><body/></document>' );
$zip->addFromString( 'nested/bomb.zip', 'PK' . "\x03\x04" . 'fake_zip_data' );
$zip->close();
$result = GDTG_Zip_Validator::validate( $nested );
assert_true( is_wp_error( $result ), 'nested zip returns WP_Error' );
assert_contains( $result->get_error_code(), 'nested_zip', 'error code is nested zip' );
unlink( $nested );

suite( 'Phase 2 — ZIP Validator: missing document.xml' );
$no_doc = tempnam( sys_get_temp_dir(), 'gdtg-test-' );
$zip = new ZipArchive();
$zip->open( $no_doc, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '[Content_Types].xml', '<Types/>' );
$zip->addFromString( '_rels/.rels', '<Relationships/>' );
$zip->addFromString( 'word/other.xml', '<other/>' );
$zip->close();
$result = GDTG_Zip_Validator::validate( $no_doc );
assert_true( is_wp_error( $result ), 'missing document.xml returns WP_Error' );
assert_contains( $result->get_error_code(), 'no_document', 'error code is no document' );
unlink( $no_doc );

// ─── Phase 2 — DOCX Parser Tests ────────────────────────────────

suite( 'Phase 2 — DOCX Parser: basic paragraphs and heading' );
$parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/basic.docx' );
$nodes = $parser->parse_nodes();
assert_true( count( $nodes ) >= 3, 'basic.docx produces at least 3 nodes' );
assert_true( 'paragraph' === $nodes[0]->type, 'first node is paragraph' );
assert_contains( $nodes[0]->content, 'Hello World', 'first paragraph content' );
$heading_idx = null;
foreach ( $nodes as $i => $n ) {
	if ( 'heading' === $n->type ) { $heading_idx = $i; break; }
}
assert_true( null !== $heading_idx, 'basic.docx contains a heading node' );
if ( null !== $heading_idx ) {
	assert_contains( $nodes[ $heading_idx ]->content, 'Main Title', 'heading content is Main Title' );
	assert_true( 1 === $nodes[ $heading_idx ]->attrs['level'], 'heading level is 1' );
}

suite( 'Phase 2 — DOCX Parser: inline bold and italic' );
$has_bold = false;
$has_italic = false;
foreach ( $nodes as $n ) {
	if ( false !== strpos( $n->content, '<strong>' ) ) { $has_bold = true; }
	if ( false !== strpos( $n->content, '<em>' ) ) { $has_italic = true; }
}
assert_true( $has_bold, 'basic.docx contains bold text' );
assert_true( $has_italic, 'basic.docx contains italic text' );

suite( 'Phase 2 — DOCX Parser: hyperlink' );
$has_link = false;
foreach ( $nodes as $n ) {
	if ( false !== strpos( $n->content, '<a href=' ) ) { $has_link = true; break; }
}
assert_true( $has_link, 'basic.docx contains a hyperlink' );

suite( 'Phase 2 — DOCX Parser: headings fixture' );
$h_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/headings.docx' );
$h_nodes = $h_parser->parse_nodes();
$heading_levels = array();
foreach ( $h_nodes as $n ) {
	if ( 'heading' === $n->type && isset( $n->attrs['level'] ) ) {
		$heading_levels[] = $n->attrs['level'];
	}
}
assert_true( in_array( 1, $heading_levels, true ), 'headings.docx has H1' );
assert_true( in_array( 2, $heading_levels, true ), 'headings.docx has H2' );
assert_true( in_array( 3, $heading_levels, true ), 'headings.docx has H3' );

suite( 'Phase 2 — DOCX Parser: lists fixture' );
$l_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/lists.docx' );
$l_nodes = $l_parser->parse_nodes();
$list_count = 0;
$list_item_count = 0;
foreach ( $l_nodes as $n ) {
	if ( 'list' === $n->type ) {
		$list_count++;
		$list_item_count += count( $n->children );
	}
}
assert_true( $list_count >= 1, 'lists.docx has at least 1 list node' );
assert_true( $list_item_count >= 3, 'lists.docx has at least 3 list items total' );

$sample_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/draftsync-sample-guide.docx' );
$sample_nodes = $sample_parser->parse_nodes();

$flat_bullet_list = null;
$nested_bullet_list = null;
$numbered_list = null;
foreach ( $sample_nodes as $i => $n ) {
	if ( 'list' !== $n->type ) {
		continue;
	}
	$first_content = ! empty( $n->children ) ? $n->children[0]->content : '';
	if ( false !== strpos( $first_content, 'First item in a flat list' ) ) {
		$flat_bullet_list = $n;
	} elseif ( false !== strpos( $first_content, 'Top-level item' ) ) {
		$nested_bullet_list = $n;
	} elseif ( false !== strpos( $first_content, 'Install the DraftSync plugin' ) ) {
		$numbered_list = $n;
	}
}

assert_true( null !== $flat_bullet_list, 'sample guide has flat bullet list' );
assert_true( null !== $flat_bullet_list && empty( $flat_bullet_list->attrs['ordered'] ), 'DOCX numbering.xml bullet format renders unordered list' );
assert_true( null !== $nested_bullet_list, 'sample guide has nested bullet list' );
assert_count( 2, $nested_bullet_list->children, 'nested bullet list keeps only top-level items at root' );
assert_true( null !== $nested_bullet_list && ! empty( $nested_bullet_list->children[0]->children ), 'nested bullet list attaches level 1 items to parent item' );
assert_count( 2, $nested_bullet_list->children[0]->children[0]->children, 'nested bullet list preserves two nested items' );
assert_true( null !== $numbered_list && ! empty( $numbered_list->attrs['ordered'] ), 'DOCX numbering.xml decimal format renders ordered list' );

$sample_rendered = ( new GDTG_Block_Renderer() )->render( $sample_nodes );
assert_contains( $sample_rendered, '<ul class="wp-block-list">', 'sample guide bullet list renders ul' );
assert_contains( $sample_rendered, '<ol class="wp-block-list">', 'sample guide numbered list renders ol' );
assert_not_contains( $sample_rendered, '<p></p>', 'sample guide does not render empty paragraphs' );
assert_contains( $sample_rendered, '{"hasFixedLayout":false}', 'sample guide table blocks use hasFixedLayout false' );
assert_not_contains( $sample_rendered, '<!-- wp:table -->', 'sample guide has no bare table delimiter (must carry attributes)' );


suite( 'Phase 2 — DOCX Parser: tables fixture' );
$t_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/tables.docx' );
$t_nodes = $t_parser->parse_nodes();
$table_found = false;
foreach ( $t_nodes as $n ) {
	if ( 'table' === $n->type ) {
		$table_found = true;
		assert_true( count( $n->children ) === 2, 'table has 2 rows' );
		$first_row = $n->children[0];
		assert_true( 'table_row' === $first_row->type, 'first child is table_row' );
		assert_true( count( $first_row->children ) === 2, 'first row has 2 cells' );
		break;
	}
}
assert_true( $table_found, 'tables.docx contains a table node' );

suite( 'Phase 2 — DOCX Parser: inline styles' );
$s_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/inline-styles.docx' );
$s_nodes = $s_parser->parse_nodes();
$has_underline = false;
$has_strikethrough = false;
$has_color = false;
foreach ( $s_nodes as $n ) {
	if ( false !== strpos( $n->content, '<u>' ) ) { $has_underline = true; }
	if ( false !== strpos( $n->content, '<s>' ) ) { $has_strikethrough = true; }
	if ( false !== strpos( $n->content, 'color:#FF0000' ) ) { $has_color = true; }
}
assert_true( $has_underline, 'inline-styles.docx has underline' );
assert_true( $has_strikethrough, 'inline-styles.docx has strikethrough' );
assert_true( $has_color, 'inline-styles.docx has red color' );

suite( 'Phase 2 — DOCX Parser: page break' );
$p_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/page-break.docx' );
$p_nodes = $p_parser->parse_nodes();
$has_nextpage = false;
foreach ( $p_nodes as $n ) {
	if ( 'nextpage' === $n->type ) { $has_nextpage = true; break; }
}
assert_true( $has_nextpage, 'page-break.docx has a nextpage node' );

suite( 'Phase 2 — DOCX Parser: mixed fixture' );
$m_parser = new GDTG_Docx_Parser( __DIR__ . '/fixtures/docx/mixed.docx' );
$m_nodes = $m_parser->parse_nodes();
$types = array_map( function( $n ) { return $n->type; }, $m_nodes );
assert_true( in_array( 'heading', $types, true ), 'mixed.docx has headings' );
assert_true( in_array( 'paragraph', $types, true ), 'mixed.docx has paragraphs' );
assert_true( in_array( 'list', $types, true ), 'mixed.docx has lists' );
assert_true( in_array( 'table', $types, true ), 'mixed.docx has tables' );

suite( 'Phase 2 — DOCX Parser + Block Renderer integration' );
$block = new GDTG_Block_Renderer();
$rendered = $block->render( $m_nodes );
assert_contains( $rendered, '<!-- wp:heading', 'mixed doc renders heading blocks' );
assert_contains( $rendered, '<!-- wp:paragraph', 'mixed doc renders paragraph blocks' );
assert_contains( $rendered, '<!-- wp:list', 'mixed doc renders list blocks' );
assert_contains( $rendered, '<!-- wp:table', 'mixed doc renders table blocks' );

suite( 'Phase 2 — DOCX Parser + Classic Renderer integration' );
$classic_mixed = new GDTG_HTML_Renderer();
$c_rendered = $classic_mixed->render( $m_nodes );
assert_contains( $c_rendered, '<h1', 'mixed doc classic has h1' );
assert_contains( $c_rendered, '<ul', 'mixed doc classic has ul' );
assert_contains( $c_rendered, '<table', 'mixed doc classic has table' );
assert_not_contains( $c_rendered, '<!-- wp:', 'mixed doc classic has no block comments' );


// ─── Phase 3 — Source Reference Parser Tests ─────────────────────

// Standalone replica of parse_source_reference() for testing.
function parse_source_reference_test( $input ) {
	$input = trim( $input );

	if ( preg_match( '#^https?://docs\.google\.com/spreadsheets/#', $input )
		|| preg_match( '#^https?://docs\.google\.com/presentation/#', $input )
	) {
		return 'unsupported';
	}

	if ( preg_match( '#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
		$id = $matches[1];
		if ( '' !== $id && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
			return array( 'type' => 'gdoc', 'id' => $id );
		}
		return 'invalid';
	}

	if ( preg_match( '#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
		$id = $matches[1];
		if ( '' !== $id && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
			return array( 'type' => 'drive_file', 'id' => $id );
		}
	}
	if ( preg_match( '#drive\.google\.com/open\?#', $input ) ) {
		$parsed = parse_url( $input );
		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $params );
			if ( ! empty( $params['id'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $params['id'] ) ) {
				return array( 'type' => 'drive_file', 'id' => $params['id'] );
			}
		}
	}
	if ( preg_match( '#drive\.google\.com/uc\?#', $input ) ) {
		$parsed = parse_url( $input );
		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $params );
			if ( ! empty( $params['id'] ) && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $params['id'] ) ) {
				return array( 'type' => 'drive_file', 'id' => $params['id'] );
			}
		}
	}

	if ( 1 === preg_match( '/^[a-zA-Z0-9_-]+$/', $input ) ) {
		return array( 'type' => 'gdoc', 'id' => $input );
	}

	return 'invalid';
}

suite( 'Phase 3 — Source reference: native Google Docs URLs' );
$ref = parse_source_reference_test( 'https://docs.google.com/document/d/abc123_-/edit' );
assert_true( is_array( $ref ) && 'gdoc' === $ref['type'], 'native GDoc URL → gdoc type' );
assert_true( is_array( $ref ) && 'abc123_-' === $ref['id'], 'native GDoc URL → correct ID' );

$ref2 = parse_source_reference_test( 'abc123DEF_-XYZ' );
assert_true( is_array( $ref2 ) && 'gdoc' === $ref2['type'], 'raw ID → gdoc type' );
assert_true( is_array( $ref2 ) && 'abc123DEF_-XYZ' === $ref2['id'], 'raw ID → correct ID' );

suite( 'Phase 3 — Source reference: Drive file URLs' );
$ref3 = parse_source_reference_test( 'https://drive.google.com/file/d/1abcDEF_-/view?usp=sharing' );
assert_true( is_array( $ref3 ) && 'drive_file' === $ref3['type'], 'Drive file/d/ URL → drive_file type' );
assert_true( is_array( $ref3 ) && '1abcDEF_-' === $ref3['id'], 'Drive file/d/ URL → correct ID' );

$ref4 = parse_source_reference_test( 'https://drive.google.com/open?id=1abcDEF_-' );
assert_true( is_array( $ref4 ) && 'drive_file' === $ref4['type'], 'Drive open?id= URL → drive_file type' );
assert_true( is_array( $ref4 ) && '1abcDEF_-' === $ref4['id'], 'Drive open?id= URL → correct ID' );

$ref5 = parse_source_reference_test( 'https://drive.google.com/uc?id=1abcDEF_-' );
assert_true( is_array( $ref5 ) && 'drive_file' === $ref5['type'], 'Drive uc?id= URL → drive_file type' );
assert_true( is_array( $ref5 ) && '1abcDEF_-' === $ref5['id'], 'Drive uc?id= URL → correct ID' );

suite( 'Phase 3 — Source reference: unsupported formats' );
$ref6 = parse_source_reference_test( 'https://docs.google.com/spreadsheets/d/abc123/edit' );
assert_true( 'unsupported' === $ref6, 'Sheets URL → unsupported' );

$ref7 = parse_source_reference_test( 'https://docs.google.com/presentation/d/abc123/edit' );
assert_true( 'unsupported' === $ref7, 'Slides URL → unsupported' );

suite( 'Phase 3 — Source reference: invalid input' );
$ref8 = parse_source_reference_test( 'not_a_valid_url_or_id!!!' );
assert_true( 'invalid' === $ref8, 'invalid chars → invalid' );

$ref9 = parse_source_reference_test( '' );
assert_true( 'invalid' === $ref9, 'empty string → invalid' );

// ─── Summary ────────────────────────────────────────────────────────

echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Results: {$pass_count} passed, {$fail_count} failed, {$test_count} total\n";
echo str_repeat( '=', 50 ) . "\n";

exit( $fail_count > 0 ? 1 : 0 );
