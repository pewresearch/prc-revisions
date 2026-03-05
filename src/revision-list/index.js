/**
 * WordPress Dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal Dependencies
 */
import edit from './edit';
import metadata from './block.json';

const { name } = metadata;

registerBlockType(name, {
	...metadata,
	edit,
});
