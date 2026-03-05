<?php
/**
 * Plugin Deactivator
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Plugin Deactivator
 */
class Plugin_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();

		if ( defined( 'DEFAULT_TECHNICAL_CONTACT' ) ) {
			wp_mail(
				DEFAULT_TECHNICAL_CONTACT,
				'PRC Revisions Deactivated',
				'The PRC Revisions plugin has been deactivated on ' . get_site_url()
			);
		}
	}
}
