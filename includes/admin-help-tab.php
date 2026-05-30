<?php
/**
 * Help tab content for the DraftSync admin settings page.
 *
 * Renders the full user guideline inline as structured HTML.
 *
 * @package DraftSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gdtg-help-toc">
	<h3><?php esc_html_e( 'Contents', 'draftsync' ); ?></h3>
	<ol>
		<li><a href="#help-getting-started"><?php esc_html_e( 'Getting Started', 'draftsync' ); ?></a></li>
		<li><a href="#help-google-doc-import"><?php esc_html_e( 'Google Doc Import', 'draftsync' ); ?></a></li>
		<li><a href="#help-docx-import"><?php esc_html_e( 'Word Document (.docx) Import', 'draftsync' ); ?></a></li>
		<li><a href="#help-drive-docx"><?php esc_html_e( 'Google Drive .docx Import', 'draftsync' ); ?></a></li>
		<li><a href="#help-import-options"><?php esc_html_e( 'Import Options', 'draftsync' ); ?></a></li>
		<li><a href="#help-style-overrides"><?php esc_html_e( 'Style Overrides', 'draftsync' ); ?></a></li>
		<li><a href="#help-metadata"><?php esc_html_e( 'Publishing Metadata', 'draftsync' ); ?></a></li>
		<li><a href="#help-bulk"><?php esc_html_e( 'Bulk Import', 'draftsync' ); ?></a></li>
		<li><a href="#help-resync"><?php esc_html_e( 'Linked Re-Sync', 'draftsync' ); ?></a></li>
		<li><a href="#help-wpcli"><?php esc_html_e( 'WP-CLI Commands', 'draftsync' ); ?></a></li>
		<li><a href="#help-classic"><?php esc_html_e( 'Classic Editor Support', 'draftsync' ); ?></a></li>
		<li><a href="#help-troubleshooting"><?php esc_html_e( 'Troubleshooting', 'draftsync' ); ?></a></li>
	</ol>
</div>

<!-- 1. Getting Started -->
<div id="help-getting-started" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Getting Started', 'draftsync' ); ?></h3>

	<h4><?php esc_html_e( 'Installation', 'draftsync' ); ?></h4>
	<ol>
		<li><?php esc_html_e( 'Upload the draftsync folder to /wp-content/plugins/.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Activate the plugin in Plugins \u2192 Installed Plugins.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Navigate to DraftSync in the WordPress admin sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Connect with Google to authorize access to your Google Docs.', 'draftsync' ); ?></li>
	</ol>
	<p class="description"><?php esc_html_e( 'No API keys or technical setup required \u2014 DraftSync handles authentication through its free SaaS bridge.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Requirements', 'draftsync' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'WordPress 5.0+ with Gutenberg editor (Classic Editor also supported)', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'PHP 7.4+', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'A Google account with access to the documents you want to import', 'draftsync' ); ?></li>
	</ul>
</div>

<!-- 2. Google Doc Import -->
<div id="help-google-doc-import" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Google Doc Import', 'draftsync' ); ?></h3>

	<h4><?php esc_html_e( 'Via the Gutenberg Sidebar', 'draftsync' ); ?></h4>
	<ol>
		<li><?php esc_html_e( 'Open or create a new post/page in the Gutenberg editor.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click the DraftSync cloud icon in the top-right toolbar to open the sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Paste a Google Doc URL into the Document URL field.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Configure import options (see Import Options below).', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Import.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Wait for the import to complete. A success message with a link to the post will appear.', 'draftsync' ); ?></li>
	</ol>

	<h4><?php esc_html_e( 'What Gets Imported', 'draftsync' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Element', 'draftsync' ); ?></th>
				<th><?php esc_html_e( 'Gutenberg Block', 'draftsync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Paragraphs', 'draftsync' ); ?></td><td><code>core/paragraph</code></td></tr>
			<tr><td><?php esc_html_e( 'Headings (H1\u2013H6)', 'draftsync' ); ?></td><td><code>core/heading</code></td></tr>
			<tr><td><?php esc_html_e( 'Images', 'draftsync' ); ?></td><td><code>core/image</code></td></tr>
			<tr><td><?php esc_html_e( 'Ordered / Unordered lists', 'draftsync' ); ?></td><td><code>core/list</code></td></tr>
			<tr><td><?php esc_html_e( 'Tables', 'draftsync' ); ?></td><td><code>core/table</code></td></tr>
			<tr><td><?php esc_html_e( 'Blockquotes', 'draftsync' ); ?></td><td><code>core/quote</code></td></tr>
			<tr><td><?php esc_html_e( 'Horizontal rules', 'draftsync' ); ?></td><td><code>core/separator</code></td></tr>
			<tr><td><?php esc_html_e( 'Page breaks', 'draftsync' ); ?></td><td><code>core/nextpage</code></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Inline Styles Preserved', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'Bold, italic, strikethrough, underline, text color, subscript, superscript, and links (preserves URL and target).', 'draftsync' ); ?></p>
</div>

<!-- 3. Word Document (.docx) Import -->
<div id="help-docx-import" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Word Document (.docx) Import', 'draftsync' ); ?></h3>

	<h4><?php esc_html_e( 'Via the Gutenberg Sidebar', 'draftsync' ); ?></h4>
	<ol>
		<li><?php esc_html_e( 'Open or create a new post/page in the Gutenberg editor.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Open the DraftSync sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Switch to Upload .docx mode using the toggle at the top.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Drag and drop a .docx file onto the upload area, or click to browse.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Configure import options.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Import.', 'draftsync' ); ?></li>
	</ol>

	<h4><?php esc_html_e( 'Security', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'All .docx files pass through DraftSync\u2019s ZIP security validator: magic byte verification, path traversal prevention, nested ZIP detection, entry count limits, and compression ratio checks.', 'draftsync' ); ?></p>
</div>

<!-- 4. Google Drive .docx Import -->
<div id="help-drive-docx" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Google Drive .docx Import', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'Import .docx files stored in Google Drive without downloading them first:', 'draftsync' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Open the DraftSync sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Paste a Google Drive file URL (e.g. https://drive.google.com/file/d/FILE_ID/view).', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Configure import options.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Import.', 'draftsync' ); ?></li>
	</ol>
</div>

<!-- 5. Import Options -->
<div id="help-import-options" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Import Options', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'Toggle these options in the sidebar before importing:', 'draftsync' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Option', 'draftsync' ); ?></th>
				<th><?php esc_html_e( 'Default', 'draftsync' ); ?></th>
				<th><?php esc_html_e( 'Description', 'draftsync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Import images', 'draftsync' ); ?></strong></td><td><?php esc_html_e( 'On', 'draftsync' ); ?></td><td><?php esc_html_e( 'Download and sideload images to the Media Library.', 'draftsync' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Optimize images', 'draftsync' ); ?></strong></td><td><?php esc_html_e( 'On', 'draftsync' ); ?></td><td><?php esc_html_e( 'Compress and resize images during import.', 'draftsync' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Overwrite existing', 'draftsync' ); ?></strong></td><td><?php esc_html_e( 'Off', 'draftsync' ); ?></td><td><?php esc_html_e( 'Replace the current post content. When off, imported content is appended.', 'draftsync' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Import as draft', 'draftsync' ); ?></strong></td><td><?php esc_html_e( 'On', 'draftsync' ); ?></td><td><?php esc_html_e( 'Save the post as a draft. When off, the post is published immediately.', 'draftsync' ); ?></td></tr>
		</tbody>
	</table>
</div>

<!-- 6. Style Overrides -->
<div id="help-style-overrides" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Style Overrides', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'Control how imported content is formatted. Set these in the sidebar\u2019s Style Overrides panel before importing.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Heading Demotion', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'Shift all heading levels down by N positions. Useful when your document uses H1 for titles but your theme reserves H1 for the post title.', 'draftsync' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Value', 'draftsync' ); ?></th><th><?php esc_html_e( 'Effect', 'draftsync' ); ?></th></tr></thead>
		<tbody>
			<tr><td>0</td><td><?php esc_html_e( 'No change (default)', 'draftsync' ); ?></td></tr>
			<tr><td>1</td><td><?php esc_html_e( 'H1\u2192H2, H2\u2192H3, H3\u2192H4, etc.', 'draftsync' ); ?></td></tr>
			<tr><td>2</td><td><?php esc_html_e( 'H1\u2192H3, H2\u2192H4, H3\u2192H5, etc.', 'draftsync' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Minimum Heading Level', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'Prevent headings above a certain level from appearing.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Default Text Alignment', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'Force all paragraph text to a specific alignment: left, center, or right. Leave empty to use the document\u2019s original alignment.', 'draftsync' ); ?></p>
</div>

<!-- 7. Publishing Metadata -->
<div id="help-metadata" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Publishing Metadata', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'DraftSync can set post metadata during import via a metadata table at the beginning of your Google Doc, or via the sidebar\u2019s Publishing Metadata panel.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Supported Metadata Fields', 'draftsync' ); ?></h4>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Field', 'draftsync' ); ?></th><th><?php esc_html_e( 'Description', 'draftsync' ); ?></th></tr></thead>
		<tbody>
			<tr><td><code>slug</code></td><td><?php esc_html_e( 'URL slug for the post', 'draftsync' ); ?></td></tr>
			<tr><td><code>excerpt</code></td><td><?php esc_html_e( 'Post excerpt', 'draftsync' ); ?></td></tr>
			<tr><td><code>seo_title</code></td><td><?php esc_html_e( 'SEO title (Yoast / RankMath)', 'draftsync' ); ?></td></tr>
			<tr><td><code>seo_description</code></td><td><?php esc_html_e( 'SEO meta description', 'draftsync' ); ?></td></tr>
			<tr><td><code>seo_focus_keyword</code></td><td><?php esc_html_e( 'SEO focus keyword', 'draftsync' ); ?></td></tr>
			<tr><td><code>canonical</code></td><td><?php esc_html_e( 'Canonical URL', 'draftsync' ); ?></td></tr>
			<tr><td><code>categories</code></td><td><?php esc_html_e( 'Comma-separated category names or IDs', 'draftsync' ); ?></td></tr>
			<tr><td><code>tags</code></td><td><?php esc_html_e( 'Comma-separated tag names', 'draftsync' ); ?></td></tr>
			<tr><td><code>featured_image</code></td><td><?php esc_html_e( 'URL of the featured image (downloaded and attached)', 'draftsync' ); ?></td></tr>
			<tr><td><code>acf_*</code></td><td><?php esc_html_e( 'ACF field values (e.g. acf_subtitle)', 'draftsync' ); ?></td></tr>
		</tbody>
	</table>
</div>

<!-- 8. Bulk Import -->
<div id="help-bulk" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Bulk Import', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'Import multiple documents in a single operation via the sidebar, WP-CLI, or the REST API.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Via the Sidebar', 'draftsync' ); ?></h4>
	<ol>
		<li><?php esc_html_e( 'Open the DraftSync sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Switch to Bulk Import mode.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Add rows with document URLs and optional per-row settings.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Import All.', 'draftsync' ); ?></li>
	</ol>

	<h4><?php esc_html_e( 'Via WP-CLI', 'draftsync' ); ?></h4>
	<pre><code>wp draftsync import-bulk --rows='[{"url":"https://docs.google.com/document/d/ABC123/edit"}]' --user=1</code></pre>

	<h4><?php esc_html_e( 'Via the REST API', 'draftsync' ); ?></h4>
	<pre><code>POST /wp-json/gdtg/v1/import-bulk
{
  "rows": [
    {"url": "https://docs.google.com/document/d/ABC123/edit"},
    {"url": "https://docs.google.com/document/d/DEF456/edit"}
  ],
  "dry_run": false
}</code></pre>
	<p class="description"><?php esc_html_e( 'Maximum 100 rows per bulk request. Add "dry_run": true to validate without importing.', 'draftsync' ); ?></p>
</div>

<!-- 9. Linked Re-Sync -->
<div id="help-resync" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Linked Re-Sync', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'After importing a document, DraftSync maintains a link between the WordPress post and the source document. You can re-sync to pull in updates.', 'draftsync' ); ?></p>

	<h4><?php esc_html_e( 'Via the Sidebar', 'draftsync' ); ?></h4>
	<ol>
		<li><?php esc_html_e( 'Open a post that was previously imported with DraftSync.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'The sidebar shows the linked source document URL.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Click Re-Sync to pull the latest content.', 'draftsync' ); ?></li>
	</ol>

	<h4><?php esc_html_e( 'Via WP-CLI', 'draftsync' ); ?></h4>
	<pre><code>wp draftsync sync 123 --user=1
wp draftsync sync 123 --user=1 --force
wp draftsync sync-all --user=1</code></pre>

	<h4><?php esc_html_e( 'Conflict Protection', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'DraftSync tracks a content hash of the last import. If the post has been manually edited, re-sync will refuse to overwrite by default. Use --force to override.', 'draftsync' ); ?></p>
</div>

<!-- 10. WP-CLI Commands -->
<div id="help-wpcli" class="gdtg-help-section">
	<h3><?php esc_html_e( 'WP-CLI Commands', 'draftsync' ); ?></h3>

	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Command', 'draftsync' ); ?></th><th><?php esc_html_e( 'Description', 'draftsync' ); ?></th></tr></thead>
		<tbody>
			<tr><td><code>wp draftsync import URL</code></td><td><?php esc_html_e( 'Import a Google Doc', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync import-docx FILE</code></td><td><?php esc_html_e( 'Import a local .docx file', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync import-bulk</code></td><td><?php esc_html_e( 'Bulk import multiple documents', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync sync POST_ID</code></td><td><?php esc_html_e( 'Re-sync a specific post', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync sync-all</code></td><td><?php esc_html_e( 'Re-sync all linked posts', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync status JOB_ID</code></td><td><?php esc_html_e( 'Check a specific job status', 'draftsync' ); ?></td></tr>
			<tr><td><code>wp draftsync status --all</code></td><td><?php esc_html_e( 'List all recent jobs', 'draftsync' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Common Flags', 'draftsync' ); ?></h4>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e( 'Flag', 'draftsync' ); ?></th><th><?php esc_html_e( 'Description', 'draftsync' ); ?></th></tr></thead>
		<tbody>
			<tr><td><code>--post_id=N</code></td><td><?php esc_html_e( 'Import into an existing post', 'draftsync' ); ?></td></tr>
			<tr><td><code>--post_type=post|page</code></td><td><?php esc_html_e( 'Create as post or page (default: post)', 'draftsync' ); ?></td></tr>
			<tr><td><code>--draft</code></td><td><?php esc_html_e( 'Save as draft (default: on)', 'draftsync' ); ?></td></tr>
			<tr><td><code>--output=blocks|html</code></td><td><?php esc_html_e( 'Output format (default: blocks)', 'draftsync' ); ?></td></tr>
			<tr><td><code>--heading-demotion=N</code></td><td><?php esc_html_e( 'Shift headings down N levels', 'draftsync' ); ?></td></tr>
			<tr><td><code>--min-heading-level=N</code></td><td><?php esc_html_e( 'Minimum heading level to allow', 'draftsync' ); ?></td></tr>
			<tr><td><code>--force</code></td><td><?php esc_html_e( 'Force re-sync even if unchanged or conflicts', 'draftsync' ); ?></td></tr>
			<tr><td><code>--dry_run</code></td><td><?php esc_html_e( 'Validate without importing (bulk)', 'draftsync' ); ?></td></tr>
		</tbody>
	</table>
</div>

<!-- 11. Classic Editor Support -->
<div id="help-classic" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Classic Editor Support', 'draftsync' ); ?></h3>
	<p><?php esc_html_e( 'If your site uses the Classic Editor instead of Gutenberg, DraftSync automatically detects this and outputs clean HTML instead of Gutenberg block markup. No configuration needed.', 'draftsync' ); ?></p>
	<p><?php esc_html_e( 'To explicitly request HTML output (e.g., for use with page builders):', 'draftsync' ); ?></p>
	<pre><code>wp draftsync import URL --output=html --user=1</code></pre>
</div>

<!-- 12. Troubleshooting -->
<div id="help-troubleshooting" class="gdtg-help-section">
	<h3><?php esc_html_e( 'Troubleshooting', 'draftsync' ); ?></h3>

	<h4><?php esc_html_e( 'Import Fails with Timeout', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'Large documents with many images may timeout on shared hosting. Solutions:', 'draftsync' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Disable image import \u2014 Toggle off "Import images" in the sidebar.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Use WP-CLI \u2014 CLI imports have longer timeout limits.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Batch import \u2014 Split into smaller documents or use bulk import with fewer rows per batch.', 'draftsync' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Images Not Appearing', 'draftsync' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'Ensure "Import images" is toggled on.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Check that the Google Doc is shared (at least "Anyone with the link").', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Verify your server allows outbound HTTP requests to googleusercontent.com.', 'draftsync' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Formatting Looks Wrong', 'draftsync' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'Use style overrides to adjust heading levels and alignment.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Check the original document \u2014 DraftSync faithfully reproduces the document structure.', 'draftsync' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Permission Errors', 'draftsync' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'Ensure your WordPress user has the edit_posts capability.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'For CLI commands, pass --user=1 (or the appropriate user ID).', 'draftsync' ); ?></li>
	</ul>

	<h4><?php esc_html_e( '"Failed to Fetch Document" Error', 'draftsync' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'Verify the Google Doc URL is correct and accessible.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Ensure DraftSync has been connected to your Google account.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Try disconnecting and reconnecting your Google account.', 'draftsync' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Re-Sync Reports Conflict', 'draftsync' ); ?></h4>
	<p><?php esc_html_e( 'This means the post was manually edited since the last import. Options:', 'draftsync' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Review changes \u2014 Compare the current post content with the source document.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Force re-sync \u2014 Use --force flag or the force toggle to overwrite.', 'draftsync' ); ?></li>
		<li><?php esc_html_e( 'Keep manual edits \u2014 Do nothing; the post remains as-is.', 'draftsync' ); ?></li>
	</ul>
</div>
