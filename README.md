# PRC Revisions

Public revision versioning and fork/merge workflow for PRC Platform. Enables editors to mark specific WordPress revisions as publicly accessible via versioned URLs, and to create draft forks of published posts that merge back on publish.

## What it does

- Registers a `/version/{letter}` rewrite endpoint on all permalinks; visiting `/post-slug/version/a` serves the revision content in place of the current post content
- Lets editors toggle individual WordPress revisions as "public" from the block editor sidebar, assigning sequential version letters (`a`, `b`, … `z`, `aa`, …)
- Protects public revisions from deletion by WordPress's revision pruning
- Provides a "fork" workflow: duplicate a published post as a draft, edit it independently, and merge it back into the parent on publish
- Lets editors discard (trash) a pending future revision from the post status panel without merging
- Enforces one active fork per post at a time (409 on duplicate fork attempts)
- Excludes fork posts from publication listing queries (`prc_platform_pub_listing_default_args`)
- Injects `version` and `isBasedOn` properties into article structured data when viewing a versioned URL; adds `hasPart` to the parent post schema listing all public versions
- Copies attachments from a revision to the parent post on `revision_applied`
- Renders a yellow admin-bar banner on fork post previews, with optional scheduled-publish date

## Key files

| File | Purpose |
| --- | --- |
| `prc-revisions.php` | Plugin bootstrap; defines constants, registers activation/deactivation hooks |
| `includes/class-plugin.php` | Core class; wires all components via `Loader`, registers `prc-revisions` post type support on `post` |
| `includes/class-public-revisions.php` | Manages public revision meta, version URL routing, content substitution, and toggle logic |
| `includes/class-future-revisions.php` | Fork/merge workflow: create fork, merge on publish, fork banners, fork meta registration |
| `includes/class-rest-api.php` | REST endpoints for public revision reads, toggle, and fork operations |
| `includes/class-rewrite.php` | Registers the `version` rewrite endpoint via `add_rewrite_endpoint` |
| `includes/class-schema.php` | Hooks into `prc-schema-seo` filters to inject version metadata into structured data |
| `includes/class-wp-admin.php` | Registers and enqueues the block editor sidebar panel script |
| `includes/inspector-sidebar-panel/src/index.js` | Block editor plugin: "Revisions" sidebar, fork panel, pre-publish panel, post-publish redirect |
| `src/revision-list/` | `prc-revisions/list` block — renders a linked list of public versions on the frontend |

## Filters / hooks

### Actions

| Hook | Class | Description |
| --- | --- | --- |
| `init` (priority 5) | `Plugin` | Calls `add_post_type_support( 'post' \| 'page', 'prc-revisions' )` |
| `init` | `Rewrite` | Registers `version` rewrite endpoint on `EP_PERMALINK` |
| `init` | `Public_Revisions` | Registers `_prc_public_revisions` post meta (REST-exposed) |
| `init` (priority 20) | `Future_Revisions` | Registers fork meta (`_prc_fork_parent`, `_prc_fork_status`, `_prc_active_fork`) per enabled post type |
| `template_redirect` | `Public_Revisions` | Detects `/version/{letter}` query var; sets up content and title substitution filters, or 404s |
| `revision_applied` | `Public_Revisions` | Re-parents attachments from revision to published post |
| `enqueue_block_editor_assets` | `WP_Admin` | Enqueues inspector sidebar panel on `post` screens for supported post types |
| `prc_platform_on_publish` (priority 5) | `Future_Revisions` | Detects fork publish, triggers merge into parent |
| `before_delete_post` | `Future_Revisions` | Cleans up `_prc_active_fork` meta on parent when fork is deleted |
| `wp_trash_post` | `Future_Revisions` | Cleans up `_prc_active_fork` meta on parent when fork is trashed |
| `wp_body_open` | `Future_Revisions` | Renders future-revision banner for fork posts when admin bar is visible |

### Filters

| Hook | Class | Description |
| --- | --- | --- |
| `wp_prepare_revision_for_js` | `Public_Revisions` | Adds `prcIsPublic` and `prcVersionLetter` to revision data passed to the JS revisions UI |
| `pre_delete_post` | `Public_Revisions` | Returns `false` to block deletion of revisions that are marked public |
| `the_content` (priority 1, conditional) | `Public_Revisions` | Substitutes post content with revision content on versioned URL requests |
| `the_title` (priority 10, conditional) | `Public_Revisions` | Appends `(Version X)` to the post title on versioned URL requests |
| `prc_platform_pub_listing_default_args` | `Future_Revisions` | Adds a `meta_query` condition to exclude fork posts from publication listings |
| `prc_schema_seo_article_schema` | `Schema` | Adds `version` and `isBasedOn` to article schema when viewing a versioned URL |
| `prc_schema_seo_schema_data` | `Schema` | Adds `hasPart` array to parent post schema, listing all public version URLs |

## REST API endpoints

All routes are under the `prc-revisions/v1` namespace.

