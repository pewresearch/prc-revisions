<?php
/**
 * Plugin Activator
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Plugin Activator
 *
 * @package PRC\Platform\Revisions
 */
class Plugin_Activator {

	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Revisions Activated',
			'The PRC Revisions plugin has been activated on ' . get_site_url()
		);
	}
}
