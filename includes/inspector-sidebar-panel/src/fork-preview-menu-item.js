import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { PluginPreviewMenuItem, store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

import { REST_NAMESPACE } from './hooks';

/**
 * PluginPreviewMenuItem: "Preview Future Revision"
 *
 * Shown in the preview dropdown on parent posts that have an active fork.
 * Opens the fork's preview URL in a new tab.
 */
export default function ForkPreviewMenuItem() {
	const { postId } = useSelect(
		(select) => ({
			postId: select(editorStore).getCurrentPostId(),
		}),
		[]
	);

	const [forkInfo, setForkInfo] = useState(null);

	useEffect(() => {
		if (!postId) {
			return;
		}
		apiFetch({
			path: `/${REST_NAMESPACE}/fork-info/${postId}`,
		})
			.then(setForkInfo)
			.catch(() => setForkInfo(null));
	}, [postId]);

	if (!forkInfo || forkInfo.role !== 'parent' || !forkInfo.fork_id) {
		return null;
	}

	if ('function' !== typeof PluginPreviewMenuItem) {
		return null;
	}

	const previewUrl = `/?p=${forkInfo.fork_id}&preview=true`;

	return (
		<PluginPreviewMenuItem
			onClick={() => window.open(previewUrl, '_blank')}
		>
			Preview Future Revision
		</PluginPreviewMenuItem>
	);
}
