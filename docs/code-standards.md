# DraftSync — Code Standards & Conventions

## 1. PHP Version Requirement

**PHP 7.4 minimum.** This aligns with the lowest PHP version WordPress itself supports in recent releases.

### What This Means in Practice

| Feature | Allowed | Reason |
|---|---|---|
| `array_key_first()`, `array_key_last()` | No | PHP 7.3+ but not widely available on shared hosting |
| Null coalescing (`??`) | Yes | PHP 7.0+ |
| Null coalescing assignment (`??=`) | Yes | PHP 7.4+ |
| Arrow functions (`fn() =>`) | Yes | PHP 7.4+ |
| Typed properties | No | Avoid — WP ecosystem still catches up |
| Constructor promotion | No | Avoid — breaks readability on older tooling |
| Named arguments | No | PHP 8.0+ |
| Fibers / enums | No | PHP 8.1+ |
| `match` expression | No | PHP 8.0+ |

**Rule:** All public properties in `GDTG_Doc_Node` use plain `public` declarations with `@var` docblocks. No typed properties or constructor promotion.

---

## 2. WordPress Coding Standards

The codebase follows **WordPress Coding Standards (WPCS)** with these specifics:

| Convention | Rule |
|---|---|
| Indentation | Tabs, not spaces |
| Brace style | Allman for classes/functions, K&R for control structures |
| Yoda conditions | Always (`'foo' === $var`, not `$var === 'foo'`) |
| String comparison | Strict (`===`, `!==`) |
| Array syntax | `array()` long form preferred (PHP 7.4 compat) |
| Naming — classes | `GDTG_PascalCase` with `GDTG_` prefix |
| Naming — methods | `snake_case` |
| Naming — variables | `$snake_case` |
| Naming — constants | `GDTG_UPPER_SNAKE_CASE` |
| Naming — hooks | `gdtg_` prefix |
| Docblocks | PHPDoc format on every public/protected method |
| Internationalization | All user-facing strings wrapped in `__()` or `esc_html__()` with text domain `'draftsync'` |

---

## 3. File Structure Conventions

### ABSPATH Guard

Every PHP file begins with:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

This prevents direct file access outside the WordPress context.

### File Naming

| Pattern | Example | Used For |
|---|---|---|
| `class-gdtg-{name}.php` | `class-gdtg-parser.php` | Single-class files |
| `draftsync.php` | — | Main plugin entry point |
| `src/index.js` | — | Webpack entry point |
| `src/sidebar/index.js` | — | React component entry |

### Class-to-File Mapping

| File | Class |
|---|---|
| `class-gdtg-loader.php` | `GDTG_Loader` |
| `class-gdtg-admin.php` | `GDTG_Admin` |
| `class-gdtg-api.php` | `GDTG_API` |
| `class-gdtg-parser.php` | `GDTG_Parser` |
| `class-gdtg-docx-parser.php` | `GDTG_Docx_Parser` |
| `class-gdtg-zip-validator.php` | `GDTG_Zip_Validator` |
| `class-gdtg-import-orchestrator.php` | `GDTG_Import_Orchestrator` |
| `class-gdtg-cli-command.php` | `GDTG_CLI_Command` |
| `class-gdtg-doc-node.php` | `GDTG_Doc_Node` |
| `class-gdtg-block-renderer.php` | `GDTG_Block_Renderer` |
| `class-gdtg-html-renderer.php` | `GDTG_HTML_Renderer` |
| `class-gdtg-sideloader.php` | `GDTG_Sideloader` |
| `class-gdtg-rest-endpoints.php` | `GDTG_REST_Endpoints` |

---

## 4. Loader Pattern

All WordPress hook registrations are mediated through `GDTG_Loader`:

```php
// In a class constructor:
$this->loader->add_action( 'admin_menu', $this, 'register_admin_menu' );
$this->loader->add_filter( 'some_filter', $this, 'some_callback', 10, 2 );

// In the main controller:
$this->loader->run();  // Binds all collected hooks
```

**Why:** Centralizes hook registration, makes dependency order explicit, and allows testing hooks in isolation.

**Rule:** Never call `add_action()` or `add_filter()` directly inside service classes. Always go through the loader.

---

## 5. Escaping Contract

The parser and renderer have a strict division of escaping responsibilities:

### Parsers (`GDTG_Parser`, `GDTG_Docx_Parser`)

- **Escapes** all text content via `esc_html()` before placing into `$node->content`.
- **Escapes** URLs via `esc_url()`.
- **Escapes** image alt text via `esc_attr()`.
- Output is safe inline HTML — the renderer must NOT re-escape `$node->content`.

