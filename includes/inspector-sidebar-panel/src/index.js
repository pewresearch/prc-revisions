/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useState, useEffect, useCallback } from '@wordpress/element';
import { useCommand } from '@wordpress/commands';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editPostStore } from '@wordpress/edit-post';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, store as editorStore } from '@wordpress/editor';
import { Icon, backup } from '@wordpress/icons';
import { store as coreStore } from '@wordpress/core-data';
import { PanelBody, PanelRow, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal Dependencies
 */
import { useCurrentRevisionId, REST_NAMESPACE } from './hooks';
import { RevisionItem, WPRevisionItem } from './revision-items';
import ForkPanel from './fork-panel';
import ForkEditorNotice from './fork-editor-notice';
import ForkPrePublishPanel from './fork-pre-publish';
import ForkPreviewMenuItem from './fork-preview-menu-item';
import ForkPostPublishRedirect from './fork-post-publish-redirect';

const PLUGIN_NAME = 'prc-revisions-panel';

/**
 * Main sidebar panel for managing public revisions and future revisions.
 */
function RevisionsPanel() {
	const { openGeneralSidebar } = useDispatch(editPostStore);

	useCommand({
		name: 'prc/show-revisions',
		label: __('Show Public Versions', 'prc-revisions'),
		icon: backup,
		category: 'view',
		keywords: ['revisions', 'versions', 'public', 'history'],
		callback: ({ close }) => {
			openGeneralSidebar(`${PLUGIN_NAME}/${PLUGIN_NAME}`);
			close();
		},
	});

	const { postId, restBase } = useSelect((select) => {
		const currentPostType = select(editorStore).getCurrentPostType();
		const postTypeObject = currentPostType
			? select(coreStore).getPostType(currentPostType)
			: null;
		return {
			postId: select(editorStore).getCurrentPostId(),
			restBase: postTypeObject?.rest_base,
		};
	}, []);

	const currentRevisionId = useCurrentRevisionId();

	const [publicRevisions, setPublicRevisions] = useState([]);
	const [wpRevisions, setWpRevisions] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [isToggling, setIsToggling] = useState(false);
	const [error, setError] = useState(null);

	const fetchPublicRevisions = useCallback(async () => {
		if (!postId) {
			return;
		}
		try {
			const data = await apiFetch({
				path: `/${REST_NAMESPACE}/public-revisions/${postId}`,
			});
			setPublicRevisions(data);
		} catch (err) {
			setError(err.message);
		}
	}, [postId]);

	const fetchWPRevisions = useCallback(async () => {
		if (!postId || !restBase) {
			return;
		}
		try {
			const data = await apiFetch({
				path: `/wp/v2/${restBase}/${postId}/revisions?per_page=50`,
			});
			setWpRevisions(
				data.map((rev) => ({
					id: rev.id,
					date_display: new Date(rev.date).toLocaleDateString(
						'en-US',
						{
							year: 'numeric',
							month: 'short',
							day: 'numeric',
							hour: '2-digit',
							minute: '2-digit',
						}
					),
				}))
			);
		} catch {
			setWpRevisions([]);
		}
	}, [postId, restBase]);

	useEffect(() => {
		setIsLoading(true);
		Promise.all([fetchPublicRevisions(), fetchWPRevisions()]).finally(
			() => {
				setIsLoading(false);
			}
		);
	}, [fetchPublicRevisions, fetchWPRevisions]);

	const handleToggle = useCallback(
		async (revisionId) => {
			setIsToggling(true);
			setError(null);
			try {
				await apiFetch({
					path: `/${REST_NAMESPACE}/toggle/${postId}/${revisionId}`,
					method: 'POST',
				});
				await fetchPublicRevisions();
			} catch (err) {
				setError(err.message);
			} finally {
				setIsToggling(false);
			}
		},
		[postId, fetchPublicRevisions]
	);

	if (!postId) {
		return null;
	}

	return (
		<Fragment>
			<PluginSidebar
				name={PLUGIN_NAME}
				title="Revisions"
				icon={<Icon icon={backup} size={20} />}
			>
				{error && (
					<Notice status="error" isDismissible={false}>
						{error}
					</Notice>
				)}

				{currentRevisionId && (
					<Notice status="info" isDismissible={false}>
						Viewing revision #{currentRevisionId}. Toggle
						&ldquo;Public&rdquo; below to publish it at a versioned
						URL.
					</Notice>
				)}

				<PanelBody title="Public Versions" initialOpen={true}>
					{isLoading && <Spinner />}
					{!isLoading && publicRevisions.length === 0 && (
						<PanelRow>
							<p style={{ color: '#757575', fontSize: '13px' }}>
								No revisions have been made public yet. Use the
								toggles below to publish a revision as a
								versioned URL.
							</p>
						</PanelRow>
					)}
					{!isLoading &&
						publicRevisions.map((revision) => (
							<RevisionItem
								key={revision.revision_id}
								revision={revision}
								onToggle={handleToggle}
								isToggling={isToggling}
								isActive={
									revision.revision_id === currentRevisionId
								}
							/>
						))}
				</PanelBody>

				<PanelBody
					title="All Revisions"
					initialOpen={!!currentRevisionId}
				>
					{isLoading && <Spinner />}
					{!isLoading && wpRevisions.length === 0 && (
						<PanelRow>
							<p style={{ color: '#757575', fontSize: '13px' }}>
								No revisions found for this post.
							</p>
						</PanelRow>
					)}
					{!isLoading &&
						wpRevisions.map((wpRevision) => (
							<WPRevisionItem
								key={wpRevision.id}
								wpRevision={wpRevision}
								publicRevisions={publicRevisions}
								onToggle={handleToggle}
								isToggling={isToggling}
								isActive={wpRevision.id === currentRevisionId}
							/>
						))}
				</PanelBody>
			</PluginSidebar>
		</Fragment>
	);
}

registerPlugin(PLUGIN_NAME, {
	render: RevisionsPanel,
});

registerPlugin('prc-revisions-fork-panel', {
	render: ForkPanel,
});

registerPlugin('prc-revisions-fork-preview', {
	render: ForkPreviewMenuItem,
});

registerPlugin('prc-revisions-fork-post-publish', {
	render: ForkPostPublishRedirect,
});

registerPlugin('prc-revisions-fork-status-info', {
	render: ForkEditorNotice,
});

registerPlugin('prc-revisions-fork-pre-publish', {
	render: ForkPrePublishPanel,
});
