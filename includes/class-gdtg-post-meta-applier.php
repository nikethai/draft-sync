<?php
/**
 * Post Meta Applier: Handles publishing metadata (SEO, taxonomy, slug, featured image).
 *
 * Consolidates the post metadata logic, parsing document tables for metadata,
 * setting post slug and excerpt, writing Yoast and RankMath SEO keys, resolving
 * terms for categories/tags, and mapping featured image selections.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Post_Meta_Applier
 */
class GDTG_Post_Meta_Applier {

	/**
	 * Recognized direct metadata keys.
	 *
	 * @var array
	 */
	private static $recognized_keys = array(
		'slug'           => 'slug',
		'url slug'       => 'slug',
		'post slug'      => 'slug',
		'excerpt'        => 'excerpt',
		'seo title'      => 'seo_title',
		'seo_title'      => 'seo_title',
		'seo description'=> 'seo_description',
		'seo_desc'       => 'seo_description',
		'seo_description'=> 'seo_description',
		'meta desc'      => 'seo_description',
		'meta description'=>'seo_description',
		'focus keyword'  => 'focus_keyword',
		'focus_keyword'  => 'focus_keyword',
		'focus kw'       => 'focus_keyword',
		'canonical url'  => 'canonical_url',
		'canonical_url'  => 'canonical_url',
		'canonical'      => 'canonical_url',
		'categories'     => 'categories',
		'category'       => 'categories',
		'tags'           => 'tags',
		'tag'            => 'tags',
		'featured image' => 'featured_image',
		'featured_image' => 'featured_image',
	);

	/**
	 * Extract metadata from the first AST node if it matches a strict two-column metadata table.
	 *
	 * Strict rules:
	 * - Only inspect the first AST node.
	 * - Node must be a table with two-columns.
	 * - Target table must have a header row of 'Metadata'/'Value', or at least some recognized metadata keys/prefixes.
	 *
	 * @param GDTG_Doc_Node[] $nodes The document AST nodes.
	 * @return array{metadata: array, nodes: GDTG_Doc_Node[]} Result metadata array and the modified nodes AST.
	 */
	public function extract_metadata_table( $nodes ) {
		$extracted = array();
		if ( empty( $nodes ) ) {
			return array(
				'metadata' => $extracted,
				'nodes'    => $nodes,
			);
		}

		$first_node = $nodes[0];
		if ( ! ( $first_node instanceof GDTG_Doc_Node ) || 'table' !== $first_node->type ) {
			return array(
				'metadata' => $extracted,
				'nodes'    => $nodes,
			);
		}

		// Table must have rows.
		$rows = $first_node->children;
		if ( empty( $rows ) ) {
			return array(
				'metadata' => $extracted,
				'nodes'    => $nodes,
			);
		}

		// Verify table columns structure: every row (or at least first one) must have exactly 2 cells.
		foreach ( $rows as $row ) {
			if ( ! ( $row instanceof GDTG_Doc_Node ) || 'table_row' !== $row->type ) {
				return array(
					'metadata' => $extracted,
					'nodes'    => $nodes,
				);
			}
			if ( count( $row->children ) !== 2 ) {
				return array(
					'metadata' => $extracted,
					'nodes'    => $nodes,
				);
			}
		}

		// First row check for Metadata / Value header.
		$header_row = $rows[0];
		$cell0_text = strtolower( trim( $this->get_cell_plain_text( $header_row->children[0] ) ) );
		$cell1_text = strtolower( trim( $this->get_cell_plain_text( $header_row->children[1] ) ) );

		$has_header = ( 'metadata' === $cell0_text && 'value' === $cell1_text );
		
		$potential_meta = array();
		$recognized_count = 0;
		$start_index = $has_header ? 1 : 0;

		$total_rows = count( $rows);
		for ( $i = $start_index; $i < $total_rows; $i++ ) {
			$row = $rows[ $i ];
			$key_raw   = trim( $this->get_cell_plain_text( $row->children[0] ) );
			$val_raw   = trim( $this->get_cell_plain_text( $row->children[1] ) );
			$key       = strtolower( $key_raw );

			if ( '' === $key ) {
				continue;
			}

			// Check standard keys.
			if ( isset( self::$recognized_keys[ $key ] ) ) {
				$norm_key = self::$recognized_keys[ $key ];
				$potential_meta[ $norm_key ] = $val_raw;
				$recognized_count++;
				continue;
			}

			// Check prefixes: acf:, meta:
			if ( 0 === strpos( $key, 'acf:' ) ) {
				$acf_field = substr( $key_raw, 4 );
				if ( ! isset( $potential_meta['acf'] ) ) {
					$potential_meta['acf'] = array();
				}
				$potential_meta['acf'][ $acf_field ] = $val_raw;
				$recognized_count++;
				continue;
			}

			if ( 0 === strpos( $key, 'meta:' ) ) {
				$meta_key = substr( $key_raw, 5 );
				if ( ! isset( $potential_meta['meta'] ) ) {
					$potential_meta['meta'] = array();
				}
				$potential_meta['meta'][ $meta_key ] = $val_raw;
				$recognized_count++;
				continue;
			}
		}

		// If no recognized keys are found and no header, it is not a metadata table.
		if ( ! $has_header && 0 === $recognized_count ) {
			return array(
				'metadata' => $extracted,
				'nodes'    => $nodes,
			);
		}

		// Convert standard keys into proper shapes.
		$norm_meta = array();
		if ( isset( $potential_meta['slug'] ) ) {
			$norm_meta['slug'] = $potential_meta['slug'];
		}
		if ( isset( $potential_meta['excerpt'] ) ) {
			$norm_meta['excerpt'] = $potential_meta['excerpt'];
		}

		// Resolve categorises and tags into CSV arrays.
		if ( isset( $potential_meta['categories'] ) ) {
			$cats = array_map( 'trim', explode( ',', $potential_meta['categories'] ) );
			$norm_meta['categories'] = array_filter( $cats );
		}
		if ( isset( $potential_meta['tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', $potential_meta['tags'] ) );
			$norm_meta['tags'] = array_filter( $tags );
		}

		if ( isset( $potential_meta['featured_image'] ) ) {
			$norm_meta['featured_image'] = $potential_meta['featured_image'];
		}

		// SEO mapping.
		$seo = array();
		if ( isset( $potential_meta['seo_title'] ) ) {
			$seo['title'] = $potential_meta['seo_title'];
		}
		if ( isset( $potential_meta['seo_description'] ) ) {
			$seo['description'] = $potential_meta['seo_description'];
		}
		if ( isset( $potential_meta['focus_keyword'] ) ) {
			$seo['focus_keyword'] = $potential_meta['focus_keyword'];
		}
		if ( isset( $potential_meta['canonical_url'] ) ) {
			$seo['canonical'] = $potential_meta['canonical_url'];
		}
		if ( ! empty( $seo ) ) {
			$norm_meta['seo'] = $seo;
		}

		// Custom Meta & ACF.
		if ( isset( $potential_meta['acf'] ) ) {
			$norm_meta['acf'] = $potential_meta['acf'];
		}
		if ( isset( $potential_meta['meta'] ) ) {
			$norm_meta['meta'] = $potential_meta['meta'];
		}

		// Strip metadata table from nodes.
		array_shift( $nodes );

		return array(
			'metadata' => $norm_meta,
			'nodes'    => $nodes,
		);
	}

