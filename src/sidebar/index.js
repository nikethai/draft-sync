import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { PluginSidebar } from '@wordpress/edit-post';
import {
	PanelBody,
	TextControl,
	Button,
	Spinner,
	Notice,
	ToggleControl,
	SelectControl,
	TextareaControl,
	RangeControl,
	RadioControl,
	CheckboxControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { registerPlugin } from '@wordpress/plugins';
import './sidebar.css';

const cloudUploadIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="20"
		height="20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M21 2v6h-6" />
		<path d="M3 12a9 9 0 0 1 15-6.7L21 8" />
		<path d="M3 22v-6h6" />
		<path d="M21 12a9 9 0 0 1-15 6.7L3 16" />
	</svg>
);
const documentIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="20"
		height="20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
		<polyline points="14 2 14 8 20 8" />
		<line x1="16" y1="13" x2="8" y2="13" />
		<line x1="16" y1="17" x2="8" y2="17" />
	</svg>
);

/* global GDTG_Settings */
// eslint-disable-next-line camelcase
const settings = typeof GDTG_Settings !== 'undefined' ? GDTG_Settings : {};

const POLL_INTERVAL = 3000;

const parseSettingBoolean = ( value, fallback ) => {
	if ( value === undefined || value === null || value === '' ) {
		return fallback;
	}
	if ( typeof value === 'boolean' ) {
		return value;
	}
	if ( typeof value === 'number' ) {
		return value !== 0;
	}
	if ( typeof value === 'string' ) {
		const normalized = value.trim().toLowerCase();
		if ( [ '1', 'true', 'yes', 'on' ].includes( normalized ) ) {
			return true;
		}
		if ( [ '0', 'false', 'no', 'off' ].includes( normalized ) ) {
			return false;
		}
	}
	return fallback;
};

