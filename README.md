# DraftSync

**Import Google Docs and .docx files directly into native WordPress Gutenberg blocks.**

DraftSync reads the Google Docs REST API JSON or parses .docx OOXML, reconstructs document structure, and emits native Gutenberg blocks — paragraphs, headings, lists, images, tables — that WordPress can edit natively. No raw HTML blobs. No Classic Editor workarounds. WP-CLI support for headless/automated imports.


## Key Features

- **Native Gutenberg blocks** — each paragraph, heading, list, image, and table becomes an independently editable block
- **.docx import** — upload .docx files or import from Google Drive; namespace-aware OOXML parser with ZIP security hardening
- **Drive Picker (Enterprise)** — "Choose from Google Drive" button in the sidebar; select Docs/Drive files without copy-pasting URLs
- **Sync health visibility** — per-post sync event log with info/warning/error events; clear event history via REST
- **Encrypted Enterprise secrets** — AES-256-GCM encryption for `gdtg_enterprise_client_secret` at rest via `GDTG_Secret_Store`
- **WP-CLI commands** — `wp draftsync import`, `wp draftsync import-docx`, `wp draftsync status` for headless workflows
- **Bulk import** — import multiple Google Docs or .docx files in a single REST or CLI call; dry-run validation; per-row metadata, SEO, ACF
- **Linked re-sync** — re-import from the original source with conflict detection (baseline content hash) and force override; auto-migrates Drive .docx to Google Doc source when Google converts the file
- **Metadata publishing** — slug, excerpt, SEO title/description, canonical URL, categories/tags, featured image, ACF fields, guarded custom meta
- **SaaS OAuth Bridge** — zero-config authentication for Google Docs API
- **Gutenberg sidebar UI** — paste a Google Doc URL, click Import, done
- **Image sideloading** — downloads images to WordPress Media Library with automatic optimization
- **List nesting** — supports multi-level bulleted and numbered lists
- **Table support** — native `wp:table` block output
- **Inline style preservation** — bold, italic, underline, strikethrough, links, text color, subscript/superscript
- **Style overrides** — heading demotion, min heading level, default alignment; applied at render time
- **IDOR-safe REST API** — per-post capability checks on every import request
- **Zero external dependencies at runtime** — pure PHP, no Composer, no third-party libraries

---

### Auto-Migration from Drive .docx to Google Doc

When you import a `.docx` from Google Drive and later open it with **Open with Google Docs** in Drive, Google converts it to a native Google Doc. All your edits now live in the Google Doc web editor — not the `.docx` binary.

DraftSync automatically detects this conversion on the next re-sync:

1. **Re-sync detects the conversion** — DraftSync sees the file is now `application/vnd.google-apps.document` instead of the original `.docx` MIME type.
2. **Seamless migration** — The post's source type is upgraded from `drive_file` to `gdoc`. Future re-syncs use the Google Docs API.
3. **Zero configuration** — No settings to change, no buttons to click. It happens automatically.

| Before | After |
|--------|-------|
| Import .docx from Drive | Same |
| Open .docx with Google Docs (in Drive) | Same |
| Edit in Google Docs web editor | Same |
| Re-sync in WordPress | Now pulls your live Google Doc edits |

**CLI batch migration:**

```bash
# Find all eligible posts (dry-run)
wp draftsync migrate-drive-sources --dry-run --user=1

# Migrate all eligible posts
wp draftsync migrate-drive-sources --user=1
```

**Troubleshooting:** If migration fails (e.g., Google Docs API is temporarily unavailable), the post keeps its `drive_file` source type. The next re-sync will retry automatically.


### Drive Picker (Enterprise)

Enterprise users can select Google Docs and Drive files directly from the Gutenberg sidebar without copying URLs:

1. Click **"Choose from Google Drive"** next to the URL input.
2. The Google Picker API opens showing your Docs and Drive files.
3. Select a file — its metadata (`id`, `name`, `mimeType`) feeds into the existing import pipeline.

Manual URL/ID input remains available as a fallback for all connection modes.



