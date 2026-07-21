<?php
/**
 * PRC Revisions
 *
 * @package           PRC_Revisions
 * @author            Seth Rubenstein
 * @copyright         2025 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Revisions
 * Plugin URI:        https://github.com/pewresearch/prc-revisions
 * Description:       Public revision versioning and fork/merge workflow for PRC Platform. Allows editors to mark specific WordPress revisions as public, accessible via versioned URLs and visible on the frontend.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-revisions
 * Requires Plugins:  prc-scripts, prc-post-publish-pipeline
 */

namespace PRC\Platform\Revisions;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


define( 'PRC_REVISIONS_FILE', __FILE__ );
define( 'PRC_REVISIONS_DIR', __DIR__ );
define( 'PRC_REVISIONS_BLOCKS_DIR', __DIR__ . '/build' );
define( 'PRC_REVISIONS_VERSION', '1.0.0' );

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_revisions_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_revisions_autoloader ) ) {
		require_once $prc_revisions_autoloader;
	}
	unset( $prc_revisions_autoloader );
}

/**
 * The core plugin class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function run_prc_revisions() {
	$plugin = new Plugin();
	$plugin->run();
}
run_prc_revisions();
