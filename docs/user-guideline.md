# DraftSync — User Guideline

Complete guide to importing documents into WordPress using DraftSync.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Google Doc Import](#google-doc-import)
3. [Word Document (.docx) Import](#word-document-docx-import)
4. [Google Drive .docx Import](#google-drive-docx-import)
5. [Import Options](#import-options)
6. [Style Overrides](#style-overrides)
7. [Publishing Metadata](#publishing-metadata)
8. [Bulk Import](#bulk-import)
9. [Linked Re-Sync](#linked-re-sync)
10. [WP-CLI Commands](#wp-cli-commands)
11. [Classic Editor Support](#classic-editor-support)
12. [Troubleshooting](#troubleshooting)

---

## Getting Started

### Installation

1. Upload the `draftsync` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Navigate to **DraftSync** in the WordPress admin sidebar.
4. Click **Connect with Google** to authorize access to your Google Docs.

No API keys or technical setup required — DraftSync handles authentication through its free SaaS bridge.

### Admin Settings

The admin area provides:

- **Settings** — Connect your Google account, configure connection mode, set global defaults.
- **Imported Docs** — View and manage all posts imported through DraftSync. See source document links, re-sync status, and trigger manual re-syncs.
- **Live Import** — Import documents directly from the admin dashboard without opening the editor.

### Requirements

- WordPress 5.0+ with Gutenberg editor (Classic Editor also supported)
- PHP 7.4+
- A Google account with access to the documents you want to import

---

## Google Doc Import

### Via the Gutenberg Sidebar

1. Open or create a new post/page in the Gutenberg editor.
2. Click the **DraftSync** cloud icon in the top-right toolbar to open the sidebar.
3. Paste a Google Doc URL into the **Document URL** field:
   ```
   https://docs.google.com/document/d/DOCUMENT_ID/edit
   ```
4. Configure import options (see [Import Options](#import-options)).
5. Click **Import**.
6. Wait for the import to complete. A success message with a link to the post will appear.

### What Gets Imported

| Element | Gutenberg Block |
|---|---|
| Paragraphs | `core/paragraph` |
| Headings (H1–H6) | `core/heading` |
| Images | `core/image` (sideloaded to Media Library) |
| Ordered lists | `core/list` (ordered) |
| Unordered lists | `core/list` (unordered) |
| Nested lists | `core/list` (preserves nesting) |
| Tables | `core/table` |
| Blockquotes | `core/quote` |
| Horizontal rules | `core/separator` |
| Page breaks | `core/nextpage` |

### Inline Styles Preserved

- **Bold**, *italic*, ~~strikethrough~~, underline
- Text color
- Subscript and superscript
- Links (preserves URL and target)

---

## Word Document (.docx) Import

### Via the Gutenberg Sidebar

1. Open or create a new post/page in the Gutenberg editor.
2. Open the **DraftSync** sidebar.
3. Switch to **Upload .docx** mode using the toggle at the top.
4. Drag and drop a `.docx` file onto the upload area, or click to browse.
5. Configure import options.
6. Click **Import**.

### Via the REST API

Send a multipart POST request:

```bash
curl -X POST https://yoursite.com/wp-json/gdtg/v1/upload-docx \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -F "file=@/path/to/document.docx" \
  -F "options={\"import_images\":true,\"import_as_draft\":true}"
```

### Security

All `.docx` files pass through DraftSync's ZIP security validator:
- Magic byte verification (validates the file is a real ZIP/DOCX)
- Path traversal prevention
- Nested ZIP detection
- Entry count limits
- Compression ratio checks (prevents zip bombs)

If a file fails validation, the import is rejected with a clear error message.

---

## Google Drive .docx Import

Import `.docx` files stored in Google Drive without downloading them first:

1. Open the DraftSync sidebar.
2. Paste a Google Drive file URL:
   ```
   https://drive.google.com/file/d/FILE_ID/view
   ```
3. Configure import options.
4. Click **Import**.

DraftSync downloads the file from Drive and processes it through the `.docx` parser.

---

## Import Options

Toggle these options in the sidebar before importing:

| Option | Default | Description |
|---|---|---|
| **Import images** | On | Download and sideload images to the WordPress Media Library. Turn off to skip all images. |
| **Optimize images** | On | Compress and resize images during import. Reduces file size without visible quality loss. |
| **Import tables** | On | Convert document tables to Gutenberg table blocks. Turn off to skip tables. |
| **Overwrite existing** | Off | Replace the current post content. When off, imported content is appended. |
| **Import as draft** | On (sidebar), Off (REST/CLI) | Save the post as a draft. When off, the post is published immediately. |
---

## Style Overrides

Control how imported content is formatted. Set these in the sidebar's **Style Overrides** panel before importing.

### Heading Demotion

Shift all heading levels down by N positions. Useful when your document uses H1 for titles but your WordPress theme reserves H1 for the post title.

| Value | Effect |
|---|---|
| 0 | No change (default) |
| 1 | H1→H2, H2→H3, H3→H4, etc. |
| 2 | H1→H3, H2→H4, H3→H5, etc. |

### Minimum Heading Level

Prevent headings above a certain level from appearing. For example, setting minimum to `2` ensures no H1 tags appear in the imported content.

| Value | Effect |
|---|---|
| 1 | Allow all headings (default) |
| 2 | No H1 in body content |
| 3 | No H1 or H2 in body content |

### Default Text Alignment

Force all paragraph text to a specific alignment:

| Value | Effect |
|---|---|
| (none) | Use document's original alignment (default) |
| Left | Force all paragraphs left-aligned |
| Center | Force all paragraphs center-aligned |
| Right | Force all paragraphs right-aligned |

---

## Publishing Metadata

DraftSync can set post metadata during import. You can provide metadata in two ways:

### Metadata Table in the Document

Add a table at the very beginning of your Google Doc with metadata fields:

| Field | Value |
|---|---|
| slug | my-custom-url-slug |
| excerpt | A short summary for search engines and social sharing. |
| seo_title | Custom SEO Title — Site Name |
| seo_description | SEO meta description for search results. |
| canonical | https://example.com/original-url |
| categories | Technology, Tutorials |
| tags | wordpress, gutenberg, import |
| featured_image | https://example.com/hero-image.jpg |

DraftSync extracts this table, applies the values to the post, and removes the table from the imported content.

### Supported Metadata Fields

| Field | Description |
|---|---|
| `slug` | URL slug for the post |
| `excerpt` | Post excerpt |
| `seo_title` | SEO title (Yoast / RankMath) |
| `seo_description` | SEO meta description (Yoast / RankMath) |
| `seo_focus_keyword` | SEO focus keyword (Yoast / RankMath) |
| `canonical` | Canonical URL |
| `categories` | Comma-separated category names or IDs |
| `tags` | Comma-separated tag names |
| `featured_image` | URL of the featured image (downloaded and attached) |
| `acf_*` | ACF field values (e.g., `acf_subtitle`) |

### Via Sidebar Controls

The sidebar includes a **Publishing Metadata** panel where you can enter:

- Custom slug
- Excerpt
- SEO title and description
- Categories and tags
- Featured image URL

These values override any metadata table found in the document.

---

## Bulk Import

Import multiple documents in a single operation.

### Via the Sidebar

1. Open the DraftSync sidebar.
2. Switch to **Bulk Import** mode.
3. Add rows with document URLs and optional per-row settings.
4. Click **Import All**.

### Via WP-CLI

```bash
wp draftsync import-bulk /path/to/imports.json --user=1
```

### Via the REST API

```bash
curl -X POST https://yoursite.com/wp-json/gdtg/v1/import-bulk \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "rows": [
      {"source": "https://docs.google.com/document/d/ABC123/edit"},
      {"source": "https://docs.google.com/document/d/DEF456/edit"}
    ],
    "dry_run": false
  }'
```

### Dry Run

Add `"dry_run": true` (REST) or `--dry_run` (CLI) to validate all rows without actually importing. Reports which rows would succeed or fail.

### Limits

- Maximum 100 rows per bulk request
- Each row can have its own metadata, options, and style overrides

---

## Linked Re-Sync

After importing a document, DraftSync maintains a link between the WordPress post and the source document. You can re-sync to pull in updates.

### Via the Sidebar

1. Open a post that was previously imported with DraftSync.
2. The sidebar shows the linked source document URL.
3. Click **Re-Sync** to pull the latest content.

### Via WP-CLI

```bash
# Re-sync a specific post
wp draftsync sync 123 --user=1

# Force re-sync even if content hasn't changed
wp draftsync sync 123 --user=1 --force

# Re-sync all linked posts
wp draftsync sync-all --user=1
```

### Conflict Protection

DraftSync tracks a content hash of the last import. If the post has been manually edited since the last import, re-sync will:

1. Detect the conflict.
2. Refuse to overwrite by default.
3. Allow forced override with `--force` (CLI) or the force toggle (sidebar).

This prevents accidentally overwriting manual edits.


---

## Imported Docs Manager

The **Imported Docs** admin page (under **DraftSync → Imported Docs**) provides a central view of all posts imported through DraftSync.

### Viewing Imported Documents

The table lists every post with a DraftSync import source:

| Column | Description |
|---|---|
| **Post Title** | The imported post title (links to editor) |
| **Source Type** | `gdoc` (Google Docs) or `drive_file` (Google Drive .docx) |
| **Source URL** | Truncated link to the original document |
| **Last Imported** | Date of the most recent import |
| **Auto-Sync** | Whether automatic re-sync is enabled for this post |
| **Actions** | Re-sync button, View post link |

### Manual Re-Sync

Click the **Re-Sync** button on any row to pull the latest content from the source document. Conflict protection applies — if the post has been manually edited, re-sync will be blocked unless forced.

### Bulk Operations

Select multiple posts and choose **Enable Auto-Sync** or **Disable Auto-Sync** from the bulk actions dropdown to manage auto-sync status in batch.
---

## WP-CLI Commands

DraftSync provides full WP-CLI support for developers and agencies.

### Import a Google Doc

```bash
wp draftsync import https://docs.google.com/document/d/DOCUMENT_ID/edit --user=1
```

**Options:**

| Flag | Description |
|---|---|
| `--post_id=N` | Import into an existing post |
| `--post_type=post\|page` | Create as post or page (default: post) |
| `--draft` | Save as draft (default: on) |
| `--output=blocks\|html` | Output format (default: blocks) |
| `--heading-demotion=N` | Shift headings down N levels |
| `--min-heading-level=N` | Minimum heading level to allow |
| `--default-alignment=left\|center\|right` | Force text alignment |

### Import a Local .docx File

```bash
wp draftsync import-docx /path/to/document.docx --user=1
```

Supports the same options as `import`.

### Bulk Import

```bash
wp draftsync import-bulk --rows='[{"url":"..."},{"url":"..."}]' --user=1
```

**Options:**

| Flag | Description |
|---|---|
| `--dry_run` | Validate without importing |

### Re-Sync a Post

```bash
wp draftsync sync 123 --user=1
```

**Options:**

| Flag | Description |
|---|---|
| `--force` | Re-sync even if no changes detected or conflicts exist |

### Re-Sync All Linked Posts

```bash
wp draftsync sync-all --user=1
```

### Check Job Status

```bash
# Check a specific job
wp draftsync status JOB_ID

# List all recent jobs
wp draftsync status --all
```

---

## Classic Editor Support

If your site uses the Classic Editor instead of Gutenberg, DraftSync automatically detects this and outputs clean HTML instead of Gutenberg block markup.

No configuration needed — the plugin detects the active editor and adjusts output automatically.

### Force HTML Output

To explicitly request HTML output (e.g., for use with page builders):

```bash
wp draftsync import https://docs.google.com/document/d/ABC123/edit --output=html --user=1
```

---

## REST API Reference

All endpoints require WordPress authentication (nonce or application password) and appropriate capabilities.

### Import a Document

```
POST /wp-json/gdtg/v1/import
```

| Parameter | Type | Required | Description |
|---|---|---|---|
| `doc_id` | string | Yes | Google Doc URL, Drive .docx URL, or raw document ID |
| `post_id` | int | No | Target post ID (omit to create new) |
| `options` | object | No | Import options (see below) |

**Options object:**

```json
{
  "import_images": true,
  "optimize_images": true,
  "overwrite_existing": false,
  "import_as_draft": true,
  "overrides": {
    "heading_demotion": 1,
    "min_heading_level": 2,
    "default_alignment": "left"
  }
}
```

### Upload a .docx File

```
POST /wp-json/gdtg/v1/upload-docx
```

Multipart form data with `file` field containing the `.docx`.

### Check Import Status

```
GET /wp-json/gdtg/v1/import/{job_id}/status
```

### Continue a Batched Import

```
POST /wp-json/gdtg/v1/import/{job_id}/continue
```

### Bulk Import

```
POST /wp-json/gdtg/v1/import-bulk
```

| Parameter | Type | Required | Description |
|---|---|---|---|
| `rows` | array | Yes | Array of import row objects (max 100) |
| `dry_run` | bool | No | Validate only, don't import |

### Re-Sync

```
POST /wp-json/gdtg/v1/sync/{post_id}
```

| Parameter | Type | Required | Description |
|---|---|---|---|
| `force` | bool | No | Force re-sync even if unchanged |

---

## Troubleshooting

### Import Fails with Timeout

Large documents with many images may timeout on shared hosting. Solutions:

1. **Disable image import** — Toggle off "Import images" in the sidebar.
2. **Use WP-CLI** — CLI imports have longer timeout limits:
   ```bash
   wp draftsync import https://docs.google.com/document/d/ABC123/edit --user=1
   ```
3. **Batch import** — Split into smaller documents or use bulk import with fewer rows per batch.

### Images Not Appearing

- Ensure "Import images" is toggled on.
- Check that the Google Doc is shared (at least "Anyone with the link").
- Verify your server allows outbound HTTP requests to `googleusercontent.com`.

### Formatting Looks Wrong

- Use **style overrides** to adjust heading levels and alignment.
- Check the original document — DraftSync faithfully reproduces the document structure. If the source has inconsistent formatting, the output will reflect that.

### Permission Errors

- Ensure your WordPress user has the `edit_posts` capability.
- When importing into a specific post, you need `edit_post` capability for that post.
- For CLI commands, pass `--user=1` (or the appropriate user ID).

### "Failed to Fetch Document" Error

- Verify the Google Doc URL is correct and accessible.
- Ensure DraftSync has been connected to your Google account (check **DraftSync → Settings**).
- Try disconnecting and reconnecting your Google account.

### Re-Sync Reports Conflict

This means the post was manually edited since the last import. Options:

1. **Review changes** — Compare the current post content with the source document.
2. **Force re-sync** — Use `--force` flag or the force toggle to overwrite.
3. **Keep manual edits** — Do nothing; the post remains as-is.
