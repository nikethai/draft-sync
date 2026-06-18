/* global sessionStorage, alert, FileReader */
/**
 * Admin Settings Dashboard — tab navigation, mode selector, and Imported Docs manager.
 */
( function () {
	'use strict';

	// ── Tab switching ──
	function showTab( tabId ) {
		document
			.querySelectorAll( '.gdtg-tab-panel' )
			.forEach( function ( panel ) {
				panel.classList.remove( 'active' );
			} );
		document.querySelectorAll( '.nav-tab' ).forEach( function ( el ) {
			el.classList.remove( 'nav-tab-active' );
		} );

		const panel = document.getElementById( 'gdtg-tab-' + tabId );
		const tabLink = document.querySelector(
			'.nav-tab[data-tab="' + tabId + '"]'
		);
		if ( panel ) {
			panel.classList.add( 'active' );
		}
		if ( tabLink ) {
			tabLink.classList.add( 'nav-tab-active' );
		}

		try {
			sessionStorage.setItem( 'gdtg_active_tab', tabId );
		} catch ( e ) {
			/* ignore */
		}

		// Fire custom event so Imported Docs manager can initialize on tab activation
		document.dispatchEvent(
			new CustomEvent( 'gdtg_admin_tab_switch', {
				detail: { tab: tabId },
			} )
		);
	}

	// ── Resolve active tab: URL param > sessionStorage > first tab ──
	let urlTab = null;
	try {
		const params = new URLSearchParams( window.location.search );
		urlTab = params.get( 'tab' );
	} catch ( e ) {
		/* ignore */
	}

	let savedTab = null;
	try {
		savedTab = sessionStorage.getItem( 'gdtg_active_tab' );
	} catch ( e ) {
		/* ignore */
	}

	document
		.querySelectorAll( '.nav-tab[data-tab]' )
		.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				showTab( this.getAttribute( 'data-tab' ) );
			} );
		} );

	const activeTab = urlTab || savedTab;
	if ( activeTab && document.getElementById( 'gdtg-tab-' + activeTab ) ) {
		showTab( activeTab );
	} else {
		const firstTab = document.querySelector( '.nav-tab[data-tab]' );
		if ( firstTab ) {
			showTab( firstTab.getAttribute( 'data-tab' ) );
		}
	}

	// ── Connection mode selector ──
	window.selectMode = function ( mode ) {
		document.getElementById( 'mode_saas' ).checked = mode === 'saas';
		document.getElementById( 'mode_enterprise' ).checked =
			mode === 'enterprise';

		if ( mode === 'saas' ) {
			document.getElementById( 'gdtg-saas-fields' ).style.display =
				'block';
			document.getElementById( 'gdtg-enterprise-fields' ).style.display =
				'none';
			document
				.querySelectorAll( '.gdtg-mode-card' )[ 0 ]
				.classList.add( 'active' );
			document
				.querySelectorAll( '.gdtg-mode-card' )[ 1 ]
				.classList.remove( 'active' );
		} else {
			document.getElementById( 'gdtg-saas-fields' ).style.display =
				'none';
			document.getElementById( 'gdtg-enterprise-fields' ).style.display =
				'block';
			document
				.querySelectorAll( '.gdtg-mode-card' )[ 0 ]
				.classList.remove( 'active' );
			document
				.querySelectorAll( '.gdtg-mode-card' )[ 1 ]
				.classList.add( 'active' );
		}
	};

	// ═══════════════════════════════════════════════════
	// Imported Docs Manager
	// ═══════════════════════════════════════════════════

	let managerLoaded = false;
	const pollingTimers = {};

	/** Fetch imported-docs listing from REST. */
	function fetchImportedDocs() {
		const sourcesTable = document.getElementById(
			'gdtg-imported-docs-sources-table'
		);
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return
		const localTable = document.getElementById(
			'gdtg-imported-docs-local-table'
		);

		if ( ! window.GDTG_Admin || ! window.GDTG_Admin.rest_url ) {
			if ( sourcesTable ) {
				sourcesTable.innerHTML =
					'<p class="gdtg-imported-docs-error">' +
					'REST API not configured.</p>';
			}
			return;
		}

		fetch( window.GDTG_Admin.rest_url + 'imported-docs', {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.GDTG_Admin.nonce,
				'Content-Type': 'application/json',
			},
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( renderManagerData )
			.catch( function ( err ) {
				const msg =
					'<p class="gdtg-imported-docs-error">Failed to load imported docs. ' +
					( err.message || '' ) +
					'</p>';
				if ( sourcesTable ) {
					sourcesTable.innerHTML = msg;
				}
				if ( localTable ) {
					localTable.innerHTML = '';
				}
			} );
	}

	/**
	 * Render both tables from manager JSON.
	 * @param {Object} data
	 */
	function renderManagerData( data ) {
		renderSourcesTable(
			document.getElementById( 'gdtg-imported-docs-sources-table' ),
			data.sources || []
		);
		renderLocalTable(
			document.getElementById( 'gdtg-imported-docs-local-table' ),
			data.local_uploads || []
		);
	}

	/**
	 * Render remote sources table with expandable linked posts.
	 * @param {HTMLElement} container
	 * @param {Array}       sources
	 */
	function renderSourcesTable( container, sources ) {
		if ( ! sources.length ) {
			container.innerHTML =
				'<p class="description">No remote sources found.</p>';
			return;
		}

		let html =
			'<table class="wp-list-table widefat fixed striped"><thead><tr>';
		html +=
			'<th>Source</th><th>Type</th><th>Last Imported</th><th>Linked Posts</th><th></th>';
		html += '</tr></thead><tbody>';

		sources.forEach( function ( src, srcIdx ) {
			const latest = src.latest_imported_at || '\u2014';
			const postCount = src.posts ? src.posts.length : 0;

			html += '<tr class="gdtg-imported-docs-source-row">';
			html +=
				'<td><strong>' +
				escapeHtml( src.source_name || src.source_id ) +
				'</strong></td>';
			html += '<td>' + escapeHtml( src.source_type ) + '</td>';
			html += '<td>' + escapeHtml( latest ) + '</td>';
			html += '<td>' + postCount + '</td>';
			html +=
				'<td><button type="button" class="button button-small gdtg-imported-docs-toggle" data-src="' +
				srcIdx +
				'">Expand \u25BC</button></td>';
			html += '</tr>';

			// Expanded linked posts row (hidden initially)
			html +=
				'<tr class="gdtg-imported-docs-posts-row" id="gdtg-imported-docs-posts-' +
				srcIdx +
				'" style="display:none;">';
			html += '<td colspan="5" style="padding:0;">';
			html +=
				'<table class="wp-list-table widefat" style="border:0;margin:0;">';
			html += '<thead><tr>';
			html +=
				'<th>Post</th><th>Status</th><th>Last Imported</th><th>Actions</th>';

			( src.posts || [] ).forEach( function ( post ) {
				const status = post.last_sync_status || 'unknown';
				const badgeClass =
					'gdtg-imported-docs-status-badge gdtg-imported-docs-status-badge--' +
					status;
				const badgeLabel = status.replace( '_', ' ' );

				html += '<tr class="gdtg-imported-docs-post-row">';
				html +=
					'<td><a href="' +
					escapeAttr( post.edit_url || '#' ) +
					'">' +
					escapeHtml( post.post_title || 'Post #' + post.post_id ) +
					'</a></td>';
				html +=
					'<td><span class="' +
					badgeClass +
					'" data-post-id="' +
					post.post_id +
					'" style="cursor:pointer;" title="Click to view recent events">' +
					escapeHtml( badgeLabel ) +
					'</span></td>';
				html +=
					'<td>' +
					escapeHtml( post.last_imported_at || '\u2014' ) +
					'</td>';
				html +=
					'<td><button type="button" class="button button-small gdtg-imported-docs-resync" data-post-id="' +
					post.post_id +
					'" ' +
					( post.resyncable ? '' : 'disabled' ) +
					'>Resync</button></td>';
				html += '</tr>';

				// Hidden events row
				html +=
					'<tr class="gdtg-imported-docs-events" id="gdtg-events-' +
					post.post_id +
					'" style="display:none;">';
				html +=
					'<td colspan="4" class="gdtg-imported-docs-events-cell" id="gdtg-events-content-' +
					post.post_id +
					'"></td>';
				html += '</tr>';
			} );

			html += '</tbody></table>';
			html += '</td></tr>';
		} );

		html += '</tbody></table>';
		container.innerHTML = html;

		// Wire expand/collapse toggles
		container
			.querySelectorAll( '.gdtg-imported-docs-toggle' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					const idx = this.getAttribute( 'data-src' );
					const row = document.getElementById(
						'gdtg-imported-docs-posts-' + idx
					);
					if ( row ) {
						const showing = row.style.display !== 'none';
						row.style.display = showing ? 'none' : 'table-row';
						this.textContent = showing
							? 'Expand \u25BC'
							: 'Collapse \u25B2';
					}
				} );
			} );

		// Wire status badge clicks — fetch and show events.
		container
			.querySelectorAll( '.gdtg-imported-docs-status-badge' )
			.forEach( function ( badge ) {
				badge.addEventListener( 'click', function () {
					const pid = parseInt(
						this.getAttribute( 'data-post-id' ),
						10
					);
					togglePostEvents( pid );
				} );
			} );

		// Wire resync buttons
		container
			.querySelectorAll( '.gdtg-imported-docs-resync' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( this.disabled ) {
						return;
					}
					triggerResync(
						parseInt( this.getAttribute( 'data-post-id' ), 10 ),
						this
					);
				} );
			} );
	}

	/**
	 * Fetch and toggle recent sync events for a linked post.
	 * @param {number} postId
	 */
	function togglePostEvents( postId ) {
		const eventsRow = document.getElementById( 'gdtg-events-' + postId );
		const contentCell = document.getElementById(
			'gdtg-events-content-' + postId
		);

		if ( ! eventsRow || ! contentCell ) {
			return;
		}

		// If already visible, hide.
		if ( eventsRow.style.display !== 'none' ) {
			eventsRow.style.display = 'none';
			return;
		}

		// Show loading.
		eventsRow.style.display = 'table-row';
		contentCell.innerHTML =
			'<span class="gdtg-imported-docs-loading">Loading events\u2026</span>';

		fetch( window.GDTG_Admin.rest_url + 'sync/' + postId + '/events', {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.GDTG_Admin.nonce,
			},
		} )
			.then( function ( resp ) {
				return resp.json();
			} )
			.then( function ( data ) {
				const events = data.events || [];
				if ( ! events.length ) {
					contentCell.innerHTML =
						'<p class="description">No recent events.</p>';
					return;
				}

				let eventsHtml = '<ul class="gdtg-events-list">';
				events.slice( 0, 5 ).forEach( function ( ev ) {
					const levelClass =
						'gdtg-event-level gdtg-event-level--' +
						( ev.level || 'info' );
					const timeStr = ev.ts ? formatRelativeTime( ev.ts ) : '';
					eventsHtml += '<li class="gdtg-event-item">';
					eventsHtml +=
						'<span class="' +
						levelClass +
						'">' +
						escapeHtml( ev.level || 'info' ) +
						'</span> ';
					eventsHtml +=
						'<span class="gdtg-event-message">' +
						escapeHtml( ev.message || '' ) +
						'</span> ';
					if ( timeStr ) {
						eventsHtml +=
							'<span class="gdtg-event-time">' +
							escapeHtml( timeStr ) +
							'</span>';
					}
					eventsHtml += '</li>';
				} );
				eventsHtml += '</ul>';
				contentCell.innerHTML = eventsHtml;
			} )
			.catch( function () {
				contentCell.innerHTML =
					'<p class="gdtg-imported-docs-error">Failed to load events.</p>';
			} );
	}

	/**
	 * Format a Unix timestamp as a relative time string.
	 * @param {number} ts Unix timestamp in seconds.
	 * @return {string} Relative time string (e.g. "2 min ago").
	 */
	function formatRelativeTime( ts ) {
		const now = Math.floor( Date.now() / 1000 );
		const diff = now - ts;
		if ( diff < 60 ) {
			return 'just now';
		}
		if ( diff < 3600 ) {
			const mins = Math.floor( diff / 60 );
			return mins + ' min ago';
		}
		if ( diff < 86400 ) {
			const hours = Math.floor( diff / 3600 );
			return hours + ' hr ago';
		}
		const days = Math.floor( diff / 86400 );
		return days + ' day' + ( days > 1 ? 's' : '' ) + ' ago';
	}

	/**
	 * Render local uploads table (non-resyncable).
	 * @param {HTMLElement} container
	 * @param {Array}       uploads
	 */
	function renderLocalTable( container, uploads ) {
		if ( ! uploads.length ) {
			container.innerHTML =
				'<p class="description">No local uploads found.</p>';
			return;
		}

		let html =
			'<table class="wp-list-table widefat fixed striped"><thead><tr>';
		html +=
			'<th>Post</th><th>Source Name</th><th>Last Imported</th><th>Resync</th>';
		html += '</tr></thead><tbody>';

		uploads.forEach( function ( item ) {
			html += '<tr>';
			html +=
				'<td><a href="' +
				escapeAttr( item.edit_url || '#' ) +
				'">' +
				escapeHtml( item.post_title || 'Post #' + item.post_id ) +
				'</a></td>';
			html +=
				'<td>' + escapeHtml( item.post_title || '\u2014' ) + '</td>';
			html +=
				'<td>' +
				escapeHtml( item.last_imported_at || '\u2014' ) +
				'</td>';
			html +=
				'<td><button type="button" class="button button-small" disabled title="Local uploads can\u2019t be resynced because there\u2019s no remote source to fetch again.">Resync</button></td>';
			html += '</tr>';
		} );

		html += '</tbody></table>';
		container.innerHTML = html;
	}

	/**
	 * Trigger resync for a linked WP post, handling immediate or batched responses.
	 * @param {number}            postId
	 * @param {HTMLButtonElement} btn
	 */
	function triggerResync( postId, btn ) {
		const originalText = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Syncing\u2026';

		fetch( window.GDTG_Admin.rest_url + 'sync/' + postId, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.GDTG_Admin.nonce,
				'Content-Type': 'application/json',
			},
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.batch && data.job_id ) {
					// Batch job — start polling
					pollJobStatus( data.job_id, btn, originalText );
				} else if ( data.success ) {
					btn.textContent = 'Done';
					btn.style.color = '#0a6e2e';
					setTimeout( function () {
						btn.textContent = originalText;
						btn.style.color = '';
						btn.disabled = false;
					}, 3000 );
					fetchImportedDocs(); // refresh listing
				} else if ( data.message ) {
					btn.textContent = 'Error';
					btn.style.color = '#b32d2e';
					// eslint-disable-next-line no-alert
					alert( data.message );
					setTimeout( function () {
						btn.textContent = originalText;
						btn.style.color = '';
						btn.disabled = false;
					}, 3000 );
				} else {
					btn.disabled = false;
					btn.textContent = originalText;
				}
			} )
			.catch( function ( err ) {
				btn.textContent = 'Error';
				btn.style.color = '#b32d2e';
				// eslint-disable-next-line no-alert
				alert( err.message || 'Resync failed' );
				setTimeout( function () {
					btn.textContent = originalText;
					btn.style.color = '';
					btn.disabled = false;
				}, 3000 );
			} );
	}

	/**
	 * Poll batch job status until completion.
	 * @param {string}            jobId
	 * @param {HTMLButtonElement} btn
	 * @param {string}            originalText
	 */
	function pollJobStatus( jobId, btn, originalText ) {
		btn.textContent = 'Polling\u2026';

		function poll() {
			fetch( window.GDTG_Admin.rest_url + 'import/' + jobId + '/status', {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': window.GDTG_Admin.nonce,
				},
			} )
				.then( function ( resp ) {
					return resp.json();
				} )
				.then( function ( data ) {
					if ( data.status === 'complete' ) {
						btn.textContent = 'Done';
						btn.style.color = '#0a6e2e';
						setTimeout( function () {
							btn.textContent = originalText;
							btn.style.color = '';
							btn.disabled = false;
						}, 3000 );
						fetchImportedDocs(); // refresh listing
					} else if (
						data.status === 'pending' ||
						data.status === 'processing'
					) {
						btn.textContent =
							'Processing ' +
							( data.image_done || 0 ) +
							'/' +
							( data.image_total || 0 );
						pollingTimers[ jobId ] = setTimeout( poll, 2000 );
					} else {
						btn.textContent = 'Error';
						btn.style.color = '#b32d2e';
						setTimeout( function () {
							btn.textContent = originalText;
							btn.style.color = '';
							btn.disabled = false;
						}, 3000 );
					}
				} )
				.catch( function () {
					btn.textContent = 'Error';
					btn.style.color = '#b32d2e';
					setTimeout( function () {
						btn.textContent = originalText;
						btn.style.color = '';
						btn.disabled = false;
					}, 3000 );
				} );
		}

		pollingTimers[ jobId ] = setTimeout( poll, 2000 );
	}

	let importMode = 'url';

	/**
	 * Switch between URL and file import modes.
	 * @param {string} mode
	 */
	function switchImportMode( mode ) {
		importMode = mode;
		const urlSection = document.getElementById( 'gdtg-import-url-section' );
		const fileSection = document.getElementById(
			'gdtg-import-file-section'
		);
		const submitBtn = document.getElementById( 'gdtg-import-submit' );

		document
			.querySelectorAll( '.gdtg-import-mode-tab' )
			.forEach( function ( t ) {
				t.classList.toggle(
					'active',
					t.getAttribute( 'data-import-mode' ) === mode
				);
			} );

		if ( urlSection ) {
			urlSection.style.display = mode === 'url' ? '' : 'none';
		}
		if ( fileSection ) {
			fileSection.style.display = mode === 'file' ? '' : 'none';
		}

		if ( submitBtn ) {
			submitBtn.textContent =
				mode === 'file'
					? 'Upload & Parse .docx'
					: 'Import & Parse Document';
		}

		// Reset result/state
		const resultEl = document.getElementById( 'gdtg-import-result' );
		if ( resultEl ) {
			resultEl.textContent = '';
			resultEl.className = 'gdtg-import-result';
		}
	}

	/**
	 * Show selected file name in the file section.
	 * @param {File} file
	 */
	function handleFileSelect( file ) {
		const nameEl = document.getElementById( 'gdtg-import-docx-name' );
		if ( nameEl && file ) {
			nameEl.textContent = 'Selected: ' + file.name;
			nameEl.className =
				'gdtg-import-docx-name gdtg-import-docx-name--selected';
		}
	}

	/**
	 * Handle Import form submission — URL mode calls /import, file mode calls /upload-docx.
	 */
	function handleImport() {
		/* eslint-disable @wordpress/no-unused-vars-before-return */
		const urlInput = document.getElementById( 'gdtg-import-doc-url' );
		const fileInput = document.getElementById( 'gdtg-import-docx-file' );
		const btn = document.getElementById( 'gdtg-import-submit' );
		const resultEl = document.getElementById( 'gdtg-import-result' );
		/* eslint-enable @wordpress/no-unused-vars-before-return */
		if ( importMode === 'file' ) {
			const file =
				fileInput && fileInput.files.length
					? fileInput.files[ 0 ]
					: null;
			if ( ! file ) {
				if ( resultEl ) {
					resultEl.textContent = 'Please select a .docx file.';
					resultEl.className =
						'gdtg-import-result gdtg-import-result--error';
				}
				return;
			}

			if ( btn ) {
				btn.disabled = true;
				btn.textContent = 'Parsing .docx\u2026';
			}

			const formData = new FormData();
			formData.append( 'file', file );
			formData.append(
				'import_images',
				document.getElementById( 'gdtg-import-images' )
					? document.getElementById( 'gdtg-import-images' ).checked
					: true
			);
			formData.append(
				'import_tables',
				document.getElementById( 'gdtg-import-tables' )
					? document.getElementById( 'gdtg-import-tables' ).checked
					: true
			);
			formData.append(
				'overwrite',
				document.getElementById( 'gdtg-import-overwrite' )
					? document.getElementById( 'gdtg-import-overwrite' ).checked
					: false
			);
			formData.append(
				'import_as_draft',
				document.getElementById( 'gdtg-import-as-draft' )
					? document.getElementById( 'gdtg-import-as-draft' ).checked
					: false
			);
			formData.append(
				'output_mode',
				document.getElementById( 'gdtg-import-output-mode' )
					? document.getElementById( 'gdtg-import-output-mode' ).value
					: 'gutenberg'
			);
			fetch( window.GDTG_Admin.rest_url + 'upload-docx', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.GDTG_Admin.nonce },
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( data.batch && data.job_id ) {
						if ( resultEl ) {
							resultEl.textContent = 'Processing images\u2026';
							resultEl.className = 'gdtg-import-result';
						}
						pollImportJob( data.job_id, btn, resultEl );
					} else if ( data.success && data.post_id ) {
						if ( resultEl ) {
							resultEl.innerHTML =
								'<span style="color:#0a6e2e;">\u2713 Imported</span> \u2014 ' +
								'<a href="' +
								escapeAttr( data.edit_url || '#' ) +
								'">' +
								escapeHtml( data.title || 'View Post' ) +
								'</a>';
							resultEl.className =
								'gdtg-import-result gdtg-import-result--success';
						}
						if ( fileInput ) {
							fileInput.value = '';
						}
						const nameEl = document.getElementById(
							'gdtg-import-docx-name'
						);
						if ( nameEl ) {
							nameEl.textContent = '';
							nameEl.className = 'gdtg-import-docx-name';
						}
						fetchImportedDocs();
					} else if ( data.message ) {
						if ( resultEl ) {
							resultEl.textContent = data.message;
							resultEl.className =
								'gdtg-import-result gdtg-import-result--error';
						}
					}
					if ( btn ) {
						btn.disabled = false;
						btn.textContent =
							importMode === 'file'
								? 'Upload & Parse .docx'
								: 'Import & Parse Document';
					}
				} )
				.catch( function ( err ) {
					if ( resultEl ) {
						resultEl.textContent = err.message || 'Upload failed';
						resultEl.className =
							'gdtg-import-result gdtg-import-result--error';
					}
					if ( btn ) {
						btn.disabled = false;
						btn.textContent =
							importMode === 'file'
								? 'Upload & Parse .docx'
								: 'Import & Parse Document';
					}
				} );

			return; // file mode — exit early
		}

		// — URL mode —
		const docUrl = urlInput ? urlInput.value.trim() : '';
		if ( ! docUrl ) {
			if ( resultEl ) {
				resultEl.textContent = 'Please enter a Google Doc URL.';
				resultEl.className =
					'gdtg-import-result gdtg-import-result--error';
			}
			return;
		}

		if ( btn ) {
			btn.disabled = true;
			btn.textContent = 'Parsing Google Doc\u2026';
		}
		if ( resultEl ) {
			resultEl.textContent = '';
			resultEl.className = 'gdtg-import-result';
		}

		const body = {
			doc_id: docUrl,
			import_images: document.getElementById( 'gdtg-import-images' )
				? document.getElementById( 'gdtg-import-images' ).checked
				: true,
			import_tables: document.getElementById( 'gdtg-import-tables' )
				? document.getElementById( 'gdtg-import-tables' ).checked
				: true,
			overwrite: document.getElementById( 'gdtg-import-overwrite' )
				? document.getElementById( 'gdtg-import-overwrite' ).checked
				: false,
			import_as_draft: document.getElementById( 'gdtg-import-as-draft' )
				? document.getElementById( 'gdtg-import-as-draft' ).checked
				: false,
			output_mode: document.getElementById( 'gdtg-import-output-mode' )
				? document.getElementById( 'gdtg-import-output-mode' ).value
				: 'gutenberg',
		};

		fetch( window.GDTG_Admin.rest_url + 'import', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.GDTG_Admin.nonce,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( body ),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.batch && data.job_id ) {
					if ( resultEl ) {
						resultEl.textContent = 'Processing images\u2026';
						resultEl.className = 'gdtg-import-result';
					}
					pollImportJob( data.job_id, btn, resultEl );
				} else if ( data.success && data.post_id ) {
					if ( resultEl ) {
						resultEl.innerHTML =
							'<span style="color:#0a6e2e;">\u2713 Imported</span> \u2014 ' +
							'<a href="' +
							escapeAttr( data.edit_url || '#' ) +
							'">' +
							escapeHtml( data.title || 'View Post' ) +
							'</a>';
						resultEl.className =
							'gdtg-import-result gdtg-import-result--success';
					}
					if ( urlInput ) {
						urlInput.value = '';
					}
					fetchImportedDocs();
				} else if ( data.message ) {
					if ( resultEl ) {
						resultEl.textContent = data.message;
						resultEl.className =
							'gdtg-import-result gdtg-import-result--error';
					}
				}
				if ( btn ) {
					btn.disabled = false;
					btn.textContent = 'Import & Parse Document';
				}
			} )
			.catch( function ( err ) {
				if ( resultEl ) {
					resultEl.textContent = err.message || 'Import failed';
					resultEl.className =
						'gdtg-import-result gdtg-import-result--error';
				}
				if ( btn ) {
					btn.disabled = false;
					btn.textContent = 'Import & Parse Document';
				}
			} );
	}

	/**
	 * Poll batch import job status until completion, then show result.
	 * @param {string}            jobId
	 * @param {HTMLButtonElement} btn
	 * @param {HTMLElement}       resultEl
	 */
	function pollImportJob( jobId, btn, resultEl ) {
		function poll() {
			fetch( window.GDTG_Admin.rest_url + 'import/' + jobId + '/status', {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': window.GDTG_Admin.nonce,
				},
			} )
				.then( function ( resp ) {
					return resp.json();
				} )
				.then( function ( data ) {
					if ( data.status === 'complete' ) {
						if ( resultEl ) {
							resultEl.innerHTML =
								'<span style="color:#0a6e2e;">\u2713 Imported</span> \u2014 ' +
								( data.edit_url
									? '<a href="' +
									  escapeAttr( data.edit_url ) +
									  '">' +
									  escapeHtml(
											data.message || 'View Post'
									  ) +
									  '</a>'
									: escapeHtml( data.message || '' ) );
							resultEl.className =
								'gdtg-import-result gdtg-import-result--success';
						}
						if ( btn ) {
							btn.disabled = false;
							btn.textContent = 'Import & Parse Document';
						}
						fetchImportedDocs();
					} else if (
						data.status === 'pending' ||
						data.status === 'processing'
					) {
						if ( resultEl ) {
							resultEl.textContent =
								'Processing images: ' +
								( data.image_done || 0 ) +
								'/' +
								( data.image_total || 0 );
						}
						pollingTimers[ jobId ] = setTimeout( poll, 2000 );
					} else {
						if ( resultEl ) {
							resultEl.textContent =
								data.message || 'Import failed';
							resultEl.className =
								'gdtg-import-result gdtg-import-result--error';
						}
						if ( btn ) {
							btn.disabled = false;
							btn.textContent = 'Import & Parse Document';
						}
					}
				} )
				.catch( function () {
					if ( resultEl ) {
						resultEl.textContent = 'Import failed';
						resultEl.className =
							'gdtg-import-result gdtg-import-result--error';
					}
					if ( btn ) {
						btn.disabled = false;
						btn.textContent = 'Import & Parse Document';
					}
				} );
		}

		pollingTimers[ jobId ] = setTimeout( poll, 2000 );
	}

	/** Load manager data and wire import button when Imported Docs tab becomes active. */
	function initManagerIfNeeded() {
		const panel = document.getElementById( 'gdtg-tab-imported-docs' );
		if ( ! panel ) {
			return;
		}
		if ( panel.classList.contains( 'active' ) && ! managerLoaded ) {
			managerLoaded = true;
			fetchImportedDocs();

			// Wire import form — mode tabs, file input, submit
			document
				.querySelectorAll( '.gdtg-import-mode-tab' )
				.forEach( function ( tab ) {
					tab.addEventListener( 'click', function () {
						switchImportMode(
							this.getAttribute( 'data-import-mode' )
						);
					} );
				} );

			const fileInput = document.getElementById(
				'gdtg-import-docx-file'
			);
			if ( fileInput ) {
				fileInput.addEventListener( 'change', function () {
					handleFileSelect( this.files[ 0 ] );
				} );
			}

			const importBtn = document.getElementById( 'gdtg-import-submit' );
			if ( importBtn ) {
				importBtn.addEventListener( 'click', handleImport );
			}
		}
	}

	// Listen for tab switch events
	document.addEventListener( 'gdtg_admin_tab_switch', function ( e ) {
		if ( e.detail && e.detail.tab === 'imported-docs' ) {
			initManagerIfNeeded();
		}
	} );

	// Also check on initial load (in case Imported Docs tab is active from URL)
	if (
		document.readyState === 'complete' ||
		document.readyState === 'interactive'
	) {
		setTimeout( initManagerIfNeeded, 1 );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			setTimeout( initManagerIfNeeded, 1 );
		} );
	}

	// ── Simple HTML escaping helpers ──
	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function escapeAttr( str ) {
		if ( ! str ) {
			return '';
		}
		return str
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}
} )();

