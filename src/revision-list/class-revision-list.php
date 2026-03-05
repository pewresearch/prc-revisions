<?php
/**
 * Revision List Block
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Block Name:        Revision List
 * Description:       Displays a list of all public revisions for the current post.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 *
 * @package prc-revisions
 */
class Revision_List {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'block_init' );
	}

	/**
	 * Registers the block using the metadata loaded from block.json.
	 *
	 * @hook init
	 *
	 * @see https://developer.wordpress.org/reference/functions/register_block_type/
	 */
	public function block_init() {
		register_block_type_from_metadata(
			PRC_REVISIONS_BLOCKS_DIR . '/revision-list',
			array(
				'render_callback' => array( $this, 'render_block_callback' ),
			)
		);
	}

	/**
	 * Render callback for the block. Delegates to render.php.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block content.
	 * @param \WP_Block $block      Block instance.
	 * @return string Rendered block HTML.
	 */
	public function render_block_callback( $attributes, $content, $block ) {
		ob_start();
		include PRC_REVISIONS_BLOCKS_DIR . '/revision-list/render.php';
		return ob_get_clean();
	}
}
