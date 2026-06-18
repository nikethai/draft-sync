# DraftSync — Master Test Plan

> Comprehensive QA plan for the **DraftSync** WordPress plugin (Google Docs / `.docx` → native Gutenberg blocks).
> This plan is grounded in the real architecture: PHP 7.4+, no Composer, standalone PHP test harnesses, `@wordpress/scripts` build, and a React Gutenberg sidebar. It is **not** a Next.js/NestJS plan.

## Overview

| Field | Value |
|---|---|
| Product | DraftSync (`draftsync`) |
| Type | WordPress plugin |
| Runtime | PHP 7.4+, WordPress 6.0+ |
| Frontend | React Gutenberg sidebar via `@wordpress/scripts` |
| Data store | `wp_options` + post meta + transients (no custom tables) |
| Test tooling | Standalone PHP harnesses, `wp-scripts lint-js`, `wp-scripts build`, manual Gutenberg smoke, browser-driven sidebar e2e, (gap) WP integration harness |
| Reference docs | `docs/system-architecture.md`, `docs/codebase-summary.md`, `docs/code-standards.md` |

## Scope

### In scope
- AST parsing: `GDTG_Parser` (GDoc JSON), `GDTG_Docx_Parser` (OOXML).
- Rendering: `GDTG_Block_Renderer`, `GDTG_HTML_Renderer`, including style overrides.
- Security gate: `GDTG_Zip_Validator` (magic bytes, traversal, nested zip, entry limit, compression bomb).
- Pipeline: `GDTG_Import_Orchestrator` (Google Doc, Drive `.docx`, local `.docx`, bulk, re-sync).
- Metadata: `GDTG_Post_Meta_Applier` (slug, excerpt, SEO, taxonomy, featured image, ACF, guarded custom meta).
- Surfaces: `GDTG_REST_Endpoints`, `GDTG_CLI_Command`, `GDTG_Sync_Scheduler`.
- Auth: `GDTG_Admin` OAuth state/CSRF, `GDTG_API` token lifecycle (SaaS + Enterprise).
- Image pipeline: `GDTG_Sideloader` (URL sideload + `sideload_from_bytes`).
- Frontend: Gutenberg sidebar import flow (`src/sidebar/index.js`).

### Out of scope
- Google Docs / Drive API server behavior (external).
- SaaS auth bridge internals (external service).
- WordPress core, theme, and third-party plugin behavior beyond integration boundaries.
- Load testing of Google's CDN.

## Test Strategy

| Layer | Tool | Coverage target | Status |
|---|---|---|---|
| Parser + renderers (unit) | Standalone PHP harness | Block validity, escaping, overrides, lists, tables, images | **Implemented** (`parser-renderer-test.php`, 235+ assertions) |
| Sideloader (unit) | Standalone PHP harness | URL scheme guard, bytes sideload, optimize path | **Implemented** (`sideloader-test.php`) |
| Orchestrator (unit) | Standalone PHP harness | Pipeline, bulk normalization, re-sync meta | **Implemented** (`test-orchestrator.php`) |
| Large-doc streamer (unit) | Standalone PHP harness | Chunk loop, progress callback, cumulative commit, error path | **Implemented** (`large-doc-streamer-test.php`, 27 assertions) |
| REST (unit) | Standalone PHP harness | Permissions, normalization, source parsing | **Implemented** (`rest-endpoints-test.php`) |
| CLI (unit) | Standalone PHP harness | import / import-docx / import-bulk / sync / status | **Implemented** (`cli-command-test.php`) |
| Post meta applier (unit) | Standalone PHP harness | SEO/taxonomy/ACF/guarded meta | **Implemented** (`post-meta-applier-test.php`) |
| Sync scheduler (unit) | Standalone PHP harness | Cron, change detection, conflict, sync-all | **Implemented** (`sync-scheduler-test.php`) |
| `.docx` parser (unit) | Standalone PHP harness | OOXML → AST, namespace handling | **Implemented** (`docx-parser-test.php`, ~55 assertions) |
| ZIP validator (security unit) | Standalone PHP harness | Bomb, traversal, nested, entry cap, magic bytes | **Implemented** (`zip-validator-test.php`, ~15 assertions) |
| OAuth / CSRF (unit) | Standalone PHP harness | State generation, TTL, single-use, validation | **Implemented** (`oauth-state-test.php`, ~16 assertions) |
| API token lifecycle (unit) | Standalone PHP harness | Refresh on 401, expiry, mode routing | **Implemented** (`api-token-test.php`, ~25 assertions) |
| JS lint / build | `wp-scripts` | Sidebar compiles, artifacts in sync | **Available** |
| REST (integration) | PHPUnit + WP test harness | Real nonce, real capabilities, real routes | **GAP — not set up** |
| Sidebar (e2e) | Browser automation | Import flow, mode switch, file picker, busy state | **Partially covered — live browser checks + selectors in place; scripted harness still evolving** |

### Techniques applied
- **Happy path** per component.
 - **Boundary values** — heading levels 1–6, demotion 0–5, REST bulk row max (100), compression ratio at 100×, entry count at 10000.
- **Equivalence partitioning** — source input classes (GDoc URL, raw ID, Drive URL, local path, invalid).
- **State transitions** — sidebar `idle → syncing/polling → success/error`; sync `unchanged → changed → conflict`.
- **Error handling** — invalid input, network failure/timeout, token expiry, malformed OOXML, image sideload failure.
- **Edge cases** — empty doc, metadata-only first node, long filenames, RTL, private meta keys, IDOR attempts.

## Risk Prioritization

