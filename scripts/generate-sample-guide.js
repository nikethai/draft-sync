const fs = require( 'fs' );
const {
	Document,
	Packer,
	Paragraph,
	TextRun,
	Table,
	TableRow,
	TableCell,
	Header,
	Footer,
	AlignmentType,
	LevelFormat,
	ExternalHyperlink,
	HeadingLevel,
	BorderStyle,
	WidthType,
	ShadingType,
	PageNumber,
	PageBreak,
} = require( 'docx' );

// ── Helpers ──────────────────────────────────────────────────

const border = { style: BorderStyle.SINGLE, size: 1, color: 'CCCCCC' };
const borders = { top: border, bottom: border, left: border, right: border };
const cellMargins = { top: 80, bottom: 80, left: 120, right: 120 };

function headerCell( text, width ) {
	return new TableCell( {
		borders,
		width: { size: width, type: WidthType.DXA },
		shading: { fill: '2B579A', type: ShadingType.CLEAR },
		margins: cellMargins,
		verticalAlign: 'center',
		children: [
			new Paragraph( {
				children: [
					new TextRun( {
						text,
						bold: true,
						color: 'FFFFFF',
						font: 'Arial',
						size: 20,
					} ),
				],
			} ),
		],
	} );
}

function cell( text, width ) {
	return new TableCell( {
		borders,
		width: { size: width, type: WidthType.DXA },
		margins: cellMargins,
		children: [
			new Paragraph( {
				children: [ new TextRun( { text, font: 'Arial', size: 20 } ) ],
			} ),
		],
	} );
}

function cellWithRuns( runs, width ) {
	return new TableCell( {
		borders,
		width: { size: width, type: WidthType.DXA },
		margins: cellMargins,
		children: [ new Paragraph( { children: runs } ) ],
	} );
}

function p( text ) {
	return new Paragraph( {
		spacing: { after: 120 },
		children: [ new TextRun( { text, font: 'Arial', size: 22 } ) ],
	} );
}

function heading( text, level ) {
	return new Paragraph( {
		heading: level,
		spacing: { before: 240, after: 120 },
		children: [ new TextRun( { text, font: 'Arial' } ) ],
	} );
}

// ── Metadata Table (first-node metadata for DraftSync) ──────

const metaTable = new Table( {
	width: { size: 9360, type: WidthType.DXA },
	columnWidths: [ 2800, 6560 ],
	rows: [
		new TableRow( {
			children: [
				headerCell( 'Field', 2800 ),
				headerCell( 'Value', 6560 ),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'slug', 2800 ),
				cell( 'draftsync-sample-guide', 6560 ),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'excerpt', 2800 ),
				cell(
					'A sample document demonstrating all DraftSync import features. Use this to test Google Docs, .docx upload, and Drive import.',
					6560
				),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'seo_title', 2800 ),
				cell( 'DraftSync Sample Guide — Test All Features', 6560 ),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'seo_description', 2800 ),
				cell(
					'Sample document for testing DraftSync imports: headings, lists, tables, images, inline styles, and metadata.',
					6560
				),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'categories', 2800 ),
				cell( 'Documentation, Tutorials', 6560 ),
			],
		} ),
		new TableRow( {
			children: [
				cell( 'tags', 2800 ),
				cell( 'draftsync, gutenberg, import, test', 6560 ),
			],
		} ),
	],
} );

// ── Numbering configs ────────────────────────────────────────

