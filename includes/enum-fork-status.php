<?php
/**
 * Fork status enum.
 *
 * @package PRC\Platform\Revisions
 */

declare(strict_types=1);

namespace PRC\Platform\Revisions;

/**
 * States of a fork, stored in `_prc_fork_status` through `Meta_State`.
 *
 * Edges, in `Future_Revisions::fork_status()`:
 *
 * - Draft to Merging: a publish claims the merge.
 * - Merging to Merged: the merge wrote the parent.
 * - Merging to Draft: the merge failed before it wrote the parent.
 * - Draft to Discarded: an editor discards the fork.
 * - Discarded to Draft: an editor restores a discarded fork, or a discard could not trash it.
 */
enum Fork_Status: string {

	case Draft     = 'draft';
	case Merging   = 'merging';
	case Merged    = 'merged';
	case Discarded = 'discarded';
}
