/**
 * WordPress Dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import {
	Button,
	Spinner,
	Notice,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { Icon, backup } from '@wordpress/icons';

import { REST_NAMESPACE } from './hooks';

/**
 * Fork/merge panel shown in Post Status Info on published posts and on fork posts.
 */
export default function ForkPanel() {
	const { postId, postStatus } = useSelect(
		(select) => ({
			postId: select(editorStore).getCurrentPostId(),
			postStatus: select(editorStore).getEditedPostAttribute('status'),
		}),
		[]
	);

	const [forkInfo, setForkInfo] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [isCreating, setIsCreating] = useState(false);
	const [error, setError] = useState(null);

	const fetchForkInfo = useCallback(async () => {
		if (!postId) {
			return;
		}
		try {
			const data = await apiFetch({
				path: `/${REST_NAMESPACE}/fork-info/${postId}`,
			});
			setForkInfo(data);
		} catch {
			setForkInfo({ role: 'none' });
		} finally {
			setIsLoading(false);
		}
	}, [postId]);

	useEffect(() => {
		fetchForkInfo();
	}, [fetchForkInfo]);

	const handleCreateFork = useCallback(async () => {
		setIsCreating(true);
		setError(null);
		try {
			const result = await apiFetch({
				path: `/${REST_NAMESPACE}/fork/${postId}`,
				method: 'POST',
			});
			if (result.edit_url) {
				window.location.href = result.edit_url;
			}
		} catch (err) {
			setError(err.message);
			setIsCreating(false);
		}
	}, [postId]);

	if (!postId) {
		return null;
	}

	if (isLoading) {
		return (
			<PluginPostStatusInfo>
				<div>
					<Spinner />
					<span>Loading fork information...</span>
				</div>
			</PluginPostStatusInfo>
		);
	}

	if (forkInfo?.role === 'fork') {
		return (
			<PluginPostStatusInfo>
				<div>
					<span>
						Future revision of{' '}
						<a href={forkInfo.parent_edit_url}>
							{forkInfo.parent_title}
						</a>
						. Publish to merge back.
					</span>
				</div>
			</PluginPostStatusInfo>
		);
	}

	if (forkInfo?.role === 'parent') {
		return (
			<PluginPostStatusInfo>
				<div>
					<span>
						Active fork exists.{' '}
						<a href={forkInfo.fork_edit_url}>Edit the fork</a>
						<span style={{ fontSize: '12px', marginLeft: '4px' }}>
							({forkInfo.fork_status})
						</span>
					</span>
				</div>
			</PluginPostStatusInfo>
		);
	}

	if (postStatus !== 'publish') {
		return null;
	}

	return (
		<PluginPostStatusInfo>
			<div>
				{error && (
					<Notice
						status="error"
						isDismissible={false}
						style={{ marginBottom: '8px' }}
					>
						{error}
					</Notice>
				)}
				<Button
					variant="secondary"
					onClick={handleCreateFork}
					isBusy={isCreating}
					disabled={isCreating}
					icon={<Icon icon={backup} size={20} />}
				>
					{isCreating ? 'Creating...' : 'Create Future Revision'}
				</Button>
			</div>
		</PluginPostStatusInfo>
	);
}
