<?php
/**
 * Render callback for the prc-revisions/list block.
 *
 * @package PRC\Platform\Revisions
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block content.
 * @param WP_Block $block      Block instance.
 * @return string Rendered block HTML.
 */

namespace PRC\Platform\Revisions;

$list_post_id = $block->context['postId'] ?? get_the_ID();
$show_dates   = $attributes['showDates'] ?? true;

$public_revisions = Public_Revisions::get_public_revisions( $list_post_id );

if ( empty( $public_revisions ) ) {
	return '';
}

$parent_url = get_permalink( $list_post_id );
$list_items = '';

foreach ( $public_revisions as $entry ) {
	if ( ! empty( $entry['orphaned'] ) ) {
		continue;
	}
	$revision = get_post( $entry['revision_id'] );
	if ( ! Public_Revisions::revision_belongs_to_post( $revision, (int) $list_post_id ) ) {
		continue;
	}

	$version_letter = strtoupper( $entry['version'] );
	$version_url    = trailingslashit( $parent_url ) . Rewrite::ENDPOINT . '/' . $entry['version'];
	$date_display   = get_the_date( '', $revision );

	$date_html = '';
	if ( $show_dates ) {
		$date_html = wp_sprintf(
			'<span class="wp-block-prc-revisions-list__date">%s</span>',
			esc_html( $date_display )
		);
	}

	$list_items .= wp_sprintf(
		'<li class="wp-block-prc-revisions-list__item"><a href="%1$s"><span class="wp-block-prc-revisions-list__version">Version %2$s</span>%3$s</a></li>',
		esc_url( $version_url ),
		esc_html( $version_letter ),
		$date_html
	);
}

$block_attrs = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-prc-revisions-list',
	)
);

echo wp_sprintf(
	'<ul %1$s>%2$s</ul>',
	$block_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is core-escaped.
	wp_kses_post( $list_items )
);
