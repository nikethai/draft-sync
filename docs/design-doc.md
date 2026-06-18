# DraftSync — Design Document

> Brainstorm session output. Decisions locked. Implementation follows this.

---

## 1. Problem & Positioning

Import Google Docs into WordPress as **native Gutenberg blocks** — not HTML blobs. Other plugins produce HTML blobs. DraftSync produces `<!-- wp:paragraph -->`, `<!-- wp:heading -->`, `<!-- wp:image -->` etc. That's the differentiator.

**Classic Editor fallback**: auto-detect environment, output clean HTML when Gutenberg absent.

**Dual-mode**: SaaS bridge (zero-config) + Direct OAuth (BYO-key). Both fully supported.

---

## 2. Format Support & AST

### Supported inputs

| Input | Method | Parser |
|-------|--------|--------|
| Google Doc URL | `documents.get` → JSON | `Gdtg_Gdoc_Parser` |
| Google Drive .docx URL | `files.get` → OOXML | `Gdtg_Docx_Parser` |
| .docx file upload | multipart POST → OOXML | `Gdtg_Docx_Parser` |

No other formats. Friendly error for unsupported inputs.

### Shared AST (Document Node Model)

Both parsers produce the same intermediate representation. Inspired by Quill Delta. Renderers consume the AST — parsers never know about output format.

```php
class Gdtg_DocNode {
    public string $type;       // 'paragraph', 'heading', 'image', 'list', 'list_item',
                               // 'quote', 'separator', 'nextpage', 'table', 'table_row', 'table_cell'
    public string $content;    // Inner HTML (inline styles already applied)
    public array  $attrs;      // e.g. ['level' => 2, 'align' => 'center', 'ordered' => true,
                               //       'src' => '...', 'alt' => '...', 'attachment_id' => 42]
    public array  $children;   // Nested Gdtg_DocNode[] (list → list_items, table → rows → cells)
}
```

```
Pipeline:
  Google Docs JSON ──→ Gdtg_Gdoc_Parser  ──┐
                                            ├──→ Gdtg_DocNode[] ──→ Gdtg_BlockRenderer  → Gutenberg markup
  .docx / Drive file ──→ Gdtg_Docx_Parser ──┘                     └─→ Gdtg_HtmlRenderer   → Clean HTML
```

One bug fix in a renderer fixes both formats. One new output format = one new renderer.

---

## 3. Architecture

| Layer | Tech | Why |
|-------|------|-----|
| WordPress plugin | PHP 7.4+ | WP runtime — non-negotiable |
| SaaS bridge server | Go | Binary deploy, zero deps, cheap horizontal scale |
| AST + parsers + renderers | PHP (in-plugin) | Runs on user's WP, no external deps for Direct OAuth mode |
| Gutenberg sidebar | JS/React (`@wordpress/scripts`) | Required for editor integration |

### Component map

