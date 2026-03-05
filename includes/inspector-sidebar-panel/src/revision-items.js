import {
	PanelRow,
	Button,
	ExternalLink,
	ToggleControl,
	Notice,
} from '@wordpress/components';

export function RevisionItem({ revision, onToggle, isToggling, isActive }) {
	const isOrphaned = revision.orphaned === true;

	return (
		<PanelRow>
			<div
				style={{
					display: 'flex',
					flexDirection: 'column',
					width: '100%',
					gap: '4px',
					padding: '8px',
					borderBottom: '1px solid #e0e0e0',
					borderLeft: isActive
						? '3px solid var(--wp-admin-theme-color, #3858e9)'
						: '3px solid transparent',
					backgroundColor: isActive
						? 'rgba(var(--wp-admin-theme-color--rgb, 56, 88, 233), 0.04)'
						: 'transparent',
				}}
			>
				<div
					style={{
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'center',
					}}
				>
					<strong>
						Version {revision.version?.toUpperCase() ?? '?'}
						{isActive && !isOrphaned && (
							<span
								style={{
									fontSize: '11px',
									fontWeight: 'normal',
									marginLeft: '6px',
									color: 'var(--wp-admin-theme-color, #3858e9)',
								}}
							>
								(viewing)
							</span>
						)}
					</strong>
					<Button
						isDestructive
						variant="tertiary"
						size="small"
						onClick={() => onToggle(revision.revision_id)}
						disabled={isToggling}
					>
						{isOrphaned ? 'Remove stale reference' : 'Remove'}
					</Button>
				</div>
				{isOrphaned && (
					<Notice status="warning" isDismissible={false}>
						This revision no longer exists. The versioned URL will
						404. Remove the stale reference to clean up.
					</Notice>
				)}
				<span style={{ fontSize: '12px', color: '#757575' }}>
					{revision.date_display}
				</span>
				{revision.url && (
					<ExternalLink
						href={revision.url}
						style={{ fontSize: '12px' }}
					>
						{revision.url}
					</ExternalLink>
				)}
			</div>
		</PanelRow>
	);
}

export function WPRevisionItem({
	wpRevision,
	publicRevisions,
	onToggle,
	isToggling,
	isActive,
}) {
	const isPublic = publicRevisions.some(
		(r) => r.revision_id === wpRevision.id
	);
	const publicEntry = publicRevisions.find(
		(r) => r.revision_id === wpRevision.id
	);
	const versionLabel = publicEntry
		? ` (Version ${publicEntry.version.toUpperCase()})`
		: '';

	return (
		<PanelRow>
			<div
				style={{
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					width: '100%',
					padding: '6px 8px',
					borderLeft: isActive
						? '3px solid var(--wp-admin-theme-color, #3858e9)'
						: '3px solid transparent',
					backgroundColor: isActive
						? 'rgba(var(--wp-admin-theme-color--rgb, 56, 88, 233), 0.04)'
						: 'transparent',
				}}
			>
				<div style={{ display: 'flex', flexDirection: 'column' }}>
					<span style={{ fontSize: '13px' }}>
						{wpRevision.date_display}
						{versionLabel}
						{isActive && (
							<span
								style={{
									fontSize: '11px',
									marginLeft: '6px',
									color: 'var(--wp-admin-theme-color, #3858e9)',
								}}
							>
								(viewing)
							</span>
						)}
					</span>
				</div>
				<ToggleControl
					__nextHasNoMarginBottom
					checked={isPublic}
					onChange={() => onToggle(wpRevision.id)}
					disabled={isToggling}
					label="Public"
				/>
			</div>
		</PanelRow>
	);
}
