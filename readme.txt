=== DraftSync ===
Contributors: cortisol
Tags: google-docs, gutenberg, import, docx, document
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Google Docs and .docx files into editable native Gutenberg blocks. Preserves formatting, images, lists, and tables.

== Description ==

DraftSync imports Google Docs and .docx files directly into native WordPress Gutenberg blocks — paragraphs, headings, lists, images, and tables become independently editable blocks. No raw HTML blobs. No Classic Editor workarounds.

**Features:**

- Native Gutenberg blocks from Google Docs and .docx
- WP-CLI support for headless/automated imports
- Bulk import multiple documents in one call
- Linked re-sync from source with conflict detection
- Metadata publishing: slug, excerpt, SEO title/description, categories/tags, featured image, ACF fields
- Image sideloading to WordPress Media Library
- Style overrides: heading demotion, min heading level, default alignment
- SaaS OAuth bridge for zero-config Google authentication
- Enterprise BYO-key mode with direct Google API access
- Inline formatting: bold, italic, underline, strikethrough, links, text color

**External Services**

This plugin can connect to the following external services when you choose to use Google Docs import features:

- **Google APIs** (docs.googleapis.com, www.googleapis.com, oauth2.googleapis.com): Used to fetch Google Doc content and Drive file metadata. Requires a Google account connection via OAuth.
- **DraftSync SaaS Bridge** (hosted service): An optional OAuth broker that handles Google authentication and token refresh. The plugin stores OAuth access tokens, refresh tokens, and your connection mode locally in your WordPress database. No document content passes through the bridge — it only brokers authentication. Terms of Service: https://draftsync.cortisol.icu/terms | Privacy Policy: https://draftsync.cortisol.icu/privacy

You can also use the plugin without any external services by importing local .docx files directly.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/draftsync/`, or install through WordPress Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Navigate to DraftSync in the admin menu.
4. To import Google Docs: connect your Google account via the settings screen.
5. To import .docx files: use the Gutenberg sidebar, WP-CLI, or admin import.

== Frequently Asked Questions ==

= Do I need a Google account to use this plugin? =

No. You can import .docx files locally without any Google account. Google Docs import requires a Google account connection.

= What happens to my data when I delete the plugin? =

Deletion (uninstall) removes all plugin settings, OAuth tokens, connection configuration, and import metadata. Deactivation keeps your settings intact.

= Can I use my own Google API credentials? =

Yes. Switch to Enterprise mode in settings to use your own Google Cloud project credentials.

= Does this plugin support two-way sync? =

No. DraftSync performs one-way import/sync from Google Docs or .docx into WordPress. It does not write WordPress edits back to Google Docs.


= Is the source code human-readable? =

Yes. The plugin ships with unminified JavaScript source in the `src/` directory, and you can build it yourself with Node.js 16+ and pnpm. See `package.json` for build commands.
== Screenshots ==

1. Admin settings screen with connection and import defaults.
2. Gutenberg editor sidebar with .docx upload and style overrides.
3. Imported Docs manager listing linked posts.
4. Successfully imported document as native Gutenberg blocks.

== Changelog ==

= 0.2.0 =
* Scheduled one-way auto-sync from linked Google Docs sources.
* Bulk import with per-row metadata, SEO, and ACF fields.
* Admin settings for import defaults and sync configuration.
* .docx local import with ZIP security validation.
* Linked re-sync with conflict detection and force override.
* WP-CLI commands for import, sync, and status.

== Upgrade Notice ==

= 0.2.0 =
Initial submission. No upgrade path needed.