```
WP Admin
├── Settings page (class-gdtg-admin.php)
│   ├── Connection mode: SaaS | Direct OAuth                           ← both fully supported
│   ├── API keys / SaaS credentials                               ← IMPLEMENTED
│   ├── Style overrides (global defaults)                         ← NOT YET WIRED (per-import overrides in sidebar are shipped)
│
├── REST Endpoints (class-gdtg-rest-endpoints.php)
│   ├── POST /gdtg/v1/import      — trigger import (GDoc + Drive) ← IMPLEMENTED
│   ├── POST /gdtg/v1/upload-docx — file upload                   ← IMPLEMENTED
│   ├── GET  /gdtg/v1/import/{id}/status  — poll import progress  ← IMPLEMENTED
│   ├── POST /gdtg/v1/import/{id}/continue — resume batched job  ← IMPLEMENTED
│   ├── POST /gdtg/v1/import-bulk                                ← IMPLEMENTED
│   └── POST /gdtg/v1/sync/{id}                                  ← IMPLEMENTED
│
├── API Handler (class-gdtg-api.php)
│   ├── SaaS mode → calls Go bridge                               ← IMPLEMENTED
│   ├── Enterprise mode → direct Google API                       ← supported for interactive admin/editor; WP-CLI/cron/multisite intentionally unsupported
│   ├── get_drive_file_metadata() → file name + MIME              ← IMPLEMENTED
│   └── fetch_drive_file() → raw OOXML bytes                      ← IMPLEMENTED
│
├── Parsers
│   ├── class-gdtg-parser.php       — Google Docs JSON → DocNode[] ← IMPLEMENTED
│   └── class-gdtg-docx-parser.php  — OOXML → DocNode[]           ← IMPLEMENTED
│
├── ZIP Security (class-gdtg-zip-validator.php)
│   └── Magic bytes, path traversal, nested zips,                 ← IMPLEMENTED
│       entry limits, compression ratio validation
│
├── Orchestrator (class-gdtg-import-orchestrator.php)
│   └── Shared parse→render→write for REST + CLI                  ← IMPLEMENTED
│
├── CLI (class-gdtg-cli-command.php)
│   └── wp draftsync {import,import-docx,import-bulk,sync,status} ← IMPLEMENTED
│
├── AST (class-gdtg-doc-node.php)
│   └── Gdtg_DocNode value object                                  ← IMPLEMENTED
│
├── Renderers
│   ├── class-gdtg-block-renderer.php — DocNode[] → Gutenberg markup + overrides  ← IMPLEMENTED
│   └── class-gdtg-html-renderer.php  — DocNode[] → Clean HTML + overrides       ← IMPLEMENTED
│
├── Style Overrides
│   └── $overrides param on both renderers (heading_demotion,      ← IMPLEMENTED (backend + sidebar)
│       min_heading_level, default_alignment)
│

├── Imported Docs Manager (class-gdtg-admin.php)
│   └── Admin listing of all imported posts, post-meta display,        ← IMPLEMENTED
│       re-sync integration, bulk auto-sync toggle

├── Post Meta Applier (class-gdtg-post-meta-applier.php)
│       ACF, guarded custom meta
│
└── Sideloader (class-gdtg-sideloader.php)
    ├── Download + optimize + attach to Media Library               ← IMPLEMENTED
    └── sideload_from_bytes() for .docx embedded images            ← IMPLEMENTED

├── Secret Store (class-gdtg-secret-store.php)
│   └── AES-256-GCM encryption for Enterprise secrets               ← IMPLEMENTED (Phase 1)
│
├── Sync Lock (class-gdtg-sync-lock.php)
│   └── Per-post mutex to prevent concurrent sync                   ← IMPLEMENTED (Phase 1)
│
├── Sync Log (class-gdtg-sync-log.php)
│   └── FIFO-capped event log per post                              ← IMPLEMENTED (Phase 3)
│
├── Picker Config + Token endpoints (class-gdtg-rest-endpoints.php)
│   └── GET /gdtg/v1/picker/config + GET /gdtg/v1/auth/token        ← IMPLEMENTED (Phase 2)

Gutenberg Sidebar (src/sidebar/)
├── Doc URL input                                                     ← IMPLEMENTED
├── .docx file upload dropzone (drag-and-drop)                        ← IMPLEMENTED
├── Per-import toggles (import images, import tables, overwrite, draft) ← IMPLEMENTED
├── Style override controls (per-import, heading demotion, etc.)      ← IMPLEMENTED
├── Publishing metadata controls                                      ← IMPLEMENTED
├── Linked re-sync with conflict protection                           ← IMPLEMENTED
└── Import button + progress polling                                  ← IMPLEMENTED

SaaS Bridge (Go + chi — in-repo bridge/)
├── GET  /api/auth          — initiate Google OAuth flow                     ← IMPLEMENTED
├── GET  /api/callback      — Google OAuth callback                         ← IMPLEMENTED
├── POST /api/token         — redeem broker code for Google tokens             ← IMPLEMENTED
├── POST /api/refresh       — refresh an access token                       ← IMPLEMENTED
├── POST /api/optimize      — deferred (image CDN)                          ← not active
└── GET  /healthz           — health check                                  ← IMPLEMENTED
```

