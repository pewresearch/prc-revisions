<?php
/**
 * Future Revisions (Fork/Merge workflow).
 *
 * Editors can "fork" a published post into a draft, edit it independently,
 * and merge it back — replacing revisionary-pro.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

use WP_Error;

/**
 * Future Revisions class.
 */
class Future_Revisions {

	/**
	 * Meta key for the fork parent post ID (stored on the fork post).
	 *
	 * @var string
	 */
	const FORK_PARENT_META = '_prc_fork_parent';

	/**
	 * Meta key for the fork status (stored on the fork post).
	 * Possible values: draft, pending_review, merged.
	 *
	 * @var string
	 */
	const FORK_STATUS_META = '_prc_fork_status';

	/**
	 * Meta key for the active fork ID (stored on the parent post).
	 * Enforces one active fork per post at a time.
	 *
	 * @var string
	 */
	const ACTIVE_FORK_META = '_prc_active_fork';

	/**
	 * Meta keys that should NOT be copied from parent to fork or fork to parent.
	 *
	 * @var array
	 */
	const META_COPY_BLOCKLIST = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_prc_fork_parent',
		'_prc_fork_status',
		'_prc_active_fork',
		'_prc_public_revisions',
	);

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'register_meta' );
		$loader->add_action( 'prc_platform_on_publish', $this, 'handle_fork_publish', 5, 1 );
		$loader->add_action( 'before_delete_post', $this, 'cleanup_fork_reference' );
		$loader->add_action( 'wp_trash_post', $this, 'cleanup_fork_reference' );
		$loader->add_action( 'wp_body_open', $this, 'render_future_revision_banner' );
	}

	/**
	 * Register the meta fields for fork workflow.
	 *
	 * @hook init
	 */
	public function register_meta() {
		foreach ( Plugin::get_enabled_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::FORK_PARENT_META,
				array(
					'single'        => true,
					'type'          => 'integer',
					'description'   => 'The parent post ID this fork was created from.',
					'default'       => 0,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);

			register_post_meta(
				$post_type,
				self::FORK_STATUS_META,
				array(
					'single'            => true,
					'type'              => 'string',
					'description'       => 'Fork status: draft, pending_review, or merged.',
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => function ( $value ) {
						return in_array( $value, array( 'draft', 'pending_review', 'merged' ), true ) ? $value : '';
					},
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);

			register_post_meta(
				$post_type,
				self::ACTIVE_FORK_META,
				array(
					'single'        => true,
					'type'          => 'integer',
					'description'   => 'The ID of the currently active fork for this post.',
					'default'       => 0,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Create a fork of a published post.
	 *
	 * Duplicates the post as a draft with a parent reference. Copies content,
	 * taxonomy terms, and relevant meta. Enforces one-fork-at-a-time guard.
	 *
	 * @param int $parent_post_id The ID of the published post to fork.
	 * @return int|WP_Error The fork post ID, or WP_Error on failure.
	 */
	public static function create_fork( $parent_post_id ) {
		$parent = get_post( $parent_post_id );
		if ( ! $parent ) {
			return new WP_Error( 'invalid_parent', __( 'Parent post does not exist.', 'prc-revisions' ) );
		}

		if ( 'publish' !== $parent->post_status ) {
			return new WP_Error( 'not_published', __( 'Only published posts can be forked.', 'prc-revisions' ) );
		}

		$existing_fork = get_post_meta( $parent_post_id, self::ACTIVE_FORK_META, true );
		if ( $existing_fork && get_post( $existing_fork ) && 'trash' !== get_post_status( $existing_fork ) ) {
			return new WP_Error(
				'fork_exists',
				__( 'An active fork already exists for this post.', 'prc-revisions' ),
				array( 'fork_id' => (int) $existing_fork )
			);
		}

		$fork_data = array(
			'post_type'    => $parent->post_type,
			'post_status'  => 'draft',
			'post_title'   => $parent->post_title,
			'post_name'    => $parent->post_name . '__fork',
			'post_content' => $parent->post_content,
			'post_excerpt' => $parent->post_excerpt,
			'post_author'  => get_current_user_id(),
			'post_parent'  => 0,
		);

		$fork_id = wp_insert_post( $fork_data, true );
		if ( is_wp_error( $fork_id ) ) {
			return $fork_id;
		}

		update_post_meta( $fork_id, self::FORK_PARENT_META, $parent_post_id );
		update_post_meta( $fork_id, self::FORK_STATUS_META, 'draft' );
		update_post_meta( $parent_post_id, self::ACTIVE_FORK_META, $fork_id );

		self::copy_taxonomy_terms( $parent_post_id, $fork_id );
		self::copy_post_meta( $parent_post_id, $fork_id );

		return $fork_id;
	}

	/**
	 * Copy all taxonomy terms from one post to another.
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $destination_id Destination post ID.
	 */
	private static function copy_taxonomy_terms( $source_id, $destination_id ) {
		$post_type  = get_post_type( $source_id );
		$taxonomies = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $destination_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * Copy post meta from one post to another, respecting the blocklist.
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $destination_id Destination post ID.
	 */
	private static function copy_post_meta( $source_id, $destination_id ) {
		$meta = get_post_meta( $source_id );
		if ( ! $meta ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, self::META_COPY_BLOCKLIST, true ) ) {
				continue;
			}
			delete_post_meta( $destination_id, $key );
			foreach ( $values as $value ) {
				add_post_meta( $destination_id, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Handle fork publish — trigger merge when a fork is published.
	 *
	 * Hooked at priority 5 so the merge (including meta/taxonomy copy)
	 * completes before other plugins (e.g. report-package at priority 10)
	 * react to the parent's pipeline hooks.
	 *
	 * @hook prc_platform_on_publish
	 *
	 * @param object $post The enriched post object from the publish pipeline.
	 */
	public function handle_fork_publish( $post ) {
		$parent_id = get_post_meta( $post->ID, self::FORK_PARENT_META, true );
		if ( ! $parent_id ) {
			return;
		}

		$fork_status = get_post_meta( $post->ID, self::FORK_STATUS_META, true );
		if ( 'merged' === $fork_status ) {
			return;
		}

		$result = self::merge_fork( $post->ID, $parent_id );

		if ( is_wp_error( $result ) ) {
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * Merge a fork back into its parent post.
	 *
	 * Steps:
	 * 1. Copy meta and taxonomy from fork to parent (before content update so
	 *    the publish pipeline sees the fork's meta when it fires).
	 * 2. Update parent content (title, content, excerpt) — triggers pipeline.
	 * 3. Re-parent attachments and child posts from fork to parent.
	 * 4. Fire the revision_applied action for compatibility.
	 * 5. Mark the fork as merged and trash it.
	 *
	 * @param int $fork_id   The fork post ID.
	 * @param int $parent_id The parent post ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function merge_fork( $fork_id, $parent_id ) {
		$fork   = get_post( $fork_id );
		$parent = get_post( $parent_id );

		if ( ! $fork || ! $parent ) {
			return new WP_Error( 'invalid_posts', __( 'Fork or parent post not found.', 'prc-revisions' ) );
		}

		// Step 1: Copy meta and taxonomy first so the parent has the fork's
		// data before wp_update_post triggers the publish pipeline.
		self::copy_post_meta( $fork_id, $parent_id );
		self::copy_taxonomy_terms( $fork_id, $parent_id );

		// Step 2: Update parent content from fork.
		$fork_slug   = $fork->post_name;
		$parent_slug = $parent->post_name;
		$fork_suffix = '__fork';

		if ( str_ends_with( $fork_slug, $fork_suffix ) ) {
			$resolved_slug = $parent_slug;
		} else {
			$resolved_slug = $fork_slug;
		}

		$update_result = wp_update_post(
			array(
				'ID'            => $parent_id,
				'post_title'    => $fork->post_title,
				'post_name'     => $resolved_slug,
				'post_content'  => $fork->post_content,
				'post_excerpt'  => $fork->post_excerpt,
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		// Step 3: Re-parent attachments.
		$attachments = get_children(
			array(
				'post_parent' => $fork_id,
				'post_type'   => 'attachment',
				'numberposts' => -1,
			)
		);
		foreach ( $attachments as $attachment ) {
			wp_update_post(
				array(
					'ID'          => $attachment->ID,
					'post_parent' => $parent_id,
				)
			);
		}

		// Re-parent child posts.
		$children = get_children(
			array(
				'post_parent' => $fork_id,
				'post_type'   => 'any',
				'numberposts' => -1,
				'exclude'     => array_keys( $attachments ),
			)
		);
		foreach ( $children as $child ) {
			wp_update_post(
				array(
					'ID'          => $child->ID,
					'post_parent' => $parent_id,
				)
			);
		}

		// Step 4: Fire revision_applied for compatibility with attachment copy logic.
		$latest_revision = wp_get_post_revisions(
			$parent_id,
			array(
				'numberposts' => 1,
				'order'       => 'DESC',
			)
		);
		if ( ! empty( $latest_revision ) ) {
			$revision = reset( $latest_revision );
			do_action( 'revision_applied', $parent_id, $revision );
		}

		// Step 5: Mark fork as merged and trash it.
		update_post_meta( $fork_id, self::FORK_STATUS_META, 'merged' );
		delete_post_meta( $parent_id, self::ACTIVE_FORK_META );
		wp_trash_post( $fork_id );

		return true;
	}

	/**
	 * Clean up the parent's active fork reference when a fork is deleted or trashed.
	 *
	 * @hook before_delete_post
	 * @hook wp_trash_post
	 *
	 * @param int $post_id The post being deleted/trashed.
	 */
	public function cleanup_fork_reference( $post_id ) {
		$parent_id = get_post_meta( $post_id, self::FORK_PARENT_META, true );
		if ( ! $parent_id ) {
			return;
		}

		$active_fork = get_post_meta( $parent_id, self::ACTIVE_FORK_META, true );
		if ( (int) $active_fork === (int) $post_id ) {
			delete_post_meta( $parent_id, self::ACTIVE_FORK_META );
		}
	}

	/**
	 * Get the HTML for the future revision banner.
	 *
	 * Reusable by callers who pass a label and optionally parent link details.
	 * Each consumer controls verbosity: pass only `label` for a short message
	 * (e.g. "Previewing future revision"), or pass `parent_url`/`parent_title`
	 * for a full message with link (e.g. "This is a future revision of: [Title]").
	 *
	 * @param array $args {
	 *     Optional. Banner arguments.
	 *
	 *     @type string $label        Required. The main banner text. E.g. "Previewing future revision" or "This is a future revision of:".
	 *     @type string $parent_url   Optional. When set with parent_title, appends a link after the label.
	 *     @type string $parent_title Optional. Link text when parent_url is provided.
	 *     @type string $schedule_text Optional. Appended after label/link. E.g. " Scheduled for Jan 1, 2025.".
	 * }
	 * @return string Banner HTML including styles.
	 */
	public static function get_future_revision_banner_html( $args ) {
		$defaults = array(
			'label'         => __( 'This is a future revision of:', 'prc-revisions' ),
			'parent_url'    => '',
			'parent_title'  => '',
			'schedule_text' => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$label = trim( $args['label'] );
		if ( '' === $label ) {
			return '';
		}

		$label         = esc_html( $label );
		$schedule_text = ! empty( $args['schedule_text'] ) ? ' ' . esc_html( $args['schedule_text'] ) : '';

		$has_parent_link = ! empty( $args['parent_url'] ) && ! empty( $args['parent_title'] );
		$parent_markup   = '';
		if ( $has_parent_link ) {
			$parent_url   = esc_url( $args['parent_url'] );
			$parent_title = esc_html( $args['parent_title'] );
			$parent_markup = ' <a href="' . $parent_url . '">' . $parent_title . '</a>';
		}

		return '<style>
			.prc-future-revision-banner {
				background-color: #ffd84d;
				background-image: repeating-linear-gradient(
					-45deg,
					rgba(0, 0, 0, 0.14) 0,
					rgba(0, 0, 0, 0.14) 12px,
					rgba(0, 0, 0, 0.05) 12px,
					rgba(0, 0, 0, 0.05) 24px
				);
				color: #1d2327;
				border-bottom: 1px solid rgba(0, 0, 0, 0.25);
				padding: 10px 16px;
				font-size: 13px;
				font-weight: 600;
				line-height: 1.3;
				text-align: center;
			}
			.prc-future-revision-banner a {
				color: #1d2327;
				text-decoration: underline;
			}
		</style>
		<div class="prc-future-revision-banner" role="status">' . $label . $parent_markup . $schedule_text . '</div>';
	}

	/**
	 * Render a front-end banner for fork posts when the admin bar is visible.
	 *
	 * @hook wp_body_open
	 */
	public function render_future_revision_banner() {
		if ( is_admin() || ! is_singular() || ! is_admin_bar_showing() ) {
			return;
		}

		$queried_post = get_queried_object();
		if ( ! ( $queried_post instanceof \WP_Post ) ) {
			return;
		}

		$parent_id = (int) get_post_meta( $queried_post->ID, self::FORK_PARENT_META, true );
		if ( ! $parent_id ) {
			return;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return;
		}

		$parent_url   = get_permalink( $parent );
		$parent_title = get_the_title( $parent );
		if ( ! $parent_url || ! $parent_title ) {
			return;
		}

		$scheduled_label = $this->get_future_revision_schedule_label( $queried_post );
		$schedule_text   = '';
		if ( false !== $scheduled_label ) {
			$schedule_text = sprintf(
				__( 'Scheduled for %s.', 'prc-revisions' ),
				$scheduled_label
			);
		}

		echo self::get_future_revision_banner_html(
			array(
				'label'         => __( 'This is a future revision of:', 'prc-revisions' ),
				'parent_url'    => $parent_url,
				'parent_title'  => $parent_title,
				'schedule_text' => $schedule_text,
			)
		);
	}

	/**
	 * Get a human-readable scheduled publish date for a fork post.
	 *
	 * @param \WP_Post $post The fork post object.
	 * @return string|false Scheduled datetime label, or false when not scheduled.
	 */
	private function get_future_revision_schedule_label( $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return false;
		}

		$timestamp = get_post_timestamp( $post );
		if ( ! $timestamp ) {
			return false;
		}

		$is_future_status = 'future' === $post->post_status;
		$is_future_time   = $timestamp > current_time( 'timestamp' );
		if ( ! $is_future_status || ! $is_future_time ) {
			return false;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Get the fork info for a given post.
	 *
	 * @param int $post_id The post ID (parent or fork).
	 * @return array Fork information.
	 */
	public static function get_fork_info( $post_id ) {
		$active_fork_id = get_post_meta( $post_id, self::ACTIVE_FORK_META, true );
		$fork_parent_id = get_post_meta( $post_id, self::FORK_PARENT_META, true );

		if ( $active_fork_id ) {
			$fork = get_post( $active_fork_id );
			if ( $fork && 'trash' !== $fork->post_status ) {
				return array(
					'role'          => 'parent',
					'fork_id'       => (int) $active_fork_id,
					'fork_status'   => get_post_meta( $active_fork_id, self::FORK_STATUS_META, true ),
					'fork_edit_url' => get_edit_post_link( $active_fork_id, 'raw' ),
				);
			}
		}

		if ( $fork_parent_id ) {
			return array(
				'role'            => 'fork',
				'parent_id'       => (int) $fork_parent_id,
				'parent_title'    => get_the_title( $fork_parent_id ),
				'parent_edit_url' => get_edit_post_link( $fork_parent_id, 'raw' ),
				'fork_status'     => get_post_meta( $post_id, self::FORK_STATUS_META, true ),
			);
		}

		return array(
			'role' => 'none',
		);
	}
}
