<?php
/**
 * Test suite for GDTG_Post_Meta_Applier.
 */

// Define standard mocks if not running in WordPress context.
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

	// Mock global post meta storage.
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

	// Mock tags/categories.
	global $mock_terms, $mock_post_terms, $mock_current_user_caps;
	$mock_terms = array();
	$mock_post_terms = array();
	$mock_current_user_caps = array( 'edit_posts' => true );

	function current_user_can( $cap ) {
		global $mock_current_user_caps;
		return isset( $mock_current_user_caps[ $cap ] ) && $mock_current_user_caps[ $cap ];
	}

	function get_term( $term_id, $taxonomy ) {
		global $mock_terms;
		if ( isset( $mock_terms[ $taxonomy ][ $term_id ] ) ) {
			return $mock_terms[ $taxonomy ][ $term_id ];
		}
		return null;
	}

	function get_term_by( $field, $value, $taxonomy ) {
		global $mock_terms;
		if ( isset( $mock_terms[ $taxonomy ] ) ) {
			foreach ( $mock_terms[ $taxonomy ] as $term ) {
				if ( 'slug' === $field && $term->slug === $value ) {
					return $term;
				}
				if ( 'name' === $field && $term->name === $value ) {
					return $term;
				}
			}
		}
		return null;
	}

	function wp_insert_term( $term_name, $taxonomy ) {
		global $mock_terms;
		if ( ! isset( $mock_terms[ $taxonomy ] ) ) {
			$mock_terms[ $taxonomy ] = array();
		}
		$new_id = count( $mock_terms[ $taxonomy ] ) + 100;
		$term = (object) array(
			'term_id' => $new_id,
			'name'    => $term_name,
			'slug'    => sanitize_title( $term_name ),
		);
		$mock_terms[ $taxonomy ][ $new_id ] = $term;
		return array( 'term_id' => $new_id );
	}

	function wp_set_post_categories( $post_id, $cats ) {
		global $mock_post_terms;
		$mock_post_terms[ $post_id ]['category'] = $cats;
		return true;
	}

	function wp_set_post_tags( $post_id, $tags ) {
		global $mock_post_terms;
		$mock_post_terms[ $post_id ]['post_tag'] = $tags;
		return true;
	}

	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '-', $title ) );
	}
	function absint( $val ) {
		return abs( intval( $val ) );
	}

	function sanitize_text_field( $field ) {
		return trim( strip_tags( $field ) );
	}

	function sanitize_textarea_field( $field ) {
		return trim( $field );
	}

	function esc_url_raw( $url ) {
		return $url;
	}

	// Mock featured image setting.
	global $mock_post_thumbnails;
	$mock_post_thumbnails = array();

	function set_post_thumbnail( $post_id, $attachment_id ) {
		global $mock_post_thumbnails;
		$mock_post_thumbnails[ $post_id ] = $attachment_id;
		return true;
	}

	// Mock active filters.
	global $mock_filters;
	$mock_filters = array();

	function add_filter( $tag, $callback ) {
		global $mock_filters;
		$mock_filters[ $tag ][] = $callback;
	}

	function apply_filters( $tag, $value, ...$args ) {
		global $mock_filters;
		if ( isset( $mock_filters[ $tag ] ) ) {
			foreach ( $mock_filters[ $tag ] as $callback ) {
				$value = call_user_func( $callback, $value, ...$args );
			}
		}
		return $value;
	}
}

// Load necessary files.
require_once __DIR__ . '/../includes/class-gdtg-doc-node.php';
require_once __DIR__ . '/../includes/class-gdtg-post-meta-applier.php';

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

// Start tests.
suite( 'Post Meta Applier: Metadata Table Extraction' );

$applier = new GDTG_Post_Meta_Applier();

// Test case 1: No nodes.
$extracted = $applier->extract_metadata_table( array() );
assert_equals( array(), $extracted['metadata'], 'Empty nodes returns no metadata' );

// Test case 2: First node is non-table node.
$p_node = new GDTG_Doc_Node( 'paragraph', 'Some text' );
$extracted = $applier->extract_metadata_table( array( $p_node ) );
assert_equals( array(), $extracted['metadata'], 'Plain paragraph first node returns no metadata' );
assert_equals( 1, count( $extracted['nodes'] ), 'Plain paragraph node is preserved (not stripped)' );

