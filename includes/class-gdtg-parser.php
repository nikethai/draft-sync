<?php
/**
 * Stateful Parser Engine: Translates Google Docs JSON into a GDTG_Doc_Node AST.
 *
 * The public API offers two entry points:
 *
 *   parse_nodes() — returns GDTG_Doc_Node[] for consumption by GDTG_Block_Renderer
 *                   or any future renderer (HTML Classic Editor, .docx, etc.).
 *
 *   parse()       — backward-compatible wrapper that returns Gutenberg block
 *                   markup string. Equivalent to:
 *                     ( new GDTG_Block_Renderer() )->render( $this->parse_nodes() )
 *
 * Escaping contract:
 *   - compile_elements() escapes text runs with esc_html() and links with esc_url().
 *   - The renderer treats node content as safe inline HTML and only escapes attrs/URLs.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDTG_Parser {

	/**
	 * Decoded document JSON array.
	 *
	 * @var array
	 */
	private $document;

	/**
	 * Map of inline objects (images, drawings).
	 *
	 * @var array
	 */
	private $inline_objects = [];

	/**
	 * Active post ID to attach sideloaded images to.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Parser options.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * Count of image nodes encountered during parsing.
	 *
	 * @var int
	 */
	private $image_count = 0;

	/**
	 * List stack used during parse_nodes().
	 * Each entry is a GDTG_Doc_Node of type 'list'.
	 * Index 0 = root list, index 1 = first nested level, etc.
	 *
	 * @var GDTG_Doc_Node[]
	 */
	private $list_stack = [];

	/**
	 * Whether the ordered-ness of the current root list.
	 *
	 * @var bool
	 */
	private $list_is_ordered = false;

	/**
	 * Constructor.
	 *
	 * @param string $doc_json_string Raw Google Doc API JSON payload.
	 * @param int    $post_id         WordPress post ID for media sideloading.
	 * @param array  $options         Parser options: import_images (bool), import_tables (bool).
	 */
	public function __construct( $doc_json_string, $post_id = 0, $options = [] ) {
		$decoded              = json_decode( $doc_json_string, true );
		$this->document       = is_array( $decoded ) ? $decoded : [];
		$this->inline_objects = isset( $this->document['inlineObjects'] ) && is_array( $this->document['inlineObjects'] )
			? $this->document['inlineObjects']
			: [];
		$this->post_id        = absint( $post_id );
		$this->options        = array_merge(
			[
				'import_images' => true,
				'import_tables' => true,
				'defer_images'  => false,
			],
			is_array( $options ) ? $options : []
		);
		$this->image_count    = 0;
	}

	// ─── Public API ────────────────────────────────────────────────

	/**
	 * Parse the document and return Gutenberg block markup (backward-compatible).
	 *
	 * @return string Gutenberg Block HTML ready for post_content.
	 */
	public function parse() {
		$nodes = $this->parse_nodes();
		if ( empty( $nodes ) ) {
			return '';
		}
		return ( new GDTG_Block_Renderer() )->render( $nodes );
	}

	/**
	 * Parse the document and return the AST node tree.
	 *
	 * @return GDTG_Doc_Node[] Top-level document nodes.
	 */
	public function parse_nodes() {
		if ( empty( $this->document['body']['content'] ) || ! is_array( $this->document['body']['content'] ) ) {
			return [];
		}

		$nodes    = [];
		$elements = $this->document['body']['content'];

		foreach ( $elements as $element ) {
			if ( isset( $element['paragraph'] ) ) {
				$paragraph_nodes = $this->parse_paragraph( $element['paragraph'] );
				// Pick up any list that was flushed before this paragraph.
				if ( null !== $this->pending_list_flush ) {
					$nodes[]              = $this->pending_list_flush;
					$this->pending_list_flush = null;
				}
				foreach ( $paragraph_nodes as $node ) {
					$nodes[] = $node;
				}
			} elseif ( isset( $element['table'] ) ) {
				if ( $this->options['import_tables'] ) {
					$this->flush_list_stack( $nodes );
					$node = $this->parse_table( $element['table'] );
					if ( null !== $node ) {
						$nodes[] = $node;
					}
				}
			} elseif ( isset( $element['sectionBreak'] ) || isset( $element['pageBreak'] ) ) {
				$this->flush_list_stack( $nodes );
				if ( isset( $element['pageBreak'] ) ) {
					$nodes[] = new GDTG_Doc_Node( 'nextpage' );
				}
			}
		}

		$this->flush_list_stack( $nodes );

		return $nodes;
	}

	/**
	 * Get the total count of image nodes encountered during parsing.
	 *
	 * @return int Image count.
	 */
	public function get_image_count() {
		return $this->image_count;
	}

	// ─── Paragraph / Heading ───────────────────────────────────────

	/**
	 * Parse a paragraph element. Returns 0..N nodes.
	 *
	 * @param array $paragraph Paragraph object from Google Docs JSON.
	 * @return GDTG_Doc_Node[] Nodes produced by this paragraph.
	 */
	private function parse_paragraph( $paragraph ) {
		// 1. Bullet lists are handled statefully via the list stack.
		if ( isset( $paragraph['bullet'] ) ) {
			$this->push_list_item( $paragraph );
			return [];
		}

		// Non-list paragraph → flush any open list.
		$this->flush_list_stack_from_paragraph();

		$elements = isset( $paragraph['elements'] ) && is_array( $paragraph['elements'] )
			? $paragraph['elements']
			: [];

		// 2. Inline images — each image becomes its own node.
		$nodes = [];
		if ( $this->options['import_images'] ) {
			foreach ( $elements as $element ) {
				if ( isset( $element['inlineObjectElement'] ) ) {
					$img = $this->build_image_node( $element['inlineObjectElement']['inlineObjectId'] );
					if ( null !== $img ) {
						$nodes[] = $img;
					}
				}
			}
		} else {
			// Count images even if we skip sideloading them.
			foreach ( $elements as $element ) {
				if ( isset( $element['inlineObjectElement'] ) ) {
					$this->image_count++;
				}
			}
		}

		if ( ! empty( $nodes ) ) {
			return $nodes;
		}

		// 3. Compile inline text elements.
		$text_content = $this->compile_elements( $elements );
		if ( empty( trim( wp_strip_all_tags( $text_content ) ) ) ) {
			return [];
		}

		$named_style = isset( $paragraph['paragraphStyle']['namedStyleType'] )
			? $paragraph['paragraphStyle']['namedStyleType']
			: 'NORMAL_TEXT';

		$alignment = isset( $paragraph['paragraphStyle']['alignment'] )
			? $paragraph['paragraphStyle']['alignment']
			: 'START';

		$align = '';
		if ( 'CENTER' === $alignment ) {
			$align = 'center';
		} elseif ( 'END' === $alignment ) {
			$align = 'right';
		}

		$attrs = [];
		if ( '' !== $align ) {
			$attrs['align'] = $align;
		}

		if ( 0 === strpos( $named_style, 'HEADING_' ) ) {
			$level          = (int) substr( $named_style, -1 );
			$attrs['level'] = $level;
			return [ new GDTG_Doc_Node( 'heading', $text_content, $attrs ) ];
		}

		return [ new GDTG_Doc_Node( 'paragraph', $text_content, $attrs ) ];
	}

	// ─── Lists (stack-based nesting) ───────────────────────────────

	/**
	 * Push a list item onto the list stack, creating or extending nested lists
	 * as the nesting level requires.
	 *
	 * @param array $paragraph Paragraph object with a 'bullet' key.
	 */
	private function push_list_item( $paragraph ) {
		$elements      = isset( $paragraph['elements'] ) && is_array( $paragraph['elements'] ) ? $paragraph['elements'] : [];
		$text_content  = $this->compile_elements( $elements );
		$list_id       = isset( $paragraph['bullet']['listId'] ) ? $paragraph['bullet']['listId'] : '';
		if ( empty( $list_id ) ) {
			return;
		}

		$nesting_level = isset( $paragraph['bullet']['nestingLevel'] ) ? absint( $paragraph['bullet']['nestingLevel'] ) : 0;
		$is_ordered    = $this->is_list_ordered( $list_id );

		// Create root list node if stack is empty.
		if ( empty( $this->list_stack ) ) {
			$this->list_stack     = [];
			$this->list_is_ordered = $is_ordered;
			$this->list_stack[0]  = new GDTG_Doc_Node( 'list', '', [ 'ordered' => $is_ordered ] );
		}

		// Ensure there's a list node at the required nesting level.
		for ( $level = 1; $level <= $nesting_level; $level++ ) {
			if ( ! isset( $this->list_stack[ $level ] ) ) {
				// Create a nested list inside the last item of the parent level.
				$parent_list = $this->list_stack[ $level - 1 ];
				$last_item   = ! empty( $parent_list->children ) ? end( $parent_list->children ) : null;
				if ( $last_item && 'list_item' === $last_item->type ) {
					$nested = new GDTG_Doc_Node( 'list', '', [ 'ordered' => $is_ordered ] );
					$last_item->children[] = $nested;
					$this->list_stack[ $level ] = $nested;
				} else {
					// No parent item to nest into — bail.
					return;
				}
			}
		}

		// If the target level is shallower than current stack depth, trim.
		for ( $i = count( $this->list_stack ) - 1; $i > $nesting_level; $i-- ) {
			unset( $this->list_stack[ $i ] );
		}
		// Re-index to keep contiguous keys.
		$this->list_stack = array_values( $this->list_stack );

		// Append item at the correct level.
		$item = new GDTG_Doc_Node( 'list_item', $text_content );
		$this->list_stack[ $nesting_level ]->children[] = $item;
	}

	/**
	 * Flush the list stack into the given nodes array.
	 *
	 * @param GDTG_Doc_Node[] &$nodes Reference to the nodes accumulator.
	 */
	private function flush_list_stack( &$nodes ) {
		if ( ! empty( $this->list_stack ) ) {
			$nodes[] = $this->list_stack[0];
		}
		$this->list_stack = [];
	}

	/**
	 * Flush list stack called from parse_paragraph (non-bullet path).
	 * Uses a separate method so we don't need to pass &$nodes around.
	 */
	private function flush_list_stack_from_paragraph() {
		// We can't easily pass &$nodes from parse_paragraph since it returns an array.
		// Instead, we store a pending list node and have the caller pick it up.
		// Actually, we need a different approach: store the pending flush.
		// The cleanest way: parse_paragraph returns extra nodes that need flushing.
		// We handle this by having parse_nodes check a pending node after each call.
		// For simplicity, we'll use a pending list property.
		$this->pending_list_flush = ! empty( $this->list_stack ) ? $this->list_stack[0] : null;
		$this->list_stack = [];
	}

	/**
	 * Pending list node to prepend after parse_paragraph returns.
	 *
	 * @var GDTG_Doc_Node|null
	 */
	private $pending_list_flush = null;

	// ─── Images ────────────────────────────────────────────────────
	/**
	 * Build an image node. Behavior depends on parser options:
	 *
	 * - defer_images=true:  return a placeholder node with source_url, alt; no sideloading.
	 * - defer_images=false: sideload immediately and return a node with id, url, alt.
	 *
	 * @param string $image_id Inline object ID from Google Docs.
	 * @return GDTG_Doc_Node|null Image node or null if the image cannot be processed.
	 */
	private function build_image_node( $image_id ) {
		if ( ! isset( $this->inline_objects[ $image_id ] ) ) {
			return null;
		}
		$inline_obj = $this->inline_objects[ $image_id ];
		if ( ! isset( $inline_obj['inlineObjectProperties']['embeddedObject'] ) ) {
			return null;
		}
		$properties = $inline_obj['inlineObjectProperties']['embeddedObject'];
		if ( ! isset( $properties['imageProperties']['contentUri'] ) ) {
			return null;
		}
		$temp_url = $properties['imageProperties']['contentUri'];
		// Validate URL scheme.
		$parsed = wp_parse_url( $temp_url );
		if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			return null;
		}
		$alt_text = isset( $properties['description'] ) ? esc_attr( $properties['description'] ) : '';
		if ( empty( $alt_text ) && isset( $properties['title'] ) ) {
			$alt_text = esc_attr( $properties['title'] );
		}
		$this->image_count++;
		// Deferred mode: return a placeholder node without sideloading.
		if ( ! empty( $this->options['defer_images'] ) ) {
			return new GDTG_Doc_Node( 'image', '', [
				'source_url' => $temp_url,
				'alt'        => $alt_text,
			] );
		}
		// Normal mode: sideload immediately.
		$sideload_options = [];
		if ( isset( $this->options['optimize_images'] ) ) {
			$sideload_options['optimize_images'] = $this->options['optimize_images'];
		}
		$wp_attachment_id = GDTG_Sideloader::sideload( $temp_url, $this->post_id, $alt_text, $sideload_options );
		if ( ! $wp_attachment_id ) {
			return null;
		}
		$uploaded_url = wp_get_attachment_url( $wp_attachment_id );
		return new GDTG_Doc_Node( 'image', '', [
			'id'  => $wp_attachment_id,
			'url' => $uploaded_url,
			'alt' => $alt_text,
		] );
	}

	// ─── Tables ────────────────────────────────────────────────────

	/**
	 * Parse a table element into a table node tree.
	 *
	 * @param array $table Table object from Google Docs JSON.
	 * @return GDTG_Doc_Node|null Table node or null if empty.
	 */
	private function parse_table( $table ) {
		$rows = isset( $table['tableRows'] ) && is_array( $table['tableRows'] ) ? $table['tableRows'] : [];
		if ( empty( $rows ) ) {
			return null;
		}

		$table_node = new GDTG_Doc_Node( 'table' );

		foreach ( $rows as $row ) {
			$cells     = isset( $row['tableCells'] ) && is_array( $row['tableCells'] ) ? $row['tableCells'] : [];
			$row_node  = new GDTG_Doc_Node( 'table_row' );

			foreach ( $cells as $cell ) {
				$cell_content = isset( $cell['content'] ) && is_array( $cell['content'] ) ? $cell['content'] : [];
				$cell_html    = $this->compile_cell_content( $cell_content );
				$row_node->children[] = new GDTG_Doc_Node( 'table_cell', $cell_html );
			}

			$table_node->children[] = $row_node;
		}

		return $table_node;
	}

	/**
	 * Compile table cell content into inline HTML.
	 *
	 * Table cells contain structural elements (paragraphs, etc.) that we need
	 * to flatten into inline HTML without block comment wrappers.
	 *
	 * @param array $cell_content Array of structural elements inside a table cell.
	 * @return string Compiled inline HTML.
	 */
	private function compile_cell_content( $cell_content ) {
		if ( empty( $cell_content ) ) {
			return '';
		}

		$html = '';
		foreach ( $cell_content as $element ) {
			if ( isset( $element['paragraph'] ) ) {
				$paragraph = $element['paragraph'];
				$elements  = isset( $paragraph['elements'] ) && is_array( $paragraph['elements'] ) ? $paragraph['elements'] : [];
				$text      = $this->compile_elements( $elements );

				$named_style = isset( $paragraph['paragraphStyle']['namedStyleType'] )
					? $paragraph['paragraphStyle']['namedStyleType']
					: 'NORMAL_TEXT';

				if ( 0 === strpos( $named_style, 'HEADING_' ) ) {
					// Cell content uses inline tags only — no block wrappers.
					$html .= "<strong>{$text}</strong>";
				} else {
					// No <p> wrapper — Gutenberg rich-text expects inline HTML in cells.
					if ( '' !== $html ) {
						$html .= '<br>';
					}
					$html .= $text;
				}
			}
		}

		return $html;
	}

	// ─── Inline element compilation (unchanged logic) ──────────────

	/**
	 * Compile text runs into inline HTML with nested style tags.
	 *
	 * Content is escaped with esc_html(). Links are escaped with esc_url().
	 *
	 * @param array $elements Array of paragraph elements.
	 * @return string Compiled inline HTML.
	 */
	private function compile_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$html = '';
		foreach ( $elements as $element ) {
			if ( isset( $element['textRun'] ) ) {
				$content = isset( $element['textRun']['content'] ) ? $element['textRun']['content'] : '';
				$content = str_replace( "\n", '', $content );

				if ( '' === $content ) {
					continue;
				}

				$style   = isset( $element['textRun']['textStyle'] ) && is_array( $element['textRun']['textStyle'] ) ? $element['textRun']['textStyle'] : [];
				$wrapped = esc_html( $content );

				if ( ! empty( $style['bold'] ) ) {
					$wrapped = "<strong>{$wrapped}</strong>";
				}
				if ( ! empty( $style['italic'] ) ) {
					$wrapped = "<em>{$wrapped}</em>";
				}
				if ( ! empty( $style['strikethrough'] ) ) {
					$wrapped = "<s>{$wrapped}</s>";
				}
				if ( ! empty( $style['underline'] ) ) {
					$wrapped = '<span style="text-decoration: underline">' . $wrapped . '</span>';
				}
				if ( isset( $style['baselineOffset'] ) ) {
					if ( 'SUBSCRIPT' === $style['baselineOffset'] ) {
						$wrapped = "<sub>{$wrapped}</sub>";
					} elseif ( 'SUPERSCRIPT' === $style['baselineOffset'] ) {
						$wrapped = "<sup>{$wrapped}</sup>";
					}
				}
				if ( isset( $style['foregroundColor']['color']['rgbColor'] ) ) {
					$hex     = $this->rgb_to_hex( $style['foregroundColor']['color']['rgbColor'] );
					$wrapped = '<span style="color: ' . $hex . '" class="has-inline-color">' . $wrapped . '</span>';
				}
				if ( isset( $style['backgroundColor']['color']['rgbColor'] ) ) {
					$hex     = $this->rgb_to_hex( $style['backgroundColor']['color']['rgbColor'] );
					$wrapped = '<span style="background-color: ' . $hex . '">' . $wrapped . '</span>';
				}
				if ( isset( $style['link']['url'] ) ) {
					$url     = esc_url( $style['link']['url'] );
					$wrapped = '<a href="' . $url . '">' . $wrapped . '</a>';
				}

				$html .= $wrapped;
			}
		}
		return $html;
	}

	// ─── Helpers ───────────────────────────────────────────────────

	/**
	 * Check whether a list uses ordered numbering.
	 *
	 * @param string $list_id List ID from the bullet structure.
	 * @return bool
	 */
	private function is_list_ordered( $list_id ) {
		if ( isset( $this->document['lists'][ $list_id ]['listProperties']['nestingLevels'][0]['glyphType'] ) ) {
			$glyph = $this->document['lists'][ $list_id ]['listProperties']['nestingLevels'][0]['glyphType'];
			return in_array( $glyph, [ 'DECIMAL', 'LATIN_LOWER', 'LATIN_UPPER', 'ROMAN_LOWER', 'ROMAN_UPPER' ], true );
		}
		return false;
	}

	/**
	 * Convert Google Docs RGB ratios (0-1 floats) to hex color code.
	 *
	 * @param array $rgb RGB object with red, green, blue keys.
	 * @return string Hex color code (e.g. '#ff0000').
	 */
	private function rgb_to_hex( $rgb ) {
		$r = max( 0, min( 255, round( ( $rgb['red'] ?? 0 ) * 255 ) ) );
		$g = max( 0, min( 255, round( ( $rgb['green'] ?? 0 ) * 255 ) ) );
		$b = max( 0, min( 255, round( ( $rgb['blue'] ?? 0 ) * 255 ) ) );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}
