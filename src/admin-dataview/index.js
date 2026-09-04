/**
 * WordPress Dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { backup } from '@wordpress/icons';

/**
 * PRC Dependencies
 */
import { StatusDotBadge, STATUS_DOT_COLORS } from '@prc/components';

/**
 * Internal Dependencies
 */
import './style.scss';

function FutureRevisionBadge({ item }) {
	const indicator = item?.futureRevision;
	if (!indicator?.label || !indicator?.role) {
		return null;
	}

	return (
		<StatusDotBadge
			label={indicator.label}
			color={STATUS_DOT_COLORS.warning}
			icon={backup}
			className={`prc-revisions-dataview__future-revision prc-revisions-dataview__future-revision--${indicator.role}`}
		/>
	);
}

function wrapStatusRender(OriginalRender) {
	return function StatusWithFutureRevision({ item }) {
		return (
			<span className="prc-revisions-dataview__status-cell">
				{OriginalRender ? <OriginalRender item={item} /> : null}
				<FutureRevisionBadge item={item} />
			</span>
		);
	};
}

addFilter(
	'prcWpAdminDataview.fields',
	'prc-revisions/future-revision-status',
	(fields) => {
		if (!Array.isArray(fields)) {
			return fields;
		}

		return fields.map((field) => {
			if (field?.id !== 'status') {
				return field;
			}

			return {
				...field,
				render: wrapStatusRender(field.render),
			};
		});
	}
);
