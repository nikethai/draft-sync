<?php
/**
 * Large Document Progressive Streamer.
 *
 * Streams a large GDTG_Doc_Node[] AST to a post in node-count batches,
 * committing cumulative content and reporting progress between batches.
 * Stateless except for per-call accumulation. No image/Drive logic.
 *
 * @package GoogleDocsToGutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GDTG_Large_Doc_Streamer
 */
class GDTG_Large_Doc_Streamer {

	/**
	 * Nodes rendered and committed per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 40;

	/**
	 * Stream a large AST to a post in batches, committing cumulative
	 * content and reporting progress between each batch.
	 *
	 * @param GDTG_Doc_Node[] $nodes       Full top-level AST (from GDTG_Parser::parse_nodes()).
	 * @param int             $post_id     Existing draft post ID (MUST be a created shell).
	 * @param array           $overrides   Style overrides for GDTG_Block_Renderer::render().
	 * @param callable|null   $on_progress fn(int $rendered, int $total, int $percent): void
	 * @return string|WP_Error Final full rendered block markup, or WP_Error on a commit failure.
	 */
	public static function stream( $nodes, $post_id, $overrides = array(), $on_progress = null ) {
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return new WP_Error(
				'gdtg_empty_doc',
				__( 'The Google Doc is empty or could not be parsed.', 'draftsync' )
			);
		}

		$total    = count( $nodes );
		$renderer = new GDTG_Block_Renderer();

		$accumulated = '';
		$rendered    = 0;
		$batches     = array_chunk( $nodes, self::BATCH_SIZE );

		foreach ( $batches as $batch ) {
			$slice_markup = $renderer->render( $batch, $overrides );

			if ( '' !== $accumulated ) {
				$accumulated .= "\n\n" . $slice_markup;
			} else {
				$accumulated = $slice_markup;
			}

			// Commit cumulative content to the post.
			$update = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $accumulated,
					)
				),
				true
			);
			if ( is_wp_error( $update ) ) {
				return new WP_Error(
					'gdtg_partial_commit_failed',
					__( 'Could not save part of the large document.', 'draftsync' )
				);
			}

			$rendered = min( $rendered + count( $batch ), $total );

			// Report progress: scaled into the 55–95 percent band.
			if ( is_callable( $on_progress ) ) {
				$percent = 55 + (int) floor( 40 * $rendered / $total );
				if ( $percent > 95 ) {
					$percent = 95;
				}
				$on_progress( $rendered, $total, $percent );
			}
		}

		return $accumulated;
	}
}