| Component | Risk | Rationale | Depth |
|---|---|---|---|
| `GDTG_Zip_Validator` | **Critical** | Untrusted `.docx` upload → DoS / traversal / bomb | Full automated + adversarial fixtures |
| REST permissions / `check_permissions` | **Critical** | IDOR, capability bypass, data integrity | Full automated + integration |
| `GDTG_Admin` OAuth state | **Critical** | CSRF on token exchange | Automated unit + manual flow |
| `GDTG_API` token lifecycle | High | Auth failure breaks all imports; refresh-retry correctness | Automated unit |
| `GDTG_Parser` / `GDTG_Docx_Parser` | High | Block validation breakage = corrupt posts | Automated regression (broad fixtures) |
| Renderers + overrides | High | Invalid Gutenberg markup, escaping (XSS) | Automated regression |
| `GDTG_Import_Orchestrator` | High | Core write path; overwrite vs draft semantics | Automated + integration |
| `GDTG_Post_Meta_Applier` | High | Private-meta guard, SEO/taxonomy correctness | Automated |
| `GDTG_Sync_Scheduler` | Medium | Background re-import, conflict skip | Automated |
| CLI commands | Medium | Operator surface, arg mapping | Automated |
| Sideloader | Medium | Non-blocking failure, optimize path | Automated |
| Sidebar UI | Medium | UX flow, busy-state disabling, a11y | Manual smoke + (gap) e2e |
| Admin settings page | Low | Cosmetic, config persistence | Manual + selective |

---

## Test Cases

IDs are stable. Priority: P1 (critical/revenue/security) … P4 (cosmetic). Type: U=unit, S=security, I=integration, E2E, M=manual.

### Group A — `GDTG_Parser` (Google Docs JSON → AST)

Harness: `tests/parser-renderer-test.php` (extend; do not fork).

#### TC-A01: Paragraph with mixed inline styles
- **Priority:** P2 · **Type:** U
- **Precondition:** Fixture with bold/italic/underline/link runs.
- **Steps:** Parse fixture → inspect first node's `content`.
- **Expected:** Inline HTML escaped via `esc_html()`; `<strong>`, `<em>`, `<a href>` present; no raw `<script>`.
- **Test Data:** `tests/fixtures/google-docs/inline-styles.json`

#### TC-A02: Heading level mapping
- **Priority:** P2 · **Type:** U
- **Steps:** Parse doc with H1–H6 named styles.
- **Expected:** Node `type=heading`, `attrs.level` matches source 1–6; TITLE/SUBTITLE handled per parser rules.

#### TC-A03: Nested ordered/unordered lists
- **Priority:** P2 · **Type:** U
- **Steps:** Parse doc with 3-level mixed nesting.
- **Expected:** `list_stack` flush produces correctly nested list nodes; ordered vs unordered preserved per `is_list_ordered()`.
- **Edge:** List directly followed by paragraph flushes stack before paragraph node.

#### TC-A04: Table parse
- **Priority:** P2 · **Type:** U
- **Steps:** Parse doc with a 2×3 table including an empty cell.
- **Expected:** `type=table` node; rows/cells preserved; empty cell yields empty (not dropped) cell content.

#### TC-A05: Image inline object → node
- **Priority:** P3 · **Type:** U
- **Steps:** Parse doc with one `inlineObjectElement`; stub sideloader to return attachment id.
- **Expected:** `build_image_node()` returns node with `attrs.id/url/alt`.
- **Edge (TC-A05b):** Sideloader returns `false` → node is `null` → image silently omitted, parse does not throw.

#### TC-A06: Empty document
- **Priority:** P3 · **Type:** U
- **Steps:** Parse JSON with empty body.
- **Expected:** `parse_nodes()` returns `[]`; renderer emits empty string; no fatal.

#### TC-A07: Color conversion
- **Priority:** P4 · **Type:** U
- **Steps:** Run `rgb_to_hex()` boundary inputs (0,0,0), (255,255,255), fractional rgb floats.
- **Expected:** Correct 6-digit hex; clamps out-of-range.

### Group B — `GDTG_Docx_Parser` (OOXML → AST)

Harness: `tests/docx-parser-test.php` (~55 assertions). Uses real `.docx` fixtures (ZIP w/ `word/document.xml`).

#### TC-B01: Paragraph + run properties
- **Priority:** P2 · **Type:** U
- **Steps:** Parse fixture with bold/italic/color runs.
- **Expected:** Namespace-aware extraction; `esc_html()` applied; styles mapped to inline HTML.

#### TC-B02: Heading via paragraph style
- **Priority:** P2 · **Type:** U
- **Expected:** `w:pStyle` Heading1–6 → heading node with correct level.

#### TC-B03: Table parse
- **Priority:** P2 · **Type:** U
- **Expected:** `w:tbl` → table node; `w:tr`/`w:tc` structure preserved.

#### TC-B04: Namespace robustness
- **Priority:** P2 · **Type:** U
- **Steps:** Fixture using non-default namespace prefixes for `w:`.
- **Expected:** Parser still resolves elements via namespace URI, not prefix string.

#### TC-B05: Missing `word/document.xml`
- **Priority:** P2 · **Type:** U/S
- **Steps:** Valid ZIP without the document part.
 - **Expected:** Graceful failure with empty node list, no fatal, no external fetch.
 
 #### TC-B06: Malformed XML
 - **Priority:** P2 · **Type:** U/S
 - **Expected:** Caught; returns empty nodes; no warning leak, no uncaught exception.

#### TC-B07: Image extraction from media
- **Priority:** P3 · **Type:** U
- **Steps:** Fixture with `w:drawing` + `word/media/image1.png`; stub `sideload_from_bytes`.
- **Expected:** Image node built from returned attachment id; failure path omits image.

### Group C — `GDTG_Zip_Validator` (security gate)

Harness: `tests/zip-validator-test.php` (~15 assertions). Adversarial fixtures included.

#### TC-C01: Valid `.docx` passes
- **Priority:** P1 · **Type:** S
- **Expected:** `validate()` returns `true` for a real minimal `.docx`.

