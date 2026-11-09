/**
 * Backups admin page: setup wizard, dashboard, snapshots, settings, environment and logs.
 *
 * Plain JavaScript on core's wp-api-fetch and wp-i18n (ADR 0012). Server data is always
 * inserted as text, never as HTML.
 */
/* global wp */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const config = window.backupsForRestic || {};
	const DOMAIN = 'backups-for-restic';
	const POLL_MS = 3000;

	/**
	 * Creates an element.
	 *
	 * @param {string} tag        Tag name.
	 * @param {Object} attributes Properties; functions starting with "on" become listeners.
	 * @param {Array}  children   Nodes or strings.
	 * @return {HTMLElement} Element.
	 */
	function el( tag, attributes, children ) {
		const node = document.createElement( tag );
		Object.entries( attributes || {} ).forEach( function ( [ name, value ] ) {
			if ( name.startsWith( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( name.slice( 2 ).toLowerCase(), value );
			} else if ( 'dataset' === name ) {
				Object.assign( node.dataset, value );
			} else if ( name in node ) {
				node[ name ] = value;
			} else {
				node.setAttribute( name, value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( null !== child && undefined !== child ) {
				node.append( child instanceof Node ? child : String( child ) );
			}
		} );
		return node;
	}

	function api( path, options ) {
		return wp.apiFetch( Object.assign( { path: '/backups-for-restic/v1' + path }, options || {} ) );
	}

	function errorText( error ) {
		if ( error && error.message ) {
			const hint = error.data && error.data.hint ? ' ' + error.data.hint : '';
			return error.message + hint;
		}
		return __( 'Something went wrong. Please reload the page.', DOMAIN );
	}

	function notice( type, text ) {
		return el( 'div', { className: 'notice inline notice-' + type }, [ el( 'p', {}, [ text ] ) ] );
	}

	function formatBytes( bytes ) {
		if ( 'number' !== typeof bytes ) {
			return '–';
		}
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let value = bytes;
		let unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit++;
		}
		return value.toFixed( unit > 1 ? 1 : 0 ) + ' ' + units[ unit ];
	}

	function formatTime( seconds ) {
		return seconds ? new Date( seconds * 1000 ).toLocaleString() : '–';
	}

	function replace( root, children ) {
		root.replaceChildren( ...children );
	}

	function button( label, onClick, primary ) {
		return el( 'button', { type: 'button', className: 'button' + ( primary ? ' button-primary' : '' ), onClick }, [ label ] );
	}

	function field( label, input, help ) {
		const id = 'bfr-' + Math.random().toString( 36 ).slice( 2 );
		input.id = id;
		return el( 'p', { className: 'bfr-field' }, [
			el( 'label', { htmlFor: id }, [ label ] ),
			input,
			help ? el( 'span', { className: 'description' }, [ help ] ) : null,
		] );
	}

	/* ------------------------------------------------------------------ Jobs */

	function progressLines( job ) {
		const progress = job.progress || {};
		const lines = [ el( 'p', { className: 'bfr-step' }, [ job.step_label ] ) ];
		if ( 'number' === typeof progress.unlock_percent ) {
			lines.push( el( 'progress', { max: 100, value: progress.unlock_percent } ) );
		}
		if ( 'number' === typeof progress.total_files_processed ) {
			lines.push(
				el( 'p', {}, [
					sprintf(
						/* translators: 1: number of files, 2: amount of data such as 1.2 GB. */
						__( '%1$d files, %2$s processed', DOMAIN ),
						progress.total_files_processed,
						formatBytes( progress.total_bytes_processed )
					),
				] )
			);
		}
		if ( 'number' === typeof progress.data_added ) {
			lines.push(
				el( 'p', {}, [
					/* translators: %s: amount of data such as 15 MB. */
					sprintf( __( '%s of new data uploaded', DOMAIN ), formatBytes( progress.data_added ) ),
				] )
			);
		}
		if ( progress.current ) {
			lines.push( el( 'p', { className: 'bfr-current' }, [ progress.current ] ) );
		}
		return lines;
	}

	function jobOutcome( job ) {
		if ( 'failed' === job.state && job.error ) {
			return notice( 'error', job.error.message + ' ' + job.error.hint );
		}
		if ( 'succeeded' === job.state ) {
			const warnings = ( job.warnings || [] ).length;
			return notice(
				warnings ? 'warning' : 'success',
				warnings
					? /* translators: %d: number of warnings. */ sprintf( __( 'Finished with %d warnings; see the log.', DOMAIN ), warnings )
					: __( 'Finished successfully.', DOMAIN )
			);
		}
		return notice( 'info', job.state_label );
	}

	/**
	 * Follows a job until it ends. Each request also advances the job a little, so an open tab
	 * speeds up slow hosts.
	 *
	 * @param {string}   id       Job ID.
	 * @param {Element}  target   Where to show progress.
	 * @param {Function} finished Called with the final job.
	 */
	function follow( id, target, finished ) {
		const cancel = button( __( 'Cancel', DOMAIN ), function () {
			cancel.disabled = true;
			api( '/jobs/' + id + '/cancel', { method: 'POST' } ).catch( function () {} );
		} );
		function poll() {
			api( '/jobs/' + id )
				.then( function ( response ) {
					const job = response.job;
					if ( [ 'queued', 'running' ].includes( job.state ) ) {
						replace( target, [
							...progressLines( job ),
							el( 'p', { className: 'description' }, [ __( 'Keep this tab open to speed up the backup on slow hosts.', DOMAIN ) ] ),
							cancel,
						] );
						window.setTimeout( poll, POLL_MS );
					} else {
						replace( target, [ jobOutcome( job ) ] );
						finished( job );
					}
				} )
				.catch( function ( error ) {
					replace( target, [ notice( 'error', errorText( error ) ) ] );
					window.setTimeout( poll, POLL_MS * 3 );
				} );
		}
		poll();
	}

	function jobRow( job, onSelect ) {
		const name = onSelect
			? el( 'a', { href: '#', onClick: function ( event ) { event.preventDefault(); onSelect( job ); } }, [ formatTime( job.created_at ) ] )
			: formatTime( job.created_at );
		return el( 'tr', {}, [
			el( 'td', {}, [ name ] ),
			el( 'td', {}, [ job.type ] ),
			el( 'td', {}, [ job.state_label ] ),
			el( 'td', {}, [ job.error ? job.error.message : '' ] ),
		] );
	}

	function jobTable( jobs, onSelect ) {
		return el( 'table', { className: 'widefat striped' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', {}, [ __( 'Started', DOMAIN ) ] ),
					el( 'th', {}, [ __( 'Type', DOMAIN ) ] ),
					el( 'th', {}, [ __( 'Result', DOMAIN ) ] ),
					el( 'th', {}, [ __( 'Message', DOMAIN ) ] ),
				] ),
			] ),
			el( 'tbody', {}, jobs.map( function ( job ) { return jobRow( job, onSelect ); } ) ),
		] );
	}

	/* ------------------------------------------------------------- Dashboard */

	function dashboard( root ) {
		api( '/status' )
			.then( function ( status ) {
				const last = status.last_backup || {};
				const running = el( 'div', { className: 'bfr-card bfr-running' } );
				const start = button( __( 'Back up now', DOMAIN ), function () {
					start.disabled = true;
					api( '/jobs', { method: 'POST' } )
						.then( function ( response ) {
							follow( response.job.id, running, function () { dashboard( root ); } );
						} )
						.catch( function ( error ) {
							start.disabled = false;
							replace( running, [ notice( 'error', errorText( error ) ) ] );
						} );
				}, true );
				const appendOnly = {
					yes: __( 'Append-only: the site cannot delete backups.', DOMAIN ),
					no: __( 'Deletions allowed. Recommended: turn on append-only on your server, so that nobody who takes over this site can delete your backups.', DOMAIN ),
					unknown: __( 'Append-only status unknown.', DOMAIN ),
				};
				replace( root, [
					el( 'div', { className: 'bfr-cards' }, [
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Last backup', DOMAIN ) ] ),
							el( 'p', { className: 'bfr-big' }, [ last.finished ? formatTime( last.finished ) : __( 'None yet', DOMAIN ) ] ),
							last.snapshot ? el( 'p', {}, [ /* translators: %s: snapshot ID. */ sprintf( __( 'Snapshot %s', DOMAIN ), String( last.snapshot ).slice( 0, 8 ) ) ] ) : null,
						] ),
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Next backup', DOMAIN ) ] ),
							el( 'p', { className: 'bfr-big' }, [ status.next_backup ? formatTime( status.next_backup ) : __( 'Manual only', DOMAIN ) ] ),
							start,
						] ),
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Repository', DOMAIN ) ] ),
							el( 'p', {}, [ status.repository.host ] ),
							el( 'p', { className: 'description' }, [ appendOnly[ status.repository.append_only ] || '' ] ),
						] ),
					] ),
					running,
					el( 'h2', {}, [ __( 'Recent jobs', DOMAIN ) ] ),
					jobTable( status.recent_jobs ),
				] );
				if ( status.active_job ) {
					start.disabled = true;
					follow( status.active_job.id, running, function () { dashboard( root ); } );
				}
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	/* ------------------------------------------------------------- Snapshots */

	function snapshots( root ) {
		api( '/snapshots' )
			.then( function ( response ) {
				if ( ! response.snapshots.length ) {
					replace( root, [ notice( 'info', __( 'No snapshots yet.', DOMAIN ) ) ] );
					return;
				}
				replace( root, [
					el( 'table', { className: 'widefat striped' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', {}, [ __( 'Time', DOMAIN ) ] ),
								el( 'th', {}, [ __( 'Snapshot', DOMAIN ) ] ),
								el( 'th', {}, [ __( 'Host', DOMAIN ) ] ),
								el( 'th', {}, [ __( 'Files', DOMAIN ) ] ),
								el( 'th', {}, [ __( 'Size', DOMAIN ) ] ),
								el( 'th', {}, [ __( 'New data', DOMAIN ) ] ),
							] ),
						] ),
						el(
							'tbody',
							{},
							response.snapshots.map( function ( snapshot ) {
								return el( 'tr', {}, [
									el( 'td', {}, [ new Date( String( snapshot.time ).replace( /(\.\d{3})\d+/, '$1' ) ).toLocaleString() ] ),
									el( 'td', {}, [ el( 'code', {}, [ snapshot.short_id ] ) ] ),
									el( 'td', {}, [ snapshot.hostname ] ),
									el( 'td', {}, [ null === snapshot.files ? '–' : String( snapshot.files ) ] ),
									el( 'td', {}, [ formatBytes( snapshot.bytes ) ] ),
									el( 'td', {}, [ formatBytes( snapshot.data_added ) ] ),
								] );
							} )
						),
					] ),
					el( 'p', { className: 'description' }, [ __( 'Restoring from the admin area arrives in a later version. Until then, the recovery kit explains how to restore with the restic command line.', DOMAIN ) ] ),
				] );
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	/* -------------------------------------------------------------- Settings */

	const SETTING_GROUPS = [
		{
			title: __( 'Schedule', DOMAIN ),
			fields: [
				[ 'schedule.backup.frequency', __( 'Back up', DOMAIN ), 'choice', { manual: __( 'Only manually', DOMAIN ), twice_daily: __( 'Twice a day', DOMAIN ), daily: __( 'Daily', DOMAIN ), weekly: __( 'Weekly', DOMAIN ) } ],
				[ 'schedule.backup.hour', __( 'Hour', DOMAIN ), 'number' ],
				[ 'schedule.backup.minute', __( 'Minute', DOMAIN ), 'number' ],
				[ 'schedule.backup.weekday', __( 'Weekday for weekly backups (0 = Sunday)', DOMAIN ), 'number' ],
			],
		},
		{
			title: __( 'What to back up', DOMAIN ),
			fields: [
				[ 'backup.database.enabled', __( 'Back up the database', DOMAIN ), 'boolean' ],
				[ 'backup.database.table_scope', __( 'Tables', DOMAIN ), 'choice', { prefix: __( 'Tables of this site (with its prefix)', DOMAIN ), all: __( 'All tables of the database', DOMAIN ) } ],
				[ 'backup.database.exclude_tables', __( 'Tables to leave out, one per line', DOMAIN ), 'list' ],
				[ 'backup.extra_roots', __( 'Additional directories, one absolute path per line', DOMAIN ), 'list' ],
				[ 'backup.excludes', __( 'Exclude patterns, one per line', DOMAIN ), 'list' ],
				[ 'backup.exclude_caches', __( 'Skip directories marked as caches', DOMAIN ), 'boolean' ],
			],
		},
		{
			title: __( 'Advanced', DOMAIN ),
			fields: [
				[ 'advanced.tick_budget_seconds', __( 'Seconds per work step', DOMAIN ), 'number' ],
				[ 'advanced.pack_size_mib', __( 'Pack size in MiB', DOMAIN ), 'number' ],
				[ 'advanced.upload_timeout_seconds', __( 'Upload timeout in seconds', DOMAIN ), 'number' ],
				[ 'advanced.loopback_chaining', __( 'Continue jobs with loopback requests', DOMAIN ), 'choice', { auto: __( 'Yes', DOMAIN ), off: __( 'No', DOMAIN ) } ],
				[ 'advanced.keep_jobs', __( 'Jobs to keep in the log', DOMAIN ), 'number' ],
			],
		},
	];

	function input( kind, value, choices ) {
		if ( 'boolean' === kind ) {
			return el( 'input', { type: 'checkbox', checked: !! value } );
		}
		if ( 'choice' === kind ) {
			return el(
				'select',
				{},
				Object.entries( choices ).map( function ( [ key, label ] ) {
					return el( 'option', { value: key, selected: key === value }, [ label ] );
				} )
			);
		}
		if ( 'list' === kind ) {
			return el( 'textarea', { rows: 3, className: 'large-text code', value: ( value || [] ).join( '\n' ) } );
		}
		return el( 'input', { type: 'number', className: 'small-text', value: String( value ) } );
	}

	function settings( root ) {
		api( '/settings' )
			.then( function ( response ) {
				const inputs = {};
				const message = el( 'div' );
				const groups = SETTING_GROUPS.map( function ( group ) {
					return el( 'fieldset', { className: 'bfr-group' }, [
						el( 'legend', {}, [ group.title ] ),
						...group.fields.map( function ( [ path, label, kind, choices ] ) {
							inputs[ path ] = { kind, node: input( kind, response.values[ path ], choices ) };
							inputs[ path ].error = el( 'span', { className: 'bfr-error' } );
							const row = field( label, inputs[ path ].node );
							row.append( inputs[ path ].error );
							return row;
						} ),
					] );
				} );
				const save = button( __( 'Save settings', DOMAIN ), function () {
					const values = {};
					Object.entries( inputs ).forEach( function ( [ path, entry ] ) {
						entry.error.textContent = '';
						values[ path ] = 'boolean' === entry.kind ? entry.node.checked : entry.node.value;
					} );
					save.disabled = true;
					api( '/settings', { method: 'PUT', data: { values } } )
						.then( function () {
							replace( message, [ notice( 'success', __( 'Settings saved.', DOMAIN ) ) ] );
						} )
						.catch( function ( error ) {
							replace( message, [ notice( 'error', errorText( error ) ) ] );
							Object.entries( ( error.data && error.data.fields ) || {} ).forEach( function ( [ path, text ] ) {
								if ( inputs[ path ] ) {
									inputs[ path ].error.textContent = text;
								}
							} );
						} )
						.finally( function () {
							save.disabled = false;
						} );
				}, true );
				replace( root, [
					...groups,
					el( 'fieldset', { className: 'bfr-group' }, [
						el( 'legend', {}, [ __( 'Repository', DOMAIN ) ] ),
						el( 'p', {}, [ response.values[ 'repository.url' ] ] ),
						el( 'p', { className: 'description' }, [
							/* translators: %s: directory path. */
							sprintf( __( 'Working directory: %s', DOMAIN ), response.private_dir ),
						] ),
						recoveryKitForm(),
					] ),
					message,
					save,
				] );
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	function kitView( kit ) {
		const text = el( 'textarea', { className: 'large-text code', rows: 14, readOnly: true, value: kit } );
		const download = button( __( 'Download', DOMAIN ), function () {
			const url = URL.createObjectURL( new Blob( [ kit ], { type: 'text/plain' } ) );
			const link = el( 'a', { href: url, download: 'backup-recovery-kit.txt' } );
			document.body.append( link );
			link.click();
			link.remove();
			URL.revokeObjectURL( url );
		} );
		const copy = button( __( 'Copy', DOMAIN ), function () {
			window.navigator.clipboard.writeText( kit ).catch( function () {
				text.select();
			} );
		} );
		return el( 'div', { className: 'bfr-kit' }, [ text, el( 'p', {}, [ download, ' ', copy ] ) ] );
	}

	function recoveryKitForm() {
		const box = el( 'div' );
		const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password' } );
		const show = button( __( 'Show recovery kit', DOMAIN ), function () {
			show.disabled = true;
			api( '/recovery-kit', { method: 'POST', data: { user_password: password.value } } )
				.then( function ( response ) {
					replace( box, [ kitView( response.recovery_kit ) ] );
				} )
				.catch( function ( error ) {
					show.disabled = false;
					replace( box, [ notice( 'error', errorText( error ) ), field( __( 'Your WordPress password', DOMAIN ), password ), show ] );
				} );
		} );
		replace( box, [ field( __( 'Your WordPress password', DOMAIN ), password, __( 'Needed to show the repository password.', DOMAIN ) ), show ] );
		return box;
	}

	/* ----------------------------------------------------------- Environment */

	function checkList( checks ) {
		return el(
			'ul',
			{ className: 'bfr-checks' },
			checks.map( function ( check ) {
				return el( 'li', { className: 'bfr-check bfr-' + check.status }, [
					el( 'strong', {}, [ check.label ] ),
					check.description ? el( 'span', {}, [ ' ', check.description ] ) : null,
				] );
			} )
		);
	}

	function environment( root ) {
		api( '/environment?fresh=1' )
			.then( function ( response ) {
				replace( root, [ checkList( response.checks ), button( __( 'Check again', DOMAIN ), function () { environment( root ); } ) ] );
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	/* ------------------------------------------------------------------ Logs */

	function logs( root ) {
		const detail = el( 'div' );
		api( '/jobs' )
			.then( function ( response ) {
				replace( root, [
					jobTable( response.jobs, function ( job ) {
						api( '/jobs/' + job.id + '/log' ).then( function ( log ) {
							replace( detail, [
								el( 'h2', {}, [ formatTime( job.created_at ) + ' – ' + job.state_label ] ),
								el(
									'table',
									{ className: 'widefat striped bfr-log' },
									log.entries.map( function ( entry ) {
										return el( 'tr', {}, [
											el( 'td', {}, [ formatTime( entry.time ) ] ),
											el( 'td', {}, [ entry.level ] ),
											el( 'td', {}, [ entry.message, entry.context && Object.keys( entry.context ).length ? ' ' + JSON.stringify( entry.context ) : '' ] ),
										] );
									} )
								),
								( log.job.warnings || [] ).length ? el( 'ul', {}, log.job.warnings.map( function ( warning ) { return el( 'li', {}, [ warning ] ); } ) ) : null,
							] );
						} );
					} ),
					detail,
				] );
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	/* ---------------------------------------------------------------- Wizard */

	function setup( root ) {
		const state = { server: 'borgbase', report: null };
		const steps = el( 'ol', { className: 'bfr-steps' } );
		const content = el( 'div', { className: 'bfr-step-content' } );
		const titles = [ __( 'Environment', DOMAIN ), __( 'Repository server', DOMAIN ), __( 'Password', DOMAIN ), __( 'Recovery kit', DOMAIN ), __( 'Schedule', DOMAIN ), __( 'First backup', DOMAIN ) ];

		function show( index, nodes ) {
			replace( steps, titles.map( function ( title, position ) {
				return el( 'li', { className: position === index ? 'bfr-current-step' : '' }, [ title ] );
			} ) );
			replace( content, nodes );
		}

		function stepEnvironment() {
			show( 0, [ el( 'p', {}, [ __( 'Checking this server…', DOMAIN ) ] ) ] );
			api( '/environment?fresh=1' )
				.then( function ( response ) {
					const blocked = response.checks.some( function ( check ) { return 'critical' === check.status; } );
					show( 0, [
						checkList( response.checks ),
						blocked
							? notice( 'error', __( 'Please fix the problems marked in red, then check again.', DOMAIN ) )
							: button( __( 'Continue', DOMAIN ), stepServer, true ),
						' ',
						button( __( 'Check again', DOMAIN ), stepEnvironment ),
					] );
				} )
				.catch( function ( error ) {
					show( 0, [ notice( 'error', errorText( error ) ) ] );
				} );
		}

		function stepServer() {
			const borgbase = el( 'input', { type: 'radio', name: 'bfr-server', value: 'borgbase', checked: 'borgbase' === state.server } );
			const own = el( 'input', { type: 'radio', name: 'bfr-server', value: 'rest-server', checked: 'rest-server' === state.server } );
			const url = el( 'input', { type: 'url', className: 'regular-text', placeholder: 'https://xxxxxxxx.repo.borgbase.com' } );
			const username = el( 'input', { type: 'text', className: 'regular-text', autocomplete: 'off' } );
			const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'new-password' } );
			const ca = el( 'textarea', { className: 'large-text code', rows: 4, placeholder: '-----BEGIN CERTIFICATE-----' } );
			const caRow = field( __( 'CA certificate for a self-signed server (optional, PEM)', DOMAIN ), ca );
			const result = el( 'div' );
			function sync() {
				state.server = own.checked ? 'rest-server' : 'borgbase';
				caRow.hidden = ! own.checked;
				url.placeholder = own.checked ? 'https://backup.example.net:8000/site/' : 'https://xxxxxxxx.repo.borgbase.com';
			}
			borgbase.addEventListener( 'change', sync );
			own.addEventListener( 'change', sync );
			sync();
			const data = function () {
				return { server: state.server, url: url.value, username: username.value, password: password.value };
			};
			const save = button( __( 'Save and continue', DOMAIN ), function () {
				save.disabled = true;
				const upload = own.checked && ca.value.trim() ? api( '/repository/ca-certificate', { method: 'POST', data: { pem: ca.value } } ) : Promise.resolve();
				upload
					.then( function () { return api( '/repository/save', { method: 'POST', data: data() } ); } )
					.then( function ( response ) {
						state.report = response.report;
						stepPassword();
					} )
					.catch( function ( error ) {
						save.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			const test = button( __( 'Test connection', DOMAIN ), function () {
				test.disabled = true;
				api( '/repository/test', { method: 'POST', data: data() } )
					.then( function ( response ) {
						const report = response.report;
						const parts = [ report.exists ? __( 'Connected. A repository exists at this address.', DOMAIN ) : __( 'Connected. The address is empty; a new repository will be created.', DOMAIN ) ];
						if ( 'append_only' === report.append_only ) {
							parts.push( __( 'The repository is append-only: this site will never be able to delete backups.', DOMAIN ) );
						} else if ( 'deletions_allowed' === report.append_only ) {
							parts.push( __( 'Recommended: turn on append-only on your server (BorgBase: repository settings; rest-server: --append-only). Then nobody who takes over this website can delete your backups.', DOMAIN ) );
						}
						replace( result, [ notice( 'success', parts.join( ' ' ) ), save ] );
					} )
					.catch( function ( error ) {
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} )
					.finally( function () {
						test.disabled = false;
					} );
			}, true );
			show( 1, [
				el( 'fieldset', {}, [
					el( 'legend', { className: 'screen-reader-text' }, [ __( 'Server', DOMAIN ) ] ),
					el( 'label', {}, [ borgbase, ' ', __( 'BorgBase', DOMAIN ) ] ),
					el( 'br' ),
					el( 'label', {}, [ own, ' ', __( 'Own restic REST server', DOMAIN ) ] ),
				] ),
				field( __( 'Repository URL', DOMAIN ), url ),
				field( __( 'REST username', DOMAIN ), username ),
				field( __( 'REST password', DOMAIN ), password ),
				caRow,
				test,
				result,
			] );
		}

		function stepPassword() {
			const result = el( 'div' );
			if ( state.report && state.report.exists ) {
				const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'off' } );
				const connect = button( __( 'Connect', DOMAIN ), function () {
					connect.disabled = true;
					api( '/repository/connect', { method: 'POST', data: { password: password.value } } )
						.then( function ( response ) {
							follow( response.job.id, result, function ( job ) {
								if ( 'succeeded' === job.state ) {
									stepKit( response.recovery_kit );
								} else {
									connect.disabled = false;
								}
							} );
						} )
						.catch( function ( error ) {
							connect.disabled = false;
							replace( result, [ notice( 'error', errorText( error ) ) ] );
						} );
				}, true );
				show( 2, [
					el( 'p', {}, [ __( 'Enter the password of the existing repository. Unlocking can take a few minutes on slow hosts; it runs in the background.', DOMAIN ) ] ),
					field( __( 'Repository password', DOMAIN ), password ),
					connect,
					result,
				] );
				return;
			}
			const create = button( __( 'Create repository', DOMAIN ), function () {
				create.disabled = true;
				replace( result, [ el( 'p', {}, [ __( 'Creating the repository…', DOMAIN ) ] ) ] );
				api( '/repository/create', { method: 'POST' } )
					.then( function ( response ) {
						stepKit( response.recovery_kit );
					} )
					.catch( function ( error ) {
						create.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			show( 2, [
				el( 'p', {}, [ __( 'The plugin creates a new, encrypted repository with a random 32-character password. Nobody can read your backups without it, not even the storage provider.', DOMAIN ) ] ),
				create,
				result,
			] );
		}

		function stepKit( kit ) {
			const confirm = el( 'input', { type: 'checkbox' } );
			const result = el( 'div' );
			const next = button( __( 'Continue', DOMAIN ), function () {
				next.disabled = true;
				api( '/setup/complete', { method: 'POST', data: { recovery_kit_acknowledged: confirm.checked } } )
					.then( stepSchedule )
					.catch( function ( error ) {
						next.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			show( 3, [
				el( 'p', {}, [ __( 'Store this recovery kit outside this website, for example in a password manager. Without it, nobody can restore your backups if the site is lost.', DOMAIN ) ] ),
				kitView( kit ),
				el( 'p', {}, [ el( 'label', {}, [ confirm, ' ', __( 'I have stored the recovery kit outside this server.', DOMAIN ) ] ) ] ),
				next,
				result,
			] );
		}

		function stepSchedule() {
			const frequency = input( 'choice', 'daily', { manual: __( 'Only manually', DOMAIN ), twice_daily: __( 'Twice a day', DOMAIN ), daily: __( 'Daily', DOMAIN ), weekly: __( 'Weekly', DOMAIN ) } );
			const hour = el( 'input', { type: 'number', className: 'small-text', min: 0, max: 23, value: String( Math.floor( Math.random() * 5 ) + 1 ) } );
			const minute = el( 'input', { type: 'number', className: 'small-text', min: 0, max: 59, value: String( Math.floor( Math.random() * 60 ) ) } );
			const result = el( 'div' );
			const next = button( __( 'Save and run the first backup', DOMAIN ), function () {
				next.disabled = true;
				api( '/settings', { method: 'PUT', data: { values: { 'schedule.backup.frequency': frequency.value, 'schedule.backup.hour': hour.value, 'schedule.backup.minute': minute.value } } } )
					.then( stepFirstBackup )
					.catch( function ( error ) {
						next.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			show( 4, [ field( __( 'Back up', DOMAIN ), frequency ), field( __( 'Hour', DOMAIN ), hour ), field( __( 'Minute', DOMAIN ), minute ), next, result ] );
		}

		function stepFirstBackup() {
			const progress = el( 'div' );
			show( 5, [ el( 'p', {}, [ __( 'The first backup uploads everything once; later backups only send changes.', DOMAIN ) ] ), progress ] );
			api( '/jobs', { method: 'POST' } )
				.then( function ( response ) {
					follow( response.job.id, progress, function () {
						progress.append( el( 'p', {}, [ el( 'a', { className: 'button button-primary', href: config.pageUrl }, [ __( 'Go to the dashboard', DOMAIN ) ] ) ] ) );
					} );
				} )
				.catch( function ( error ) {
					replace( progress, [ notice( 'error', errorText( error ) ) ] );
				} );
		}

		replace( root, [ el( 'h2', {}, [ __( 'Set up backups', DOMAIN ) ] ), steps, content ] );
		stepEnvironment();
	}

	const views = { dashboard, snapshots, settings, environment, logs, setup };

	wp.domReady( function () {
		const root = document.getElementById( 'backups-for-restic-app' );
		if ( root && views[ root.dataset.view ] ) {
			views[ root.dataset.view ]( root );
		}
	} );
}() );
