<?php
/**
 * Block Renderer: Translates a GDTG_Doc_Node AST into Gutenberg block markup.
 *
 * Each render_* method handles one node type and produces valid Gutenberg
 * block comment HTML. The renderer treats node `content` as safe inline HTML
 * that was already escaped by the parser; it only escapes attributes and URLs.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Block_Renderer
 *
 * Stateless renderer — no internal state between render() calls.
 */
class GDTG_Block_Renderer {
	/**
	 * @var array Style overrides applied for this render call.
	 */
	private $overrides = array();
	/**
	 * Read an override value, falling back to a default.
	 *
	 * @param string $key     Override key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_override( $key, $default = null ) {
		return array_key_exists( $key, $this->overrides ) ? $this->overrides[ $key ] : $default;
	}


	/**
	 * Render an array of AST nodes into Gutenberg block markup.
	 *
	 * @param GDTG_Doc_Node[] $nodes     Top-level document nodes.
	 * @param array           $overrides Optional style overrides (heading_demotion, min_heading_level, default_alignment).
	 * @return string Gutenberg block HTML ready for post_content.
	 */
	public function render( $nodes, $overrides = array() ) {
		$this->overrides = $overrides;
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return '';
		}

		$blocks = [];
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}
			$rendered = $this->render_node( $node );
			if ( '' !== $rendered ) {
				$blocks[] = $rendered;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Dispatch a single node to the appropriate render method.
	 *
	 * @param GDTG_Doc_Node $node The node to render.
	 * @return string Rendered block markup or '' if type is unknown.
	 */
	private function render_node( $node ) {
		switch ( $node->type ) {
			case 'paragraph':
				return $this->render_paragraph( $node );
			case 'heading':
				return $this->render_heading( $node );
			case 'image':
				return $this->render_image( $node );
			case 'list':
				return $this->render_list( $node );
			case 'table':
				return $this->render_table( $node );
			case 'nextpage':
				return "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->";
			default:
				return '';
		}
	}

	/**
	 * Render a paragraph node.
	 *
	 * @param GDTG_Doc_Node $node Paragraph node.
	 * @return string Gutenberg paragraph block.
	 */
	private function render_paragraph( $node ) {
		$content = $node->content;

		$align_attr  = '';
		$align_class = '';

		if ( isset( $node->attrs['align'] ) ) {
			$alignment = $node->attrs['align'];
			if ( 'center' === $alignment ) {
				$align_attr  = ' {"align":"center"}';
				$align_class = ' class="has-text-align-center"';
			} elseif ( 'right' === $alignment ) {
				$align_attr  = ' {"align":"right"}';
				$align_class = ' class="has-text-align-right"';
			}
		}
		// Apply default alignment override when no explicit alignment exists.
		if ( '' === $align_attr && ! empty( $this->overrides['default_alignment'] ) ) {
			$default = $this->overrides['default_alignment'];
			if ( 'center' === $default ) {
				$align_attr  = ' {"align":"center"}';
				$align_class = ' class="has-text-align-center"';
			} elseif ( 'right' === $default ) {
				$align_attr  = ' {"align":"right"}';
				$align_class = ' class="has-text-align-right"';
			}
		}
		return "<!-- wp:paragraph{$align_attr} -->\n<p{$align_class}>{$content}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Render a heading node.
	 *
	 * @param GDTG_Doc_Node $node Heading node (attrs: level, align).
	 * @return string Gutenberg heading block.
	 */
	private function render_heading( $node ) {
		$content = $node->content;
		$level   = isset( $node->attrs['level'] ) ? absint( $node->attrs['level'] ) : 2;
		$demotion = $this->get_override( 'heading_demotion', 0 );
		$level    = max( 1, min( 6, $level + $demotion ) );
		$min_level = $this->get_override( 'min_heading_level', 1 );
		$level     = max( $level, (int) $min_level );

		$align_attr  = '';
		$align_class = '';

		if ( isset( $node->attrs['align'] ) ) {
			$alignment = $node->attrs['align'];
			if ( 'center' === $alignment ) {
				$align_attr  = ' {"align":"center"}';
				$align_class = ' class="has-text-align-center"';
			} elseif ( 'right' === $alignment ) {
				$align_attr  = ' {"align":"right"}';
				$align_class = ' class="has-text-align-right"';
			}
		}

		// Build the JSON attrs block: level is always first.
		$json_attrs = "\"level\":{$level}{$align_attr}";

		return "<!-- wp:heading {{$json_attrs}} -->\n<h{$level} class=\"wp-block-heading{$align_class}\">{$content}</h{$level}>\n<!-- /wp:heading -->";
	}

	/**
	 * Render an image node.
	 *
	 * @param GDTG_Doc_Node $node Image node (attrs: id, url, alt).
	 * @return string Gutenberg image block, or '' if critical attrs are missing.
	 */
	private function render_image( $node ) {
		$id  = isset( $node->attrs['id'] ) ? absint( $node->attrs['id'] ) : 0;
		$url = isset( $node->attrs['url'] ) ? esc_url( $node->attrs['url'] ) : '';
		$alt = isset( $node->attrs['alt'] ) ? esc_attr( $node->attrs['alt'] ) : '';

		if ( empty( $id ) || empty( $url ) ) {
			return '';
		}

		return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"{$url}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/></figure>\n<!-- /wp:image -->";
	}

	/**
	 * Render a list node.
	 *
	 * @param GDTG_Doc_Node $node List node (attrs: ordered; children are list_item nodes).
	 * @return string Gutenberg list block.
	 */
	private function render_list( $node ) {
		$is_ordered = ! empty( $node->attrs['ordered'] );
		$list_tag   = $is_ordered ? 'ol' : 'ul';
		$ordered_json = $is_ordered ? ' {"ordered":true}' : '';
		$list_items = '';
		foreach ( $node->children as $child ) {
			if ( $child instanceof GDTG_Doc_Node && 'list_item' === $child->type ) {
				$list_items .= $this->render_list_item( $child );
			}
		}
		return "<!-- wp:list{$ordered_json} -->\n<{$list_tag} class=\"wp-block-list\">{$list_items}</{$list_tag}>\n<!-- /wp:list -->";
	}
	/**
	 * Render a single list item, including any nested lists.
	 *
	 * @param GDTG_Doc_Node $node list_item node.
	 * @return string <li> HTML.
	 */
	private function render_list_item( $node ) {
		$content = $node->content;
		// Children that are nested lists.
		$nested = '';
		foreach ( $node->children as $child ) {
			if ( $child instanceof GDTG_Doc_Node && 'list' === $child->type ) {
				// For nested lists, render the inner <ul>/<ol> content without wrapping block comments.
				$nested .= $this->render_nested_list_inner( $child );
			}
		}
		return "<li>{$content}{$nested}</li>";
	}
	/**
	 * Render the inner <ul>/<ol> content of a nested list (without block comments).
	 *
	 * @param GDTG_Doc_Node $node List node.
	 * @return string Inner HTML.
	 */
	private function render_nested_list_inner( $node ) {
		$is_ordered = ! empty( $node->attrs['ordered'] );
		$list_tag   = $is_ordered ? 'ol' : 'ul';
		$items = '';
		foreach ( $node->children as $child ) {
			if ( $child instanceof GDTG_Doc_Node && 'list_item' === $child->type ) {
				$content = $child->content;
				$nested  = '';
				foreach ( $child->children as $grandchild ) {
					if ( $grandchild instanceof GDTG_Doc_Node && 'list' === $grandchild->type ) {
						$nested .= $this->render_nested_list_inner( $grandchild );
					}
				}
				$items .= "<li>{$content}{$nested}</li>";
			}
		}
		return "<{$list_tag}>{$items}</{$list_tag}>";
	}

	/**
	 * Render a table node.
	 *
	 * @param GDTG_Doc_Node $node Table node (children are table_row nodes).
	 * @return string Gutenberg table block.
	 */
	private function render_table( $node ) {
		$thead_html  = '';
		$tbody_html  = '';
		$is_first_row = true;

		foreach ( $node->children as $row ) {
			if ( ! ( $row instanceof GDTG_Doc_Node ) || 'table_row' !== $row->type ) {
				continue;
			}
			$cells_html = '';
			$tag        = $is_first_row ? 'th' : 'td';
			foreach ( $row->children as $cell ) {
				if ( ! ( $cell instanceof GDTG_Doc_Node ) || 'table_cell' !== $cell->type ) {
					continue;
				}
				$cells_html .= "<{$tag}>{$cell->content}</{$tag}>";
			}
			if ( $is_first_row ) {
				$thead_html  .= "<tr>{$cells_html}</tr>";
				$is_first_row = false;
			} else {
				$tbody_html .= "<tr>{$cells_html}</tr>";
			}
		}

		$table_inner = '';
		if ( '' !== $thead_html ) {
			$table_inner .= "<thead>{$thead_html}</thead>";
		}
		if ( '' !== $tbody_html ) {
			$table_inner .= "<tbody>{$tbody_html}</tbody>";
		}

		return "<!-- wp:table {\"hasFixedLayout\":false} -->\n<figure class=\"wp-block-table\"><table>{$table_inner}</table></figure>\n<!-- /wp:table -->";
	}
}