#### TC-C02: Missing/unreadable file
- **Priority:** P1 · **Type:** S
 - **Expected:** `WP_Error('gdtg_docx_not_found')`.
 
 #### TC-C03: Bad magic bytes
 - **Priority:** P1 · **Type:** S
 - **Steps:** File whose first 4 bytes != `PK\x03\x04` (e.g. renamed `.txt`).
 - **Expected:** `WP_Error('gdtg_docx_bad_magic')`.
 
 #### TC-C04: Path traversal entry
 - **Priority:** P1 · **Type:** S
 - **Steps:** ZIP containing `../../evil.php`, `..\evil`, and absolute `/etc/x`.
 - **Expected:** `WP_Error('gdtg_docx_path_traversal')` for each.
 
 #### TC-C05: Nested ZIP
 - **Priority:** P1 · **Type:** S
 - **Steps:** Entry with `.zip` extension.
 - **Expected:** `WP_Error('gdtg_docx_nested_zip')`.
 - **Note:** Current implementation detects nested ZIPs by entry name extension only; it does not inspect entry contents for PKZIP magic.
 
 #### TC-C06: Entry-count cap (boundary)
 - **Priority:** P1 · **Type:** S
 - **Steps:** ZIP with exactly 10000 entries (pass) and 10001 (fail).
 - **Expected:** 10000 → pass; 10001 → `WP_Error('gdtg_docx_too_many_entries')`.
 
 #### TC-C07: Compression bomb (boundary)
 - **Priority:** P1 · **Type:** S
 - **Steps:** Entry with uncompressed/compressed ratio = 100 (pass) and >100 (fail).
 - **Expected:** ratio 100 → pass; ratio 101 → `WP_Error('gdtg_docx_zip_bomb')`.

#### TC-C08: Order-of-checks invariant
- **Priority:** P2 · **Type:** S
- **Steps:** File that is both missing-magic and would traverse.
- **Expected:** Earliest failing check (magic) reported — deterministic order.

### Group D — Renderers (`GDTG_Block_Renderer`, `GDTG_HTML_Renderer`)

Harness: `tests/parser-renderer-test.php`.

#### TC-D01: Block markup validity
- **Priority:** P1 · **Type:** U
- **Steps:** Render each node type; assert opening/closing block comment pairs and explicit attributes.
- **Expected:** Valid `<!-- wp:* -->` delimiters; no Gutenberg "block validation" mismatch shapes; no trailing semicolons in inline styles (known pitfall).

#### TC-D02: Escaping / XSS guard
- **Priority:** P1 · **Type:** S/U
- **Steps:** Node content containing `"><script>alert(1)</script>`.
- **Expected:** Output is escaped; no executable markup emitted.

#### TC-D03: `heading_demotion` override (boundary)
- **Priority:** P2 · **Type:** U
- **Steps:** Demotion 0,1,5 against H1/H3/H6.
- **Expected:** Levels drop by N, never exceed H6; demotion 0 = no-op.

#### TC-D04: `min_heading_level` override (boundary)
- **Priority:** P2 · **Type:** U
- **Steps:** min=3 with H1/H2 sources.
- **Expected:** H1/H2 promoted to H3; deeper headings untouched.

#### TC-D05: `default_alignment` override
- **Priority:** P3 · **Type:** U
- **Expected:** Applied only to paragraphs without explicit alignment; explicit alignment preserved.

#### TC-D06: Demotion + min interaction
- **Priority:** P2 · **Type:** U
- **Steps:** demotion=2 and min=3 together.
- **Expected:** Documented precedence holds; result clamped to valid 1–6 range.

#### TC-D07: HTML renderer parity
- **Priority:** P3 · **Type:** U
- **Steps:** Render same AST through HTML renderer.
- **Expected:** Classic HTML equivalent; overrides honored; escaping identical.

### Group E — `GDTG_Import_Orchestrator`

Harness: `tests/test-orchestrator.php`.

#### TC-E01: Google Doc → new draft
- **Priority:** P1 · **Type:** U
- **Steps:** Stub API + parser; `post_id=0`.
- **Expected:** `wp_insert_post()` as draft; returns `{success, post_id, title, edit_url}`.

#### TC-E02: Existing post overwrite vs draft
- **Priority:** P1 · **Type:** U
- **Steps:** `post_id` provided with overwrite true/false.
- **Expected:** Overwrite → `wp_update_post()`; non-overwrite path respects documented semantics; no accidental publish of drafts.

#### TC-E03: Drive `.docx` routing
- **Priority:** P2 · **Type:** U
- **Steps:** Stub `get_drive_file_metadata` (valid docx MIME) + `fetch_drive_file` bytes.
- **Expected:** Routes through zip validate → docx parser; non-docx MIME → error before download/parse.

#### TC-E04: Local `.docx` import
- **Priority:** P2 · **Type:** U
- **Expected:** Validate → parse → render → save; temp file cleaned on success and failure.

#### TC-E05: Bulk row normalization (boundary)
- **Priority:** P2 · **Type:** U
- **Steps:** `normalize_bulk_row_options()` with valid row, missing source, bad canonical URL.
- **Expected:** Canonicalized options on valid; `WP_Error` on invalid; row count cap honored.

#### TC-E06: Re-sync metadata persistence
- **Priority:** P2 · **Type:** U
- **Expected:** `_gdtg_source_url/_type/_content_hash/_source_modified_time` written after import.

