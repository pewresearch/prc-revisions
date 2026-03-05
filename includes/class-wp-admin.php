<?php
/**
 * WP Admin
 *
 * Handles registering and enqueuing admin assets for the revisions editor panel.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

use WP_Error;

/**
 * WP Admin class.
 */
class WP_Admin {

	/**
	 * The handle for the admin assets.
	 *
	 * @var string
	 */
	public static $handle = 'prc-revisions-interface';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_panel_assets' );
	}

	/**
	 * Register the UI panel assets for this block editor plugin.
	 *
	 * @return WP_Error|true
	 */
	public function register_panel_assets() {
		$asset_path = plugin_dir_path( __FILE__ ) . 'inspector-sidebar-panel/build/index.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return new WP_Error( self::$handle, 'Asset file not found. Run npm run build first.' );
		}

		$asset_file = include $asset_path;
		$asset_slug = self::$handle;
		$script_src = plugin_dir_url( __FILE__ ) . 'inspector-sidebar-panel/build/index.js';

		$script = wp_register_script(
			$asset_slug,
			$script_src,
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);
		if ( ! $script ) {
			return new WP_Error( self::$handle, 'Failed to register all assets' );
		}

		return true;
	}

	/**
	 * Enqueue the assets for this block editor plugin.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_panel_assets() {
		$registered = $this->register_panel_assets();
		if ( ! is_admin() || is_wp_error( $registered ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		if ( ! post_type_supports( $screen->post_type, 'prc-revisions' ) ) {
			return;
		}

		wp_enqueue_script( self::$handle );
	}
}
