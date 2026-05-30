<?php
/**
 * HTML Renderer: Translates a GDTG_Doc_Node AST into Classic Editor HTML.
 *
 * Produces clean HTML without Gutenberg block comments. Suitable for
 * the Classic Editor, HTML export, and non-block contexts.
 *
 * The renderer treats node `content` as safe inline HTML that was already
 * escaped by the parser; it only escapes attributes and URLs.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_HTML_Renderer
 *
 * Stateless renderer — no internal state between render() calls.
 */
class GDTG_HTML_Renderer {
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
	 * Render an array of AST nodes into Classic Editor HTML.
	 *
	 * @param GDTG_Doc_Node[] $nodes     Top-level document nodes.
	 * @param array           $overrides Optional style overrides (heading_demotion, min_heading_level, default_alignment).
	 * @return string HTML ready for post_content.
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
	 * @return string Rendered HTML or '' if type is unknown.
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
				return '<!--nextpage-->';
			default:
				return '';
		}
	}

	/**
	 * Render a paragraph node.
	 *
	 * @param GDTG_Doc_Node $node Paragraph node.
	 * @return string Paragraph HTML.
	 */
	private function render_paragraph( $node ) {
		$content = $node->content;

		$style_attr = '';
		if ( isset( $node->attrs['align'] ) ) {
			$alignment  = $node->attrs['align'];
			$style_attr = ' style="text-align:' . esc_attr( $alignment ) . ';"';
		}
		// Apply default alignment override when no explicit alignment exists.
		if ( '' === $style_attr && ! empty( $this->overrides['default_alignment'] ) ) {
			$default    = $this->overrides['default_alignment'];
			$style_attr = ' style="text-align:' . esc_attr( $default ) . ';"';
		}
		return '<p' . $style_attr . '>' . $content . '</p>';
	}

	/**
	 * Render a heading node.
	 *
	 * @param GDTG_Doc_Node $node Heading node (attrs: level, align).
	 * @return string Heading HTML.
	 */
	private function render_heading( $node ) {
		$content = $node->content;
		$level   = isset( $node->attrs['level'] ) ? absint( $node->attrs['level'] ) : 2;
		$demotion = $this->get_override( 'heading_demotion', 0 );
		$level    = max( 1, min( 6, $level + $demotion ) );
		$min_level = $this->get_override( 'min_heading_level', 1 );
		$level     = max( $level, (int) $min_level );

		$style_attr = '';
		if ( isset( $node->attrs['align'] ) ) {
			$alignment  = $node->attrs['align'];
			$style_attr = ' style="text-align:' . esc_attr( $alignment ) . ';"';
		}

		return '<h' . $level . $style_attr . '>' . $content . '</h' . $level . '>';
	}

	/**
	 * Render an image node.
	 *
	 * @param GDTG_Doc_Node $node Image node (attrs: id, url, alt).
	 * @return string Image HTML, or '' if critical attrs are missing.
	 */
	private function render_image( $node ) {
		$id  = isset( $node->attrs['id'] ) ? absint( $node->attrs['id'] ) : 0;
		$url = isset( $node->attrs['url'] ) ? esc_url( $node->attrs['url'] ) : '';
		$alt = isset( $node->attrs['alt'] ) ? esc_attr( $node->attrs['alt'] ) : '';

		if ( empty( $url ) ) {
			return '';
		}

		$class = $id ? ' class="wp-image-' . (int) $id . '"' : '';

		return '<img src="' . $url . '" alt="' . $alt . '"' . $class . ' />';
	}

	/**
	 * Render a list node.
	 *
	 * @param GDTG_Doc_Node $node List node (attrs: ordered; children are list_item nodes).
	 * @return string List HTML.
	 */
	private function render_list( $node ) {
		$is_ordered = ! empty( $node->attrs['ordered'] );
		$list_tag   = $is_ordered ? 'ol' : 'ul';

		$list_items = '';
		foreach ( $node->children as $child ) {
			if ( $child instanceof GDTG_Doc_Node && 'list_item' === $child->type ) {
				$list_items .= $this->render_list_item( $child );
			}
		}

		return '<' . $list_tag . '>' . $list_items . '</' . $list_tag . '>';
	}

	/**
	 * Render a single list item, including any nested lists.
	 *
	 * @param GDTG_Doc_Node $node list_item node.
	 * @return string <li> HTML.
	 */
	private function render_list_item( $node ) {
		$content = $node->content;

		$nested = '';
		foreach ( $node->children as $child ) {
			if ( $child instanceof GDTG_Doc_Node && 'list' === $child->type ) {
				$nested .= $this->render_list( $child );
			}
		}

		return '<li>' . $content . $nested . '</li>';
	}

	/**
	 * Render a table node.
	 *
	 * @param GDTG_Doc_Node $node Table node (children are table_row nodes).
	 * @return string Table HTML.
	 */
	private function render_table( $node ) {
		$rows_html = '';
		foreach ( $node->children as $row ) {
			if ( ! ( $row instanceof GDTG_Doc_Node ) || 'table_row' !== $row->type ) {
				continue;
			}

			$cells_html = '';
			foreach ( $row->children as $cell ) {
				if ( ! ( $cell instanceof GDTG_Doc_Node ) || 'table_cell' !== $cell->type ) {
					continue;
				}
				$cells_html .= '<td>' . $cell->content . '</td>';
			}

			$rows_html .= '<tr>' . $cells_html . '</tr>';
		}

		return '<table><tbody>' . $rows_html . '</tbody></table>';
	}
}
