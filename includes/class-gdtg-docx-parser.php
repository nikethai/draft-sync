<?php
/**
 * DOCX Parser: Translates an OOXML .docx file into GDTG_Doc_Node[] AST.
 *
 * Emits the same AST shape as GDTG_Parser so both Gutenberg and Classic
 * renderers work unchanged. Only the v2 core subset is supported;
 * unsupported elements are silently skipped.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Docx_Parser
 */
class GDTG_Docx_Parser {

	/** @var string Absolute path to the .docx file. */
	private $file_path;

	/** @var int Target post ID for image sideloading. */
	private $post_id;

	/** @var array Normalized import options. */
	private $options;

	/** @var ZipArchive Open archive handle. */
	private $zip;

	/** @var array Relationship ID → target path/URL from word/_rels/document.xml.rels. */
	private $relationships = array();

	/** @var array Numbering ID → abstract numbering ID from word/numbering.xml. */
	private $numbering_instances = array();

	/** @var array Abstract numbering ID → level → numbering format. */
	private $numbering_formats = array();

	/** @var int Count of image nodes found. */
	private $image_count = 0;

	// OOXML namespace URIs.
	const NS_W  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const NS_R  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
	const NS_A  = 'http://schemas.openxmlformats.org/drawingml/2006/main';
	const NS_PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

	/**
	 * Constructor.
	 *
	 * @param string $file_path Absolute path to a validated .docx file.
	 * @param int    $post_id   Target post ID (0 for new).
	 * @param array  $options   Import options.
	 */
	public function __construct( $file_path, $post_id = 0, $options = array() ) {
		$this->file_path = $file_path;
		$this->post_id   = absint( $post_id );
		$this->options   = $options;
	}

	/**
	 * Parse the .docx into an array of GDTG_Doc_Node.
	 *
	 * @return GDTG_Doc_Node[] Top-level AST nodes.
	 */
	public function parse_nodes() {
		$this->zip = new ZipArchive();
		$result = $this->zip->open( $this->file_path, ZipArchive::RDONLY );
		if ( true !== $result ) {
			return array();
		}

		$this->load_numbering();
		$this->load_relationships();

		$doc_xml = $this->zip->getFromName( 'word/document.xml' );
		if ( false === $doc_xml ) {
			$this->zip->close();
			return array();
		}

		// Disable external entity loading before parsing (only needed on PHP < 8.0).
		if ( PHP_VERSION_ID < 80000 ) {
			$previous = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}
		$doc = simplexml_load_string( $doc_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR );
		if ( PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $previous ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}

		// Access w:body through the OOXML namespace.
		$body = false !== $doc ? $doc->children( self::NS_W )->body : null;
		if ( null === $body || 0 === count( $body ) ) {
			if ( $doc ) {
				$this->zip->close();
			}
			return array();
		}

		$doc->registerXPathNamespace( 'w', self::NS_W );
		$doc->registerXPathNamespace( 'r', self::NS_R );

		$nodes = $this->parse_body( $body );

		$this->zip->close();
		return $nodes;
	}

	/**
	 * Get the number of image nodes found during parse.
	 *
	 * @return int
	 */
	public function get_image_count() {
		return $this->image_count;
	}

	/**
	 * Load hyperlink and image relationships from word/_rels/document.xml.rels.
	 */
	private function load_relationships() {
		$rels_xml = $this->zip->getFromName( 'word/_rels/document.xml.rels' );
		if ( false === $rels_xml ) {
			return;
		}

		if ( PHP_VERSION_ID < 80000 ) {
			$previous = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}
		$rels = simplexml_load_string( $rels_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR );
		if ( PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $previous ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}

		if ( false === $rels ) {
			return;
		}

		$ns_r = 'http://schemas.openxmlformats.org/package/2006/relationships';
		$rels->registerXPathNamespace( 'r', $ns_r );

		foreach ( $rels->Relationship as $rel ) {
			$rid    = (string) $rel['Id'];
			$target = (string) $rel['Target'];
			if ( ! empty( $rid ) && ! empty( $target ) ) {
				$this->relationships[ $rid ] = $target;
			}
		}
	}

	/**
	 * Load list numbering formats from word/numbering.xml.
	 */
	private function load_numbering() {
		$numbering_xml = $this->zip->getFromName( 'word/numbering.xml' );
		if ( false === $numbering_xml ) {
			return;
		}

		if ( PHP_VERSION_ID < 80000 ) {
			$previous = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}
		$numbering = simplexml_load_string( $numbering_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR );
		if ( PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $previous ); // phpcs:ignore PHPCompatibility.FunctionUse.DeprecatedFunctions.libxml_disable_entity_loader
		}