| Method | Route | Auth | Description |
| --- | --- | --- | --- |
| `GET` | `/prc-revisions/v1/public-revisions/{post_id}` | Public | Returns all public revisions for a post with version, date, author, URL, and orphaned flag |
| `POST` | `/prc-revisions/v1/toggle/{post_id}/{revision_id}` | `edit_post` | Toggles a revision's public status; returns `{ action, version, url }` |
| `POST` | `/prc-revisions/v1/fork/{post_id}` | `edit_post` | Creates a fork draft of a published post; returns `{ fork_id, edit_url }`. Returns 409 if an active fork already exists |
| `DELETE` | `/prc-revisions/v1/fork/{post_id}` | `edit_post` (+ `delete_post` on the fork) | Trashes a pending future revision. Accepts parent or fork ID; returns `{ trashed, fork_id, parent_id }`. Does not merge content into the parent |
| `GET` | `/prc-revisions/v1/fork-info/{post_id}` | `edit_post` | Returns fork relationship info: role (`parent`, `fork`, or `none`) and related IDs/URLs |

### Example: toggle a revision public

```bash
curl -X POST https://example.com/wp-json/prc-revisions/v1/toggle/42/789 \
  -H "X-WP-Nonce: {nonce}"
# Response: { "action": "added", "version": "a", "url": "https://example.com/my-post/version/a" }
```

### Example: create a fork

```bash
curl -X POST https://example.com/wp-json/prc-revisions/v1/fork/42 \
  -H "X-WP-Nonce: {nonce}"
# Response: { "fork_id": 99, "edit_url": "https://example.com/wp-admin/post.php?post=99&action=edit" }
```

### Example: discard (trash) a pending future revision

```bash
# Parent or fork ID both work:
curl -X DELETE https://example.com/wp-json/prc-revisions/v1/fork/42 \
  -H "X-WP-Nonce: {nonce}"
# Response: { "trashed": true, "fork_id": 99, "parent_id": 42 }
```

## Block

### `prc-revisions/list`

Renders a `<ul>` of all public revisions for a post, with linked version labels and optional dates. Intended for use in templates or post content where a version history list should appear.

**Attributes**

| Attribute | Type | Default | Description |
| --- | --- | --- | --- |
| `showDates` | `boolean` | `true` | Whether to display the revision date next to each version link |

The block reads `postId` from block context. It renders nothing if the post has no public revisions.

## Post meta

| Meta key | Stored on | Type | Description |
| --- | --- | --- | --- |
| `_prc_public_revisions` | Parent post | `array` | Array of `{ version: string, revision_id: int }` mappings |
| `_prc_fork_parent` | Fork post | `integer` | ID of the published post this fork was created from |
| `_prc_fork_status` | Fork post | `string` | Fork lifecycle state: `draft`, `pending_review`, or `merged` |
| `_prc_active_fork` | Parent post | `integer` | ID of the currently active fork; enforces one-fork-at-a-time |

## Enabling on additional post types

The plugin enables revision features on `post` and `page` by default. The chart custom post type (`chart`) opts in via `@prc/chart-builder` (`Content_Type::register_revisions_support()` on `init` priority 11).

To enable on other post types, call `add_post_type_support` from your plugin or theme:

```php
add_action( 'init', function () {
    add_post_type_support( 'my-post-type', 'prc-revisions' );
} );
```

`Plugin::get_enabled_post_types()` returns all public post types currently supporting `prc-revisions`. This list drives fork meta registration and write-permission checks.

## Version letter sequencing

Version letters are assigned automatically in alphabetical order: `a`, `b`, … `z`, `aa`, `ab`, … Removing a version letter does not reclaim it; the next assignment always increments past the highest letter currently in use.

## Fork/merge lifecycle

1. Editor calls `POST /prc-revisions/v1/fork/{post_id}` — creates a draft with `post_name` set to `{parent-slug}__fork`, copies all meta (excluding the blocklist) and taxonomy terms.
2. Editor makes changes on the fork and publishes it.
3. `prc_platform_on_publish` fires at priority 5 — `Future_Revisions::merge_fork()` copies fork meta and taxonomy to parent first, then updates parent title/content/excerpt, re-parents attachments and child posts, fires `revision_applied`, marks fork as `merged`, and trashes the fork.
4. If merge fails, the fork is reverted to `draft` status.

## Dependencies

- **PHP 8.2+**, **WordPress 6.7+**
- Requires plugin: `prc-platform-core`
- Uses `\PRC\BlockUtils\load_blocks` for block registration
- Schema integration requires `prc-schema-seo` filters (`prc_schema_seo_article_schema`, `prc_schema_seo_schema_data`) to be present; degrades silently if absent
- Fork post exclusion requires `prc_platform_pub_listing_default_args` filter to be consumed by the listing layer

## Build

```bash
# Build blocks and inspector panel
npm run build -w @prc/revisions

# Watch blocks only (inspector panel requires a separate process)
npm run start:blocks -w @prc/revisions
npm run start:inspector-panel -w @prc/revisions
```

Assets output:
- `build/revision-list/` — compiled `prc-revisions/list` block
- `includes/inspector-sidebar-panel/build/` — compiled editor sidebar panel
