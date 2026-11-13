<?php
/**
 * WP-CLI commands for future revisions.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

use PRC\Primitives\Concurrency\Meta_State_Error;
use WP_CLI;
use WPCOM_VIP_CLI_Command;

if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Maintain future revision (fork) data.
	 */
	class CLI extends WPCOM_VIP_CLI_Command {

		/**
		 * Give every live fork that has no `_prc_fork_status` row its first status, `draft`.
		 *
		 * Forks made before the status moved to `Meta_State` may have no row.
		 * `Meta_State::init()` writes the row once, so the command is safe to run
		 * again, and safe to run while editors work. A fork that gets published or
		 * discarded before this command reaches it gets its row at that moment.
		 *
		 * The command also lists, and does not change, two kinds of fork that need
		 * a person to decide. A live fork whose status is not draft, merging, merged,
		 * or discarded can never be published or discarded. A fork that has been
		 * `merging` for longer than the merge lease belongs to a request that died.
		 * The recovery sweep finishes it, and `recover-stuck` does the same by hand.
		 *
		 * Defaults to dry-run mode. Pass --dry-run=false to write.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run=<bool>]
		 * : Run without making changes. Default: true.
		 *
		 * [--batch-size=<number>]
		 * : Forks per batch, 100 at most. Default: 100.
		 *
		 * [--start-id=<id>]
		 * : Resume from the first fork with an ID above this one. Default: 0.
		 *
		 * [--sleep=<seconds>]
		 * : Seconds to wait between batches. Default: 2.
		 *
		 * ## EXAMPLES
		 *
		 *     # Preview
		 *     wp prc revisions backfill-fork-status
		 *
		 *     # Write
		 *     wp prc revisions backfill-fork-status --dry-run=false
		 *
		 *     # Resume after an interruption
		 *     wp prc revisions backfill-fork-status --dry-run=false --start-id=12345
		 *
		 * @subcommand backfill-fork-status
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function backfill_fork_status( $args, $assoc_args ) {
			$dry_run    = $this->parse_dry_run( $assoc_args );
			$batch_size = max( 1, min( (int) ( $assoc_args['batch-size'] ?? 100 ), 100 ) );
			$start_id   = (int) ( $assoc_args['start-id'] ?? 0 );
			$sleep      = max( 0, (int) ( $assoc_args['sleep'] ?? 2 ) );

			WP_CLI::line( $dry_run ? 'Running in dry-run mode. Nothing is written.' : 'Writing missing fork statuses.' );

			$this->start_bulk_operation();

			$found   = 0;
			$written = 0;
			$raced   = 0;
			$failed  = 0;
			$last_id = $start_id;
			$status  = Future_Revisions::fork_status();

			do {
				$batch_ids = $this->fork_ids( 'missing', $last_id, $batch_size );
				$has_more  = count( $batch_ids ) === $batch_size;

				foreach ( $batch_ids as $fork_id ) {
					++$found;
					$last_id = $fork_id;
					if ( $dry_run ) {
						continue;
					}

					try {
						if ( $status->init( $fork_id, Fork_Status::Draft ) ) {
							++$written;
						} else {
							++$raced;
						}
					} catch ( Meta_State_Error $error ) {
						++$failed;
						WP_CLI::warning( sprintf( 'Fork %d: %s', $fork_id, $error->getMessage() ) );
					}
				}

				if ( array() !== $batch_ids ) {
					WP_CLI::line( sprintf( 'Batch done. Last fork ID: %d. Without a status so far: %d.', $last_id, $found ) );
					if ( $sleep > 0 ) {
						sleep( $sleep ); // phpcs:ignore WordPressVIPMinimum.Performance.NoSleep -- WP-CLI only. The command pauses between batches so a large backfill does not load the primary database.
					}
					$this->vip_inmemory_cleanup();
				}
			} while ( $has_more );

			$this->end_bulk_operation();

			$invalid = $this->collect_ids( 'invalid', $batch_size );
			$stuck   = $this->collect_ids( 'stuck', $batch_size );

			if ( $dry_run ) {
				WP_CLI::success( sprintf( '%d live forks have no status row and would get draft. Run with --dry-run=false to write.', $found ) );
			} else {
				WP_CLI::success(
					sprintf(
						'%d live forks had no status row. Wrote draft for %d, %d already had a row by then, %d failed. Last fork ID: %d.',
						$found,
						$written,
						$raced,
						$failed,
						$last_id
					)
				);
			}

			$this->report( 'Live forks with a status that is not draft, merging, merged, or discarded (not changed)', $invalid );
			$this->report( 'Forks in merging for longer than the merge lease (finished by the recovery sweep, or by recover-stuck; not changed here)', $stuck );
		}

		/**
		 * Finish future revisions that a dead request left in `merging`.
		 *
		 * A fork that entered `merging` more than 30 seconds ago, whose parent's fork lease is
		 * free, gets its merge run again. The merge writes the parent only when the parent does
		 * not already hold the fork content. A fork that cannot be finished stays in `merging`,
		 * and the command counts the attempt. After 5 failed attempts the editor shows a
		 * notice on the fork and on its parent. The recurring sweep does the same work every
		 * minute. This command is for a person who wants to see, or to run, one pass.
		 *
		 * A fork in `merging` that has no entry time gets one now and is left alone until the
		 * next pass.
		 *
		 * The command also trashes a fork whose status is `merged` and whose post is not in the
		 * Trash. A request that died between those two writes leaves such a fork published. The
		 * command does not merge it again. The recurring sweep looks for such a fork among the
		 * merges of the last day only, so its idle query does not grow with the Trash. This
		 * command looks at every merged fork, so it finds an older one too.
		 *
		 * The command keeps retrying a fork that failed 5 times, so a person can run it by hand
		 * once the cause is fixed. The recurring sweep does the same, and logs the fork once, at
		 * the 5th failure.
		 *
		 * The command has no `--yes` flag. It defaults to dry-run mode. Pass --dry-run=false to write.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run=<bool>]
		 * : Print what would happen and write nothing. Default: true.
		 *
		 * [--batch-size=<number>]
		 * : Forks per batch, 100 at most. Default: 20.
		 *
		 * [--start-id=<id>]
		 * : Resume from the first fork with an ID above this one. Default: 0.
		 *
		 * [--sleep=<seconds>]
		 * : Seconds to wait between batches. Default: 1.
		 *
		 * ## EXAMPLES
		 *
		 *     # Preview
		 *     wp prc revisions recover-stuck
		 *
		 *     # Finish the stuck merges
		 *     wp prc revisions recover-stuck --dry-run=false
		 *
		 * @subcommand recover-stuck
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function recover_stuck( $args, $assoc_args ) {
			$dry_run    = $this->parse_dry_run( $assoc_args );
			$batch_size = max( 1, min( (int) ( $assoc_args['batch-size'] ?? Fork_Recovery::BATCH ), 100 ) );
			$last_id    = (int) ( $assoc_args['start-id'] ?? 0 );
			$sleep      = max( 0, (int) ( $assoc_args['sleep'] ?? 1 ) );

			WP_CLI::line( $dry_run ? 'Running in dry-run mode. Nothing is written.' : 'Finishing stuck merges.' );

			$this->start_bulk_operation();

			$lists  = array();
			$failed = array();
			do {
				$report   = Fork_Recovery::sweep( $dry_run, $batch_size, $last_id, 0 );
				$last_id  = $report['last_id'];
				$has_more = $report['scanned'] === $batch_size;

				foreach ( array( 'young', 'stamped', 'unstamped', 'would_recover', 'would_trash', 'busy', 'gone', 'recovered', 'trashed', 'capped' ) as $key ) {
					$lists[ $key ] = array_merge( $lists[ $key ] ?? array(), $report[ $key ] );
				}
				$failed += $report['failed'];

				if ( $report['scanned'] > 0 ) {
					WP_CLI::line( sprintf( 'Batch done. Last fork ID: %d. Forks found in this batch: %d.', $last_id, $report['scanned'] ) );
					if ( $has_more && $sleep > 0 ) {
						sleep( $sleep ); // phpcs:ignore WordPressVIPMinimum.Performance.NoSleep -- WP-CLI only. The command pauses between batches so a large run does not load the primary database.
					}
					$this->vip_inmemory_cleanup();
				}
			} while ( $has_more );

			$this->end_bulk_operation();

			$this->report( $dry_run ? 'Would finish the merge of' : 'Finished the merge of', $lists[ $dry_run ? 'would_recover' : 'recovered' ] ?? array() );
			$this->report( $dry_run ? 'Merged but still published (would be trashed, not merged again)' : 'Merged but still published (trashed, not merged again)', $lists[ $dry_run ? 'would_trash' : 'trashed' ] ?? array() );
			$this->report( 'Entered merging less than 30 seconds ago (left alone)', $lists['young'] ?? array() );
			$this->report( $dry_run ? 'Have no entry time (would get one now)' : 'Had no entry time (got one now, retried on the next pass)', $lists[ $dry_run ? 'unstamped' : 'stamped' ] ?? array() );
			$this->report( 'Parent lease busy (skipped)', $lists['busy'] ?? array() );
			$this->report( 'No longer merging, or already in the Trash (skipped)', $lists['gone'] ?? array() );
			$this->report( 'Failed, left as they were', array_keys( $failed ) );
			foreach ( $failed as $fork_id => $code ) {
				WP_CLI::warning( sprintf( 'Fork %d: %s', $fork_id, $code ) );
			}
			$this->report( sprintf( 'Failed %d times or more, so the editor shows the stuck notice (needs a person; this command still retries them)', Fork_Recovery::MAX_ATTEMPTS ), $lists['capped'] ?? array() );

			WP_CLI::success( $dry_run ? 'Dry run done. Run with --dry-run=false to finish the merges.' : 'Done.' );
		}

		/**
		 * Print a count and the first fork IDs of a list that needs a decision.
		 *
		 * @param string $label Heading.
		 * @param int[]  $ids   Fork IDs.
		 */
		private function report( $label, $ids ) {
			WP_CLI::line( sprintf( '%s: %d', $label, count( $ids ) ) );
			if ( array() !== $ids ) {
				WP_CLI::line( '  IDs: ' . implode( ', ', array_slice( $ids, 0, 50 ) ) . ( count( $ids ) > 50 ? ', ...' : '' ) );
			}
		}

		/**
		 * Every fork ID of one kind, in batches. Read only.
		 *
		 * @param string $kind       'invalid' or 'stuck'.
		 * @param int    $batch_size Rows per query.
		 * @return int[]
		 */
		private function collect_ids( $kind, $batch_size ) {
			$all     = array();
			$last_id = 0;
			do {
				$ids      = $this->fork_ids( $kind, $last_id, $batch_size );
				$has_more = count( $ids ) === $batch_size;
				if ( array() !== $ids ) {
					$last_id = end( $ids );
					$all     = array_merge( $all, $ids );
				}
			} while ( $has_more );

			return $all;
		}

		/**
		 * One batch of fork IDs, in ascending order, after $after_id.
		 *
		 * Forks are posts with a `_prc_fork_parent` row. Each kind is a bounded
		 * keyset query on post ID, so no query reads the whole table.
		 *
		 * - missing: a live fork with no `_prc_fork_status` row.
		 * - invalid: a live fork whose status is not a `Fork_Status` value.
		 * - stuck: a fork that entered `merging` before the cutoff, or has no entry time.
		 *
		 * @param string $kind     'missing', 'invalid', or 'stuck'.
		 * @param int    $after_id Return IDs above this one.
		 * @param int    $limit    Rows to return.
		 * @return int[]
		 */
		private function fork_ids( $kind, $after_id, $limit ) {
			global $wpdb;

			$from = "FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} fp ON fp.post_id = p.ID AND fp.meta_key = %s AND fp.meta_value NOT IN ('', '0')
				LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = %s";

			if ( 'missing' === $kind ) {
				$where = "WHERE p.ID > %d AND p.post_status NOT IN ('trash', 'auto-draft') AND st.meta_id IS NULL";
				$extra = array();
			} elseif ( 'invalid' === $kind ) {
				$where = "WHERE p.ID > %d AND p.post_status NOT IN ('trash', 'auto-draft') AND st.meta_id IS NOT NULL AND st.meta_value NOT IN ('draft', 'merging', 'merged', 'discarded')";
				$extra = array();
			} else {
				$where = "WHERE p.ID > %d AND st.meta_value = 'merging' AND ( ms.meta_id IS NULL OR CAST( ms.meta_value AS UNSIGNED ) < %d )";
				$extra = array( time() - Fork_Recovery::STUCK_AFTER_SECONDS );
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A keyset query on the post ID. The core API has no "meta row missing" query without a meta_query join of the same cost. The fragments above are fixed strings, and every value is prepared.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID {$from} {$where} ORDER BY p.ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge(
						array( Future_Revisions::FORK_PARENT_META, Future_Revisions::FORK_STATUS_META, Fork_Recovery::SINCE_META, $after_id ),
						$extra,
						array( $limit )
					)
				)
			);
			// phpcs:enable

			return array_map( 'intval', (array) $ids );
		}

		/**
		 * Parse --dry-run safely. WP-CLI passes the string 'false'.
		 *
		 * @param array $assoc_args Associative arguments.
		 * @return bool
		 */
		private function parse_dry_run( $assoc_args ) {
			if ( ! isset( $assoc_args['dry-run'] ) ) {
				return true;
			}

			return 'false' !== $assoc_args['dry-run'] && (bool) $assoc_args['dry-run'];
		}
	}

	WP_CLI::add_command( 'prc revisions', __NAMESPACE__ . '\\CLI' );
}
