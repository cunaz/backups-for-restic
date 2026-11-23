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
	const JOB_ID = /^\d{8}T\d{6}Z-[0-9a-f]{6}$/;

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

	function notice( type, text, announce = true ) {
		const attributes = { className: 'notice inline notice-' + type };
		if ( announce && 'error' === type ) {
			attributes.role = 'alert';
		} else if ( announce && 'success' === type ) {
			attributes.role = 'status';
		}
		return el( 'div', attributes, [ el( 'p', {}, [ text ] ) ] );
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
		root.replaceChildren( ...children.filter( ( child ) => null !== child && undefined !== child ) );
	}

	function button( label, onClick, primary ) {
		return el( 'button', { type: 'button', className: 'button' + ( primary ? ' button-primary' : '' ), onClick }, [ label ] );
	}

	function field( label, control, help ) {
		const id = 'bfr-' + Math.random().toString( 36 ).slice( 2 );
		control.id = id;
		if ( help ) {
			control.setAttribute( 'aria-describedby', id + '-help' );
		}
		return el( 'p', { className: 'bfr-field' }, [
			el( 'label', { htmlFor: id }, [ label ] ),
			control,
			help ? el( 'span', { className: 'description', id: id + '-help' }, [ help ] ) : null,
		] );
	}

	/* ------------------------------------------------------------------ Jobs */

	function progressLines( job ) {
		const progress = job.progress || {};
		const lines = [ el( 'p', { className: 'bfr-step' }, [ job.step_label ] ) ];
		if ( 'restore' === job.type && 'number' === typeof progress.total_files ) {
			if ( 'plan' !== progress.phase ) {
				lines.push( el( 'progress', { max: 100, value: progress.percent || 0 } ) );
			}
			lines.push( el( 'p', {}, [
				/* translators: 1: staged files, 2: files found by the planner, 3: staged bytes, 4: bytes found by the planner. */
				sprintf( __( '%1$d of %2$d files staged (%3$s of %4$s).', 'backups-for-restic' ), progress.files, progress.total_files, formatBytes( progress.bytes ), formatBytes( progress.total_bytes ) ),
			] ) );
		}
		if ( 'number' === typeof progress.database_tables ) {
			const label = 'database_apply' === job.type
				? /* translators: 1: prepared replacement tables, 2: total tables. */ __( 'Preparing replacement tables (%1$d of %2$d).', 'backups-for-restic' )
				: /* translators: 1: imported database tables, 2: total tables. */ __( 'Rehearsing database import (%1$d of %2$d tables).', 'backups-for-restic' );
			lines.push( el( 'p', {}, [ sprintf( label, progress.database_tables, progress.database_total ) ] ) );
		}
		if ( 'archiving' === progress.phase ) {
			lines.push( el( 'p', {}, [
				/* translators: %s: amount of data written to the ZIP. */
				sprintf( __( 'Building ZIP (%s written).', 'backups-for-restic' ), formatBytes( progress.archive_bytes || 0 ) ),
			] ) );
		}
		if ( 'number' === typeof progress.unlock_percent ) {
			lines.push( el( 'progress', { max: 100, value: progress.unlock_percent } ) );
		}
		if ( 'file_plan' === job.type && Number.isInteger( progress.done ) ) {
			lines.push( el( 'p', {}, [
				/* translators: %d: inspected file entries. */
				sprintf( _n( 'Inspected %d file entry.', 'Inspected %d file entries.', progress.done, 'backups-for-restic' ), progress.done ),
			] ) );
		}
		if ( [ 'apply', 'undo' ].includes( job.type ) && 'number' === typeof progress.total ) {
			lines.push( el( 'p', {}, [
				/* translators: 1: completed operations, 2: total reviewed operations. */
				sprintf( __( '%1$d of %2$d file changes completed.', 'backups-for-restic' ), progress.done || 0, progress.total ),
			] ) );
			if ( progress.total ) {
				lines.push( el( 'progress', { max: progress.total, value: progress.done || 0 } ) );
			}
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
		if ( 'number' === typeof progress.total_bytes_processed && progress.expected_bytes > 0 ) {
			// Measured against the last backup: this one knows its own total only at the end.
			const processed = Math.min( progress.total_bytes_processed, progress.expected_bytes );
			const elapsed = ( Date.now() / 1000 ) - job.started_at;
			lines.push( el( 'progress', { max: progress.expected_bytes, value: processed } ) );
			if ( job.started_at > 0 && elapsed >= 30 && processed > 0 && processed < progress.expected_bytes ) {
				const minutes = Math.ceil( ( ( progress.expected_bytes - processed ) / ( processed / elapsed ) ) / 60 );
				lines.push(
					el( 'p', { className: 'description bfr-estimate' }, [
						sprintf(
							/* translators: %d: number of minutes. */
							_n( 'About %d minute left, judging by the last backup.', 'About %d minutes left, judging by the last backup.', minutes, 'backups-for-restic' ),
							minutes,
						),
					] ),
				);
			}
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
		if ( 'undo' === job.type && 'succeeded' !== job.state && 'number' === typeof result.kept ) {
			// A stopped undo still says what it did before it stopped, and what it kept.
			return el( 'div', {}, [
				'failed' === job.state && job.error ? notice( 'error', job.error.message + ' ' + job.error.hint ) : notice( 'info', job.state_label ),
				undoOutcome( job ),
			] );
		}
		if ( 'failed' === job.state && job.error ) {
			return notice( 'error', job.error.message + ' ' + job.error.hint );
		}
		if ( 'succeeded' === job.state && 'restore' === job.type ) {
			return el( 'div', {}, [
				notice( 'success', sprintf(
					/* translators: 1: number of staged files, 2: total staged size. */
					_n( 'Restored %1$d file to staging (%2$s).', 'Restored %1$d files to staging (%2$s).', result.files, 'backups-for-restic' ), result.files, formatBytes( result.bytes ),
				) ),
				result.skipped ? notice( 'warning', sprintf(
					/* translators: %d: number of skipped snapshot entries. */
					_n( '%d entry was skipped. See the restore report.', '%d entries were skipped. See the restore report.', result.skipped, 'backups-for-restic' ), result.skipped,
				) ) : null,
				'number' === typeof result.database_tables ? notice( 'success', sprintf(
					/* translators: 1: number of database tables, 2: number of rows. */
					__( 'Database rehearsal verified %1$d tables and %2$d rows.', 'backups-for-restic' ), result.database_tables, result.database_rows,
				) ) : null,
				...( job.warnings || [] ).map( ( warning ) => notice( 'warning', warning ) ),
			] );
		}
		if ( 'succeeded' === job.state && [ 'database_apply', 'database_undo' ].includes( job.type ) ) {
			return el( 'div', {}, [
				notice( 'success', 'database_undo' === job.type ? __( 'Database restore undone.', 'backups-for-restic' ) : __( 'Database restore applied.', 'backups-for-restic' ) ),
				el( 'p', {}, [
					/* translators: %d: number of database tables in the exchange. */
					sprintf( _n( '%d table exchanged.', '%d tables exchanged.', result.database_tables, 'backups-for-restic' ), result.database_tables ),
				] ),
				result.retained_until && 'database_apply' === job.type ? el( 'p', {}, [
					/* translators: %s: date and time when the previous live database tables expire. */
					sprintf( __( 'Previous database tables available until %s.', 'backups-for-restic' ), formatTime( result.retained_until ) ),
				] ) : null,
			] );
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
		if ( 'succeeded' === job.state && 'undo' === job.type ) {
			return undoOutcome( job );
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
	 * What an ended undo did, with the entries it kept and why, page by page.
	 *
	 * @param {Object} job Ended undo job.
	 * @return {Element} Outcome.
	 */
	function undoOutcome( job ) {
		const result = job.result || {};
		const kept = el( 'div' );
		const path = '/jobs/' + encodeURIComponent( job.id ) + '/kept-entries';
		if ( result.kept ) {
			api( path ).then( function ( page ) {
				replace( kept, [ fileActions( page, ( offset ) => api( path + '?offset=' + offset ), false ) ] );
			} ).catch( function ( error ) {
				replace( kept, [ notice( 'error', errorText( error ) ) ] );
			} );
		}
		let summary = notice( 'warning', sprintf(
			/* translators: 1: restored files, 2: removed files and folders, 3: kept entries. */
			__( 'The apply is partly undone: %1$d restored, %2$d removed, %3$d kept. The list gives the reason for each kept entry. Kept entries stay undoable until the recovery window ends.', 'backups-for-restic' ), result.restored || 0, result.removed || 0, result.kept || 0,
		) );
		if ( 'undone' === result.apply_state ) {
			summary = notice( 'success', sprintf(
				/* translators: 1: restored files, 2: removed files and folders. */
				__( 'The apply is fully undone: %1$d restored, %2$d removed.', 'backups-for-restic' ), result.restored || 0, result.removed || 0,
			) );
		} else if ( 'applied' === result.apply_state ) {
			// An undo that stopped before it reverted an entry.
			summary = notice( 'info', sprintf(
				/* translators: %d: kept entries. */
				_n( 'Nothing of the apply is undone yet: %d entry was kept. The list gives the reason.', 'Nothing of the apply is undone yet: %d entries were kept. The list gives the reason for each.', result.kept || 0, 'backups-for-restic' ), result.kept || 0,
			) );
		}
		return el( 'div', {}, [
			summary,
			result.changed_after_review ? el( 'p', {}, [ sprintf(
				/* translators: %d: entries that changed between the review and the undo. */
				_n( '%d entry changed after the review and was kept.', '%d entries changed after the review and were kept.', result.changed_after_review, 'backups-for-restic' ), result.changed_after_review,
			) ] ) : null,
			kept,
		] );
	}

	/**
	 * Says that an apply is partly undone, and what is left of it.
	 *
	 * @param {Object} job Undo opportunity.
	 * @return {?Element} Notice, or null for an apply that no undo has changed.
	 */
	function undoState( job ) {
		if ( 'partly_undone' !== job.state ) {
			return null;
		}
		if ( 'number' !== typeof job.kept ) {
			return notice( 'warning', __( 'Partly undone: the last undo stopped before it finished. Preview the undo again to revert the rest.', 'backups-for-restic' ) );
		}
		return notice( 'warning', sprintf(
			/* translators: %d: entries that the last undo kept. */
			_n( 'Partly undone: the last undo kept %d entry. Preview the undo again to see why, and whether it can be reverted now.', 'Partly undone: the last undo kept %d entries. Preview the undo again to see why, and whether they can be reverted now.', job.kept, 'backups-for-restic' ), job.kept,
		) );
	}

	/**
	 * Follows a job until it ends. Each request also advances the job a little, so an open tab
	 * speeds up slow hosts.
	 *
	 * @param {string}                  id            Job ID.
	 * @param {Element}                 target        Where to show progress.
	 * @param {(job: Object) => void}   finished      Called with the final job.
	 * @param {(error: Object) => void} [unavailable] Called when this session can no longer read the job.
	 */
	function follow( id, target, finished, unavailable ) {
		const activity = el( 'div' );
		const pending = el( 'div' );
		const feedback = el( 'div', { role: 'status' } );
		const cancel = button( __( 'Cancel', 'backups-for-restic' ), function () {
			if ( 'true' === cancel.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			cancel.setAttribute( 'aria-disabled', 'true' );
			api( '/jobs/' + id + '/cancel', { method: 'POST' } ).then( function () {
				feedback.textContent = __( 'Cancellation requested.', 'backups-for-restic' );
			} ).catch( function ( error ) {
				cancel.removeAttribute( 'aria-disabled' );
				replace( feedback, [ notice( 'error', errorText( error ) ) ] );
			} );
		} );
		pending.hidden = true;
		pending.append( el( 'p', { className: 'description' }, [ __( 'Keep this tab open to speed up the job on slow hosts.', 'backups-for-restic' ) ] ), cancel );
		replace( target, [ activity, pending, feedback ] );
		function poll() {
			api( '/jobs/' + id )
				.then( function ( response ) {
					const job = response.job;
					if ( [ 'queued', 'running' ].includes( job.state ) ) {
						replace( activity, progressLines( job ) );
						pending.hidden = false;
						if ( job.cancel_requested ) {
							cancel.setAttribute( 'aria-disabled', 'true' );
						}
						window.setTimeout( poll, POLL_MS );
					} else {
						const hadFocus = cancel.ownerDocument.activeElement === cancel;
						replace( target, [ jobOutcome( job ) ] );
						if ( hadFocus ) {
							target.tabIndex = -1;
							target.focus();
						}
						finished( job );
					}
				} )
				.catch( function ( error ) {
					replace( activity, [ notice( 'error', errorText( error ) ) ] );
					if ( ! error.data || ! [ 401, 403, 404 ].includes( error.data.status ) ) {
						window.setTimeout( poll, POLL_MS * 3 );
					} else if ( unavailable ) {
						unavailable( error );
					}
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
							status.repository.usage && status.repository.usage.at
								? el( 'p', { className: 'bfr-usage' }, [
									sprintf(
										/* translators: 1: amount of data such as 1.2 GB, 2: date and time. */
										__( '%1$s stored, measured on %2$s', 'backups-for-restic' ),
										formatBytes( status.repository.usage.bytes ),
										formatTime( status.repository.usage.at ),
									),
								] )
								: null,
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

	/**
	 * The paths that are ticked in the directory browser, for a restore to start from. They
	 * belong to one snapshot: opening another one empties the selection. `paths` maps the
	 * token of an entry, which is what a request names it by, to `{ path, exact, type, above }`:
	 * the text to show, whether that text is the stored name, file, dir, symlink or other,
	 * and the tokens of the directories above the entry. A restore brings back everything in
	 * a ticked directory, so the map never holds an entry together with a directory above it.
	 */
	const restoreSelection = { snapshot: null, paths: new Map() };

	// A restore refuses more paths than this (Selection::MAX_PATHS on the server).
	const MAX_SELECTED_PATHS = 256;

	function formatStamp( text ) {
		// Snapshots carry nanoseconds, of which Date takes three digits.
		const date = new Date( String( text ).replace( /(\.\d{3})\d+/, '$1' ) );
		return Number.isNaN( date.getTime() ) ? String( text ) : date.toLocaleString();
	}

	/**
	 * Moves the keyboard to what a click brought up, because the control that was clicked
	 * is no longer on the page.
	 *
	 * @param {HTMLElement} node Element to focus.
	 */
	function focusOn( node ) {
		node.tabIndex = -1;
		node.focus();
	}

	/**
	 * What to show when a read of the repository failed. A lock is nobody's mistake and goes
	 * away by itself, so it gets its own words and a way to ask again.
	 *
	 * @param {Object}            error Error of a request.
	 * @param {(() => void)|null} retry Sends the request again; null where a control for that is on the page already.
	 * @return {Array} Nodes.
	 */
	function snapshotError( error, retry ) {
		if ( error && 'backups_for_restic_repository_locked' === error.code ) {
			const locked = notice( 'warning', __( 'The repository is locked at the moment, for example by a cleanup that is running. Its snapshots can be read again when that has finished.', 'backups-for-restic' ) );
			return retry ? [ locked, el( 'p', {}, [ button( __( 'Try again', 'backups-for-restic' ), retry ) ] ) ] : [ locked ];
		}
		return [ notice( 'error', errorText( error ) ) ];
	}

	/**
	 * A marker behind a name that is shown with replacement characters: the stored name has
	 * bytes that are no text, so what the page shows is not what a restore writes.
	 *
	 * @return {HTMLElement} Marker.
	 */
	function inexactMark() {
		return el( 'span', { className: 'description bfr-inexact' }, [ __( '(name shown approximately)', 'backups-for-restic' ) ] );
	}

	function tagList( tags ) {
		return el( 'ul', { className: 'bfr-tags' }, ( tags || [] ).map( function ( tag ) {
			return el( 'li', {}, [ tag ] );
		} ) );
	}

	/**
	 * A table of names and values. Rows without a value are left out.
	 *
	 * @param {string} className Class of the table.
	 * @param {Array}  rows      Pairs of a label and a node, a string or null.
	 * @return {HTMLElement} Table.
	 */
	function detailTable( className, rows ) {
		return el( 'table', { className: 'widefat striped bfr-details ' + className }, [
			el( 'tbody', {}, rows.filter( function ( row ) {
				return null !== row[ 1 ] && undefined !== row[ 1 ];
			} ).map( function ( [ label, value ] ) {
				return el( 'tr', {}, [ el( 'th', { scope: 'row' }, [ label ] ), el( 'td', {}, [ value ] ) ] );
			} ) ),
		] );
	}

	function textList( items ) {
		const texts = Array.isArray( items ) ? items : [];
		return texts.length ? el( 'ul', { className: 'bfr-paths' }, texts.map( function ( item ) {
			return el( 'li', {}, [ el( 'code', {}, [ item ] ) ] );
		} ) ) : '–';
	}

	/**
	 * The site that a snapshot holds, as its manifest describes it.
	 *
	 * @param {Object} snapshot Answer of the snapshot route.
	 * @return {Array} Nodes.
	 */
	function manifestView( snapshot ) {
		if ( 'unreadable' === snapshot.manifest_state ) {
			return [ notice( 'warning', __( 'The description of the site in this snapshot could not be read. Its files can still be browsed below.', 'backups-for-restic' ) ) ];
		}
		if ( 'found' !== snapshot.manifest_state || ! snapshot.manifest ) {
			return [ notice( 'info', __( 'This snapshot holds no description of a site: another program wrote it, or it does not contain a whole site. Its files can still be browsed below.', 'backups-for-restic' ) ) ];
		}
		const data = snapshot.manifest.data || {};
		const versions = data.versions || {};
		const text = function ( value ) {
			return 'string' === typeof value && '' !== value ? value : null;
		};
		let database = null;
		if ( 'boolean' === typeof data.database ) {
			database = data.database ? __( 'Included', 'backups-for-restic' ) : __( 'Not included', 'backups-for-restic' );
		}
		return [
			detailTable( 'bfr-manifest', [
				[ __( 'Site address', 'backups-for-restic' ), text( data.home_url ) ],
				[ __( 'WordPress version', 'backups-for-restic' ), text( versions.wordpress ) ],
				[ __( 'PHP version', 'backups-for-restic' ), text( versions.php ) ],
				[ __( 'Plugin version', 'backups-for-restic' ), text( versions.plugin ) ],
				[ __( 'Table prefix', 'backups-for-restic' ), text( data.prefix ) ? el( 'code', {}, [ data.prefix ] ) : null ],
				[ __( 'Database', 'backups-for-restic' ), database ],
				[ __( 'WordPress directory', 'backups-for-restic' ), text( data.abspath ) ? el( 'code', {}, [ data.abspath ] ) : null ],
				[ __( 'Content directory', 'backups-for-restic' ), text( data.content ) ? el( 'code', {}, [ data.content ] ) : null ],
				[ __( 'Uploads directory', 'backups-for-restic' ), text( data.uploads ) ? el( 'code', {}, [ data.uploads ] ) : null ],
				[ __( 'Directories in the backup', 'backups-for-restic' ), textList( data.roots ) ],
				[ __( 'Exclude patterns', 'backups-for-restic' ), textList( data.excludes ) ],
				[ __( 'Written', 'backups-for-restic' ), text( data.created ) ? formatStamp( data.created ) : null ],
				[ __( 'Stored in the snapshot as', 'backups-for-restic' ), text( snapshot.manifest.path ) ? el( 'code', {}, [ snapshot.manifest.path ] ) : null ],
			] ),
		];
	}

	/**
	 * What the retention settings would do with a snapshot at the next cleanup.
	 *
	 * @param {string|null} retention keep, remove, or null where no cleanup of this site decides.
	 * @return {string} Text.
	 */
	function retentionText( retention ) {
		if ( 'keep' === retention ) {
			return __( 'Will be kept', 'backups-for-restic' );
		}
		if ( 'remove' === retention ) {
			return __( 'Will be removed at the next cleanup', 'backups-for-restic' );
		}
		return '–';
	}

	/**
	 * The list of the ticked paths. It is drawn again whenever the selection changes.
	 *
	 * @param {Object}     snapshot Snapshot details and manifest.
	 * @param {() => void} changed  Called after the list removed something from the selection.
	 * @return {Object} The element as `box`, and `render()`.
	 */
	function selectionSummary( snapshot, changed ) {
		const box = el( 'div', { className: 'bfr-selection' } );
		const message = el( 'div' );
		const archive = el( 'input', { type: 'checkbox' } );
		const preview = el( 'input', { type: 'checkbox' } );
		const manifest = snapshot.manifest;
		const database = manifest && manifest.data && manifest.data.database && manifest.path
			? manifest.path.slice( 0, manifest.path.lastIndexOf( '/' ) + 1 ) + 'database' : '';
		let submitting = false;
		function render() {
			const count = restoreSelection.paths.size;
			const heading = el( 'h3', {}, [ __( 'Selected for a restore', 'backups-for-restic' ) ] );
			if ( ! count ) {
				const whole = button( __( 'Select entire snapshot', 'backups-for-restic' ), function () {
					restoreSelection.paths.set( null, { path: '/', exact: true, type: 'dir', above: [] } );
					render();
					changed();
				} );
				replace( box, [ heading, el( 'p', {}, [ __( 'Nothing is selected. Tick files or directories above to choose what a restore brings back.', 'backups-for-restic' ) ] ), whole ] );
				return;
			}
			const entries = Array.from( restoreSelection.paths.values() );
			const exact = entries.every( ( chosen ) => chosen.exact );
			const includesDatabase = database && entries.some( ( chosen ) => '/' === chosen.path || database === chosen.path || database.startsWith( chosen.path + '/' ) );
			if ( ! includesDatabase ) {
				preview.checked = false;
			}
			const restore = button( __( 'Restore to staging', 'backups-for-restic' ), function () {
				submitting = true;
				restore.disabled = true;
				api( '/jobs', { method: 'POST', data: {
					type: 'restore', snapshot: restoreSelection.snapshot, paths: entries.map( ( chosen ) => chosen.path ), archive: archive.checked, database_preview: preview.checked,
				} } ).then( function ( response ) {
					window.location.assign( config.pageUrl + '&tab=restores&job=' + encodeURIComponent( response.job.id ) );
				} ).catch( function ( error ) {
					submitting = false;
					render();
					replace( message, [ notice( 'error', errorText( error ) ) ] );
				} );
			}, true );
			restore.disabled = submitting || ! exact;
			const clear = button( __( 'Clear selection', 'backups-for-restic' ), function () {
				restoreSelection.paths.clear();
				render();
				changed();
				focusOn( box.querySelector( 'h3' ) );
			} );
			replace( box, [
				heading,
				el( 'p', {}, [
					/* translators: %d: number of files and directories. */
					sprintf( _n( '%d path selected:', '%d paths selected:', count, 'backups-for-restic' ), count ),
				] ),
				el( 'ul', { className: 'bfr-paths' }, entries.map( function ( chosen ) {
					return el( 'li', {}, [ el( 'code', {}, [ chosen.path ] ), chosen.exact ? null : ' ', chosen.exact ? null : inexactMark() ] );
				} ) ),
				count >= MAX_SELECTED_PATHS ? el( 'p', { className: 'bfr-selection-full' }, [
					/* translators: %d: number of files and directories. */
					sprintf( __( 'A restore takes at most %d paths, so nothing more can be ticked. Tick a directory instead of the entries in it.', 'backups-for-restic' ), MAX_SELECTED_PATHS ),
				] ) : null,
				el( 'p', {}, [ el( 'label', {}, [ archive, ' ', __( 'Build a ZIP for download (requires additional disk space).', 'backups-for-restic' ) ] ) ] ),
				includesDatabase ? el( 'p', {}, [ el( 'label', {}, [ preview, ' ', __( 'Rehearse the database import in temporary tables.', 'backups-for-restic' ) ] ) ] ) : null,
				el( 'p', {}, [ __( 'Staging writes a private copy of the selected files for inspection or download.', 'backups-for-restic' ) ] ),
				exact ? null : notice( 'warning', __( 'To restore a name shown approximately, select its containing directory instead.', 'backups-for-restic' ) ),
				el( 'p', {}, [ restore, ' ', clear ] ), message,
			] );
		}
		render();
		return { box, render };
	}

	/**
	 * The directories of a snapshot, one per request. A directory is asked for by the token
	 * that the server gave for it, never by a path put together from names: a name may hold
	 * bytes that are no text. Links are listed with their target and lead nowhere.
	 *
	 * @param {string}     id      Full snapshot ID.
	 * @param {() => void} changed Called after a checkbox changed the selection.
	 * @return {Object} The element as `box`, `open()` for the top directory, and `sync()` after the selection changed elsewhere.
	 */
	function directoryBrowser( id, changed ) {
		const box = el( 'div', { className: 'bfr-browser' } );
		const types = {
			file: __( 'File', 'backups-for-restic' ),
			dir: __( 'Directory', 'backups-for-restic' ),
			symlink: __( 'Link', 'backups-for-restic' ),
			other: __( 'Special file', 'backups-for-restic' ),
		};
		// Counts the directories asked for: an answer that arrives after the next click is not shown.
		let asked = 0;
		// The tokens of the open directory and of those above it, for sync().
		let shownAbove = [];

		/**
		 * Whether a ticked directory already holds everything below these directories. A
		 * restore brings such entries back whatever their own boxes say, so the boxes say it too.
		 *
		 * @param {Array} above Tokens of directories, the top one first.
		 * @return {boolean} Whether one of them is selected.
		 */
		function covered( above ) {
			return above.some( function ( token ) {
				return restoreSelection.paths.has( token );
			} );
		}

		function request( token, offset ) {
			const query = [];
			if ( token ) {
				query.push( 'path=' + encodeURIComponent( token ) );
			}
			if ( offset ) {
				query.push( 'offset=' + encodeURIComponent( offset ) );
			}
			return api( '/snapshots/' + encodeURIComponent( id ) + '/tree' + ( query.length ? '?' + query.join( '&' ) : '' ) );
		}

		/**
		 * The way from the top directory to the open one; every step but the last goes back up.
		 *
		 * @param {Array} crumbs Directories as `{ name, exact, token }`, the top one first.
		 * @return {HTMLElement} Navigation.
		 */
		function trail( crumbs ) {
			return el( 'nav', { className: 'bfr-trail', 'aria-label': __( 'Path in the snapshot', 'backups-for-restic' ) }, [
				el( 'ol', {}, crumbs.map( function ( crumb, position ) {
					const label = 0 === position ? __( 'Top directory', 'backups-for-restic' ) : crumb.name;
					const mark = crumb.exact ? null : inexactMark();
					if ( position === crumbs.length - 1 ) {
						return el( 'li', { 'aria-current': 'location' }, [ el( 'strong', {}, [ label ] ), mark ? ' ' : null, mark ] );
					}
					const up = function () {
						open( crumbs.slice( 0, position + 1 ) );
					};
					return el( 'li', {}, [ el( 'button', { type: 'button', className: 'button-link', onClick: up }, [ label ] ), mark ? ' ' : null, mark ] );
				} ) ),
			] );
		}

		function row( entry, page, crumbs ) {
			const path = ( '/' === page.path ? '' : page.path ) + '/' + entry.name;
			const above = crumbs.map( function ( crumb ) {
				return crumb.token;
			} );
			const inside = covered( above );
			const select = el( 'input', {
				type: 'checkbox',
				checked: inside || restoreSelection.paths.has( entry.token ),
				disabled: inside,
				dataset: { token: entry.token },
				/* translators: %s: name of a file or directory. */
				'aria-label': sprintf( __( 'Select %s', 'backups-for-restic' ), entry.name ),
				onChange() {
					if ( select.checked ) {
						// What lies below this entry comes back with it and is not counted twice.
						restoreSelection.paths.forEach( function ( chosen, token ) {
							if ( ( chosen.above || [] ).includes( entry.token ) ) {
								restoreSelection.paths.delete( token );
							}
						} );
						if ( restoreSelection.paths.size >= MAX_SELECTED_PATHS ) {
							select.checked = false;
						} else {
							restoreSelection.paths.set( entry.token, { path, exact: !! ( page.exact && entry.exact ), type: entry.type, above } );
						}
					} else {
						restoreSelection.paths.delete( entry.token );
					}
					changed();
				},
			} );
			const enter = function () {
				open( crumbs.concat( [ { name: entry.name, exact: entry.exact, token: entry.token } ] ) );
			};
			// Only a directory leads further. A link is shown, never followed: its target is text.
			const name = 'dir' === entry.type ? el( 'button', { type: 'button', className: 'button-link', onClick: enter }, [ entry.name ] ) : entry.name;
			return el( 'tr', {}, [
				el( 'td', { className: 'bfr-select' }, [ select ] ),
				el( 'td', { className: 'bfr-name' }, [
					name,
					'symlink' === entry.type && null !== entry.target ? ' → ' + entry.target : null,
					entry.exact ? null : ' ',
					entry.exact ? null : inexactMark(),
					entry.incomplete ? ' ' : null,
					entry.incomplete ? el( 'span', { className: 'description' }, [ __( '(saved with a read error)', 'backups-for-restic' ) ] ) : null,
				] ),
				el( 'td', {}, [ types[ entry.type ] || types.other ] ),
				el( 'td', {}, [ 'file' === entry.type ? formatBytes( entry.size ) : '–' ] ),
				el( 'td', {}, [ entry.mtime ? formatStamp( entry.mtime ) : '–' ] ),
			] );
		}

		function show( crumbs, first ) {
			const rows = el( 'tbody' );
			const shown = el( 'p', { className: 'description bfr-shown' } );
			const more = el( 'div' );
			const note = el( 'p', { className: 'description bfr-covered' }, [ __( 'This directory lies in a selected directory: a restore brings back everything in it.', 'backups-for-restic' ) ] );
			let count = 0;
			shownAbove = crumbs.map( function ( crumb ) {
				return crumb.token;
			} );
			note.hidden = ! covered( shownAbove );
			function add( page ) {
				page.entries.forEach( function ( entry ) {
					rows.append( row( entry, first, crumbs ) );
				} );
				count += page.entries.length;
				replace( shown, [
					/* translators: 1: number of entries shown, 2: number of entries in the directory. */
					sprintf( _n( '%1$d of %2$d entry shown.', '%1$d of %2$d entries shown.', page.total, 'backups-for-restic' ), count, page.total ),
				] );
				if ( null === page.next ) {
					replace( more, [] );
					return;
				}
				const load = button( __( 'Load more entries', 'backups-for-restic' ), function () {
					const current = asked;
					load.disabled = true;
					request( first.token, page.next )
						.then( function ( next ) {
							if ( current === asked ) {
								const before = rows.children.length;
								add( next );
								// The button went away with the page it asked for: the keyboard goes on at the first new entry.
								focusOn( rows.children[ before ] || shown );
							}
						} )
						.catch( function ( error ) {
							if ( current === asked ) {
								load.disabled = false;
								replace( more, [ ...snapshotError( error, null ), el( 'p', {}, [ load ] ) ] );
								// A disabled button loses the focus; it is the control to press again.
								load.focus();
							}
						} );
				} );
				replace( more, [ el( 'p', {}, [ load ] ) ] );
			}
			const path = trail( crumbs );
			if ( ! first.entries.length && ! first.total ) {
				replace( box, [ path, el( 'p', {}, [ __( 'This directory is empty.', 'backups-for-restic' ) ] ) ] );
			} else {
				add( first );
				replace( box, [
					path,
					note,
					el( 'table', { className: 'widefat striped bfr-entries' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', { scope: 'col', className: 'bfr-select' }, [ el( 'span', { className: 'screen-reader-text' }, [ __( 'Select', 'backups-for-restic' ) ] ) ] ),
								el( 'th', { scope: 'col' }, [ __( 'Name', 'backups-for-restic' ) ] ),
								el( 'th', { scope: 'col' }, [ __( 'Type', 'backups-for-restic' ) ] ),
								el( 'th', { scope: 'col' }, [ __( 'Size', 'backups-for-restic' ) ] ),
								el( 'th', { scope: 'col' }, [ __( 'Changed', 'backups-for-restic' ) ] ),
							] ),
						] ),
						rows,
					] ),
					shown,
					more,
				] );
			}
			return path;
		}

		function open( crumbs, initial ) {
			const current = ++asked;
			const token = crumbs[ crumbs.length - 1 ].token;
			replace( box, [ trail( crumbs ), el( 'p', {}, [ __( 'Loading the directory…', 'backups-for-restic' ) ] ) ] );
			request( token, 0 )
				.then( function ( page ) {
					if ( current !== asked ) {
						return;
					}
					// The top directory is asked for without a token and learns its own here.
					const known = crumbs.slice( 0, -1 ).concat( [ Object.assign( {}, crumbs[ crumbs.length - 1 ], { token: page.token } ) ] );
					const path = show( known, page );
					if ( ! initial ) {
						focusOn( path.querySelector( '[aria-current]' ) );
					}
				} )
				.catch( function ( error ) {
					if ( current === asked ) {
						replace( box, [ trail( crumbs ), ...snapshotError( error, function () {
							open( crumbs, initial );
						} ) ] );
					}
				} );
		}

		return {
			box,
			open() {
				open( [ { name: '/', exact: true, token: null } ], true );
			},
			sync() {
				const inside = covered( shownAbove );
				box.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( select ) {
					select.checked = inside || restoreSelection.paths.has( select.dataset.token );
					select.disabled = inside;
				} );
				const note = box.querySelector( '.bfr-covered' );
				if ( note ) {
					note.hidden = ! inside;
				}
			},
		};
	}

	/**
	 * One snapshot: its details, the site it holds, and its directories with the selection.
	 *
	 * @param {Element}    root    Where to show it.
	 * @param {Object}     summary The snapshot as the list has it.
	 * @param {() => void} back    Shows the list again.
	 */
	function snapshotView( root, summary, back ) {
		const body = el( 'div' );
		const heading = el( 'h2', {}, [
			/* translators: %s: snapshot ID. */
			sprintf( __( 'Snapshot %s', 'backups-for-restic' ), summary.short_id ),
		] );
		if ( restoreSelection.snapshot !== summary.id ) {
			restoreSelection.snapshot = summary.id;
			restoreSelection.paths.clear();
		}
		function load() {
			replace( body, [ el( 'p', {}, [ __( 'Loading the snapshot…', 'backups-for-restic' ) ] ) ] );
			api( '/snapshots/' + encodeURIComponent( summary.id ) )
				.then( function ( response ) {
					const snapshot = response.snapshot;
					let browser = null;
					const selection = selectionSummary( snapshot, function () {
						browser.sync();
					} );
					browser = directoryBrowser( snapshot.id, selection.render );
					replace( body, [
						detailTable( 'bfr-snapshot', [
							[ __( 'Time', 'backups-for-restic' ), formatStamp( snapshot.time ) ],
							[ __( 'ID', 'backups-for-restic' ), el( 'code', {}, [ snapshot.id ] ) ],
							[ __( 'Host', 'backups-for-restic' ), snapshot.hostname ],
							[ __( 'Tags', 'backups-for-restic' ), ( snapshot.tags || [] ).length ? tagList( snapshot.tags ) : '–' ],
							[ __( 'Paths', 'backups-for-restic' ), textList( snapshot.paths ) ],
							[ __( 'Files', 'backups-for-restic' ), null === snapshot.files ? '–' : String( snapshot.files ) ],
							[ __( 'Size', 'backups-for-restic' ), formatBytes( snapshot.bytes ) ],
							[ __( 'New data', 'backups-for-restic' ), formatBytes( snapshot.data_added ) ],
							[ __( 'Next cleanup', 'backups-for-restic' ), summary.retention ? retentionText( summary.retention ) : null ],
						] ),
						el( 'h3', {}, [ __( 'Site in this snapshot', 'backups-for-restic' ) ] ),
						...manifestView( snapshot ),
						el( 'h3', {}, [ __( 'Files', 'backups-for-restic' ) ] ),
						browser.box,
						selection.box,
					] );
					browser.open();
				} )
				.catch( function ( error ) {
					replace( body, snapshotError( error, load ) );
				} );
		}
		replace( root, [
			el( 'p', {}, [ button( __( 'Back to the snapshots', 'backups-for-restic' ), back ) ] ),
			heading,
			body,
		] );
		focusOn( heading );
		load();
	}

	/**
	 * Submits the short-lived download grant directly to WordPress so the browser can
	 * stream large files to disk. The grant travels in the body of the request.
	 *
	 * @param {Object} grant Response of the download route.
	 */
	function submitDownload( grant ) {
		const form = el( 'form', { method: 'post', action: grant.url } );
		Object.entries( grant.fields ).forEach( function ( [ name, value ] ) {
			form.append( el( 'input', { type: 'hidden', name, value } ) );
		} );
		document.body.append( form );
		form.submit();
		form.remove();
	}

	/**
	 * Pages a reviewed table, keeping its controls and status in place while rows change.
	 *
	 * @param {Object}                                     first  First page of rows.
	 * @param {(offset: number) => Object|Promise<Object>} load   Reads a page at the requested offset.
	 * @param {(page: Object) => void}                     show   Replaces the visible table rows.
	 * @param {Object}                                     labels Translated labels and page summary.
	 * @return {Element} Paging controls.
	 */
	function pageControls( first, load, show, labels ) {
		const status = el( 'p', { role: 'status', 'aria-atomic': 'true' } );
		const previous = button( labels.previous, () => move( true ) );
		const next = button( labels.next, () => move( false ) );
		const visited = [ 0 ];
		let page = first;
		let busy = false;
		function display() {
			show( page );
			previous.hidden = next.hidden = 1 === visited.length && null === page.next_offset;
			previous.disabled = 1 === visited.length;
			next.disabled = null === page.next_offset;
			status.textContent = labels.summary( page );
		}
		async function move( back ) {
			if ( busy ) {
				return;
			}
			busy = true;
			const trigger = back ? previous : next;
			const alternative = back ? next : previous;
			const offset = back ? visited[ visited.length - 2 ] : page.next_offset;
			previous.setAttribute( 'aria-disabled', 'true' );
			next.setAttribute( 'aria-disabled', 'true' );
			try {
				const result = await load( offset );
				const keepFocus = trigger.ownerDocument.activeElement === trigger;
				page = result;
				if ( back ) {
					visited.pop();
				} else {
					visited.push( offset );
				}
				display();
				if ( keepFocus && trigger.disabled ) {
					alternative.focus();
				}
			} catch ( error ) {
				status.textContent = errorText( error );
			} finally {
				busy = false;
				previous.removeAttribute( 'aria-disabled' );
				next.removeAttribute( 'aria-disabled' );
			}
		}
		display();
		return el( 'div', {}, [ status, previous, ' ', next ] );
	}

	/**
	 * Displays a bounded page of file actions. Paths are text, including when they
	 * contain characters significant in HTML.
	 *
	 * @param {Object}                              plan    Initial reviewed page.
	 * @param {(offset: number) => Promise<Object>} getPage Fetches a page of the same completed background plan.
	 * @param {boolean}                             fresh   Whether content roots are mapped onto a new installation.
	 * @return {Element} Table and paging controls.
	 */
	function fileActions( plan, getPage, fresh ) {
		const actions = {
			create: __( 'Create file', 'backups-for-restic' ), replace: __( 'Replace file', 'backups-for-restic' ),
			mkdir: __( 'Create directory', 'backups-for-restic' ), keep: __( 'Keep', 'backups-for-restic' ),
			skip: __( 'Skip', 'backups-for-restic' ), refuse: __( 'Refuse', 'backups-for-restic' ),
			remove: __( 'Remove', 'backups-for-restic' ), restore: __( 'Restore original', 'backups-for-restic' ),
		};
		const reasons = {
			protected: __( 'Protected plugin, configuration or private path', 'backups-for-restic' ),
			offline_restore_required: __( 'Restore this WordPress startup file offline using the recovery kit.', 'backups-for-restic' ),
			unverified_name: __( 'This name may match a protected path. Comparing it needs the PHP intl extension.', 'backups-for-restic' ),
			snapshot_spool: __( 'Private database dump and site manifest of a backup', 'backups-for-restic' ),
			outside_roots: __( 'Outside the live site directories', 'backups-for-restic' ),
			unavailable_destination_root: __( 'The live destination directory is unavailable.', 'backups-for-restic' ),
			invalid_path: __( 'Invalid file name or path', 'backups-for-restic' ),
			staging_unavailable: __( 'Staging is unavailable', 'backups-for-restic' ),
			missing_or_linked_source: __( 'Missing or linked staged entry', 'backups-for-restic' ),
			unsafe_source: __( 'Unsupported staged file type', 'backups-for-restic' ),
			ambiguous_source: __( 'Conflicting staged file names', 'backups-for-restic' ),
			root_ancestor: __( 'Parent directory of the live site', 'backups-for-restic' ),
			root_ancestor_not_directory: __( 'Expected a parent directory', 'backups-for-restic' ),
			live_root_changed: __( 'Live site directory changed', 'backups-for-restic' ),
			live_root: __( 'Live site directory', 'backups-for-restic' ),
			type_conflict: __( 'Staged and live file types differ', 'backups-for-restic' ),
			ambiguous_destination: __( 'Conflicting names at the destination', 'backups-for-restic' ),
			linked_destination: __( 'Destination contains a symbolic link', 'backups-for-restic' ),
			unwritable_destination: __( 'Destination is read-only', 'backups-for-restic' ),
			foreign_owner: __( 'The live file belongs to another user than PHP, so its owner could not be kept', 'backups-for-restic' ),
			foreign_group: __( 'PHP cannot give the replacement the group of the live file', 'backups-for-restic' ),
			special_permissions: __( 'The live file has setuid, setgid or sticky permissions', 'backups-for-restic' ),
			unsafe_destination_parent: __( 'Unsafe parent directory', 'backups-for-restic' ),
			missing_destination: __( 'New path on the live site', 'backups-for-restic' ),
			existing_directory: __( 'Directory already exists', 'backups-for-restic' ),
			existing_file: __( 'File already exists', 'backups-for-restic' ),
			unstaged_special_entry: __( 'Special entry recorded in the restore report', 'backups-for-restic' ),
			changed_since_apply: __( 'Live file changed after the apply job', 'backups-for-restic' ),
			undo_journal: __( 'Still as the apply job left it', 'backups-for-restic' ),
			installed_content: __( 'Another copy with the content and attributes that the apply job installed', 'backups-for-restic' ),
			not_applied: __( 'The apply job did not change this entry', 'backups-for-restic' ),
			already_undone: __( 'Already undone', 'backups-for-restic' ),
			removed_since_apply: __( 'Removed after the apply job', 'backups-for-restic' ),
			deleted_since_apply: __( 'Deleted after the apply job', 'backups-for-restic' ),
			parent_changed: __( 'The folder that holds it was replaced or moved after the apply job', 'backups-for-restic' ),
			new_children: __( 'The folder holds entries that this undo keeps', 'backups-for-restic' ),
			destination_changed: __( 'The site now maps this snapshot path to another live path', 'backups-for-restic' ),
			original_unavailable: __( 'The saved original copy is damaged', 'backups-for-restic' ),
		};
		const remote = !! plan.plan_job;
		const mapped = fresh || remote || plan.entries.some( ( entry ) => 'source' in entry && entry.source !== entry.destination );
		const total = remote ? plan.total : plan.entries.length;
		const rows = el( 'tbody' );
		function localPage( offset ) {
			return { entries: plan.entries.slice( offset, offset + 50 ), offset, next_offset: offset + 50 < total ? offset + 50 : null };
		}
		function render( page ) {
			replace( rows, page.entries.map( function ( entry ) {
				return el( 'tr', {}, [
					el( 'td', {}, [ actions[ entry.action ] || entry.action ] ),
					mapped ? el( 'td', {}, [ el( 'code', {}, [ entry.source ] ) ] ) : null,
					el( 'td', {}, [ el( 'code', {}, [ mapped ? ( entry.destination || '–' ) : entry.path ] ) ] ),
					el( 'td', {}, [ reasons[ entry.reason ] || entry.reason ] ),
				] );
			} ) );
		}
		async function load( offset ) {
			const page = remote ? await getPage( offset ) : localPage( offset );
			if ( remote && ( page.plan_job !== plan.plan_job || page.digest !== plan.digest || page.offset !== offset ) ) {
				throw new Error( __( 'Review a fresh preview of these file changes.', 'backups-for-restic' ) );
			}
			return page;
		}
		const paging = pageControls( remote ? plan : localPage( 0 ), load, render, {
			previous: __( 'Previous file changes', 'backups-for-restic' ),
			next: __( 'Next file changes', 'backups-for-restic' ),
			/* translators: 1: first file action on this page, 2: last action shown, 3: total actions. */
			summary: ( page ) => total ? sprintf( __( 'Showing file changes %1$d–%2$d of %3$d.', 'backups-for-restic' ), page.offset + 1, page.offset + page.entries.length, total ) : __( 'No file changes.', 'backups-for-restic' ),
		} );
		return el( 'div', { className: 'bfr-file-actions' }, [
			el( 'table', { className: 'widefat striped' }, [
				el( 'thead', {}, [ el( 'tr', {}, [
					el( 'th', { scope: 'col' }, [ __( 'Action', 'backups-for-restic' ) ] ),
					mapped ? el( 'th', { scope: 'col' }, [ __( 'Snapshot path', 'backups-for-restic' ) ] ) : null,
					el( 'th', { scope: 'col' }, [ __( 'Live path', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Reason', 'backups-for-restic' ) ] ),
				] ) ] ), rows,
			] ), paging,
		] );
	}

	/**
	 * Confirms a reviewed live change without retaining a password or exposing it in a URL.
	 *
	 * @param {Object} plan    Authorized preview.
	 * @param {Object} options Submit label, endpoint, operation-specific arguments and UI callbacks.
	 * @return {Element} Confirmation form.
	 */
	function reviewConfirmation( plan, options ) {
		const reviewed = el( 'input', { type: 'checkbox' } );
		const typed = el( 'input', { type: 'text', className: 'regular-text code', autocomplete: 'off' } );
		const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password' } );
		const message = el( 'div', { role: 'status' } );
		const submit = button( options.submitLabel, function () {
			submit.disabled = true;
			options.busy( true );
			api( options.path, { method: 'POST', data: {
				...options.data, reviewed: reviewed.checked, site_url: typed.value, user_password: password.value,
				digest: plan.digest, review_token: plan.review_token,
			} } ).then( function ( response ) {
				if ( options.submitted ) {
					options.submitted();
				}
				window.location.assign( config.pageUrl + '&tab=restores&job=' + encodeURIComponent( response.job.id ) );
			} ).catch( function ( error ) {
				password.value = '';
				options.busy( false );
				if ( error.data && 409 === error.data.status ) {
					options.stale( error );
				} else {
					submit.disabled = false;
					replace( message, [ notice( 'error', errorText( error ), false ) ] );
					if ( ! reviewed.checked ) {
						reviewed.focus();
					} else if ( typed.value !== plan.site_url ) {
						typed.focus();
					} else {
						password.focus();
					}
				}
			} );
		} );
		return el( 'div', {}, [
			el( 'p', {}, [ el( 'label', {}, [ reviewed, ' ', __( 'I have reviewed these changes to the live site.', 'backups-for-restic' ) ] ) ] ),
			field( __( 'Type the site address to confirm', 'backups-for-restic' ), typed, plan.site_url ),
			field( __( 'Your WordPress password', 'backups-for-restic' ), password ),
			submit, message,
		] );
	}

	function liveChange( id, undo, fresh = false ) {
		const base = ( undo ? '/undo/' : '/restores/' ) + encodeURIComponent( id );
		const report = el( 'div' );
		const status = el( 'p', { role: 'status', className: 'screen-reader-text' } );
		const planKey = 'backups-for-restic-plan:' + config.pageUrl + ':' + base;
		let shown = false;
		function remember( job ) {
			try {
				if ( job ) {
					window.sessionStorage.setItem( planKey, job );
				} else {
					window.sessionStorage.removeItem( planKey );
				}
			} catch {
				// Browsing with storage disabled still supports the complete review in this tab.
			}
		}
		function remembered() {
			try {
				const idOfPlan = window.sessionStorage.getItem( planKey );
				return idOfPlan && JOB_ID.test( idOfPlan ) ? idOfPlan : null;
			} catch {
				return null;
			}
		}
		function busy( active ) {
			preview.setAttribute( 'aria-disabled', String( active ) );
		}
		const preview = button( undo ? __( 'Preview undo', 'backups-for-restic' ) : __( 'Preview live file changes', 'backups-for-restic' ), function () {
			if ( 'true' === preview.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			const resume = shown ? null : remembered();
			shown = true;
			busy( true );
			status.textContent = __( 'Inspecting files…', 'backups-for-restic' );
			replace( report, [ el( 'p', {}, [ __( 'Inspecting files…', 'backups-for-restic' ) ] ) ] );

			function fetchPage( job = null, offset = 0 ) {
				return api( base + ( undo ? '/plan' : '/apply-plan' ), { method: 'POST', data: { plan_job: job, offset } } );
			}
			function failed( error ) {
				remember( null );
				busy( false );
				replace( report, [ notice( 'error', errorText( error ), false ) ] );
				status.textContent = errorText( error );
			}
			function track( idOfPlan ) {
				remember( idOfPlan );
				follow( idOfPlan, report, function ( job ) {
					if ( 'succeeded' === job.state ) {
						fetchPage( idOfPlan ).then( display ).catch( failed );
					} else {
						remember( null );
						busy( false );
						status.textContent = job.state_label;
					}
				}, failed );
			}
			function display( plan ) {
				if ( plan.job ) {
					track( plan.job.id );
					return;
				}
				const totals = undo && plan.totals ? plan.totals : null;
				const totalsLine = totals ? el( 'p', { className: 'bfr-undo-totals' }, [ sprintf(
					/* translators: 1: files to restore, 2: files and folders to remove, 3: entries to keep. */
					__( 'To restore: %1$d. To remove: %2$d. To keep: %3$d.', 'backups-for-restic' ), totals.restore, totals.remove, totals.keep,
				) ] ) : null;
				if ( undo && plan.undone ) {
					// The review recorded the apply as undone; there is nothing to preview again.
					remember( null );
					const done = __( 'Nothing is left to revert: every entry is as it was before the apply, or was removed since. The apply is now recorded as undone.', 'backups-for-restic' );
					status.textContent = done;
					replace( report, [ totalsLine, notice( 'success', done ) ] );
					return;
				}
				busy( false );
				status.textContent = false === plan.enough_space ? __( 'File preview ready. The destination needs more disk space.', 'backups-for-restic' ) : __( 'File preview ready.', 'backups-for-restic' );
				let refusal = __( 'This preview has no file changes that can be applied safely.', 'backups-for-restic' );
				if ( totals && 0 === totals.restore + totals.remove ) {
					refusal = __( 'Nothing of this apply can be undone now: every entry is already undone or is kept. The list gives the reason for each entry.', 'backups-for-restic' );
				}
				let controls;
				if ( false === plan.enough_space ) {
					controls = notice( 'warning', __( 'There is too little disk space for these file changes. Free space on the destination and open a new preview.', 'backups-for-restic' ) );
				} else if ( plan.applicable && plan.write_allowed ) {
					controls = reviewConfirmation( plan, {
						path: base + ( undo ? '' : '/apply' ),
						submitLabel: undo ? __( 'Undo live file changes', 'backups-for-restic' ) : __( 'Apply to live site', 'backups-for-restic' ),
						data: { plan_job: plan.plan_job || null },
						busy, submitted: () => remember( null ),
						stale( error ) {
							remember( null );
							replace( report, [ notice( 'warning', errorText( error ) ) ] );
							status.textContent = errorText( error );
							preview.focus();
						},
					} );
				} else {
					controls = notice( 'info', plan.applicable
						? __( 'This account or installation does not permit live file changes.', 'backups-for-restic' )
						: refusal );
				}
				replace( report, [
					'required_bytes' in plan ? detailTable( 'bfr-file-space', [
						[ __( 'Additional disk space needed at peak', 'backups-for-restic' ), 'number' === typeof plan.required_bytes ? formatBytes( plan.required_bytes ) : __( 'Open a new preview for an estimate.', 'backups-for-restic' ) ],
						[ __( 'Available disk space', 'backups-for-restic' ), 'number' === typeof plan.available_bytes ? formatBytes( plan.available_bytes ) : __( 'Unknown on this host', 'backups-for-restic' ) ],
					] ) : null,
					totalsLine,
					fileActions( plan, ( offset ) => fetchPage( plan.plan_job, offset ), fresh ),
					controls,
				] );
			}
			if ( resume ) {
				track( resume );
			} else {
				remember( null );
				fetchPage().then( display ).catch( failed );
			}
		} );

		return el( 'div', {}, [
			preview, status, report,
		] );
	}

	/**
	 * Opens the same fixed, expiring report as the attachment. Stream the decoded
	 * bytes so a large inventory can still be downloaded without filling the tab.
	 *
	 * @param {Object} grant Single-use POST grant.
	 * @return {Promise<Object>} Database rehearsal.
	 */
	async function readRehearsal( grant ) {
		const response = await window.fetch( grant.url, { method: 'POST', body: new window.URLSearchParams( grant.fields ), mode: 'same-origin', credentials: 'same-origin', redirect: 'error', cache: 'no-store' } );
		if ( ! response.ok || ! response.body ) {
			throw new Error( __( 'The database rehearsal report is unavailable.', 'backups-for-restic' ) );
		}
		const reader = response.body.getReader();
		const decoder = new window.TextDecoder();
		const chunks = [];
		let size = 0;
		try {
			for ( ;; ) {
				const { value, done } = await reader.read();
				if ( done ) {
					chunks.push( decoder.decode() );
					return JSON.parse( chunks.join( '' ) );
				}
				size += value.length;
				if ( size > 4 * 1024 * 1024 ) {
					await reader.cancel();
					throw new Error( __( 'Download the database rehearsal report to view this large inventory.', 'backups-for-restic' ) );
				}
				chunks.push( decoder.decode( value, { stream: true } ) );
			}
		} finally {
			reader.releaseLock();
		}
	}

	function databaseFindings() {
		return {
			write_locked_rename: __( 'MariaDB requires a manual live database restore on this server. Recover the SQL dump from the staging ZIP or recovery kit and import it into a separate database.', 'backups-for-restic' ),
			database_admin_collision: __( 'The snapshot has a different user with the same ID, login or email as the confirming administrator.', 'backups-for-restic' ),
			database_wordpress_version: __( 'The source and destination WordPress database schema versions differ.', 'backups-for-restic' ),
			database_exchange_changed: __( 'The destination table generations or administrator no longer match this review.', 'backups-for-restic' ),
			snapshot_check: __( 'The snapshot contains a CHECK constraint that needs manual handling.', 'backups-for-restic' ),
			wordpress_tables: __( 'The snapshot must include compatible WordPress options, users and user metadata tables.', 'backups-for-restic' ),
			wordpress_environment: __( 'This WordPress installation cannot perform an automatic database exchange.', 'backups-for-restic' ),
			database_account: __( 'This WordPress account does not have the required database restore permissions.', 'backups-for-restic' ),
			persistent_object_cache: __( 'A persistent object cache prevents a verified database exchange on this site.', 'backups-for-restic' ),
			check_constraints: __( 'Named CHECK constraints require manual handling.', 'backups-for-restic' ),
			live_definition: __( 'The definitions of live tables require manual review.', 'backups-for-restic' ),
			server_configuration: __( 'The database server configuration does not support a safe swap.', 'backups-for-restic' ),
			performance_schema: __( 'The database server does not list every connection, so the exchange cannot wait for requests that are still running. It needs MySQL 8.0.22 or later with the Performance Schema enabled and enough of its thread instances.', 'backups-for-restic' ),
			innodb_table_locks: __( 'InnoDB table locks are switched off for the database connection (innodb_table_locks).', 'backups-for-restic' ),
			binlog_format: __( 'Binary logging uses the statement format for the database connection (binlog_format), under which the exchange cannot copy the protected tables.', 'backups-for-restic' ),
			wsrep_on: __( 'The database server is a Galera cluster node, where table locks do not hold on the other nodes.', 'backups-for-restic' ),
			database_privileges: __( 'The database account lacks required privileges.', 'backups-for-restic' ),
			table_count: __( 'The number of tables is outside the supported range.', 'backups-for-restic' ),
			dictionary_names: __( 'Database table names cannot be resolved unambiguously.', 'backups-for-restic' ),
			dictionary_privilege: __( 'The account cannot inspect all database relationships.', 'backups-for-restic' ),
			foreign_keys: __( 'Foreign keys involve tables in the restore.', 'backups-for-restic' ),
			live_triggers: __( 'Live tables have triggers.', 'backups-for-restic' ),
			live_views: __( 'A database view uses a live table in this restore.', 'backups-for-restic' ),
			live_object_identity: __( 'The identity of a live database object could not be verified.', 'backups-for-restic' ),
			snapshot_triggers_unknown: __( 'The snapshot has no trigger inventory.', 'backups-for-restic' ),
			snapshot_triggers: __( 'The source table had triggers.', 'backups-for-restic' ),
			snapshot_view: __( 'The snapshot includes a view.', 'backups-for-restic' ),
			snapshot_engine: __( 'The source storage engine requires review.', 'backups-for-restic' ),
			live_object_type: __( 'The live object has a different type.', 'backups-for-restic' ),
			live_engine: __( 'The live storage engine requires review.', 'backups-for-restic' ),
		};
	}

	function databaseTables( name, labels, tables, cells ) {
		const rows = el( 'tbody' );
		function read( offset ) {
			return { entries: tables.slice( offset, offset + 50 ), offset, next_offset: offset + 50 < tables.length ? offset + 50 : null };
		}
		const paging = pageControls( read( 0 ), read, function ( page ) {
			replace( rows, page.entries.map( ( table ) => el( 'tr', {}, cells( table ).map( ( value ) => el( 'td', {}, [ value ] ) ) ) ) );
		}, {
			previous: __( 'Previous tables', 'backups-for-restic' ), next: __( 'Next tables', 'backups-for-restic' ),
			/* translators: 1: first table on this page, 2: last table shown, 3: total tables in the report. */
			summary: ( page ) => tables.length ? sprintf( __( 'Showing tables %1$d–%2$d of %3$d.', 'backups-for-restic' ), page.offset + 1, page.offset + page.entries.length, tables.length ) : __( 'No tables in this report.', 'backups-for-restic' ),
		} );
		return el( 'div', {}, [ el( 'table', { className: 'widefat striped ' + name }, [
			el( 'thead', {}, [ el( 'tr', {}, labels.map( ( label ) => el( 'th', { scope: 'col' }, [ label ] ) ) ) ] ), rows,
		] ), paging ] );
	}

	function rehearsalView( report ) {
		const labels = databaseFindings();
		const preflight = report.swap_preflight || {};
		const findings = new Map();
		( report.findings || [] ).forEach( ( item ) => {
			const table = item.table || '';
			const group = findings.get( table ) || [];
			group.push( labels[ item.code ] || item.code );
			findings.set( table, group );
		} );
		const head = [ __( 'Table', 'backups-for-restic' ), __( 'Verified source rows', 'backups-for-restic' ), __( 'Estimated live rows', 'backups-for-restic' ), __( 'Source engine and collation', 'backups-for-restic' ), __( 'Live engine and collation', 'backups-for-restic' ), __( 'Findings', 'backups-for-restic' ) ];
		const tables = databaseTables( 'bfr-database-tables', head, report.tables, function ( table ) {
			const source = [ table.snapshot_engine, table.snapshot_charset, table.snapshot_collation ].filter( Boolean ).join( ' · ' );
			const live = [ table.live_engine, table.live_charset, table.live_collation ].filter( Boolean ).join( ' · ' );
			return [ table.name, table.snapshot_rows ?? '–', table.live_rows_estimate ?? '–', source || '–', live || '–', ( findings.get( table.name ) || [] ).join( ' ' ) ];
		} );
		const issues = ( preflight.blockers || [] ).map( ( code ) => labels[ code ] || code ).concat( findings.get( '' ) || [] );
		return el( 'div', {}, [
			detailTable( 'bfr-database-versions', [
				[ __( 'Source database server', 'backups-for-restic' ), report.snapshot_server ],
				[ __( 'Destination database server', 'backups-for-restic' ), report.destination_server ],
				[ __( 'Verified source rows', 'backups-for-restic' ), report.rows ],
				[ __( 'Views excluded from rehearsal', 'backups-for-restic' ), ( report.views_not_imported || [] ).join( ', ' ) || null ],
				[ __( 'Missing database privileges', 'backups-for-restic' ), ( preflight.missing_privileges || [] ).join( ', ' ) || null ],
			] ),
			issues.length ? notice( 'warning', issues.join( ' ' ) ) : null,
			tables,
		] );
	}

	function databaseRehearsal( endpoint ) {
		const body = el( 'div' );
		const status = el( 'p', { role: 'status', className: 'screen-reader-text' } );
		const label = __( 'Inspect database rehearsal', 'backups-for-restic' );
		let loading = false;
		const open = button( label, function () {
			if ( loading ) {
				return;
			}
			if ( 'true' === open.getAttribute( 'aria-expanded' ) ) {
				replace( body, [] );
				open.setAttribute( 'aria-expanded', 'false' );
				open.textContent = label;
				status.textContent = '';
				return;
			}
			loading = true;
			open.setAttribute( 'aria-disabled', 'true' );
			status.textContent = __( 'Loading the database rehearsal report…', 'backups-for-restic' );
			api( endpoint + '/download', { method: 'POST', data: { kind: 'database_report' } } )
				.then( readRehearsal ).then( function ( report ) {
					replace( body, [ rehearsalView( report ) ] );
					open.textContent = __( 'Close database rehearsal', 'backups-for-restic' );
					open.setAttribute( 'aria-expanded', 'true' );
					status.textContent = __( 'Database rehearsal report opened.', 'backups-for-restic' );
				} ).catch( function ( error ) {
					replace( body, [ notice( 'error', errorText( error ), false ) ] );
					status.textContent = errorText( error );
				} ).finally( function () {
					loading = false;
					open.removeAttribute( 'aria-disabled' );
				} );
		} );
		open.setAttribute( 'aria-expanded', 'false' );
		return el( 'div', {}, [ open, status, body ] );
	}

	/**
	 * Displays the database generations and compatibility checks that the service reviewed.
	 *
	 * @param {string}  id   Restore or original database apply.
	 * @param {boolean} undo Whether to bring back retained originals.
	 * @return {Element} Reviewed exchange.
	 */
	function databaseChange( id, undo ) {
		const base = ( undo ? '/database-changes/' : '/restores/' ) + encodeURIComponent( id );
		const body = el( 'div' );
		const status = el( 'p', { role: 'status', className: 'screen-reader-text' } );
		const preview = button( undo ? __( 'Preview database undo', 'backups-for-restic' ) : __( 'Preview live database changes', 'backups-for-restic' ), function () {
			if ( 'true' === preview.getAttribute( 'aria-disabled' ) ) {
				return;
			}
			busy( true );
			replace( body, [] );
			status.textContent = __( 'Inspecting database tables…', 'backups-for-restic' );
			api( base + ( undo ? '/undo-plan' : '/database/plan' ), { method: 'POST' } ).then( function ( plan ) {
				const labels = databaseFindings();
				const actions = {
					replace: __( 'Replace live table', 'backups-for-restic' ), create: __( 'Create live table', 'backups-for-restic' ),
					keep: __( 'Keep live table', 'backups-for-restic' ), remove_from_live: __( 'Remove table from the live database', 'backups-for-restic' ),
				};
				const canApply = plan.applicable && plan.write_allowed && plan.preserve_admin && plan.preserve_address && ! plan.blockers.length;
				const confirmation = canApply ? reviewConfirmation( plan, {
					submitLabel: undo ? __( 'Undo database restore', 'backups-for-restic' ) : __( 'Apply database restore', 'backups-for-restic' ),
					path: base + ( undo ? '/undo' : '/database/apply' ), data: {}, busy,
					stale( error ) {
						showError( error );
						preview.focus();
					},
				} ) : null;
				const table = databaseTables( 'bfr-database-changes', [ __( 'Table', 'backups-for-restic' ), __( 'Action', 'backups-for-restic' ) ], plan.entries,
					( entry ) => [ el( 'code', {}, [ entry.table ] ), actions[ entry.action ] || entry.action ] );
				replace( body, [
					plan.preserve_admin && plan.preserve_address ? el( 'p', {}, [ __( 'The exchange keeps this site address, your administrator account and its active sessions, and the backup configuration.', 'backups-for-restic' ) ] ) : null,
					plan.entries.length ? table : null,
					...plan.blockers.map( ( code ) => notice( 'warning', labels[ code ] || code ) ),
					plan.missing_privileges.length ? detailTable( 'bfr-database-privileges', [ [ __( 'Missing database privileges', 'backups-for-restic' ), plan.missing_privileges.join( ', ' ) ] ] ) : null,
					! plan.write_allowed ? notice( 'info', __( 'This account cannot apply or undo live database changes.', 'backups-for-restic' ) ) : null,
					! canApply && plan.write_allowed && ! plan.blockers.length ? notice( 'info', __( 'No database changes can be applied from this review.', 'backups-for-restic' ) ) : null,
					confirmation,
				] );
				status.textContent = __( 'Database preview ready.', 'backups-for-restic' );
			} ).catch( showError ).finally( () => busy( false ) );
		} );
		function busy( active ) {
			preview.setAttribute( 'aria-disabled', String( active ) );
		}
		function showError( error ) {
			replace( body, [ notice( 'error', errorText( error ), false ) ] );
			status.textContent = errorText( error );
		}
		return el( 'div', {}, [ preview, status, body ] );
	}

	function stagedRestore( staged, render ) {
		const endpoint = '/restores/' + encodeURIComponent( staged.job );
		const message = el( 'div' );
		const path = el( 'input', { type: 'text', className: 'regular-text code', value: staged.file || '', placeholder: '/wp-content/uploads/photo.jpg' } );
		// Restored files can be wp-config.php or the database, so they need the WordPress
		// password; the reports hold names and counts and do not.
		const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password' } );
		function download( label, kind ) {
			const content = [ 'file', 'archive' ].includes( kind );
			const control = button( label, function () {
				const data = { kind };
				if ( 'file' === kind ) {
					data.path = path.value;
				}
				if ( content ) {
					// Only in the JSON body of this one request, and gone from the form at once.
					data.user_password = password.value;
					password.value = '';
				}
				control.disabled = true;
				replace( message, [] );
				api( endpoint + '/download', { method: 'POST', data } )
					.then( submitDownload )
					.catch( function ( error ) {
						replace( message, [ notice( 'error', errorText( error ) ) ] );
						if ( content && error.data && [ 403, 429 ].includes( error.data.status ) ) {
							password.focus();
						}
					} )
					.finally( function () {
						control.disabled = false;
					} );
			} );
			return control;
		}
		const remove = button( __( 'Delete staged files', 'backups-for-restic' ), function () {
			const confirm = button( __( 'Confirm deletion', 'backups-for-restic' ), function () {
				confirm.disabled = true;
				keep.disabled = true;
				api( endpoint, { method: 'DELETE' } ).then( render ).catch( function ( error ) {
					remove.disabled = false;
					replace( message, [ notice( 'error', errorText( error ) ) ] );
				} );
			} );
			const keep = button( __( 'Keep files', 'backups-for-restic' ), function () {
				replace( message, [] );
				remove.disabled = false;
				remove.focus();
			} );
			remove.disabled = true;
			replace( message, [ el( 'p', {}, [ __( 'Delete the local copies and downloads for this restore?', 'backups-for-restic' ) ] ), confirm, ' ', keep ] );
			keep.focus();
		} );
		return el( 'section', { className: 'bfr-card' }, [
			el( 'h3', {}, [ staged.job ] ),
			detailTable( 'bfr-staging', [
				[ __( 'Snapshot', 'backups-for-restic' ), staged.snapshot ? el( 'code', {}, [ staged.snapshot ] ) : null ],
				[ __( 'Files', 'backups-for-restic' ), staged.files ],
				[ __( 'Size', 'backups-for-restic' ), formatBytes( staged.bytes ) ],
				[ __( 'Expires', 'backups-for-restic' ), formatTime( staged.expires ) ],
			] ),
			field( __( 'Absolute file path in the snapshot', 'backups-for-restic' ), path ),
			field( __( 'WordPress password for downloads', 'backups-for-restic' ), password, __( 'Your WordPress password, needed for every download of a restored file or the ZIP, which is recorded. The reports need no password.', 'backups-for-restic' ) ),
			el( 'p', {}, [
				download( __( 'Download file', 'backups-for-restic' ), 'file' ), ' ',
				staged.archive ? download( __( 'Download ZIP', 'backups-for-restic' ), 'archive' ) : null, ' ',
				staged.database_report ? download( __( 'Download database rehearsal report', 'backups-for-restic' ), 'database_report' ) : null, ' ',
				staged.report ? download( __( 'Download restore report', 'backups-for-restic' ), 'report' ) : null,
			] ),
			staged.database_report ? databaseRehearsal( endpoint ) : null,
			staged.database_report ? el( 'details', {}, [ el( 'summary', {}, [ __( 'Restore tables to the live database', 'backups-for-restic' ) ] ), databaseChange( staged.job, false ) ] ) : null,
			remove, message,
			el( 'details', {}, [ el( 'summary', {}, [ __( 'Restore files to the live site', 'backups-for-restic' ) ] ), liveChange( staged.job, false, staged.fresh_install ) ] ),
		] );
	}

	function restores( root ) {
		if ( '1' === new URL( window.location.href ).searchParams.get( 'import' ) ) {
			freshInstall( root );
			return;
		}
		const list = el( 'div' );
		const progress = el( 'div' );
		const history = el( 'div' );
		const databaseHistory = el( 'div' );
		let stagedVersion = null;
		function render( response ) {
			const version = JSON.stringify( response.restores );
			if ( version === stagedVersion ) {
				return;
			}
			stagedVersion = version;
			replace( list, response.restores.length
				? response.restores.map( ( staged ) => stagedRestore( staged, render ) )
				: [ el( 'p', {}, [ __( 'No staged files. Open a snapshot to restore files or directories.', 'backups-for-restic' ) ] ) ] );
		}
		function load() {
			api( '/undo' ).then( function ( response ) {
				const jobs = response.jobs;
				replace( history, jobs.length ? [
					el( 'h2', {}, [ __( 'Undo live file changes', 'backups-for-restic' ) ] ),
					...jobs.map( ( job ) => el( 'section', { className: 'bfr-card' }, [
						el( 'h3', {}, [ job.job ] ),
						el( 'p', {}, [
							/* translators: %s: date and time when the saved original files expire. */
							sprintf( __( 'Original files available until %s.', 'backups-for-restic' ), formatTime( job.expires ) ),
						] ),
						undoState( job ),
						liveChange( job.job, true ),
					] ) ),
				] : [] );
			} ).catch( function ( error ) {
				replace( history, [ notice( 'error', errorText( error ) ) ] );
			} );
			api( '/database-changes' ).then( function ( response ) {
				replace( databaseHistory, response.jobs.length ? [
					el( 'h2', {}, [ __( 'Undo live database changes', 'backups-for-restic' ) ] ),
					...response.jobs.map( ( job ) => el( 'section', { className: 'bfr-card' }, [
						el( 'h3', {}, [ job.job ] ),
						el( 'p', {}, [
							/* translators: %s: date and time when the previous live database tables expire. */
							sprintf( __( 'Previous database tables available until %s.', 'backups-for-restic' ), formatTime( job.expires ) ),
						] ),
						databaseChange( job.job, true ),
					] ) ),
				] : [] );
			} ).catch( function ( error ) {
				replace( databaseHistory, [ notice( 'error', errorText( error ) ) ] );
			} );
			api( '/restores' ).then( render ).catch( function ( error ) {
				stagedVersion = null;
				replace( list, [ notice( 'error', errorText( error ) ), button( __( 'Try again', 'backups-for-restic' ), load ) ] );
			} );
		}
		replace( root, [
			el( 'h2', {}, [ __( 'Staged restores', 'backups-for-restic' ) ] ),
			el( 'p', {}, [ el( 'a', { className: 'button', href: config.pageUrl + '&tab=restores&import=1' }, [ __( 'Restore from an existing site snapshot', 'backups-for-restic' ) ] ) ] ),
			progress, list, history, databaseHistory,
		] );
		load();
		const job = new URL( window.location.href ).searchParams.get( 'job' );
		if ( job && JOB_ID.test( job ) ) {
			follow( job, progress, load );
		} else {
			api( '/status' ).then( function ( status ) {
				if ( status.active_job && [ 'restore', 'file_plan', 'apply', 'undo', 'database_apply', 'database_undo' ].includes( status.active_job.type ) ) {
					follow( status.active_job.id, progress, function ( done ) {
						if ( 'file_plan' !== done.type ) {
							load();
						}
					} );
				}
			} ).catch( function ( error ) {
				replace( progress, [ notice( 'error', errorText( error ) ) ] );
			} );
		}
	}

	/**
	 * Lets a new installation choose a site from the connected repository. Staging
	 * includes a verified import into temporary database tables before live review.
	 *
	 * @param {Element} root Restores screen.
	 */
	function freshInstall( root ) {
		const body = el( 'div' );
		replace( root, [
			el( 'h2', {}, [ __( 'Restore from an existing site snapshot', 'backups-for-restic' ) ] ),
			el( 'p', {}, [ __( 'Choose the source site and backup time. You can review file destinations and the database rehearsal before applying changes.', 'backups-for-restic' ) ] ),
			body,
			el( 'p', {}, [ el( 'a', { href: config.pageUrl + '&tab=restores' }, [ __( 'Back to staged restores', 'backups-for-restic' ) ] ) ] ),
		] );

		function list() {
			replace( body, [ el( 'p', {}, [ __( 'Loading the snapshots…', 'backups-for-restic' ) ] ) ] );
			api( '/snapshots?scope=repository&fresh=1' ).then( function ( response ) {
				replace( body, response.snapshots.length
					? [ snapshotTable( response.snapshots, 'repository', select ) ]
					: [ notice( 'info', __( 'No snapshots yet.', 'backups-for-restic' ) ) ] );
			} ).catch( function ( error ) {
				replace( body, snapshotError( error, list ) );
			} );
		}

		function select( summary ) {
			replace( body, [ el( 'p', {}, [ __( 'Loading the snapshot…', 'backups-for-restic' ) ] ) ] );
			api( '/snapshots/' + encodeURIComponent( summary.id ) ).then( function ( response ) {
				const snapshot = response.snapshot;
				const data = snapshot.manifest && snapshot.manifest.data;
				const eligible = 'found' === snapshot.manifest_state && data && 'backups-for-restic-site/1' === data.format && true === data.database;
				const message = el( 'div' );
				const archive = el( 'input', { type: 'checkbox' } );
				const start = button( __( 'Stage site files and rehearse the database restore', 'backups-for-restic' ), function () {
					start.disabled = true;
					api( '/jobs', { method: 'POST', data: { type: 'restore', snapshot: snapshot.id, fresh_install: true, database_preview: true, archive: archive.checked } } )
						.then( function ( result ) {
							window.location.assign( config.pageUrl + '&tab=restores&job=' + encodeURIComponent( result.job.id ) );
						} ).catch( function ( error ) {
							start.disabled = false;
							replace( message, [ notice( 'error', errorText( error ) ) ] );
						} );
				}, true );
				replace( body, [
					button( __( 'Choose another snapshot', 'backups-for-restic' ), list ),
					el( 'h3', {}, [ summary.short_id, ' · ', formatStamp( snapshot.time ) ] ),
					...( 'found' === snapshot.manifest_state ? manifestView( snapshot ) : [] ),
					eligible ? el( 'p', {}, [ __( 'The restore stages content and uploads for this installation and verifies the source database in temporary tables. Review the file changes and database findings separately. Servers that cannot safely swap live tables require a manual SQL import.', 'backups-for-restic' ) ] ) : notice( 'warning', __( 'This import requires a site manifest and a database backup. Use the Snapshots tab to select individual files.', 'backups-for-restic' ) ),
					eligible ? el( 'p', {}, [ el( 'label', {}, [ archive, ' ', __( 'Build a downloadable ZIP with the SQL dump for offline recovery (requires additional disk space).', 'backups-for-restic' ) ] ) ] ) : null,
					eligible ? start : null, message,
				] );
			} ).catch( function ( error ) {
				replace( body, [ ...snapshotError( error, () => select( summary ) ), button( __( 'Choose another snapshot', 'backups-for-restic' ), list ) ] );
			} );
		}
		list();
	}

	function snapshotTable( list, scope, open ) {
		const site = 'site' === scope;
		return el( 'table', { className: 'widefat striped bfr-snapshots' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', { scope: 'col' }, [ __( 'Time', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Snapshot', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Host', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Tags', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Files', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'Size', 'backups-for-restic' ) ] ),
					el( 'th', { scope: 'col' }, [ __( 'New data', 'backups-for-restic' ) ] ),
					site ? el( 'th', { scope: 'col' }, [ __( 'Next cleanup', 'backups-for-restic' ) ] ) : null,
				] ),
			] ),
			el( 'tbody', {}, list.map( function ( snapshot ) {
				// The tag that says where the manifest lies is an address for the plugin; the details show it.
				const tags = ( snapshot.tags || [] ).filter( function ( tag ) {
					return ! String( tag ).startsWith( 'backups-for-restic-manifest:' );
				} );
				const show = el( 'button', {
					type: 'button',
					className: 'button-link',
					/* translators: %s: snapshot ID. */
					'aria-label': sprintf( __( 'Open snapshot %s', 'backups-for-restic' ), snapshot.short_id ),
					onClick() {
						open( snapshot );
					},
				}, [ el( 'code', {}, [ snapshot.short_id ] ) ] );
				return el( 'tr', {}, [
					el( 'td', {}, [ formatStamp( snapshot.time ) ] ),
					el( 'td', {}, [ show ] ),
					el( 'td', {}, [ snapshot.hostname ] ),
					el( 'td', {}, [ tags.length ? tagList( tags ) : '–' ] ),
					el( 'td', {}, [ null === snapshot.files ? '–' : String( snapshot.files ) ] ),
					el( 'td', {}, [ formatBytes( snapshot.bytes ) ] ),
					el( 'td', {}, [ formatBytes( snapshot.data_added ) ] ),
					site ? el( 'td', { className: 'bfr-retention' }, [ retentionText( snapshot.retention ) ] ) : null,
				] );
			} ) ),
		] );
	}

	/**
	 * The Snapshots tab: the list of this site's snapshots or of the whole repository, and
	 * one snapshot when it is opened. The scope survives a visit to a snapshot.
	 *
	 * @param {Element} root       Where to show it.
	 * @param {string}  [initial]  Scope to start with: site or repository.
	 * @param {boolean} [returned] Whether the list comes back from a snapshot, so that the keyboard has to follow.
	 */
	function snapshots( root, initial, returned ) {
		const list = el( 'div' );
		let scope = 'repository' === initial ? 'repository' : 'site';
		// Counts the lists asked for: the answer for a scope that was left is not shown.
		let asked = 0;

		function load( fresh ) {
			const current = ++asked;
			replace( list, [ el( 'p', {}, [ __( 'Loading the snapshots…', 'backups-for-restic' ) ] ) ] );
			api( '/snapshots?scope=' + scope + ( fresh ? '&fresh=1' : '' ) )
				.then( function ( response ) {
					if ( current !== asked ) {
						return;
					}
					if ( ! response.snapshots.length ) {
						replace( list, [ notice( 'info', __( 'No snapshots yet.', 'backups-for-restic' ) ) ] );
						return;
					}
					const previewed = response.snapshots.some( function ( snapshot ) {
						return 'keep' === snapshot.retention || 'remove' === snapshot.retention;
					} );
					let preview = __( 'These are all snapshots of the repository, also those of other sites and programs. No cleanup of this site removes what it did not write.', 'backups-for-restic' );
					if ( 'site' === scope ) {
						preview = previewed
							? __( 'The last column says what the retention settings do at the next cleanup. Looking at it removes nothing.', 'backups-for-restic' )
							: __( 'This site does not remove old snapshots at the moment, so the list cannot say what a cleanup would remove.', 'backups-for-restic' );
					}
					replace( list, [
						snapshotTable( response.snapshots, scope, function ( snapshot ) {
							snapshotView( root, snapshot, function () {
								snapshots( root, scope, true );
							} );
						} ),
						el( 'p', { className: 'description' }, [ preview ] ),
					] );
				} )
				.catch( function ( error ) {
					if ( current === asked ) {
						replace( list, snapshotError( error, function () {
							load( fresh );
						} ) );
					}
				} );
		}

		const choice = function ( value, label ) {
			const choose = function () {
				scope = value;
				load( false );
			};
			return el( 'label', {}, [ el( 'input', { type: 'radio', name: 'bfr-snapshot-scope', value, checked: value === scope, onChange: choose } ), ' ', label ] );
		};
		replace( root, [
			el( 'fieldset', { className: 'bfr-scope' }, [
				el( 'legend', {}, [ __( 'Snapshots to show', 'backups-for-restic' ) ] ),
				choice( 'site', __( 'This site', 'backups-for-restic' ) ),
				' ',
				choice( 'repository', __( 'Whole repository', 'backups-for-restic' ) ),
			] ),
			el( 'p', {}, [ button( __( 'Read the list again', 'backups-for-restic' ), function () {
				load( true );
			} ) ] ),
			list,
		] );
		if ( returned ) {
			root.querySelector( 'input:checked' ).focus();
		}
		load( false );
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
			title: __( 'Restore', 'backups-for-restic' ),
			fields: [
				[ 'restore.staging_expiry_hours', __( 'Hours to keep restored files', 'backups-for-restic' ), 'number', null, __( 'A restore puts unencrypted copies of the selected files into the private directory. They are removed this many hours after the restore finished: at least 1, at most 168.', 'backups-for-restic' ) ],
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
							Object.entries( values ).forEach( function ( [ path, submitted ] ) {
								inputs[ path ].initial = submitted;
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
				// The record of who saw the kit, kept current without touching the form above it,
				// and of who downloaded restored files, which can hold the same secrets.
				const views = el( 'div', {}, [ kitViews( response.kit_views ), downloadGrants( response.downloads ) ] );
				const kit = recoveryKitForm( function () {
					api( '/settings' )
						.then( function ( fresh ) {
							replace( views, [ kitViews( fresh.kit_views ), downloadGrants( fresh.downloads ) ] );
						} )
						.catch( function () {} );
				} );
				replace( root, [
					...groups,
					el( 'fieldset', { className: 'bfr-group' }, [
						el( 'legend', {}, [ __( 'Repository', 'backups-for-restic' ) ] ),
						...repositoryForms( response, kit.reset ),
						el( 'h3', {}, [ __( 'Working files and recovery kit', 'backups-for-restic' ) ] ),
						el( 'p', { className: 'description' }, [
							/* translators: %s: directory path. */
							sprintf( __( 'Working directory: %s', 'backups-for-restic' ), response.private_dir ),
						] ),
						cacheControl( response.cache_bytes ),
						kit.box,
						views,
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
	 * @param {Object}     response Answer of the settings route.
	 * @param {() => void} changed  Called after a password or the certificate changed.
	 * @return {Array} Nodes.
	 */
	function repositoryForms( response, changed ) {
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
					changed();
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
							changed();
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
		const renderCa = function ( state, message, entered ) {
			const pem = el( 'textarea', { className: 'large-text code', rows: 4, placeholder: '-----BEGIN CERTIFICATE-----', value: entered || '' } );
			const upload = button( __( 'Upload certificate', 'backups-for-restic' ), function () {
				upload.disabled = true;
				remove.disabled = true;
				api( '/repository/ca-certificate', { method: 'POST', data: { pem: pem.value } } )
					.then( function () {
						changed();
						renderCa( 'uploaded', notice( 'success', __( 'The certificate is uploaded.', 'backups-for-restic' ) ) );
					} )
					.catch( function ( error ) {
						// What was pasted stays, to be corrected.
						renderCa( state, notice( 'error', errorText( error ) ), pem.value );
					} );
			} );
			const remove = button( __( 'Remove certificate', 'backups-for-restic' ), function () {
				upload.disabled = true;
				remove.disabled = true;
				api( '/repository/ca-certificate', { method: 'DELETE' } )
					.then( function () {
						changed();
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
			if ( ! window.navigator.clipboard ) {
				text.select();
				return;
			}
			window.navigator.clipboard.writeText( kit ).catch( function () {
				text.select();
			} );
		} );
		return el( 'div', { className: 'bfr-kit' }, [ text, el( 'p', {}, [ download, ' ', copy ] ) ] );
	}

	/**
	 * Who was shown the recovery kit last, and when.
	 *
	 * @param {Array} views Latest deliveries of the kit, newest first.
	 * @return {Element|null} Paragraph, or null if the kit was never shown.
	 */
	function kitViews( views ) {
		if ( ! views || ! views.length ) {
			return null;
		}
		return el( 'p', { className: 'description bfr-kit-views' }, [
			sprintf(
				/* translators: 1: date and time, 2: user name. */
				__( 'The recovery kit was last shown on %1$s to %2$s.', 'backups-for-restic' ),
				formatTime( views[ 0 ].at ),
				views[ 0 ].login,
			),
		] );
	}

	/**
	 * Who asked for downloads of restored files or archives lately, and for which. The
	 * server sends each path with its control characters quoted.
	 *
	 * @param {Array} grants Latest download grants, newest first.
	 * @return {Element|null} List, or null if no restored content was downloaded.
	 */
	function downloadGrants( grants ) {
		if ( ! grants || ! grants.length ) {
			return null;
		}
		return el( 'div', { className: 'description bfr-download-grants' }, [
			el( 'p', {}, [ __( 'Recent downloads of restored files:', 'backups-for-restic' ) ] ),
			el( 'ul', {}, grants.map( function ( grant ) {
				return el( 'li', {}, [
					'archive' === grant.kind
						? sprintf(
							/* translators: 1: date and time, 2: user name, 3: ID of the restore job. */
							__( '%1$s: %2$s asked for the ZIP of restore %3$s.', 'backups-for-restic' ),
							formatTime( grant.at ),
							grant.login,
							grant.job,
						)
						: sprintf(
							/* translators: 1: date and time, 2: user name, 3: file path in the snapshot, 4: ID of the restore job. */
							__( '%1$s: %2$s asked for %3$s from restore %4$s.', 'backups-for-restic' ),
							formatTime( grant.at ),
							grant.login,
							grant.path,
							grant.job,
						),
				] );
			} ) ),
		] );
	}

	/**
	 * The form that shows the recovery kit. Its second part puts the form back in place of a
	 * kit that is on the screen: after a password or the certificate changed, that kit is no
	 * longer the one to keep.
	 *
	 * @param {() => void} shown Called after the server delivered the kit.
	 * @return {Object} The form's element as `box`, and `reset()`.
	 */
	function recoveryKitForm( shown ) {
		const box = el( 'div' );
		// Counts the changes: a kit that was asked for before the last one is not shown.
		let changes = 0;
		function render( message ) {
			const password = el( 'input', { type: 'password', className: 'regular-text', autocomplete: 'current-password' } );
			const show = button( __( 'Show recovery kit', 'backups-for-restic' ), function () {
				const asked = changes;
				show.disabled = true;
				api( '/recovery-kit', { method: 'POST', data: { user_password: password.value } } )
					.then( function ( response ) {
						if ( asked === changes ) {
							replace( box, [ kitView( response.recovery_kit ) ] );
						}
						shown();
					} )
					.catch( function ( error ) {
						if ( asked === changes ) {
							render( notice( 'error', errorText( error ) ) );
						}
					} );
			} );
			replace( box, [ message, field( __( 'Your WordPress password', 'backups-for-restic' ), password, __( 'Needed to show the repository password.', 'backups-for-restic' ) ), show ] );
		}
		render( null );
		return {
			box,
			reset() {
				changes++;
				if ( box.querySelector( '.bfr-kit' ) || box.querySelector( 'button:disabled' ) ) {
					render( notice( 'info', __( 'The recovery kit changed. Show it again and replace your copy.', 'backups-for-restic' ) ) );
				}
			},
		};
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
	 * request it from itself, and reports what came back. The same kind of request for a known
	 * file of the plugin shows whether a refusal came from this site or from something in
	 * between, such as a firewall or a CDN challenge. Never fails: a probe that cannot run
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
				const options = { credentials: 'omit', cache: 'no-store', redirect: 'manual', signal: patience.signal };
				function look( url, limit ) {
					return window
						.fetch( url, options )
						.then( function ( answer ) {
							return answer.text().then( function ( body ) {
								return { status: answer.status, body: body.slice( 0, limit ) };
							} );
						} )
						.catch( function () {
							return { status: 0, body: '' };
						} );
				}
				return look( probe.url, 1024 )
					.then( function ( seen ) {
						if ( ! probe.control ) {
							return Object.assign( seen, { control_status: 0, control_body: '' } );
						}
						// One character more than the file may have, so that a longer answer does not match.
						return look( probe.control, 65537 ).then( function ( control ) {
							return Object.assign( seen, { control_status: control.status, control_body: control.body } );
						} );
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
							const user = log.job.user;
							replace( detail, [
								el( 'h2', {}, [ formatTime( job.created_at ) + ' – ' + job.state_label ] ),
								user ? el( 'p', {}, [
									/* translators: %s: user name, or the number of a deleted user account. */
									sprintf( __( 'Started by %s.', 'backups-for-restic' ), user.login || '#' + user.id ),
								] ) : null,
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
			// The certificate travels with the request. A test uses it for that test only; it
			// becomes the server's certificate when the server is saved.
			const data = function () {
				return { server: state.server, url: url.value, username: username.value, password: password.value, ca_certificate: own.checked ? ca.value.trim() : '' };
			};
			const save = button( __( 'Save and continue', 'backups-for-restic' ), function () {
				save.disabled = true;
				test.disabled = true;
				api( '/repository/save', { method: 'POST', data: data() } )
					.then( function ( response ) {
						state.report = response.report;
						stepPassword();
					} )
					.catch( function ( error ) {
						save.disabled = false;
						test.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}, true );
			const test = button( __( 'Test connection', 'backups-for-restic' ), function () {
				test.disabled = true;
				save.disabled = true;
				api( '/repository/test', { method: 'POST', data: data() } )
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
						save.disabled = false;
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
			function acknowledge( importSnapshot ) {
				next.disabled = true;
				restore.disabled = true;
				api( '/setup/complete', { method: 'POST', data: { recovery_kit_acknowledged: confirm.checked } } )
					.then( function () {
						if ( importSnapshot ) {
							window.location.assign( config.pageUrl + '&tab=restores&import=1' );
						} else {
							stepSchedule();
						}
					} )
					.catch( function ( error ) {
						next.disabled = false;
						restore.disabled = false;
						replace( result, [ notice( 'error', errorText( error ) ) ] );
					} );
			}
			const next = button( __( 'Continue', 'backups-for-restic' ), () => acknowledge( false ), true );
			const restore = button( __( 'Restore from an existing site snapshot', 'backups-for-restic' ), () => acknowledge( true ) );

			show( 3, [
				el( 'p', {}, [ __( 'Store this recovery kit outside this website, for example in a password manager. Without it, nobody can restore your backups if the site is lost.', 'backups-for-restic' ) ] ),
				kitView( kit ),
				el( 'p', {}, [ el( 'label', {}, [ confirm, ' ', __( 'I have stored the recovery kit outside this server.', 'backups-for-restic' ) ] ) ] ),
				next, ' ', state.report && state.report.exists ? restore : null,
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

	const views = { dashboard, snapshots, restores, settings, environment, logs, setup };

	wp.domReady( function () {
		const root = document.getElementById( 'backups-for-restic-app' );
		if ( root && views[ root.dataset.view ] ) {
			views[ root.dataset.view ]( root );
		}
	} );
}() );