#### TC-E07: Oversized doc — chunked streaming import (when Drive HTML export fails)
- **Priority:** P1 · **Type:** U
- **Precondition:** `$doc_json` exceeds `GDTG_LARGE_DOC_BYTE_THRESHOLD`; `export_google_doc_as_html()` returns `WP_Error` or empty.
- **Steps:** Feed oversized JSON through `import_google_doc()` with `post_id=0`.
- **Expected:** `GDTG_Large_Doc_Streamer::stream()` invoked; `wp_update_post()` called ≥2 times (batch commits); return array includes `'streamed' => true`; `_gdtg_last_sync_status` = `'success'`; `_gdtg_sync_progress` deleted after completion; sync-log contains at least one `step = large_doc_partial` event with `progress` between 55 and 100.
- **Edge (TC-E07b):** Empty parsed nodes → `WP_Error('gdtg_empty_doc')` returned directly (no shell created, no deferred status).
- **Edge (TC-E07c):** Partial commit failure mid-stream → `_gdtg_last_sync_status` = `'error'`; partial `post_content` preserved; shell NOT deleted.
- **Edge (TC-E07d):** `import_images` forced to `false` regardless of caller options; no image sideload jobs created.

#### TC-E08: Oversized doc — Drive HTML export still takes priority
- **Priority:** P2 · **Type:** U
- **Precondition:** `$doc_json` exceeds threshold; `export_google_doc_as_html()` returns valid HTML.
- **Expected:** Drive HTML fallback path used (wrapped in `wp:html` block); `GDTG_Large_Doc_Streamer::stream()` NOT called; result includes `'export_fallback' => true`.

### Group F — `GDTG_Post_Meta_Applier`

Harness: `tests/post-meta-applier-test.php`.

#### TC-F01: First-node metadata table extraction
- **Priority:** P2 · **Type:** U
- **Steps:** AST whose first node is a key/value metadata table.
- **Expected:** `extract_metadata_table()` returns parsed map; non-first tables ignored.

#### TC-F02: SEO keys (Yoast + RankMath)
- **Priority:** P2 · **Type:** U
- **Expected:** Writes both Yoast (`_yoast_wpseo_*`) and RankMath (`rank_math_*`) keys from `seo_title`/`seo_description`.

#### TC-F03: Taxonomy resolution
- **Priority:** P2 · **Type:** U
- **Steps:** Categories/tags by name; stub term lookup/create.
- **Expected:** Resolved to term IDs; missing terms handled per policy.

#### TC-F04: Guarded custom meta (security)
- **Priority:** P1 · **Type:** S
- **Steps:** Metadata includes a private key (leading `_`) and a disallowed key.
- **Expected:** Private/disallowed keys rejected; only allowlisted custom meta written.

#### TC-F05: Featured image
- **Priority:** P3 · **Type:** U
- **Expected:** Sideloaded attachment set as `_thumbnail_id`; failure is non-fatal.

#### TC-F06: ACF path
- **Priority:** P3 · **Type:** U
- **Steps:** `update_field()` available vs absent.
- **Expected:** Uses `update_field()` when present; degrades safely when ACF absent.

### Group G — `GDTG_REST_Endpoints` (permissions + handlers)

Harness: `tests/rest-endpoints-test.php` (unit). Integration items flagged for the GAP harness.

#### TC-G01: IDOR — edit_post on target
- **Priority:** P1 · **Type:** S
- **Steps:** `post_id` set, user lacks `edit_post` for it.
- **Expected:** `check_permissions()` denies (403/`rest_forbidden`).

#### TC-G02: New post requires edit_posts
- **Priority:** P1 · **Type:** S
- **Steps:** No `post_id`, user lacks `edit_posts`.
- **Expected:** Denied.

#### TC-G03: Post type allowlist
- **Priority:** P2 · **Type:** S
- **Steps:** Target a non `post`/`page` type.
- **Expected:** Rejected.

#### TC-G04: Nonce required
- **Priority:** P1 · **Type:** S/I
- **Steps:** Request without/invalid `wp_rest` nonce (integration harness).
- **Expected:** 401/403.

#### TC-G05: `parse_source_reference` classification
- **Priority:** P2 · **Type:** U
- **Steps:** GDoc URL, raw doc ID, Drive file URL, junk string.
- **Expected:** `{type:gdoc}`, `{type:gdoc}`, `{type:drive_file}`, `WP_Error` respectively.

#### TC-G06: Input sanitization
- **Priority:** P2 · **Type:** S
- **Expected:** Strings via `sanitize_text_field`, ints via `absint`; overrides clamped (demotion 0–5, min 1–6, alignment enum).

#### TC-G07: Bulk row cap
- **Priority:** P2 · **Type:** U
- **Steps:** `rows` length 100 (ok) and 101 (reject).
- **Expected:** >100 rejected before processing.

#### TC-G08: Sync routes are admin-only
- **Priority:** P1 · **Type:** S
- **Steps:** `GET /sync/status`, `POST /sync/run`, `POST /sync/settings/{id}` as non-admin.
- **Expected:** `manage_options` enforced; denied for editors.

#### TC-G09: Job status / continue
- **Priority:** P3 · **Type:** U
- **Expected:** Transient-backed job state returned; continue resumes batched job; unknown job → error.

#### TC-G10: Sync progress field in single-post status
- **Priority:** P2 · **Type:** U
- **Steps:** Query `GET /sync/status?post_id=<id>` when `_gdtg_sync_progress` meta is absent (idle) and when set to 75 (streaming).
- **Expected:** Idle response returns `"sync_progress": 0`; streaming response returns `"sync_progress": 75`; field always present (never omitted) in single-post response; NOT present in list-all response.

### Group H — `GDTG_CLI_Command`

Harness: `tests/cli-command-test.php`.

#### TC-H01: `import <url>` arg mapping
- **Priority:** P2 · **Type:** U
- **Expected:** assoc_args → options (`--output`, `--heading-demotion`, `--min-heading-level`, `--default-alignment`, `--draft`, `--post_type`, `--post_id`); delegates to orchestrator.

