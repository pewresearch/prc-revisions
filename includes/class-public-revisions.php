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
	 * Whether the request URL is a /version/{letter} public revision URL.
	 * Set in parse_request when we normalize the main query to the parent post.
	 *
	 * @var bool
	 */
	private $is_version_request = false;

	/**
	 * Parent post ID when parse_request resolved a version URL (fallback if queried object is wrong).
	 *
	 * @var int|null
	 */
	private $version_endpoint_parent_id = null;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'register_meta' );
		$loader->add_action( 'parse_request', $this, 'maybe_intercept_version_request', 1 );
		$loader->add_filter( 'redirect_canonical', $this, 'prevent_version_canonical_redirect', 1, 2 );
		$loader->add_action( 'template_redirect', $this, 'handle_version_endpoint' );
		$loader->add_action( 'revision_applied', $this, 'on_revision_application__copy_attachments', 10, 2 );
		$loader->add_filter( 'wp_prepare_revision_for_js', $this, 'inject_public_flag_into_revision_js', 10, 3 );
		$loader->add_filter( 'pre_delete_post', $this, 'protect_public_revision', 10, 3 );
		$loader->add_filter( 'get_post_metadata', $this, 'filter_parent_meta_for_public_revision', 10, 4 );
		$loader->add_filter( 'the_author', $this, 'filter_the_author_for_public_revision', 10, 1 );
		$loader->add_filter( 'get_the_date', $this, 'filter_get_the_date_for_public_revision', 10, 3 );
		$loader->add_filter( 'get_the_modified_date', $this, 'filter_get_the_modified_date_for_public_revision', 10, 3 );
		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_public_version_notice_styles' );
		$loader->add_filter( 'document_title_parts', $this, 'filter_document_title_parts_for_public_revision', 10, 1 );
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
	 * Resolve /post-slug/version/{letter} to the parent post early.
	 *
	 * WordPress can otherwise match the wrong post (or trigger redirect_canonical /
	 * 404 permalink guessing to an unrelated post) when the main query does not
	 * bind the version endpoint to the correct permalink. We strip the suffix,
	 * resolve the parent via URL (VIP-cached when available), and set query vars
	 * explicitly — same pattern as prc-embeds iframe URLs.
	 *
	 * @hook parse_request
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function maybe_intercept_version_request( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! $path ) {
			return;
		}

		if ( ! preg_match( '#/version/([a-zA-Z]{1,2})/?$#', $path, $matches ) ) {
			return;
		}

		$version_letter = strtolower( sanitize_text_field( $matches[1] ) );

		// Research-team permalinks (prc-taxonomies) already set `research_team` + `version` in query_vars.
		// Do not replace the whole query — that drops year/month/day/name and breaks resolution for logged-in users.
		if ( ! empty( $wp->query_vars['research_team'] ) && isset( $wp->query_vars['version'] ) ) {
			$post_id = $this->resolve_post_id_from_research_team_query( $wp->query_vars );
			if ( ! $post_id ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$this->is_version_request            = true;
			$this->version_endpoint_parent_id = $post_id;

			$wp->query_vars['p']                         = $post_id;
			$wp->query_vars['post_type']                 = $post->post_type;
			$wp->query_vars[ Rewrite::ENDPOINT ]         = $version_letter;

			return;
		}

		$parent_path = preg_replace( '#/version/[a-zA-Z]{1,2}/?$#', '', $path );
		$parent_path = trim( $parent_path, '/' );

		if ( '' === $parent_path ) {
			return;
		}

		// Strip multisite subdirectory prefix (e.g. pewresearch-org) — home_url() already includes it.
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && str_starts_with( $parent_path, $home_path ) ) {
			$parent_path = substr( $parent_path, strlen( $home_path ) );
			$parent_path = trim( $parent_path, '/' );
		}

		if ( '' === $parent_path ) {
			return;
		}

		$parent_url = home_url( '/' . $parent_path . '/' );
		$post_id    = function_exists( 'wpcom_vip_url_to_postid' )
			? (int) wpcom_vip_url_to_postid( $parent_url )
			: (int) url_to_postid( $parent_url );

		if ( ! $post_id ) {
			$parent_url = home_url( '/' . $parent_path );
			$post_id    = function_exists( 'wpcom_vip_url_to_postid' )
				? (int) wpcom_vip_url_to_postid( $parent_url )
				: (int) url_to_postid( $parent_url );
		}

		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$this->is_version_request            = true;
		$this->version_endpoint_parent_id = $post_id;

		$wp->query_vars = array(
			'p'                => $post_id,
			'post_type'        => $post->post_type,
			Rewrite::ENDPOINT => $version_letter,
		);
	}

	/**
	 * Resolve the post ID from research-team rewrite query vars (year/month/day/name or post_type+name).
	 *
	 * @param array $qv Parsed query variables from the main request.
	 * @return int Post ID or 0.
	 */
	private function resolve_post_id_from_research_team_query( array $qv ) {
		$args = array(
			'posts_per_page'         => 1,
			'post_status'            => array( 'publish' ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'update_post_meta_cache'   => false,
			'update_post_term_cache'   => false,
		);

		if ( ! empty( $qv['post_type'] ) ) {
			$args['post_type'] = $qv['post_type'];
		} else {
			$args['post_type'] = 'post';
		}

		if ( ! empty( $qv['name'] ) ) {
			$args['name'] = $qv['name'];
		}

		if ( ! empty( $qv['year'] ) ) {
			$args['year'] = (int) $qv['year'];
		}
		if ( ! empty( $qv['monthnum'] ) ) {
			$args['monthnum'] = (int) $qv['monthnum'];
		}
		if ( ! empty( $qv['day'] ) ) {
			$args['day'] = (int) $qv['day'];
		}

		$query = new \WP_Query( $args );
		if ( $query->have_posts() ) {
			return (int) $query->posts[0];
		}

		return 0;
	}

	/**
	 * Prevent canonical redirect away from /version/{letter} URLs.
	 *
	 * Core would otherwise 301 to the "canonical" permalink and drop the version segment.
	 *
	 * @hook redirect_canonical
	 *
	 * @param string|false $redirect_url  Redirect URL, or false to cancel.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function prevent_version_canonical_redirect( $redirect_url, $requested_url ) {
		if ( $this->is_version_request ) {
			return false;
		}
		return $redirect_url;
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

		$post_id = ( null !== $this->version_endpoint_parent_id )
			? (int) $this->version_endpoint_parent_id
			: (int) get_queried_object_id();
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
		add_filter( 'the_content', array( $this, 'prepend_newer_version_notice' ), 5 );
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

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( ! $revision ) {
			$version = strtoupper( self::$current_revision_context['version'] );
			return $title . ' (Version ' . $version . ')';
		}

		$version = strtoupper( self::$current_revision_context['version'] );
		return $revision->post_title . ' (Version ' . $version . ')';
	}

	/**
	 * Show the revision snapshot date in the_date / get_the_date on the public version view.
	 *
	 * @hook get_the_date
	 *
	 * @param string|false $the_date The formatted date.
	 * @param string       $format   PHP date format.
	 * @param \WP_Post     $post     Post object.
	 * @return string|false
	 */
	public function filter_get_the_date_for_public_revision( $the_date, $format, $post ) {
		if ( null === self::$current_revision_context || ! $post instanceof \WP_Post ) {
			do_action('qm/debug', 'No revision context');
			return $the_date;
		}
		if ( (int) $post->ID !== self::$current_revision_context['parent_id'] ) {
			do_action('qm/debug', 'Not parent');
			return $the_date;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			do_action('qm/debug', 'Not in loop');
			return $the_date;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );

		do_action('qm/debug', 'REVISION:');
		do_action('qm/debug', print_r($revision, true));

		if ( ! $revision ) {
			return $the_date;
		}

		// Prefer GMT columns (UTC instants). Core sets PHP's default timezone to UTC, so
		// passing local post_date through strtotime() misreads them before wp_date().
		$time = get_post_time( 'U', true, $revision );
		if ( false === $time ) {
			$time = get_post_time( 'U', false, $revision );
		}
		if ( false === $time ) {
			return $the_date;
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' );
		}

		return wp_date( $format, $time );
	}

	/**
	 * Align "last updated" style output with the revision snapshot when viewing a public version.
	 *
	 * @hook get_the_modified_date
	 *
	 * @param string|false $the_date The formatted date.
	 * @param string       $format   PHP date format.
	 * @param \WP_Post     $post     Post object.
	 * @return string|false
	 */
	public function filter_get_the_modified_date_for_public_revision( $the_date, $format, $post ) {
		if ( null === self::$current_revision_context || ! $post instanceof \WP_Post ) {
			return $the_date;
		}
		if ( (int) $post->ID !== self::$current_revision_context['parent_id'] ) {
			return $the_date;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $the_date;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( ! $revision ) {
			return $the_date;
		}

		// Prefer modified time, then published; GMT first for each, then local (site TZ).
		$time = get_post_modified_time( 'U', true, $revision );
		if ( false === $time ) {
			$time = get_post_modified_time( 'U', false, $revision );
		}
		if ( false === $time ) {
			$time = get_post_time( 'U', true, $revision );
		}
		if ( false === $time ) {
			$time = get_post_time( 'U', false, $revision );
		}
		if ( false === $time ) {
			return $the_date;
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' );
		}

		return wp_date( $format, $time );
	}

	/**
	 * Use the revision author's display name for byline-style output that relies on the_author.
	 *
	 * @hook the_author
	 *
	 * @param string $display_name Author display name.
	 * @return string
	 */
	public function filter_the_author_for_public_revision( $display_name ) {
		if ( null === self::$current_revision_context ) {
			return $display_name;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $display_name;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( ! $revision ) {
			return $display_name;
		}

		$author = get_the_author_meta( 'display_name', (int) $revision->post_author );
		return '' !== $author ? $author : $display_name;
	}

	/**
	 * Expose revision bylines meta on the parent post ID while viewing a public version (staff bylines block).
	 *
	 * @hook get_post_metadata
	 *
	 * @param mixed  $value    The value to return.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Whether to return a single value.
	 * @return mixed
	 */
	public function filter_parent_meta_for_public_revision( $value, $post_id, $meta_key, $single ) {
		if ( null === self::$current_revision_context ) {
			return $value;
		}
		if ( (int) $post_id !== self::$current_revision_context['parent_id'] ) {
			return $value;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $value;
		}

		if ( 'bylines' !== $meta_key && 'displayBylines' !== $meta_key ) {
			return $value;
		}

		$revision_id = self::$current_revision_context['revision_id'];
		// Always read raw meta rows ($single => false). If we used $single true here and
		// returned the unserialized value (e.g. full bylines array), core get_metadata()
		// would then treat that return as a list of rows and, when the outer call used
		// $single true, return only $check[0] — the first byline only. See get_metadata()
		// in wp-includes/meta.php; Nelio A/B Testing uses the same pattern.
		$rev_rows = get_metadata( 'post', $revision_id, $meta_key, false );

		if ( 'bylines' === $meta_key ) {
			if ( is_array( $rev_rows ) && ! empty( $rev_rows ) ) {
				return $rev_rows;
			}
			return $value;
		}

		// displayBylines: use revision when set; otherwise fall back to parent meta.
		$first = is_array( $rev_rows ) && array_key_exists( 0, $rev_rows ) ? $rev_rows[0] : null;
		if ( '' !== $first && null !== $first ) {
			return $rev_rows;
		}

		return $value;
	}

	/**
	 * Prepend a notice that a newer version of the article exists (parent is newer than this snapshot).
	 *
	 * @hook the_content
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function prepend_newer_version_notice( $content ) {
		if ( null === self::$current_revision_context ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$parent = get_post( self::$current_revision_context['parent_id'] );
		if ( ! $parent ) {
			return $content;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( ! $revision ) {
			return $content;
		}

		$parent_ts   = strtotime( $parent->post_modified_gmt ? $parent->post_modified_gmt : $parent->post_modified );
		$revision_ts = strtotime( $revision->post_date_gmt ? $revision->post_date_gmt : $revision->post_date );

		if ( false === $parent_ts || false === $revision_ts || $parent_ts <= $revision_ts ) {
			return $content;
		}

		$current_url = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path        = $current_url ? (string) wp_parse_url( $current_url, PHP_URL_PATH ) : '';
		$notice_path = $path ? preg_replace( '#/version/[a-zA-Z]{1,2}/?$#', '', $path ) : '';
		$notice_path = $notice_path ? trailingslashit( $notice_path ) : '';
		$notice_url  = $notice_path ? home_url( $notice_path ) : get_permalink( $parent->ID );

		$message = sprintf(
			/* translators: %s: link to the current version of the article */
			__( 'A <a href="%s">newer version</a> of this article is available.', 'prc-revisions' ),
			esc_url( $notice_url )
		);

		$notice = '<div class="prc-public-revision-notice" role="status">' . wp_kses_post( $message ) . '</div>';

		return $notice . $content;
	}

	/**
	 * Inline styles for the newer-version notice (no separate asset file).
	 *
	 * @hook wp_enqueue_scripts
	 */
	public function enqueue_public_version_notice_styles() {
		if ( null === self::$current_revision_context ) {
			return;
		}

		$css = '.prc-public-revision-notice{border:1px solid var(--wp--preset--color--neutral-400, #ccc);background:var(--wp--preset--color--neutral-100, #f6f7f7);padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:var(--wp--preset--font-size--small, 0.875rem);line-height:1.5;}';

		wp_register_style( 'prc-public-revision-notice', false, array(), PRC_REVISIONS_VERSION );
		wp_enqueue_style( 'prc-public-revision-notice' );
		wp_add_inline_style( 'prc-public-revision-notice', $css );
	}

	/**
	 * Use the public revision title in the HTML document title.
	 *
	 * @hook document_title_parts
	 *
	 * @param array $title Document title parts.
	 * @return array
	 */
	public function filter_document_title_parts_for_public_revision( $title ) {
		if ( null === self::$current_revision_context || ! is_array( $title ) ) {
			return $title;
		}

		$revision = get_post( self::$current_revision_context['revision_id'] );
		if ( ! $revision ) {
			return $title;
		}

		$version = strtoupper( self::$current_revision_context['version'] );
		$title['title'] = $revision->post_title . ' (Version ' . $version . ')';

		return $title;
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
