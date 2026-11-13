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

use PRC\Primitives\Concurrency\Lease;
use PRC\Primitives\Concurrency\Meta_State;
use PRC\Primitives\Concurrency\Meta_State_Error;
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
	 * Possible values are the `Fork_Status` cases: draft, merging, merged, discarded.
	 * Only `Meta_State` writes it. A write waits on the return value of
	 * `fork_status()->transition()`. A refusal or a guard reads it with
	 * `read_fork_status()`, the one read of the key.
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
		'_wp_desired_post_slug',
		'_prc_fork_parent',
		'_prc_fork_status',
		'_prc_fork_merging_since',
		'_prc_fork_recovery_attempts',
		'_prc_active_fork',
		'_prc_public_revisions',
	);

	/**
	 * Option name prefix of the per-parent lease that every `_prc_active_fork` writer takes.
	 *
	 * The row is `prc_fork_create_{parent ID}`, far under the 191 byte option name limit.
	 * Do not rename it. A row written before a deploy must still block after it.
	 *
	 * @var string
	 */
	const FORK_LEASE_PREFIX = 'prc_fork_create_';

	/**
	 * Seconds before another process may take over an abandoned fork lease.
	 *
	 * Create calls `refresh()` after its one slow step, `wp_insert_post()`. The TTL
	 * must stay above three times `FORK_LEASE_WORST_CASE_SECONDS`, and a test
	 * asserts it.
	 *
	 * @var int
	 */
	const FORK_LEASE_TTL = 30;

	/**
	 * Seconds the slowest create or merge took on the largest post measured.
	 *
	 * Measured on the dev-env with outbound HTTP blocked: 4.3 MB of content, 400
	 * blocks, 100 meta keys of 2 KB, 10 terms, and 40 attachments. The merge took
	 * 1.9 s at most (create 0.6 s). A post of 1 MB with 200 blocks took 1.0 s to merge
	 * (create 0.4 s). The value is rounded up. Raise it, and the TTL with it, when a
	 * new measure is slower. Logs: `r-fix2/r3/lease-measure-*.log` in the PR evidence.
	 *
	 * @var int
	 */
	const FORK_LEASE_WORST_CASE_SECONDS = 2;

	/**
	 * Seconds a scheduled fork waits for the next cron publish after its merge met a held lease.
	 *
	 * @var int
	 */
	const MERGE_RETRY_SECONDS = 60;

	/**
	 * Query arg that tells the classic admin a trash was refused because the lease was held.
	 *
	 * @var string
	 */
	const TRASH_BUSY_QUERY_ARG = 'prc_fork_trash_busy';

	/**
	 * Query arg that tells the classic admin a publish went back on the schedule because the merge was busy.
	 *
	 * @var string
	 */
	const PUBLISH_BUSY_QUERY_ARG = 'prc_fork_publish_busy';

	/**
	 * The fork status state machine, built once per request.
	 *
	 * @var Meta_State|null
	 */
	private static $fork_status = null;

	/**
	 * Query arg that carries the reason a classic admin Restore was refused.
	 *
	 * @var string
	 */
	const RESTORE_REFUSED_QUERY_ARG = 'prc_fork_restore';

	/**
	 * Query arg that carries how many items of a bulk Restore were refused.
	 *
	 * @var string
	 */
	const RESTORE_REFUSED_COUNT_ARG = 'prc_fork_refused';

	/**
	 * Query arg that carries how many items of a bulk Restore went through.
	 *
	 * @var string
	 */
	const RESTORE_RESTORED_COUNT_ARG = 'prc_fork_restored';

	/**
	 * Fork IDs whose restore the status hook already handled, keyed by post ID.
	 *
	 * `untrashed_post` fires after `transition_post_status` on the same restore.
	 *
	 * @var array<int, true>
	 */
	private $restore_handled = array();

	/**
	 * Fork IDs whose refused restore write the backstop turned back into a no-op, keyed by post ID.
	 *
	 * @var array<int, true>
	 */
	private $refused_writes = array();

	/**
	 * Refusal codes of the bulk Restore in progress, in the order the items were refused.
	 *
	 * @var string[]
	 */
	private $bulk_restore_refusals = array();

	/**
	 * Fork leases this request holds, keyed by parent post ID.
	 *
	 * Create, restore, merge, and a trash that moves the pointer each take the lease
	 * before they write. The entry names the fork the holder works on and the
	 * operation that took it. A second operation on another fork in the same request
	 * does not reuse it. The holder gives it back after its pointer write, or the
	 * shutdown hook does when the request ends first.
	 *
	 * @var array<int, array{token: string, fork_id: int, by: string}>
	 */
	private static $held_leases = array();

	/**
	 * Fork IDs whose trash or delete was refused because another process held the lease, keyed by post ID.
	 *
	 * @var array<int, true>
	 */
	private static $refused_leaves = array();

	/**
	 * Fork IDs whose admin publish went back on the schedule, keyed by post ID.
	 *
	 * @var array<int, true>
	 */
	private static $deferred_publishes = array();

	/**
	 * Number of items of the bulk Trash in progress that were refused because the lease was held.
	 *
	 * @var int
	 */
	private $bulk_trash_refusals = 0;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		self::fork_status()->guard();

		$loader->add_action( 'init', $this, 'register_meta', 20 );
		$loader->add_action( 'prc_platform_on_publish', $this, 'handle_fork_publish', 5, 1 );
		$loader->add_action( 'before_delete_post', $this, 'cleanup_fork_reference' );
		$loader->add_action( 'wp_trash_post', $this, 'cleanup_fork_reference' );
		$loader->add_filter( 'pre_trash_post', $this, 'gate_fork_pointer_move', 10, 2 );
		$loader->add_filter( 'pre_delete_post', $this, 'gate_fork_pointer_move', 10, 2 );
		$loader->add_action( 'trashed_post', $this, 'release_fork_pointer_move' );
		$loader->add_action( 'deleted_post', $this, 'release_fork_pointer_move' );
		$loader->add_action( 'trashed_post', $this, 'discard_trashed_merging_fork', 5 );
		$loader->add_filter( 'pre_untrash_post', $this, 'refuse_blocked_untrash', 10, 2 );
		$loader->add_filter( 'wp_insert_post_data', $this, 'guard_restore_write', 5, 2 );
		$loader->add_action( 'untrashed_post', $this, 'maybe_activate_restored_fork' );
		$loader->add_action( 'transition_post_status', $this, 'maybe_activate_restored_fork_on_status', 10, 3 );
		$loader->add_action( 'rest_api_init', $this, 'register_rest_restore_guard' );
		$loader->add_action( 'admin_notices', $this, 'render_restore_refused_notice' );
		$loader->add_action( 'admin_notices', $this, 'render_busy_notices' );
		$loader->add_filter( 'redirect_post_location', $this, 'flag_deferred_publish_redirect', 10, 2 );
		$loader->add_action( 'manage_posts_extra_tablenav', $this, 'render_classic_list_flag' );
		$loader->add_action( 'manage_pages_extra_tablenav', $this, 'render_classic_list_flag' );
		$loader->add_filter( 'removable_query_args', $this, 'remove_restore_refused_query_arg' );
		$loader->add_filter( 'wp_revisions_to_keep', $this, 'skip_refused_restore_revision', 10, 2 );
		$loader->add_filter( 'prc_platform_post_publish_pipeline_should_process', $this, 'skip_refused_restore_pipeline', 10, 2 );
		$loader->add_action( 'wp_after_insert_post', $this, 'forget_refused_restore_write', 999 );
		$loader->add_action( 'shutdown', $this, 'release_held_leases' );
		$loader->add_action( 'wp_body_open', $this, 'render_future_revision_banner' );
		$loader->add_filter( 'display_post_states', $this, 'add_future_revision_post_state', 10, 2 );
		$loader->add_filter( 'wp_unique_post_slug', $this, 'keep_parent_slug_on_fork', 10, 6 );
	}

	/**
	 * Keep a fork's slug mergeable when an editor sets it to the parent's slug.
	 *
	 * Without this, WP suffixes the fork (`slug-2`) because the parent owns the
	 * slug, and the merge then writes that suffixed slug onto the parent. The
	 * `__fork` form tells `merge_fork()` to keep the parent's slug.
	 *
	 * @hook wp_unique_post_slug
	 *
	 * @param string $slug          The unique slug WP chose.
	 * @param int    $post_id       Post ID.
	 * @param string $post_status   Post status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Post parent ID.
	 * @param string $original_slug The slug before WP made it unique.
	 * @return string
	 */
	public function keep_parent_slug_on_fork( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( $slug === $original_slug || ! $post_id ) {
			return $slug;
		}

		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id ) {
			return $slug;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent || $parent->post_name !== $original_slug ) {
			return $slug;
		}

		return $original_slug . '__fork';
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
					'description'       => 'Fork status: draft, merging, merged, or discarded. Read only. Only the fork workflow changes it.',
					'default'           => '',
					'show_in_rest'      => array(
						'schema' => array(
							'type'     => 'string',
							'readonly' => true,
						),
					),
					'sanitize_callback' => function ( $value ) {
						return null !== Fork_Status::tryFrom( (string) $value ) ? $value : '';
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
					'description'   => 'The ID of the currently active fork for this post. Read-only: only the fork lease holders write it.',
					'default'       => 0,
					'show_in_rest'  => true,
					'auth_callback' => '__return_false',
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
	 * The guard reads `_prc_active_fork` and writes it later, with an insert in
	 * between. Two creates on one parent could both pass the read. The create runs
	 * under the parent's fork lease, and the pointer is read again inside it.
	 *
	 * When another process holds the lease, this returns `fork_create_busy` and
	 * writes nothing. The REST route answers 409. The caller may try again.
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

		$parent_id = absint( $parent_post_id );
		$token     = self::take_held_lease( $parent_id, 0, 'create' );
		if ( '' === $token ) {
			return self::create_busy_error();
		}

		try {
			return self::create_fork_under_lease( $parent, self::fork_lease(), $token );
		} finally {
			self::release_held_lease( $parent_id );
		}
	}

	/**
	 * Create the fork while this process holds the parent's fork lease.
	 *
	 * @param \WP_Post $parent_post The published parent post.
	 * @param Lease    $lease       The fork lease.
	 * @param string   $token       Owner token from `acquire()`.
	 * @return int|WP_Error The fork post ID, or WP_Error on failure.
	 */
	private static function create_fork_under_lease( $parent_post, $lease, $token ) {
		$parent_post_id = (int) $parent_post->ID;

		$existing_fork = self::read_active_fork( $parent_post_id );
		if ( $existing_fork ) {
			$existing_post = get_post( $existing_fork );
			// Only treat as a conflict when the pointer still refers to an open
			// fork of this parent. A merged or discarded fork (or a leftover
			// pointer) must not block creating a new fork.
			if (
				$existing_post
				&& 'trash' !== $existing_post->post_status
				&& absint( get_post_meta( $existing_fork, self::FORK_PARENT_META, true ) ) === $parent_post_id
			) {
				if ( self::is_closed( $existing_fork ) ) {
					// The value limits the delete to this fork id. A pointer that names a newer fork stays.
					delete_post_meta( $parent_post_id, self::ACTIVE_FORK_META, $existing_fork );
				} else {
					return new WP_Error(
						'fork_exists',
						__( 'An active fork already exists for this post.', 'prc-revisions' ),
						array( 'fork_id' => (int) $existing_fork )
					);
				}
			}
		}

		$fork_data = array(
			'post_type'    => $parent_post->post_type,
			'post_status'  => 'draft',
			'post_title'   => $parent_post->post_title,
			'post_name'    => $parent_post->post_name . '__fork',
			'post_content' => $parent_post->post_content,
			'post_excerpt' => $parent_post->post_excerpt,
			'post_author'  => get_current_user_id(),
			'post_parent'  => 0,
		);

		// wp_insert_post() runs wp_unslash() on post fields. Content from
		// get_post() is already unslashed, so without wp_slash() Gutenberg
		// RichText \uXXXX escapes in block markup are corrupted
		// (\u003c → u003c). See PRC-633 / PRC-528.
		$fork_id = wp_insert_post( wp_slash( $fork_data ), true );
		if ( is_wp_error( $fork_id ) ) {
			return $fork_id;
		}

		// The insert is the one slow step. If it outlasted the TTL, another create may
		// hold the lease now. Drop this fork before it carries any fork meta.
		if ( ! $lease->refresh( $parent_post_id, $token ) ) {
			wp_delete_post( $fork_id, true );
			return self::create_busy_error();
		}

		update_post_meta( $fork_id, self::FORK_PARENT_META, $parent_post_id );

		try {
			self::fork_status()->init( (int) $fork_id, Fork_Status::Draft );
		} catch ( Meta_State_Error ) {
			wp_delete_post( $fork_id, true );
			return new WP_Error(
				'fork_status_failed',
				__( 'Could not record the status of the future revision.', 'prc-revisions' )
			);
		}

		update_post_meta( $parent_post_id, self::ACTIVE_FORK_META, $fork_id );

		// The pointer is the last write the lease protects. The copies below touch only the new fork.
		self::release_held_lease( $parent_post_id );

		self::copy_taxonomy_terms( $parent_post_id, $fork_id );
		self::copy_post_meta( $parent_post_id, $fork_id );

		return $fork_id;
	}

	/**
	 * The state machine behind `_prc_fork_status`.
	 *
	 * Edges: draft to merging (a publish claims the merge), merging to merged
	 * (the merge wrote the parent), merging to draft (the merge failed before it
	 * wrote the parent), draft to discarded (an editor discards the fork),
	 * merging to discarded (core trashed a fork whose merge is stuck), and
	 * discarded to draft (an editor restores the fork, or the trash call failed).
	 * `merged` has no way out. Only the two trash paths that hold the lease use
	 * merging to discarded. It is one swap, so a publish claim cannot meet a
	 * `draft` between the two, and `trash_fork()` still refuses a fork in `merging`.
	 *
	 * The constructor calls `guard()`. After that, `add_post_meta()`,
	 * `update_post_meta()`, `delete_post_meta()`, and a REST `meta` write of the
	 * key change nothing. `drop_rest_fork_status()` removes the key from a REST
	 * write first, so the client gets a clean response.
	 *
	 * @return Meta_State
	 */
	public static function fork_status() {
		if ( null === self::$fork_status ) {
			self::$fork_status = new Meta_State(
				self::FORK_STATUS_META,
				Fork_Status::class,
				array(
					array( Fork_Status::Draft, Fork_Status::Merging ),
					array( Fork_Status::Merging, Fork_Status::Merged ),
					array( Fork_Status::Merging, Fork_Status::Draft ),
					array( Fork_Status::Draft, Fork_Status::Discarded ),
					array( Fork_Status::Merging, Fork_Status::Discarded ),
					array( Fork_Status::Discarded, Fork_Status::Draft ),
				)
			);
		}

		return self::$fork_status;
	}

	/**
	 * Give a fork that has no status row its first one, `draft`.
	 *
	 * Forks made before `_prc_fork_status` moved to `Meta_State` may have no row.
	 * `init()` writes it once, whatever the number of callers. The existence
	 * check reads the meta cache, so a fork that has a row costs no query.
	 * `wp prc revisions backfill-fork-status` does the same for every live fork.
	 *
	 * @param int $fork_id Fork post ID.
	 */
	private static function ensure_status( $fork_id ) {
		if ( metadata_exists( 'post', $fork_id, self::FORK_STATUS_META ) ) {
			return;
		}

		self::fork_status()->init( (int) $fork_id, Fork_Status::Draft );
	}

	/**
	 * The one read of `_prc_fork_status` for a guard or a refusal.
	 *
	 * `Meta_State` writes with its own SQL, so the read does not trust the meta
	 * cache, and pins `wp_postmeta` to the primary because the lease write pins
	 * `wp_options` only. A write never waits on this read. It waits on the return
	 * value of `fork_status()->transition()`.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return Fork_Status|null The status, or null when the fork has no valid row.
	 */
	private static function read_fork_status( $fork_id ) {
		self::pin_postmeta_to_primary();
		wp_cache_delete( $fork_id, 'post_meta' );
		return Fork_Status::tryFrom( (string) get_post_meta( $fork_id, self::FORK_STATUS_META, true ) );
	}

	/**
	 * Whether a fork is closed: merged into its parent, or discarded.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return bool
	 */
	private static function is_closed( $fork_id ) {
		return in_array( self::read_fork_status( $fork_id ), array( Fork_Status::Merged, Fork_Status::Discarded ), true );
	}

	/**
	 * The per-parent lease every `_prc_active_fork` writer takes.
	 *
	 * @return Lease
	 */
	private static function fork_lease() {
		return new Lease( self::FORK_LEASE_PREFIX, self::FORK_LEASE_TTL, 'fork_' );
	}

	/**
	 * Error for a create that met a held lease.
	 *
	 * @return WP_Error
	 */
	private static function create_busy_error() {
		return new WP_Error(
			'fork_create_busy',
			__( 'Another change to this post\'s future revisions is still running. Try again in a moment.', 'prc-revisions' ),
			array( 'retry_after' => 1 )
		);
	}

	/**
	 * Error for a merge that met a held lease. It wrote nothing.
	 *
	 * @return WP_Error
	 */
	private static function merge_busy_error() {
		return new WP_Error(
			'fork_merge_busy',
			self::lease_refusal_messages()['fork_merge_busy'],
			array( 'status' => 409 )
		);
	}

	/**
	 * Editor-facing text for each refusal that comes from a held lease.
	 *
	 * @return array<string, string>
	 */
	private static function lease_refusal_messages() {
		return array(
			'fork_merge_busy' => __( 'Another change to this post\'s future revisions is still running. Wait a moment, then publish again.', 'prc-revisions' ),
			'fork_trash_busy' => __( 'Another change to this post\'s future revisions is still running. Wait a moment, then discard again.', 'prc-revisions' ),
		);
	}

	/**
	 * Send every read of the post meta table in this request to the primary database.
	 *
	 * Taking the lease writes `wp_options`. HyperDB pins only that table to the
	 * primary after a write, so a `wp_postmeta` read still goes to a replica that
	 * may not have the last pointer write yet. `Meta_State` does the same for its
	 * reads. Plain wpdb has one server and nothing to pin.
	 */
	private static function pin_postmeta_to_primary() {
		global $wpdb;

		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->postmeta ] = true;
		}
	}

	/**
	 * Read `_prc_active_fork` from the primary database, not from a cached copy.
	 *
	 * Call it under the fork lease, or before a decision that the lease then
	 * confirms. The read is pinned to the primary on its own account, because the
	 * lease write pins `wp_options` only.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return int Fork ID, or 0.
	 */
	private static function read_active_fork( $parent_id ) {
		self::pin_postmeta_to_primary();
		wp_cache_delete( $parent_id, 'post_meta' );
		return absint( get_post_meta( $parent_id, self::ACTIVE_FORK_META, true ) );
	}

	/**
	 * Take the parent's fork lease without waiting, and note it as held by this request.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param int    $fork_id   Fork the holder works on, or 0 for a create.
	 * @param string $by        Operation that takes the lease.
	 * @return string Owner token, or '' when another process holds the lease.
	 */
	private static function take_held_lease( $parent_id, $fork_id, $by ) {
		$token = self::fork_lease()->acquire( $parent_id );
		if ( '' !== $token ) {
			self::$held_leases[ $parent_id ] = array(
				'token'   => $token,
				'fork_id' => (int) $fork_id,
				'by'      => $by,
			);
		}

		return $token;
	}

	/**
	 * Take the parent's fork lease, waiting a short time for its holder, and note it as held by this request.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param int    $fork_id   Fork the holder works on.
	 * @param string $by        Operation that takes the lease.
	 * @return string Owner token, or '' when the holder outlasted the wait.
	 */
	private static function take_held_lease_waiting( $parent_id, $fork_id, $by ) {
		$token = self::acquire_fork_lease_waiting( self::fork_lease(), $parent_id );
		if ( '' !== $token ) {
			self::$held_leases[ $parent_id ] = array(
				'token'   => $token,
				'fork_id' => (int) $fork_id,
				'by'      => $by,
			);
		}

		return $token;
	}

	/**
	 * Whether this request holds the parent's fork lease for this fork.
	 *
	 * @param int $parent_id Parent post ID.
	 * @param int $fork_id   Fork post ID.
	 * @return bool
	 */
	private static function holds_lease( $parent_id, $fork_id ) {
		return isset( self::$held_leases[ $parent_id ] ) && self::$held_leases[ $parent_id ]['fork_id'] === (int) $fork_id;
	}

	/**
	 * Give back the fork lease this request holds for a parent.
	 *
	 * @param int $parent_id Parent post ID.
	 */
	private static function release_held_lease( $parent_id ) {
		if ( ! isset( self::$held_leases[ $parent_id ] ) ) {
			return;
		}

		$held = self::$held_leases[ $parent_id ];
		unset( self::$held_leases[ $parent_id ] );
		self::fork_lease()->release( $parent_id, $held['token'] );
	}

	/**
	 * Give back every fork lease when the request ends.
	 *
	 * A create, restore, merge, or trash that stops before it gives its lease back
	 * would hold it until the TTL.
	 *
	 * @hook shutdown
	 */
	public function release_held_leases() {
		foreach ( array_keys( self::$held_leases ) as $parent_id ) {
			self::release_held_lease( (int) $parent_id );
		}
	}

	/**
	 * Take the fork lease, waiting a short time for its holder.
	 *
	 * Trash and merge must not fail because a create or restore is mid-flight.
	 * Holders keep the lease for a few queries. The wait is 1000 ms by default.
	 * Filter `prc_revisions_fork_lease_wait_ms` changes it.
	 *
	 * @param Lease $lease     The fork lease.
	 * @param int   $parent_id Parent post ID.
	 * @return string Owner token, or '' when the holder outlasted the wait.
	 */
	private static function acquire_fork_lease_waiting( $lease, $parent_id ) {
		$wait_ms  = max( 0, (int) apply_filters( 'prc_revisions_fork_lease_wait_ms', 1000 ) );
		$deadline = microtime( true ) + ( $wait_ms / 1000 );

		while ( true ) {
			$token = $lease->acquire( $parent_id );
			if ( '' !== $token || microtime( true ) >= $deadline ) {
				return $token;
			}
			usleep( 50000 );
		}
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
	 * A merge that meets a held lease writes nothing, and the fork goes back on the
	 * schedule one minute later, whoever published it: cron, a classic admin
	 * save, WP-CLI, or PHP. The publish request is kept, not dropped to draft.
	 * `flag_deferred_publish_redirect()` tells a classic admin editor.
	 *
	 * Two publishes of one fork can reach this hook at once: an editor's Publish
	 * and the cron that publishes a scheduled fork. Only the one whose
	 * `draft` to `merging` transition returns true merges. An editor's Discard
	 * is the other claim on `draft`, so exactly one of merge and discard wins.
	 *
	 * A merge that fails before it writes the parent goes back to `draft`, with the
	 * transition first. A request that dies between `merging` and `merged` leaves
	 * the fork in `merging`. `Fork_Recovery` finishes such a fork once it has been
	 * `merging` for longer than the lease TTL: see `recover_fork()`.
	 *
	 * The busy branch reads the fork again from the primary before and after its
	 * schedule write, and sends a fork that closed meanwhile to the Trash. A fork
	 * that closed is never put back on the schedule.
	 *
	 * A busy publish fires this hook twice on the fork: at the publish, and again
	 * when the schedule write publishes it. Every listener must be safe to run twice.
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

		self::ensure_status( $post->ID );

		if ( ! self::fork_status()->transition( $post->ID, Fork_Status::Draft, Fork_Status::Merging ) ) {
			self::settle_lost_publish( $post->ID );
			return;
		}

		$result = self::merge_fork( $post->ID, $parent_id );

		if ( is_wp_error( $result ) ) {
			self::fork_status()->transition( $post->ID, Fork_Status::Merging, Fork_Status::Draft );

			// A discard can claim the fork between the revert and the write below. The write
			// would then restore a trashed fork.
			if ( Fork_Status::Draft !== self::read_fork_status( $post->ID ) ) {
				return;
			}

			if ( 'fork_merge_busy' === $result->get_error_code() ) {
				if ( self::defer_fork_publish( $post->ID ) ) {
					self::$deferred_publishes[ (int) $post->ID ] = true;
				}
				return;
			}

			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * Put a fork whose merge was busy back on the schedule, unless the merge finished meanwhile.
	 *
	 * The first read decides whether to write. The schedule write can still land after
	 * the rival merge marked the fork merged and trashed it, and it then puts the
	 * fork back to `future`. So the fork is read once more after the write, and a
	 * merged one goes back to the Trash.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return bool True when the fork is on the schedule.
	 */
	private static function defer_fork_publish( $fork_id ) {
		$fork_id = (int) $fork_id;

		if ( self::fork_is_closed_or_trashed( $fork_id ) ) {
			return false;
		}

		self::reschedule_fork_publish( $fork_id );

		if ( self::fork_closed_on_primary( $fork_id ) ) {
			self::trash_closed_fork( $fork_id );
			return false;
		}

		return true;
	}

	/**
	 * Whether the fork is merged or in the Trash, read from the primary database.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return bool
	 */
	private static function fork_is_closed_or_trashed( $fork_id ) {
		return self::fork_closed_on_primary( $fork_id ) || 'trash' === get_post_status( $fork_id );
	}

	/**
	 * Whether the fork is merged or discarded, read from the primary database.
	 *
	 * The merge that held the lease writes the status and the post row in this
	 * order: merged, pointer, Trash. A replica or a cached copy may still show the
	 * values from before. `is_closed()` pins the post meta table to the primary.
	 * This read pins the post table and drops its cached copy as well, so the
	 * `get_post_status()` that follows is fresh.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return bool
	 */
	private static function fork_closed_on_primary( $fork_id ) {
		self::pin_post_to_primary( $fork_id );

		return self::is_closed( $fork_id );
	}

	/**
	 * Pin the post table to the primary database and drop the cached copy of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function pin_post_to_primary( $post_id ) {
		global $wpdb;

		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->posts ] = true;
		}
		wp_cache_delete( $post_id, 'posts' );
	}

	/**
	 * Send a closed fork (merged or discarded) to the Trash.
	 *
	 * A closed fork has no content left to publish. Another publish of it
	 * must not merge it again or leave it public or scheduled. The Trash write goes
	 * through `gate_fork_pointer_move()`. While another process holds the lease
	 * and the pointer still names the fork, the gate refuses, and that merge
	 * trashes the fork a moment later.
	 *
	 * @param int $fork_id Fork post ID.
	 */
	private static function trash_closed_fork( $fork_id ) {
		if ( 'trash' === get_post_status( $fork_id ) ) {
			return;
		}

		wp_trash_post( $fork_id );
	}

	/**
	 * Put a fork that was published but could not merge back on the schedule, one minute later.
	 *
	 * @param int $fork_id Fork post ID.
	 */
	private static function reschedule_fork_publish( $fork_id ) {
		$gmt = gmdate( 'Y-m-d H:i:s', time() + self::MERGE_RETRY_SECONDS );

		wp_update_post(
			array(
				'ID'            => $fork_id,
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
				'edit_date'     => true,
			)
		);
	}

	/**
	 * Keep a fork that lost the publish claim from staying published as its own post.
	 *
	 * The publish write already ran, from a read of the status that is now old.
	 * When the claim went to a discard or to a finished merge, that write put
	 * `publish` over a trashed fork. It goes back to the trash. A fork that is
	 * still `merging` belongs to the request that holds the claim, and that
	 * request trashes it or puts it back to draft. A status that is none of the
	 * four goes to draft, so no public copy of unmerged content stays.
	 *
	 * @param int $fork_id Fork post ID.
	 */
	private static function settle_lost_publish( $fork_id ) {
		$status = self::read_fork_status( $fork_id );

		if ( Fork_Status::Merged === $status || Fork_Status::Discarded === $status ) {
			wp_trash_post( $fork_id );
			return;
		}

		if ( null === $status && 'publish' === get_post_status( $fork_id ) ) {
			wp_update_post(
				array(
					'ID'          => $fork_id,
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
	 * 5. Move the fork from `merging` to `merged`, move the pointer off it, and
	 *    trash it.
	 *
	 * The caller must hold the claim: `handle_fork_publish()` moved the fork from
	 * `draft` to `merging` first. Under the lease the merge reads the status again
	 * and writes nothing unless it is still `merging`: an editor may have trashed
	 * the fork between the claim and the lease. Step 5 acts only when `merging` to
	 * `merged` returns true. Failures before step 2 writes the parent return a
	 * WP_Error, and the caller moves the fork back to `draft`.
	 *
	 * The merge holds the parent's fork lease from before step 1 to the end. When
	 * another process holds the lease past the wait, the merge returns
	 * `fork_merge_busy` and writes nothing. The caller moves the fork back to
	 * `draft` and may try again.
	 *
	 * `$resuming` is true when `recover_fork()` finishes a merge that a dead request
	 * started. The steps then repeat what the dead request may have done, and each one is
	 * safe to repeat. See `merge_fork_under_lease()`.
	 *
	 * @param int  $fork_id   The fork post ID.
	 * @param int  $parent_id The parent post ID.
	 * @param bool $resuming  Whether this run finishes a merge that stopped half way.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function merge_fork( $fork_id, $parent_id, $resuming = false ) {
		$fork   = get_post( $fork_id );
		$parent = get_post( $parent_id );

		if ( ! $fork || ! $parent ) {
			return new WP_Error( 'invalid_posts', __( 'Fork or parent post not found.', 'prc-revisions' ) );
		}

		$fork_id   = (int) $fork->ID;
		$parent_id = (int) $parent->ID;

		$holds_lease = self::holds_lease( $parent_id, $fork_id );
		if ( ! $holds_lease && '' === self::take_held_lease_waiting( $parent_id, $fork_id, 'merge' ) ) {
			return self::merge_busy_error();
		}

		try {
			// A merge that waited for the lease may have waited for another merge of this fork.
			$status = self::read_fork_status( $fork_id );
			if ( Fork_Status::Merged === $status ) {
				return true;
			}

			// A trash between the claim and the lease discarded the fork. Its content stays off the parent.
			if ( Fork_Status::Merging !== $status ) {
				return new WP_Error( 'fork_status_lost', __( 'The future revision is not being merged.', 'prc-revisions' ) );
			}

			return self::merge_fork_under_lease( $fork, $parent, $resuming );
		} finally {
			if ( ! $holds_lease ) {
				self::release_held_lease( $parent_id );
			}
		}
	}

	/**
	 * Finish the merge of a fork that a dead request left in `merging`.
	 *
	 * `Fork_Recovery` calls this for a fork that has been `merging` longer than the lease
	 * TTL. The call takes the parent's fork lease without waiting: a live merge or trash holds
	 * it, and the call then returns `fork_recover_busy` and writes nothing. Under the lease it
	 * reads the status again from the primary database, because another sweep may have
	 * finished the fork first. A fork that is no longer `merging` returns `fork_not_merging`.
	 * A fork that sits in the Trash was discarded by an editor after the lease expired. It
	 * is settled as discarded and also returns `fork_not_merging`. Otherwise the merge runs again, with `$resuming` set. A failed run leaves the fork in
	 * `merging`: the caller counts the attempt and the next sweep tries again.
	 *
	 * @param int $fork_id   The fork post ID.
	 * @param int $parent_id The parent post ID.
	 * @return true|WP_Error True when the merge finished, WP_Error when it did not.
	 */
	public static function recover_fork( $fork_id, $parent_id ) {
		$fork_id   = (int) $fork_id;
		$parent_id = (int) $parent_id;

		if ( ! $fork_id || ! $parent_id ) {
			return new WP_Error( 'invalid_posts', __( 'Fork or parent post not found.', 'prc-revisions' ) );
		}

		if ( '' === self::take_held_lease( $parent_id, $fork_id, 'recover' ) ) {
			return new WP_Error( 'fork_recover_busy', __( 'Another change to this post\'s future revisions is still running.', 'prc-revisions' ) );
		}

		try {
			self::pin_postmeta_to_primary();
			clean_post_cache( $fork_id );
			clean_post_cache( $parent_id );

			if ( Fork_Status::Merging !== self::read_fork_status( $fork_id ) ) {
				return new WP_Error( 'fork_not_merging', __( 'The future revision is not being merged.', 'prc-revisions' ) );
			}

			// A core trash can succeed once the dead request's lease has expired. The editor
			// discarded that fork, so its content stays off the parent.
			if ( 'trash' === get_post_status( $fork_id ) ) {
				self::fork_status()->transition( $fork_id, Fork_Status::Merging, Fork_Status::Discarded );
				return new WP_Error( 'fork_not_merging', __( 'The future revision was discarded while its merge was stuck.', 'prc-revisions' ) );
			}

			return self::merge_fork( $fork_id, $parent_id, true );
		} finally {
			self::release_held_lease( $parent_id );
		}
	}

	/**
	 * Trash a fork that a dead request left merged and still published.
	 *
	 * A request that dies after the `merging` to `merged` swap and before `wp_trash_post()`
	 * leaves a published fork at its `__fork__trashed` slug. `Fork_Recovery` calls this for
	 * every merged fork that is not in the Trash. The call takes the parent's fork lease without
	 * waiting: a live merge holds it through the trash, so a free lease means no merge of this
	 * fork is running. Under the lease it reads the status and the post again from the
	 * primary database. It moves the pointer off the fork and trashes it. It does not merge
	 * again and does not fire `revision_applied`. A second call finds the fork in the Trash and
	 * returns `fork_not_merged`.
	 *
	 * @param int $fork_id   The fork post ID.
	 * @param int $parent_id The parent post ID.
	 * @return true|WP_Error True when the fork is in the Trash.
	 */
	public static function settle_merged_fork( $fork_id, $parent_id ) {
		$fork_id   = (int) $fork_id;
		$parent_id = (int) $parent_id;

		if ( ! $fork_id || ! $parent_id ) {
			return new WP_Error( 'invalid_posts', __( 'Fork or parent post not found.', 'prc-revisions' ) );
		}

		if ( '' === self::take_held_lease( $parent_id, $fork_id, 'recover' ) ) {
			return new WP_Error( 'fork_recover_busy', __( 'Another change to this post\'s future revisions is still running.', 'prc-revisions' ) );
		}

		try {
			self::pin_post_to_primary( $fork_id );
			clean_post_cache( $fork_id );

			if ( Fork_Status::Merged !== self::read_fork_status( $fork_id ) || 'trash' === get_post_status( $fork_id ) ) {
				return new WP_Error( 'fork_not_merged', __( 'The future revision is not waiting for the Trash.', 'prc-revisions' ) );
			}

			self::move_pointer_off( $parent_id, $fork_id );

			if ( ! wp_trash_post( $fork_id ) ) {
				return new WP_Error( 'fork_trash_failed', __( 'The merged future revision could not be moved to the Trash.', 'prc-revisions' ) );
			}

			return true;
		} finally {
			self::release_held_lease( $parent_id );
		}
	}

	/**
	 * Whether the parent was modified at or after the moment the fork entered `merging`.
	 *
	 * A parent that is older than the claim cannot hold a write of this merge. A fork
	 * with no stamp counts as not written, so the merge writes the parent.
	 *
	 * @param int      $fork_id     Fork post ID.
	 * @param \WP_Post $parent_post The parent post.
	 * @return bool
	 */
	private static function parent_written_since_claim( $fork_id, $parent_post ) {
		$since = (int) get_post_meta( $fork_id, Fork_Recovery::SINCE_META, true );

		return $since > 0 && strtotime( $parent_post->post_modified_gmt . ' UTC' ) >= $since;
	}

	/**
	 * Merge the fork while this process holds the parent's fork lease.
	 *
	 * Every step is safe to run twice, because a dead request may have run some of them:
	 *
	 * - Meta and terms copy as a replace: the same values land again.
	 * - The fork slug comes from `_wp_desired_post_slug` when the dead request already gave
	 *   the fork the `__trashed` suffix, so the parent never gets a `__trashed` slug.
	 *   `wp_add_trashed_suffix_to_post_name_for_post()` leaves a suffixed slug alone.
	 * - When resuming, the parent write is skipped if the parent changed after the fork
	 *   entered `merging` and already holds the title, content, excerpt, and slug of the
	 *   fork. A resumed merge then adds no second parent write, revision, or update event.
	 *   A fork starts as a copy of its parent, so matching content alone does not show that
	 *   the write ran.
	 * - Re-parenting finds nothing left to move.
	 * - `revision_applied` fires again on a resume: a consumer that copies attachments
	 *   needs it at least once more than it needs it exactly once.
	 * - The `merging` to `merged` swap, the pointer move, and the trash act once.
	 *
	 * @param \WP_Post $fork        The fork post.
	 * @param \WP_Post $parent_post The parent post.
	 * @param bool     $resuming    Whether this run finishes a merge that stopped half way.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function merge_fork_under_lease( $fork, $parent_post, $resuming = false ) {
		$fork_id   = (int) $fork->ID;
		$parent_id = (int) $parent_post->ID;

		// Step 1: Copy meta and taxonomy first so the parent has the fork's
		// data before wp_update_post triggers the publish pipeline.
		self::copy_post_meta( $fork_id, $parent_id );
		self::copy_taxonomy_terms( $fork_id, $parent_id );

		// Step 2: Update parent content from fork.
		$fork_slug = $fork->post_name;
		if ( str_ends_with( $fork_slug, '__trashed' ) ) {
			$desired = (string) get_post_meta( $fork_id, '_wp_desired_post_slug', true );
			if ( '' !== $desired ) {
				$fork_slug = $desired;
			}
		}
		$parent_slug = $parent_post->post_name;
		$fork_suffix = '__fork';

		if ( str_ends_with( $fork_slug, $fork_suffix ) ) {
			$resolved_slug = $parent_slug;
		} else {
			$resolved_slug = $fork_slug;
		}

		// wp_unique_post_slug() checks posts of every status, so while the fork
		// still owns its slug the parent would get `-2`. The fork is trashed
		// below anyway; give it the trashed suffix now to free the slug.
		wp_add_trashed_suffix_to_post_name_for_post( $fork_id );

		$parent_holds_fork = $resuming
			&& self::parent_written_since_claim( $fork_id, $parent_post )
			&& $parent_post->post_title === $fork->post_title
			&& $parent_post->post_content === $fork->post_content
			&& $parent_post->post_excerpt === $fork->post_excerpt
			&& $parent_post->post_name === $resolved_slug;

		if ( $parent_holds_fork ) {
			$update_result = $parent_id;
		} else {
			// wp_update_post() unslashes post fields. Slash so Gutenberg RichText
			// \uXXXX escapes in block markup survive the merge write (PRC-633).
			$update_result = wp_update_post(
				wp_slash(
					array(
						'ID'            => $parent_id,
						'post_title'    => $fork->post_title,
						'post_name'     => $resolved_slug,
						'post_content'  => $fork->post_content,
						'post_excerpt'  => $fork->post_excerpt,
						'post_date'     => current_time( 'mysql' ),
						'post_date_gmt' => current_time( 'mysql', true ),
					)
				),
				true
			);
		}

		if ( is_wp_error( $update_result ) ) {
			self::restore_fork_slug( $fork_id, $fork_slug );
			return $update_result;
		}

		// Step 3: Re-parent attachments.
		$attachments = get_children(
			array(
				'post_parent' => $fork_id,
				'post_type'   => 'attachment',
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- One merge re-parents every attachment on the fork.
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
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- One merge re-parents every child post on the fork.
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
		if ( ! self::fork_status()->transition( (int) $fork_id, Fork_Status::Merging, Fork_Status::Merged ) ) {
			return new WP_Error( 'fork_status_lost', __( 'The future revision is not being merged.', 'prc-revisions' ) );
		}
		self::move_pointer_off( $parent_id, $fork_id );
		wp_trash_post( $fork_id );

		return true;
	}

	/**
	 * Undo wp_add_trashed_suffix_to_post_name_for_post() on a fork that failed to merge.
	 *
	 * @param int    $fork_id   The fork post ID.
	 * @param string $fork_slug The fork's slug before the merge.
	 */
	private static function restore_fork_slug( $fork_id, $fork_slug ) {
		global $wpdb;
		delete_post_meta( $fork_id, '_wp_desired_post_slug' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Mirrors core's trashed-suffix write; wp_update_post() would re-run the publish pipeline.
		$wpdb->update( $wpdb->posts, array( 'post_name' => $fork_slug ), array( 'ID' => $fork_id ) );
		clean_post_cache( $fork_id );
	}

	/**
	 * Clean up the parent's active fork reference when a fork is deleted or trashed.
	 *
	 * A trash or delete through `gate_fork_pointer_move()`, and a merge, already hold
	 * the fork lease. Any other caller takes it here, and writes nothing when
	 * another process holds it past the wait: a pointer that still names a trashed
	 * fork is harmless, because every reader skips a trashed fork.
	 *
	 * @hook before_delete_post
	 * @hook wp_trash_post
	 *
	 * @param int $post_id The post being deleted/trashed.
	 */
	public function cleanup_fork_reference( $post_id ) {
		$post_id   = absint( $post_id );
		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id ) {
			return;
		}

		if ( self::read_active_fork( $parent_id ) !== $post_id ) {
			return;
		}

		$holds_lease = self::holds_lease( $parent_id, $post_id );
		if ( ! $holds_lease && '' === self::take_held_lease_waiting( $parent_id, $post_id, 'cleanup' ) ) {
			return;
		}

		try {
			self::move_pointer_off( $parent_id, $post_id );
		} finally {
			if ( ! $holds_lease ) {
				self::release_held_lease( $parent_id );
			}
		}
	}

	/**
	 * Refuse a trash or delete of the fork the parent points at while another process holds the lease.
	 *
	 * Moving the pointer off a fork must not interleave with a create or a
	 * restore. This filter takes the lease before `wp_trash_post()` or
	 * `wp_delete_post()` writes anything. When the holder outlasts the wait, the
	 * trash or delete stops with nothing written, and `trash_fork()` answers
	 * 409. The lease stays held through `cleanup_fork_reference()` and is given
	 * back by `release_fork_pointer_move()`.
	 *
	 * @hook pre_trash_post
	 * @hook pre_delete_post
	 *
	 * @param bool|null $check Short-circuit value. Null lets the trash or delete go on.
	 * @param \WP_Post  $post  Post about to be trashed or deleted.
	 * @return bool|null
	 */
	public function gate_fork_pointer_move( $check, $post ) {
		if ( null !== $check || ! ( $post instanceof \WP_Post ) ) {
			return $check;
		}

		$post_id = (int) $post->ID;
		unset( self::$refused_leaves[ $post_id ] );

		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id || self::read_active_fork( $parent_id ) !== $post_id ) {
			return $check;
		}

		if ( self::holds_lease( $parent_id, $post_id ) ) {
			return $check;
		}

		if ( '' === self::take_held_lease_waiting( $parent_id, $post_id, 'trash' ) ) {
			$context = $this->classic_trash_context();
			if ( 'bulk' === $context ) {
				$this->note_bulk_trash_refusal();
				return $post;
			}
			if ( 'link' === $context ) {
				$this->redirect_refused_trash( $post );
			}

			self::$refused_leaves[ $post_id ] = true;
			return false;
		}

		return $check;
	}

	/**
	 * Which classic admin trash this request is: the row link on post.php, or the bulk action on edit.php.
	 *
	 * Core answers a refused `wp_trash_post()` with a bare `wp_die()`, and a bulk
	 * loop stops at the first one. REST, WP-CLI, and PHP callers have their own error channel.
	 *
	 * @return string 'link', 'bulk', or '' for any other caller.
	 */
	private function classic_trash_context() {
		global $pagenow;

		if ( ! is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core checked the nonce before it ran the action. This only reads which action it is.
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'post.php' === $pagenow && 'trash' === $action ) {
			return 'link';
		}

		if ( 'edit.php' === $pagenow && ( 'trash' === $action || 'trash' === $action2 ) ) {
			return 'bulk';
		}

		return '';
	}

	/**
	 * Send a refused classic Trash link back to the list with a notice.
	 *
	 * @param \WP_Post $post Fork whose trash was refused.
	 */
	private function redirect_refused_trash( $post ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = add_query_arg( 'post_type', $post->post_type, admin_url( 'edit.php' ) );
		}

		wp_safe_redirect( add_query_arg( self::TRASH_BUSY_QUERY_ARG, 1, $back ) );
		exit;
	}

	/**
	 * Record one fork of a bulk Trash that was refused, and report it with the redirect.
	 *
	 * The filter returns the post so core counts it as handled and goes on with the
	 * next item. It does not write. `report_bulk_trash_refusals()` takes the refused
	 * items out of core's trashed count.
	 */
	private function note_bulk_trash_refusal() {
		++$this->bulk_trash_refusals;
		if ( ! has_filter( 'wp_redirect', array( $this, 'report_bulk_trash_refusals' ) ) ) {
			add_filter( 'wp_redirect', array( $this, 'report_bulk_trash_refusals' ) );
		}
	}

	/**
	 * Say how many forks of a bulk Trash stayed out of the Trash.
	 *
	 * @hook wp_redirect
	 *
	 * @param string $location Redirect target built by core after the bulk loop.
	 * @return string
	 */
	public function report_bulk_trash_refusals( $location ) {
		if ( ! $this->bulk_trash_refusals ) {
			return $location;
		}

		$refused = $this->bulk_trash_refusals;
		$query   = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$trashed = isset( $query['trashed'] ) ? absint( $query['trashed'] ) : 0;

		$this->bulk_trash_refusals = 0;
		remove_filter( 'wp_redirect', array( $this, 'report_bulk_trash_refusals' ) );

		return add_query_arg(
			array(
				'trashed'                  => max( 0, $trashed - $refused ),
				self::TRASH_BUSY_QUERY_ARG => $refused,
			),
			remove_query_arg( array( 'ids' ), $location )
		);
	}

	/**
	 * Drop the "Post published" message from a post-save redirect when the publish went back on the schedule.
	 *
	 * @hook redirect_post_location
	 *
	 * @param string $location Redirect target after a classic admin save.
	 * @param int    $post_id  Saved post ID.
	 * @return string
	 */
	public function flag_deferred_publish_redirect( $location, $post_id ) {
		if ( ! isset( self::$deferred_publishes[ (int) $post_id ] ) ) {
			return $location;
		}

		unset( self::$deferred_publishes[ (int) $post_id ] );

		return add_query_arg( self::PUBLISH_BUSY_QUERY_ARG, 1, remove_query_arg( 'message', $location ) );
	}

	/**
	 * Show why a trash was refused or a publish was put off, when the fork lease was held.
	 *
	 * @hook admin_notices
	 */
	public function render_busy_notices() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$messages = self::lease_refusal_messages();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notices. A flag picks a fixed message.
		if ( ! empty( $_GET[ self::TRASH_BUSY_QUERY_ARG ] ) ) {
			$count = absint( $_GET[ self::TRASH_BUSY_QUERY_ARG ] );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of future revisions left out of the Trash, 2: why and what to do. */
						_n( '%1$d future revision stayed out of the Trash. %2$s', '%1$d future revisions stayed out of the Trash. %2$s', max( 1, $count ), 'prc-revisions' ),
						max( 1, $count ),
						$messages['fork_trash_busy']
					)
				)
			);
		}

		if ( ! empty( $_GET[ self::PUBLISH_BUSY_QUERY_ARG ] ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'Publishing is busy. Another change to this post\'s future revisions is still running. This future revision publishes again in about a minute. You do not need to publish it again.', 'prc-revisions' )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Give back the lease `gate_fork_pointer_move()` took, once the trash or delete finished.
	 *
	 * @hook trashed_post
	 * @hook deleted_post
	 *
	 * @param int $post_id Post that was trashed or deleted.
	 */
	public function release_fork_pointer_move( $post_id ) {
		$post_id = absint( $post_id );
		foreach ( self::$held_leases as $parent_id => $held ) {
			if ( 'trash' === $held['by'] && $held['fork_id'] === $post_id ) {
				self::release_held_lease( (int) $parent_id );
			}
		}
	}

	/**
	 * Settle a fork in `merging` that core just trashed as discarded.
	 *
	 * A merge holds the fork lease from before its parent write to its own trash, and
	 * trashes the fork only after `merging` to `merged`. So a fork that reaches the Trash
	 * in `merging` was trashed by an editor after a dead merge's lease expired. That is a
	 * discard. Left in `merging`, the fork would be merged by the sweep after a restore, or
	 * after a late publish write over the Trash, which loses its claim only to a closed fork.
	 *
	 * Runs before `release_fork_pointer_move()`, so a trash through the gate still holds the
	 * lease. Any other trash takes it without waiting. A held lease leaves the fork to
	 * `recover_fork()`, which settles a merging fork in the Trash the same way.
	 *
	 * @hook trashed_post
	 *
	 * @param int $post_id Post that was trashed.
	 */
	public function discard_trashed_merging_fork( $post_id ) {
		$post_id   = absint( $post_id );
		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id || Fork_Status::Merging !== self::read_fork_status( $post_id ) ) {
			return;
		}

		$holds_lease = self::holds_lease( $parent_id, $post_id );
		if ( ! $holds_lease && '' === self::take_held_lease( $parent_id, $post_id, 'discard' ) ) {
			return;
		}

		try {
			self::fork_status()->transition( $post_id, Fork_Status::Merging, Fork_Status::Discarded );
		} finally {
			if ( ! $holds_lease ) {
				self::release_held_lease( $parent_id );
			}
		}
	}

	/**
	 * Adopt another pending fork or delete the pointer, when it still names this fork.
	 *
	 * Call it with the parent's fork lease held. The adoption is the one write that
	 * can leave two pending forks pointed at wrongly, so it checks the lease is still
	 * this request's right before it writes. A lease that expired and went to
	 * another process turns the adoption into a delete of the row that names this
	 * fork, and nothing else.
	 *
	 * @param int $parent_id Parent post ID.
	 * @param int $fork_id   Fork post ID that is merged or leaving.
	 */
	private static function move_pointer_off( $parent_id, $fork_id ) {
		if ( self::read_active_fork( $parent_id ) !== $fork_id ) {
			return;
		}

		$token       = self::$held_leases[ $parent_id ]['token'] ?? '';
		$replacement = self::next_pending_fork( $parent_id, $fork_id );
		if ( $replacement && self::fork_lease()->refresh( $parent_id, $token ) ) {
			// The previous value limits the write to a pointer that still names this fork. A rival create's pointer stays.
			update_post_meta( $parent_id, self::ACTIVE_FORK_META, $replacement, $fork_id );
			return;
		}

		// The value limits the delete to this fork id. A pointer that names a newer fork stays.
		delete_post_meta( $parent_id, self::ACTIVE_FORK_META, $fork_id );
	}

	/**
	 * Another live, open fork of this parent, if one exists besides $except_id.
	 *
	 * Called while a fork is being trashed, before its status is trash, so the
	 * query excludes that id instead of trusting post_status.
	 *
	 * @param int $parent_id Parent post ID.
	 * @param int $except_id Fork post ID to skip.
	 * @return int Replacement fork ID, or 0.
	 */
	private static function next_pending_fork( $parent_id, $except_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'draft', 'pending', 'future', 'publish', 'private' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One parent, a handful of forks, and only when a fork is trashed.
					'relation' => 'AND',
					array(
						'key'   => self::FORK_PARENT_META,
						'value' => $parent_id,
					),
					array(
						'key'     => self::FORK_STATUS_META,
						'value'   => array( Fork_Status::Merged->value, Fork_Status::Discarded->value ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id && $id !== $except_id ) {
				return $id;
			}
		}

		return 0;
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
		$args     = wp_parse_args( $args, $defaults );

		$label = trim( $args['label'] );
		if ( '' === $label ) {
			return '';
		}

		$label         = esc_html( $label );
		$schedule_text = ! empty( $args['schedule_text'] ) ? ' ' . esc_html( $args['schedule_text'] ) : '';

		$has_parent_link = ! empty( $args['parent_url'] ) && ! empty( $args['parent_title'] );
		$parent_markup   = '';
		if ( $has_parent_link ) {
			$parent_url    = esc_url( $args['parent_url'] );
			$parent_title  = esc_html( $args['parent_title'] );
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
	 * Badge fork rows and parents in the Posts list table.
	 *
	 * @hook display_post_states
	 *
	 * @param array    $post_states An array of post display states.
	 * @param \WP_Post $post       The post object (full object, not excerpt).
	 * @return array
	 */
	public function add_future_revision_post_state( $post_states, $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return $post_states;
		}

		if ( ! in_array( $post->post_type, Plugin::get_enabled_post_types(), true ) ) {
			return $post_states;
		}

		// Fork row: this post is a future revision of another post.
		$parent_id = (int) get_post_meta( $post->ID, self::FORK_PARENT_META, true );
		if ( $parent_id ) {
			$post_states['prc_future_revision'] = __( 'Future Revision', 'prc-revisions' );

			return $post_states;
		}

		// Parent row: an active fork exists for this post.
		$active_fork_id = (int) get_post_meta( $post->ID, self::ACTIVE_FORK_META, true );
		if ( $active_fork_id ) {
			$fork = get_post( $active_fork_id );
			if ( $fork && 'trash' !== $fork->post_status ) {
				$post_states['prc_has_future_revision'] = __( 'Has Future Revision', 'prc-revisions' );
			}
		}

		return $post_states;
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
				/* translators: %s: scheduled date and time. */
				__( 'Scheduled for %s.', 'prc-revisions' ),
				$scheduled_label
			);
		}

		$banner_html = self::get_future_revision_banner_html(
			array(
				'label'         => __( 'This is a future revision of:', 'prc-revisions' ),
				'parent_url'    => $parent_url,
				'parent_title'  => $parent_title,
				'schedule_text' => $schedule_text,
			)
		);
		echo $banner_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_future_revision_banner_html() escapes every part.
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
	 * Decide whether a trashed fork may leave the trash, and hold the parent's fork lease if it may.
	 *
	 * A merged fork stays in the trash: it would publish a second copy of the
	 * article. A discarded fork stays in the trash while the parent has another
	 * pending fork: a restore would leave two pending forks on one parent.
	 *
	 * The busy-parent check and the pointer write must not interleave with a
	 * create or another restore. A permitted restore keeps the lease from here to
	 * the pointer write in `activate_restored_fork()`. When another process holds
	 * the lease, the restore is refused with `fork_parent_locked`.
	 *
	 * @param \WP_Post $post      Trashed post.
	 * @param int      $parent_id Parent post ID from `_prc_fork_parent`.
	 * @return WP_Error|null Error with HTTP status 409 when the restore is refused. Null when it may go on.
	 */
	private static function gate_restore( $post, $parent_id ) {
		$messages = self::restore_refusal_messages();

		if ( Fork_Status::Merged === self::read_fork_status( (int) $post->ID ) ) {
			return new WP_Error( 'fork_already_merged', $messages['fork_already_merged'], array( 'status' => 409 ) );
		}

		if ( ! self::holds_lease( $parent_id, (int) $post->ID ) && '' === self::take_held_lease( $parent_id, (int) $post->ID, 'restore' ) ) {
			return new WP_Error( 'fork_parent_locked', $messages['fork_parent_locked'], array( 'status' => 409 ) );
		}

		$permitted = false;
		try {
			if ( self::parent_has_pending_fork( $parent_id ) ) {
				return new WP_Error( 'fork_parent_busy', $messages['fork_parent_busy'], array( 'status' => 409 ) );
			}

			$permitted = true;
			return null;
		} finally {
			if ( ! $permitted ) {
				self::release_held_lease( $parent_id );
			}
		}
	}

	/**
	 * Editor-facing text for each refusal code.
	 *
	 * @return array<string, string>
	 */
	private static function restore_refusal_messages() {
		return array(
			'fork_already_merged' => __( 'This future revision is already merged. It stays in the trash.', 'prc-revisions' ),
			'fork_parent_busy'    => __( 'This post already has an open future revision. Discard or publish that one first, then restore this one.', 'prc-revisions' ),
			'fork_parent_locked'  => __( 'Another change to this post\'s future revisions is still running. Wait a moment, then restore again.', 'prc-revisions' ),
		);
	}

	/**
	 * Parent post ID when this post is a trashed fork, otherwise 0.
	 *
	 * @param \WP_Post|null $post Post to check.
	 * @return int
	 */
	private static function trashed_fork_parent( $post ) {
		if ( ! ( $post instanceof \WP_Post ) || 'trash' !== $post->post_status ) {
			return 0;
		}

		return absint( get_post_meta( $post->ID, self::FORK_PARENT_META, true ) );
	}

	/**
	 * Refuse to restore a merged fork, or a discarded fork beside a pending one.
	 *
	 * `pre_untrash_post` treats any non-null return as "do not untrash", so
	 * `wp_untrash_post()` stops before it writes anything. In the classic admin
	 * the editor lands back on the Trash list with the reason in a notice.
	 *
	 * @hook pre_untrash_post
	 *
	 * @param mixed    $untrash Short-circuit value. Null lets untrash proceed.
	 * @param \WP_Post $post    Post being restored.
	 * @return mixed
	 */
	public function refuse_blocked_untrash( $untrash, $post ) {
		if ( null !== $untrash ) {
			return $untrash;
		}

		$parent_id = self::trashed_fork_parent( $post );
		if ( ! $parent_id ) {
			return $untrash;
		}

		$refusal = self::gate_restore( $post, $parent_id );
		if ( ! $refusal ) {
			return $untrash;
		}

		if ( $this->is_classic_bulk_restore() ) {
			$this->note_bulk_restore_refusal( $refusal );
			return $post;
		}

		$this->redirect_refused_restore( $post, $refusal );
		return false;
	}

	/**
	 * Whether this request is the classic Posts list bulk Restore.
	 *
	 * Core stops a bulk Restore with `wp_die()` when `wp_untrash_post()` is falsy.
	 * Refusing one item that way would strand the items after it.
	 *
	 * @return bool
	 */
	private function is_classic_bulk_restore() {
		global $pagenow;

		if ( ! is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) || 'edit.php' !== $pagenow ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core checked the bulk nonce before it ran the action. This only reads which action it is.
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'untrash' === $action || 'untrash' === $action2;
	}

	/**
	 * Record one refused item of a bulk Restore and report it with the redirect.
	 *
	 * The item is not written. The filter returns the post so core counts it as
	 * handled and goes on with the next item. `report_bulk_restore_refusals()`
	 * takes the refused items out of core's restored count.
	 *
	 * @param WP_Error $refusal Refusal from `gate_restore()`.
	 */
	private function note_bulk_restore_refusal( $refusal ) {
		$this->bulk_restore_refusals[] = $refusal->get_error_code();
		if ( ! has_filter( 'wp_redirect', array( $this, 'report_bulk_restore_refusals' ) ) ) {
			add_filter( 'wp_redirect', array( $this, 'report_bulk_restore_refusals' ) );
		}
	}

	/**
	 * Say how many items a bulk Restore restored and how many it refused.
	 *
	 * @hook wp_redirect
	 *
	 * @param string $location Redirect target built by core after the bulk loop.
	 * @return string
	 */
	public function report_bulk_restore_refusals( $location ) {
		if ( ! $this->bulk_restore_refusals ) {
			return $location;
		}

		$refused = count( $this->bulk_restore_refusals );
		$codes   = implode( ',', array_unique( $this->bulk_restore_refusals ) );
		$query   = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$handled = isset( $query['untrashed'] ) ? absint( $query['untrashed'] ) : 0;

		$this->bulk_restore_refusals = array();
		remove_filter( 'wp_redirect', array( $this, 'report_bulk_restore_refusals' ) );

		return add_query_arg(
			array(
				self::RESTORE_REFUSED_QUERY_ARG  => $codes,
				self::RESTORE_REFUSED_COUNT_ARG  => $refused,
				self::RESTORE_RESTORED_COUNT_ARG => max( 0, $handled - $refused ),
			),
			remove_query_arg( array( 'untrashed', 'ids' ), $location )
		);
	}

	/**
	 * Send a refused classic admin Restore back to the list with a notice.
	 *
	 * Core answers a refused `wp_untrash_post()` with a bare `wp_die()`. Only the
	 * Restore link on post.php gets this redirect. The bulk action on edit.php
	 * goes on with the next item (see `note_bulk_restore_refusal()`). REST,
	 * WP-CLI, and PHP callers keep their own error channel.
	 *
	 * @param \WP_Post $post    Post whose restore was refused.
	 * @param WP_Error $refusal Refusal from `gate_restore()`.
	 */
	private function redirect_refused_restore( $post, $refusal ) {
		global $pagenow;

		$is_classic_screen = is_admin()
			&& ! wp_doing_ajax()
			&& ! defined( 'REST_REQUEST' )
			&& ! defined( 'WP_CLI' )
			&& 'post.php' === $pagenow;
		if ( ! $is_classic_screen ) {
			return;
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = add_query_arg(
				array(
					'post_status' => 'trash',
					'post_type'   => $post->post_type,
				),
				admin_url( 'edit.php' )
			);
		}

		wp_safe_redirect( add_query_arg( self::RESTORE_REFUSED_QUERY_ARG, $refusal->get_error_code(), $back ) );
		exit;
	}

	/**
	 * Keep `classic=1` in the bulk form of the classic Trash list.
	 *
	 * The bulk form is a GET form. Its submit drops `classic=1`, so the DataViews
	 * redirect on `load-edit.php` sends the editor away before the Restore runs.
	 *
	 * @hook manage_posts_extra_tablenav
	 * @hook manage_pages_extra_tablenav
	 *
	 * @param string $which Which table nav is drawn: `top` or `bottom`.
	 */
	public function render_classic_list_flag( $which ) {
		if ( 'top' !== $which || ! is_admin() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only. The values pick whether a fixed hidden field is printed.
		$is_classic = isset( $_GET['classic'] ) && '1' === (string) $_GET['classic'];
		$status     = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$screen = get_current_screen();
		if ( ! $is_classic || 'trash' !== $status || ! $screen || ! in_array( $screen->post_type, Plugin::get_enabled_post_types(), true ) ) {
			return;
		}

		echo '<input type="hidden" name="classic" value="1" />';
	}

	/**
	 * Show why a Restore was refused.
	 *
	 * @hook admin_notices
	 */
	public function render_restore_refused_notice() {
		if ( empty( $_GET[ self::RESTORE_REFUSED_QUERY_ARG ] ) || ! is_string( $_GET[ self::RESTORE_REFUSED_QUERY_ARG ] ) || ! current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice. The value only picks fixed messages.
			return;
		}

		$messages = self::restore_refusal_messages();
		$reasons  = array();
		foreach ( explode( ',', wp_unslash( $_GET[ self::RESTORE_REFUSED_QUERY_ARG ] ) ) as $code ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each code is sanitized and looked up in a fixed list.
			$code = sanitize_key( $code );
			if ( isset( $messages[ $code ] ) ) {
				$reasons[ $code ] = $messages[ $code ];
			}
		}
		if ( ! $reasons ) {
			return;
		}

		$text     = implode( ' ', $reasons );
		$type     = 'error';
		$refused  = isset( $_GET[ self::RESTORE_REFUSED_COUNT_ARG ] ) ? absint( $_GET[ self::RESTORE_REFUSED_COUNT_ARG ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$restored = isset( $_GET[ self::RESTORE_RESTORED_COUNT_ARG ] ) ? absint( $_GET[ self::RESTORE_RESTORED_COUNT_ARG ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $refused ) {
			$type = $restored ? 'warning' : 'error';
			$text = sprintf(
				/* translators: 1: number of items restored, 2: number of items refused, 3: why they were refused. */
				__( '%1$d restored, %2$d refused. %3$s', 'prc-revisions' ),
				$restored,
				$refused,
				$text
			);
		}

		wp_admin_notice(
			$text,
			array(
				'type'        => $type,
				'dismissible' => true,
			)
		);
	}

	/**
	 * Let WordPress drop the refusal arg from the address bar.
	 *
	 * @hook removable_query_args
	 *
	 * @param string[] $args Query args WordPress removes from admin URLs.
	 * @return string[]
	 */
	public function remove_restore_refused_query_arg( $args ) {
		$args[] = self::RESTORE_REFUSED_QUERY_ARG;
		$args[] = self::RESTORE_REFUSED_COUNT_ARG;
		$args[] = self::RESTORE_RESTORED_COUNT_ARG;
		$args[] = self::TRASH_BUSY_QUERY_ARG;
		$args[] = self::PUBLISH_BUSY_QUERY_ARG;
		return $args;
	}

	/**
	 * Watch REST updates for a Restore of a trashed fork.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_restore_guard() {
		foreach ( Plugin::get_enabled_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'guard_rest_restore' ) );
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'guard_rest_fork_publish' ) );
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'drop_rest_active_fork' ), 10, 2 );
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'drop_rest_fork_status' ), 10, 2 );
		}
		add_filter( 'rest_request_after_callbacks', array( $this, 'explain_refused_rest_trash' ), 10, 3 );
	}

	/**
	 * Answer a core REST delete of a busy fork with 409 and the busy message.
	 *
	 * `wp_trash_post()` and `wp_delete_post()` return false when the trash gate
	 * refuses, and core turns that into a generic 500 `rest_cannot_delete`. Only the
	 * fork the gate refused in this request is rewritten.
	 *
	 * @hook rest_request_after_callbacks
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response|WP_Error|mixed $response Result of the route callback.
	 * @param array                                              $handler  Route handler.
	 * @param \WP_REST_Request                                   $request  The request.
	 * @return mixed
	 */
	public function explain_refused_rest_trash( $response, $handler, $request ) {
		if ( ! $response instanceof WP_Error || ! self::$refused_leaves || 'DELETE' !== $request->get_method() ) {
			return $response;
		}

		if ( ! preg_match( '#^/wp/v2/[^/]+/(\d+)$#', $request->get_route(), $matches ) || ! isset( self::$refused_leaves[ (int) $matches[1] ] ) ) {
			return $response;
		}

		unset( self::$refused_leaves[ (int) $matches[1] ] );

		return new WP_Error(
			'fork_trash_busy',
			self::lease_refusal_messages()['fork_trash_busy'],
			array( 'status' => 409 )
		);
	}

	/**
	 * Refuse a REST publish of a fork while another process holds the parent's lease.
	 *
	 * The merge runs after the publish write and takes the lease itself. A merge
	 * that met a held lease cannot answer the request any more: the fork is
	 * already published. This check answers 409 before any write, so the editor
	 * sees why and publishes again. It is a courtesy. The merge's own lease wait
	 * is the guard.
	 *
	 * @hook rest_pre_insert_{post_type}
	 *
	 * @param \stdClass|WP_Error $prepared_post Post data the REST controller will write.
	 * @return \stdClass|WP_Error
	 */
	public function guard_rest_fork_publish( $prepared_post ) {
		if ( is_wp_error( $prepared_post ) || empty( $prepared_post->ID ) || 'publish' !== ( $prepared_post->post_status ?? '' ) ) {
			return $prepared_post;
		}

		$existing = get_post( $prepared_post->ID );
		if ( ! ( $existing instanceof \WP_Post ) || 'publish' === $existing->post_status || 'trash' === $existing->post_status ) {
			return $prepared_post;
		}

		$parent_id = absint( get_post_meta( $existing->ID, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id || Fork_Status::Merged === self::read_fork_status( (int) $existing->ID ) ) {
			return $prepared_post;
		}

		if ( self::holds_lease( $parent_id, (int) $existing->ID ) || ! self::fork_lease()->is_held( $parent_id ) ) {
			return $prepared_post;
		}

		return self::merge_busy_error();
	}

	/**
	 * Remove `_prc_active_fork` from a REST write, so the pointer stays read only without failing the save.
	 *
	 * The key has `auth_callback` `__return_false`, so a REST write that changes it
	 * answers `rest_cannot_update` and the whole update fails. A client that sends
	 * the meta object back with a pointer it read earlier would lose its save
	 * whenever a create, merge, or discard moved the pointer in between. The write
	 * is dropped here, the rest of the request goes on, and the response shows the
	 * stored pointer.
	 *
	 * @hook rest_pre_insert_{post_type}
	 *
	 * @param \stdClass|WP_Error $prepared_post Post data the REST controller will write.
	 * @param \WP_REST_Request   $request       The request.
	 * @return \stdClass|WP_Error
	 */
	public function drop_rest_active_fork( $prepared_post, $request ) {
		$meta = $request['meta'];
		if ( is_array( $meta ) && array_key_exists( self::ACTIVE_FORK_META, $meta ) ) {
			unset( $meta[ self::ACTIVE_FORK_META ] );
			$request['meta'] = $meta;
		}

		return $prepared_post;
	}

	/**
	 * Remove `_prc_fork_status` from a REST write, so the key stays read only.
	 *
	 * `Meta_State::guard()` refuses every outside write of the key. Without this
	 * filter, a REST `meta` write of it reaches `update_post_meta()` and the
	 * client gets a 500 `rest_meta_database_error`. The write is dropped here, the
	 * rest of the request goes on, and the response shows the stored status.
	 *
	 * @hook rest_pre_insert_{post_type}
	 *
	 * @param \stdClass|WP_Error $prepared_post Post data the REST controller will write.
	 * @param \WP_REST_Request   $request       The request.
	 * @return \stdClass|WP_Error
	 */
	public function drop_rest_fork_status( $prepared_post, $request ) {
		$meta = $request['meta'];
		if ( is_array( $meta ) && array_key_exists( self::FORK_STATUS_META, $meta ) ) {
			unset( $meta[ self::FORK_STATUS_META ] );
			$request['meta'] = $meta;
		}

		return $prepared_post;
	}

	/**
	 * Refuse a REST Restore of a blocked fork before any write, and land the rest as drafts.
	 *
	 * The DataViews Trash screen and the posts list restore a post with
	 * `POST /wp/v2/{type}/{id}` and a status. A refusal here is an HTTP 409
	 * with a message, so the editor sees why. Any other status becomes `draft`.
	 *
	 * @hook rest_pre_insert_{post_type}
	 *
	 * @param \stdClass|WP_Error $prepared_post Post data the REST controller will write.
	 * @return \stdClass|WP_Error
	 */
	public function guard_rest_restore( $prepared_post ) {
		if ( is_wp_error( $prepared_post ) || empty( $prepared_post->ID ) || empty( $prepared_post->post_status ) ) {
			return $prepared_post;
		}

		$existing  = get_post( $prepared_post->ID );
		$parent_id = self::trashed_fork_parent( $existing );
		if ( ! $parent_id || 'trash' === $prepared_post->post_status ) {
			return $prepared_post;
		}

		$refusal = self::gate_restore( $existing, $parent_id );
		if ( $refusal ) {
			return $refusal;
		}

		$prepared_post->post_status = 'draft';
		return $prepared_post;
	}

	/**
	 * Backstop for PHP callers that write a new status to a trashed fork.
	 *
	 * `wp_update_post()` and `wp post update` skip `pre_untrash_post`. A merged
	 * fork, or a discarded fork beside a pending one, goes back to `trash`, and
	 * this write keeps the trashed slug, `_wp_desired_post_slug`, and
	 * `post_modified`. Revisions and the publish pipeline skip it (see
	 * `skip_refused_restore_revision()` and `skip_refused_restore_pipeline()`).
	 * Any other restore lands as a draft, whatever status the caller asked for.
	 * REST and `wp_untrash_post()` refuse before they get here. `wp post update`
	 * stops with the reason instead of printing Success.
	 *
	 * @hook wp_insert_post_data
	 *
	 * @param array $data    Sanitized post data about to be written.
	 * @param array $postarr Raw post data, including the ID on an update.
	 * @return array
	 */
	public function guard_restore_write( $data, $postarr ) {
		$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		if ( ! $post_id || 'trash' === ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$existing  = get_post( $post_id );
		$parent_id = self::trashed_fork_parent( $existing );
		if ( ! $parent_id ) {
			return $data;
		}

		$refusal = self::gate_restore( $existing, $parent_id );
		if ( ! $refusal ) {
			$data['post_status'] = 'draft';
			return $data;
		}

		$data['post_status']       = 'trash';
		$data['post_name']         = $existing->post_name;
		$data['post_modified']     = $existing->post_modified;
		$data['post_modified_gmt'] = $existing->post_modified_gmt;
		// Core deleted this meta before the filter ran, as if the restore went through.
		if ( str_ends_with( $existing->post_name, '__trashed' ) ) {
			add_post_meta( $post_id, '_wp_desired_post_slug', substr( $existing->post_name, 0, -strlen( '__trashed' ) ), true );
		}

		$this->refused_writes[ $post_id ] = true;

		if ( self::is_wp_cli_post_update() ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: post ID, 2: why the restore was refused. */
					__( 'Post %1$d stays in the trash. %2$s', 'prc-revisions' ),
					$post_id,
					$refusal->get_error_message()
				)
			);
		}

		return $data;
	}

	/**
	 * Whether this process is `wp post update`.
	 *
	 * That command reports Success for any write that does not return a WP_Error,
	 * and a filter cannot return one. A refused restore stops it with a message.
	 *
	 * @return bool
	 */
	private static function is_wp_cli_post_update() {
		if ( ! class_exists( '\WP_CLI', false ) ) {
			return false;
		}

		return array( 'post', 'update' ) === array_slice( \WP_CLI::get_runner()->arguments, 0, 2 );
	}

	/**
	 * Save no revision for a refused restore write.
	 *
	 * @hook wp_revisions_to_keep
	 *
	 * @param int      $num  Revisions to keep.
	 * @param \WP_Post $post Post being saved.
	 * @return int
	 */
	public function skip_refused_restore_revision( $num, $post ) {
		return ( $post instanceof \WP_Post && isset( $this->refused_writes[ $post->ID ] ) ) ? 0 : $num;
	}

	/**
	 * Fire no `prc_platform_on_*` event for a refused restore write.
	 *
	 * The fork is still in the trash. A second trash event would repeat the
	 * IndexNow removal and the chart usage clear.
	 *
	 * @hook prc_platform_post_publish_pipeline_should_process
	 *
	 * @param bool $should_process Whether the pipeline processes this write.
	 * @param int  $post_id        Post ID.
	 * @return bool
	 */
	public function skip_refused_restore_pipeline( $should_process, $post_id ) {
		return isset( $this->refused_writes[ absint( $post_id ) ] ) ? false : $should_process;
	}

	/**
	 * Forget a refused restore write once the save finished, and give back the restore lease.
	 *
	 * @hook wp_after_insert_post
	 *
	 * @param int $post_id Post ID.
	 */
	public function forget_refused_restore_write( $post_id ) {
		$post_id = absint( $post_id );
		unset( $this->refused_writes[ $post_id ] );

		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( $parent_id && self::holds_lease( $parent_id, $post_id ) && 'restore' === self::$held_leases[ $parent_id ]['by'] ) {
			self::release_held_lease( $parent_id );
		}
	}

	/**
	 * Point the parent at a discarded fork that left the trash through a status write.
	 *
	 * Every restore path writes a status, so this hook sees them all. Moving
	 * into the trash forgets an earlier restore of the same fork.
	 *
	 * @hook transition_post_status
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post whose status changed.
	 */
	public function maybe_activate_restored_fork_on_status( $new_status, $old_status, $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		if ( 'trash' === $new_status ) {
			unset( $this->restore_handled[ $post->ID ] );
			return;
		}

		if ( 'trash' !== $old_status ) {
			return;
		}

		$this->restore_handled[ $post->ID ] = true;
		$this->activate_restored_fork( $post->ID );
	}

	/**
	 * Point the parent at a restored discarded fork when the status hook has not.
	 *
	 * `wp_untrash_post()` fires this after the status write that already ran the rule.
	 *
	 * @hook untrashed_post
	 *
	 * @param int $post_id Restored post ID.
	 */
	public function maybe_activate_restored_fork( $post_id ) {
		$post_id = absint( $post_id );
		if ( isset( $this->restore_handled[ $post_id ] ) ) {
			unset( $this->restore_handled[ $post_id ] );
			return;
		}

		$this->activate_restored_fork( $post_id );
	}

	/**
	 * Make a restored discarded fork the parent's pointer when the parent has no pending fork.
	 *
	 * A restored fork also moves from `discarded` back to `draft`, so it can be
	 * published or discarded again. A fork that an editor trashed through core
	 * while a dead merge left it in `merging` moves from `merging` to `draft`.
	 * Only a new publish merges it then. `Fork_Recovery` reads only `merging`.
	 *
	 * The guard that let this restore through holds the fork lease. The check and
	 * the write run under it, and the lease is checked again right before the
	 * write. A status write that no guard saw takes the lease here. When another
	 * process holds it, or the lease was lost, nothing is written and
	 * `report_unlinked_restore()` says so.
	 *
	 * @param int $post_id Restored post ID.
	 */
	private function activate_restored_fork( $post_id ) {
		$post_id   = absint( $post_id );
		$parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
		if ( ! $parent_id ) {
			return;
		}

		if ( Fork_Status::Merged === self::read_fork_status( $post_id ) ) {
			return;
		}

		if ( ! self::holds_lease( $parent_id, $post_id ) && '' === self::take_held_lease( $parent_id, $post_id, 'restore' ) ) {
			self::report_unlinked_restore( $post_id, $parent_id, 'lease_busy' );
			return;
		}

		try {
			self::ensure_status( $post_id );
			// A discarded fork is a draft again. A fork the editor trashed through core stays `draft`, so this does not match.
			self::fork_status()->transition( $post_id, Fork_Status::Discarded, Fork_Status::Draft );
			// A core trash of a stuck merge was a discard. Left in `merging`, the restored draft would be merged by the next sweep.
			self::fork_status()->transition( $post_id, Fork_Status::Merging, Fork_Status::Draft );

			if ( self::parent_has_pending_fork( $parent_id ) ) {
				return;
			}

			if ( ! self::fork_lease()->refresh( $parent_id, self::$held_leases[ $parent_id ]['token'] ) ) {
				self::report_unlinked_restore( $post_id, $parent_id, 'lease_lost' );
				return;
			}

			update_post_meta( $parent_id, self::ACTIVE_FORK_META, $post_id );
		} finally {
			self::release_held_lease( $parent_id );
		}
	}

	/**
	 * Report a restored fork that did not become the parent's pointer.
	 *
	 * The status write already moved the fork out of the trash. Without the
	 * pointer the editor panel does not show it. The gates refuse a restore when
	 * the lease is busy, so this runs only for a status write that no gate saw, or
	 * when the lease expired mid-restore.
	 *
	 * @param int    $post_id   Restored fork ID.
	 * @param int    $parent_id Parent post ID.
	 * @param string $reason    `lease_busy` or `lease_lost`.
	 */
	private static function report_unlinked_restore( $post_id, $parent_id, $reason ) {
		/**
		 * Fires when a restored fork could not become the parent's active fork.
		 *
		 * @param int    $post_id   Restored fork ID.
		 * @param int    $parent_id Parent post ID.
		 * @param string $reason    `lease_busy` or `lease_lost`.
		 */
		do_action( 'prc_revisions_restore_pointer_skipped', $post_id, $parent_id, $reason );

		error_log( sprintf( 'prc-revisions: fork %1$d was restored but not linked to parent %2$d (%3$s).', $post_id, $parent_id, $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A restore runs inside save hooks, so the failure is logged instead of thrown.
	}

	/**
	 * Whether `_prc_active_fork` names a live, open fork of this parent.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return bool
	 */
	private static function parent_has_pending_fork( $parent_id ) {
		$parent_id = absint( $parent_id );
		$active_id = self::read_active_fork( $parent_id );
		if ( ! $active_id ) {
			return false;
		}

		$active = get_post( $active_id );
		if ( ! $active || 'trash' === $active->post_status ) {
			return false;
		}

		if ( absint( get_post_meta( $active_id, self::FORK_PARENT_META, true ) ) !== $parent_id ) {
			return false;
		}

		return ! self::is_closed( $active_id );
	}

	/**
	 * Reject discard of a merged fork and clear a stale active-fork pointer.
	 *
	 * `merge_fork` clears `_prc_active_fork` before trashing, so a normal merge
	 * does not leave this pointer. A discard that meets a merged fork can still
	 * find the pointer on it: `merge_fork` marks the fork merged one step before
	 * it moves the pointer. Clear that pointer without trashing, so `create_fork`
	 * is not blocked.
	 *
	 * @param int $fork_id   Fork post ID.
	 * @param int $parent_id Parent post ID.
	 * @return WP_Error Always `fork_already_merged`.
	 */
	private static function reject_merged_fork( $fork_id, $parent_id ) {
		$fork_id   = absint( $fork_id );
		$parent_id = absint( $parent_id );

		$holds_lease = self::holds_lease( $parent_id, $fork_id );
		if ( $holds_lease || '' !== self::take_held_lease_waiting( $parent_id, $fork_id, 'reject' ) ) {
			try {
				if ( self::read_active_fork( $parent_id ) === $fork_id ) {
					// The value limits the delete to this fork id. A pointer that names a newer fork stays.
					delete_post_meta( $parent_id, self::ACTIVE_FORK_META, $fork_id );
				}
			} finally {
				if ( ! $holds_lease ) {
					self::release_held_lease( $parent_id );
				}
			}
		}

		return new WP_Error(
			'fork_already_merged',
			__( 'This future revision has already been merged.', 'prc-revisions' )
		);
	}

	/**
	 * Say why a discard did not win the `draft` to `discarded` transition.
	 *
	 * The status is read only to pick the message. The transition already decided.
	 *
	 * @param int $fork_id   Fork post ID.
	 * @param int $parent_id Parent post ID.
	 * @return WP_Error
	 */
	private static function refuse_discard( $fork_id, $parent_id ) {
		switch ( self::read_fork_status( $fork_id ) ) {
			case Fork_Status::Merged:
				return self::reject_merged_fork( $fork_id, $parent_id );
			case Fork_Status::Merging:
				return new WP_Error(
					'fork_merging',
					__( 'This future revision is being published. It cannot be discarded now.', 'prc-revisions' )
				);
			case Fork_Status::Discarded:
				return new WP_Error(
					'fork_already_trashed',
					__( 'This future revision is already in the trash.', 'prc-revisions' )
				);
			default:
				return new WP_Error(
					'trash_failed',
					__( 'Could not trash the future revision.', 'prc-revisions' )
				);
		}
	}

	/**
	 * Trash a pending future revision (fork).
	 *
	 * Accepts either the parent post ID (with an active fork) or the fork post ID.
	 * Does not merge content into the parent. Existing trash/delete hooks clear
	 * the parent's `_prc_active_fork` meta.
	 *
	 * A discard of the fork the parent points at takes the parent's fork lease before
	 * its first write and keeps it to the end of the trash. A create or a restore
	 * that starts in between cannot
	 * see a discarded fork that is about to go back to `draft`, so the parent never
	 * ends with two pending forks. A holder that outlasts the wait makes the
	 * discard stop with nothing written (409 `fork_trash_busy`). The fork is
	 * trashed only after `draft` to `discarded` returns true. A publish that
	 * already holds `merging`, or a merge that finished, wins instead and the
	 * discard returns an error. When the trash call fails, the fork goes back to
	 * `draft`.
	 *
	 * @param int $post_id Parent or fork post ID.
	 * @return array|WP_Error {
	 *     @type int $fork_id   Trashed fork ID.
	 *     @type int $parent_id Parent post ID.
	 * }
	 */
	public static function trash_fork( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post does not exist.', 'prc-revisions' )
			);
		}

		$fork_id   = 0;
		$parent_id = 0;

		$active_fork_id = self::read_active_fork( $post_id );
		if ( $active_fork_id ) {
			$fork = get_post( $active_fork_id );
			if ( $fork && 'trash' !== $fork->post_status ) {
				// Only trust `_prc_active_fork` when it points at a fork of this parent.
				$fork_parent_of_active = absint( get_post_meta( $active_fork_id, self::FORK_PARENT_META, true ) );
				if ( $fork_parent_of_active === $post_id ) {
					$fork_id   = $active_fork_id;
					$parent_id = $post_id;
				}
			}
		}

		if ( ! $fork_id ) {
			$fork_parent_id = absint( get_post_meta( $post_id, self::FORK_PARENT_META, true ) );
			if ( $fork_parent_id ) {
				if ( 'trash' === $post->post_status ) {
					return new WP_Error(
						'fork_already_trashed',
						__( 'This future revision is already in the trash.', 'prc-revisions' )
					);
				}
				$fork_id   = $post_id;
				$parent_id = $fork_parent_id;
			}
		}

		if ( ! $fork_id || ! $parent_id ) {
			return new WP_Error(
				'no_active_fork',
				__( 'No pending future revision found for this post.', 'prc-revisions' )
			);
		}

		if ( ! current_user_can( 'delete_post', $fork_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this future revision.', 'prc-revisions' )
			);
		}

		self::ensure_status( $fork_id );

		$takes_lease = ! self::holds_lease( $parent_id, $fork_id )
			&& Fork_Status::Draft === self::read_fork_status( $fork_id )
			&& self::read_active_fork( $parent_id ) === $fork_id;
		if ( $takes_lease && '' === self::take_held_lease_waiting( $parent_id, $fork_id, 'trash' ) ) {
			return new WP_Error(
				'fork_trash_busy',
				self::lease_refusal_messages()['fork_trash_busy'],
				array( 'status' => 409 )
			);
		}

		try {
			if ( ! self::fork_status()->transition( (int) $fork_id, Fork_Status::Draft, Fork_Status::Discarded ) ) {
				return self::refuse_discard( $fork_id, $parent_id );
			}

			if ( ! wp_trash_post( $fork_id ) ) {
				self::fork_status()->transition( (int) $fork_id, Fork_Status::Discarded, Fork_Status::Draft );
				return new WP_Error(
					'trash_failed',
					__( 'Could not trash the future revision.', 'prc-revisions' )
				);
			}
		} finally {
			if ( $takes_lease ) {
				self::release_held_lease( $parent_id );
			}
		}

		return array(
			'fork_id'   => $fork_id,
			'parent_id' => $parent_id,
		);
	}

	/**
	 * Get the fork info for a given post.
	 *
	 * @param int $post_id The post ID (parent or fork).
	 * @return array Fork information.
	 */
	public static function get_fork_info( $post_id ) {
		$post_id        = absint( $post_id );
		$active_fork_id = absint( get_post_meta( $post_id, self::ACTIVE_FORK_META, true ) );
		$fork_parent_id = get_post_meta( $post_id, self::FORK_PARENT_META, true );

		if ( $active_fork_id ) {
			$fork = get_post( $active_fork_id );
			// Match trash_fork: only report parent role for a pending (open,
			// non-trashed) fork of this post, so the panel never offers Discard
			// for a fork that trash_fork will reject.
			if (
				$fork
				&& 'trash' !== $fork->post_status
				&& absint( get_post_meta( $active_fork_id, self::FORK_PARENT_META, true ) ) === $post_id
				&& ! self::is_closed( $active_fork_id )
			) {
				return array(
					'role'          => 'parent',
					'fork_id'       => (int) $active_fork_id,
					'fork_status'   => get_post_meta( $active_fork_id, self::FORK_STATUS_META, true ),
					'fork_edit_url' => get_edit_post_link( $active_fork_id, 'raw' ),
					'merge_stuck'   => Fork_Recovery::is_stuck( $active_fork_id ),
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
				'merge_stuck'     => Fork_Recovery::is_stuck( $post_id ),
			);
		}

		return array(
			'role' => 'none',
		);
	}
}
