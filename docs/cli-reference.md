# DraftSync WP-CLI Reference

All commands use `wp draftsync` namespace. Requires `--user=<id>` if no interactive user.

## Commands

### `wp draftsync import <url_or_id>`

Import a Google Doc or Drive file by URL or raw ID.

**Options:**
- `--post_id=<id>` — Target post to overwrite (default: create new)
- `--post_type=<type>` — Post type for new post (default: `post`)
- `--draft` — Save as draft
- `--output=<mode>` — `blocks` or `html` (default: `blocks`)
- `--heading-demotion=<n>` — Shift headings down N levels (0–5)
- `--min-heading-level=<n>` — Minimum heading level (1–6)
- `--default-alignment=<align>` — `left`, `center`, or `right`
- `--user=<id>` — WordPress user ID

**Examples:**
```bash
wp draftsync import https://docs.google.com/document/d/ABC123/edit --user=1
wp draftsync import https://docs.google.com/document/d/ABC123/edit --post_id=42 --user=1
wp draftsync import https://docs.google.com/document/d/ABC123/edit --draft --output=html --user=1
```

---

### `wp draftsync import-docx <path>`

Import a local `.docx` file.

**Options:** Same as `import` (except `--output` and `--post_id`).

**Examples:**
```bash
wp draftsync import-docx /path/to/document.docx --user=1
wp draftsync import-docx /path/to/document.docx --draft --user=1
```

---

### `wp draftsync import-bulk <file>`

Import multiple documents from a CSV or JSON file.

**Arguments:**
- `<file>` — Path to CSV (.csv) or JSON (.json) file containing import rows.

**Options:**
- `--dry_run` / `--dry-run` — Validate without importing
- `--stop-on-error` — Halt on the first failed row instead of continuing

**File formats:**

*CSV* — Header row with columns: `source`, `post_id`, `post_type`, plus any post_meta keys (slug, excerpt, seo_title, etc.).

*JSON* — Array of objects:
```json
[
  {"source": "https://docs.google.com/document/d/ABC/edit", "post_meta": {"slug": "my-post"}},
  {"source": "https://docs.google.com/document/d/DEF/edit", "post_type": "page"}
]
```

**Row object fields:**
- `source` (required) — Google Doc URL, Drive URL, or local .docx path
- `post_id` (optional) — Target post ID
- `post_type` (optional) — Post type
- `post_meta` (optional) — Metadata object (slug, excerpt, seo, categories, tags, etc.)
- `metadata` (optional) — Alternative key for post_meta (JSON string or array)

**Examples:**
```bash
wp draftsync import-bulk /path/to/imports.csv --user=1
wp draftsync import-bulk /path/to/rows.json --dry_run --user=1
wp draftsync import-bulk /path/to/rows.json --stop-on-error --user=1
```

---

### `wp draftsync sync <post_id>`

Re-sync a linked post from its source.

**Options:**
- `--force` — Force re-import even if content was modified locally

**Examples:**
```bash
wp draftsync sync 42 --user=1
wp draftsync sync 42 --force --user=1
```

---

### `wp draftsync sync-all`

Synchronize all linked posts with auto-sync enabled.

**Options:**
- `--limit=<n>` — Max posts to process (default: `gdtg_auto_sync_limit` option, 10)
- `--force` — Force re-import even on conflict
- `--dry-run` / `--dry_run` — Report candidates without importing
- `--user=<id>` — WordPress user ID

**Examples:**
```bash
wp draftsync sync-all --user=1
wp draftsync sync-all --dry-run --limit=5 --user=1
wp draftsync sync-all --force --limit=20 --user=1
```

**Output:**
```
Sync summary: checked=5 synced=3 skipped=1 conflicts=1 failed=0
+---------+-------------+-----------+-------+
| post_id | source_type | status    | error |
+---------+-------------+-----------+-------+
| 42      | gdoc        | synced    |       |
| 43      | drive_file  | skipped   |       |
| 44      | gdoc        | conflict  |       |
+---------+-------------+-----------+-------+
Success: All eligible posts synchronized.
```

---

### `wp draftsync status [job_id]`

Check batch import job status.

**Options:**
- `--all` — List all recent jobs

**Examples:**
```bash
wp draftsync status abc123def456
wp draftsync status --all
```

---

## Server Cron Integration

For reliable scheduled auto-sync on low-traffic sites, set up a server cron:

```bash
# Every 15 minutes
*/15 * * * * cd /path/to/wordpress && wp cron event run gdtg_auto_sync_event --quiet
```

Or disable WP Cron entirely and use server cron:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```bash
# Server crontab
* * * * * cd /path/to/wordpress && wp cron event run --all --quiet
```
