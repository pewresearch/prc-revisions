<?php
/**
 * Recovery of future revisions that a dead request left in `merging`.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

use PRC\Primitives\Concurrency\Meta_State;
use WP_Error;

/**
 * A request that dies between `draft` to `merging` and `merging` to `merged` leaves the fork in
 * `merging`. A recurring Action Scheduler sweep finishes such a fork.
 *
 * The sweep acts on a fork only when all three hold:
 *
 * 1. The fork entered `merging` more than `STUCK_AFTER_SECONDS` ago. The time is the stamp
 *    this class writes when the `draft` to `merging` transition wins. A merge takes seconds
 *    (`FORK_LEASE_WORST_CASE_SECONDS`), so an older `merging` fork belongs to a dead request.
 * 2. The parent's fork lease is free. A live merge or trash holds it.
 * 3. The fork is still `merging` after the lease is taken.
 *
 * It then runs the merge again under the lease. The merge writes the parent only when the
 * parent does not already hold the fork content, so a second run adds no second write.
 * A run that fails leaves the fork in `merging`, logs, and counts the attempt. At
 * `MAX_ATTEMPTS` the editor shows a notice on the fork and on its parent. The sweep logs the
 * fork once more at that cap and then stays quiet, and it keeps trying.
 *
 * A request can also die after `merging` to `merged` and before the trash. The fork is then merged
 * and still published. The sweep also takes a fork whose status is `merged` and whose post is
 * not in the Trash, under the same lease, and trashes it. It does not merge it again.
 *
 * @package PRC\Platform\Revisions
 */
class Fork_Recovery {

	/**
	 * Post meta key that holds the Unix time the fork entered `merging`.
	 *
	 * @var string
	 */
	const SINCE_META = '_prc_fork_merging_since';

	/**
	 * Post meta key that counts failed recovery runs of a fork.
	 *
	 * @var string
	 */
	const ATTEMPTS_META = '_prc_fork_recovery_attempts';

	/**
	 * Seconds a fork must stay in `merging` before the sweep may act on it.
	 *
	 * It equals the lease TTL: a merge that holds a live lease is younger than this.
	 *
	 * @var int
	 */
	const STUCK_AFTER_SECONDS = Future_Revisions::FORK_LEASE_TTL;

	/**
	 * Failed recovery runs after which the editor shows a notice.
	 *
	 * The sweep keeps trying after the cap. A later run that works clears the notice.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Seconds back from now in which the scheduled sweep looks for a merged fork that is still published.
	 *
	 * The sweep reads the entry stamp (`SINCE_META`) of forks that went through `merging` in this
	 * window. A merged fork stays in the Trash for 30 days, and its status row stays with it. A read
	 * of every merged row would grow with the Trash on every sweep. The window bounds the read by
	 * the merges of one day. `wp prc revisions recover-stuck` reads without a window.
	 *
	 * @var int
	 */
	const ORPHAN_WINDOW = DAY_IN_SECONDS;

	/**
	 * Forks one sweep run handles.
	 *
	 * @var int
	 */
	const BATCH = 20;

	/**
	 * Seconds between sweep runs.
	 *
	 * @var int
	 */
	const INTERVAL = 60;

	/**
	 * Action Scheduler hook the recurring sweep fires.
	 *
	 * @var string
	 */
	const HOOK = 'prc_revisions_recover_stuck_forks';

	/**
	 * Action Scheduler group of the sweep.
	 *
	 * @var string
	 */
	const ACTION_GROUP = 'prc-revisions';

	/**
	 * Site option that holds the schedule version the site was last repaired for.
	 *
	 * @var string
	 */
	const SCHEDULE_VERSION_OPTION = 'prc_revisions_recovery_schedule_version';

