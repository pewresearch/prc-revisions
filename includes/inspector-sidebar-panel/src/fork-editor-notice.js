import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

import { useForkInfo } from './hooks';

const FORK_NOTICE_ID = 'prc-revisions-fork-notice';
const MERGE_STUCK_NOTICE_ID = 'prc-revisions-merge-stuck-notice';

/**
 * Headless component: creates an editor-level warning notice banner
 * when the current post is a fork, and an error notice on the fork and
 * on its parent when the automatic recovery of a stuck merge gave up.
 */
export default function ForkEditorNotice() {
	const { postId, parentId } = useSelect((select) => {
		const meta = select(editorStore).getEditedPostAttribute('meta') || {};
		return {
			postId: select(editorStore).getCurrentPostId(),
			parentId: meta._prc_fork_parent || 0,
		};
	}, []);

	const { forkInfo, isLoading } = useForkInfo(postId);
	const { createWarningNotice, createErrorNotice, removeNotice } =
		useDispatch('core/notices');

	useEffect(() => {
		if (!parentId || isLoading || forkInfo?.role !== 'fork') {
			removeNotice(FORK_NOTICE_ID);
			return;
		}
		createWarningNotice(
			`This is a future revision of "${forkInfo.parent_title}"`,
			{
				id: FORK_NOTICE_ID,
				isDismissible: false,
				actions: [
					{
						label: 'View original post',
						url: forkInfo.parent_edit_url,
					},
				],
			}
		);
		return () => removeNotice(FORK_NOTICE_ID);
	}, [parentId, isLoading, forkInfo, createWarningNotice, removeNotice]);

	useEffect(() => {
		if (isLoading || !forkInfo?.merge_stuck) {
			removeNotice(MERGE_STUCK_NOTICE_ID);
			return;
		}
		const isFork = forkInfo.role === 'fork';
		const link = isFork ? forkInfo.parent_edit_url : forkInfo.fork_edit_url;
		createErrorNotice(
			isFork
				? 'This future revision did not finish publishing. The system tried again several times and could not complete the merge. Ask an administrator to run "wp prc revisions recover-stuck".'
				: 'A future revision of this post did not finish publishing. The system tried again several times and could not complete the merge. Ask an administrator to run "wp prc revisions recover-stuck".',
			{
				id: MERGE_STUCK_NOTICE_ID,
				isDismissible: false,
				actions: link
					? [
							{
								label: isFork
									? 'View original post'
									: 'View future revision',
								url: link,
							},
						]
					: [],
			}
		);
		return () => removeNotice(MERGE_STUCK_NOTICE_ID);
	}, [isLoading, forkInfo, createErrorNotice, removeNotice]);

	return null;
}
