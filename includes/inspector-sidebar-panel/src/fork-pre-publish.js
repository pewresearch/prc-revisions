import { useSelect } from '@wordpress/data';
import { PluginPrePublishPanel, store as editorStore } from '@wordpress/editor';
import { Icon, backup } from '@wordpress/icons';
import { Notice } from '@wordpress/components';

import { useForkInfo } from './hooks';

/**
 * PluginPrePublishPanel: Explains merge behavior when about to publish a fork.
 */
export default function ForkPrePublishPanel() {
	const { postId, parentId } = useSelect((select) => {
		const meta = select(editorStore).getEditedPostAttribute('meta') || {};
		return {
			postId: select(editorStore).getCurrentPostId(),
			parentId: meta._prc_fork_parent || 0,
		};
	}, []);

	const { forkInfo, isLoading } = useForkInfo(postId);

	if (!parentId || isLoading || forkInfo?.role !== 'fork') {
		return null;
	}

	return (
		<PluginPrePublishPanel
			title="Future Revision"
			icon={<Icon icon={backup} size={20} />}
			initialOpen
		>
			<Notice status="warning" isDismissible={false}>
				<p>
					Publishing will merge into the original post:{' '}
					<a href={forkInfo.parent_edit_url}>
						{forkInfo.parent_title}
					</a>
				</p>
				<p style={{ marginTop: '8px' }}>
					<strong>What happens when you publish:</strong>
				</p>
				<ul style={{ marginTop: '4px', paddingLeft: '20px' }}>
					<li>
						The original post will be updated with this fork&apos;s
						content, meta, and taxonomy.
					</li>
					<li>This fork will be trashed after the merge.</li>
					<li>You will be redirected to the original post.</li>
				</ul>
			</Notice>
		</PluginPrePublishPanel>
	);
}
