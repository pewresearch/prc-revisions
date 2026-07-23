/**
 * WordPress Dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch, select } from '@wordpress/data';
import { PluginPostStatusInfo, store as editorStore } from '@wordpress/editor';
import { store as coreStore } from '@wordpress/core-data';
import {
	Button,
	Spinner,
	Notice,
	Flex,
	FlexItem,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis -- ConfirmDialog is the standard destructive confirm pattern in WP packages.
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { Icon, backup } from '@wordpress/icons';

import { REST_NAMESPACE } from './hooks';

/**
 * Clear `_prc_active_fork` on the parent entity record without marking edits dirty.
 * Chart synced-ref resolution reads this meta from core-data (including `context: edit`).
 *
 * @param {string}   postType
 * @param {number}   postId
 * @param {Function} receiveEntityRecords
 */
function clearActiveForkEntityMeta(postType, postId, receiveEntityRecords) {
	const queries = [undefined, { context: 'edit' }];
	queries.forEach((query) => {
		const record = select(coreStore).getEntityRecord(
			'postType',
			postType,
			postId,
			query
		);
		if (!record) {
			return;
		}
		receiveEntityRecords(
			'postType',
			postType,
			{
				...record,
				meta: {
					...(record.meta || {}),
					_prc_active_fork: 0,
				},
			},
			query,
			true
		);
	});
}

/**
 * Fork/merge panel shown in Post Status Info on published posts and on fork posts.
 * Discard is only offered on the parent; on the fork, use Move to trash.
 */
export default function ForkPanel() {
	const { postId, postStatus, postType } = useSelect(
		(selectStore) => ({
			postId: selectStore(editorStore).getCurrentPostId(),
			postStatus:
				selectStore(editorStore).getEditedPostAttribute('status'),
			postType: selectStore(editorStore).getCurrentPostType(),
		}),
		[]
	);

	const { receiveEntityRecords } = useDispatch(coreStore);

	const [forkInfo, setForkInfo] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [isCreating, setIsCreating] = useState(false);
	const [isDiscarding, setIsDiscarding] = useState(false);
	const [isConfirmOpen, setIsConfirmOpen] = useState(false);
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

	const handleDiscardFork = useCallback(async () => {
		if (isDiscarding) {
			return;
		}
		setIsDiscarding(true);
		setError(null);
		try {
			await apiFetch({
				path: `/${REST_NAMESPACE}/fork/${postId}`,
				method: 'DELETE',
			});
			// Keep core-data in sync with the server so embeds stop using the trashed fork.
			clearActiveForkEntityMeta(postType, postId, receiveEntityRecords);
			// Parent context: refresh panel so Create Future Revision returns.
			setForkInfo({ role: 'none' });
			setIsConfirmOpen(false);
		} catch (err) {
			setError(err.message);
		} finally {
			setIsDiscarding(false);
		}
	}, [isDiscarding, postId, postType, receiveEntityRecords]);

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
				<ConfirmDialog
					isOpen={isConfirmOpen}
					onConfirm={handleDiscardFork}
					onCancel={() => {
						if (!isDiscarding) {
							setIsConfirmOpen(false);
						}
					}}
					confirmButtonText={
						isDiscarding ? 'Discarding...' : 'Discard'
					}
					isBusy={isDiscarding}
					shouldCloseOnEsc={!isDiscarding}
					shouldCloseOnClickOutside={!isDiscarding}
				>
					Discard this future revision? It will be moved to the trash.
					The published post will not be changed.
				</ConfirmDialog>
				<Flex direction="column" gap={2} style={{ flexGrow: 1 }}>
					{error && (
						<Notice
							status="error"
							isDismissible={false}
							style={{ marginBottom: '8px' }}
						>
							{error}
						</Notice>
					)}
					<FlexItem>
						<span>
							Active fork exists.{' '}
							<a href={forkInfo.fork_edit_url}>Edit the fork</a>
							<span
								style={{ fontSize: '12px', marginLeft: '4px' }}
							>
								({forkInfo.fork_status})
							</span>
						</span>
					</FlexItem>
					<FlexItem>
						<Button
							variant="link"
							isDestructive
							__next40pxDefaultSize
							onClick={() => setIsConfirmOpen(true)}
							disabled={isDiscarding || isConfirmOpen}
						>
							Discard Future Revision
						</Button>
					</FlexItem>
				</Flex>
			</PluginPostStatusInfo>
		);
	}

	if (postStatus !== 'publish') {
		return null;
	}

	return (
		<PluginPostStatusInfo>
			<Flex direction="column" gap={2} style={{ flexGrow: 1 }}>
				{error && (
					<Notice
						status="error"
						isDismissible={false}
						style={{ marginBottom: '8px' }}
					>
						{error}
					</Notice>
				)}
				<FlexItem>
					<Button
						variant="secondary"
						onClick={handleCreateFork}
						isBusy={isCreating}
						disabled={isCreating}
						icon={<Icon icon={backup} size={20} />}
						style={{
							justifyContent: 'center',
							width: '100%',
						}}
					>
						{isCreating ? 'Creating...' : 'Create Future Revision'}
					</Button>
				</FlexItem>
			</Flex>
		</PluginPostStatusInfo>
	);
}