### Renderers (`GDTG_Block_Renderer`, `GDTG_HTML_Renderer`)

- Treats `$node->content` as **pre-escaped safe HTML** — passes through directly.
- **Escapes** block comment attributes: `wp_json_encode()` for the JSON comment, `esc_attr()` for HTML attributes.
- **Escapes** URLs: `esc_url()` for image `src`, link `href`.

### Sideloader (`GDTG_Sideloader`)

- Validates URL scheme (`http`/`https` only) before passing to `media_sideload_image()`.
- No additional escaping needed — WordPress handles the download.

**Rule:** If you add a new content type, escape at the parser level. The renderer is a passthrough for content.

---

## 6. OOXML Namespace Handling

The `.docx` parser (`GDTG_Docx_Parser`) works with OOXML namespaces in SimpleXML. These patterns are standardized across the codebase:

### Namespace Registration

```php
// Always register namespaces explicitly before parsing
$namespaces = array(
    'w'  => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
    'r'  => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
    'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
);
```

### Accessing Namespaced Elements

```php
// Use the namespace prefix with SimpleXML children() method
$body = $xml->children( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' )->body;
$paragraphs = $body->children( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' )->p;
```

### Attribute Access

OOXML attributes use namespace URIs directly (not registered prefixes):

```php
// Access element attributes with namespace URIs
$style_id = (string) $para[ $ns_uri . ':val' ];
$num_id   = (string) $numPr->numId[ $ns_uri . ':val' ];
```

**Rules:**
- Never use `ZipArchive::extractTo()` — parse document.xml from the ZIP stream in memory.
- Never fetch external URLs during parsing — all data comes from the ZIP.
- Always use namespace-aware XPath or `children()` calls. Never rely on unprefixed element names.
- Validate all attribute values exist before casting to string.

---

## 7. ZIP Validation Security Patterns

`GDTG_Zip_Validator` enforces security boundaries on all .docx uploads:

| Check | Implementation | Why |
|---|---|---|
| **Magic bytes** | First 4 bytes must be `PK\x03\x04` (PKZIP signature) | Prevents non-ZIP files from reaching the parser |
| **Path traversal** | No entry name may contain `../`, `..\`, or absolute paths starting with `/` | Prevents ZIP slip attacks |
| **Nested ZIPs** | No entry may have a `.zip` extension or PKZIP magic bytes in content | Prevents zip bomb nesting |
| **Entry limit** | Maximum 1000 entries per ZIP | Prevents resource exhaustion |
| **Compression ratio** | No single entry may compress >20x (uncompressed/compressed ratio) | Prevents zip bombs (e.g. 42KB → 4.5PB) |

```php
// Validation returns true or WP_Error with specific error code
$result = GDTG_Zip_Validator::validate( $file_path );
if ( is_wp_error( $result ) ) {
    // $result->get_error_code() → 'invalid_zip', 'path_traversal', 'nested_zip',
    //   'too_many_entries', 'compression_bomb', 'cannot_open'
}
```

**Rules:**
- All .docx upload paths MUST pass through `GDTG_Zip_Validator::validate()` before parsing.
- The validator is called by `GDTG_Import_Orchestrator` — individual callers should not bypass it.
- New validators added to the pipeline must return `true|WP_Error` for consistency.

---

## 8. AST Design Patterns

### Node Structure

```php
$node = new GDTG_Doc_Node( $type, $content, $attrs, $children );
```

| Field | Type | Escaping | Example |
|---|---|---|---|
| `$type` | string | Internal enum, never user-supplied | `'paragraph'`, `'heading'`, `'image'` |
| `$content` | string | Pre-escaped inline HTML | `'<strong>Hello</strong>'` |
| `$attrs` | array | Escaped at render time | `['level' => 2, 'align' => 'center']` |
| `$children` | GDTG_Doc_Node[] | Recursive | Lists contain `list_item` nodes |

### Known Node Types

| Type | Has Content | Has Children | Has Attrs |
|---|---|---|---|
| `paragraph` | Yes | No | `align` |
| `heading` | Yes | No | `level`, `align` |
| `image` | No | No | `id`, `url`, `alt` |
| `list` | No | Yes (`list_item`) | `ordered` (bool) |
| `list_item` | Yes | Yes (nested `list`) | — |
| `table` | No | Yes (`table_row`) | — |
| `table_row` | No | Yes (`table_cell`) | — |
| `table_cell` | Yes (inline HTML) | No | — |
| `nextpage` | No | No | — |

---

## 9. React Component Patterns

### State Machine

The sidebar component uses a simple finite state machine:

```
idle → syncing → success
                → error → idle
