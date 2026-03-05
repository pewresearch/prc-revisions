/**
 * Fork/Merge workflow tests for prc-revisions.
 *
 * Covers:
 * - POST /prc-revisions/v1/fork/{post_id} — creating forks
 * - GET /prc-revisions/v1/fork-info/{post_id} — fork status queries
 * - Fork content duplication (title, content, excerpt)
 * - One-fork-at-a-time guard
 * - Fork meta fields on parent and fork posts
 * - Error handling for non-published posts
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

test.describe('Fork REST API', () => {
	let parentPostId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Fork Test Parent Post',
			content: 'Parent post content for fork testing.',
			excerpt: 'Parent excerpt.',
			status: 'publish',
		});
		parentPostId = post.id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (parentPostId) {
			// Clean up any active fork first.
			const parentMeta = await requestUtils.rest({
				method: 'GET',
				path: `/wp/v2/posts/${parentPostId}`,
			});
			const activeForkId = parentMeta?.meta?._prc_active_fork;
			if (activeForkId) {
				try {
					await deletePost(requestUtils, activeForkId);
				} catch {
					// Fork may already be cleaned up.
				}
			}
			await deletePost(requestUtils, parentPostId);
		}
	});

	test('POST fork creates a draft copy of a published post', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/fork/${parentPostId}`,
		});

		expect(response).toHaveProperty('fork_id');
		expect(typeof response.fork_id).toBe('number');
		expect(response).toHaveProperty('edit_url');

		// Verify the fork is a draft with the parent's content.
		const fork = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${response.fork_id}`,
		});

		expect(fork.status).toBe('draft');
		expect(fork.title.rendered).toBe('Fork Test Parent Post');
		expect(fork.content.rendered).toContain(
			'Parent post content for fork testing.'
		);
	});

	test('POST fork sets correct meta on fork and parent', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/fork/${parentPostId}`,
		});

		const forkId = response.fork_id;

		// Check fork meta.
		const fork = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${forkId}`,
		});
		expect(fork.meta._prc_fork_parent).toBe(parentPostId);
		expect(fork.meta._prc_fork_status).toBe('draft');

		// Check parent meta.
		const parent = await requestUtils.rest({
			method: 'GET',
			path: `/wp/v2/posts/${parentPostId}`,
		});
		expect(parent.meta._prc_active_fork).toBe(forkId);
	});

	test('POST fork prevents creating a second fork (one-at-a-time guard)', async ({
		requestUtils,
	}) => {
		await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/fork/${parentPostId}`,
		});

		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parentPostId}`,
			});
			expect(true).toBe(false);
		} catch (error: any) {
			expect(error.code).toBe('fork_exists');
		}
	});

	test('POST fork on a draft post returns error', async ({
		requestUtils,
	}) => {
		const draftPost = await requestUtils.createPost({
			title: 'Draft Post',
			status: 'draft',
		});

		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${draftPost.id}`,
			});
			expect(true).toBe(false);
		} catch (error: any) {
			expect(error.code).toBe('not_published');
		} finally {
			await deletePost(requestUtils, draftPost.id);
		}
	});

	test('POST fork on nonexistent post returns error', async ({
		requestUtils,
	}) => {
		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/999999`,
			});
			expect(true).toBe(false);
		} catch (error: any) {
			expect(error.code).toBeTruthy();
		}
	});
});

test.describe('Fork Info REST API', () => {
	let parentPostId: number;
	let forkId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Fork Info Test Parent',
			content: 'Content for fork info tests.',
			status: 'publish',
		});
		parentPostId = post.id;

		const forkResponse = await requestUtils.rest({
			method: 'POST',
			path: `/prc-revisions/v1/fork/${parentPostId}`,
		});
		forkId = forkResponse.fork_id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (forkId) {
			try {
				await deletePost(requestUtils, forkId);
			} catch {
				// May already be trashed/deleted.
			}
		}
		if (parentPostId) {
			await deletePost(requestUtils, parentPostId);
		}
	});

	test('GET fork-info on parent returns role=parent with fork details', async ({
		requestUtils,
	}) => {
		const info = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/fork-info/${parentPostId}`,
		});

		expect(info.role).toBe('parent');
		expect(info.fork_id).toBe(forkId);
		expect(info.fork_status).toBe('draft');
		expect(info).toHaveProperty('fork_edit_url');
	});

	test('GET fork-info on fork returns role=fork with parent details', async ({
		requestUtils,
	}) => {
		const info = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/fork-info/${forkId}`,
		});

		expect(info.role).toBe('fork');
		expect(info.parent_id).toBe(parentPostId);
		expect(info.fork_status).toBe('draft');
		expect(info).toHaveProperty('parent_title');
		expect(info).toHaveProperty('parent_edit_url');
	});

	test('GET fork-info on unrelated post returns role=none', async ({
		requestUtils,
	}) => {
		const unrelatedPost = await requestUtils.createPost({
			title: 'Unrelated Post',
			status: 'publish',
		});

		try {
			const info = await requestUtils.rest({
				method: 'GET',
				path: `/prc-revisions/v1/fork-info/${unrelatedPost.id}`,
			});
			expect(info.role).toBe('none');
		} finally {
			await deletePost(requestUtils, unrelatedPost.id);
		}
	});

	test('GET fork-info on parent returns role=none after fork is deleted', async ({
		requestUtils,
	}) => {
		await deletePost(requestUtils, forkId);
		forkId = 0;

		const info = await requestUtils.rest({
			method: 'GET',
			path: `/prc-revisions/v1/fork-info/${parentPostId}`,
		});

		expect(info.role).toBe('none');
	});
});

test.describe('Fork content duplication', () => {
	test('fork copies parent title, content, and excerpt', async ({
		requestUtils,
	}) => {
		const parent = await requestUtils.createPost({
			title: 'Duplication Test Title',
			content: '<!-- wp:paragraph --><p>Unique duplication content.</p><!-- /wp:paragraph -->',
			excerpt: 'Unique duplication excerpt.',
			status: 'publish',
		});

		let forkId: number | undefined;
		try {
			const response = await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parent.id}`,
			});
			forkId = response.fork_id;

			const fork = await requestUtils.rest({
				method: 'GET',
				path: `/wp/v2/posts/${forkId}`,
			});

			expect(fork.title.rendered).toBe('Duplication Test Title');
			expect(fork.content.raw).toContain('Unique duplication content.');
			expect(fork.excerpt.raw).toBe('Unique duplication excerpt.');
		} finally {
			if (forkId) {
				await deletePost(requestUtils, forkId);
			}
			await deletePost(requestUtils, parent.id);
		}
	});

	test('fork allows a new fork after first fork is deleted', async ({
		requestUtils,
	}) => {
		const parent = await requestUtils.createPost({
			title: 'Re-fork Test',
			content: 'Content for re-fork test.',
			status: 'publish',
		});

		let firstForkId: number | undefined;
		let secondForkId: number | undefined;
		try {
			const first = await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parent.id}`,
			});
			firstForkId = first.fork_id;

			// Delete the first fork.
			await deletePost(requestUtils, firstForkId);
			firstForkId = undefined;

			// Creating a second fork should now succeed.
			const second = await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parent.id}`,
			});
			secondForkId = second.fork_id;

			expect(secondForkId).toBeTruthy();
			expect(typeof secondForkId).toBe('number');
		} finally {
			if (firstForkId) {
				await deletePost(requestUtils, firstForkId);
			}
			if (secondForkId) {
				await deletePost(requestUtils, secondForkId);
			}
			await deletePost(requestUtils, parent.id);
		}
	});
});