#### TC-H02: `import-docx <path>`
- **Priority:** P2 · **Type:** U
- **Expected:** Local path validated + parsed; same options surface as import.

#### TC-H03: `import-bulk` dry-run
- **Priority:** P2 · **Type:** U
- **Steps:** `--dry_run` / `--dry-run`.
- **Expected:** Validates rows, reports per-row pass/fail, performs no writes.

#### TC-H04: `sync <post_id> --force`
- **Priority:** P2 · **Type:** U
- **Expected:** Force bypasses conflict skip; non-force respects hash conflict.

#### TC-H05: `status [job_id] --all`
- **Priority:** P3 · **Type:** U
- **Expected:** Reads transient job state; `--all` lists recent jobs; unknown id → graceful message.

#### TC-H06: Error surfaces as `WP_CLI::error`
- **Priority:** P3 · **Type:** U
- **Expected:** Orchestrator `WP_Error` becomes non-zero CLI exit.

### Group I — `GDTG_Sync_Scheduler`

Harness: `tests/sync-scheduler-test.php`.

#### TC-I01: Change detection
- **Priority:** P2 · **Type:** U
- **Steps:** Stub `get_drive_file_metadata` modifiedTime newer than stored.
- **Expected:** Post queued for re-import; counted in `synced`.

#### TC-I02: Unchanged skip
- **Priority:** P2 · **Type:** U
- **Expected:** Equal modifiedTime → skipped, not re-imported.

#### TC-I03: Local-edit conflict
- **Priority:** P1 · **Type:** U
- **Steps:** Content hash mismatch, `force=false`.
- **Expected:** Counted as `conflicts`, not overwritten; `force=true` overrides.

#### TC-I04: Query filter
- **Priority:** P2 · **Type:** U
- **Expected:** Only `_gdtg_auto_sync=1` and `_gdtg_source_type IN (gdoc, drive_file)` selected.

#### TC-I05: Idempotent scheduling
- **Priority:** P2 · **Type:** U
- **Steps:** `maybe_reschedule()` when enabled/disabled.
- **Expected:** Event exists when enabled, cleared when disabled; no duplicate events.

#### TC-I06: Dry-run summary
- **Priority:** P3 · **Type:** U
- **Expected:** `{checked,synced,skipped,conflicts,failed,dry_run}` accurate; no writes on dry-run.

### Group J — `GDTG_Admin` OAuth state / CSRF

Harness: `tests/oauth-state-test.php` (~16 assertions). Unit-isolates the state helpers.

#### TC-J01: State generation
- **Priority:** P1 · **Type:** S
- **Expected:** `generate_oauth_state()` returns 32-char cryptographic string; stored as transient with 15-min TTL.

#### TC-J02: Single-use validation
- **Priority:** P1 · **Type:** S
- **Steps:** Validate once, then again with same state.
- **Expected:** First passes and deletes transient; second fails (replay blocked).

#### TC-J03: Expired state
- **Priority:** P1 · **Type:** S
- **Steps:** Simulate TTL elapsed.
- **Expected:** `validate_oauth_state()` returns false.

#### TC-J04: Mismatched/absent state
- **Priority:** P1 · **Type:** S
- **Expected:** Callback rejected; no token exchange attempted.

### Group K — `GDTG_API` token lifecycle

Harness: `tests/api-token-test.php` (~25 assertions). Stubs `wp_remote_*`.

#### TC-K01: Valid cached token
- **Priority:** P2 · **Type:** U
- **Expected:** `get_access_token()` returns stored token when `expires` in future; no network call.

#### TC-K02: Refresh on expiry
- **Priority:** P2 · **Type:** U
- **Steps:** `expires` in past.
- **Expected:** Refresh path invoked, new token stored with new expiry.

#### TC-K03: 401 refresh-and-retry
- **Priority:** P1 · **Type:** U
- **Steps:** `fetch_google_doc` first response 401, refresh succeeds, retry 200.
- **Expected:** Single transparent retry; success returned; no infinite loop on repeated 401.

#### TC-K04: Mode routing
- **Priority:** P2 · **Type:** U
- **Steps:** `gdtg_connection_mode` = saas vs enterprise.
- **Expected:** Correct token getter/refresh used per mode.

#### TC-K05: Drive metadata MIME guard
- **Priority:** P2 · **Type:** U/S
- **Steps:** `get_drive_file_metadata` returns non-docx MIME.
- **Expected:** Caller refuses download; no `fetch_drive_file` call.

#### TC-K06: HTTP timeout handling
- **Priority:** P3 · **Type:** U
- **Steps:** `wp_remote_get` returns WP_Error (timeout).
- **Expected:** Surfaced as WP_Error; no fatal; import fails cleanly.

### Group L — `GDTG_Sideloader`

Harness: `tests/sideloader-test.php`.

#### TC-L01: URL scheme guard
- **Priority:** P1 · **Type:** S
- **Steps:** `sideload()` with `file://`, `ftp://`, `javascript:` URLs.
- **Expected:** Rejected; only http/https proceed (SSRF guard).

#### TC-L02: `sideload_from_bytes`
- **Priority:** P2 · **Type:** U
- **Expected:** Writes via `wp_upload_bits()` + `wp_insert_attachment()`; returns attachment id.

#### TC-L03: Optimize path
- **Priority:** P3 · **Type:** U
- **Steps:** `gdtg_optimize_images` enabled.
- **Expected:** Resize/compress/WebP attempted; disabled flag skips optimization.

#### TC-L04: Failure is non-blocking
- **Priority:** P2 · **Type:** U
- **Expected:** Download failure returns `false`; pipeline continues, image omitted.

### Group M — Gutenberg Sidebar (`src/sidebar/index.js`) — e2e GAP

Manual smoke now; browser-driven automation when harness exists. Build/lint via `wp-scripts`.

