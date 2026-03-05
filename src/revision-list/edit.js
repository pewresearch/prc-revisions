/**
 * WordPress Dependencies
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, Spinner } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const REST_NAMESPACE = 'prc-revisions/v1';

function Edit({ attributes, setAttributes, context }) {
	const { showDates } = attributes;
	const { postId } = context;
	const [revisions, setRevisions] = useState([]);
	const [isLoading, setIsLoading] = useState(true);

	const fetchRevisions = useCallback(async () => {
		if (!postId) {
			setIsLoading(false);
			return;
		}
		try {
			const data = await apiFetch({
				path: `/${REST_NAMESPACE}/public-revisions/${postId}`,
			});
			setRevisions(data);
		} catch {
			setRevisions([]);
		} finally {
			setIsLoading(false);
		}
	}, [postId]);

	useEffect(() => {
		fetchRevisions();
	}, [fetchRevisions]);

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title="Settings">
					<ToggleControl
						__nextHasNoMarginBottom
						label="Show dates"
						checked={showDates}
						onChange={(value) =>
							setAttributes({ showDates: value })
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div {...blockProps}>
				{isLoading && <Spinner />}
				{!isLoading && revisions.length === 0 && (
					<p
						style={{
							color: '#757575',
							fontStyle: 'italic',
							padding: '16px',
							border: '1px dashed #ccc',
							textAlign: 'center',
						}}
					>
						No public revisions. Mark revisions as public in the
						Revisions sidebar panel.
					</p>
				)}
				{!isLoading && revisions.length > 0 && (
					<ul
						style={{
							listStyle: 'none',
							margin: 0,
							padding: 0,
						}}
					>
						{revisions.map((revision) => (
							<li
								key={revision.revision_id}
								style={{
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									padding: '8px 0',
									borderBottom: '1px solid #e0e0e0',
								}}
							>
								<span>
									<strong>
										Version{' '}
										{revision.version.toUpperCase()}
									</strong>
								</span>
								{showDates && (
									<span
										style={{
											color: '#757575',
											fontSize: '0.9em',
										}}
									>
										{revision.date_display}
									</span>
								)}
							</li>
						))}
					</ul>
				)}
			</div>
		</>
	);
}

export default Edit;
