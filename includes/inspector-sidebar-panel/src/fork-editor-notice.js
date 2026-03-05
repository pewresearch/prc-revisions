import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

import { useForkInfo } from './hooks';

const FORK_NOTICE_ID = 'prc-revisions-fork-notice';

/**
 * Headless component: creates an editor-level warning notice banner
 * when the current post is a fork.
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
	const { createWarningNotice, removeNotice } = useDispatch('core/notices');

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

	return null;
}