	/**
	 * Helper function to retrieve the plain text content of a table cell.
	 *
	 * @param GDTG_Doc_Node $cell_node The cell node.
	 * @return string The plain text representation.
	 */
	private function get_cell_plain_text( $cell_node ) {
		if ( ! ( $cell_node instanceof GDTG_Doc_Node ) ) {
			return '';
		}

		$text = '';
		if ( ! empty( $cell_node->content ) ) {
			$text .= $cell_node->content;
		}

		if ( ! empty( $cell_node->children ) ) {
			foreach ( $cell_node->children as $child ) {
				$text .= ' ' . $this->get_cell_plain_text( $child );
			}
		}

		return preg_replace( '/\s+/', ' ', trim( $text ) );
	}

	/**
	 * Build WP post arguments from metadata array.
	 *
	 * @param array $metadata The normalized metadata options.
	 * @return array The array to merge into wp_insert_post/wp_update_post calls.
	 */
	public function build_post_data( $metadata ) {
		$post_data = array();

		if ( isset( $metadata['slug'] ) && '' !== trim( $metadata['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $metadata['slug'] );
		}

		if ( isset( $metadata['excerpt'] ) && '' !== trim( $metadata['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $metadata['excerpt'] );
		}

		return $post_data;
	}

	/**
	 * Apply metadata fields to the given post.
	 *
	 * @param int             $post_id  The target WordPress post ID.
	 * @param GDTG_Doc_Node[] $nodes    The document AST nodes (useful for images).
	 * @param array           $metadata The normalized metadata structure.
	 * @param array           $context  Optional execution context variables.
	 * @return array The non-fatal warnings encountered during mapping.
	 */
	public function apply( $post_id, $nodes, $metadata, $context = array() ) {
		$warnings = array();

		if ( empty( $post_id ) ) {
			return $warnings;
		}

		// 1. Categories
		if ( isset( $metadata['categories'] ) && is_array( $metadata['categories'] ) ) {
			$cat_ids = array();
			foreach ( $metadata['categories'] as $cat_input ) {
				$resolved = $this->resolve_term( $cat_input, 'category', 'manage_categories' );
				if ( is_wp_error( $resolved ) ) {
					$warnings[] = $resolved->get_error_message();
				} elseif ( $resolved > 0 ) {
					$cat_ids[] = $resolved;
				}
			}
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// 2. Tags
		if ( isset( $metadata['tags'] ) && is_array( $metadata['tags'] ) ) {
			$tag_ids = array();
			foreach ( $metadata['tags'] as $tag_input ) {
				$resolved = $this->resolve_term( $tag_input, 'post_tag', 'edit_posts' );
				if ( is_wp_error( $resolved ) ) {
					$warnings[] = $resolved->get_error_message();
				} elseif ( $resolved > 0 ) {
					$tag_ids[] = $resolved;
				}
			}
			wp_set_post_tags( $post_id, $tag_ids );
		}

		// 3. SEO Fields (Yoast & RankMath)
		if ( isset( $metadata['seo'] ) && is_array( $metadata['seo'] ) ) {
			$seo = $metadata['seo'];

			// Title
			if ( isset( $seo['title'] ) && '' !== trim( $seo['title'] ) ) {
				$title = sanitize_text_field( $seo['title'] );
				update_post_meta( $post_id, '_yoast_wpseo_title', $title );
				update_post_meta( $post_id, 'rank_math_title', $title );
			}

			// Description
			if ( isset( $seo['description'] ) && '' !== trim( $seo['description'] ) ) {
				$desc = sanitize_text_field( $seo['description'] );
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
				update_post_meta( $post_id, 'rank_math_description', $desc );
			}

			// Focus Keyword
			if ( isset( $seo['focus_keyword'] ) && '' !== trim( $seo['focus_keyword'] ) ) {
				$kw = sanitize_text_field( $seo['focus_keyword'] );
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $kw );
				update_post_meta( $post_id, 'rank_math_focus_keyword', $kw );
			}

			// Canonical URL
			if ( isset( $seo['canonical'] ) && '' !== trim( $seo['canonical'] ) ) {
				$url = esc_url_raw( $seo['canonical'] );
				if ( $url ) {
					update_post_meta( $post_id, '_yoast_wpseo_canonical', $url );
					update_post_meta( $post_id, 'rank_math_canonical_url', $url );
				}
			}
		}

		// 4. Featured Image
		if ( isset( $metadata['featured_image'] ) && 'none' !== $metadata['featured_image'] ) {
			$featured_ref = $metadata['featured_image'];
			$attachment_id = $this->resolve_featured_image( $featured_ref, $nodes );
			if ( $attachment_id > 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} elseif ( '' !== trim( $featured_ref ) ) {
				/* translators: %s: the image reference (filename or URL) that could not be resolved */
				$warnings[] = sprintf( __( 'Could not resolve featured image selection: %s', 'draftsync' ), $featured_ref );
			}
		}

		// 5. ACF and Custom Meta mapping (handled dynamically in Phase 6)
		if ( ! empty( $metadata['acf'] ) || ! empty( $metadata['meta'] ) ) {
			// Trigger a WordPress hook or utility function that Phase 6 will handle.
			$mappings = array(
				'acf'  => isset( $metadata['acf'] ) ? $metadata['acf'] : array(),
				'meta' => isset( $metadata['meta'] ) ? $metadata['meta'] : array(),
			);
			$mappings = apply_filters( 'gdtg_post_custom_mappings', $mappings, $post_id, $warnings );
			if ( isset( $mappings['acf'] ) && ! empty( $mappings['acf'] ) ) {
				foreach ( $mappings['acf'] as $field => $value ) {
					if ( function_exists( 'update_field' ) ) {
						update_field( $field, $value, $post_id );
					} else {
					/* translators: %s: ACF field name */
						$warnings[] = sprintf( __( 'ACF is not active. Field %s mapping skipped.', 'draftsync' ), $field );
					}
				}
			}
			if ( isset( $mappings['meta'] ) && ! empty( $mappings['meta'] ) ) {
				// Safety filtering rules for Phase 6.
				foreach ( $mappings['meta'] as $key => $value ) {
					$is_allowed = false;
					// Matches gdtg_ or draftsync_
					if ( 0 === strpos( $key, 'gdtg_' ) || 0 === strpos( $key, 'draftsync_' ) ) {
						$is_allowed = true;
					}
					
					// Rejections for private leading underscore keys unless explicitly filtered.
					if ( 0 === strpos( $key, '_' ) ) {
						$is_allowed = false;
					}

					$is_allowed = apply_filters( 'gdtg_allow_custom_meta_key', $is_allowed, $key );

					if ( $is_allowed ) {
						update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
					} else {
					/* translators: %s: the custom meta key name */
						$warnings[] = sprintf( __( 'Custom meta key %s rejected due to security configuration.', 'draftsync' ), $key );
					}
				}
			}
		}

		return $warnings;
	}

	/**
	 * Resolve Term by Slug, Name, or ID. Creates it if missing and user possesses capability.
	 *
	 * @param string|int $term_input Term ID, Slug, or Name.
	 * @param string     $taxonomy   The target taxonomy (category or post_tag).
	 * @param string     $cap        The capability required to create the term.
	 * @return int|WP_Error Resolved term ID, or WP_Error on failure.
	 */
	private function resolve_term( $term_input, $taxonomy, $cap ) {
		if ( empty( $term_input ) ) {
			return 0;
		}

		if ( is_numeric( $term_input ) ) {
			$term = get_term( absint( $term_input ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->term_id;
			}
		}

		// Try resolving as string first.
		$term_str = (string) $term_input;

		// 1. Try slug.
		$term = get_term_by( 'slug', $term_str, $taxonomy );
		if ( $term ) {
			return $term->term_id;
		}

		// 2. Try name.
		$term = get_term_by( 'name', $term_str, $taxonomy );
		if ( $term ) {
			return $term->term_id;
		}

		// 3. Creator check.
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'gdtg_missing_term_cap',
			/* translators: %1$s: term name, %2$s: taxonomy name (e.g. category, post_tag) */
				sprintf( __( 'Term %1$s not found in %2$s. Current user lacks permissions to create terms.', 'draftsync' ), $term_str, $taxonomy )
			);
		}

		// 4. Create term.
		$new_term = wp_insert_term( $term_str, $taxonomy );
		if ( is_wp_error( $new_term ) ) {
			return $new_term;
		}

		return $new_term['term_id'];
	}

	/**
	 * Resolve a featured image selection from the processed AST nodes.
	 *
	 * @param string|int      $featured_ref Referencer input ('first', numeric index, or filename).
	 * @param GDTG_Doc_Node[] $nodes        The document AST.
	 * @return int Sideloaded attachment ID, or 0 if not found.
	 */
	private function resolve_featured_image( $featured_ref, $nodes ) {
		$images = $this->collect_image_attachments( $nodes );
		if ( empty( $images ) ) {
			return 0;
		}

		// Case 1: First
		if ( 'first' === $featured_ref ) {
			return $images[0]['id'];
		}

		// Case 2: 1-based selective Index
		if ( is_numeric( $featured_ref ) ) {
			$idx = absint( $featured_ref ) - 1;
			if ( isset( $images[ $idx ] ) ) {
				return $images[ $idx ]['id'];
			}
			return 0;
		}

		// Case 3: Filename matching
		$ref_filename = strtolower( trim( $featured_ref ) );
		foreach ( $images as $img ) {
			$fn = isset( $img['filename'] ) ? strtolower( basename( $img['filename'] ) ) : '';
			$src = isset( $img['source_url'] ) ? strtolower( basename( $img['source_url'] ) ) : '';
			$url = isset( $img['url'] ) ? strtolower( basename( $img['url'] ) ) : '';

			if ( $fn === $ref_filename || $src === $ref_filename || $url === $ref_filename ) {
				return $img['id'];
			}
		}

		return 0;
	}

	/**
	 * Traverse the parsed nodes list to extract all processed image details with matching attachments.
	 *
	 * @param GDTG_Doc_Node[] $nodes The AST nodes.
	 * @return array Array of image data including attachment ID and filename.
	 */
	private function collect_image_attachments( $nodes ) {
		$images = array();
		foreach ( $nodes as $node ) {
			if ( ! ( $node instanceof GDTG_Doc_Node ) ) {
				continue;
			}

			if ( 'image' === $node->type && ! empty( $node->attrs['id'] ) ) {
				$images[] = array(
					'id'         => absint( $node->attrs['id'] ),
					'filename'   => isset( $node->attrs['source_name'] ) ? $node->attrs['source_name'] : '',
					'source_url' => isset( $node->attrs['source_url'] ) ? $node->attrs['source_url'] : '',
					'url'        => isset( $node->attrs['url'] ) ? $node->attrs['url'] : '',
				);
			}

			if ( ! empty( $node->children ) ) {
				$images = array_merge( $images, $this->collect_image_attachments( $node->children ) );
			}
		}

		return $images;
	}
}