const numbering = {
	config: [
		{
			reference: 'bullets',
			levels: [
				{
					level: 0,
					format: LevelFormat.BULLET,
					text: '\u2022',
					alignment: AlignmentType.LEFT,
					style: {
						paragraph: { indent: { left: 720, hanging: 360 } },
					},
				},
				{
					level: 1,
					format: LevelFormat.BULLET,
					text: '\u25E6',
					alignment: AlignmentType.LEFT,
					style: {
						paragraph: { indent: { left: 1440, hanging: 360 } },
					},
				},
			],
		},
		{
			reference: 'numbers',
			levels: [
				{
					level: 0,
					format: LevelFormat.DECIMAL,
					text: '%1.',
					alignment: AlignmentType.LEFT,
					style: {
						paragraph: { indent: { left: 720, hanging: 360 } },
					},
				},
				{
					level: 1,
					format: LevelFormat.DECIMAL,
					text: '%2)',
					alignment: AlignmentType.LEFT,
					style: {
						paragraph: { indent: { left: 1440, hanging: 360 } },
					},
				},
			],
		},
		{
			reference: 'steps',
			levels: [
				{
					level: 0,
					format: LevelFormat.DECIMAL,
					text: 'Step %1:',
					alignment: AlignmentType.LEFT,
					style: {
						paragraph: { indent: { left: 720, hanging: 720 } },
					},
				},
			],
		},
	],
};

// ── Content blocks ──────────────────────────────────────────