// Test case 3: 3-column table first node is not metadata table.
$cell = new GDTG_Doc_Node( 'table_cell', 'txt' );
$row = new GDTG_Doc_Node( 'table_row', '', array(), array( $cell, $cell, $cell ) );
$three_col_table = new GDTG_Doc_Node( 'table', '', array(), array( $row ) );
$extracted = $applier->extract_metadata_table( array( $three_col_table ) );
assert_equals( array(), $extracted['metadata'], '3-column table is ignored' );

// Test case 4: Valid 2-column Metadata Table with header.
$cell_meta = new GDTG_Doc_Node( 'table_cell', 'Metadata' );
$cell_val = new GDTG_Doc_Node( 'table_cell', 'Value' );
$header = new GDTG_Doc_Node( 'table_row', '', array(), array( $cell_meta, $cell_val ) );

$c_slug = new GDTG_Doc_Node( 'table_cell', 'Slug' );
$v_slug = new GDTG_Doc_Node( 'table_cell', 'my-custom-slug' );
$row_slug = new GDTG_Doc_Node( 'table_row', '', array(), array( $c_slug, $v_slug ) );

$c_seo_desc = new GDTG_Doc_Node( 'table_cell', 'SEO Description' );
$v_seo_desc = new GDTG_Doc_Node( 'table_cell', 'This is a description.' );
$row_desc = new GDTG_Doc_Node( 'table_row', '', array(), array( $c_seo_desc, $v_seo_desc ) );

$c_cats = new GDTG_Doc_Node( 'table_cell', 'Categories' );
$v_cats = new GDTG_Doc_Node( 'table_cell', 'News, Tech, Science' );
$row_cats = new GDTG_Doc_Node( 'table_row', '', array(), array( $c_cats, $v_cats ) );

$c_fake = new GDTG_Doc_Node( 'table_cell', 'acf:author_bio' );
$v_fake = new GDTG_Doc_Node( 'table_cell', 'Expert developer bio' );
$row_fake = new GDTG_Doc_Node( 'table_row', '', array(), array( $c_fake, $v_fake ) );

$c_meta = new GDTG_Doc_Node( 'table_cell', 'meta:draftsync_sub_id' );
$v_meta = new GDTG_Doc_Node( 'table_cell', '456' );
$row_meta = new GDTG_Doc_Node( 'table_row', '', array(), array( $c_meta, $v_meta ) );

$meta_table = new GDTG_Doc_Node( 'table', '', array(), array( $header, $row_slug, $row_desc, $row_cats, $row_fake, $row_meta ) );
$nodes_pool = array( $meta_table, $p_node );

$extracted = $applier->extract_metadata_table( $nodes_pool );
$metadata = $extracted['metadata'];

assert_equals( 'my-custom-slug', $metadata['slug'], 'Metadata slug extracted' );
assert_equals( 'This is a description.', $metadata['seo']['description'], 'Metadata SEO Description mapped to sub-array' );
assert_equals( array( 'News', 'Tech', 'Science' ), $metadata['categories'], 'Metadata categories CSV parsed correctly' );
assert_equals( 'Expert developer bio', $metadata['acf']['author_bio'], 'ACF prefixed key extracted' );
assert_equals( '456', $metadata['meta']['draftsync_sub_id'], 'Meta prefixed key extracted' );
assert_equals( 1, count( $extracted['nodes'] ), 'Metadata table stripped from nodes' );
assert_equals( 'Some text', $extracted['nodes'][0]->content, 'Subsequent document nodes are preserved' );

// Test case 5: Metadata table without header but recognized keys.
$no_header_pool = array(
	new GDTG_Doc_Node( 'table', '', array(), array( $row_slug, $row_desc ) ),
	$p_node,
);
$extracted_no_header = $applier->extract_metadata_table( $no_header_pool );
assert_equals( 'my-custom-slug', $extracted_no_header['metadata']['slug'], 'Metadata recognized without column headers when key matched' );

suite( 'Post Meta Applier: Applying Metadata to Post' );

global $mock_post_meta, $mock_post_terms, $mock_post_thumbnails, $mock_terms, $mock_current_user_caps;

// Add mock categories.
$mock_terms['category'][10] = (object) array( 'term_id' => 10, 'name' => 'News', 'slug' => 'news' );

$applier = new GDTG_Post_Meta_Applier();

