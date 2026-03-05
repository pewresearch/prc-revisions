/**
 * Editor Panel tests for prc-revisions.
 *
 * Verifies:
 * - The "Revisions" PluginSidebar is registered and accessible.
 * - The panel contains "Public Versions" and "All Revisions" sections.
 * - The "Future Revisions" section appears for published posts.
 * - The "Create Future Revision" button is present on published posts.
 * - Empty state messaging is displayed when no revisions exist.
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
 * Open the "Revisions" plugin sidebar in the post editor.
 */
async function openRevisionsSidebar(page: any): Promise<void> {
	const sidebarButton = page.getByRole('button', {
		name: 'Revisions',
		exact: false,
	});

	if (await sidebarButton.first().isVisible()) {
		await sidebarButton.first().click();
		return;
	}

	const moreMenuButton = page.getByRole('button', {
		name: 'Options',
		exact: false,
	});
	if (await moreMenuButton.isVisible()) {
		await moreMenuButton.click();
		const pluginMenuItem = page.getByRole('menuitemcheckbox', {
			name: 'Revisions',
			exact: false,
		});
		if (await pluginMenuItem.isVisible()) {
			await pluginMenuItem.click();
		}
	}
}

test.describe('Editor Revisions Sidebar Panel', () => {
	test('the Revisions sidebar plugin is registered and visible', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();

		await openRevisionsSidebar(page);

		const sidebarHeading = page.getByRole('heading', {
			name: 'Revisions',
		});
		await expect(sidebarHeading).toBeVisible();
	});

	test('the panel shows "Public Versions" section', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();
		await openRevisionsSidebar(page);

		const publicVersionsPanel = page.getByRole('button', {
			name: 'Public Versions',
			exact: false,
		});
		await expect(publicVersionsPanel).toBeVisible();
	});

	test('the panel shows "All Revisions" section', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();
		await openRevisionsSidebar(page);

		const allRevisionsPanel = page.getByRole('button', {
			name: 'All Revisions',
			exact: false,
		});
		await expect(allRevisionsPanel).toBeVisible();
	});

	test('the panel shows empty state for public versions on new post', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();
		await openRevisionsSidebar(page);

		const emptyMessage = page.getByText(
			'No revisions have been made public yet',
			{ exact: false }
		);
		await expect(emptyMessage).toBeVisible({ timeout: 10000 });
	});

	test('the panel shows "Future Revisions" section on a published post', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Published Post',
			status: 'publish',
		});

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);
			await openRevisionsSidebar(page);

			const futureRevisionsPanel = page.getByRole('button', {
				name: 'Future Revisions',
				exact: false,
			});
			await expect(futureRevisionsPanel).toBeVisible({ timeout: 10000 });
		} finally {
			await deletePost(requestUtils, post.id);
		}
	});

	test('the "Create Future Revision" button appears on published posts', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Fork Button Test',
			status: 'publish',
		});

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);
			await openRevisionsSidebar(page);

			// Expand the Future Revisions panel if collapsed.
			const futureRevisionsToggle = page.getByRole('button', {
				name: 'Future Revisions',
				exact: false,
			});
			await futureRevisionsToggle.click();

			const createButton = page.getByRole('button', {
				name: 'Create Future Revision',
			});
			await expect(createButton).toBeVisible({ timeout: 10000 });
		} finally {
			await deletePost(requestUtils, post.id);
		}
	});

	test('the panel shows revision toggle controls when revisions exist', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Revisions List Test',
			content: 'Initial content.',
			status: 'publish',
		});

		// Create revisions by updating.
		await requestUtils.rest({
			method: 'POST',
			path: `/wp/v2/posts/${post.id}`,
			data: { content: 'First update.' },
		});
		await requestUtils.rest({
			method: 'POST',
			path: `/wp/v2/posts/${post.id}`,
			data: { content: 'Second update.' },
		});

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);
			await openRevisionsSidebar(page);

			// Expand "All Revisions" panel.
			const allRevisionsToggle = page.getByRole('button', {
				name: 'All Revisions',
				exact: false,
			});
			await allRevisionsToggle.click();

			// Wait for revisions to load; look for "Public" toggle labels.
			const publicToggle = page.getByLabel('Public');
			await expect(publicToggle.first()).toBeVisible({ timeout: 15000 });
		} finally {
			await deletePost(requestUtils, post.id);
		}
	});

	test('the panel displays fork notice when editing a fork', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const parentPost = await requestUtils.createPost({
			title: 'Fork Notice Parent',
			content: 'Parent content.',
			status: 'publish',
		});

		let forkId: number | undefined;
		try {
			const forkResponse = await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parentPost.id}`,
			});
			forkId = forkResponse.fork_id;

			await admin.visitAdminPage(
				'post.php',
				`post=${forkId}&action=edit`
			);
			await openRevisionsSidebar(page);

			const forkNotice = page.getByText('This is a future revision of', {
				exact: false,
			});
			await expect(forkNotice).toBeVisible({ timeout: 10000 });
		} finally {
			if (forkId) {
				await deletePost(requestUtils, forkId);
			}
			await deletePost(requestUtils, parentPost.id);
		}
	});

	test('the panel shows active fork info on the parent post', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const parentPost = await requestUtils.createPost({
			title: 'Active Fork Info Parent',
			content: 'Parent content.',
			status: 'publish',
		});

		let forkId: number | undefined;
		try {
			const forkResponse = await requestUtils.rest({
				method: 'POST',
				path: `/prc-revisions/v1/fork/${parentPost.id}`,
			});
			forkId = forkResponse.fork_id;

			await admin.visitAdminPage(
				'post.php',
				`post=${parentPost.id}&action=edit`
			);
			await openRevisionsSidebar(page);

			const activeForkNotice = page.getByText(
				'An active future revision exists',
				{ exact: false }
			);
			await expect(activeForkNotice).toBeVisible({ timeout: 10000 });

			const editForkLink = page.getByRole('link', {
				name: 'Edit the fork',
			});
			await expect(editForkLink).toBeVisible();
		} finally {
			if (forkId) {
				await deletePost(requestUtils, forkId);
			}
			await deletePost(requestUtils, parentPost.id);
		}
	});
});
