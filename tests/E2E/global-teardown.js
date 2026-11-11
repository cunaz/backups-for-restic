/**
 * Stops the repository server of the browser tests and removes its data.
 */
const fs = require( 'node:fs' );
const path = require( 'node:path' );

module.exports = async function globalTeardown() {
	const file = path.resolve( __dirname, '../../.tools/e2e-server.json' );
	if ( ! fs.existsSync( file ) ) {
		return;
	}
	const server = JSON.parse( fs.readFileSync( file, 'utf8' ) );
	try {
		process.kill( server.pid );
	} catch {
		// Gone already.
	}
	fs.rmSync( server.directory, { recursive: true, force: true } );
	fs.rmSync( file, { force: true } );
};