$meta_payload = array(
	'categories'     => array( 10, 'Tech' ), // 10 is existing ID; 'Tech' is new.
	'tags'           => array( 'WordPress', 'Google Docs' ),
	'featured_image' => 'first',
	'seo'            => array(
		'title'         => 'Custom SEO Title',
		'description'   => 'Custom SEO description.',
		'focus_keyword' => 'wp sync',
		'canonical'     => 'https://google.com/',
	),
	'meta'           => array(
		'draftsync_key_one' => 'val1',
		'gdtg_key_two'      => 'val2',
		'_private_key'      => 'forbidden',
	),
);

// Setup node AST containing mock processed image sideload.
$img_node = new GDTG_Doc_Node( 'image', '', array( 'id' => 77, 'source_name' => 'my-hero.png' ) );
$nodes_with_images = array( $p_node, $img_node );

// Assign capability to avoid term creation restriction.
$mock_current_user_caps['manage_categories'] = true;

$warnings = $applier->apply( 5, $nodes_with_images, $meta_payload );

assert_equals( array( 'Custom meta key _private_key rejected due to security configuration.' ), $warnings, 'No warnings returned on successful metadata apply (except safe private key warning)' );
assert_equals( array( 10, 101 ), $mock_post_terms[5]['category'], 'Correct categories resolved/created' );
assert_equals( array( 100, 101 ), $mock_post_terms[5]['post_tag'], 'Correct tags resolved/created' );
assert_equals( 77, $mock_post_thumbnails[5], 'Featured image sideload resolved from "first" node' );

// Verify SEO.
assert_equals( 'Custom SEO Title', get_post_meta( 5, '_yoast_wpseo_title', true ), 'Yoast title set' );
assert_equals( 'Custom SEO Title', get_post_meta( 5, 'rank_math_title', true ), 'RankMath title set' );
assert_equals( 'Custom SEO description.', get_post_meta( 5, '_yoast_wpseo_metadesc', true ), 'Yoast description set' );
assert_equals( 'Custom SEO description.', get_post_meta( 5, 'rank_math_description', true ), 'RankMath description set' );
assert_equals( 'wp sync', get_post_meta( 5, '_yoast_wpseo_focuskw', true ), 'Yoast focus keyword set' );
assert_equals( 'wp sync', get_post_meta( 5, 'rank_math_focus_keyword', true ), 'RankMath focus keyword set' );
assert_equals( 'https://google.com/', get_post_meta( 5, '_yoast_wpseo_canonical', true ), 'Yoast canonical set' );
assert_equals( 'https://google.com/', get_post_meta( 5, 'rank_math_canonical_url', true ), 'RankMath canonical set' );

// Verify Safeguard Meta keys.
assert_equals( 'val1', get_post_meta( 5, 'draftsync_key_one', true ), 'Safe meta key mapping (draftsync_)' );
assert_equals( 'val2', get_post_meta( 5, 'gdtg_key_two', true ), 'Safe meta key mapping (gdtg_)' );
assert_equals( '', get_post_meta( 5, '_private_key', true ), 'Private meta key rejected' );

// Try setting featured image by filename.
$meta_payload_fn = array( 'featured_image' => 'my-hero.png' );
$applier->apply( 5, $nodes_with_images, $meta_payload_fn );
assert_equals( 77, $mock_post_thumbnails[5], 'Featured image resolved by filename match' );

// Capability restriction check.
$mock_current_user_caps['manage_categories'] = false;
$meta_payload_unauth = array( 'categories' => array( 'Unauthorised-Cat' ) );
$warnings_unauth = $applier->apply( 5, $nodes_with_images, $meta_payload_unauth );
assert_true( count( $warnings_unauth ) > 0, 'Unauthorised term creation fails with non-fatal warning' );

// Test post data builder.
$meta_b = array( 'slug' => 'cool-slug', 'excerpt' => 'cool summary' );
$b_data = $applier->build_post_data( $meta_b );
assert_equals( 'cool-slug', $b_data['post_name'], 'Slug mapped correctly to post data' );
assert_equals( 'cool-summary', sanitize_title( $b_data['post_excerpt'] ), 'Excerpt preserved in post data' );

echo "\n==================================================\n";
echo "Post Meta Applier Test Suite PASSED successfully!\n";
echo "==================================================\n\n";