	/**
	 * Current schedule version. Raise it to run the one-time repair again.
	 *
	 * Version 1 cancels duplicate sweep actions.
	 *
	 * @var int
	 */
	const SCHEDULE_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'init', $this, 'maybe_schedule', 20 );
		$loader->add_action( self::HOOK, $this, 'run' );
		$loader->add_action( Meta_State::TRANSITIONED_ACTION, $this, 'track_transition', 10, 4 );
	}

	/**
	 * Stamp the moment a fork enters `merging`, and reset its failure count.
	 *
	 * `Meta_State` fires the action only for the request whose transition won, right after
	 * the write. A fork that leaves `merging` keeps its stamp. The next claim writes a new one.
	 *
	 * @hook prc_meta_state_transitioned
	 *
	 * @param int              $post_id  Fork post ID.
	 * @param string           $meta_key State meta key.
	 * @param \BackedEnum|null $from     Old state.
	 * @param \BackedEnum      $to       New state.
	 */
	public function track_transition( $post_id, $meta_key, $from, $to ) {
		if ( Future_Revisions::FORK_STATUS_META !== $meta_key || Fork_Status::Merging !== $to ) {
			return;
		}

		update_post_meta( (int) $post_id, self::SINCE_META, time() );
		if ( metadata_exists( 'post', (int) $post_id, self::ATTEMPTS_META ) ) {
			delete_post_meta( (int) $post_id, self::ATTEMPTS_META );
		}
	}

	/**
	 * Keep one recurring sweep scheduled.
	 *
	 * Runs in the admin and in cron only, so a public request never queries Action Scheduler.
	 * `$unique` makes Action Scheduler refuse a second action when two requests see none.
	 * Every lookup uses the hook alone. The hook name belongs to this class, and a lookup by
	 * group fails where the group table and the action table disagree.
	 *
	 * @hook init
	 */
	public function maybe_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( (int) get_option( self::SCHEDULE_VERSION_OPTION, 0 ) < self::SCHEDULE_VERSION ) {
			$this->repair_duplicates();
			update_option( self::SCHEDULE_VERSION_OPTION, self::SCHEDULE_VERSION, true );
		}

		if ( as_has_scheduled_action( self::HOOK ) ) {
			return;
		}

		as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), self::ACTION_GROUP, true );
	}

	/**
	 * Cancel the pending sweep actions when a site holds more than one live action.
	 *
	 * A running action counts, because it stores its next instance when it ends.
	 */
	private function repair_duplicates() {
		$live = count( $this->action_ids( 'pending', 2 ) );

		if ( 1 === $live ) {
			$live += count( $this->action_ids( 'in-progress', 1 ) );
		}

		if ( $live > 1 ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}

	/**
	 * IDs of the sweep actions with one status.
	 *
	 * @param string $status   Action Scheduler status: pending or in-progress.
	 * @param int    $per_page Most IDs to read.
	 * @return int[]
	 */
	private function action_ids( $status, $per_page ) {
		return as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'status'   => $status,
				'per_page' => $per_page,
			),
			'ids'
		);
	}

	/**
	 * Run one sweep batch.
	 *
	 * @hook prc_revisions_recover_stuck_forks
	 */
	public function run() {
		self::sweep( false );
	}

	/**
	 * Look at the forks in `merging`, and finish the ones whose merge died.
	 *
	 * One keyset query on `wp_postmeta` finds the forks in `merging`. A site with none
	 * pays that one query. The read is pinned to the primary database.
	 *
	 * @param bool $dry_run       List what the sweep would do and write nothing.
	 * @param int  $limit         Forks to look at.
	 * @param int  $after_id      Look at forks with an ID above this one.
	 * @param int  $orphan_window Seconds back in which to look for a merged fork that is still published. 0 reads every merged fork.
	 * @return array{scanned:int,last_id:int,young:int[],stamped:int[],unstamped:int[],would_recover:int[],would_trash:int[],busy:int[],gone:int[],recovered:int[],trashed:int[],failed:array<int,string>,capped:int[]}
	 */
	public static function sweep( $dry_run = false, $limit = self::BATCH, $after_id = 0, $orphan_window = self::ORPHAN_WINDOW ) {
		$report = array(
			'scanned'       => 0,
			'last_id'       => (int) $after_id,
			'young'         => array(),
			'stamped'       => array(),
			'unstamped'     => array(),
			'would_recover' => array(),
			'would_trash'   => array(),
			'busy'          => array(),
			'gone'          => array(),
			'recovered'     => array(),
			'trashed'       => array(),
			'failed'        => array(),
			'capped'        => array(),
		);

		foreach ( self::stuck_forks( (int) $after_id, max( 1, (int) $limit ), max( 0, (int) $orphan_window ) ) as $fork_id => $state ) {
			++$report['scanned'];
			$report['last_id'] = $fork_id;

			if ( Fork_Status::Merged->value === $state ) {
				self::sweep_merged_fork( $fork_id, $dry_run, $report );
				continue;
			}

			$since = self::read_since( $fork_id );
			if ( 0 === $since ) {
				if ( $dry_run ) {
					$report['unstamped'][] = $fork_id;
				} else {
					update_post_meta( $fork_id, self::SINCE_META, time() );
					$report['stamped'][] = $fork_id;
				}
				continue;
			}

			if ( time() - $since <= self::STUCK_AFTER_SECONDS ) {
				$report['young'][] = $fork_id;
				continue;
			}

			if ( $dry_run ) {
				$report['would_recover'][] = $fork_id;
				continue;
			}

			$parent_id = absint( get_post_meta( $fork_id, Future_Revisions::FORK_PARENT_META, true ) );
			$result    = Future_Revisions::recover_fork( $fork_id, $parent_id );

			if ( true === $result ) {
				$report['recovered'][] = $fork_id;
			} elseif ( 'fork_recover_busy' === $result->get_error_code() ) {
				$report['busy'][] = $fork_id;
			} elseif ( 'fork_not_merging' === $result->get_error_code() ) {
				$report['gone'][] = $fork_id;
			} else {
				$report['failed'][ $fork_id ] = $result->get_error_code();
				if ( self::record_failure( $fork_id, $parent_id, $result ) >= self::MAX_ATTEMPTS ) {
					$report['capped'][] = $fork_id;
				}
			}
		}

		return $report;
	}

	/**
	 * Forks in `merging` that need a person, because recovery failed `MAX_ATTEMPTS` times.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return int Failed recovery runs so far.
	 */
	public static function attempts( $fork_id ) {
		return (int) get_post_meta( (int) $fork_id, self::ATTEMPTS_META, true );
	}

	/**
	 * Whether the editor must show the "merge is stuck" notice for a fork.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return bool
	 */
	public static function is_stuck( $fork_id ) {
		return self::attempts( $fork_id ) >= self::MAX_ATTEMPTS
			&& Fork_Status::Merging->value === get_post_meta( (int) $fork_id, Future_Revisions::FORK_STATUS_META, true );
	}

	/**
	 * Count a failed recovery run, log it, and announce a fork that reached the cap.
	 *
	 * @param int      $fork_id   Fork post ID.
	 * @param int      $parent_id Parent post ID.
	 * @param WP_Error $error     Why the run failed.
	 * @return int Failed runs including this one.
	 */
	private static function record_failure( $fork_id, $parent_id, $error ) {
		$attempts = self::attempts( $fork_id );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return $attempts;
		}

		++$attempts;
		update_post_meta( $fork_id, self::ATTEMPTS_META, $attempts );

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The only record of a merge that did not finish.
			sprintf(
				'prc-revisions: recovery of fork %d (parent %d) failed, attempt %d of %d: %s %s%s',
				$fork_id,
				$parent_id,
				$attempts,
				self::MAX_ATTEMPTS,
				$error->get_error_code(),
				$error->get_error_message(),
				self::MAX_ATTEMPTS === $attempts ? ' The editor now shows the stuck notice. The sweep keeps trying and logs no more.' : ''
			)
		);

		if ( self::MAX_ATTEMPTS === $attempts ) {
			/**
			 * Fires once, when recovery of a fork in `merging` fails for the last allowed attempt.
			 *
			 * @param int      $fork_id   Fork post ID.
			 * @param int      $parent_id Parent post ID.
			 * @param int      $attempts  Failed runs.
			 * @param WP_Error $error     The last failure.
			 */
			do_action( 'prc_revisions_fork_merge_stuck', $fork_id, $parent_id, $attempts, $error );
		}

		return $attempts;
	}

	/**
	 * Trash a merged fork that is still published, and note the outcome in the report.
	 *
	 * @param int                 $fork_id Fork post ID.
	 * @param bool                $dry_run List the fork and write nothing.
	 * @param array<string,mixed> $report  The sweep report.
	 */
	private static function sweep_merged_fork( $fork_id, $dry_run, &$report ) {
		if ( $dry_run ) {
			$report['would_trash'][] = $fork_id;
			return;
		}

		$parent_id = absint( get_post_meta( $fork_id, Future_Revisions::FORK_PARENT_META, true ) );
		$result    = Future_Revisions::settle_merged_fork( $fork_id, $parent_id );

		if ( true === $result ) {
			$report['trashed'][] = $fork_id;
			error_log( sprintf( 'prc-revisions: trashed fork %d (parent %d), merged but still published.', $fork_id, $parent_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The only record that a merged fork was left published.
		} elseif ( 'fork_recover_busy' === $result->get_error_code() ) {
			$report['busy'][] = $fork_id;
		} elseif ( 'fork_not_merged' === $result->get_error_code() ) {
			$report['gone'][] = $fork_id;
		} else {
			$report['failed'][ $fork_id ] = $result->get_error_code();
			if ( self::record_failure( $fork_id, $parent_id, $result ) >= self::MAX_ATTEMPTS ) {
				$report['capped'][] = $fork_id;
			}
		}
	}

	/**
	 * The Unix time a fork entered `merging`, read from the primary database.
	 *
	 * @param int $fork_id Fork post ID.
	 * @return int Unix time, or 0 when the fork has no stamp.
	 */
	private static function read_since( $fork_id ) {
		self::pin_to_primary();
		wp_cache_delete( $fork_id, 'post_meta' );
		return (int) get_post_meta( $fork_id, self::SINCE_META, true );
	}

	/**
	 * One batch of forks the sweep may act on, ascending by ID, after $after_id.
	 *
	 * A fork is in the batch when its status is `merging`, or when its status is `merged` and its
	 * post is not in the Trash (a request died between the two writes). One keyset query reads both.
	 *
	 * The `merging` rows come from the status index alone. The merged rows come from the entry
	 * stamp index, newest window only, so the read does not grow with the forks in the Trash.
	 * Without a window the query reads every merged row.
	 *
	 * @param int $after_id      Return IDs above this one.
	 * @param int $limit         Rows to return.
	 * @param int $orphan_window Seconds back to look for a merged fork that is still published. 0 for no limit.
	 * @return array<int, string> Fork ID to its status, `merging` or `merged`.
	 */
	private static function stuck_forks( $after_id, $limit, $orphan_window = self::ORPHAN_WINDOW ) {
		global $wpdb;

		self::pin_to_primary();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One keyset read of the status rows that must see the primary. The core API has no query for it that skips the cache.
		if ( $orphan_window > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"( SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m WHERE m.meta_key = %s AND m.meta_value = %s AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d ) UNION ALL ( SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} s INNER JOIN {$wpdb->postmeta} m ON m.post_id = s.post_id AND m.meta_key = %s AND m.meta_value = %s INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id WHERE s.meta_key = %s AND s.meta_value >= %s AND s.post_id > %d AND p.post_status <> 'trash' ORDER BY s.post_id ASC LIMIT %d ) ORDER BY post_id ASC LIMIT %d",
					Future_Revisions::FORK_STATUS_META,
					Fork_Status::Merging->value,
					$after_id,
					$limit,
					Future_Revisions::FORK_STATUS_META,
					Fork_Status::Merged->value,
					self::SINCE_META,
					(string) ( time() - $orphan_window ),
					$after_id,
					$limit,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND ( m.meta_value = %s OR ( m.meta_value = %s AND p.post_status <> 'trash' ) ) AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d",
					Future_Revisions::FORK_STATUS_META,
					Fork_Status::Merging->value,
					Fork_Status::Merged->value,
					$after_id,
					$limit
				)
			);
		}
		// phpcs:enable

		$forks = array();
		foreach ( (array) $rows as $row ) {
			$forks[ (int) $row->post_id ] = (string) $row->meta_value;
		}

		return $forks;
	}

	/**
	 * Read `wp_postmeta` from the primary database, because the lease write pins `wp_options` only.
	 */
	private static function pin_to_primary() {
		global $wpdb;

		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->postmeta ] = true;
			$wpdb->srtm[ $wpdb->posts ]    = true;
		}
	}
}
