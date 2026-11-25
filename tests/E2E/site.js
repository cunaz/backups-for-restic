/**
 * The test site as the browser tests use it: signing in, opening the plugin's page, the
 * repository server that global-setup.js started, and files for a backup to work on.
 */
const { execFileSync } = require( 'node:child_process' );
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const net = require( 'node:net' );

const ROOT = path.resolve( __dirname, '../..' );
const UPLOADS = path.join( ROOT, '.tools/site/wp-content/uploads/e2e' );

function freePort() {
	return new Promise( function ( resolve, reject ) {
		const server = net.createServer();
		server.on( 'error', reject );
		server.listen( 0, '127.0.0.1', function () {
			const { port } = server.address();
			server.close( function () {
				resolve( port );
			} );
		} );
	} );
}

/** The administrator of bin/test-site.sh install. */
const ADMIN = { login: 'admin', password: 'admin-password-test-only' };

/**
 * Signs in as the administrator.
 *
 * @param {import('@playwright/test').Page} page      Page.
 * @param {Object}                          [account] WordPress login for this installation.
 */
async function signIn( page, account = ADMIN ) {
	await page.goto( '/wp-login.php?redirect_to=' + encodeURIComponent( '/wp-admin/admin.php?page=backups-for-restic' ) );
	await page.locator( '#user_login' ).fill( account.login );
	await page.locator( '#user_pass' ).fill( account.password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\//, { waitUntil: 'domcontentloaded', timeout: 60 * 1000 } );
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
 * The directory of the test site as a snapshot stores it: the absolute path, without links.
 *
 * @return {string} Path.
 */
function siteRoot() {
	return fs.realpathSync( path.join( ROOT, '.tools/site' ) );
}

/**
 * Seeds WordPress content for the migration or reads a stored value for verification.
 *
 * @param {string} root   Disposable site's installation directory.
 * @param {string} script PHP fixture operation.
 * @param {Array}  args   Input arguments.
 * @return {string} Fixture response.
 */
function wordpress( root, script, args = [] ) {
	return execFileSync( 'php', [ '-r', '$_SERVER["HTTP_HOST"] = "127.0.0.1"; require $argv[1] . "/wp-load.php"; ' + script, root, ...args ], { encoding: 'utf8' } ).trim();
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

/**
 * Blocks the site's requests to its own address, as the firewall of many shared hosts does
 * (bin/test-site-hostile-filters.php), or stops doing so.
 *
 * @param {boolean} active Whether to block.
 */
function noLoopback( active ) {
	const flag = path.join( ROOT, '.tools/site/wp-content/bfr-no-loopback' );
	if ( active ) {
		fs.writeFileSync( flag, '' );
	} else {
		fs.rmSync( flag, { force: true } );
	}
}

/**
 * Provisions a second installation with independent WordPress salts, database,
 * content directory and URL. The source is the package already installed on the first site.
 *
 * @return {Promise<Object>} Destination and its cleanup function.
 */
async function freshSite() {
	const plugin = path.join( siteRoot(), 'wp-content/plugins/backups-for-restic' );
	if ( fs.lstatSync( plugin ).isSymbolicLink() ) {
		throw new Error( 'Install the browser fixture with BFR_TEST_PLUGIN_DIR pointing at the built package.' );
	}
	const port = await freePort();
	const tools = fs.mkdtempSync( path.join( ROOT, '.tools/e2e-destination-' ) );
	const env = { ...process.env, BFR_TEST_PLUGIN_DIR: plugin, BFR_SITE_TOOLS_DIR: tools, BFR_DISABLE_WP_CRON: '1',
		BFR_SITE_PORT: String( port ), BFR_DB_NAME: 'bfr_import_' + crypto.randomBytes( 8 ).toString( 'hex' ) };
	function run( command ) {
		execFileSync( path.join( ROOT, 'bin/test-site.sh' ), [ command ], { env } );
	}
	function remove() {
		run( 'reset' );
		fs.rmSync( tools, { recursive: true, force: true } );
	}
	try {
		run( 'install' );
		run( 'start' );
		return { url: 'http://127.0.0.1:' + port, root: path.join( tools, 'site' ), remove };
	} catch ( error ) {
		remove();
		throw error;
	}
}

module.exports = { ADMIN, freePort, freshSite, signIn, openBackups, repositoryServer, siteRoot, wordpress, addUpload, removeUpload, noLoopback };