export default function GDTGSidebar() {
	const [ docUrl, setDocUrl ] = useState( '' );
	const [ status, setStatus ] = useState( 'idle' ); // 'idle', 'syncing', 'polling', 'success', 'error'
	const [ errorMsg, setErrorMsg ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ progress, setProgress ] = useState( null ); // { image_done, image_total }
	const [ importMode, setImportMode ] = useState( 'url' ); // 'url' | 'docx' | 'bulk'
	const [ docxFile, setDocxFile ] = useState( null );
	const [ docxName, setDocxName ] = useState( '' );
	const [ bulkRows, setBulkRows ] = useState( [
		{ source: '', post_id: '' },
	] );
	const [ bulkDryRun, setBulkDryRun ] = useState( false );
	const [ bulkResult, setBulkResult ] = useState( null );
	const [ draggedOver, setDraggedOver ] = useState( false );
	const fileInputRef = useRef( null );
	const pollingRef = useRef( null );
	const isMountedRef = useRef( true );
	const isBusy = status === 'syncing' || status === 'polling';
	// ── Picker state (Phase 2) ──
	const [ pickerSource, setPickerSource ] = useState( null ); // { id, url, name, mimeType, type } | null
	const [ pickerConfig, setPickerConfig ] = useState( null ); // { enabled, app_id, developer_key, scopes } | null
	const [ pickerConfigLoading, setPickerConfigLoading ] = useState( true );
	const [ pickerError, setPickerError ] = useState( '' );

	const openDocxPicker = () => {
		if ( isBusy || ! fileInputRef.current ) {
			return;
		}
		fileInputRef.current.click();
	};
	let busyLabel = __( 'Parsing .docx…', 'draftsync' );
	if ( importMode === 'bulk' ) {
		busyLabel = __( 'Importing documents…', 'draftsync' );
	}
	if ( status === 'polling' ) {
		busyLabel = __( 'Processing Images…', 'draftsync' );
	} else if ( importMode === 'url' ) {
		busyLabel = __( 'Parsing Google Doc…', 'draftsync' );
	}

	let submitLabel;
	if ( importMode === 'url' ) {
		submitLabel = __( 'Import & Parse Document', 'draftsync' );
	} else if ( importMode === 'docx' ) {
		submitLabel = __( 'Upload & Parse .docx', 'draftsync' );
	} else {
		submitLabel = __( 'Import All Documents', 'draftsync' );
	}
	// Import options — initialize from admin defaults.
	const [ importImages, setImportImages ] = useState(
		parseSettingBoolean( settings.import_images, true )
	);
	const [ importTables, setImportTables ] = useState(
		parseSettingBoolean( settings.import_tables, true )
	);
	const [ overwrite, setOverwrite ] = useState(
		parseSettingBoolean( settings.overwrite, false )
	);
	const [ importAsDraft, setImportAsDraft ] = useState(
		parseSettingBoolean( settings.import_as_draft, true )
	);
	const [ outputMode, setOutputMode ] = useState(
		settings.output_mode || 'gutenberg'
	);
	const [ optimizeImages, setOptimizeImages ] = useState(
		parseSettingBoolean( settings.optimize_images, true )
	);
	const [ headingDemotion, setHeadingDemotion ] = useState(
		parseInt( settings.heading_demotion, 10 ) || 0
	);
	const [ minHeadingLevel, setMinHeadingLevel ] = useState(
		parseInt( settings.min_heading_level, 10 ) || 1
	);
	const [ defaultAlignment, setDefaultAlignment ] = useState(
		settings.default_alignment || ''
	);
	const [ slug, setSlug ] = useState( '' );
	const [ meta, setMeta ] = useState( {
		excerpt: '',
		seo_title: '',
		seo_description: '',
		focus_keyword: '',
		canonical_url: '',
		categories: '',
		tags: '',
		featured_image: '',
	} );
	const [ resyncStatus, setResyncStatus ] = useState( null ); // 'idle' | 'syncing' | 'success' | 'error'
	const [ resyncMsg, setResyncMsg ] = useState( '' );
	const [ resyncConflict, setResyncConflict ] = useState( false );
	const [ syncStatus, setSyncStatus ] = useState( null );
	const [ syncStatusLoading, setSyncStatusLoading ] = useState( false );
	const [ viewingEvents, setViewingEvents ] = useState( false );
	const [ postEvents, setPostEvents ] = useState( [] );
	const [ postEventsLoading, setPostEventsLoading ] = useState( false );
	const [ autoSyncEnabled, setAutoSyncEnabled ] = useState(
		parseSettingBoolean( settings.post_auto_sync, false )
	);

	const activeBulkRows = useMemo(
		() =>
			bulkRows.filter( ( row ) => {
				return row.source.trim() || row.post_id.trim();
			} ),
		[ bulkRows ]
	);

	const linkedSourceLabel = useMemo( () => {
		if ( ! syncStatus?.source_type ) {
			return '';
		}

		if ( syncStatus.source_type === 'gdoc' ) {
			return __( 'Google Doc', 'draftsync' );
		}

		if ( syncStatus.source_type === 'drive_file' ) {
			return __( 'Drive File', 'draftsync' );
		}

		if ( syncStatus.source_type === 'docx_upload' ) {
			return __( 'Local .docx Upload', 'draftsync' );
		}

		return syncStatus.source_type;
	}, [ syncStatus ] );

	useEffect( () => {
		return () => {
			isMountedRef.current = false;
			if ( pollingRef.current ) {
				clearTimeout( pollingRef.current );
			}
		};
	}, [] );

	useEffect( () => {
		const currentFileInput = fileInputRef.current;

		return () => {
			if ( currentFileInput ) {
				currentFileInput.value = '';
			}
		};
	}, [ importMode ] );

	useEffect( () => {
		if ( ! settings.post_id || ! settings.rest_url || ! settings.nonce ) {
			return undefined;
		}

		const loadSyncStatus = async () => {
			setSyncStatusLoading( true );
			try {
				const response = await apiFetch( {
					url: settings.rest_url.replace(
						/\/import$/,
						`/sync/status?post_id=${ settings.post_id }`
					),
					method: 'GET',
					headers: {
						'X-WP-Nonce': settings.nonce,
					},
				} );

				if ( isMountedRef.current ) {
					setSyncStatus( response );
					setAutoSyncEnabled( !! response.auto_sync );
				}
			} catch ( error ) {
				if ( isMountedRef.current ) {
					setSyncStatus( null );
				}
			} finally {
				if ( isMountedRef.current ) {
					setSyncStatusLoading( false );
				}
			}
		};

		loadSyncStatus();

		return undefined;
	}, [] );

	// ── Fetch picker config on mount ──
	useEffect( () => {
		if (
			! settings.connection_mode ||
			settings.connection_mode !== 'enterprise' ||
			! settings.picker_config_url
		) {
			setPickerConfigLoading( false );
			return undefined;
		}

		let cancelled = false;

		const loadConfig = async () => {
			try {
				const response = await apiFetch( {
					url: settings.picker_config_url,
					method: 'GET',
					headers: { 'X-WP-Nonce': settings.nonce },
				} );
				if ( ! cancelled ) {
					setPickerConfig( response );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setPickerConfig( { enabled: false } );
				}
			} finally {
				if ( ! cancelled ) {
					setPickerConfigLoading( false );
				}
			}
		};

		loadConfig();

		return () => {
			cancelled = true;
		};
	}, [] );

	const buildBasePayload = ( extra = {} ) => {
		const payload = {
			doc_id: docUrl,
			post_id: settings.post_id || 0,
			import_images: importImages,
			import_tables: importTables,
			overwrite,
			import_as_draft: importAsDraft,
			output_mode: outputMode,
			optimize_images: optimizeImages,
			heading_demotion: headingDemotion,
			min_heading_level: minHeadingLevel,
			default_alignment: defaultAlignment,
			...extra,
		};
		const postMeta = buildPostMeta();
		if ( postMeta ) {
			payload.post_meta = postMeta;
		}
		return payload;
	};

	const buildPostMeta = () => {
		const cleaned = {};
		for ( const key of Object.keys( meta ) ) {
			const value = meta[ key ].trim();
			if ( value ) {
				cleaned[ key ] = value;
			}
		}
		if ( slug.trim() ) {
			cleaned.slug = slug.trim();
		}
		return Object.keys( cleaned ).length ? cleaned : null;
	};

	const pollJob = async ( jobId ) => {
		try {
			const statusUrl = settings.rest_url.replace(
				/\/import$/,
				`/import/${ jobId }/status`
			);
			const statusResp = await apiFetch( {
				url: statusUrl,
				method: 'GET',
				headers: {
					'X-WP-Nonce': settings.nonce,
				},
			} );

			if ( ! isMountedRef.current ) {
				return;
			}

			setProgress( {
				image_done: statusResp.image_done || 0,
				image_total: statusResp.image_total || 0,
			} );

			if ( statusResp.status === 'complete' ) {
				setStatus( 'success' );
				setResult( statusResp );
				setProgress( null );
				return;
			}

			if ( statusResp.status === 'error' ) {
				setErrorMsg(
					statusResp.message ||
						__( 'Batch image processing failed.', 'draftsync' )
				);
				setStatus( 'error' );
				setProgress( null );
				return;
			}

			// Continue processing next batch.
			const continueUrl = settings.rest_url.replace(
				/\/import$/,
				`/import/${ jobId }/continue`
			);
			await apiFetch( {
				url: continueUrl,
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				data: {},
			} );

			if ( ! isMountedRef.current ) {
				return;
			}

			// Poll again — clear any pending timeout first.
			if ( ! isMountedRef.current ) {
				return;
			}
			if ( pollingRef.current ) {
				clearTimeout( pollingRef.current );
			}
			pollingRef.current = setTimeout(
				() => pollJob( jobId ),
				POLL_INTERVAL
			);
		} catch ( error ) {
			if ( ! isMountedRef.current ) {
				return;
			}
			setErrorMsg(
				error.message ||
					__( 'Batch image processing failed.', 'draftsync' )
			);
			setStatus( 'error' );
			setProgress( null );
		}
	};

	const updateBulkRow = ( index, key, value ) => {
		setBulkRows( ( currentRows ) =>
			currentRows.map( ( row, rowIndex ) => {
				if ( rowIndex !== index ) {
					return row;
				}

				return {
					...row,
					[ key ]: value,
				};
			} )
		);
	};

	const addBulkRow = () => {
		setBulkRows( ( currentRows ) => [
			...currentRows,
			{ source: '', post_id: '' },
		] );
	};

	const removeBulkRow = ( index ) => {
		setBulkRows( ( currentRows ) => {
			if ( currentRows.length === 1 ) {
				return [ { source: '', post_id: '' } ];
			}

			return currentRows.filter(
				( row, rowIndex ) => rowIndex !== index
			);
		} );
	};

	const handleDropDocx = ( event ) => {
		event.preventDefault();
		setDraggedOver( false );

		if ( isBusy ) {
			return;
		}

		const file = event.dataTransfer?.files?.[ 0 ] || null;
		if ( file ) {
			setDocxFile( file );
			setDocxName( file.name );
		}
	};

	const togglePostEvents = async () => {
		if ( viewingEvents ) {
			setViewingEvents( false );
			return;
		}

		if ( ! settings.post_id || ! settings.rest_url || ! settings.nonce ) {
			return;
		}

		setViewingEvents( true );
		setPostEventsLoading( true );
		setPostEvents( [] );

		try {
			const response = await apiFetch( {
				url: settings.rest_url.replace(
					/\/import$/,
					`/sync/${ settings.post_id }/events`
				),
				method: 'GET',
				headers: {
					'X-WP-Nonce': settings.nonce,
				},
			} );

			if ( isMountedRef.current ) {
				setPostEvents( response.events || [] );
			}
		} catch ( error ) {
			if ( isMountedRef.current ) {
				setPostEvents( [] );
			}
		} finally {
			if ( isMountedRef.current ) {
				setPostEventsLoading( false );
			}
		}
	};

	const formatRelativeTime = ( ts ) => {
		if ( ! ts ) {
			return '';
		}

		const parsedTs = Math.floor(
			new Date( String( ts ).replace( ' ', 'T' ) ).getTime() / 1000
		);

		if ( Number.isNaN( parsedTs ) || parsedTs <= 0 ) {
			return '';
		}

		const now = Math.floor( Date.now() / 1000 );
		const diff = Math.max( 0, now - parsedTs );
		if ( diff < 60 ) {
			return __( 'just now', 'draftsync' );
		}
		if ( diff < 3600 ) {
			return sprintf(
				/* translators: %d: minutes */
				__( '%d min ago', 'draftsync' ),
				Math.floor( diff / 60 )
			);
		}
		if ( diff < 86400 ) {
			return sprintf(
				/* translators: %d: hours */
				__( '%d hr ago', 'draftsync' ),
				Math.floor( diff / 3600 )
			);
		}
		return sprintf(
			/* translators: %d: days */
			__( '%d days ago', 'draftsync' ),
			Math.floor( diff / 86400 )
		);
	};
	const handleToggleAutoSync = async ( value ) => {
		if ( ! settings.post_id || ! settings.rest_url || ! settings.nonce ) {
			return;
		}

		setAutoSyncEnabled( value );
		try {
			const response = await apiFetch( {
				url: settings.rest_url.replace(
					/\/import$/,
					`/sync/settings/${ settings.post_id }`
				),
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				data: {
					auto_sync: value,
				},
			} );

			if ( isMountedRef.current ) {
				setAutoSyncEnabled( !! response.auto_sync );
				setSyncStatus( ( currentStatus ) => {
					if ( ! currentStatus ) {
						return currentStatus;
					}

					return {
						...currentStatus,
						auto_sync: !! response.auto_sync,
					};
				} );
			}
		} catch ( error ) {
			if ( isMountedRef.current ) {
				setAutoSyncEnabled( ! value );
				setResyncStatus( 'error' );
				setResyncMsg(
					error.message ||
						__(
							'Could not update auto-sync for this post.',
							'draftsync'
						)
				);
			}
		}
	};

	const handleBulkImport = async () => {
		if ( ! activeBulkRows.length ) {
			setErrorMsg(
				__(
					'Add at least one source before starting a bulk import.',
					'draftsync'
				)
			);
			setStatus( 'error' );
			return;
		}

		if ( ! settings.rest_url || ! settings.nonce ) {
			setErrorMsg(
				__(
					'REST API settings are not available. Please reload the editor.',
					'draftsync'
				)
			);
			setStatus( 'error' );
			return;
		}

		setStatus( 'syncing' );
		setErrorMsg( '' );
		setResult( null );
		setBulkResult( null );
		setProgress( null );

		try {
			const response = await apiFetch( {
				url: settings.rest_url.replace( /\/import$/, '/import-bulk' ),
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				data: {
					dry_run: bulkDryRun,
					rows: activeBulkRows.map( ( row ) => {
						const payload = {
							source: row.source.trim(),
							...buildBasePayload(),
						};

						if ( row.post_id.trim() ) {
							payload.post_id = parseInt( row.post_id, 10 ) || 0;
						}

						return payload;
					} ),
				},
			} );

			setStatus( 'success' );
			setBulkResult( response );
			setResult( {
				message: bulkDryRun
					? __( 'Bulk dry run completed.', 'draftsync' )
					: __( 'Bulk import completed.', 'draftsync' ),
			} );
		} catch ( error ) {
			setErrorMsg(
				error.message ||
					__(
						'An unexpected error occurred during bulk import.',
						'draftsync'
					)
			);
			setStatus( 'error' );
		}
	};

	const handleDocxChange = ( event ) => {
		const file = event.target.files?.[ 0 ] || null;
		setDocxFile( file );
		setDocxName( file ? file.name : '' );
	};

	const handleUploadDocx = async () => {
		if ( ! docxFile ) {
			setErrorMsg(
				__( 'Choose a .docx file before uploading.', 'draftsync' )
			);
			setStatus( 'error' );
			return;
		}

		if ( ! settings.rest_url || ! settings.nonce ) {
			setErrorMsg(
				__(
					'REST API settings are not available. Please reload the editor.',
					'draftsync'
				)
			);
			setStatus( 'error' );
			return;
		}

		setStatus( 'syncing' );
		setErrorMsg( '' );
		setResult( null );
		setProgress( null );

		try {
			const formData = new FormData();
			formData.append( 'file', docxFile );
			formData.append( 'post_id', settings.post_id || 0 );
			formData.append( 'import_tables', importTables ? '1' : '0' );
			formData.append( 'overwrite', overwrite ? '1' : '0' );
			formData.append( 'import_as_draft', importAsDraft ? '1' : '0' );
			formData.append( 'output_mode', outputMode );
			formData.append( 'optimize_images', optimizeImages ? '1' : '0' );
			formData.append( 'heading_demotion', headingDemotion );
			formData.append( 'min_heading_level', minHeadingLevel );
			formData.append( 'default_alignment', defaultAlignment );
			const postMeta = buildPostMeta();
			if ( postMeta ) {
				formData.append( 'post_meta', JSON.stringify( postMeta ) );
			}

			const response = await apiFetch( {
				url: settings.rest_url.replace( /\/import$/, '/upload-docx' ),
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
				},
				body: formData,
			} );

			if ( response.success ) {
				if ( response.batch ) {
					setStatus( 'polling' );
					setProgress( {
						image_done: 0,
						image_total: response.image_count || 0,
					} );
					if ( pollingRef.current ) {
						clearTimeout( pollingRef.current );
					}
					pollingRef.current = setTimeout(
						() => pollJob( response.job_id ),
						POLL_INTERVAL
					);
					return;
				}

				setStatus( 'success' );
				setResult( response );
			} else {
				throw new Error(
					response.message || __( 'Sync failed.', 'draftsync' )
				);
			}
		} catch ( error ) {
			setErrorMsg(
				error.message ||
					__( 'An unexpected error occurred.', 'draftsync' )
			);
			setStatus( 'error' );
		}
	};

	const handleImport = async () => {
		if ( ! docUrl ) {
			setErrorMsg(
				__( 'Please enter a Google Doc URL or ID.', 'draftsync' )
			);
			setStatus( 'error' );
			return;
		}

		if ( ! settings.rest_url || ! settings.nonce ) {
			setErrorMsg(
				__(
					'REST API settings are not available. Please reload the editor.',
					'draftsync'
				)
			);
			setStatus( 'error' );
			return;
		}

		setStatus( 'syncing' );
		setErrorMsg( '' );
		setResult( null );
		setProgress( null );

		try {
			const response = await apiFetch( {
				url: settings.rest_url,
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				data: buildBasePayload(),
			} );

			// Success: HTTP 2xx with response body
			if ( response.batch ) {
				// Batch image processing needed — start polling.
				setStatus( 'polling' );
				setProgress( {
					image_done: 0,
					image_total: response.image_count || 0,
				} );
				if ( pollingRef.current ) {
					clearTimeout( pollingRef.current );
				}
				pollingRef.current = setTimeout(
					() => pollJob( response.job_id ),
					POLL_INTERVAL
				);
				return;
			}

			setStatus( 'success' );
			setResult( response );
		} catch ( error ) {
			// Error: HTTP 4xx/5xx - apiFetch throws with error object
			setErrorMsg(
				error.message ||
					__( 'An unexpected error occurred.', 'draftsync' )
			);
			setStatus( 'error' );
		}
	};

	const handleResync = async ( force = false ) => {
		if ( ! settings.post_id ) {
			setResyncMsg(
				__( 'No source post detected for re-sync.', 'draftsync' )
			);
			setResyncStatus( 'error' );
			return;
		}
		if ( ! settings.rest_url || ! settings.nonce ) {
			setResyncMsg(
				__(
					'REST API settings are not available. Please reload the editor.',
					'draftsync'
				)
			);
			setResyncStatus( 'error' );
			return;
		}

		setResyncStatus( 'syncing' );
		setResyncMsg( '' );
		setResyncConflict( false );

		try {
			const response = await apiFetch( {
				url: settings.rest_url.replace(
					/\/import$/,
					`/sync/${ settings.post_id }`
				),
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				data: { force },
			} );
			// Success: HTTP 2xx with response body
			if ( response.batch ) {
				// Batch image processing needed — start polling.
				setResyncStatus( 'polling' );
				if ( pollingRef.current ) {
					clearTimeout( pollingRef.current );
				}
				pollingRef.current = setTimeout(
					() => pollJob( response.job_id ),
					POLL_INTERVAL
				);
				return;
			}

			setResyncStatus( 'success' );
			setResyncMsg(
				response.message ||
					__( 'Re-sync completed. Refreshing editor…', 'draftsync' )
			);
			// Reload the editor so the user sees the updated post content.
			setTimeout( () => {
				if ( isMountedRef.current ) {
					window.location.reload();
				}
			}, 1500 );
		} catch ( error ) {
			// Error: HTTP 4xx/5xx - apiFetch throws with error object
			// error.message contains the backend error message
			// error.data.status contains the HTTP status code
			const statusCode = error.data?.status || 0;

			// Conflict detection: backend returns HTTP 409
			if ( 409 === statusCode ) {
				setResyncConflict( true );
			}

			setResyncStatus( 'error' );
			setResyncMsg(
				error.message ||
					__(
						'An unexpected error occurred during re-sync.',
						'draftsync'
					)
			);
		}
	};

	// ── Google Picker helpers (Phase 2) ──

	const clearPickerSource = () => {
		setPickerSource( null );
		setPickerError( '' );
	};

	const openGooglePicker = async () => {
		if ( isBusy ) {
			return;
		}

		setPickerError( '' );

		// Fetch picker-scoped access token.
		let token;
		try {
			const tokenResp = await apiFetch( {
				url: settings.picker_token_url + '?purpose=picker',
				method: 'GET',
				headers: { 'X-WP-Nonce': settings.nonce },
			} );
			token = tokenResp.token;
		} catch ( err ) {
			setPickerError(
				err.message ||
					__(
						'Could not authenticate with Google. Please reconnect your account.',
						'draftsync'
					)
			);
			return;
		}

		if ( ! token ) {
			setPickerError(
				__(
					'Not connected to Google. Please reconnect your Enterprise account.',
					'draftsync'
				)
			);
			return;
		}

		// Lazy-load the Google API loader.
		if ( typeof gapi === 'undefined' ) {
			try {
				await new Promise( ( resolve, reject ) => {
					const script = document.createElement( 'script' );
					script.src = 'https://apis.google.com/js/api.js';
					script.onload = resolve;
					script.onerror = () =>
						reject(
							new Error( 'Failed to load Google API script.' )
						);
					document.head.appendChild( script );
				} );
			} catch ( err ) {
				// Picker script failed to load — stay silent, button won't work.
				return;
			}
		}

		// Load the Picker library.
		try {
			await new Promise( ( resolve, reject ) => {
				window.gapi.load( 'picker', {
					callback: resolve,
					onerror: () =>
						reject(
							new Error( 'Failed to load Google Picker library.' )
						),
				} );
			} );
		} catch ( err ) {
			return;
		}

		// Build and show the picker.
		const appId = pickerConfig.app_id;
		const developerKey = pickerConfig.developer_key;

		if ( ! appId || ! developerKey ) {
			setPickerError(
				__(
					'Picker is not configured. Please set App ID and Developer Key in DraftSync settings.',
					'draftsync'
				)
			);
			return;
		}

		const view = new window.google.picker.DocsView()
			.setIncludeFolders( false )
			.setMimeTypes(
				'application/vnd.google-apps.document,application/vnd.openxmlformats-officedocument.wordprocessingml.document'
			);

		const picker = new window.google.picker.PickerBuilder()
			.enableFeature( window.google.picker.Feature.NAV_HIDDEN )
			.setAppId( appId )
			.setOAuthToken( token )
			.setDeveloperKey( developerKey )
			.addView( view )
			.setCallback( pickerCallback )
			.build();

		picker.setVisible( true );
	};

	const pickerCallback = ( data ) => {
		if ( data.action === window.google.picker.Action.PICKED ) {
			const doc = data.docs?.[ 0 ];
			if ( ! doc ) {
				return;
			}

			// Validate supported types.
			const mimeType = doc.mimeType || '';
			const isGoogleDoc =
				mimeType === 'application/vnd.google-apps.document';
			const isDocx =
				mimeType ===
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

			if ( ! isGoogleDoc && ! isDocx ) {
				// Try wp.data notices, fallback to inline error.
				try {
					wp.data.dispatch( 'core/notices' ).createNotice( {
						status: 'error',
						content: __(
							'Unsupported file. Choose a Google Doc or .docx.',
							'draftsync'
						),
					} );
				} catch ( e ) {
					setPickerError(
						__(
							'Unsupported file. Choose a Google Doc or .docx.',
							'draftsync'
						)
					);
				}
				return;
			}

			const sourceType = isGoogleDoc ? 'gdoc' : 'drive_file';
			const sourceUrl =
				isGoogleDoc && doc.url
					? doc.url
					: `https://drive.google.com/file/d/${ doc.id }/view`;

			const picked = {
				id: doc.id,
				url: sourceUrl,
				name: doc.name || '',
				mimeType,
				type: sourceType,
			};

			setPickerSource( picked );
			setDocUrl( sourceUrl );
			setImportMode( 'url' );
			setPickerError( '' );
		} else if ( data.action === window.google.picker.Action.CANCEL ) {
			// User cancelled — silent.
		}
	};
	return (
		<PluginSidebar
			name="gdtg-sidebar"
			title={ __( 'Google Docs Sync', 'draftsync' ) }
			icon={ cloudUploadIcon }
		>
			{ /* ── Import Source ── */ }
			<PanelBody
				title={ __( 'Import Source', 'draftsync' ) }
				initialOpen={ true }
			>
				<div className="gdtg-sidebar-flow-section">
					<div data-gdtg-source-type>
						<RadioControl
							label={ __( 'Source Type', 'draftsync' ) }
							selected={ importMode }
							options={ [
								{
									label: __( 'Google Doc URL', 'draftsync' ),
									value: 'url',
								},
								{
									label: __( '.docx Upload', 'draftsync' ),
									value: 'docx',
								},
								{
									label: __( 'Bulk Import', 'draftsync' ),
									value: 'bulk',
								},
							] }
							onChange={ setImportMode }
							disabled={ isBusy }
							className="gdtg-sidebar-mode"
						/>
					</div>
					{ /* URL Import */ }
					{ importMode === 'url' && (
						<div data-gdtg-doc-url>
							<TextControl
								label={ __(
									'Google Doc Link / ID',
									'draftsync'
								) }
								value={ docUrl }
								onChange={ ( val ) => setDocUrl( val ) }
								placeholder="https://docs.google.com/document/d/..."
								disabled={ isBusy }
							/>
							{ /* Phase 2: Google Picker button */ }
							{ settings.connection_mode === 'enterprise' &&
								! pickerConfigLoading &&
								pickerConfig?.enabled && (
									<div className="gdtg-picker-row">
										<Button
											variant="secondary"
											type="button"
											disabled={ isBusy }
											className="gdtg-picker-button"
											onClick={ openGooglePicker }
										>
											{ __(
												'Choose from Google Drive',
												'draftsync'
											) }
										</Button>
									</div>
								) }
							{ /* Picker chip — shows selected file info */ }
							{ pickerSource && (
								<div className="gdtg-picker-chip">
									<span className="gdtg-picker-chip__name">
										{ pickerSource.name ||
											pickerSource.url ||
											pickerSource.id }
									</span>
									<button
										type="button"
										className="gdtg-picker-chip__clear"
										onClick={ clearPickerSource }
										aria-label={ __(
											'Clear picker selection',
											'draftsync'
										) }
									>
										&times;
									</button>
								</div>
							) }
							{ pickerError && (
								<p className="gdtg-sidebar-bulk-error">
									{ pickerError }
								</p>
							) }
						</div>
					) }
					{ importMode === 'docx' && (
						<div
							className={ `gdtg-sidebar-filepicker-card${
								draggedOver ? ' is-drag-over' : ''
							}` }
							data-gdtg-docx-picker
							onDragOver={ ( event ) => {
								event.preventDefault();
								if ( ! isBusy ) {
									setDraggedOver( true );
								}
							} }
							onDragLeave={ () => setDraggedOver( false ) }
							onDrop={ handleDropDocx }
						>
							<input
								id="gdtg-docx-file"
								ref={ fileInputRef }
								type="file"
								accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
								onChange={ handleDocxChange }
								disabled={ isBusy }
								className="gdtg-sidebar-file-input"
								aria-label={ __(
									'Upload .docx file',
									'draftsync'
								) }
								data-gdtg-docx-input
							/>
							<Button
								variant="secondary"
								onClick={ openDocxPicker }
								disabled={ isBusy }
								className="gdtg-sidebar-file-button"
								data-gdtg-docx-trigger
							>
								{ docxName
									? __( 'Change .docx File', 'draftsync' )
									: __( 'Choose .docx File', 'draftsync' ) }
							</Button>
							<p className="gdtg-sidebar-drop-hint">
								{ __(
									'Drag and drop a .docx file here, or click to browse.',
									'draftsync'
								) }
							</p>
							<div
								className="gdtg-sidebar-file-state"
								aria-live="polite"
								data-gdtg-docx-name
							>
								<span className="gdtg-sidebar-file-icon-slot">
									{ documentIcon }
								</span>
								<span className="gdtg-sidebar-file-name">
									{ docxName ||
										__( 'No file selected', 'draftsync' ) }
								</span>
							</div>
						</div>
					) }
					{ importMode === 'bulk' && (
						<div className="gdtg-sidebar-bulk-panel">
							{ bulkRows.map( ( row, index ) => (
								<div
									className="gdtg-sidebar-bulk-row"
									key={ index }
								>
									<TextControl
										label={ sprintf(
											/* translators: %d: row number */
											__( 'Source %d', 'draftsync' ),
											index + 1
										) }
										value={ row.source }
										onChange={ ( value ) =>
											updateBulkRow(
												index,
												'source',
												value
											)
										}
										placeholder="https://docs.google.com/document/d/..."
										disabled={ isBusy }
									/>
									<TextControl
										label={ __(
											'Existing Post ID (optional)',
											'draftsync'
										) }
										value={ row.post_id }
										onChange={ ( value ) =>
											updateBulkRow(
												index,
												'post_id',
												value
											)
										}
										type="number"
										disabled={ isBusy }
									/>
									<Button
										variant="tertiary"
										onClick={ () => removeBulkRow( index ) }
										disabled={ isBusy }
									>
										{ __( 'Remove row', 'draftsync' ) }
									</Button>
								</div>
							) ) }
							<Button
								variant="secondary"
								onClick={ addBulkRow }
								disabled={ isBusy || bulkRows.length >= 100 }
							>
								{ __( 'Add row', 'draftsync' ) }
							</Button>
							<CheckboxControl
								label={ __( 'Dry run only', 'draftsync' ) }
								help={ __(
									'Validate sources and settings without importing posts.',
									'draftsync'
								) }
								checked={ bulkDryRun }
								onChange={ setBulkDryRun }
								disabled={ isBusy }
							/>
						</div>
					) }
					{ /* Output Mode (shared) */ }
					<SelectControl
						label={ __( 'Output Mode', 'draftsync' ) }
						value={ outputMode }
						options={ [
							{
								label: __( 'Gutenberg Blocks', 'draftsync' ),
								value: 'gutenberg',
							},
							{
								label: __( 'Classic HTML', 'draftsync' ),
								value: 'classic',
							},
						] }
						onChange={ ( val ) => setOutputMode( val ) }
						disabled={ isBusy }
					/>
				</div>
				<div className="gdtg-sidebar-action-zone">
					<Button
						variant="primary"
						onClick={ ( () => {
							if ( importMode === 'url' ) {
								return handleImport;
							}
							if ( importMode === 'docx' ) {
								return handleUploadDocx;
							}
							return handleBulkImport;
						} )() }
						disabled={
							isBusy ||
							( importMode === 'url' && ! docUrl.trim() ) ||
							( importMode === 'docx' && ! docxFile ) ||
							( importMode === 'bulk' && ! activeBulkRows.length )
						}
						className="gdtg-sidebar-primary-action"
						data-gdtg-primary-action
						data-gdtg-submit-mode={ importMode }
					>
						{ isBusy ? (
							<>
								<Spinner />
								{ busyLabel }
							</>
						) : (
							submitLabel
						) }
					</Button>
					{ status === 'syncing' && (
						<p
							className="gdtg-sidebar-status-text"
							data-gdtg-status="syncing"
						>
							{ __(
								'Downloading assets and translating content…',
								'draftsync'
							) }
						</p>
					) }
					{ status === 'polling' && progress && (
						<p
							className="gdtg-sidebar-status-text"
							data-gdtg-status="polling"
						>
							{ sprintf(
								/* translators: 1: done count, 2: total count */
								__(
									'Processing images: %1$d of %2$d',
									'draftsync'
								),
								progress.image_done,
								progress.image_total
							) }
						</p>
					) }
					{ status === 'success' && result && (
						<div data-gdtg-result>
							<Notice
								status="success"
								isDismissible
								onDismiss={ () => setStatus( 'idle' ) }
							>
								<p>{ result.message }</p>
								{ result.edit_url && (
									<p>
										<a
											href={ result.edit_url }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ __(
												'Open updated post',
												'draftsync'
											) }
										</a>
									</p>
								) }
								{ bulkResult && bulkResult.summary && (
									<div className="gdtg-sidebar-bulk-results">
										<p className="gdtg-sidebar-source-info">
											{ sprintf(
												/* translators: 1: success count, 2: failed count */
												__(
													'Rows processed: %1$d successful, %2$d failed.',
													'draftsync'
												),
												bulkResult.summary.success || 0,
												bulkResult.summary.failed || 0
											) }
										</p>
										{ bulkResult.results?.map(
											( entry ) => (
												<div
													key={ `${ entry.row }-${ entry.source }` }
												>
													<p className="gdtg-sidebar-source-info">
														{ sprintf(
															/* translators: 1: row number, 2: status message */
															__(
																'Row %1$d: %2$s',
																'draftsync'
															),
															entry.row,
															entry.message
														) }
													</p>
												</div>
											)
										) }
									</div>
								) }
							</Notice>
						</div>
					) }
					{ status === 'error' && (
						<div data-gdtg-error>
							<Notice
								status="error"
								onDismiss={ () => setStatus( 'idle' ) }
							>
								<p>{ errorMsg }</p>
							</Notice>
						</div>
					) }
				</div>
			</PanelBody>
			{ /* ── Import Options ── */ }
			<PanelBody
				title={ __( 'Import Options', 'draftsync' ) }
				initialOpen={ true }
			>
				<div className="gdtg-sidebar-options-group">
					<ToggleControl
						label={ __( 'Import Images', 'draftsync' ) }
						checked={ importImages }
						onChange={ ( val ) => setImportImages( val ) }
						disabled={ isBusy }
					/>
					{ importImages && (
						<ToggleControl
							label={ __( 'Optimize Images', 'draftsync' ) }
							help={ __(
								'Compress and optimize uploaded images dynamically.',
								'draftsync'
							) }
							checked={ optimizeImages }
							onChange={ ( val ) => setOptimizeImages( val ) }
							disabled={ isBusy }
							className="gdtg-nested-toggle"
						/>
					) }
					<ToggleControl
						label={ __( 'Import Tables', 'draftsync' ) }
						checked={ importTables }
						onChange={ ( val ) => setImportTables( val ) }
						disabled={ isBusy }
					/>
					{ ! settings.post_id && (
						<ToggleControl
							label={ __( 'Import as Draft', 'draftsync' ) }
							help={
								importAsDraft
									? __(
											'New post will be saved as draft.',
											'draftsync'
									  )
									: __(
											'New post will be published immediately.',
											'draftsync'
									  )
							}
							checked={ importAsDraft }
							onChange={ ( val ) => setImportAsDraft( val ) }
							disabled={ isBusy }
						/>
					) }
				</div>
				{ settings.post_id && (
					<div className="gdtg-sidebar-destructive-option gdtg-caution-zone">
						<p className="gdtg-caution-zone__label">
							{ __( 'Content policy', 'draftsync' ) }
						</p>
						<ToggleControl
							label={ __(
								'Overwrite Existing Content',
								'draftsync'
							) }
							help={
								overwrite
									? __(
											'Replaces current post content.',
											'draftsync'
									  )
									: __(
											'Appends to current post content.',
											'draftsync'
									  )
							}
							checked={ overwrite }
							onChange={ ( val ) => setOverwrite( val ) }
							disabled={ isBusy }
						/>
					</div>
				) }
			</PanelBody>

			{ /* ── Style Overrides ── */ }
			<PanelBody
				title={ __( 'Style Overrides', 'draftsync' ) }
				initialOpen={ false }
			>
				<RangeControl
					label={ __( 'Heading Demotion', 'draftsync' ) }
					help={ __(
						'Shift heading levels down so H1 becomes H2, etc.',
						'draftsync'
					) }
					value={ headingDemotion }
					onChange={ setHeadingDemotion }
					min={ 0 }
					max={ 5 }
					disabled={ isBusy }
				/>
				<SelectControl
					label={ __( 'Minimum Heading Level', 'draftsync' ) }
					value={ minHeadingLevel }
					onChange={ ( value ) =>
						setMinHeadingLevel( parseInt( value, 10 ) )
					}
					options={ [
						{
							label: __( 'None', 'draftsync' ),
							value: '1',
						},
						{ label: 'H1', value: '1' },
						{ label: 'H2', value: '2' },
						{ label: 'H3', value: '3' },
						{ label: 'H4', value: '4' },
						{ label: 'H5', value: '5' },
						{ label: 'H6', value: '6' },
					] }
					disabled={ isBusy }
				/>
				<SelectControl
					label={ __( 'Default Alignment', 'draftsync' ) }
					value={ defaultAlignment }
					onChange={ setDefaultAlignment }
					options={ [
						{
							label: __( 'Keep original', 'draftsync' ),
							value: '',
						},
						{
							label: __( 'Left', 'draftsync' ),
							value: 'left',
						},
						{
							label: __( 'Center', 'draftsync' ),
							value: 'center',
						},
						{
							label: __( 'Right', 'draftsync' ),
							value: 'right',
						},
					] }
					help={ __(
						'Fallback alignment applied when none is defined.',
						'draftsync'
					) }
					disabled={ isBusy }
				/>
			</PanelBody>

			{ /* ── Publishing Metadata ── */ }
			<PanelBody
				title={ __( 'Publishing Metadata', 'draftsync' ) }
				initialOpen={ false }
			>
				<TextControl
					label={ __( 'Slug', 'draftsync' ) }
					value={ slug }
					onChange={ setSlug }
					disabled={ isBusy }
					autoComplete="off"
				/>
				<TextareaControl
					label={ __( 'Excerpt', 'draftsync' ) }
					value={ meta.excerpt }
					onChange={ ( value ) =>
						setMeta( { ...meta, excerpt: value } )
					}
					disabled={ isBusy }
					rows={ 3 }
				/>
				<TextControl
					label={ __( 'SEO Title', 'draftsync' ) }
					value={ meta.seo_title }
					onChange={ ( value ) =>
						setMeta( { ...meta, seo_title: value } )
					}
					disabled={ isBusy }
					autoComplete="off"
					help={ __( 'Used for Yoast and RankMath.', 'draftsync' ) }
				/>
				<TextControl
					label={ __( 'SEO Description', 'draftsync' ) }
					value={ meta.seo_description }
					onChange={ ( value ) =>
						setMeta( { ...meta, seo_description: value } )
					}
					disabled={ isBusy }
				/>
				<TextControl
					label={ __( 'Focus Keyword', 'draftsync' ) }
					value={ meta.focus_keyword }
					onChange={ ( value ) =>
						setMeta( { ...meta, focus_keyword: value } )
					}
					disabled={ isBusy }
					autoComplete="off"
				/>
				<TextControl
					label={ __( 'Canonical URL', 'draftsync' ) }
					value={ meta.canonical_url }
					onChange={ ( value ) =>
						setMeta( { ...meta, canonical_url: value } )
					}
					disabled={ isBusy }
					autoComplete="url"
				/>
				<TextControl
					label={ __( 'Categories (comma separated)', 'draftsync' ) }
					value={ meta.categories }
					onChange={ ( value ) =>
						setMeta( { ...meta, categories: value } )
					}
					disabled={ isBusy }
				/>
				<TextControl
					label={ __( 'Tags (comma separated)', 'draftsync' ) }
					value={ meta.tags }
					onChange={ ( value ) =>
						setMeta( { ...meta, tags: value } )
					}
					disabled={ isBusy }
				/>
				<TextControl
					label={ __( 'Featured Image Selection', 'draftsync' ) }
					value={ meta.featured_image }
					onChange={ ( value ) =>
						setMeta( { ...meta, featured_image: value } )
					}
					disabled={ isBusy }
					help={ __(
						'Use first, an index (1), or a filename.',
						'draftsync'
					) }
					autoComplete="off"
				/>
			</PanelBody>

			{ settings.post_id && (
				<PanelBody
					title={ __( 'Linked Source Status', 'draftsync' ) }
					initialOpen={ true }
				>
					{ syncStatusLoading && <Spinner /> }
					{ ! syncStatusLoading && syncStatus && (
						<div className="gdtg-sidebar-source-status">
							<p className="gdtg-sidebar-source-info">
								<strong>{ __( 'Source', 'draftsync' ) }</strong>
								: { linkedSourceLabel }
								{ syncStatus.source_name
									? ` — ${ syncStatus.source_name }`
									: '' }
							</p>
							{ syncStatus.last_imported_at && (
								<p className="gdtg-sidebar-source-info">
									<strong>
										{ __( 'Last Imported', 'draftsync' ) }
									</strong>
									:{ ' ' }
									{ formatRelativeTime(
										syncStatus.health
											?.last_imported_at_ts ||
											syncStatus.last_imported_at
									) || syncStatus.last_imported_at }
								</p>
							) }
							{ syncStatus.last_sync_status && (
								<p className="gdtg-sidebar-source-info">
									<strong>
										{ __( 'Status', 'draftsync' ) }
									</strong>
									:{ ' ' }
									<span
										className={
											'gdtg-sidebar-status-badge gdtg-sidebar-status-badge--' +
											syncStatus.last_sync_status
										}
									>
										{ syncStatus.last_sync_status.replace(
											'_',
											' '
										) }
									</span>
								</p>
							) }
							{ syncStatus.last_sync_error && (
								<p className="gdtg-sidebar-bulk-error">
									{ syncStatus.last_sync_error }
								</p>
							) }
							<Button
								variant="tertiary"
								onClick={ togglePostEvents }
								disabled={ postEventsLoading }
								className="gdtg-sidebar-events-toggle"
							>
								{ viewingEvents
									? __( 'Hide history', 'draftsync' )
									: __( 'View history', 'draftsync' ) }
							</Button>
							{ viewingEvents && postEventsLoading && (
								<Spinner />
							) }
							{ viewingEvents &&
								! postEventsLoading &&
								postEvents.length === 0 && (
									<p className="gdtg-sidebar-source-info">
										{ __(
											'No recent events.',
											'draftsync'
										) }
									</p>
								) }
							{ viewingEvents &&
								! postEventsLoading &&
								postEvents.length > 0 && (
									<ul className="gdtg-sidebar-events-list">
										{ postEvents
											.slice( 0, 5 )
											.map( ( ev, idx ) => (
												<li
													key={ idx }
													className="gdtg-sidebar-event-item"
												>
													<span
														className={
															'gdtg-sidebar-event-level gdtg-sidebar-event-level--' +
															( ev.level ||
																'info' )
														}
													>
														{ ev.level || 'info' }
													</span>{ ' ' }
													<span className="gdtg-sidebar-event-message">
														{ ev.message || '' }
													</span>
													{ ev.ts && (
														<span className="gdtg-sidebar-event-time">
															{ formatRelativeTime(
																ev.ts
															) }
														</span>
													) }
												</li>
											) ) }
									</ul>
								) }
							{ syncStatus.syncable && (
								<ToggleControl
									label={ __(
										'Enable Auto-Sync for this post',
										'draftsync'
									) }
									checked={ autoSyncEnabled }
									onChange={ handleToggleAutoSync }
									disabled={ isBusy }
								/>
							) }
						</div>
					) }
				</PanelBody>
			) }
			{ /* ── Linked Re-Sync (existing posts only) ── */ }
			{ settings.post_id &&
				! isBusy &&
				syncStatus?.syncable !== false && (
					<PanelBody
						title={ __( 'Linked Re-Sync', 'draftsync' ) }
						initialOpen={ true }
					>
						<Button
							variant="secondary"
							onClick={ () => handleResync( false ) }
							disabled={ resyncStatus === 'syncing' }
							className="gdtg-sidebar-secondary-action"
						>
							{ resyncStatus === 'syncing'
								? __(
										'Checking linked source\u2026',
										'draftsync'
								  )
								: __( 'Re-sync linked source', 'draftsync' ) }
						</Button>
						{ resyncConflict && (
							<Button
								isDestructive
								className="gdtg-sidebar-resync-force gdtg-sidebar-secondary-action"
								onClick={ () => handleResync( true ) }
							>
								{ __(
									'Force Re-sync and overwrite local edits',
									'draftsync'
								) }
							</Button>
						) }
						{ resyncStatus === 'success' && (
							<Notice status="success" isDismissible={ false }>
								<p>{ resyncMsg }</p>
							</Notice>
						) }
						{ resyncStatus === 'error' && (
							<Notice status="error" isDismissible={ false }>
								<p>{ resyncMsg }</p>
							</Notice>
						) }
					</PanelBody>
				) }
		</PluginSidebar>
	);
}

registerPlugin( 'gdtg-google-docs-sync', {
	render: GDTGSidebar,
	icon: cloudUploadIcon,
} );
