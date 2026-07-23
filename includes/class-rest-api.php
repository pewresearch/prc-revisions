<?php
/**
 * REST API endpoints for PRC Revisions.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API class.
 *
 * Provides endpoints for toggling a revision's public status
 * and retrieving public revisions for a post.
 */
class Rest_API {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'prc-revisions/v1';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes() {
		// Phase 1: Public revisions.
		register_rest_route(
			self::NAMESPACE,
			'/public-revisions/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_public_revisions' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/toggle/(?P<post_id>\d+)/(?P<revision_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'toggle_public_revision' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'post_id'     => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'revision_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Phase 2: Fork/merge.
		register_rest_route(
			self::NAMESPACE,
			'/fork/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_fork' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'trash_fork' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fork-info/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fork_info' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for reading public revisions.
	 *
	 * Published posts are readable by anyone. Draft posts are readable only
	 * by users who can edit the post, to avoid leaking the existence of
	 * unpublished content or author/timing metadata to anonymous callers.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function read_permissions_check( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Post not found.', 'prc-revisions' ),
				array( 'status' => 404 )
			);
		}

		if ( ! post_type_supports( $post->post_type, 'prc-revisions' ) ) {
			return new WP_Error(
				'rest_post_type_not_supported',
				__( 'This post type does not support revisions features.', 'prc-revisions' ),
				array( 'status' => 403 )
			);
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view revisions for this post.', 'prc-revisions' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for toggling public revision status (editor-only).
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function write_permissions_check( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$post    = get_post( $post_id );
		if ( $post && ! post_type_supports( $post->post_type, 'prc-revisions' ) ) {
			return new WP_Error(
				'rest_post_type_not_supported',
				__( 'This post type does not support revisions features.', 'prc-revisions' ),
				array( 'status' => 403 )
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify this post.', 'prc-revisions' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get public revisions for a post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_public_revisions( $request ) {
		$post_id   = $request->get_param( 'post_id' );
		$revisions = Public_Revisions::get_public_revisions( $post_id );

		$data = array();
		foreach ( $revisions as $entry ) {
			$revision = get_post( $entry['revision_id'] );
			$orphaned = ! empty( $entry['orphaned'] ) || ! $revision || 'revision' !== $revision->post_type;

			$parent_url  = get_permalink( $post_id );
			$version_url = trailingslashit( $parent_url ) . Rewrite::ENDPOINT . '/' . $entry['version'];

			if ( $orphaned ) {
				$data[] = array(
					'version'      => $entry['version'] ?? '',
					'revision_id'  => $entry['revision_id'],
					'date'         => '',
					'date_display' => __( 'No longer available', 'prc-revisions' ),
					'url'          => $version_url,
					'author'       => '',
					'orphaned'     => true,
				);
			} else {
				$data[] = array(
					'version'      => $entry['version'],
					'revision_id'  => $entry['revision_id'],
					'date'         => $revision->post_date,
					'date_display' => get_the_date( '', $revision ),
					'url'          => $version_url,
					'author'       => get_the_author_meta( 'display_name', $revision->post_author ),
				);
			}
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Toggle a revision's public status.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_public_revision( $request ) {
		$post_id     = $request->get_param( 'post_id' );
		$revision_id = $request->get_param( 'revision_id' );

		$revision = get_post( $revision_id );
		$orphaned = ! $revision || 'revision' !== $revision->post_type;

		if ( $orphaned ) {
			$public_revisions = Public_Revisions::get_public_revisions( $post_id );
			$found            = false;
			foreach ( $public_revisions as $entry ) {
				if ( (int) $entry['revision_id'] === (int) $revision_id ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return new WP_Error(
					'invalid_revision',
					__( 'The specified revision does not exist.', 'prc-revisions' ),
					array( 'status' => 404 )
				);
			}
		} elseif ( (int) $revision->post_parent !== (int) $post_id ) {
			return new WP_Error(
				'revision_mismatch',
				__( 'The revision does not belong to the specified post.', 'prc-revisions' ),
				array( 'status' => 400 )
			);
		}

		$result = Public_Revisions::toggle_public_revision( $post_id, $revision_id );

		$parent_url  = get_permalink( $post_id );
		$version_url = '';
		if ( 'added' === $result['action'] ) {
			$version_url = trailingslashit( $parent_url ) . Rewrite::ENDPOINT . '/' . $result['version'];
		}

		return new WP_REST_Response(
			array(
				'action'  => $result['action'],
				'version' => $result['version'],
				'url'     => $version_url,
			),
			200
		);
	}

	/**
	 * Create a fork of a published post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_fork( $request ) {
		$post_id = $request->get_param( 'post_id' );

		$fork_id = Future_Revisions::create_fork( $post_id );
		if ( is_wp_error( $fork_id ) ) {
			$status = 'fork_exists' === $fork_id->get_error_code() ? 409 : 400;
			return new WP_Error(
				$fork_id->get_error_code(),
				$fork_id->get_error_message(),
				array_merge(
					array( 'status' => $status ),
					$fork_id->get_error_data() ?? array()
				)
			);
		}

		return new WP_REST_Response(
			array(
				'fork_id'  => $fork_id,
				'edit_url' => get_edit_post_link( $fork_id, 'raw' ),
			),
			201
		);
	}

	/**
	 * Get fork information for a post.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_fork_info( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$info    = Future_Revisions::get_fork_info( $post_id );

		return new WP_REST_Response( $info, 200 );
	}

	/**
	 * Trash a pending future revision (fork).
	 *
	 * Accepts either the parent post ID or the fork post ID.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trash_fork( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$result  = Future_Revisions::trash_fork( $post_id );

		if ( is_wp_error( $result ) ) {
			$status_map = array(
				'invalid_post'         => 404,
				'no_active_fork'       => 404,
				'fork_already_trashed' => 410,
				'fork_already_merged'  => 400,
				'rest_forbidden'       => 403,
				'trash_failed'         => 500,
			);
			$code   = $result->get_error_code();
			$status = $status_map[ $code ] ?? 400;

			return new WP_Error(
				$code,
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		return new WP_REST_Response(
			array(
				'trashed'   => true,
				'fork_id'   => $result['fork_id'],
				'parent_id' => $result['parent_id'],
			),
			200
		);
	}
}
