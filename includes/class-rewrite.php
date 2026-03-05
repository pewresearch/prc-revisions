<?php
/**
 * Rewrite endpoint registration.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Registers the /version/ rewrite endpoint for public revision versioned URLs.
 *
 * @package PRC\Platform\Revisions
 */
class Rewrite {

	/**
	 * The endpoint slug used in URLs: /post-slug/version/a
	 *
	 * @var string
	 */
	const ENDPOINT = 'version';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'register_endpoint' );
	}

	/**
	 * Register the version rewrite endpoint on all permalink structures.
	 *
	 * @hook init
	 */
	public function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PERMALINK );
	}
}