```

State is managed via React `useState`:

```javascript
const [status, setStatus] = useState('idle'); // 'idle' | 'syncing' | 'success' | 'error'
```

### Gutenberg Component Usage

- Uses `@wordpress/components` for UI: `PanelBody`, `TextControl`, `Button`, `Spinner`, `Notice`.
- Uses `@wordpress/edit-post` for `PluginSidebar` registration.
- Uses `@wordpress/api-fetch` for REST API calls (handles nonce automatically).
- Uses `@wordpress/i18n` for translatable strings.
- No external React state management (Redux, Zustand) — local `useState` only.

### API Communication

```javascript
const result = await apiFetch({
    path: settings.rest_url,
    method: 'POST',
    data: { doc_id: docUrl, post_id: settings.post_id },
});
```

The nonce is injected via `wp_localize_script()` in PHP as `GDTG_Settings.nonce`.

---


## 10. CSS Conventions

### Tone vs Layout Class Split

Stylesheets in DraftSync follow a strict separation between **tone** (visual semantics, color, emphasis) and **layout** (spacing, positioning, structure):

**Tone rules** belong in shared `assets/css/` stylesheets. They define the visual "mood" of a UI zone and are reusable across multiple contexts. Example:

```css
/* assets/css/import-caution.css — shared across editor + admin */
.gdtg-caution-zone {
    background: #fcf0e6;
    border-inline-start: 4px solid #b32d2e;
    padding-inline: 12px;
}
.gdtg-caution-zone__label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #b32d2e;
}
```

**Layout rules** stay in the component's own stylesheet. They define margins, padding, dividers, and structural positioning specific to that component:

```css
/* src/sidebar/sidebar.css — layout only, tone delegated to gdtg-caution-zone */
.gdtg-sidebar-destructive-option {
    margin-top: 10px;
    padding-top: 12px;
}
```

**Rules:**
- Tone rules MUST live in standalone `assets/css/` files and be enqueued via `wp_enqueue_style()` where needed.
- Layout rules MUST live in the component's own CSS (sidebar, admin, etc.) and reference shared tone classes by composition.
- Never duplicate tone rules (color, background, border, typographic emphasis) across component stylesheets — use the shared class.
- BEM-like naming: `.gdtg-{zone}` for the block, `.gdtg-{zone}__{element}` for children.

## 11. Error Handling Conventions

### PHP

| Pattern | Usage |
|---|---|
| `WP_Error` returns | API class methods (`fetch_google_doc`, `get_access_token`) return `WP_Error` on failure |
| `is_wp_error()` checks | Every caller checks before proceeding |
| `try/catch` | Used in REST handler for JSON decode failures |
| `wp_die()` | OAuth callback error pages (user-facing) |
| HTTP status codes | REST responses use 400 (bad input), 403 (forbidden), 404 (not found), 500 (server error) |

### JavaScript

```javascript
try {
    const result = await apiFetch({ ... });
    setStatus('success');
} catch (error) {
    setStatus('error');
    setErrorMsg(error.message || 'Import failed');
}
```

---

## 12. Security Patterns

| Pattern | Implementation | Location |
|---|---|---|
| **Nonce verification** | `wp_create_nonce('wp_rest')` on JS side; WordPress auto-verifies for REST routes | `enqueue_editor_assets()`, REST handler |
| **Nonce verification** (admin forms) | `check_admin_referer()` added to all admin form handlers; `@` error suppression operator removed site-wide | `GDTG_Admin` (settings save), `GDTG_REST_Endpoints` |
| **CSRF protection (OAuth)** | `wp_generate_password(32, false)` state param stored as transient, validated on callback | `generate_oauth_state()`, `validate_oauth_state()` |
| **IDOR prevention** | `check_permissions()` verifies `current_user_can('edit_post', $post_id)` per request | `GDTG_REST_Endpoints` |
| **Input sanitization** | `sanitize_text_field()` for strings, `absint()` for integers | REST route args, admin form handler |
| **Output escaping** | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()` | Parser, renderer, admin template |
| **URL scheme validation** | Only `http`/`https` allowed for image URLs | `build_image_node()`, `sideload()` |
| **Unsupported URL detection** | Regex blocks Google Sheets/Slides/Drive URLs with friendly error | `extract_doc_id()` |
| **ZIP security hardening** | Magic bytes, path traversal, nested zips, entry limits, compression ratio | `GDTG_Zip_Validator` |
| **Capability checks** | `manage_options` for admin page, `edit_post(s)` for import | Admin menu, REST permissions |
| **Token storage** | Stored in `wp_options` (encrypted at rest if DB encryption is configured) | `handle_oauth_redirect()` |
| **Disconnect action** | Protected by `check_admin_referer('gdtg_disconnect')` | `handle_oauth_redirect()` |