#### TC-M01: State machine transitions
- **Priority:** P2 · **Type:** E2E/M
- **Expected:** `idle → syncing/polling → success/error`; success shows edit link; error shows message.

#### TC-M02: Source mode switch
- **Priority:** P2 · **Type:** E2E/M
- **Steps:** Toggle URL ↔ `.docx`.
- **Expected:** Contextual input swaps; payload/endpoint correct per mode.

#### TC-M03: `.docx` file picker
- **Priority:** P3 · **Type:** E2E/M
- **Expected:** Hidden input + trigger button; selected filename announced (`aria-live=polite`); long filename wraps, no horizontal overflow.

#### TC-M04: Busy-state disabling
- **Priority:** P2 · **Type:** E2E/M
- **Expected:** Source type, inputs, output mode, primary action, file button all disabled while syncing/polling.

#### TC-M05: Overwrite separation (existing post)
- **Priority:** P3 · **Type:** M
- **Expected:** `Overwrite Existing Content` visually separated from routine toggles; context-sensitive vs new-post draft flow.

#### TC-M06: Polling cleanup
- **Priority:** P2 · **Type:** E2E/M
- **Steps:** Start polling, then unmount/navigate away.
- **Expected:** Interval cleared (no polling leak — known pitfall).

#### TC-M07: Accessibility
- **Priority:** P3 · **Type:** M
- **Expected:** Labelled controls, logical DOM order, keyboard reachable, announced status. (WCAG 2.1 AA full validation requires manual AT + expert review.)

### Group M1 — Manual UI Smoke Checklist (release-build)

Run on a real WordPress admin + Gutenberg editor session after `pnpm run build`.

#### Environment
- WordPress 6.0+
- Plugin activated
- Sidebar assets built from current source (`build/index.js`, `build/index.css`, `build/index-rtl.css`)
- One editor-capable user and one admin user
- One editable existing post and one new draft post
- One known-good Google Doc URL and one known-good local `.docx` fixture

#### Manual flow M1-A: Google Doc import into existing post
1. Open existing post in Gutenberg.
2. Open DraftSync sidebar.
3. Confirm URL mode is visible by default or can be selected.
4. Paste valid Google Doc URL.
5. Toggle import options deliberately (`import_images`, `import_tables`, `overwrite`, `import_as_draft`, output mode).
6. Start import.
7. Observe busy state while request is active.
8. Wait for success state.
9. Verify imported blocks render in editor canvas.
10. Save/update post and reload editor.

Expected:
- Controls disable during request/polling.
- Success state includes clear completion signal and edit/view affordance if applicable.
- Imported content appears in editor without broken block warnings.
- Reload preserves imported content.

#### Manual flow M1-B: `.docx` import into existing post
1. Open existing post in Gutenberg.
2. Switch sidebar to `.docx` mode.
3. Use file picker to choose valid `.docx`.
4. Confirm selected filename is shown and announced.
5. Start import.
6. Wait for completion.
7. Verify imported blocks and media behavior in editor.

Expected:
- URL-only fields disappear or disable when `.docx` mode is active.
- File-picker trigger works with mouse and keyboard.
- Long filename does not overflow sidebar width.
- Successful import does not leave stale busy state.

#### Manual flow M1-C: New-post / draft-safe behavior
1. Open a new post.
2. Open DraftSync sidebar.
3. Import valid Google Doc URL with draft-related options enabled.
4. Complete import.

Expected:
- New-post flow does not present overwrite behavior as the primary path.
- Draft/import defaults are intelligible for a new post context.
- No accidental publish-state escalation.

#### Manual flow M1-D: Error handling
1. Use invalid Google Doc URL.
2. Retry with malformed/unsupported `.docx`.
3. Trigger one permission/auth failure scenario if reproducible in staging.

Expected:
- Error message is visible, human-readable, and non-empty.
- Sidebar returns to usable state after failure.
- Retry path works without page reload.
- No stuck polling loop.

#### Manual flow M1-E: Unmount / navigation cleanup
1. Start an import that enters polling state.
2. Navigate away, reload, or close the editor before completion.
3. Re-open editor.

Expected:
- No duplicate polling or repeated status churn after remount.
- Fresh sidebar instance starts clean.
- Browser console shows no interval/cleanup-related errors.

### Group M2 — Browser-Driven E2E Outline (Chrome DevTools / browser tool)

Implement with the browser automation tooling already available for this repo. Prefer stable `data-gdtg-*` selectors over text-only selectors in sidebar markup.

#### Spec M2-01: URL import happy path
- Seed editor page for an existing post.
- Open sidebar.
- Select URL mode.
- Fill valid Google Doc URL.
- Submit import.
- Wait for success state.
- Assert success message, disabled→enabled control transition, and imported block presence in editor DOM.

#### Spec M2-02: `.docx` import happy path
- Open sidebar.
- Switch to `.docx` mode.
- Upload valid fixture through the file input.
- Submit import.
- Assert selected filename, success state, and imported content presence.

#### Spec M2-03: Mode-switch UI contract
- Toggle URL → `.docx` → URL.
- Assert only relevant input controls are visible/enabled for the active mode.
- Assert stale values do not leak into the inactive mode payload.

#### Spec M2-04: Busy-state lockout
- Trigger import and hold the request long enough to observe the busy state.
- Assert source selector, text/file inputs, output mode, and primary CTA are disabled until request settles.

#### Spec M2-05: Failure recovery
- Submit invalid/unsupported input or exercise a controlled 4xx/5xx failure.
- Assert visible error state.
- Assert controls re-enable.
- Retry with success response and assert recovery without reload.

#### Spec M2-06: Polling cleanup on unmount
- Start import and confirm polling begins.
- Start import and confirm polling begins.
- Navigate away or close editor surface.
- Assert polling stops after unmount.

