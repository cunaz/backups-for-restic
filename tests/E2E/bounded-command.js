/**
 * Runs one command for runCommand() of site.js: node bounded-command.js <seconds> <record>
 * <program> [<argument>...]. The command leads a process group of its own, and after the
 * bound the whole group is stopped, so that what the command started and waits for, a
 * database client or a download, ends with it and does not outlive the test. A command that
 * ends in time leaves what it put in the background running, as the site's server of
 * "bin/test-site.sh start". The ID of the group is written to <record>, so that runCommand()
 * can stop the group itself if this program does not get to it; a command whose group cannot
 * be noted is stopped at once. What is left is the moment between the start of the command
 * and the note: a wrapper that stalls exactly there leaves the command to CI's job limit. The
 * exit status is the command's, 124 after the bound, 125 without the note, and 127 for a
 * program that cannot be started.
 */
const { spawn } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const { clearTimeout, setTimeout } = require( 'node:timers' );

const [ seconds, record, file, ...args ] = process.argv.slice( 2 );
const child = spawn( file, args, { stdio: 'inherit', detached: true } );
if ( undefined !== child.pid ) {
	try {
		fs.writeFileSync( record, String( child.pid ) );
	} catch ( error ) {
		// Without the note, runCommand() could not stop the group where this program does
		// not: the command does not go on unrecorded.
		try {
			process.kill( -child.pid, 'SIGKILL' );
		} catch {
			// The group ended in this moment.
		}
		process.stderr.write( 'The group of the command could not be noted: ' + error.message + '\n' );
		process.exit( 125 );
	}
}
const timer = setTimeout( function () {
	try {
		// A negative ID names the group that the command leads.
		process.kill( -child.pid, 'SIGKILL' );
	} catch {
		// The group ended in this moment.
	}
	process.exit( 124 );
}, Number( seconds ) * 1000 );
child.on( 'error', function ( error ) {
	clearTimeout( timer );
	process.stderr.write( error.message + '\n' );
	process.exit( 127 );
} );
child.on( 'exit', function ( code ) {
	clearTimeout( timer );
	// A command that a signal ended has no status of its own.
	process.exit( null === code ? 1 : code );
} );
