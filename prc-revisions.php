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

if ( ! defined( 'DEFAULT_TECHNICAL_CONTACT' ) ) {
	define( 'DEFAULT_TECHNICAL_CONTACT', 'webdev@pewresearch.org' );
}

define( 'PRC_REVISIONS_FILE', __FILE__ );
define( 'PRC_REVISIONS_DIR', __DIR__ );
define( 'PRC_REVISIONS_BLOCKS_DIR', __DIR__ . '/build' );
define( 'PRC_REVISIONS_VERSION', '1.0.0' );

// Load the Jetpack Autoloader so runtime version-selection can pick the
// highest version across all plugins that ship the same library dep
// (see .cursor/plans/composer-shape-b-migration_0e4e9991.plan.md).
$prc_revisions_autoloader = __DIR__ . '/vendor/autoload_packages.php';
if ( file_exists( $prc_revisions_autoloader ) ) {
	require_once $prc_revisions_autoloader;
}
unset( $prc_revisions_autoloader );

/**
 * The code that runs during plugin activation.
 */
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, '\PRC\Platform\Revisions\activate' );
register_deactivation_hook( __FILE__, '\PRC\Platform\Revisions\deactivate' );

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
