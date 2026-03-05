<?php
// This file is generated. Do not modify it manually.
return array(
	'revision-list' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-revisions/list',
		'version' => '1.0.0',
		'title' => 'Revision List',
		'category' => 'theme',
		'description' => 'Displays a list of all public revisions for the current post with version letters, dates, and links.',
		'attributes' => array(
			'showDates' => array(
				'type' => 'boolean',
				'default' => true
			)
		),
		'supports' => array(
			'anchor' => true,
			'html' => false,
			'color' => array(
				'background' => true,
				'text' => true,
				'link' => true
			),
			'spacing' => array(
				'margin' => true,
				'padding' => true,
				'blockGap' => true
			),
			'typography' => array(
				'fontSize' => true,
				'lineHeight' => true,
				'__experimentalFontFamily' => true
			)
		),
		'usesContext' => array(
			'postId',
			'postType'
		),
		'textdomain' => 'prc-revisions',
		'editorScript' => 'file:./index.js'
	)
);
