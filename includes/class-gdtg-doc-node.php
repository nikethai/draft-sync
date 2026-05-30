<?php
/**
 * Immutable value object representing a single node in the DraftSync document AST.
 *
 * Each node carries a semantic type (e.g. 'paragraph', 'heading', 'image'),
 * optional inline content already escaped by the parser, arbitrary key-value
 * attributes, and zero-or-more child nodes for nested structures (lists, tables).
 *
 * PHP 7.4-compatible — plain public properties, no typed properties or
 * constructor promotion to keep the minimum WP requirement low.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Doc_Node
 *
 * Lightweight AST node used between the parser and block renderer.
 */
class GDTG_Doc_Node {

	/**
	 * Semantic type of the node.
	 *
	 * Known values: 'paragraph', 'heading', 'image', 'list', 'list_item',
	 * 'table', 'table_row', 'table_cell', 'nextpage'.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Inline HTML content. Already escaped by the parser; the renderer must
	 * NOT re-escape this value.
	 *
	 * @var string
	 */
	public $content;

	/**
	 * Key-value attributes consumed by the renderer (level, align, url, id, …).
	 *
	 * @var array
	 */
	public $attrs;

	/**
	 * Child nodes for nested structures.
	 *
	 * @var GDTG_Doc_Node[]
	 */
	public $children;

	/**
	 * Constructor.
	 *
	 * @param string            $type     Node type.
	 * @param string            $content  Inline HTML content (default '').
	 * @param array             $attrs    Attributes map (default []).
	 * @param GDTG_Doc_Node[]  $children Child nodes (default []).
	 */
	public function __construct( $type, $content = '', $attrs = [], $children = [] ) {
		$this->type     = (string) $type;
		$this->content  = (string) $content;
		$this->attrs    = is_array( $attrs ) ? $attrs : [];
		$this->children = is_array( $children ) ? $children : [];
	}
}
