# Google Docs to Gutenberg (DraftSync) — Project Overview & Product Definition Record

## 1. Problem Statement

Content teams write in Google Docs. WordPress publishes in Gutenberg blocks. Between those two tools sits a manual, error-prone copy-paste step that destroys formatting, loses images, and wastes hours every week.

Existing solutions take one of two shortcuts:

- **Paste raw HTML** — wraps the entire document in a single `wp:html` block or Classic Editor blob. Gutenberg cannot edit individual paragraphs, headings, or images inside that blob.
- **Upload .docx files** — requires the user to export from Google Docs first, adds a step, and relies on DOCX parsers that silently drop inline styles, lists, and table formatting.

DraftSync takes a different approach: it reads the **Google Docs REST API JSON** directly or parses **.docx OOXML** natively, and emits **native Gutenberg blocks** — paragraphs, headings, lists, images, tables — that WordPress can edit natively.

---

## 2. Product Identity

| Field | Value |
|---|---|
| **Product Name** | Google Docs to Gutenberg |
| **Internal Codename** | DraftSync |
| **Version** | 0.3.0-dev |
> **WP.org Submission Candidate:** v0.2.0 is the target version for initial WordPress Plugin Directory submission. All features listed under "Shipped" below are included.
| **Author** | DraftSync |
| **License** | GPL-2.0-or-later |
| **WordPress Plugin Slug** | `draftsync` |
| **Text Domain** | `draftsync` |

---

## 3. Key Differentiator

| Feature | DraftSync | Other plugins |
|---|---|---|
| Output format | **Native Gutenberg blocks** | Raw HTML blob |
| .docx import | **Native OOXML parser** | Varies |
| Image handling | Sideloads to Media Library | Inline base64 or external |
| List support | Nested `<ul>`/`<ol>` via AST | Flat or broken |
| Table support | Native `wp:table` block | Inline HTML |
| Inline styles | Bold, italic, underline, strikethrough, color, subscript/superscript, links | Basic |
| Dual-mode auth | SaaS bridge + Direct OAuth (BYO-key) | SaaS only |
| WP-CLI | Yes | No |
| Open source | Yes (GPL-2.0) | No |

---

## 4. Dual-Mode Architecture

DraftSync supports two connection modes, selected by the site administrator:

### 4.1 SaaS OAuth Bridge (Default — Free)

- The SaaS bridge is a **self-hosted Go + chi OAuth broker** under `bridge/`.
- The bridge holds the Google `client_secret` centrally so individual WordPress sites do not need their own Google Cloud project.
- The bridge exchanges Google authorization codes server-side and issues random **broker codes** to the plugin. The raw Google code is never exposed to the plugin.
- Tokens are **not durably stored** on the bridge — they are held transiently in SQLite between `/api/callback` and `/api/token` (single-use, auto-pruned).
- The plugin stores access/refresh tokens in `wp_options` — no Google API credentials required.
- The plugin calls Google APIs directly; no document or image traffic flows through the bridge.
- Designed for non-technical users and shared hosting environments.

### 4.2 Direct OAuth (BYO-Key)
- User supplies their own Google Cloud OAuth 2.0 client ID and client secret.
- Plugin talks directly to `oauth2.googleapis.com` and `docs.googleapis.com`.
- Tokens stored locally in `wp_options`.
- Designed for agencies and environments that require full credential custody.

---

## 5. Version Roadmap

### v0.3.0-dev — In Progress

- Self-hosted OAuth bridge (`bridge/`) — Go + chi broker with SQLite ephemeral store, broker-code token exchange, graceful shutdown, redirect allowlist
- API token lifecycle — REST endpoints for token create/revoke/list, capability-gated
- SaaS bridge URL validation — HTTPS enforcement, localhost dev override, host-scoped DNS transient
- OAuth state hardening — single-use transients, replay protection, flow-type matching
- Expanded test suite — 13 standalone PHP harnesses, 530+ assertions (added: docx-parser, zip-validator, OAuth state, SaaS bridge URL, API token lifecycle)