// ═══════════════════════════════════════════════════
// Direct OAuth Setup Helpers
// ═══════════════════════════════════════════════════

// ── Redirect URI copy button ──
( function () {
	const copyBtn = document.getElementById( 'gdtg-copy-redirect-uri' );
	const uriInput = document.getElementById( 'gdtg_enterprise_redirect_uri' );

	if ( copyBtn && uriInput ) {
		copyBtn.addEventListener( 'click', function () {
			uriInput.select();
			uriInput.setSelectionRange( 0, 99999 ); // mobile

			try {
				const success = document.execCommand( 'copy' );
				if ( success ) {
					copyBtn.textContent = 'Copied!';
					setTimeout( function () {
						copyBtn.textContent = 'Copy';
					}, 2000 );
				}
			} catch ( e ) {
				copyBtn.textContent = 'Failed';
				setTimeout( function () {
					copyBtn.textContent = 'Copy';
				}, 2000 );
			}
		} );
	}
} )();

// ── JSON import helper ──
( function () {
	const jsonInput = document.getElementById( 'gdtg_json_import' );
	const importBtn = document.getElementById( 'gdtg-import-json-btn' );
	const statusEl = document.getElementById( 'gdtg-json-import-status' );
	const clientIdInput = document.getElementById(
		'gdtg_enterprise_client_id'
	);
	const clientSecretInput = document.getElementById(
		'gdtg_enterprise_client_secret'
	);

	if (
		! jsonInput ||
		! importBtn ||
		! statusEl ||
		! clientIdInput ||
		! clientSecretInput
	) {
		return;
	}

	function showStatus( msg, type ) {
		statusEl.textContent = msg;
		statusEl.style.display = '';
		statusEl.className = 'description gdtg-json-import-status--' + type;
	}

	function clearStatus() {
		statusEl.textContent = '';
		statusEl.style.display = 'none';
		statusEl.className = 'description';
	}

	importBtn.addEventListener( 'click', function () {
		const file =
			jsonInput.files && jsonInput.files.length
				? jsonInput.files[ 0 ]
				: null;

		if ( ! file ) {
			showStatus( 'Please select a JSON file first.', 'error' );
			return;
		}

		if (
			file.type &&
			file.type !== 'application/json' &&
			! /\.json$/i.test( file.name )
		) {
			showStatus(
				'File does not appear to be JSON. Please select a .json file.',
				'error'
			);
			return;
		}

		const reader = new FileReader();
		reader.onload = function ( e ) {
			let parsed;

			try {
				parsed = JSON.parse( e.target.result );
			} catch ( err ) {
				showStatus( 'Invalid JSON: ' + err.message, 'error' );
				return;
			}

			if ( ! parsed || typeof parsed !== 'object' ) {
				showStatus(
					'JSON file does not contain a valid object.',
					'error'
				);
				return;
			}

			// Detect desktop/native (installed) client.
			if ( parsed.installed ) {
				showStatus(
				'This is a Desktop / Native application client. Direct OAuth mode requires a Web Application OAuth client. Create one in Google Cloud Console.',
					'error'
				);
				return;
			}

			// Look for web client credentials.
			const web = parsed.web;
			if ( ! web || typeof web !== 'object' ) {
				showStatus(
					'JSON missing "web" key. Ensure you downloaded a Web Application OAuth client JSON.',
					'error'
				);
				return;
			}

			const cid = web.client_id;
			const csec = web.client_secret;

			if ( ! cid || typeof cid !== 'string' ) {
				showStatus( 'JSON missing web.client_id.', 'error' );
				return;
			}

			if ( ! csec || typeof csec !== 'string' ) {
				showStatus( 'JSON missing web.client_secret.', 'error' );
				return;
			}

			// Prefill form fields.
			clientIdInput.value = cid;
			clientSecretInput.value = csec;

			showStatus(
				'Client ID and Client Secret prefilled. Click Save Settings to store them.',
				'success'
			);

			// Clear file input so the same file can be re-imported.
			jsonInput.value = '';
		};

		reader.onerror = function () {
			showStatus( 'Failed to read the file.', 'error' );
		};

		reader.readAsText( file );
	} );

	// Clear status when a new file is selected.
	jsonInput.addEventListener( 'change', function () {
		clearStatus();
	} );
} )();

