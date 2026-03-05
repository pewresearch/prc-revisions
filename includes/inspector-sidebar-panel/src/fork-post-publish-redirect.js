import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	PluginPostPublishPanel,
	store as editorStore,
} from '@wordpress/editor';
import { Button } from '@wordpress/components';

/**
 * PluginPostPublishPanel: "Fork Merged" redirect
 *
 * Shown after publishing a fork. Displays a message that the fork was merged,
 * then auto-redirects to the parent post's edit screen.
 */
export default function ForkPostPublishRedirect() {
	const { parentId, postStatus } = useSelect((select) => {
		const meta = select(editorStore).getEditedPostAttribute('meta') || {};
		return {
			parentId: meta._prc_fork_parent || 0,
			postStatus: select(editorStore).getEditedPostAttribute('status'),
		};
	}, []);

	useEffect(() => {
		if (parentId && postStatus === 'publish') {
			const timer = setTimeout(() => {
				window.location.href = `${window.prcPlatform.siteUrl}/wp-admin/post.php?post=${parentId}&action=edit`;
			}, 3000);
			return () => clearTimeout(timer);
		}
	}, [parentId, postStatus]);

	if (!parentId) {
		return null;
	}

	const editUrl = `/wp-admin/post.php?post=${parentId}&action=edit`;

	return (
		<PluginPostPublishPanel title="Fork Merged" initialOpen>
			<p>
				This fork has been merged into the original post and will be
				trashed.
			</p>
			<p>Redirecting to the parent post...</p>
			<Button variant="primary" href={editUrl}>
				Go to parent post now
			</Button>
		</PluginPostPublishPanel>
	);
}