#### Spec M2-07: Accessibility smoke
- Keyboard-tab through sidebar controls.
- Assert reachable focus order.
- Assert filename/status live region updates.
- Optionally run automated a11y scan for obvious violations; keep manual AT review separate.

### Group M3 — End-to-End Import Flow Matrix

Use this matrix to ensure the full DraftSync user journey is covered beyond isolated UI behavior.

| Flow | Entry surface | Source | Expected backend path | Final assertion |
|---|---|---|---|---|
| M3-A | Gutenberg sidebar | Google Doc URL | REST import → orchestrator → parser → block renderer | Imported blocks visible in editor and persist after save/reload |
| M3-B | Gutenberg sidebar | Local `.docx` | REST/ upload path → ZIP validator → `.docx` parser → renderer | Imported blocks visible; invalid file rejected cleanly |
| M3-C | Admin settings/import manager | Re-sync action | Admin/REST action → orchestrator re-import → meta/hash update | Updated content appears without duplicate metadata drift |
| M3-D | CLI | `import` / `import-docx` | CLI → orchestrator → parser/render/save | Exit code, summary output, and saved post state match expectation |
| M3-E | Scheduler | Auto-sync linked post | Cron/scheduler → API metadata check → conditional re-import | Changed source syncs; unchanged source skips; conflict respects force policy |

Minimum release-flow coverage:
- One successful sidebar Google Doc import (`M3-A`)
- One successful sidebar `.docx` import (`M3-B`)
- One failed import with visible recovery path
- One re-sync verification (`M3-C` or `M3-E`)

### Group M4 — UI / E2E Release Gates

Treat these as required before calling a sidebar/admin-import release done:

- `pnpm run lint:js` passes.
- `pnpm run build` emits current sidebar assets.
- Group M1 manual smoke completed on release build.
- At least one real Google Doc import and one real `.docx` import completed end-to-end.
- No stuck busy state, no polling leak, no block validation warning after reload.
- Failure path verified once: user can recover and retry without editor reload.
- If browser-driven M2 automation exists, M2-01 through M2-06 pass in local release verification.

### Group N — `GDTG_Large_Doc_Streamer` (chunked streaming import)

Harness: `tests/large-doc-streamer-test.php` (27 assertions).

#### TC-N01: Empty nodes → WP_Error
- **Priority:** P2 · **Type:** U
- **Steps:** Call `stream()` with empty array.
- **Expected:** Returns `WP_Error('gdtg_empty_doc')`; no `wp_update_post` call.

#### TC-N02: Chunk loop — 90 nodes → 3 batches
- **Priority:** P1 · **Type:** U
- **Precondition:** 90 `GDTG_Doc_Node` paragraph nodes, `BATCH_SIZE=40`.
- **Steps:** Call `stream()` with progress callback.
- **Expected:** `wp_update_post` called exactly 3 times; callback called 3 times; last callback `rendered=90`, `total=90`, `percent=95`; final markup contains 90 `<!-- wp:paragraph -->` markers.

#### TC-N03: Progress band 55–95
- **Priority:** P2 · **Type:** U
- **Steps:** Collect all callback `percent` values during TC-N02.
- **Expected:** Every `percent` value is in the inclusive range [55, 95].

#### TC-N04: Exact BATCH_SIZE nodes → 1 batch
- **Priority:** P2 · **Type:** U
- **Steps:** 40 nodes → `stream()`.
- **Expected:** 1 `wp_update_post` call; 1 callback; percent = 95.

#### TC-N05: Single node → 1 commit
- **Priority:** P3 · **Type:** U
- **Expected:** 1 commit; returned markup contains the node content.

#### TC-N06: Cumulative content correctness
- **Priority:** P2 · **Type:** U
- **Steps:** 5 nodes with distinct content strings → `stream()`.
- **Expected:** Final markup contains all 5 distinct strings (content accumulated, never lost).

#### TC-N07: `wp_update_post` failure mid-stream
- **Priority:** P1 · **Type:** U
- **Steps:** Mock `wp_update_post` to return `WP_Error` on the second batch.
- **Expected:** `stream()` returns `WP_Error('gdtg_partial_commit_failed')`; first batch content remains in post.

#### TC-N08: Images forced off during streaming
- **Priority:** P2 · **Type:** U
- **Precondition:** Oversized doc with images in parser output.
- **Steps:** Verify `parser_options` set by orchestrator before calling `stream()`.
- **Expected:** `import_images=false`, `defer_images=false` regardless of caller's original options.

#### TC-N09: Sync-log context includes `progress` key
- **Priority:** P2 · **Type:** U
- **Steps:** Trigger a streamed import; read `_gdtg_sync_events` post meta.
- **Expected:** At least one event with `context.step === 'large_doc_partial'` and `context.progress` as integer 55–100.

#### TC-N10: `streamed` flag on success response
- **Priority:** P2 · **Type:** U
- **Expected:** Orchestrator return array for the streaming path includes `'streamed' => true`; normal-size and export-fallback paths do not.

## Execution Workflow

Use this workflow to avoid a noisy test → fix → retest loop for isolated assertions.

### Phase 1 — Lock test truth
- Treat this document as the executable QA contract.
- Keep test IDs stable.
- Correct the plan first when implementation truth and planned expectations disagree.
- Prioritize by risk: P1 security/core correctness, then P2 regression behavior, then P3 UI/e2e/manual polish.

### Phase 2 — Build missing harnesses before changing product code
- Implement missing standalone harnesses in priority order:
  1. `tests/zip-validator-test.php`
  2. `tests/oauth-state-test.php`
  3. `tests/docx-parser-test.php`
  4. `tests/api-token-test.php`
- Do not change production behavior unless a harness exposes a real defect.
- Encode current intended behavior, not guesses.