---

## 4. Image Pipeline

### SaaS bridge mode

```
Google temp URL → Go bridge downloads → resize (max 1920px) → compress (quality 82%)
→ convert to WebP → detect browser WebP support → return JPEG fallback URL
→ WP plugin receives optimized URL → sideload to Media Library
```

### Direct OAuth mode

```
User's WP server does everything: download → wp_get_image_editor → resize → compress
```

No Go bridge involvement. Image optimization runs locally via WordPress's built-in image editor.

### .docx images

```
Docx_Parser encounters w:drawing → extract image rId from relationships
→ Read image bytes from ZipArchive (word/media/imageN.png)
→ GDTG_Sideloader::sideload_from_bytes(bytes, filename, post_id, alt)
→ wp_upload_bits() + wp_insert_attachment() → attachment_id
→ Ast node: GDTG_Doc_Node('image', '', {id, url, alt})
```

---

## 5. Shared Hosting Resilience

| Mitigation | When |
|-----------|------|
| `set_time_limit(0)` | Every import |
| `wp_raise_memory_limit('256M')` | Every import |
| Batch image processing (3 at a time) + JS polling | >3 images |
| Skip WP thumbnail generation for sideloaded images | Option toggle |
| Progress endpoint (`GET /status/{id}`) | All async imports |
| ZIP entry limits + compression ratio checks | Every .docx import |

Background processing via WP Cron / Action Scheduler — future/non-goal unless separately implemented.

---

## 6. Block Customization & Style Overrides

### Block mapping

Parser produces blocks as-is. The research_notes.md mapping is canonical.

### Boolean toggles

| Toggle | Default | Effect |
|--------|---------|--------|
| Import images | On | Skip image sideloading entirely |
| Optimize images | On | Compress/convert images (SaaS or local) |
| Overwrite existing | Off | Append or replace post content |
| Import as draft | On | Publish immediately vs save as draft |
| Classic Editor mode | Auto-detect | Force HTML output |

### Style overrides

Live in renderer, not parser. Set via REST API params, WP-CLI flags, or sidebar controls. Admin settings UI for global defaults is not yet wired.

| Override | What it does |
|----------|-------------|
| Heading demotion | Drop all heading levels by N (e.g. H1→H2, H2→H3) |
| Min heading level | Cap at specified level (no H1/H2 in body content) |
| Default text alignment | Force all paragraphs to left/center/right |

**No per-element conditional logic.** No "if heading AND bold AND centered → do X." That's a layout builder, not an import tool.

---

## 7. Competitive Landscape

DraftSync differentiates through native Gutenberg blocks, .docx upload + Drive import, WP-CLI, style overrides, bulk import, metadata publishing, linked re-sync, open source, self-hosted, and unlimited free imports.

**Remaining UX gaps:** None for current scope. SEO plugin integration for richer editor-side controls remains a future UX/product layer, not a missing backend capability.

---

## 8. Feature Matrix

| Feature | Status | Notes |
|---------|--------|-------|
| Imports per month | Unlimited | No limits |
| Images per import | Unlimited best-effort | No limits |
| Image optimization | Local/WP best-effort | Auto-resize + WebP |
| Formats | GDoc + .docx (native parser) | Full coverage |
| Connection mode | SaaS (default) + Direct OAuth (BYO-key) | Both fully supported |
| Style overrides | Renderer-level (backend+sidebar) | Heading demotion, min level, alignment |
| WP-CLI | Full support (import, import-docx, import-bulk, sync, status) | Developer/agency workflow |
| CDN offloading | Deferred | Future |
| Background processing | Batch polling | Resilient import for image-heavy docs |
| OOXML parser maintenance burden | Tech debt | Shared AST limits scope to one parser per format |

