/**
 * The test site as the browser tests use it: signing in, opening the plugin's page, the
 * repository server that global-setup.js started, and files for a backup to work on.
 */
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.resolve( __dirname, '../..' );
const UPLOADS = path.join( ROOT, '.tools/site/wp-content/uploads/e2e' );

/** The administrator of bin/test-site.sh install. */
const ADMIN = { login: 'admin', password: 'admin-password-test-only' };

/**
 * Signs in as the administrator.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function signIn( page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( ADMIN.login );
	await page.locator( '#user_pass' ).fill( ADMIN.password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\// );
}

/**
 * Opens the plugin's page, on a tab once the setup is completed.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          [tab] dashboard, snapshots, settings, environment or logs.
 */
async function openBackups( page, tab ) {
	await page.goto( '/wp-admin/admin.php?page=backups-for-restic' + ( tab ? '&tab=' + tab : '' ) );
}

/**
 * How to reach the repository server.
 *
 * @return {{url: string, username: string, password: string, certificate: string}} Connection.
 */
function repositoryServer() {
	return JSON.parse( fs.readFileSync( path.join( ROOT, '.tools/e2e-server.json' ), 'utf8' ) );
}

/**
 * Writes a file of random bytes into the uploads directory, so that the next backup has that
 * much to chunk, encrypt and upload.
 *
 * @param {string} name      File name.
 * @param {number} megabytes Size.
 */
function addUpload( name, megabytes ) {
	fs.mkdirSync( UPLOADS, { recursive: true } );
	const file = fs.openSync( path.join( UPLOADS, name ), 'w' );
	for ( let written = 0; written < megabytes; written += 8 ) {
		fs.writeSync( file, crypto.randomBytes( 8 * 1048576 ) );
	}
	fs.closeSync( file );
}

/**
 * Removes a file that addUpload() wrote.
 *
 * @param {string} name File name.
 */
function removeUpload( name ) {
	fs.rmSync( path.join( UPLOADS, name ), { force: true } );
}

module.exports = { ADMIN, signIn, openBackups, repositoryServer, addUpload, removeUpload };