### Phase 3 — Run one baseline sweep
- Run the existing harnesses plus new harnesses relevant to current gaps.
- Include syntax/lint/build checks needed to validate touched surfaces.
- Record findings before fixing anything.

### Phase 4 — Triage findings once
- Classify each failure as one of:
  - test expectation bug
  - production bug
  - environment/flaky issue
  - missing coverage
- Group defects by subsystem, not by discovery order.

### Phase 5 — Fix in subsystem batches
- Fix related failures together:
  - ZIP / `.docx` security
  - OAuth / token lifecycle
  - parser / renderer / import path
  - REST / CLI / scheduler
  - sidebar UI / e2e
- After each batch, rerun only the relevant subsystem checks.

### Phase 6 — Full verification pass
- After batched fixes land, run the full relevant verification set once:
  - standalone PHP harnesses
  - syntax sweep
  - `pnpm run lint:js`
  - `pnpm run build`
  - Group M manual smoke
  - browser-driven M2 e2e if present

### Phase 7 — Release decision
- Ship only if:
  - all P1 cases pass
  - no unresolved Critical/High defects remain
  - sidebar/manual flow gates in Group M4 are satisfied
  - one real Google Doc import and one real `.docx` import succeed end-to-end
  - one re-sync path is verified

---

## Risk Assessment

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Malicious `.docx` (bomb/traversal/nested) bypasses validator | High | Medium | Group C adversarial fixtures; treat as P1 release gate |
| OAuth CSRF / state replay | High | Low | Group J single-use + TTL tests; manual flow check |
| IDOR via `post_id` | High | Medium | Group G permission tests + integration nonce/cap |
| Gutenberg block validation breakage | High | Medium | Group D markup-validity + broad parser fixtures on every change |
| XSS via unescaped doc content | High | Low | TC-D02/TC-A01 escaping assertions |
| Token refresh loop / auth failure | Medium | Medium | TC-K03 single-retry guard |
| Private/disallowed meta written | Medium | Low | TC-F04 guarded-meta test |
| Polling interval leak in sidebar | Medium | Medium | TC-M06 cleanup-on-unmount |
| Metadata dropped on import | Medium | Medium | Group F + orchestrator persistence tests |
| Partial commit failure on oversized doc | Medium | Medium | TC-N07 mid-stream failure test; shell preserved for retry |

## Entry / Exit Criteria

### Entry (begin a QA cycle when)
- [ ] `wp-scripts lint-js` passes.
- [ ] `wp-scripts build` emits `build/index.js`, `build/index.css`, `build/index-rtl.css` in sync with source.
- [ ] All 8 existing PHP harnesses pass on PHP 7.4+.
- [ ] PHP `-l` syntax clean across `includes/`.

### Exit (QA complete when)
- [ ] All P1 cases pass (security + permissions + block validity).
- [ ] No open Critical/High defects.
- [ ] New gap harnesses (C, J at minimum) implemented and green.
- [ ] Manual Gutenberg sidebar smoke (Group M) completed for the release build.
- [ ] Coverage of changed code demonstrably exercised (see regression mapping).

## Risk-Based Regression Mapping

Run when the listed files change (`git diff main...HEAD --name-only`).

| Changed path | Required suites |
|---|---|
| `includes/class-gdtg-parser.php` | Group A + D, `parser-renderer-test.php` |
| `includes/class-gdtg-docx-parser.php` | Group B + C + D |
| `includes/class-gdtg-zip-validator.php` | Group C (all P1) |
| `includes/class-gdtg-import-orchestrator.php` | Group E + Group N + REST + CLI |
| `includes/class-gdtg-large-doc-streamer.php` | Group N |
| `includes/class-gdtg-post-meta-applier.php` | Group F |
| `includes/class-gdtg-rest-endpoints.php` | Group G (+ integration) |
| `includes/class-gdtg-cli-command.php` | Group H |
| `includes/class-gdtg-sync-scheduler.php` | Group I |
| `includes/class-gdtg-sync-log.php` | Group E (TC-N09 sync-log context), Group N |
| `includes/class-gdtg-admin.php` | Group J + manual OAuth flow |
| `includes/class-gdtg-api.php` | Group K |
| `includes/class-gdtg-sideloader.php` | Group L |
| `src/sidebar/*` | lint + build + Group M |

Always include: a real Google Doc import smoke, a `.docx` upload smoke, and one re-sync.

## Coverage-Gap Closure Roadmap

Priority order to reach defensible coverage:

 1. **`tests/zip-validator-test.php`** (Group C) — security-critical, currently untested in isolation. Cover actual validator thresholds and `gdtg_docx_*` error codes. **P1.**
 2. **`tests/oauth-state-test.php`** (Group J) — CSRF surface. **P1.**
 3. **`tests/docx-parser-test.php`** (Group B) — second import path, only exercised indirectly; verify safe empty-result failures for missing/malformed OOXML. **P2.**
 4. **`tests/api-token-test.php`** (Group K) — refresh/retry correctness. **P2.**
5. **WP PHPUnit integration harness** — real nonce + capability enforcement for Group G. **P2.**
6. **Browser-driven e2e automation** for the sidebar (Group M). **P3.**

## Execution Reference

```bash
# Existing PHP harnesses
php tests/parser-renderer-test.php
php tests/sideloader-test.php
php tests/test-orchestrator.php
php tests/rest-endpoints-test.php
php tests/cli-command-test.php
php tests/post-meta-applier-test.php
php tests/sync-scheduler-test.php
php tests/large-doc-streamer-test.php

# Syntax sweep
for f in includes/*.php; do php -l "$f"; done

# Frontend
pnpm run lint:js
pnpm run build

# New gap harnesses (once created)
php tests/zip-validator-test.php
php tests/oauth-state-test.php
php tests/docx-parser-test.php
php tests/api-token-test.php
```