		if ( false === $numbering ) {
			return;
		}

		$w = $numbering->children( self::NS_W );
		foreach ( $w->abstractNum as $abstract_num ) {
			$abstract_id = (string) $abstract_num->attributes( self::NS_W )->abstractNumId;
			if ( '' === $abstract_id ) {
				continue;
			}

			foreach ( $abstract_num->children( self::NS_W )->lvl as $level ) {
				$level_index = (string) $level->attributes( self::NS_W )->ilvl;
				$format = $level->children( self::NS_W )->numFmt;
				$format_val = $format ? (string) $format->attributes( self::NS_W )->val : '';
				if ( '' !== $level_index && '' !== $format_val ) {
					$this->numbering_formats[ $abstract_id ][ $level_index ] = $format_val;
				}
			}
		}

		foreach ( $w->num as $num ) {
			$num_id = (string) $num->attributes( self::NS_W )->numId;
			$abstract_num_id = $num->children( self::NS_W )->abstractNumId;
			$abstract_id = $abstract_num_id ? (string) $abstract_num_id->attributes( self::NS_W )->val : '';
			if ( '' !== $num_id && '' !== $abstract_id ) {
				$this->numbering_instances[ $num_id ] = $abstract_id;
			}
		}
	}

	/**
	 * Parse w:body children into GDTG_Doc_Node[].
	 *
	 * @param SimpleXMLElement $body The w:body element.
	 * @return GDTG_Doc_Node[]
	 */
	private function parse_body( $body ) {
		$nodes      = array();
		$list_stack = array();

		foreach ( $body->children( self::NS_W ) as $child ) {
			$tag = $child->getName();

			if ( 'p' === $tag ) {
				$node = $this->parse_paragraph( $child );
				if ( null === $node ) {
					continue;
				}

				if ( 'list_item' === $node->type ) {
					$this->push_list_item( $nodes, $list_stack, $node );
					continue;
				}

				// Non-list paragraph: close any open list first.
				$list_stack = array();
				$nodes[] = $node;

			} elseif ( 'tbl' === $tag ) {
				$list_stack = array();
				$nodes[] = $this->parse_table( $child );
			}
			// Other body children (sectPr, etc.) are skipped.
		}

		return $nodes;
	}

	/**
	 * Push a list item into the current list stack, preserving nesting.
	 *
	 * @param GDTG_Doc_Node[] $nodes      Top-level nodes accumulator.
	 * @param GDTG_Doc_Node[] $list_stack Active list node per nesting level.
	 * @param GDTG_Doc_Node   $item       List item node.
	 */
	private function push_list_item( &$nodes, &$list_stack, $item ) {
		$level = isset( $item->attrs['level'] ) ? absint( $item->attrs['level'] ) : 0;
		$is_ordered = ! empty( $item->attrs['ordered'] );
		unset( $item->attrs['level'], $item->attrs['ordered'] );

		if ( empty( $list_stack ) || ! isset( $list_stack[ $level ] ) || ! $this->list_matches_type( $list_stack[ $level ], $is_ordered ) ) {
			$list = new GDTG_Doc_Node( 'list', '', $is_ordered ? array( 'ordered' => true ) : array() );
			if ( 0 === $level || empty( $list_stack[ $level - 1 ] ) ) {
				$nodes[] = $list;
			} else {
				$parent_list = $list_stack[ $level - 1 ];
				$parent_item = ! empty( $parent_list->children ) ? $parent_list->children[ count( $parent_list->children ) - 1 ] : null;
				if ( ! ( $parent_item instanceof GDTG_Doc_Node ) || 'list_item' !== $parent_item->type ) {
					$nodes[] = $list;
				} else {
					$parent_item->children[] = $list;
				}
			}
			$list_stack[ $level ] = $list;
		}

		foreach ( array_keys( $list_stack ) as $stack_level ) {
			if ( $stack_level > $level ) {
				unset( $list_stack[ $stack_level ] );
			}
		}

		$list_stack[ $level ]->children[] = $item;
	}

	/**
	 * Check whether a list node has the requested orderedness.
	 *
	 * @param GDTG_Doc_Node $list       List node.
	 * @param bool          $is_ordered Requested orderedness.
	 * @return bool
	 */
	private function list_matches_type( $list, $is_ordered ) {
		return ! empty( $list->attrs['ordered'] ) === $is_ordered;
	}


	/**
	 * Parse a w:p element into a GDTG_Doc_Node.
	 *
	 * @param SimpleXMLElement $para The w:p element.
	 * @return GDTG_Doc_Node|null Null if paragraph should be skipped.
	 */
	private function parse_paragraph( $para ) {
		$pPr = $para->children( self::NS_W )->pPr;
		$attrs   = array();
		$is_list = false;

		if ( $pPr ) {
			// Check for heading style.
			$style = $pPr->children( self::NS_W )->pStyle;
			if ( $style ) {
				$style_val = (string) $style->attributes( self::NS_W )->val;
				if ( preg_match( '/^Heading(\d)$/i', $style_val, $m ) ) {
					$level = max( 1, min( 6, (int) $m[1] ) );
					$attrs['level'] = $level;
				}
			}

			// Check for list numbering.
			$numPr = $pPr->children( self::NS_W )->numPr;
			if ( $numPr ) {
				$is_list = true;
				$num_id = $numPr->children( self::NS_W )->numId;
				$level = $numPr->children( self::NS_W )->ilvl;
				$num_val = $num_id ? (string) $num_id->attributes( self::NS_W )->val : '';
				$level_val = $level ? (string) $level->attributes( self::NS_W )->val : '0';
				$format = $this->get_numbering_format( $num_val, $level_val );
				if ( '' === $format && absint( $num_val ) > 1 ) {
					$format = 'decimal';
				}
				if ( $this->is_ordered_numbering_format( $format ) ) {
					$attrs['ordered'] = true;
				}
				$attrs['level'] = absint( $level_val );
			}

			// Check for alignment.
			$jc = $pPr->children( self::NS_W )->jc;
			if ( $jc ) {
				$jc_val = (string) $jc->attributes( self::NS_W )->val;
				$align_map = array( 'center' => 'center', 'right' => 'right', 'both' => 'left' );
				if ( isset( $align_map[ $jc_val ] ) ) {
					$attrs['align'] = $align_map[ $jc_val ];
				}
			}
		}

		// Detect page break paragraph: contains only a w:br w:type="page" run.
		$is_pagebreak = false;
		foreach ( $para->children( self::NS_W ) as $child ) {
			if ( 'r' === $child->getName() ) {
				$br = $child->children( self::NS_W )->br;
				if ( $br && 'page' === (string) $br->attributes( self::NS_W )->type ) {
					$is_pagebreak = true;
				}
			}
		}
		if ( $is_pagebreak ) {
			return new GDTG_Doc_Node( 'nextpage' );
		}

		// Parse inline content.
		$content = $this->parse_paragraph_content( $para );

		if ( $is_list ) {
			return new GDTG_Doc_Node( 'list_item', $content, $attrs, array() );
		}

		if ( empty( trim( wp_strip_all_tags( $content ) ) ) ) {
			return null;
		}

		$type = isset( $attrs['level'] ) ? 'heading' : 'paragraph';
		return new GDTG_Doc_Node( $type, $content, $attrs, array() );
	}

	/**
	 * Resolve the numbering format for a w:numId and w:ilvl pair.
	 *
	 * @param string $num_id Numbering instance ID.
	 * @param string $level  Nesting level.
	 * @return string Numbering format, e.g. bullet or decimal.
	 */
	private function get_numbering_format( $num_id, $level ) {
		if ( '' === $num_id || ! isset( $this->numbering_instances[ $num_id ] ) ) {
			return '';
		}

		$abstract_id = $this->numbering_instances[ $num_id ];
		if ( isset( $this->numbering_formats[ $abstract_id ][ $level ] ) ) {
			return $this->numbering_formats[ $abstract_id ][ $level ];
		}

		return isset( $this->numbering_formats[ $abstract_id ]['0'] ) ? $this->numbering_formats[ $abstract_id ]['0'] : '';
	}

	/**
	 * Check whether a numbering format should render as an ordered list.
	 *
	 * @param string $format OOXML numFmt value.
	 * @return bool
	 */
	private function is_ordered_numbering_format( $format ) {
		return in_array(
			$format,
			array(
				'decimal',
				'decimalZero',
				'upperRoman',
				'lowerRoman',
				'upperLetter',
				'lowerLetter',
				'ordinal',
				'cardinalText',
				'ordinalText',
			),
			true
		);
	}

	/**
	 * Parse the inline content (runs, hyperlinks) of a w:p into HTML string.
	 *
	 * @param SimpleXMLElement $para The w:p element.
	 * @return string Escaped inline HTML.
	 */
	private function parse_paragraph_content( $para ) {
		$parts = array();

		foreach ( $para->children( self::NS_W ) as $child ) {
			$tag = $child->getName();

			if ( 'r' === $tag ) {
				$parts[] = $this->parse_run( $child );
			} elseif ( 'hyperlink' === $tag ) {
				$parts[] = $this->parse_hyperlink( $child );
			}
			// pPr, bookmarkStart/End, etc. are skipped.
		}

		return implode( '', $parts );
	}

	/**
	 * Parse a w:r element into escaped inline HTML.
	 *
	 * @param SimpleXMLElement $run The w:r element.
	 * @return string
	 */
	private function parse_run( $run ) {
		// Check for page break.
		$br = $run->children( self::NS_W )->br;
		if ( $br ) {
			$br_type = (string) $br->attributes( self::NS_W )->type;
			if ( 'page' === $br_type ) {
				// Page break is handled at paragraph level; return empty here.
				// The paragraph containing only a page break should be a nextpage node.
				return '';
			}
		}

		$rPr = $run->children( self::NS_W )->rPr;
		$open_tags  = array();
		$close_tags = array();

		if ( $rPr ) {
			// Bold.
			if ( isset( $rPr->b ) ) {
				$open_tags[]  = '<strong>';
				$close_tags[] = '</strong>';
			}
			// Italic.
			if ( isset( $rPr->i ) ) {
				$open_tags[]  = '<em>';
				$close_tags[] = '</em>';
			}
			// Underline.
			if ( isset( $rPr->u ) ) {
				$open_tags[]  = '<u>';
				$close_tags[] = '</u>';
			}
			// Strikethrough.
			if ( isset( $rPr->strike ) ) {
				$open_tags[]  = '<s>';
				$close_tags[] = '</s>';
			}
			// Color.
			$color = $rPr->children( self::NS_W )->color;
			if ( $color ) {
				$hex = (string) $color->attributes( self::NS_W )->val;
				if ( preg_match( '/^[0-9A-Fa-f]{6}$/', $hex ) ) {
					$open_tags[]  = '<span style="color:#' . esc_attr( $hex ) . '">';
					$close_tags[] = '</span>';
				}
			}
		}

		// Extract text from w:t children.
		$text = '';
		foreach ( $run->children( self::NS_W ) as $child ) {
			if ( 't' === $child->getName() ) {
				$text .= (string) $child;
			}
		}

		if ( '' === $text ) {
			return '';
		}

		$escaped = esc_html( $text );
		return implode( '', $open_tags ) . $escaped . implode( '', array_reverse( $close_tags ) );
	}

	/**
	 * Parse a w:hyperlink element into an <a> tag.
	 *
	 * @param SimpleXMLElement $hyperlink The w:hyperlink element.
	 * @return string
	 */
	private function parse_hyperlink( $hyperlink ) {
		$rid = (string) $hyperlink->attributes( self::NS_R )->id;
		$url = '';

		if ( ! empty( $rid ) && isset( $this->relationships[ $rid ] ) ) {
			$target = $this->relationships[ $rid ];
			// Only allow http/https links.
			if ( preg_match( '#^https?://#i', $target ) ) {
				$url = $target;
			}
		}

		$text = '';
		foreach ( $hyperlink->children( self::NS_W ) as $run ) {
			if ( 'r' === $run->getName() ) {
				$text .= $this->parse_run( $run );
			}
		}

		if ( empty( $url ) || '' === $text ) {
			return $text;
		}

		return '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
	}

	/**
	 * Parse a w:tbl element into a table GDTG_Doc_Node.
	 *
	 * @param SimpleXMLElement $tbl The w:tbl element.
	 * @return GDTG_Doc_Node
	 */
	private function parse_table( $tbl ) {
		$rows = array();

		foreach ( $tbl->children( self::NS_W ) as $child ) {
			if ( 'tr' !== $child->getName() ) {
				continue;
			}
			$cells = array();
			foreach ( $child->children( self::NS_W ) as $cell ) {
				if ( 'tc' !== $cell->getName() ) {
					continue;
				}
				// Cell content: parse child paragraphs and concatenate.
				$cell_content = '';
				foreach ( $cell->children( self::NS_W ) as $cell_para ) {
					if ( 'p' === $cell_para->getName() ) {
						$cell_content .= $this->parse_paragraph_content( $cell_para );
					}
				}
				$cells[] = new GDTG_Doc_Node( 'table_cell', $cell_content );
			}
			$rows[] = new GDTG_Doc_Node( 'table_row', '', array(), $cells );
		}

		return new GDTG_Doc_Node( 'table', '', array(), $rows );
	}
}