---

## 13. Testing Conventions

**Current state:** 13 standalone PHP test harnesses (530+ tests). 14+ PHP files syntax clean.

| Component | Details |
|---|---|
| Test file (parser + renderers) | `tests/parser-renderer-test.php` — ~130 assertions |
| Test file (REST endpoints) | `tests/rest-endpoints-test.php` — ~130 assertions |
| Test file (DOCX parser) | `tests/docx-parser-test.php` — ~55 assertions |
| Test file (orchestrator) | `tests/test-orchestrator.php` — ~45 assertions |
| Test file (post meta applier) | `tests/post-meta-applier-test.php` — ~35 assertions |
| Test file (sync scheduler) | `tests/sync-scheduler-test.php` — ~25 assertions |
| Test file (CLI commands) | `tests/cli-command-test.php` — ~25 assertions |
| Test file (sideloader) | `tests/sideloader-test.php` — ~10 assertions |
| Test file (ZIP validator) | `tests/zip-validator-test.php` — ~15 assertions |
| Test file (OAuth state) | `tests/oauth-state-test.php` — ~16 assertions |
| Test file (SaaS bridge URL) | `tests/saas-bridge-url-test.php` — ~20 assertions |
| Test file (API token) | `tests/api-token-test.php` — ~25 assertions |
| Fixtures | `tests/fixtures/google-docs/*.json`, `tests/fixtures/docx/*.docx` |
| Run command | `php tests/<test-file>.php` for each harness |
| Latest verified result | **All 12 harnesses passing** |
| Coverage | Paragraph, headings, inline styles, lists, tables, images, page breaks, escaping, style overrides, bulk normalization, metadata extraction, SEO fields, taxonomy, ACF, cron scheduling, conflict detection, sync-all, DOCX list numbering, ZIP security, OAuth state management, bridge URL validation, API token lifecycle |

### Conventions for New Tests

| Convention | Rule |
|---|---|
| Test file location | `tests/` for standalone harness; `tests/phpunit/` for PHPUnit if added later |
| Test file naming | `test-class-gdtg-parser.php` |
| Test method naming | `test_parse_paragraph_with_bold_text()` |
| Fixtures | Store Google Docs API JSON fixtures in `tests/fixtures/google-docs/` |
| Mocking | Mock `wp_remote_get`/`wp_remote_post` for API tests; mock `media_sideload_image` for sideloader tests |
| Coverage target | Parser: 100% branch coverage; Renderer: 100% node type coverage; REST: all error paths |

### Test Data Strategy

Use real Google Docs JSON exports (sanitized) as fixtures. The `research_notes.md` file contains the exhaustive style translation dictionary — every row in those tables should have a corresponding test case.

---

## 14. GDTG_Sync_Scheduler Conventions

### WP Cron Patterns

- Hook name: `gdtg_auto_sync_event` (constant `GDTG_Sync_Scheduler::HOOK`).
- Scheduling: idempotent via `maybe_reschedule()` on `admin_init`. Checks option `gdtg_auto_sync_enabled` and `gdtg_auto_sync_frequency` before scheduling.
- Custom intervals: `gdtg_twicedaily` (12 hours) added via `cron_schedules` filter.
- `ensure_scheduled()` and `clear_scheduled()` for explicit control from settings save handlers.

### Conflict Detection

- Each import stores `_gdtg_content_hash` (sha256 of post_content) and `_gdtg_source_modified_time` (Drive `modifiedTime` ISO string).
- On re-sync: compare stored hash against current `wp_hash(post_content)`. If mismatch, content was manually edited → conflict.
- Default: skip conflicted posts. `$force` parameter bypasses for explicit overwrite.

### CLI Shared Runner

- `run_scheduled_sync($limit, $force, $dry_run)` is called by both WP Cron callback and `wp draftsync sync-all`.
- Accepts `$limit` (max posts per run, 1–50, default 10 from option), `$force` (override conflicts), `$dry_run` (report only).
- Returns structured summary: `{checked, synced, skipped, conflicts, failed, dry_run, details[]}`.

---

## 15. Admin "Imported Docs" Manager UI Conventions

- Admin sub-page under DraftSync menu, rendered via `GDTG_Admin`.
- Lists all posts with `_gdtg_source_url` meta (any import source).
- Columns: post title, source type (gdoc/drive_file), source URL (truncated), last imported date, auto-sync status, actions (re-sync, view).
- Re-sync action calls `POST /gdtg/v1/sync/{post_id}` via admin-ajax.
- Styling: reuses `assets/css/admin-settings.css` namespace (`.gdtg-admin-*`) for visual consistency.
