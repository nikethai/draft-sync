# DraftSync REST API Reference

All endpoints are under the `gdtg/v1` namespace. Authentication: WordPress nonce via `X-WP-Nonce` header or `wp_cookie` session.

## Endpoints

### POST `/gdtg/v1/import`

Import a Google Doc or Drive file.

**Permission:** `edit_post` (if `post_id` provided) or `edit_posts` (new post).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `doc_id` | string | yes | — | Google Doc URL, Drive URL, or raw document ID |
| `post_id` | int | no | 0 | Target post ID (0 = create new) |
| `import_images` | bool | no | true | Download and sideload images |
| `import_tables` | bool | no | true | Convert tables to Gutenberg table blocks |
| `overwrite` | bool | no | false | Replace existing post content |
| `import_as_draft` | bool | no | false | Save as draft |
| `output_mode` | string | no | `gutenberg` | `gutenberg` or `classic` |
| `optimize_images` | bool | no | from option | Compress images via SaaS bridge |
| `heading_demotion` | int | no | 0 | Shift headings down N levels (0–5) |
| `min_heading_level` | int | no | 1 | Minimum heading level (1–6) |
| `default_alignment` | string | no | — | `left`, `center`, or `right` |
| `post_meta` | object | no | — | Metadata object (slug, excerpt, seo, categories, tags, featured_image, acf, meta) |
| `seo_title` | string | no | — | Yoast/RankMath SEO title |
| `seo_description` | string | no | — | Yoast/RankMath SEO description |
| `focus_keyword` | string | no | — | Yoast/RankMath focus keyword |
| `canonical_url` | string | no | — | Yoast/RankMath canonical URL |
| `categories` | string/array | no | — | Comma-separated string or array |
| `tags` | string/array | no | — | Comma-separated string or array |
| `featured_image` | string | no | — | `first`, index number, or filename |
| `slug` | string | no | — | URL slug |

**Response 200:**
```json
{ "success": true, "post_id": 42, "title": "Doc Title", "edit_url": "...", "message": "..." }
```

**Response 200 (batch):**
```json
{ "success": true, "batch": true, "job_id": "abc123", "image_count": 5 }
```

---

### POST `/gdtg/v1/upload-docx`

Upload a `.docx` file for import.

**Permission:** `edit_post` or `edit_posts`.

Multipart form data: `file` (required), plus same optional parameters as `/import`.

---

### GET `/gdtg/v1/import/{job_id}/status`

Check batch import job status.

**Permission:** Job owner (verified by `check_job_permissions`).

**Response 200:**
```json
{ "success": true, "status": "pending|complete|error", "image_done": 3, "image_total": 5, "post_id": 42, "edit_url": "..." }
```

---

### POST `/gdtg/v1/import/{job_id}/continue`

Continue processing a batch import job.

**Permission:** Job owner.

---

### POST `/gdtg/v1/import-bulk`

Import up to 100 rows in one request.

**Permission:** `edit_posts`.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `rows` | array | yes | — | Array of import row objects (max 100) |
| `dry_run` | bool | no | false | Validate without importing |

**Response 200:**
```json
{ "success": true, "summary": { "success": 2, "failed": 1 }, "results": [...] }
```

---

### POST `/gdtg/v1/sync/{post_id}`

Re-sync a linked post from its source.

**Permission:** `edit_post` for target post.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `post_id` | int | yes | — | Target post ID |
| `force` | bool | no | false | Force re-import even on conflict |

---

### GET `/gdtg/v1/sync/status`

Get sync status for a post or list all linked posts.

**Permission:** `edit_posts`.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `post_id` | int | no | — | Single post status (omit for list) |

**Response 200 (single):**
```json
{
  "post_id": 42, "source_type": "gdoc", "source_id": "abc123",
  "source_name": "My Doc", "auto_sync": true,
  "last_imported_at": "2026-06-02 12:00:00", "last_sync_status": "success",
  "sync_progress": 0,
  "syncable": true
}
```

`sync_progress` — `0` when idle; `55`–`95` during streamed oversized imports.

**Response 200 (list):**
```json
{ "posts": [...], "total": 5 }
```

---

### POST `/gdtg/v1/sync/settings/{post_id}`

Update per-post sync settings.

**Permission:** `edit_post` for target post.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `post_id` | int | yes | — | Target post ID |
| `auto_sync` | bool | no | — | Enable/disable auto-sync |

---

### POST `/gdtg/v1/sync/run`

Trigger manual sync run (admin only).

**Permission:** `manage_options`.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `limit` | int | no | 10 | Max posts to process |
| `force` | bool | no | false | Force re-import on conflict |
| `dry_run` | bool | no | false | Report without importing |

**Response 200:**
```json
{ "checked": 5, "synced": 3, "skipped": 1, "conflicts": 1, "failed": 0, "dry_run": false, "details": [...] }
```