---

## 10. Ship Order

## 10b. UX + Feature Expansion (260615) — Shipped

- **Google Picker sidebar import** — "Choose from Google Drive" button in the Gutenberg sidebar launches Google Picker, feeds selected file metadata into existing import flow.
- **Per-source sync event log** — `GDTG_Sync_Log` writes FIFO-capped (50) events to `_gdtg_sync_events` post meta; exposed via `GET /gdtg/v1/sync/{post_id}/events`.
- **Queued single-post sync** — `POST /gdtg/v1/sync/{post_id}/queue` schedules a one-shot WP Cron event when the per-post lock is held.
- **Per-post sync locks** — `GDTG_Sync_Lock` uses per-post options (`gdtg_sync_lock_{post_id}`) with `add_option()` for atomic acquire to prevent duplicate concurrent sync work for the same post. 423 Locked when lock is held.
- **Encrypted OAuth secrets at rest** — `GDTG_Secret_Store` encrypts `gdtg_enterprise_client_secret` using OpenSSL AES-256-GCM with a key derived from `wp_salt('auth')` via SHA-256.
- **Large-document threshold deferral** — imports exceeding a configurable byte/time threshold are deferred to queued processing instead of blocking the REST response.

### Deferred (from this expansion)

- Docs API fallback parser for large documents
- Two-way Google Docs writeback
- Custom DB logs table
- Real-time sync / WebSockets
- Folder-level bulk import from Drive

| Phase | What | Rationale |
|-------|------|-----------|
| **v1.0** | GDoc import, Gutenberg blocks, SaaS mode (free), sidebar, AST, boolean toggles, HTML/Classic Editor output, batch image polling, shared hosting resilience, .docx parser (GDoc + Drive + upload), ZIP security validator, import orchestrator, WP-CLI, style overrides (backend+sidebar), bulk import, metadata publishing (backend+sidebar), linked re-sync (backend+sidebar), sideload_from_bytes(). | Ship the differentiator + format expansion + CLI + publish UX |
| **Future** | Multisite Direct OAuth, team features | Scale |
### CDN Offload — Decision Boundary

**Product goal**: CDN offload serves delivery speed and WordPress storage pressure. Imported images are currently sideloaded into the WP media library, consuming local disk on shared hosting and serving from the origin server. A CDN offload would move media delivery to edge-cached URLs, reduce WP upload directory growth, and improve page load for image-heavy imports.

**Deferral status**: Deferred. No plugin-side provider abstraction will be built until the external bridge contract is locked.

**External contract required before implementation**:

| Contract field | Required value |
|---|---|
| **Auth** | OAuth2 service-account token scoped to `upload` + `read` on the bridge. No WP-site-level credentials stored. |
| **Upload source shape** | Binary stream or presigned-URL callback. The plugin calls `POST /v1/assets` with `Content-Type: application/octet-stream`, optional `filename`, and `Content-Length`. Bridge returns `{ id, url, width, height, mime }`. |
| **Output URL** | Public CDN URL with `Cache-Control: public, max-age=31536000, immutable`. Private docs use signed URLs with 24-hour TTL. |
| **Failure policy** | Timeout 30 s. On failure, fallback to local sideload (current behavior). Bridge returns structured error codes: `quota_exceeded`, `invalid_mime`, `internal_error`. |
| **Privacy** | Private Google Docs: images uploaded to bridge must not be publicly indexable. Signed URLs required. Bridge must honor data-retention policy (delete after 30 days or on explicit purge). |
| **Cost controls** | Per-org upload quota. Plugin sends `X-Org-Id` header. Bridge enforces limits and returns `429` with `Retry-After`. |

**Blockers**: No Go bridge repository exists. No storage API contract. No auth provider integration. CDN offload remains in the Ship Order "Future" row until the bridge contract is implemented and tested.

> Note: bulk import, metadata publishing, and linked re-sync are implemented in code but were previously listed here as roadmap gaps.