## Requirements

| Requirement | Version |
|---|---|
| PHP | 7.4+ |
| WordPress | 6.4+ |


**Enterprise JSON import helper:** On the settings page, paste your Google Cloud OAuth 2.0 client JSON directly — DraftSync extracts `client_id` and `client_secret` automatically and encrypts the secret at rest.

## Quick Start

```bash
# Clone the repository
git clone https://github.com/your-org/draftsync.git
cd draftsync

# Install Node dependencies
pnpm install

# Build the Gutenberg sidebar JavaScript
pnpm run build

# Activate the plugin in WordPress
# Option A: Symlink into wp-content/plugins/
ln -s /path/to/draftsync /path/to/wordpress/wp-content/plugins/

# Option B: Copy directly
cp -r . /path/to/wordpress/wp-content/plugins/draftsync/
```

Then in WordPress Admin:

1. Navigate to **Google Docs Sync** in the admin menu.
2. Connect your Google account via the SaaS bridge.
3. Open any post or page in the Gutenberg editor.
4. Click the cloud icon in the sidebar, paste a Google Doc URL, and click **Import Now**.

**WP-CLI usage:**

```bash
# Import a Google Doc by URL
wp draftsync import https://docs.google.com/document/d/XYZ/edit

# Import a local .docx file
wp draftsync import-docx /path/to/document.docx

# Check import job status
wp draftsync status [job_id]

# Bulk import from CSV rows
wp draftsync import-bulk --rows='[{"source":"https://docs.google.com/document/d/XYZ/edit"}]'

# Re-sync a linked post from its source
wp draftsync sync 123
wp draftsync sync 123 --force

# Migrate Drive .docx posts that were converted to Google Docs
wp draftsync migrate-drive-sources --dry-run --user=1
wp draftsync migrate-drive-sources --user=1
```

> **WordPress.org users:** See `readme.txt` for the canonical plugin page information used by the WordPress Plugin Directory.

---

## Architecture Overview

**Enterprise secret encryption:** Only `gdtg_enterprise_client_secret` is encrypted at rest using AES-256-GCM via OpenSSL in `GDTG_Secret_Store`. The encryption key is derived from `wp_salt('auth')` using SHA-256. OAuth access and refresh tokens remain stored in plain `wp_options` rows today.

```
Google Doc URL ──→ parse_source_reference() ──→ type: gdoc ──→ GDTG_API → GDTG_Parser ──┐
Drive .docx URL ──→ parse_source_reference() ──→ type: drive_file ──→ GDTG_API → GDTG_Docx_Parser ──┤
Local .docx ──→ GDTG_Zip_Validator → GDTG_Docx_Parser ───────────────────────────────────────────────┤
                                                                                                     │
                                                                                                     ▼
                                                                                          ┌────────────────┐
                                                                                          │ GDTG_Doc_Node[] │ ── format-agnostic AST
                                                                                          └──────┬─────────┘
                                                                                                 │
                                                                                    ┌────────────┴────────────┐
                                                                                    ▼                         ▼
                                                                         ┌─────────────────────┐  ┌───────────────────┐
                                                                         │ GDTG_Block_Renderer  │  │ GDTG_HTML_Renderer │
                                                                         │ (+ style overrides)  │  │ (+ style overrides)│
                                                                         └──────────────────────┘  └───────────────────┘
```

Multiple import sources feed into a shared AST. Both renderers support style overrides (heading demotion, min heading level, default alignment). `GDTG_Import_Orchestrator` wraps the full parse→render→write pipeline for both REST and CLI.

**Backend:** PHP classes in `includes/` — loader pattern for hook registration, REST API with nonce + capability checks, WordPress HTTP API for Google requests, namespace-aware OOXML parser with ZIP validation.

**Frontend:** React sidebar built with `@wordpress/scripts`, compiled via webpack to `build/`. Uses Gutenberg component library (`@wordpress/components`) and `apiFetch` for REST communication. The sidebar now includes .docx upload mode toggle, style overrides panel (heading demotion, min heading level, alignment), and publishing metadata panel (slug, excerpt, SEO fields, ACF mapping).

