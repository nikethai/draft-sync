<?php
/**
 * Generate minimal .docx fixtures for parser tests.
 *
 * Run: php tests/fixtures/docx/generate-fixtures.php
 *
 * Each fixture is a valid OOXML .docx (ZIP) with the minimum required entries.
 * No external dependencies — uses only PHP's built-in ZipArchive.
 */

$fixtures_dir = __DIR__;

// ─── Minimal OOXML building blocks ──────────────────────────────

function content_types_xml() {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="jpg" ContentType="image/jpeg"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';
}

function root_rels_xml() {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
}

function document_rels_xml( $image_entries = array() ) {
	$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
	if ( ! empty( $image_entries ) ) {
		foreach ( $image_entries as $rid => $target ) {
			$rels .= "\n  <Relationship Id=\"{$rid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"{$target}\"/>";
		}
	}
	$rels .= "\n</Relationships>";
	return $rels;
}

/**
 * Build a .docx from an array of body XML elements.
 *
 * @param string $path          Output file path.
 * @param string $body_xml      Inner XML for w:body.
 * @param array  $image_entries Relationship entries for images (rid => target).
 * @param array  $media_files   Media files to add (archive path => raw bytes).
 */
function build_docx( $path, $body_xml, $image_entries = array(), $media_files = array() ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Failed to create {$path}\n" );
		exit( 1 );
	}

	$zip->addFromString( '[Content_Types].xml', content_types_xml() );
	$zip->addFromString( '_rels/.rels', root_rels_xml() );
	$zip->addFromString( 'word/_rels/document.xml.rels', document_rels_xml( $image_entries ) );

	$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
            xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
  <w:body>
' . $body_xml . '
  </w:body>
</w:document>';

	$zip->addFromString( 'word/document.xml', $document_xml );

	foreach ( $media_files as $archive_path => $bytes ) {
		$zip->addFromString( $archive_path, $bytes );
	}

	$zip->close();
	echo "  ✓ Created {$path}\n";
}

// ─── Fixture: basic ──────────────────────────────────────────────
// Paragraphs, heading, bold/italic, link.

echo "Generating .docx fixtures...\n";

$basic_body = '
    <w:p>
      <w:r><w:t>Hello World</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
      <w:r><w:t>Main Title</w:t></w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:b/></w:rPr>
        <w:t xml:space="preserve">Bold text</w:t>
      </w:r>
      <w:r><w:t xml:space="preserve"> and </w:t></w:r>
      <w:r>
        <w:rPr><w:i/></w:rPr>
        <w:t>italic text</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r><w:t xml:space="preserve">A </w:t></w:r>
      <w:hyperlink r:id="rIdLink" history="1">
        <w:r>
          <w:rPr><w:rStyle w:val="Hyperlink"/></w:rPr>
          <w:t>clickable link</w:t>
        </w:r>
      </w:hyperlink>
      <w:r><w:t xml:space="preserve"> here.</w:t></w:r>
    </w:p>';

$basic_rels = array( 'rIdLink' => 'https://example.com/' );

build_docx( "{$fixtures_dir}/basic.docx", $basic_body, $basic_rels );

// ─── Fixture: headings ───────────────────────────────────────────

$headings_body = '
    <w:p>
      <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
      <w:r><w:t>Heading 1</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
      <w:r><w:t>Heading 2</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading3"/></w:pPr>
      <w:r><w:t>Heading 3</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>Normal paragraph after headings.</w:t></w:r>
    </w:p>';

build_docx( "{$fixtures_dir}/headings.docx", $headings_body );

// ─── Fixture: lists ──────────────────────────────────────────────

$lists_body = '
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>
      <w:r><w:t>Item A</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>
      <w:r><w:t>Item B</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr>
      <w:r><w:t>Numbered 1</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr>
      <w:r><w:t>Numbered 2</w:t></w:r>
    </w:p>';

build_docx( "{$fixtures_dir}/lists.docx", $lists_body );

// ─── Fixture: tables ─────────────────────────────────────────────

$tables_body = '
    <w:tbl>
      <w:tblPr/>
      <w:tr>
        <w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc>
      </w:tr>
      <w:tr>
        <w:tc><w:p><w:r><w:t>A2</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold B2</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>';

build_docx( "{$fixtures_dir}/tables.docx", $tables_body );

// ─── Fixture: mixed (paragraphs + headings + list + table) ───────

$mixed_body = '
    <w:p>
      <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
      <w:r><w:t>Mixed Document</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>Introduction paragraph.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
      <w:r><w:t>List Section</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>
      <w:r><w:t>First item</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>
      <w:r><w:t>Second item</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
      <w:r><w:t>Table Section</w:t></w:r>
    </w:p>
    <w:tbl>
      <w:tblPr/>
      <w:tr>
        <w:tc><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Value</w:t></w:r></w:p></w:tc>
      </w:tr>
      <w:tr>
        <w:tc><w:p><w:r><w:t>Alpha</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>1</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>';

build_docx( "{$fixtures_dir}/mixed.docx", $mixed_body );

// ─── Fixture: inline-styles (bold, italic, underline, strikethrough, color) ──

$styles_body = '
    <w:p>
      <w:r>
        <w:rPr><w:b/></w:rPr>
        <w:t>Bold</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:i/></w:rPr>
        <w:t>Italic</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:u w:val="single"/></w:rPr>
        <w:t>Underlined</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:strike/></w:rPr>
        <w:t>Strikethrough</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:color w:val="FF0000"/></w:rPr>
        <w:t>Red text</w:t>
      </w:r>
    </w:p>';

build_docx( "{$fixtures_dir}/inline-styles.docx", $styles_body );

// ─── Fixture: page-break ─────────────────────────────────────────

$pagebreak_body = '
    <w:p>
      <w:r><w:t>Page one</w:t></w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:br w:type="page"/>
      </w:r>
    </w:p>
    <w:p>
      <w:r><w:t>Page two</w:t></w:r>
    </w:p>';

build_docx( "{$fixtures_dir}/page-break.docx", $pagebreak_body );

echo "\nAll fixtures generated.\n";
