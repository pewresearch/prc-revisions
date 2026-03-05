<?php
/**
 * Public Revisions.
 *
 * Manages the "public revision" feature: marking WordPress revisions as publicly
 * accessible via versioned URLs (/post-slug/version/a), and content substitution
 * on the frontend.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Public Revisions class.
 */
class Public_Revisions {

	/**
	 * Post meta key storing the array of public revision mappings on the parent post.
	 * Format: [ [ 'version' => 'a', 'revision_id' => 123 ], ... ]
	 *
	 * @var string
	 */
	const META_KEY = '_prc_public_revisions';

	/**
	 * Stores the currently active public revision context during a request.
	 *
	 * @var array|null { version: string, revision_id: int, parent_id: int }
	 */
	private static $current_revision_context = null;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'register_meta' );
		$loader->add_action( 'template_redirect', $this, 'handle_version_endpoint' );
		$loader->add_action( 'revision_applied', $this, 'on_revision_application__copy_attachments', 10, 2 );
		$loader->add_filter( 'wp_prepare_revision_for_js', $this, 'inject_public_flag_into_revision_js', 10, 3 );
		$loader->add_filter( 'pre_delete_post', $this, 'protect_public_revision', 10, 3 );
	}

	/**
	 * Register the post meta field for public revisions.
	 *
	 * @hook init
	 */
	public function register_meta() {
		register_post_meta(
			'',
			self::META_KEY,
			array(
				'single'        => true,
				'type'          => 'array',
				'description'   => 'Array of public revision mappings (version letter => revision ID).',
				'default'       => array(),
				'show_in_rest'  => array(
					'schema' => array(
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'version'     => array(
									'type' => 'string',
								),
								'revision_id' => array(
									'type' => 'integer',
								),
							),
						),
					),
				),
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Handle the /version/{letter} endpoint on template_redirect.
	 *
	 * Validates the version letter, locates the matching revision,
	 * and sets up content substitution via the_content filter.
	 *
	 * @hook template_redirect
	 */
	public function handle_version_endpoint() {
		$version_letter = get_query_var( Rewrite::ENDPOINT, false );

		if ( false === $version_letter || '' === $version_letter ) {
			return;
		}

		$version_letter = sanitize_text_field( strtolower( $version_letter ) );

		if ( ! preg_match( '/^[a-z]{1,2}$/', $version_letter ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$post_id           = get_queried_object_id();
		$public_revisions  = get_post_meta( $post_id, self::META_KEY, true );

		if ( empty( $public_revisions ) || ! is_array( $public_revisions ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$revision_id = null;
		foreach ( $public_revisions as $entry ) {
			if ( isset( $entry['version'] ) && $entry['version'] === $version_letter ) {
				$revision_id = (int) $entry['revision_id'];
				break;
			}
		}

		if ( ! $revision_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		self::$current_revision_context = array(
			'version'     => $version_letter,
			'revision_id' => $revision_id,
			'parent_id'   => $post_id,
		);

		add_filter( 'the_content', array( $this, 'substitute_revision_content' ), 1 );
		add_filter( 'the_title', array( $this, 'append_version_to_title' ), 10, 2 );
	}

	/**
	 * Substitute the post content with the revision's content.
	 *
	 * @hook the_content
	 *
	 * @param string $content The original post content.
	 * @return string The revision content.
	 */
	public function substitute_revision_content( $content ) {
		if ( null === self::$current_revision_context ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( $revision ) {
			// Remove our own filter to avoid infinite recursion, then return
			// the raw revision content. WordPress's other the_content filters
			// (wpautop, do_blocks, etc.) will still process it since they are
			// registered at higher priorities and this filter runs at priority 1.
			remove_filter( 'the_content', array( $this, 'substitute_revision_content' ), 1 );
			return $revision->post_content;
		}

		return $content;
	}

	/**
	 * Append the version label to the post title when viewing a revision version.
	 *
	 * @hook the_title
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post ID.
	 * @return string Modified title.
	 */
	public function append_version_to_title( $title, $post_id = 0 ) {
		if ( null === self::$current_revision_context ) {
			return $title;
		}

		if ( (int) $post_id !== self::$current_revision_context['parent_id'] ) {
			return $title;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}

		$version = strtoupper( self::$current_revision_context['version'] );
		return $title . ' (Version ' . $version . ')';
	}

	/**
	 * Copy attachments from the revision to the parent post when a revision is applied.
	 * Migrated from prc-platform-core class-revisions.php.
	 *
	 * @hook revision_applied
	 *
	 * @param int    $published_post_id The ID of the published post.
	 * @param object $revision          The revision post object.
	 */
	public function on_revision_application__copy_attachments( $published_post_id, $revision ) {
		$revision_id = $revision->ID;
		$attachments = get_children(
			array(
				'post_parent' => $revision_id,
				'post_type'   => 'attachment',
				'numberposts' => 100,
			)
		);
		foreach ( $attachments as $attachment ) {
			$attachment_id = $attachment->ID;
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $published_post_id,
				)
			);
		}
	}

	/**
	 * Inject the public revision flag into the revision data for the JS revisions UI.
	 *
	 * @hook wp_prepare_revision_for_js
	 *
	 * @param array    $revision_data The revision data array.
	 * @param \WP_Post $revision      The revision post object.
	 * @param \WP_Post $post          The parent post object.
	 * @return array Modified revision data.
	 */
	public function inject_public_flag_into_revision_js( $revision_data, $revision, $post ) {
		$public_revisions = get_post_meta( $post->ID, self::META_KEY, true );

		$is_public      = false;
		$version_letter = '';

		if ( ! empty( $public_revisions ) && is_array( $public_revisions ) ) {
			foreach ( $public_revisions as $entry ) {
				if ( isset( $entry['revision_id'] ) && (int) $entry['revision_id'] === (int) $revision->ID ) {
					$is_public      = true;
					$version_letter = $entry['version'];
					break;
				}
			}
		}

		$revision_data['prcIsPublic']      = $is_public;
		$revision_data['prcVersionLetter'] = $version_letter;

		return $revision_data;
	}

	/**
	 * Prevent deletion of revisions that are marked as public.
	 *
	 * @hook pre_delete_post
	 *
	 * @param bool|null  $delete       Whether to go forward with deletion.
	 * @param \WP_Post   $post         Post object.
	 * @param bool       $force_delete Whether to bypass trash.
	 * @return bool|null False to block deletion, pass through otherwise.
	 */
	public function protect_public_revision( $delete, $post, $force_delete ) {
		if ( ! $post instanceof \WP_Post || 'revision' !== $post->post_type ) {
			return $delete;
		}

		$public_revisions = get_post_meta( $post->post_parent, self::META_KEY, true );
		if ( empty( $public_revisions ) || ! is_array( $public_revisions ) ) {
			return $delete;
		}

		foreach ( $public_revisions as $entry ) {
			if ( isset( $entry['revision_id'] ) && (int) $entry['revision_id'] === (int) $post->ID ) {
				return false;
			}
		}

		return $delete;
	}

	/**
	 * Get the current revision context (used by schema and block rendering).
	 *
	 * @return array|null The revision context or null if not viewing a versioned page.
	 */
	public static function get_current_revision_context() {
		return self::$current_revision_context;
	}

	/**
	 * Get the public revisions for a given post.
	 *
	 * Returns both valid entries and orphaned entries (revision post no longer exists).
	 * Orphaned entries are pruned from meta on read; they are included in the return
	 * with `orphaned => true` so the REST/UI can surface them for removal.
	 *
	 * @param int $post_id The post ID.
	 * @return array Array of public revision entries (valid + orphaned with flag).
	 */
	public static function get_public_revisions( $post_id ) {
		$revisions = get_post_meta( $post_id, self::META_KEY, true );
		if ( empty( $revisions ) || ! is_array( $revisions ) ) {
			return array();
		}
		return self::cleanup_orphaned_entries( $post_id, $revisions );
	}

	/**
	 * Filter out entries whose revision post no longer exists; add orphaned flag for surfacing.
	 *
	 * Orphaned entries are kept in meta until explicitly removed (via toggle). They are
	 * returned with `orphaned => true` so the REST/UI can surface them.
	 *
	 * @param int   $post_id   The parent post ID.
	 * @param array $revisions The raw public revision entries from meta.
	 * @return array Valid entries plus orphaned entries with `orphaned => true`.
	 */
	public static function cleanup_orphaned_entries( $post_id, $revisions ) {
		$result = array();

		foreach ( $revisions as $entry ) {
			if ( empty( $entry['revision_id'] ) ) {
				continue;
			}
			$revision = get_post( (int) $entry['revision_id'] );
			if ( $revision && 'revision' === $revision->post_type ) {
				$result[] = $entry;
			} else {
				$result[] = array_merge( $entry, array( 'orphaned' => true ) );
			}
		}

		return $result;
	}

	/**
	 * Get the next available version letter for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return string The next version letter (a-z, then aa, ab, etc.).
	 */
	public static function get_next_version_letter( $post_id ) {
		$revisions = self::get_public_revisions( $post_id );

		if ( empty( $revisions ) ) {
			return 'a';
		}

		$letters = array_column( $revisions, 'version' );
		sort( $letters );
		$last = end( $letters );

		return self::increment_version_letter( $last );
	}

	/**
	 * Increment a version letter (a->b, z->aa, az->ba, etc.).
	 *
	 * @param string $letter The current version letter.
	 * @return string The next version letter.
	 */
	private static function increment_version_letter( $letter ) {
		$len  = strlen( $letter );
		$last = $letter[ $len - 1 ];

		if ( 'z' !== $last ) {
			return substr( $letter, 0, $len - 1 ) . chr( ord( $last ) + 1 );
		}

		if ( 1 === $len ) {
			return 'aa';
		}

		return self::increment_version_letter( substr( $letter, 0, $len - 1 ) ) . 'a';
	}

	/**
	 * Toggle a revision's public status.
	 *
	 * @param int $post_id     The parent post ID.
	 * @param int $revision_id The revision ID to toggle.
	 * @return array The result with 'action' (added|removed) and 'version' letter.
	 */
	public static function toggle_public_revision( $post_id, $revision_id ) {
		$revisions = self::get_public_revisions( $post_id );

		foreach ( $revisions as $index => $entry ) {
			if ( (int) $entry['revision_id'] === (int) $revision_id ) {
				$version = $entry['version'];
				array_splice( $revisions, $index, 1 );
				update_post_meta( $post_id, self::META_KEY, $revisions );
				return array(
					'action'  => 'removed',
					'version' => $version,
				);
			}
		}

		$version     = self::get_next_version_letter( $post_id );
		$revisions[] = array(
			'version'     => $version,
			'revision_id' => (int) $revision_id,
		);
		update_post_meta( $post_id, self::META_KEY, $revisions );

		return array(
			'action'  => 'added',
			'version' => $version,
		);
	}
}
