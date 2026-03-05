/**
 * REST API tests for prc-revisions.
 *
 * Covers:
 * - GET /prc-revisions/v1/public-revisions/{post_id}
 * - POST /prc-revisions/v1/toggle/{post_id}/{revision_id}
 * - Revision version letter assignment (a, b, c, ...)
 * - Toggle remove behavior
 * - Error handling for invalid revision IDs and mismatched parent
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

async function deletePost(
	requestUtils: RequestUtils,
	postId: number
): Promise<void> {
	await requestUtils.rest({
		method: 'DELETE',
		path: `/wp/v2/posts/${postId}`,
		params: { force: true },
	});
}

/**
 * Create a published post and generate revisions by updating its content.
 * Returns the post ID and an array of revision IDs (newest first).
 */
async function createPostWithRevisions(
	requestUtils: RequestUtils,
	revisionCount: number = 2
): Promise<{ postId: number; revisionIds: number[] }> {
	const post = await requestUtils.createPost({
		title: 'Public Revisions Test Post',
		content: 'Original content.',
		status: 'publish',
	});

	for (let i = 1; i <= revisionCount; i++) {
		await requestUtils.rest({
			method: 'POST',
			path: `/wp/v2/posts/${post.id}`,
			data: { content: `Revision ${i} content.` },
		});
	}

	const revisions = await requestUtils.rest({
		method: 'GET',
		path: `/wp/v2/posts/${post.id}/revisions`,
	});

	const revisionIds = revisions.map((r: any) => r.id);

	return { postId: post.id, revisionIds };
}

test.describe('Public Revisions REST API', () => {
	let postId: number;
	let revisionIds: number[];

	test.beforeEach(async ({ requestUtils }) => {
		const result = await createPostWithRevisions(requestUtils, 3);
		postId = result.postId;
		revisionIds = result.revisionIds;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (postId) {
			await deletePost(requestUtils, postId);
		}
	});

	test('GET public-revisions returns empty array for post with no public revisions', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/public-revisions/${postId}`,
		});

		expect(Array.isArray(response)).toBe(true);
		expect(response).toHaveLength(0);
	});

	test('POST toggle marks a revision as public and assigns version letter "a"', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});

		expect(response.action).toBe('added');
		expect(response.version).toBe('a');
		expect(response.url).toContain('/version/a');
	});

	test('POST toggle assigns sequential version letters', async ({
		requestUtils,
	}) => {
		const first = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});
		expect(first.version).toBe('a');

		const second = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[1]}`,
		});
		expect(second.version).toBe('b');

		const third = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[2]}`,
		});
		expect(third.version).toBe('c');
	});

	test('POST toggle removes a public revision when toggled again', async ({
		requestUtils,
	}) => {
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});

		const removeResponse = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});

		expect(removeResponse.action).toBe('removed');
		expect(removeResponse.version).toBe('a');
	});

	test('GET public-revisions returns correct data after toggling', async ({
		requestUtils,
	}) => {
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[1]}`,
		});

		const response = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/public-revisions/${postId}`,
		});

		expect(response).toHaveLength(2);
		expect(response[0]).toHaveProperty('version', 'a');
		expect(response[0]).toHaveProperty('revision_id', revisionIds[0]);
		expect(response[0]).toHaveProperty('url');
		expect(response[0]).toHaveProperty('date');
		expect(response[0]).toHaveProperty('date_display');
		expect(response[0]).toHaveProperty('author');
		expect(response[1]).toHaveProperty('version', 'b');
		expect(response[1]).toHaveProperty('revision_id', revisionIds[1]);
	});

	test('GET public-revisions reflects removal after toggle off', async ({
		requestUtils,
	}) => {
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[1]}`,
		});

		// Remove the first one.
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionIds[0]}`,
		});

		const response = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/public-revisions/${postId}`,
		});

		expect(response).toHaveLength(1);
		expect(response[0].version).toBe('b');
	});

	test('POST toggle with invalid revision ID returns error', async ({
		requestUtils,
	}) => {
		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/toggle/${postId}/999999`,
			});
			expect(true).toBe(false);
		} catch (error: any) {
			expect(error.code).toBe('invalid_revision');
		}
	});

	test('POST toggle with revision from different post returns error', async ({
		requestUtils,
	}) => {
		const otherResult = await createPostWithRevisions(requestUtils, 1);
		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/toggle/${postId}/${otherResult.revisionIds[0]}`,
			});
			expect(true).toBe(false);
		} catch (error: any) {
			expect(error.code).toBe('revision_mismatch');
		} finally {
			await deletePost(requestUtils, otherResult.postId);
		}
	});
});

test.describe('Public Revisions post meta via REST', () => {
	let postId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Meta Test Post',
			content: 'Initial content.',
			status: 'publish',
		});
		postId = post.id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (postId) {
			await deletePost(requestUtils, postId);
		}
	});

	test('_prc_public_revisions meta is exposed in REST API', async ({
		requestUtils,
	}) => {
		const post = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${postId}`,
		});

		expect(post.meta).toHaveProperty('_prc_public_revisions');
		expect(Array.isArray(post.meta._prc_public_revisions)).toBe(true);
	});

	test('_prc_public_revisions meta updates after toggle', async ({
		requestUtils,
	}) => {
		// Create a revision.
		await requestUtils.rest({
			method: 'POST',
			path: `/wp/v2/posts/${postId}`,
			data: { content: 'Updated content for revision.' },
		});

		const revisions = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${postId}/revisions`,
		});
		const revisionId = revisions[0].id;

		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/toggle/${postId}/${revisionId}`,
		});

		const post = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${postId}`,
		});

		const publicRevisions = post.meta._prc_public_revisions;
		expect(publicRevisions).toHaveLength(1);
		expect(publicRevisions[0]).toHaveProperty('version', 'a');
		expect(publicRevisions[0]).toHaveProperty('revision_id', revisionId);
	});

	test('fork meta fields are exposed in REST API', async ({
		requestUtils,
	}) => {
		const post = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${postId}`,
		});

		expect(post.meta).toHaveProperty('_prc_fork_parent');
		expect(post.meta).toHaveProperty('_prc_fork_status');
		expect(post.meta).toHaveProperty('_prc_active_fork');
	});
});