// ── Google Picker (WP Admin) ──
( function () {
	const GDTG = window.GDTG_Admin;
	if ( ! GDTG || ! GDTG.picker_config_url ) {
		return;
	}

	const pickerRow   = document.getElementById( 'gdtg-picker-row' );
	const pickerBtn   = document.getElementById( 'gdtg-admin-picker-btn' );
	const pickerError = document.getElementById( 'gdtg-admin-picker-error' );
	const urlInput    = document.getElementById( 'gdtg-import-doc-url' );

	if ( ! pickerRow || ! pickerBtn || ! urlInput ) {
		return;
	}

	let pickerConfig = null;
	let gapiLoaded   = false;

	function showError( msg ) {
		if ( pickerError ) {
			pickerError.textContent = msg;
			pickerError.style.display = 'block';
		}
	}

	function clearError() {
		if ( pickerError ) {
			pickerError.textContent = '';
			pickerError.style.display = 'none';
		}
	}

	// Fetch picker config on page load.
	fetch( GDTG.picker_config_url, {
		method: 'GET',
		headers: { 'X-WP-Nonce': GDTG.nonce },
	} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data && data.enabled ) {
				pickerConfig = data;
				pickerRow.style.display = '';
			}
		} )
		.catch( function () {
			// Silently ignore — button stays hidden.
		} );

	pickerBtn.addEventListener( 'click', async function () {
		clearError();

		// Fetch picker-scoped access token.
		let token;
		try {
			const tokenResp = await fetch(
				GDTG.picker_token_url + '?purpose=picker',
				{
					method: 'GET',
					headers: { 'X-WP-Nonce': GDTG.nonce },
				}
			);
			const body = await tokenResp.json();
			if ( ! tokenResp.ok || ! body.token ) {
				showError( body.message || 'Could not get an access token. Please reconnect your Google account.' );
				return;
			}
			token = body.token;
		} catch ( err ) {
			showError( 'Could not authenticate with Google. Please reconnect your account.' );
			return;
		}

		// Lazy-load Google API script.
		if ( typeof window.gapi === 'undefined' ) {
			try {
				await new Promise( function ( resolve, reject ) {
					var s = document.createElement( 'script' );
					s.src = 'https://apis.google.com/js/api.js';
					s.onload = resolve;
					s.onerror = function () { reject( new Error( 'Failed to load Google API script.' ) ); };
					document.head.appendChild( s );
				} );
			} catch ( err ) {
				showError( 'Failed to load Google API script.' );
				return;
			}
		}

		// Load Picker library.
		if ( ! gapiLoaded ) {
			try {
				await new Promise( function ( resolve, reject ) {
					window.gapi.load( 'picker', {
						callback: resolve,
						onerror: function () { reject( new Error( 'Failed to load Google Picker library.' ) ); },
					} );
				} );
				gapiLoaded = true;
			} catch ( err ) {
				showError( 'Failed to load Google Picker library.' );
				return;
			}
		}

		var appId         = pickerConfig.app_id;
		var developerKey  = pickerConfig.developer_key;

		if ( ! appId || ! developerKey ) {
			showError( 'Picker is not configured. Set App ID and Developer Key in DraftSync settings.' );
			return;
		}

		var view = new window.google.picker.DocsView()
			.setIncludeFolders( false )
			.setMimeTypes(
				'application/vnd.google-apps.document,application/vnd.openxmlformats-officedocument.wordprocessingml.document'
			);

		var picker = new window.google.picker.PickerBuilder()
			.enableFeature( window.google.picker.Feature.NAV_HIDDEN )
			.setAppId( appId )
			.setOAuthToken( token )
			.setDeveloperKey( developerKey )
			.addView( view )
			.setCallback( function ( data ) {
				if ( data.action === window.google.picker.Action.PICKED ) {
					var doc = data.docs && data.docs[ 0 ];
					if ( ! doc ) { return; }

					var mimeType = doc.mimeType || '';
					var isGDoc   = mimeType === 'application/vnd.google-apps.document';
					var isDocx   = mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

					if ( ! isGDoc && ! isDocx ) {
						showError( 'Unsupported file. Choose a Google Doc or .docx.' );
						return;
					}

					var url = isGDoc && doc.url
						? doc.url
						: 'https://drive.google.com/file/d/' + doc.id + '/view';

					urlInput.value = url;
					clearError();

					// Switch to URL tab if on file tab.
					var urlTab = document.querySelector( '[data-import-mode="url"]' );
					var fileSection = document.getElementById( 'gdtg-import-file-section' );
					var urlSection  = document.getElementById( 'gdtg-import-url-section' );
					if ( urlTab && fileSection && urlSection ) {
						urlTab.classList.add( 'active' );
						fileSection.style.display = 'none';
						urlSection.style.display  = '';
					}
				}
			} )
			.build();

		picker.setVisible( true );
	} );
} )();