---

## Project Structure

```
draftsync/
├── draftsync.php       # Plugin entry point
├── package.json                       # Build dependencies
├── includes/
│   ├── class-gdtg-loader.php          # Hook registry
│   ├── class-gdtg-admin.php           # Settings dashboard & OAuth
│   ├── class-gdtg-api.php             # Google API client & SaaS bridge
│   ├── class-gdtg-parser.php          # JSON → AST parser
│   ├── class-gdtg-docx-parser.php     # OOXML → AST parser (v2)
│   ├── class-gdtg-zip-validator.php   # ZIP security hardening (v2)
│   ├── class-gdtg-import-orchestrator.php # Shared parse→render→write (v2)
│   ├── class-gdtg-cli-command.php     # WP-CLI commands (v2)
│   ├── class-gdtg-doc-node.php        # AST value object
│   ├── class-gdtg-block-renderer.php  # AST → Gutenberg HTML
│   ├── class-gdtg-html-renderer.php   # AST → Classic Editor HTML
│   ├── class-gdtg-sideloader.php      # Image download & optimization
│   └── class-gdtg-rest-endpoints.php  # REST API routes
├── src/
│   ├── index.js                       # Gutenberg plugin registration
│   └── sidebar/
│       ├── index.js                   # Sidebar with docx upload, style overrides, publishing metadata panels
│       └── sidebar.css                # Sidebar styles
├── tests/
│   ├── parser-renderer-test.php       # Parser + renderer (~130 assertions)
│   ├── rest-endpoints-test.php        # REST handlers + permissions (~130 assertions)
│   ├── docx-parser-test.php           # DOCX parser standalone (~55 assertions)
│   ├── test-orchestrator.php          # Orchestrator pipeline (~45 assertions)
│   ├── post-meta-applier-test.php     # Metadata extraction (~35 assertions)
│   ├── sync-scheduler-test.php        # WP Cron sync (~25 assertions)
│   ├── cli-command-test.php           # CLI commands (~25 assertions)
│   ├── sideloader-test.php            # Image sideloading (~10 assertions)
│   ├── zip-validator-test.php         # ZIP security (~15 assertions)
│   ├── oauth-state-test.php           # OAuth state mgmt (~16 assertions)
│   ├── saas-bridge-url-test.php       # Bridge URL validation (~20 assertions)
│   ├── api-token-test.php             # API token lifecycle (~25 assertions)
│   └── fixtures/                      # JSON + DOCX test fixtures

---

## Documentation

| Document | Description |
|---|---|
| [Project Overview & PDR](docs/project-overview-pdr.md) | Problem statement, differentiator, version roadmap, pricing levers |
| [Codebase Summary](docs/codebase-summary.md) | File inventory, dependency graphs, hooks table, REST API, build pipeline |
| [Code Standards](docs/code-standards.md) | PHP 7.4 rules, WPCS conventions, escaping contract, security patterns |
| [System Architecture](docs/system-architecture.md) | Class deep dive, request lifecycle, OAuth flows, image pipeline, state management |
| [Design Document](docs/design-doc.md) | Design decisions, AST model, component map, ship order |

---

## Development

```bash
pnpm run build        # Production build (minified)
pnpm run start        # Development watch mode (rebuilds on change)
pnpm run lint:js      # ESLint
pnpm run format       # Prettier

# Run tests
php tests/parser-renderer-test.php
php tests/rest-endpoints-test.php
php tests/docx-parser-test.php
php tests/test-orchestrator.php
php tests/post-meta-applier-test.php
php tests/sync-scheduler-test.php
php tests/cli-command-test.php
php tests/sideloader-test.php
php tests/zip-validator-test.php
php tests/oauth-state-test.php
php tests/saas-bridge-url-test.php
php tests/api-token-test.php
```

---

## License

This plugin is licensed under the GPL-2.0-or-later. See the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html) for full terms.
