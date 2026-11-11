/**
 * Backups admin page: setup wizard, dashboard, snapshots, settings, environment and logs.
 *
 * Plain JavaScript on core's wp-api-fetch and wp-i18n (ADR 0012). Server data is always
 * inserted as text, never as HTML.
 */
( function () {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const config = window.backupsForRestic || {};
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
		return __( 'Something went wrong. Please reload the page.', 'backups-for-restic' );
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

	function field( label, control, help ) {
		const id = 'bfr-' + Math.random().toString( 36 ).slice( 2 );
		control.id = id;
		return el( 'p', { className: 'bfr-field' }, [
			el( 'label', { htmlFor: id }, [ label ] ),
			control,
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
						__( '%1$d files, %2$s processed', 'backups-for-restic' ),
						progress.total_files_processed,
						formatBytes( progress.total_bytes_processed ),
					),
				] ),
			);
		}
		if ( 'number' === typeof progress.data_added ) {
			lines.push(
				el( 'p', {}, [
					/* translators: %s: amount of data such as 15 MB. */
					sprintf( __( '%s of new data uploaded', 'backups-for-restic' ), formatBytes( progress.data_added ) ),
				] ),
			);
		}
		if ( 'number' === typeof progress.packs_to_read && progress.packs_to_read > 0 ) {
			lines.push( el( 'progress', { max: progress.packs_to_read, value: progress.packs_read || 0 } ) );
		} else if ( 'number' === typeof progress.trees && progress.trees > 0 ) {
			lines.push(
				el( 'p', {}, [
					/* translators: %d: number of directory listings. */
					sprintf( __( '%d directory listings read', 'backups-for-restic' ), progress.trees ),
				] ),
			);
		}
		if ( 'number' === typeof progress.bytes_deleted && progress.bytes_deleted > 0 ) {
			lines.push(
				el( 'p', {}, [
					/* translators: %s: amount of data such as 15 MB. */
					sprintf( __( '%s deleted', 'backups-for-restic' ), formatBytes( progress.bytes_deleted ) ),
				] ),
			);
		}
		if ( progress.current ) {
			lines.push( el( 'p', { className: 'bfr-current' }, [ progress.current ] ) );
		}
		return lines;
	}

	function jobOutcome( job ) {
		const result = job.result || {};
		if ( 'failed' === job.state && job.error ) {
			return notice( 'error', job.error.message + ' ' + job.error.hint );
		}
		if ( 'succeeded' === job.state && 'append_only' === result.skipped ) {
			return notice( 'warning', __( 'The server is append-only, so nothing was deleted. This site no longer removes old snapshots; remove them from another computer.', 'backups-for-restic' ) );
		}
		if ( 'succeeded' === job.state && 'retention_external' === result.skipped ) {
			return notice( 'info', __( 'Old snapshots are removed from another computer, so nothing was deleted.', 'backups-for-restic' ) );
		}
		if ( 'succeeded' === job.state && 'check' === job.type && result.errors > 0 ) {
			/* translators: %d: number of errors. */
			return notice( 'error', sprintf( _n( 'The check found %d error.', 'The check found %d errors.', result.errors, 'backups-for-restic' ), result.errors ) );
		}
		if ( 'succeeded' === job.state && 'prune' === job.type ) {
			/* translators: %s: amount of data such as 15 MB. */
			return notice( 'success', sprintf( __( 'Deleted %s of data that no snapshot uses.', 'backups-for-restic' ), formatBytes( result.bytes_deleted || 0 ) ) );
		}
		if ( 'succeeded' === job.state && 'forget' === job.type ) {
			/* translators: %d: number of snapshots. */
			return notice( 'success', sprintf( _n( 'Removed %d old snapshot.', 'Removed %d old snapshots.', result.removed || 0, 'backups-for-restic' ), result.removed || 0 ) );
		}
		if ( 'succeeded' === job.state ) {
			const warnings = ( job.warnings || [] ).length;
			return notice(
				warnings ? 'warning' : 'success',
				warnings
					? /* translators: %d: number of warnings. */ sprintf( __( 'Finished with %d warnings; see the log.', 'backups-for-restic' ), warnings )
					: __( 'Finished successfully.', 'backups-for-restic' ),
			);
		}
		return notice( 'info', job.state_label );
	}

	/**
	 * Follows a job until it ends. Each request also advances the job a little, so an open tab
	 * speeds up slow hosts.
	 *
	 * @param {string}                id       Job ID.
	 * @param {Element}               target   Where to show progress.
	 * @param {(job: Object) => void} finished Called with the final job.
	 */
	function follow( id, target, finished ) {
		const cancel = button( __( 'Cancel', 'backups-for-restic' ), function () {
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
							el( 'p', { className: 'description' }, [ __( 'Keep this tab open to speed up the backup on slow hosts.', 'backups-for-restic' ) ] ),
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
		const select = function ( event ) {
			event.preventDefault();
			onSelect( job );
		};
		const name = onSelect
			? el( 'a', { href: '#', onClick: select }, [ formatTime( job.created_at ) ] )
			: formatTime( job.created_at );
		return el( 'tr', {}, [
			el( 'td', {}, [ name ] ),
			el( 'td', {}, [ job.type_label ] ),
			el( 'td', {}, [ job.state_label ] ),
			el( 'td', {}, [ job.error ? job.error.message : '' ] ),
		] );
	}

	function jobTable( jobs, onSelect ) {
		return el( 'table', { className: 'widefat striped' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', {}, [ __( 'Started', 'backups-for-restic' ) ] ),
					el( 'th', {}, [ __( 'Type', 'backups-for-restic' ) ] ),
					el( 'th', {}, [ __( 'Result', 'backups-for-restic' ) ] ),
					el( 'th', {}, [ __( 'Message', 'backups-for-restic' ) ] ),
				] ),
			] ),
			el( 'tbody', {}, jobs.map( function ( job ) {
				return jobRow( job, onSelect );
			} ) ),
		] );
	}

	/* ------------------------------------------------------------- Dashboard */

	/**
	 * Notices that stay until their cause is gone: check findings and retention changes.
	 *
	 * @param {Object}     status Dashboard data.
	 * @param {() => void} reload Shows the dashboard again.
	 * @return {Array} Nodes.
	 */
	function alerts( status, reload ) {
		const nodes = [];
		const alert = status.check_alert;
		if ( alert ) {
			nodes.push(
				el( 'div', { className: 'notice notice-error inline bfr-alert' }, [
					el( 'p', {}, [
						el( 'strong', {}, [
							sprintf(
								/* translators: 1: number of errors, 2: date and time. */
								_n( 'The repository check on %2$s found %1$d error.', 'The repository check on %2$s found %1$d errors.', alert.errors, 'backups-for-restic' ),
								alert.errors,
								formatTime( alert.at ),
							),
						] ),
					] ),
					el( 'p', {}, [ __( 'Some snapshots may not restore completely. Do not delete anything. Run "restic check" from another computer; the disaster-recovery guide explains how to repair the repository. This warning stays until a check finds no errors.', 'backups-for-restic' ) ] ),
					el(
						'ul',
						{ className: 'bfr-findings' },
						alert.findings.map( function ( finding ) {
							return el( 'li', {}, [ finding.label, ' ', el( 'code', {}, [ finding.object ] ) ] );
						} ),
					),
				] ),
			);
		}
		const retention = status.retention;
		if ( 'external' === retention.mode && retention.proposed_since ) {
			const message = el( 'div' );
			const switchBack = button( __( 'Let this site remove old snapshots again', 'backups-for-restic' ), function () {
				switchBack.disabled = true;
				api( '/settings', { method: 'PUT', data: { values: { 'retention.mode': 'plugin' } } } )
					.then( reload )
					.catch( function ( error ) {
						switchBack.disabled = false;
						replace( message, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			nodes.push(
				el( 'div', { className: 'notice notice-info inline' }, [
					el( 'p', {}, [
						/* translators: %s: date and time. */
						sprintf( __( 'Since %s the server allows deletions again. Until you switch back, old snapshots must still be removed from another computer.', 'backups-for-restic' ), formatTime( retention.proposed_since ) ),
					] ),
					el( 'p', {}, [ switchBack ] ),
					message,
				] ),
			);
		} else if ( 'external' === retention.mode && retention.switched_at ) {
			nodes.push(
				notice(
					'warning',
					/* translators: %s: date and time. */
					sprintf( __( 'On %s the server refused to delete data: it is append-only, which protects your backups. This site no longer removes old snapshots; remove them from another computer with "restic forget" and "restic prune".', 'backups-for-restic' ), formatTime( retention.switched_at ) ),
				),
			);
		}
		return nodes;
	}

	/**
	 * The three maintenance cards: old snapshots, unused data, repository check.
	 *
	 * @param {Object}                                       status Dashboard data.
	 * @param {(type: string, label: string) => HTMLElement} action Creates a button that starts a job of a type.
	 * @return {Element} Cards.
	 */
	function maintenanceCards( status, action ) {
		const plugin = 'plugin' === status.retention.mode;
		const forget = status.maintenance.forget;
		const prune = status.maintenance.prune;
		const check = status.maintenance.check;
		const description = function ( text ) {
			return el( 'p', { className: 'description' }, [ text ] );
		};

		let forgetWhen = __( 'They are removed from another computer, because the server was append-only.', 'backups-for-restic' );
		if ( plugin && status.retention.after_backup ) {
			forgetWhen = __( 'This site removes them after each backup, following the retention settings.', 'backups-for-restic' );
		} else if ( plugin ) {
			forgetWhen = __( 'This site removes them when you start it here.', 'backups-for-restic' );
		}

		let forgetLast = __( 'Not run yet.', 'backups-for-restic' );
		if ( forget.finished && 'append_only' === forget.skipped ) {
			/* translators: %s: date and time. */
			forgetLast = sprintf( __( 'Last run on %s: the server refused deletions.', 'backups-for-restic' ), formatTime( forget.finished ) );
		} else if ( forget.finished && 'number' === typeof forget.removed ) {
			/* translators: 1: date and time, 2: snapshots removed, 3: snapshots kept. */
			forgetLast = sprintf( __( 'Last run on %1$s: %2$d removed, %3$d kept.', 'backups-for-restic' ), formatTime( forget.finished ), forget.removed, forget.keep || 0 );
		}

		let pruneWhen = __( 'It is deleted from another computer with "restic prune".', 'backups-for-restic' );
		if ( plugin && prune.next ) {
			/* translators: %s: date and time. */
			pruneWhen = sprintf( __( 'Data that no snapshot uses is deleted next on %s.', 'backups-for-restic' ), formatTime( prune.next ) );
		} else if ( plugin ) {
			pruneWhen = __( 'Data that no snapshot uses is deleted when you start it here.', 'backups-for-restic' );
		}

		let pruneLast = __( 'Not run yet.', 'backups-for-restic' );
		if ( prune.finished && 'append_only' === prune.skipped ) {
			/* translators: %s: date and time. */
			pruneLast = sprintf( __( 'Last run on %s: the server refused deletions.', 'backups-for-restic' ), formatTime( prune.finished ) );
		} else if ( prune.finished && 'retention_external' === prune.skipped ) {
			/* translators: %s: date and time. */
			pruneLast = sprintf( __( 'Last run on %s: nothing deleted, old data is removed from another computer.', 'backups-for-restic' ), formatTime( prune.finished ) );
		} else if ( prune.finished && 'number' === typeof prune.bytes_deleted ) {
			/* translators: 1: date and time, 2: amount of data such as 15 MB. */
			pruneLast = sprintf( __( 'Last run on %1$s: %2$s deleted.', 'backups-for-restic' ), formatTime( prune.finished ), formatBytes( prune.bytes_deleted ) );
		}

		let checkLast = __( 'Not checked yet.', 'backups-for-restic' );
		if ( check.finished && check.errors > 0 ) {
			/* translators: 1: number of errors, 2: date and time. */
			checkLast = sprintf( _n( 'Last check on %2$s: %1$d error.', 'Last check on %2$s: %1$d errors.', check.errors, 'backups-for-restic' ), check.errors, formatTime( check.finished ) );
		} else if ( check.finished ) {
			/* translators: %s: date and time. */
			checkLast = sprintf( __( 'Last check on %s: no errors.', 'backups-for-restic' ), formatTime( check.finished ) );
		}

		return el( 'div', { className: 'bfr-cards' }, [
			el( 'div', { className: 'bfr-card' }, [
				el( 'h3', {}, [ __( 'Old snapshots', 'backups-for-restic' ) ] ),
				el( 'p', {}, [ forgetWhen ] ),
				description( forgetLast ),
				plugin ? action( 'forget', __( 'Remove old snapshots now', 'backups-for-restic' ) ) : null,
			] ),
			el( 'div', { className: 'bfr-card' }, [
				el( 'h3', {}, [ __( 'Unused data', 'backups-for-restic' ) ] ),
				el( 'p', {}, [ pruneWhen ] ),
				description( pruneLast ),
				prune.reclaimable_bytes > 0
					? description(
						/* translators: %s: amount of data such as 15 MB. */
						sprintf( __( 'About %s more lies unused in partly used packs. Only "restic prune" on another computer can free it.', 'backups-for-restic' ), formatBytes( prune.reclaimable_bytes ) ),
					)
					: null,
				plugin ? action( 'prune', __( 'Prune now', 'backups-for-restic' ) ) : null,
			] ),
			el( 'div', { className: 'bfr-card' }, [
				el( 'h3', {}, [ __( 'Repository check', 'backups-for-restic' ) ] ),
				el( 'p', {}, [
					/* translators: %s: date and time. */
					check.next ? sprintf( __( 'The next check runs on %s.', 'backups-for-restic' ), formatTime( check.next ) ) : __( 'The check runs when you start it here.', 'backups-for-restic' ),
				] ),
				description( checkLast ),
				check.finished && 'number' === typeof check.packs_read
					? description(
						/* translators: 1: number of packs, 2: amount of data such as 15 MB. */
						sprintf( __( '%1$d packs with %2$s were downloaded and verified.', 'backups-for-restic' ), check.packs_read, formatBytes( check.bytes_read ) ),
					)
					: null,
				action( 'check', __( 'Check now', 'backups-for-restic' ) ),
			] ),
		] );
	}

	function dashboard( root ) {
		api( '/status' )
			.then( function ( status ) {
				const last = status.last_backup || {};
				const running = el( 'div', { className: 'bfr-card bfr-running' } );
				const reload = function () {
					dashboard( root );
				};
				const buttons = [];
				const action = function ( type, label, primary ) {
					const control = button( label, function () {
						buttons.forEach( function ( other ) {
							other.disabled = true;
						} );
						api( '/jobs', { method: 'POST', data: { type } } )
							.then( function ( response ) {
								follow( response.job.id, running, reload );
							} )
							.catch( function ( error ) {
								buttons.forEach( function ( other ) {
									other.disabled = false;
								} );
								replace( running, [ notice( 'error', errorText( error ) ) ] );
							} );
					}, primary );
					buttons.push( control );
					return control;
				};
				const appendOnly = {
					yes: __( 'Append-only: the site cannot delete backups.', 'backups-for-restic' ),
					no: __( 'Deletions allowed. Recommended: turn on append-only on your server, so that nobody who takes over this site can delete your backups.', 'backups-for-restic' ),
					unknown: __( 'Append-only status unknown.', 'backups-for-restic' ),
				};
				replace( root, [
					...alerts( status, reload ),
					el( 'div', { className: 'bfr-cards' }, [
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Last backup', 'backups-for-restic' ) ] ),
							el( 'p', { className: 'bfr-big' }, [ last.finished ? formatTime( last.finished ) : __( 'None yet', 'backups-for-restic' ) ] ),
							last.snapshot ? el( 'p', {}, [ /* translators: %s: snapshot ID. */ sprintf( __( 'Snapshot %s', 'backups-for-restic' ), String( last.snapshot ).slice( 0, 8 ) ) ] ) : null,
						] ),
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Next backup', 'backups-for-restic' ) ] ),
							el( 'p', { className: 'bfr-big' }, [ status.next_backup ? formatTime( status.next_backup ) : __( 'Manual only', 'backups-for-restic' ) ] ),
							action( 'backup', __( 'Back up now', 'backups-for-restic' ), true ),
						] ),
						el( 'div', { className: 'bfr-card' }, [
							el( 'h2', {}, [ __( 'Repository', 'backups-for-restic' ) ] ),
							el( 'p', {}, [ status.repository.host ] ),
							el( 'p', { className: 'description' }, [ appendOnly[ status.repository.append_only ] || '' ] ),
						] ),
					] ),
					running,
					el( 'h2', {}, [ __( 'Maintenance', 'backups-for-restic' ) ] ),
					maintenanceCards( status, action ),
					el( 'h2', {}, [ __( 'Recent jobs', 'backups-for-restic' ) ] ),
					jobTable( status.recent_jobs ),
				] );
				if ( status.active_job ) {
					buttons.forEach( function ( control ) {
						control.disabled = true;
					} );
					follow( status.active_job.id, running, reload );
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
					replace( root, [ notice( 'info', __( 'No snapshots yet.', 'backups-for-restic' ) ) ] );
					return;
				}
				replace( root, [
					el( 'table', { className: 'widefat striped' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', {}, [ __( 'Time', 'backups-for-restic' ) ] ),
								el( 'th', {}, [ __( 'Snapshot', 'backups-for-restic' ) ] ),
								el( 'th', {}, [ __( 'Host', 'backups-for-restic' ) ] ),
								el( 'th', {}, [ __( 'Files', 'backups-for-restic' ) ] ),
								el( 'th', {}, [ __( 'Size', 'backups-for-restic' ) ] ),
								el( 'th', {}, [ __( 'New data', 'backups-for-restic' ) ] ),
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
							} ),
						),
					] ),
					el( 'p', { className: 'description' }, [ __( 'Restoring from the admin area arrives in a later version. Until then, the recovery kit explains how to restore with the restic command line.', 'backups-for-restic' ) ] ),
				] );
			} )
			.catch( function ( error ) {
				replace( root, [ notice( 'error', errorText( error ) ) ] );
			} );
	}

	/* -------------------------------------------------------------- Settings */

	const SETTING_GROUPS = [
		{
			title: __( 'Schedule', 'backups-for-restic' ),
			fields: [
				[ 'schedule.backup.frequency', __( 'Back up', 'backups-for-restic' ), 'choice', { manual: __( 'Only manually', 'backups-for-restic' ), twice_daily: __( 'Twice a day', 'backups-for-restic' ), daily: __( 'Daily', 'backups-for-restic' ), weekly: __( 'Weekly', 'backups-for-restic' ) } ],
				[ 'schedule.backup.hour', __( 'Hour', 'backups-for-restic' ), 'number' ],
				[ 'schedule.backup.minute', __( 'Minute', 'backups-for-restic' ), 'number' ],
				[ 'schedule.backup.weekday', __( 'Weekday for weekly backups (0 = Sunday)', 'backups-for-restic' ), 'number' ],
			],
		},
		{
			title: __( 'Old snapshots', 'backups-for-restic' ),
			fields: [
				[ 'retention.mode', __( 'Who removes old snapshots', 'backups-for-restic' ), 'choice', { plugin: __( 'This site', 'backups-for-restic' ), external: __( 'Another computer (for append-only servers)', 'backups-for-restic' ) }, __( 'The plugin switches to "another computer" by itself when the server refuses deletions.', 'backups-for-restic' ) ],
				[ 'retention.run_forget_after_backup', __( 'Remove old snapshots after each backup', 'backups-for-restic' ), 'boolean' ],
				[ 'retention.keep.within', __( 'Keep every snapshot of the last', 'backups-for-restic' ), 'text', null, __( 'A duration such as 2d (two days), 12h or 1y6m. Required, so that recent snapshots are never removed.', 'backups-for-restic' ) ],
				[ 'retention.keep.last', __( 'Always keep the newest snapshots, this many', 'backups-for-restic' ), 'number' ],
				[ 'retention.keep.daily', __( 'Keep one snapshot per day, for this many days', 'backups-for-restic' ), 'number' ],
				[ 'retention.keep.weekly', __( 'Keep one snapshot per week, for this many weeks', 'backups-for-restic' ), 'number' ],
				[ 'retention.keep.monthly', __( 'Keep one snapshot per month, for this many months', 'backups-for-restic' ), 'number' ],
				[ 'retention.keep.yearly', __( 'Keep one snapshot per year, for this many years', 'backups-for-restic' ), 'number' ],
			],
		},
		{
			title: __( 'Maintenance', 'backups-for-restic' ),
			fields: [
				[ 'schedule.prune.frequency', __( 'Delete unused data', 'backups-for-restic' ), 'choice', { manual: __( 'Only manually', 'backups-for-restic' ), weekly: __( 'Weekly', 'backups-for-restic' ), monthly: __( 'Monthly', 'backups-for-restic' ) } ],
				[ 'schedule.prune.weekday', __( 'Weekday for maintenance (0 = Sunday)', 'backups-for-restic' ), 'number', null, __( 'Monthly pruning runs on the first such weekday of the month; weekly checks run on it as well.', 'backups-for-restic' ) ],
				[ 'schedule.prune.hour', __( 'Hour for deleting unused data', 'backups-for-restic' ), 'number' ],
				[ 'schedule.check.frequency', __( 'Check the repository', 'backups-for-restic' ), 'choice', { manual: __( 'Only manually', 'backups-for-restic' ), weekly: __( 'Weekly', 'backups-for-restic' ), monthly: __( 'Monthly', 'backups-for-restic' ) } ],
				[ 'schedule.check.day', __( 'Day of the month for monthly checks', 'backups-for-restic' ), 'number' ],
				[ 'schedule.check.hour', __( 'Hour for checks', 'backups-for-restic' ), 'number' ],
				[ 'schedule.check.read_data_subset_percent', __( 'Share of the data each check downloads and verifies, in percent', 'backups-for-restic' ), 'number', null, __( 'Each check reads a random sample of the stored data.', 'backups-for-restic' ) ],
			],
		},
		{
			title: __( 'Notifications', 'backups-for-restic' ),
			fields: [
				[ 'notifications.email.on_failure', __( 'E-mail when a job fails, a check finds damage or the server becomes append-only', 'backups-for-restic' ), 'boolean' ],
				[ 'notifications.email.on_stale', __( 'E-mail when no backup succeeded for too long', 'backups-for-restic' ), 'boolean' ],
				[ 'notifications.email.stale_after_hours', __( 'Hours without a successful backup before that e-mail', 'backups-for-restic' ), 'number', null, __( 'At least one and a half backup intervals are waited in any case.', 'backups-for-restic' ) ],
				[ 'notifications.email.recipients', __( 'Recipients, one e-mail address per line', 'backups-for-restic' ), 'list', null, __( 'Empty: the administration e-mail address of this site.', 'backups-for-restic' ) ],
			],
		},
		{
			title: __( 'What to back up', 'backups-for-restic' ),
			fields: [
				[ 'backup.database.enabled', __( 'Back up the database', 'backups-for-restic' ), 'boolean' ],
				[ 'backup.database.table_scope', __( 'Tables', 'backups-for-restic' ), 'choice', { prefix: __( 'Tables of this site (with its prefix)', 'backups-for-restic' ), all: __( 'All tables of the database', 'backups-for-restic' ) } ],
				[ 'backup.database.exclude_tables', __( 'Tables to leave out, one per line', 'backups-for-restic' ), 'list' ],
				[ 'backup.extra_roots', __( 'Additional directories, one absolute path per line', 'backups-for-restic' ), 'list' ],
				[ 'backup.excludes', __( 'Exclude patterns, one per line', 'backups-for-restic' ), 'list' ],
				[ 'backup.exclude_caches', __( 'Skip directories marked as caches', 'backups-for-restic' ), 'boolean' ],
			],
		},
		{
			title: __( 'Advanced', 'backups-for-restic' ),
			fields: [
				[ 'advanced.tick_budget_seconds', __( 'Seconds per work step', 'backups-for-restic' ), 'number' ],
				[ 'advanced.pack_size_mib', __( 'Pack size in MiB', 'backups-for-restic' ), 'number' ],
				[ 'advanced.upload_timeout_seconds', __( 'Upload timeout in seconds', 'backups-for-restic' ), 'number' ],
				[ 'advanced.cpu_throttle', __( 'Processor use', 'backups-for-restic' ), 'choice', { normal: __( 'Normal', 'backups-for-restic' ), gentle: __( 'Gentle: short pauses and smaller steps, for hosts that limit CPU time', 'backups-for-restic' ) } ],
				[ 'advanced.cache_limit_mib', __( 'Size limit of the local cache in MiB', 'backups-for-restic' ), 'number' ],
				[ 'advanced.loopback_chaining', __( 'Continue jobs with loopback requests', 'backups-for-restic' ), 'choice', { auto: __( 'Yes', 'backups-for-restic' ), off: __( 'No', 'backups-for-restic' ) } ],
				[ 'advanced.keep_jobs', __( 'Jobs to keep in the log', 'backups-for-restic' ), 'number' ],
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
				} ),
			);
		}
		if ( 'list' === kind ) {
			return el( 'textarea', { rows: 3, className: 'large-text code', value: ( value || [] ).join( '\n' ) } );
		}
		if ( 'text' === kind ) {
			return el( 'input', { type: 'text', className: 'regular-text', value: String( value ) } );
		}
		return el( 'input', { type: 'number', className: 'small-text', value: String( value ) } );
	}

	function formValue( entry ) {
		return 'boolean' === entry.kind ? entry.node.checked : entry.node.value;
	}

	function settings( root ) {
		api( '/settings' )
			.then( function ( response ) {
				const inputs = {};
				const message = el( 'div' );
				const groups = SETTING_GROUPS.map( function ( group ) {
					return el( 'fieldset', { className: 'bfr-group' }, [
						el( 'legend', {}, [ group.title ] ),
						...group.fields.map( function ( [ path, label, kind, choices, help ] ) {
							inputs[ path ] = { kind, node: input( kind, response.values[ path ], choices ) };
							inputs[ path ].error = el( 'span', { className: 'bfr-error' } );
							inputs[ path ].initial = formValue( inputs[ path ] );
							const row = field( label, inputs[ path ].node, help );
							row.append( inputs[ path ].error );
							return row;
						} ),
					] );
				} );
				const save = button( __( 'Save settings', 'backups-for-restic' ), function () {
					// Only what the administrator changed, so that a page opened before an
					// automatic change, such as the switch to external retention, cannot undo it.
					const values = {};
					Object.entries( inputs ).forEach( function ( [ path, entry ] ) {
						entry.error.textContent = '';
						if ( formValue( entry ) !== entry.initial ) {
							values[ path ] = formValue( entry );
						}
					} );
					save.disabled = true;
					api( '/settings', { method: 'PUT', data: { values } } )
						.then( function () {
							Object.values( inputs ).forEach( function ( entry ) {
								entry.initial = formValue( entry );
							} );
							replace( message, [ notice( 'success', __( 'Settings saved.', 'backups-for-restic' ) ) ] );
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
						el( 'legend', {}, [ __( 'Repository', 'backups-for-restic' ) ] ),
						...repositoryForms( response ),
						el( 'h3', {}, [ __( 'Working files and recovery kit', 'backups-for-restic' ) ] ),
						el( 'p', { className: 'description' }, [
							/* translators: %s: directory path. */
							sprintf( __( 'Working directory: %s', 'backups-for-restic' ), response.private_dir ),
						] ),
						cacheControl( response.cache_bytes ),
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

	/**
	 * The repository part of the settings: where the backups go, and the forms to enter the
	 * two passwords again and to change the server's CA certificate. What wp-config.php
	 * defines is shown as such and cannot be changed here.
	 *
	 * @param {Object} response Answer of the settings route.
	 * @return {Array} Nodes.
	 */
	function repositoryForms( response ) {
		const values = response.values;
		const secrets = response.secrets || {};
		const fromConfig = __( 'Defined in wp-config.php; change it there.', 'backups-for-restic' );
		const overridden = function ( path ) {
			return ( response.overridden || [] ).includes( path );
		};
		const address = function ( label, path ) {
			return el( 'p', {}, [
				el( 'strong', {}, [ label + ': ' ] ),
				values[ path ] || '–',
				overridden( path ) ? el( 'span', { className: 'description' }, [ ' ', fromConfig ] ) : null,
			] );
		};

		// REST password: tested against the server, then saved.
		const restResult = el( 'div' );
		const restFixed = !! ( secrets.rest_password && secrets.rest_password.overridden );
		const restPassword = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'new-password', disabled: restFixed } );
		const restSave = button( __( 'Test and save', 'backups-for-restic' ), function () {
			restSave.disabled = true;
			api( '/repository/save', {
				method: 'POST',
				data: { server: values[ 'repository.server' ], url: values[ 'repository.url' ], username: values[ 'repository.rest_username' ], password: restPassword.value },
			} )
				.then( function () {
					restPassword.value = '';
					replace( restResult, [ notice( 'success', __( 'The server accepted the password. It is saved.', 'backups-for-restic' ) ) ] );
				} )
				.catch( function ( error ) {
					replace( restResult, [ notice( 'error', errorText( error ) ) ] );
				} )
				.finally( function () {
					restSave.disabled = false;
				} );
		} );
		restSave.disabled = restFixed;

		// Repository password: an unlock job derives the key with it.
		const unlockResult = el( 'div' );
		const repositoryFixed = !! ( secrets.repository_password && secrets.repository_password.overridden );
		const repositoryPassword = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'off', disabled: repositoryFixed } );
		const confirmation = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password', disabled: repositoryFixed } );
		const unlock = button( __( 'Unlock with this password', 'backups-for-restic' ), function () {
			unlock.disabled = true;
			api( '/repository/connect', { method: 'POST', data: { password: repositoryPassword.value, user_password: confirmation.value } } )
				.then( function ( created ) {
					repositoryPassword.value = '';
					confirmation.value = '';
					follow( created.job.id, unlockResult, function ( job ) {
						unlock.disabled = false;
						if ( 'succeeded' === job.state ) {
							replace( unlockResult, [ notice( 'success', __( 'The repository is unlocked with this password.', 'backups-for-restic' ) ) ] );
						}
					} );
				} )
				.catch( function ( error ) {
					unlock.disabled = false;
					replace( unlockResult, [ notice( 'error', errorText( error ) ) ] );
				} );
		} );
		unlock.disabled = repositoryFixed;

		// CA certificate of a server with a certificate of its own authority.
		const caBox = el( 'div' );
		const renderCa = function ( state, message ) {
			const pem = el( 'textarea', { className: 'large-text code', rows: 4, placeholder: '-----BEGIN CERTIFICATE-----' } );
			const upload = button( __( 'Upload certificate', 'backups-for-restic' ), function () {
				upload.disabled = true;
				api( '/repository/ca-certificate', { method: 'POST', data: { pem: pem.value } } )
					.then( function () {
						renderCa( 'uploaded', notice( 'success', __( 'The certificate is uploaded.', 'backups-for-restic' ) ) );
					} )
					.catch( function ( error ) {
						renderCa( state, notice( 'error', errorText( error ) ) );
					} );
			} );
			const remove = button( __( 'Remove certificate', 'backups-for-restic' ), function () {
				remove.disabled = true;
				api( '/repository/ca-certificate', { method: 'DELETE' } )
					.then( function () {
						renderCa( 'none', notice( 'success', __( 'The certificate is removed.', 'backups-for-restic' ) ) );
					} )
					.catch( function ( error ) {
						renderCa( state, notice( 'error', errorText( error ) ) );
					} );
			} );
			replace( caBox, [
				field(
					__( 'CA certificate of the server (PEM)', 'backups-for-restic' ),
					pem,
					'uploaded' === state
						? __( 'A certificate is uploaded. Paste another one to replace it.', 'backups-for-restic' )
						: __( 'Only for a server with a certificate of its own authority.', 'backups-for-restic' ),
				),
				el( 'p', {}, [ upload, ' ', 'uploaded' === state ? remove : null ] ),
				message,
			] );
		};
		renderCa( values[ 'repository.ca_certificate' ], null );

		return [
			address( __( 'Repository URL', 'backups-for-restic' ), 'repository.url' ),
			address( __( 'REST username', 'backups-for-restic' ), 'repository.rest_username' ),
			el( 'h3', {}, [ __( 'REST password', 'backups-for-restic' ) ] ),
			field( __( 'New REST password', 'backups-for-restic' ), restPassword, restFixed ? fromConfig : __( 'For example after you changed it on the server.', 'backups-for-restic' ) ),
			el( 'p', {}, [ restSave ] ),
			restResult,
			el( 'h3', {}, [ __( 'Repository password', 'backups-for-restic' ) ] ),
			field(
				__( 'Repository password', 'backups-for-restic' ),
				repositoryPassword,
				repositoryFixed ? fromConfig : __( 'Enter it again if the stored one can no longer be read, or after it was changed with restic.', 'backups-for-restic' ),
			),
			field( __( 'Confirm with your WordPress password', 'backups-for-restic' ), confirmation ),
			el( 'p', {}, [ unlock ] ),
			unlockResult,
			el( 'h3', {}, [ __( 'CA certificate', 'backups-for-restic' ) ] ),
			caBox,
		];
	}

	function cacheControl( bytes ) {
		const box = el( 'div' );
		function render( size, message ) {
			const clear = button( __( 'Clear cache', 'backups-for-restic' ), function () {
				clear.disabled = true;
				api( '/cache', { method: 'DELETE' } )
					.then( function ( response ) {
						render( response.cache_bytes, notice( 'success', __( 'The cache was cleared. The next job reads the index from the repository again.', 'backups-for-restic' ) ) );
					} )
					.catch( function ( error ) {
						render( size, notice( 'error', errorText( error ) ) );
					} );
			} );
			replace( box, [
				el( 'p', { className: 'description' }, [
					/* translators: %s: amount of data such as 15 MB. */
					sprintf( __( 'Local cache: %s. It only speeds up jobs and is rebuilt when needed.', 'backups-for-restic' ), formatBytes( size ) ),
					' ',
					clear,
				] ),
				message,
			] );
		}
		render( bytes, null );
		return box;
	}

	function kitView( kit ) {
		const text = el( 'textarea', { className: 'large-text code', rows: 14, readOnly: true, value: kit } );
		const download = button( __( 'Download', 'backups-for-restic' ), function () {
			const url = URL.createObjectURL( new Blob( [ kit ], { type: 'text/plain' } ) );
			const link = el( 'a', { href: url, download: 'backup-recovery-kit.txt' } );
			document.body.append( link );
			link.click();
			link.remove();
			URL.revokeObjectURL( url );
		} );
		const copy = button( __( 'Copy', 'backups-for-restic' ), function () {
			window.navigator.clipboard.writeText( kit ).catch( function () {
				text.select();
			} );
		} );
		return el( 'div', { className: 'bfr-kit' }, [ text, el( 'p', {}, [ download, ' ', copy ] ) ] );
	}

	function recoveryKitForm() {
		const box = el( 'div' );
		const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password' } );
		const show = button( __( 'Show recovery kit', 'backups-for-restic' ), function () {
			show.disabled = true;
			api( '/recovery-kit', { method: 'POST', data: { user_password: password.value } } )
				.then( function ( response ) {
					replace( box, [ kitView( response.recovery_kit ) ] );
				} )
				.catch( function ( error ) {
					show.disabled = false;
					replace( box, [ notice( 'error', errorText( error ) ), field( __( 'Your WordPress password', 'backups-for-restic' ), password ), show ] );
				} );
		} );
		replace( box, [ field( __( 'Your WordPress password', 'backups-for-restic' ), password, __( 'Needed to show the repository password.', 'backups-for-restic' ) ), show ] );
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
			} ),
		);
	}

	/**
	 * Lets this browser request a probe file in the working directory, where the site could not
	 * request it from itself, and reports what came back. Never fails: a probe that cannot run
	 * leaves the check as it was.
	 *
	 * @return {Promise} Settled when the report is in.
	 */
	function probeFromBrowser() {
		return api( '/environment/probe', { method: 'POST' } )
			.then( function ( probe ) {
				if ( ! probe.url ) {
					return null;
				}
				// A server that accepts the request and never answers must not hold the page up.
				const patience = new window.AbortController();
				const timer = window.setTimeout( function () {
					patience.abort();
				}, 10000 );
				return window
					.fetch( probe.url, { credentials: 'omit', cache: 'no-store', redirect: 'manual', signal: patience.signal } )
					.then( function ( answer ) {
						return answer.text().then( function ( body ) {
							return { status: answer.status, body: body.slice( 0, 1024 ) };
						} );
					} )
					.catch( function () {
						return { status: 0, body: '' };
					} )
					.then( function ( seen ) {
						window.clearTimeout( timer );
						seen.probe = probe.probe;
						return api( '/environment/probe/report', { method: 'POST', data: seen } );
					} );
			} )
			.catch( function () {
				return null;
			} );
	}

	/**
	 * The checks of this server, fresh, with the browser's probe where it is needed.
	 *
	 * @return {Promise<Object>} Response of the environment route.
	 */
	function checkEnvironment() {
		return api( '/environment?fresh=1' ).then( function ( response ) {
			if ( ! response.browser_probe ) {
				return response;
			}
			return probeFromBrowser().then( function () {
				return api( '/environment' );
			} );
		} );
	}

	function environment( root ) {
		checkEnvironment()
			.then( function ( response ) {
				replace( root, [
					checkList( response.checks ),
					button( __( 'Check again', 'backups-for-restic' ), function () {
						environment( root );
					} ),
				] );
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
									} ),
								),
								( log.job.warnings || [] ).length
									? el( 'ul', {}, log.job.warnings.map( function ( warning ) {
										return el( 'li', {}, [ warning ] );
									} ) )
									: null,
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
		const state = { server: 'rest-server', report: null };
		const steps = el( 'ol', { className: 'bfr-steps' } );
		const content = el( 'div', { className: 'bfr-step-content' } );
		const titles = [ __( 'Environment', 'backups-for-restic' ), __( 'Repository server', 'backups-for-restic' ), __( 'Password', 'backups-for-restic' ), __( 'Recovery kit', 'backups-for-restic' ), __( 'Schedule', 'backups-for-restic' ), __( 'First backup', 'backups-for-restic' ) ];

		function show( index, nodes ) {
			replace( steps, titles.map( function ( title, position ) {
				return el( 'li', { className: position === index ? 'bfr-current-step' : '' }, [ title ] );
			} ) );
			replace( content, nodes );
		}

		function stepEnvironment() {
			show( 0, [ el( 'p', {}, [ __( 'Checking this server…', 'backups-for-restic' ) ] ) ] );
			checkEnvironment()
				.then( function ( response ) {
					const blocked = response.checks.some( function ( check ) {
						return 'critical' === check.status;
					} );
					show( 0, [
						checkList( response.checks ),
						blocked
							? notice( 'error', __( 'Please fix the problems marked in red, then check again.', 'backups-for-restic' ) )
							: button( __( 'Continue', 'backups-for-restic' ), stepServer, true ),
						' ',
						button( __( 'Check again', 'backups-for-restic' ), stepEnvironment ),
					] );
				} )
				.catch( function ( error ) {
					show( 0, [ notice( 'error', errorText( error ) ) ] );
				} );
		}

		function stepServer() {
			const own = el( 'input', { type: 'radio', name: 'bfr-server', value: 'rest-server', checked: 'rest-server' === state.server } );
			const borgbase = el( 'input', { type: 'radio', name: 'bfr-server', value: 'borgbase', checked: 'borgbase' === state.server } );
			const url = el( 'input', { type: 'url', className: 'regular-text', placeholder: 'https://backup.example.net:8000/site/' } );
			const username = el( 'input', { type: 'text', className: 'regular-text', autocomplete: 'off' } );
			const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'new-password' } );
			const ca = el( 'textarea', { className: 'large-text code', rows: 4, placeholder: '-----BEGIN CERTIFICATE-----' } );
			const caRow = field( __( 'CA certificate for a self-signed server (optional, PEM)', 'backups-for-restic' ), ca );
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
			// The certificate has to be there before the first request to the server: the
			// connection test of a server with a certificate of its own authority fails without it.
			const upload = function () {
				return own.checked && ca.value.trim() ? api( '/repository/ca-certificate', { method: 'POST', data: { pem: ca.value } } ) : Promise.resolve();
			};
			const save = button( __( 'Save and continue', 'backups-for-restic' ), function () {
				save.disabled = true;
				upload()
					.then( function () {
						return api( '/repository/save', { method: 'POST', data: data() } );
					} )
					.then( function ( response ) {
						state.report = response.report;
						stepPassword();
					} )
					.catch( function ( error ) {
						save.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			const test = button( __( 'Test connection', 'backups-for-restic' ), function () {
				test.disabled = true;
				upload()
					.then( function () {
						return api( '/repository/test', { method: 'POST', data: data() } );
					} )
					.then( function ( response ) {
						const report = response.report;
						const parts = [ report.exists ? __( 'Connected. A repository exists at this address.', 'backups-for-restic' ) : __( 'Connected. The address is empty; a new repository will be created.', 'backups-for-restic' ) ];
						if ( 'append_only' === report.append_only ) {
							parts.push( __( 'The repository is append-only: this site will never be able to delete backups.', 'backups-for-restic' ) );
						} else if ( 'deletions_allowed' === report.append_only ) {
							parts.push( __( 'Recommended: turn on append-only on your server (rest-server: --append-only; BorgBase: repository settings). Then nobody who takes over this website can delete your backups.', 'backups-for-restic' ) );
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
					el( 'legend', { className: 'screen-reader-text' }, [ __( 'Server', 'backups-for-restic' ) ] ),
					el( 'label', {}, [ own, ' ', __( 'Own restic REST server', 'backups-for-restic' ) ] ),
					el( 'br' ),
					el( 'label', {}, [ borgbase, ' ', __( 'BorgBase', 'backups-for-restic' ) ] ),
				] ),
				field( __( 'Repository URL', 'backups-for-restic' ), url ),
				field( __( 'REST username', 'backups-for-restic' ), username ),
				field( __( 'REST password', 'backups-for-restic' ), password ),
				caRow,
				test,
				result,
			] );
		}

		function stepPassword() {
			const result = el( 'div' );
			if ( state.report && state.report.exists ) {
				const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'off' } );
				const connect = button( __( 'Connect', 'backups-for-restic' ), function () {
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
					el( 'p', {}, [ __( 'Enter the password of the existing repository. Unlocking can take a few minutes on slow hosts; it runs in the background.', 'backups-for-restic' ) ] ),
					field( __( 'Repository password', 'backups-for-restic' ), password ),
					connect,
					result,
				] );
				return;
			}
			const create = button( __( 'Create repository', 'backups-for-restic' ), function () {
				create.disabled = true;
				replace( result, [ el( 'p', {}, [ __( 'Creating the repository…', 'backups-for-restic' ) ] ) ] );
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
				el( 'p', {}, [ __( 'The plugin creates a new, encrypted repository with a random 32-character password. Nobody can read your backups without it, not even the storage provider.', 'backups-for-restic' ) ] ),
				create,
				result,
			] );
		}

		function stepKit( kit ) {
			const confirm = el( 'input', { type: 'checkbox' } );
			const result = el( 'div' );
			const next = button( __( 'Continue', 'backups-for-restic' ), function () {
				next.disabled = true;
				api( '/setup/complete', { method: 'POST', data: { recovery_kit_acknowledged: confirm.checked } } )
					.then( stepSchedule )
					.catch( function ( error ) {
						next.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			show( 3, [
				el( 'p', {}, [ __( 'Store this recovery kit outside this website, for example in a password manager. Without it, nobody can restore your backups if the site is lost.', 'backups-for-restic' ) ] ),
				kitView( kit ),
				el( 'p', {}, [ el( 'label', {}, [ confirm, ' ', __( 'I have stored the recovery kit outside this server.', 'backups-for-restic' ) ] ) ] ),
				next,
				result,
			] );
		}

		function stepSchedule() {
			const frequency = input( 'choice', 'daily', { manual: __( 'Only manually', 'backups-for-restic' ), twice_daily: __( 'Twice a day', 'backups-for-restic' ), daily: __( 'Daily', 'backups-for-restic' ), weekly: __( 'Weekly', 'backups-for-restic' ) } );
			const hour = el( 'input', { type: 'number', className: 'small-text', min: 0, max: 23, value: String( Math.floor( Math.random() * 5 ) + 1 ) } );
			const minute = el( 'input', { type: 'number', className: 'small-text', min: 0, max: 59, value: String( Math.floor( Math.random() * 60 ) ) } );
			const result = el( 'div' );
			const next = button( __( 'Save and run the first backup', 'backups-for-restic' ), function () {
				next.disabled = true;
				api( '/settings', { method: 'PUT', data: { values: { 'schedule.backup.frequency': frequency.value, 'schedule.backup.hour': hour.value, 'schedule.backup.minute': minute.value } } } )
					.then( stepFirstBackup )
					.catch( function ( error ) {
						next.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			show( 4, [ field( __( 'Back up', 'backups-for-restic' ), frequency ), field( __( 'Hour', 'backups-for-restic' ), hour ), field( __( 'Minute', 'backups-for-restic' ), minute ), next, result ] );
		}

		function stepFirstBackup() {
			const progress = el( 'div' );
			show( 5, [ el( 'p', {}, [ __( 'The first backup uploads everything once; later backups only send changes.', 'backups-for-restic' ) ] ), progress ] );
			api( '/jobs', { method: 'POST' } )
				.then( function ( response ) {
					follow( response.job.id, progress, function () {
						progress.append( el( 'p', {}, [ el( 'a', { className: 'button button-primary', href: config.pageUrl }, [ __( 'Go to the dashboard', 'backups-for-restic' ) ] ) ] ) );
					} );
				} )
				.catch( function ( error ) {
					replace( progress, [ notice( 'error', errorText( error ) ) ] );
				} );
		}

		replace( root, [ el( 'h2', {}, [ __( 'Set up backups', 'backups-for-restic' ) ] ), steps, content ] );
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
