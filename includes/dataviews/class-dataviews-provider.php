<?php
/**
 * DataViews provider for future-revision list indicators.
 *
 * @package PRC\Platform\Revisions
 */

declare(strict_types=1);

namespace PRC\Platform\Revisions;

use WP_Post;

/**
 * Enriches prc-wp-admin-dataview lists with future-revision status badges.
 */
class DataViews_Provider {

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( 'prc_wp_admin_dataview_shape_row', $this, 'shape_row', 10, 3 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_provider_script', 20 );
	}

	/**
	 * Whether the post type supports PRC revisions.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function supports( string $post_type ): bool {
		return post_type_supports( $post_type, 'prc-revisions' );
	}

	/**
	 * Map fork-info into a DataViews list indicator.
	 *
	 * @param array $info Fork info from Future_Revisions::get_fork_info().
	 * @return array{role: string, label: string}|null
	 */
	public static function indicator_from_fork_info( array $info ): ?array {
		$role = isset( $info['role'] ) ? (string) $info['role'] : 'none';

		return match ( $role ) {
			'fork' => array(
				'role'  => 'fork',
				'label' => __( 'Future Revision', 'prc-revisions' ),
			),
			'parent' => array(
				'role'  => 'parent',
				'label' => __( 'Has Future Revision', 'prc-revisions' ),
			),
			default => null,
		};
	}

	/**
	 * Attach a future-revision indicator for fork and parent rows.
	 *
	 * @param array   $row       Row.
	 * @param WP_Post $post      Post.
	 * @param string  $post_type Post type.
	 * @return array
	 */
	public function shape_row( $row, $post, $post_type ) {
		if ( ! is_array( $row ) || ! $post instanceof WP_Post || ! $this->supports( (string) $post_type ) ) {
			return $row;
		}

		$indicator = self::indicator_from_fork_info(
			Future_Revisions::get_fork_info( (int) $post->ID )
		);
		if ( null !== $indicator ) {
			$row['futureRevision'] = $indicator;
		}

		return $row;
	}

	/**
	 * Enqueue the provider script when the shared DataViews shell is loaded.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 */
	public function enqueue_provider_script( $hook_suffix ): void {
		unset( $hook_suffix );

		if ( ! wp_script_is( 'prc-wp-admin-dataview', 'enqueued' ) ) {
			return;
		}

		$asset_file = PRC_REVISIONS_DIR . '/build/admin-dataview/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;

		$registered = wp_register_script(
			'prc-revisions-admin-dataview',
			plugins_url( 'build/admin-dataview/index.js', PRC_REVISIONS_FILE ),
			array_merge( $asset['dependencies'], array( 'prc-wp-admin-dataview' ) ),
			$asset['version'],
			true
		);
		if ( false === $registered && ! wp_script_is( 'prc-revisions-admin-dataview', 'registered' ) ) {
			return;
		}

		wp_enqueue_script( 'prc-revisions-admin-dataview' );

		$style_file = PRC_REVISIONS_DIR . '/build/admin-dataview/style-index.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'prc-revisions-admin-dataview',
				plugins_url( 'build/admin-dataview/style-index.css', PRC_REVISIONS_FILE ),
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}
}
