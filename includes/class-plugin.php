<?php
/**
 * Plugin class.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Plugin class.
 *
 * @package PRC\Platform\Revisions
 */
class Plugin {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power the plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin as initialized by hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->version     = PRC_REVISIONS_VERSION;
		$this->plugin_name = 'prc-revisions';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		$this->loader = new Loader();

		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rewrite.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-public-revisions.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rest-api.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-schema.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-wp-admin.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-future-revisions.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function init_dependencies() {
		$this->loader->add_action( 'init', $this, 'register_default_post_type_support', 5 );

		new Rewrite( $this->get_loader() );
		new Public_Revisions( $this->get_loader() );
		new Rest_API( $this->get_loader() );
		new Schema( $this->get_loader() );
		new WP_Admin( $this->get_loader() );
		new Future_Revisions( $this->get_loader() );

		// Initialize blocks.
		if ( is_dir( PRC_REVISIONS_BLOCKS_DIR ) ) {
			$manifest_file = PRC_REVISIONS_BLOCKS_DIR . '/blocks-manifest.php';
			if ( file_exists( $manifest_file ) ) {
				wp_register_block_metadata_collection(
					PRC_REVISIONS_BLOCKS_DIR,
					$manifest_file
				);
			}

			if ( function_exists( '\PRC\BlockUtils\load_blocks' ) ) {
				$blocks_loaded = \PRC\BlockUtils\load_blocks( PRC_REVISIONS_DIR );
				if ( ! is_wp_error( $blocks_loaded ) ) {
					new Revision_List( $this->get_loader() );
				}
			}
		}
	}

	/**
	 * Register default post type support for PRC revisions.
	 *
	 * @hook init
	 */
	public function register_default_post_type_support() {
		add_post_type_support( 'post', 'prc-revisions' );
	}

	/**
	 * Get the enabled post types for PRC revisions.
	 *
	 * @return string[]
	 */
	public static function get_enabled_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		return array_values(
			array_filter(
				$post_types,
				function ( $pt ) {
					return post_type_supports( $pt, 'prc-revisions' );
				}
			)
		);
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of WordPress.
	 *
	 * @since  1.0.0
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since  1.0.0
	 * @return Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since  1.0.0
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