const content = [
	// Title
	new Paragraph( {
		alignment: AlignmentType.CENTER,
		spacing: { after: 400 },
		children: [
			new TextRun( {
				text: 'DraftSync Sample Guide',
				font: 'Arial',
				size: 48,
				bold: true,
				color: '2B579A',
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.CENTER,
		spacing: { after: 200 },
		children: [
			new TextRun( {
				text: 'Test every import feature with this document',
				font: 'Arial',
				size: 24,
				italics: true,
				color: '666666',
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.CENTER,
		spacing: { after: 600 },
		children: [
			new TextRun( {
				text: 'Version 1.0 \u2022 June 2026',
				font: 'Arial',
				size: 20,
				color: '999999',
			} ),
		],
	} ),

	// ── Metadata Table Section ──
	heading( 'Document Metadata', HeadingLevel.HEADING_1 ),
	p(
		'The table below contains publishing metadata. DraftSync reads this table and applies the values to the WordPress post automatically.'
	),
	metaTable,

	new Paragraph( { children: [ new PageBreak() ] } ),

	// ── 1. Headings ──
	heading( 'Heading Levels', HeadingLevel.HEADING_1 ),
	p(
		'DraftSync imports all six heading levels. Use the heading demotion override to shift levels down.'
	),
	heading( 'This is Heading 2', HeadingLevel.HEADING_2 ),
	p( 'Content under H2.' ),
	heading( 'This is Heading 3', HeadingLevel.HEADING_3 ),
	p( 'Content under H3.' ),
	heading( 'This is Heading 4', HeadingLevel.HEADING_4 ),
	p(
		'Content under H4. Use style overrides to control minimum heading level and demotion.'
	),

	// ── 2. Paragraphs & Inline Styles ──
	heading( 'Inline Text Styles', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		spacing: { after: 120 },
		children: [
			new TextRun( {
				text: 'Bold text',
				bold: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' is rendered as ',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: 'inline bold',
				bold: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: '. Similarly, ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'italic text',
				italics: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ' and ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'underlined text',
				underline: {},
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' are preserved. You can ',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: 'combine styles',
				bold: true,
				italics: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ' too.', font: 'Arial', size: 22 } ),
		],
	} ),
	new Paragraph( {
		spacing: { after: 120 },
		children: [
			new TextRun( {
				text: 'Strikethrough text',
				strike: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' is supported. Subscript: H',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: '2',
				subScript: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: 'O and superscript: E=mc',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: '2',
				superScript: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' are handled correctly.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		spacing: { after: 120 },
		children: [
			new TextRun( { text: 'Colored text: ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'red',
				color: 'FF0000',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ', ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'blue',
				color: '2B579A',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ', and ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'green',
				color: '2E7D32',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' are imported with their original colors.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	// ── 3. Links ──
	heading( 'Hyperlinks', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		spacing: { after: 120 },
		children: [
			new TextRun( {
				text: 'Links are preserved during import. Visit ',
				font: 'Arial',
				size: 22,
			} ),
			new ExternalHyperlink( {
				children: [
					new TextRun( {
						text: 'WordPress.org',
						style: 'Hyperlink',
						font: 'Arial',
						size: 22,
					} ),
				],
				link: 'https://wordpress.org',
			} ),
			new TextRun( { text: ' or check the ', font: 'Arial', size: 22 } ),
			new ExternalHyperlink( {
				children: [
					new TextRun( {
						text: 'Gutenberg handbook',
						style: 'Hyperlink',
						font: 'Arial',
						size: 22,
					} ),
				],
				link: 'https://developer.wordpress.org/block-editor/',
			} ),
			new TextRun( {
				text: ' for more information.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	// ── 4. Bullet Lists ──
	heading( 'Bullet Lists', HeadingLevel.HEADING_1 ),
	p( 'Flat bullet lists:' ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 0 },
		children: [
			new TextRun( {
				text: 'First item in a flat list',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 0 },
		children: [
			new TextRun( { text: 'Second item', font: 'Arial', size: 22 } ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 0 },
		children: [
			new TextRun( {
				text: 'Third item with ',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: 'bold emphasis',
				bold: true,
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	p( '' ),
	p( 'Nested bullet lists:' ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 0 },
		children: [
			new TextRun( { text: 'Top-level item', font: 'Arial', size: 22 } ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 1 },
		children: [
			new TextRun( { text: 'Nested item A', font: 'Arial', size: 22 } ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 1 },
		children: [
			new TextRun( { text: 'Nested item B', font: 'Arial', size: 22 } ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'bullets', level: 0 },
		children: [
			new TextRun( {
				text: 'Another top-level item',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	// ── 5. Numbered Lists ──
	heading( 'Numbered Lists', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 0 },
		children: [
			new TextRun( {
				text: 'Install the DraftSync plugin',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 0 },
		children: [
			new TextRun( {
				text: 'Connect your Google account',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 0 },
		children: [
			new TextRun( {
				text: 'Open the Gutenberg sidebar',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 1 },
		children: [
			new TextRun( {
				text: 'Click the DraftSync cloud icon',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 1 },
		children: [
			new TextRun( {
				text: 'Or use the keyboard shortcut',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'numbers', level: 0 },
		children: [
			new TextRun( {
				text: 'Paste your document URL and click Import',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	new Paragraph( { children: [ new PageBreak() ] } ),

	// ── 6. Tables ──
	heading( 'Tables', HeadingLevel.HEADING_1 ),
	p(
		'DraftSync imports tables as native Gutenberg core/table blocks. Cell formatting, alignment, and content are preserved.'
	),
	heading( 'Import Options Reference', HeadingLevel.HEADING_2 ),
	new Table( {
		width: { size: 9360, type: WidthType.DXA },
		columnWidths: [ 2200, 1200, 5960 ],
		rows: [
			new TableRow( {
				children: [
					headerCell( 'Option', 2200 ),
					headerCell( 'Default', 1200 ),
					headerCell( 'Description', 5960 ),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Import images', 2200 ),
					cell( 'On', 1200 ),
					cell(
						'Download and sideload images to Media Library',
						5960
					),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Optimize images', 2200 ),
					cell( 'On', 1200 ),
					cell( 'Compress and resize images during import', 5960 ),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Overwrite existing', 2200 ),
					cell( 'Off', 1200 ),
					cell(
						'Replace current post content instead of appending',
						5960
					),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Import as draft', 2200 ),
					cell( 'On', 1200 ),
					cell(
						'Save as draft; turn off to publish immediately',
						5960
					),
				],
			} ),
		],
	} ),

	p( '' ),
	heading( 'Style Override Reference', HeadingLevel.HEADING_2 ),
	new Table( {
		width: { size: 9360, type: WidthType.DXA },
		columnWidths: [ 2800, 1600, 4960 ],
		rows: [
			new TableRow( {
				children: [
					headerCell( 'Override', 2800 ),
					headerCell( 'Range', 1600 ),
					headerCell( 'Effect', 4960 ),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Heading demotion', 2800 ),
					cell( '0\u20135', 1600 ),
					cell( 'Shift all heading levels down by N', 4960 ),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Min heading level', 2800 ),
					cell( '1\u20136', 1600 ),
					cell( 'Prevent headings above this level', 4960 ),
				],
			} ),
			new TableRow( {
				children: [
					cell( 'Default alignment', 2800 ),
					cell( 'left/center/right', 1600 ),
					cell( 'Force all paragraph text alignment', 4960 ),
				],
			} ),
		],
	} ),

	// ── 7. Blockquote ──
	heading( 'Blockquotes', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		indent: { left: 720 },
		spacing: { after: 120 },
		children: [
			new TextRun( {
				text: 'DraftSync reads the Google Docs REST API JSON directly and emits native Gutenberg blocks \u2014 paragraphs, headings, lists, images, tables \u2014 that WordPress can edit natively.',
				italics: true,
				font: 'Arial',
				size: 22,
				color: '555555',
			} ),
		],
	} ),

	// ── 8. Horizontal Rule ──
	heading( 'Horizontal Rules', HeadingLevel.HEADING_1 ),
	p( 'A horizontal rule (separator) appears below this paragraph.' ),
	new Paragraph( {
		spacing: { before: 200, after: 200 },
		border: {
			bottom: {
				style: BorderStyle.SINGLE,
				size: 6,
				color: 'CCCCCC',
				space: 1,
			},
		},
		children: [],
	} ),
	p( 'The separator above is imported as a core/separator block.' ),

	// ── 9. Text Alignment ──
	heading( 'Text Alignment', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		alignment: AlignmentType.LEFT,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'This paragraph is left-aligned (default).',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.CENTER,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'This paragraph is center-aligned.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.RIGHT,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'This paragraph is right-aligned.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	p(
		'Use the default alignment style override to force all paragraphs to one alignment.'
	),

	// ── 10. Mixed Content ──
	heading( 'Mixed Formatting in One Paragraph', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		spacing: { after: 120 },
		children: [
			new TextRun( {
				text: 'This paragraph demonstrates ',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: 'bold',
				bold: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ', ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'italic',
				italics: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ', ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'underlined',
				underline: {},
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ', ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'colored',
				color: 'E65100',
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( { text: ' text, a ', font: 'Arial', size: 22 } ),
			new ExternalHyperlink( {
				children: [
					new TextRun( {
						text: 'hyperlink',
						style: 'Hyperlink',
						font: 'Arial',
						size: 22,
					} ),
				],
				link: 'https://example.com',
			} ),
			new TextRun( { text: ', and ', font: 'Arial', size: 22 } ),
			new TextRun( {
				text: 'superscript',
				superScript: true,
				font: 'Arial',
				size: 22,
			} ),
			new TextRun( {
				text: ' all in one paragraph. DraftSync preserves every inline style.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	new Paragraph( { children: [ new PageBreak() ] } ),

	// ── 11. WP-CLI Quick Reference ──
	heading( 'WP-CLI Quick Reference', HeadingLevel.HEADING_1 ),
	p( 'All import features are also available via WP-CLI:' ),
	new Table( {
		width: { size: 9360, type: WidthType.DXA },
		columnWidths: [ 4200, 5160 ],
		rows: [
			new TableRow( {
				children: [
					headerCell( 'Command', 4200 ),
					headerCell( 'Purpose', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync import <url>',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Import a Google Doc or Drive .docx', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync import-docx <path>',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Import a local .docx file', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync import-bulk --rows',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Bulk import multiple documents', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync sync <post_id>',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Re-sync a linked post from source', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync sync-all',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Re-sync all linked posts', 5160 ),
				],
			} ),
			new TableRow( {
				children: [
					cellWithRuns(
						[
							new TextRun( {
								text: 'wp draftsync status [job_id]',
								font: 'Consolas',
								size: 18,
							} ),
						],
						4200
					),
					cell( 'Check import job status', 5160 ),
				],
			} ),
		],
	} ),

	// ── 12. Alignment override demo ──
	heading( 'Default Alignment Override', HeadingLevel.HEADING_1 ),
	p(
		'These paragraphs demonstrate different alignments. Use the default_alignment style override to force all text to left, center, or right.'
	),
	new Paragraph( {
		alignment: AlignmentType.LEFT,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'Left-aligned paragraph (default).',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.CENTER,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'Center-aligned paragraph.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		alignment: AlignmentType.RIGHT,
		spacing: { after: 80 },
		children: [
			new TextRun( {
				text: 'Right-aligned paragraph.',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),

	// ── Closing ──
	heading( 'Using This Document', HeadingLevel.HEADING_1 ),
	new Paragraph( {
		numbering: { reference: 'steps', level: 0 },
		children: [
			new TextRun( {
				text: 'Upload this .docx via the DraftSync sidebar',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'steps', level: 0 },
		children: [
			new TextRun( {
				text: 'Or paste the Google Docs URL after uploading to Google Docs',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'steps', level: 0 },
		children: [
			new TextRun( {
				text: 'Try different style overrides to see heading demotion and alignment in action',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'steps', level: 0 },
		children: [
			new TextRun( {
				text: 'Check that the metadata table was applied to the post (slug, excerpt, SEO, categories, tags)',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
	new Paragraph( {
		numbering: { reference: 'steps', level: 0 },
		children: [
			new TextRun( {
				text: 'Verify all block types rendered as native Gutenberg blocks',
				font: 'Arial',
				size: 22,
			} ),
		],
	} ),
];

// ── Document ─────────────────────────────────────────────────

const doc = new Document( {
	styles: {
		default: { document: { run: { font: 'Arial', size: 22 } } },
		paragraphStyles: [
			{
				id: 'Heading1',
				name: 'Heading 1',
				basedOn: 'Normal',
				next: 'Normal',
				quickFormat: true,
				run: { size: 32, bold: true, font: 'Arial', color: '2B579A' },
				paragraph: {
					spacing: { before: 360, after: 200 },
					outlineLevel: 0,
				},
			},
			{
				id: 'Heading2',
				name: 'Heading 2',
				basedOn: 'Normal',
				next: 'Normal',
				quickFormat: true,
				run: { size: 26, bold: true, font: 'Arial', color: '333333' },
				paragraph: {
					spacing: { before: 240, after: 120 },
					outlineLevel: 1,
				},
			},
		],
	},
	numbering,
	sections: [
		{
			properties: {
				page: {
					size: { width: 12240, height: 15840 },
					margin: {
						top: 1440,
						right: 1440,
						bottom: 1440,
						left: 1440,
					},
				},
			},
			headers: {
				default: new Header( {
					children: [
						new Paragraph( {
							alignment: AlignmentType.RIGHT,
							children: [
								new TextRun( {
									text: 'DraftSync Sample Guide',
									font: 'Arial',
									size: 18,
									color: '999999',
									italics: true,
								} ),
							],
						} ),
					],
				} ),
			},
			footers: {
				default: new Footer( {
					children: [
						new Paragraph( {
							alignment: AlignmentType.CENTER,
							children: [
								new TextRun( {
									text: 'Page ',
									font: 'Arial',
									size: 18,
									color: '999999',
								} ),
								new TextRun( {
									children: [ PageNumber.CURRENT ],
									font: 'Arial',
									size: 18,
									color: '999999',
								} ),
							],
						} ),
					],
				} ),
			},
			children: content,
		},
	],
} );

// ── Write ────────────────────────────────────────────────────

Packer.toBuffer( doc ).then( ( buffer ) => {
	const outPath = 'tests/fixtures/docx/draftsync-sample-guide.docx';
	fs.mkdirSync( 'tests/fixtures/docx', { recursive: true } );
	fs.writeFileSync( outPath, buffer );
	// eslint-disable-next-line no-console
	console.log(
		`Written: ${ outPath } (${ ( buffer.length / 1024 ).toFixed( 1 ) } KB)`
	);
} );