### Phase: Competitor UX + Feature Expansion (260615) — Shipped

- **Google Picker sidebar import** — sidebar "Choose from Google Drive" button launches Google Picker API. Selected file feeds existing import flow.
- **Per-post sync locks** — `GDTG_Sync_Lock` prevents duplicate concurrent sync; 423 Locked response when queue is full.
- **Encrypted OAuth secrets** — `GDTG_Secret_Store` (AES-256-GCM) encrypts `gdtg_enterprise_client_secret` at rest.
- **Direct OAuth setup guidance** — JSON import helper, redirect URI visibility, pre-flight config validation, picker key fields.
- **Large-document threshold deferral** — oversized imports deferred to queued processing instead of blocking REST response.

**Test coverage added:** 6 new test harnesses (secret-store, sync-log, picker-config, enterprise-guidance, sync-lock, sync-log tests within sync-scheduler harness). Total harnesses: 19, 610+ assertions.

**Explicit deferrals:** Docs API fallback parser for large documents, two-way Google Docs writeback, custom DB logs table, real-time sync, folder-level bulk import.

### v0.2.0 — Shipped

- Google Docs import via REST API JSON
- Native Gutenberg block output (paragraph, heading, list, image, table, separator, nextpage)
- SaaS bridge authentication (free, zero-config)
- Gutenberg post editor sidebar (React)
- Admin settings dashboard (dark mode)
- Image sideloading to WordPress Media Library with optimization
- REST API endpoint with IDOR-safe permission checks
- Boolean toggle settings for granular import control (import images, import tables, overwrite existing, import as draft)
- HTML / Classic Editor output mode (via GDTG_HTML_Renderer)
- Shared hosting resilience: chunked/streamed image downloads, longer timeouts, retry with exponential backoff
- Batch image polling with transient-based import jobs (poll /import/{job}/status and /continue)
- `.docx` file parser — OOXML → `GDTG_Doc_Node[]` via namespace-aware SimpleXML
- ZIP security validator — magic bytes, path traversal, nested zips, entry limits, compression ratio
- WP-CLI commands — `wp draftsync {import,import-docx,status}`
- Import orchestrator — shared parse→render→write pipeline for REST + CLI
- Style override support — `$overrides` param on both renderers (heading_demotion, min_heading_level, default_alignment)
- `.docx` upload endpoint — `POST /gdtg/v1/upload-docx` with multipart handling
- Drive .docx support — `parse_source_reference()` routes Drive file URLs to `GDTG_Docx_Parser`
- Linked re-sync (`POST /gdtg/v1/sync/{post_id}`, `wp draftsync sync`) — content-hash conflict detection, force override flag
- Metadata publishing pipeline — slug, excerpt, SEO title/description, canonical URL, categories/tags, featured image, ACF fields, guarded custom meta
- Sync scheduler — WP Cron auto-sync (`GDTG_Sync_Scheduler`), Drive modifiedTime change detection, conflict resolution, dry-run support, REST/CLI control surfaces
- Gutenberg sidebar supports bulk import mode, post type selection, `.docx` drag-and-drop, style override controls, publishing metadata, and an auto-sync panel for linked posts
- Admin settings include Imported Docs Manager, global style defaults (heading demotion, minimum heading level, default alignment), and sync controls
- `import_tables` toggle shipped alongside existing boolean toggles

> **Note:** Direct OAuth (BYO-key) is fully supported for admin/editor imports, WP-CLI, and scheduled auto-sync.

- Multisite support
- Frontend rendering customization
- Google Sheets or Google Slides import
- CDN offloading

---

## 6. AST Pipeline

The core import pipeline converts multiple input formats into Gutenberg blocks through an intermediate AST:

```
┌──────────────────────────────────────────────────────────────────────────┐
│                          IMPORT PIPELINE                                │
│                                                                           │
│  Google Doc URL ──→ parse_source_reference() ──→ type: gdoc              │
│  Drive .docx URL ──→ parse_source_reference() ──→ type: drive_file       │
│  Local .docx ──→ GDTG_Zip_Validator → GDTG_Docx_Parser                  │
│                                                                           │
│       │ GDTG_API::fetch_google_doc()  │   GDTG_API::fetch_drive_file()   │
│       ▼                               ▼                                  │
│  ┌─────────────┐              ┌──────────────────┐                        │
│  │ GDTG_Parser  │              │ GDTG_Docx_Parser │                        │
│  │ (stateful)   │              │ (namespace-aware) │                       │
│  └──────┬──────┘              └────────┬─────────┘                        │
│         │                              │                                  │
│         └──────────────┬───────────────┘                                  │
│                        ▼                                                  │
│  ┌─────────────────────────────────────┐                                  │
│  │ GDTG_Doc_Node[]                     │  Intermediate AST                │
│  │ (value objects)                     │  Types: paragraph, heading,      │
│  └──────────────┬──────────────────────┘  image, list, list_item, table,  │
│                 │                         table_row, table_cell, nextpage │
│                 ▼                                                        │
│  ┌──────────────────────────┐   ┌──────────────────────────┐             │
│  │ GDTG_Block_Renderer      │   │ GDTG_HTML_Renderer       │             │
│  │ (+ style overrides)      │   │ (+ style overrides)      │             │
│  └────────────┬─────────────┘   └────────────┬─────────────┘             │
│               │                              │                            │
│               ▼                              ▼                            │
│  Gutenberg Block HTML            Classic Editor HTML                      │
│  (post_content)                  (post_content)                           │
└──────────────────────────────────────────────────────────────────────────┘
```

`GDTG_Import_Orchestrator` wraps the full parse→render→write pipeline, shared by REST endpoints and WP-CLI commands. Both renderers accept style override params.

---

## 7. Feature Set

| Feature | Status | Notes |
|---|---|---|
| SaaS mode (free) | Shipped | Zero-friction onboarding; user only needs a Google account |
| Image optimization | Shipped | Value-add for all users |
| Batch image polling | Shipped | Resilient import for image-heavy documents |
| Direct OAuth (BYO-key) | Shipped | Full support: admin, editor, WP-CLI, cron auto-sync |
| Bulk import | Shipped | REST, CLI, and sidebar; dedicated sidebar bulk mode |
| Style override rules | Shipped | Heading demotion, min level, default alignment, with admin-configurable global defaults |
| `.docx` parser | Shipped | Native OOXML parser with ZIP validation |
| WP-CLI command | Shipped | Developer/agency workflow integration |
| Auto-sync controls | Shipped | Sidebar and admin surfaces manage linked-post sync behavior |
| Drive Picker | Shipped | Google Picker integration for selecting files from the editor |
---

## 8. Scope Boundaries


### Shipped
- Google Docs API v1 JSON parsing
- .docx OOXML parsing (local upload + Drive download)
- Gutenberg block markup output
- Classic Editor / HTML output mode
- SaaS bridge authentication (free)
- Gutenberg sidebar UI (Google Doc URL import, sidebar bulk import mode, post type selector, `.docx` drag-and-drop, style overrides, publishing metadata, linked re-sync, auto-sync panel)
- Admin settings page + Imported Docs Manager tab + global style defaults (heading demotion, min heading level, default alignment)
- Image sideloading and optimization
- REST API import endpoints (Google Doc, `.docx` upload, job status, continue, bulk import, sync/status/settings/run)
- WP-CLI commands (import, import-docx, import-bulk, sync, sync-all, status)
- Boolean import option toggles (import_images, import_tables, overwrite, import_as_draft)
- Batch image polling
- Style override support (heading demotion, min heading level, default alignment) — backend + sidebar + admin defaults
- Sync scheduler (WP Cron auto-sync with Drive modifiedTime detection) plus sidebar/admin sync controls
- Direct OAuth (BYO-key) for admin/editor imports, WP-CLI, and scheduled auto-sync

### Deferred
- Frontend rendering customization
- Google Sheets or Google Slides import
- Multisite support
- CDN offloading
