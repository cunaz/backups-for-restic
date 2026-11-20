/**
 * Exercises the packaged admin UI on independent WordPress installations. Setup, backup,
 * file restore, database exchange and undo run through the same controls administrators use.
 * Fixture helpers create source and destination content. Guest requests and direct reads
 * verify maintenance, public posts and retained configuration after the jobs complete.
 */
const fs = require( 'node:fs' );
const { URL } = require( 'node:url' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { ADMIN, freshSite, signIn, openBackups, repositoryServer, siteRoot, wordpress, addUpload, removeUpload, noLoopback } = require( './site' );

test.describe.configure( { mode: 'serial' } );

const SOURCE_UPLOAD = 'bfr-acceptance-source.txt';
const SOURCE_BYTES = 'Uploaded to the source installation before its first backup.\n';

const DESTINATION_ADMIN = { login: 'destination-owner', password: 'destination-owner-acceptance-only' };

function publishPost( root, slug ) {
	const id = wordpress( root,
		'echo wp_insert_post( [ "post_title" => $argv[2], "post_name" => $argv[2], "post_content" => $argv[2], "post_status" => "publish", "post_author" => 1 ] );', [ slug ] );
	expect( Number( id ) ).toBeGreaterThan( 0 );
	return id;
}

async function publicPost( request, url, slug ) {
	const response = await request.get( url + '/?rest_route=/wp/v2/posts&slug=' + encodeURIComponent( slug ) + '&_fields=slug' );
	expect( response.status() ).toBe( 200 );
	return ( await response.json() ).map( ( post ) => post.slug );
}

/**
 * A notice of the plugin's page with a text.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          type error, success, warning or info.
 * @param {string}                          text Part of its text.
 * @return {import('@playwright/test').Locator} Notice.
 */
function notice( page, type, text ) {
	return page.locator( '#backups-for-restic-app .notice-' + type ).filter( { hasText: text } );
}

/**
 * Does something until the page shows a notice. Saving a server or a password is refused for
 * the moment in which a job of the heartbeat, such as forget after the backup, is active;
 * an administrator would click again.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {() => Promise<void>}             action What to do.
 * @param {string}                          type   error, success, warning or info.
 * @param {string}                          text   Part of the notice's text.
 */
async function until( page, action, type, text ) {
	await expect( async () => {
		await action();
		await expect( notice( page, type, text ) ).toBeVisible( { timeout: 60 * 1000 } );
	} ).toPass( { timeout: 5 * 60 * 1000 } );
}

/**
 * Presses "Try again" if the page shows it. A read of the repository is refused for the
 * moment in which a job of the heartbeat, such as the cleanup after a backup, holds the
 * repository; the page then waits for the administrator.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function pressTryAgain( page ) {
	const again = page.getByRole( 'button', { name: 'Try again', exact: true } );
	if ( await again.isVisible() ) {
		await again.click( { timeout: 5 * 1000 } );
	}
}

/**
 * Waits for something that a read of the repository shows, and asks again whenever the
 * repository was locked.
 *
 * @param {import('@playwright/test').Page}    page  Page.
 * @param {import('@playwright/test').Locator} shown What the read shows when it succeeded.
 */
async function unlocked( page, shown ) {
	await expect( async () => {
		await pressTryAgain( page );
		await expect( shown ).toBeVisible( { timeout: 10 * 1000 } );
	} ).toPass( { timeout: 3 * 60 * 1000 } );
}

/**
 * A card of the dashboard by its heading.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          heading Heading.
 * @return {import('@playwright/test').Locator} Card.
 */
function card( page, heading ) {
	return page.locator( '.bfr-card' ).filter( { has: page.getByRole( 'heading', { name: heading, exact: true } ) } );
}

test.beforeEach( async ( { page } ) => {
	await signIn( page );
} );

test( 'the wizard sets the site up and runs the first backup', async ( { page } ) => {
	const server = repositoryServer();
	await openBackups( page );
	await expect( page.getByRole( 'heading', { name: 'Set up backups' } ) ).toBeVisible();
	await expect( page.locator( '.nav-tab-wrapper' ) ).toHaveCount( 0 );

	// Environment: the checks of this server, none of them critical.
	await expect( page.locator( '.bfr-checks .bfr-check' ).first() ).toBeVisible( { timeout: 120 * 1000 } );
	await expect( page.locator( '.bfr-check.bfr-critical' ) ).toHaveCount( 0 );
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();

	// Repository server. Its certificate is from an authority of its own: without that
	// certificate the connection is refused, and there is no way on.
	await page.getByLabel( 'Repository URL' ).fill( server.url );
	await page.getByLabel( 'REST username' ).fill( server.username );
	await page.getByLabel( 'REST password' ).fill( server.password );
	await page.getByRole( 'button', { name: 'Test connection' } ).click();
	await expect( notice( page, 'error', 'The repository server could not be reached.' ) ).toBeVisible( { timeout: 120 * 1000 } );
	await expect( page.getByRole( 'button', { name: 'Save and continue' } ) ).toHaveCount( 0 );

	await page.getByLabel( 'CA certificate for a self-signed server (optional, PEM)' ).fill( server.certificate );
	await page.getByRole( 'button', { name: 'Test connection' } ).click();
	await expect( notice( page, 'success', 'Connected. The address is empty; a new repository will be created.' ) ).toBeVisible( { timeout: 120 * 1000 } );
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();

	// A new repository, and its recovery kit.
	await page.getByRole( 'button', { name: 'Create repository' } ).click();
	const kit = page.locator( '.bfr-kit textarea' );
	await expect( kit ).toHaveValue( /RESTIC_PASSWORD='[A-Za-z0-9_-]{32}'/, { timeout: 120 * 1000 } );
	await expect( kit ).toHaveValue( /-----BEGIN CERTIFICATE-----/ );
	await expect( kit ).toHaveValue( /RESTIC_REST_PASSWORD='rest user'\\''s password'/ );

	// The kit has to be acknowledged.
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();
	await expect( notice( page, 'error', 'Please confirm that you stored the recovery kit.' ) ).toBeVisible();
	await page.getByLabel( 'I have stored the recovery kit outside this server.' ).check();
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();

	// Schedule, then the first backup with its progress.
	await page.getByLabel( 'Back up', { exact: true } ).selectOption( 'daily' );
	await page.getByLabel( 'Hour', { exact: true } ).fill( '3' );
	await page.getByLabel( 'Minute', { exact: true } ).fill( '17' );
	const uploads = path.join( siteRoot(), 'wp-content/uploads' );
	fs.mkdirSync( uploads, { recursive: true } );
	const file = path.join( uploads, SOURCE_UPLOAD );
	fs.writeFileSync( file, SOURCE_BYTES, { flag: 'wx' } );
	const many = fs.mkdtempSync( path.join( uploads, 'bfr-file-review-' ) );
	const sourcePost = publishPost( siteRoot(), 'bfr-migration-source' );
	try {
		// Combined with the packaged content, this forces more than 1,000 real
		// restore entries through the persistent planner in the migration test.
		for ( let i = 0; i < 500; i++ ) {
			fs.writeFileSync( path.join( many, 'document-' + i + '.txt' ), SOURCE_BYTES );
		}
		await page.getByRole( 'button', { name: 'Save and run the first backup' } ).click();
		await expect( page.getByText( 'The first backup uploads everything once' ) ).toBeVisible();
		await expect( notice( page, 'success', 'Finished successfully.' ) ).toBeVisible( { timeout: 8 * 60 * 1000 } );
	} finally {
		fs.rmSync( many, { recursive: true } );
		fs.unlinkSync( file );
		wordpress( siteRoot(), 'wp_delete_post( (int) $argv[2], true );', [ sourcePost ] );
	}

	await page.getByRole( 'link', { name: 'Go to the dashboard' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Last backup', exact: true } ) ).toBeVisible();
} );

test( 'the dashboard and the snapshot list show the backup', async ( { page } ) => {
	await openBackups( page );
	for ( const tab of [ 'Dashboard', 'Snapshots', 'Settings', 'Environment', 'Logs' ] ) {
		await expect( page.locator( '.nav-tab-wrapper' ).getByRole( 'link', { name: tab, exact: true } ) ).toBeVisible();
	}
	const last = card( page, 'Last backup' );
	await expect( last.locator( '.bfr-big' ) ).not.toHaveText( 'None yet' );
	await expect( last ).toContainText( /Snapshot [0-9a-f]{8}/ );
	const snapshot = ( await last.textContent() ).match( /Snapshot ([0-9a-f]{8})/ )[ 1 ];
	await expect( card( page, 'Repository' ) ).toContainText( '127.0.0.1' );
	await expect( card( page, 'Repository' ).locator( '.bfr-usage' ) ).toContainText( /^[\d.,]+ [KMG]?B stored, measured on / );

	// The wizard's backup is in the list of recent jobs.
	const jobs = page.locator( 'table' ).filter( { has: page.getByRole( 'columnheader', { name: 'Result' } ) } );
	await expect( jobs.locator( 'tbody tr' ).filter( { hasText: 'Succeeded' } ).first() ).toBeVisible();

	await page.locator( '.nav-tab-wrapper' ).getByRole( 'link', { name: 'Snapshots', exact: true } ).click();
	const rows = page.locator( 'table.bfr-snapshots tbody tr' );
	await expect( async () => {
		await pressTryAgain( page );
		await expect( rows ).toHaveCount( 1, { timeout: 5 * 1000 } );
	} ).toPass( { timeout: 3 * 60 * 1000 } );
	await expect( rows.first().locator( 'code' ) ).toHaveText( snapshot );
} );

test( 'a selected snapshot file can be staged and downloaded', async ( { page } ) => {
	const rows = page.locator( 'table.bfr-snapshots tbody tr' );
	// The cleanup after the first backup holds the repository for a moment; an administrator
	// would open the tab again.
	await expect( async () => {
		await openBackups( page, 'snapshots' );
		await expect( rows ).toHaveCount( 1, { timeout: 20 * 1000 } );
	} ).toPass( { timeout: 3 * 60 * 1000 } );

	// This site's snapshots come first, each with what the next cleanup does with it.
	await expect( page.getByLabel( 'This site' ) ).toBeChecked();
	await expect( page.getByRole( 'columnheader', { name: 'Next cleanup' } ) ).toBeVisible();
	await expect( rows.first().locator( '.bfr-retention' ) ).toHaveText( 'Will be kept' );

	// The whole repository holds the same snapshot here; no cleanup of the site decides about that list.
	await page.getByLabel( 'Whole repository' ).check();
	await unlocked( page, page.getByText( 'These are all snapshots of the repository' ) );
	await expect( rows ).toHaveCount( 1 );
	await expect( page.getByRole( 'columnheader', { name: 'Next cleanup' } ) ).toHaveCount( 0 );
	await page.getByLabel( 'This site' ).check();
	await expect( page.getByRole( 'columnheader', { name: 'Next cleanup' } ) ).toBeVisible();

	// The newest snapshot, with the site that it holds.
	const id = await rows.first().locator( 'code' ).textContent();
	await rows.first().getByRole( 'button', { name: 'Open snapshot ' + id } ).click();
	await expect( page.getByRole( 'heading', { name: 'Snapshot ' + id, exact: true } ) ).toBeVisible();
	const manifest = page.locator( '.bfr-manifest' );
	await unlocked( page, manifest );
	await expect( manifest.locator( 'tr' ).filter( { hasText: 'Site address' } ) ).toContainText( new URL( page.url() ).origin );
	await expect( manifest.locator( 'tr' ).filter( { hasText: 'WordPress version' } ) ).toContainText( /\d+\.\d+/ );
	await expect( manifest.locator( 'tr' ).filter( { hasText: 'Table prefix' } ) ).toContainText( /\w+_/ );
	await expect( manifest.locator( 'tr' ).filter( { hasText: 'WordPress directory' } ) ).toContainText( siteRoot() );

	// From the top directory down to the one of WordPress, one directory per request. The
	// entries are searched in the table only: the path above it names the same directories.
	const entries = page.locator( '.bfr-entries' );
	const trail = page.locator( '.bfr-trail' );
	for ( const name of siteRoot().split( '/' ).filter( Boolean ) ) {
		await unlocked( page, entries.getByRole( 'button', { name, exact: true } ) );
		await entries.getByRole( 'button', { name, exact: true } ).click();
		await expect( trail.locator( '[aria-current]' ) ).toHaveText( name, { timeout: 120 * 1000 } );
	}

	// A file is ticked and named in the selection, which stays while another directory is open.
	const license = page.getByLabel( 'Select license.txt', { exact: true } );
	await unlocked( page, license );
	await license.check();
	const selection = page.locator( '.bfr-selection' );
	await expect( selection ).toContainText( '1 path selected:' );
	await expect( selection.locator( 'code' ) ).toHaveText( siteRoot() + '/license.txt' );
	await entries.getByRole( 'button', { name: 'wp-content', exact: true } ).click();
	await expect( trail.locator( '[aria-current]' ) ).toHaveText( 'wp-content' );
	// The plugins directory is in every site; WordPress creates the uploads directory only when it needs it.
	const plugins = page.getByLabel( 'Select plugins', { exact: true } );
	await unlocked( page, plugins );
	await expect( selection ).toContainText( '1 path selected:' );

	// A ticked directory holds what lies in it: its entries are ticked with it and counted once.
	await plugins.check();
	await expect( selection ).toContainText( '2 paths selected:' );
	await entries.getByRole( 'button', { name: 'plugins', exact: true } ).click();
	await expect( trail.locator( '[aria-current]' ) ).toHaveText( 'plugins' );
	await unlocked( page, page.locator( '.bfr-covered' ) );
	await expect( entries.locator( 'input[type="checkbox"]' ).first() ).toBeChecked();
	await expect( entries.locator( 'input[type="checkbox"]' ).first() ).toBeDisabled();
	await expect( selection ).toContainText( '2 paths selected:' );
	await trail.getByRole( 'button' ).last().click();
	await expect( trail.locator( '[aria-current]' ) ).toHaveText( 'wp-content' );
	await unlocked( page, plugins );
	await plugins.uncheck();
	await expect( selection ).toContainText( '1 path selected:' );

	// Back up by the path: the box is still ticked, until the selection is cleared.
	await trail.getByRole( 'button' ).last().click();
	await unlocked( page, license );
	await expect( license ).toBeChecked();
	await page.getByRole( 'button', { name: 'Clear selection' } ).click();
	await expect( license ).not.toBeChecked();
	await expect( selection ).toContainText( 'Nothing is selected.' );
	await page.getByRole( 'button', { name: 'Select entire snapshot' } ).click();
	await expect( page.getByLabel( 'Rehearse the database import in temporary tables.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Clear selection' } ).click();

	await license.check();
	await expect( page.getByLabel( 'Rehearse the database import in temporary tables.' ) ).toHaveCount( 0 );
	await page.getByLabel( 'Build a ZIP for download', { exact: false } ).check();
	await page.getByRole( 'button', { name: 'Restore to staging' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Staged restores' } ) ).toBeVisible();
	const staged = page.locator( 'section.bfr-card' ).filter( { hasText: 'Download ZIP' } );
	await expect( staged ).toBeVisible( { timeout: 4 * 60 * 1000 } );
	await expect( staged.getByLabel( 'Absolute file path in the snapshot' ) ).toHaveValue( siteRoot() + '/license.txt' );

	const downloads = {};
	for ( const name of [ 'Download file', 'Download ZIP', 'Download restore report' ] ) {
		const pending = page.waitForEvent( 'download' );
		await staged.getByRole( 'button', { name, exact: true } ).click();
		const downloaded = await pending;
		expect( await downloaded.failure() ).toBeNull();
		downloads[ name ] = fs.readFileSync( await downloaded.path() );
	}
	expect( downloads[ 'Download file' ] ).toEqual( fs.readFileSync( siteRoot() + '/license.txt' ) );
	expect( downloads[ 'Download ZIP' ].subarray( 0, 4 ).toString( 'hex' ) ).toBe( '504b0304' );
	expect( downloads[ 'Download restore report' ].toString() ).toContain( 'license.txt' );
} );

test( 'live apply and undo require a current preview and confirmation', async ( { page } ) => {
	await openBackups( page, 'restores' );
	const staged = page.locator( 'section.bfr-card' ).filter( { hasText: 'Download ZIP' } );
	const file = siteRoot() + '/license.txt';
	const original = fs.readFileSync( file );
	const revision = 'Local change on the disposable test site.\n';
	const url = new URL( page.url() ).origin;
	async function confirm( form, label, address, password ) {
		await form.getByLabel( 'I have reviewed these changes to the live site.' ).check();
		await form.getByLabel( 'Type the site address to confirm' ).fill( address );
		await form.getByLabel( 'Your WordPress password' ).fill( password );
		await form.getByRole( 'button', { name: label, exact: true } ).click();
	}

	try {
		fs.writeFileSync( file, 'Before the preview.\n' );
		await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
		const preview = staged.getByRole( 'button', { name: 'Preview live file changes' } );
		await preview.click();
		await expect( staged.getByRole( 'cell', { name: 'Replace file', exact: true } ) ).toBeVisible();

		const apply = 'Apply to live site';
		await confirm( staged, apply, url + '/another-site', ADMIN.password );
		await expect( staged ).toContainText( 'type the exact site address' );
		await confirm( staged, apply, url, 'incorrect' );
		await expect( staged ).toContainText( 'Your WordPress password was not correct.' );

		fs.writeFileSync( file, revision );
		await confirm( staged, apply, url, ADMIN.password );
		await expect( staged ).toContainText( 'review a fresh preview', { ignoreCase: true } );
		expect( fs.readFileSync( file, 'utf8' ) ).toBe( revision );

		await preview.click();
		await confirm( staged, apply, url, ADMIN.password );
		await expect.poll( () => fs.readFileSync( file ), { timeout: 120000 } ).toEqual( original );

		const undo = page.locator( 'section.bfr-card' ).filter( { hasText: 'Preview undo' } );
		await undo.getByRole( 'button', { name: 'Preview undo' } ).click();
		await confirm( undo, 'Undo live file changes', url, ADMIN.password );
		await expect.poll( () => fs.readFileSync( file, 'utf8' ), { timeout: 120000 } ).toBe( revision );
	} finally {
		fs.writeFileSync( file, original );
	}

	await staged.getByRole( 'button', { name: 'Delete staged files' } ).click();
	await staged.getByRole( 'button', { name: 'Keep files' } ).click();
	await staged.getByRole( 'button', { name: 'Delete staged files' } ).click();
	await staged.getByRole( 'button', { name: 'Confirm deletion' } ).click();
	await expect( page.getByText( 'No staged files.', { exact: false } ) ).toBeVisible();
	await page.getByRole( 'link', { name: 'Snapshots', exact: true } ).click();
	const rows = page.locator( 'table.bfr-snapshots tbody tr' );
	await unlocked( page, rows.first() );
	await expect( rows ).toHaveCount( 1 );
} );

test( 'settings are saved, refused when invalid, and the recovery kit needs the WordPress password', async ( { page } ) => {
	await openBackups( page, 'settings' );
	const daily = page.getByLabel( 'Keep one snapshot per day, for this many days' );
	await expect( daily ).toHaveValue( '7' );
	await daily.fill( '9' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( notice( page, 'success', 'Settings saved.' ) ).toBeVisible();
	await page.reload();
	await expect( page.getByLabel( 'Keep one snapshot per day, for this many days' ) ).toHaveValue( '9' );

	// A retention window of nothing would let the next forget remove every snapshot.
	const within = page.getByLabel( 'Keep every snapshot of the last' );
	await within.fill( '0d' );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( notice( page, 'error', 'Some settings are not valid.' ) ).toBeVisible();
	await expect( page.locator( '.bfr-field' ).filter( { has: within } ).locator( '.bfr-error' ) ).not.toBeEmpty();
	await page.reload();
	await expect( page.getByLabel( 'Keep every snapshot of the last' ) ).toHaveValue( '2d' );

	// The recovery kit shows the repository password: only to somebody who knows the WordPress password.
	const password = page.getByLabel( 'Your WordPress password', { exact: true } );
	await password.fill( 'not the password' );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	await expect( notice( page, 'error', 'Your WordPress password was not correct.' ) ).toBeVisible();
	await expect( page.locator( '.bfr-kit' ) ).toHaveCount( 0 );
	await page.getByLabel( 'Your WordPress password', { exact: true } ).fill( ADMIN.password );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	await expect( page.locator( '.bfr-kit textarea' ) ).toHaveValue( /RESTIC_PASSWORD='[A-Za-z0-9_-]{32}'/ );

	// That it was shown is on record.
	await page.reload();
	await expect( page.locator( '.bfr-kit-views' ) ).toContainText( /The recovery kit was last shown on .+ to admin\./ );
} );

test( 'the passwords and the CA certificate can be entered again in the settings', async ( { page } ) => {
	const server = repositoryServer();
	await openBackups( page, 'settings' );
	await expect( page.getByText( server.url ) ).toBeVisible();

	// The repository password, from the recovery kit: the forms below need it.
	await page.getByLabel( 'Your WordPress password', { exact: true } ).fill( ADMIN.password );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	const kit = await page.locator( '.bfr-kit textarea' ).inputValue();
	const repositoryPassword = kit.match( /RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/ )[ 1 ];

	// REST password: only one that the server accepts is saved.
	await page.getByLabel( 'New REST password' ).fill( 'not the password' );
	await page.getByRole( 'button', { name: 'Test and save' } ).click();
	await expect( notice( page, 'error', 'The repository server rejected the username or password.' ) ).toBeVisible( { timeout: 60 * 1000 } );
	const saveRestPassword = async () => {
		await page.getByLabel( 'New REST password' ).fill( server.password );
		await page.getByRole( 'button', { name: 'Test and save' } ).click();
	};
	await until( page, saveRestPassword, 'success', 'The server accepted the password. It is saved.' );

	// CA certificate: without it the server's certificate is unknown again.
	await expect( page.getByText( 'A certificate is uploaded.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Remove certificate' } ).click();
	await expect( notice( page, 'success', 'The certificate is removed.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Remove certificate' } ) ).toHaveCount( 0 );
	await page.getByLabel( 'New REST password' ).fill( server.password );
	await page.getByRole( 'button', { name: 'Test and save' } ).click();
	await expect( notice( page, 'error', 'The repository server could not be reached.' ) ).toBeVisible( { timeout: 120 * 1000 } );
	await page.getByLabel( 'CA certificate of the server (PEM)' ).fill( 'not a certificate' );
	await page.getByRole( 'button', { name: 'Upload certificate' } ).click();
	await expect( notice( page, 'error', 'This is not a valid PEM certificate.' ) ).toBeVisible();
	await page.getByLabel( 'CA certificate of the server (PEM)' ).fill( server.certificate );
	await page.getByRole( 'button', { name: 'Upload certificate' } ).click();
	await expect( notice( page, 'success', 'The certificate is uploaded.' ) ).toBeVisible();
	await until( page, saveRestPassword, 'success', 'The server accepted the password. It is saved.' );

	// Repository password: needs the WordPress password, and a wrong one fails the unlock.
	await page.getByLabel( 'Repository password', { exact: true } ).fill( repositoryPassword );
	await page.getByLabel( 'Confirm with your WordPress password' ).fill( 'not the password' );
	await page.getByRole( 'button', { name: 'Unlock with this password' } ).click();
	await expect( notice( page, 'error', 'Your WordPress password was not correct.' ) ).toBeVisible();
	const unlockWith = ( password ) => async () => {
		await page.getByLabel( 'Repository password', { exact: true } ).fill( password );
		await page.getByLabel( 'Confirm with your WordPress password' ).fill( ADMIN.password );
		await page.getByRole( 'button', { name: 'Unlock with this password' } ).click();
	};
	await until( page, unlockWith( 'not the password of this repository' ), 'error', 'The repository password is wrong.' );
	await until( page, unlockWith( repositoryPassword ), 'success', 'The repository is unlocked with this password.' );

	// The site works with its repository as before.
	await page.locator( '.nav-tab-wrapper' ).getByRole( 'link', { name: 'Snapshots', exact: true } ).click();
	await expect( page.locator( 'table tbody tr' ) ).toHaveCount( 1 );
} );

test( 'the browser checks the working directory where the site cannot request itself', async ( { page } ) => {
	noLoopback( true );
	try {
		const reported = page.waitForResponse( ( response ) => response.url().includes( 'environment%2Fprobe%2Freport' ) || response.url().includes( 'environment/probe/report' ) );
		await openBackups( page, 'environment' );
		// The script asked for a probe file, requested it from here and reported the refusal.
		expect( ( await ( await reported ).json() ).exposed ).toBe( false );
		const checks = page.locator( '.bfr-checks' );
		await expect( checks ).toContainText( 'The working directory is protected' );
		await expect( checks ).not.toContainText( 'could not be verified' );
		await expect( page.locator( '.bfr-check.bfr-critical' ) ).toHaveCount( 0 );
	} finally {
		noLoopback( false );
	}
	// With requests to itself the site finds the same on its own.
	await page.getByRole( 'button', { name: 'Check again' } ).click();
	await expect( page.locator( '.bfr-checks' ) ).toContainText( 'The working directory is protected' );
} );

test( 'a fresh installation can import a source snapshot and keep its own configuration', async ( { page, browser, request } ) => {
	test.setTimeout( 20 * 60 * 1000 );
	// Read the source repository password from the kit through the regular admin screen.
	await openBackups( page, 'settings' );
	await page.getByLabel( 'Your WordPress password', { exact: true } ).fill( ADMIN.password );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	const kit = page.locator( '.bfr-kit textarea' );
	await expect( kit ).toHaveValue( /RESTIC_PASSWORD='[A-Za-z0-9_-]{32}'/ );
	const password = ( await kit.inputValue() ).match( /RESTIC_PASSWORD='([A-Za-z0-9_-]{32})'/ )[ 1 ];
	await page.close();

	const target = await freshSite();
	const context = await browser.newContext( { baseURL: target.url } );
	try {
		const destination = await context.newPage();
		const configFile = path.join( target.root, 'wp-config.php' );
		const configuration = fs.readFileSync( configFile );
		const upload = path.join( target.root, 'wp-content/uploads', SOURCE_UPLOAD );
		const owner = wordpress( target.root,
			'echo wp_insert_user( [ "user_login" => $argv[2], "user_pass" => $argv[3], "user_email" => "destination-owner@example.test", "role" => "administrator" ] );', [ DESTINATION_ADMIN.login, DESTINATION_ADMIN.password ] );
		expect( Number( owner ) ).toBeGreaterThan( 1 );
		publishPost( target.root, 'bfr-migration-destination' );
		await signIn( destination, DESTINATION_ADMIN );
		await openBackups( destination );
		await destination.getByRole( 'button', { name: 'Continue', exact: true } ).click();
		const server = repositoryServer();
		await destination.getByLabel( 'Repository URL' ).fill( server.url );
		await destination.getByLabel( 'REST username' ).fill( server.username );
		await destination.getByLabel( 'REST password' ).fill( server.password );
		await destination.getByLabel( 'CA certificate for a self-signed server (optional, PEM)' ).fill( server.certificate );
		await destination.getByRole( 'button', { name: 'Test connection' } ).click();
		await destination.getByRole( 'button', { name: 'Save and continue' } ).click();
		await destination.getByLabel( 'Repository password', { exact: true } ).fill( password );
		await destination.getByRole( 'button', { name: 'Connect', exact: true } ).click();
		await destination.getByLabel( 'I have stored the recovery kit outside this server.' ).check( { timeout: 120000 } );
		await destination.getByRole( 'button', { name: 'Restore from an existing site snapshot' } ).click();
		const row = destination.getByRole( 'button', { name: /Open snapshot/ } ).first();
		await unlocked( destination, row );
		await row.click();
		await expect( destination.locator( '.bfr-manifest' ) ).toContainText( siteRoot() + '/wp-content' );
		await destination.getByRole( 'button', { name: 'Stage site files and rehearse the database restore' } ).click();
		const staged = destination.locator( 'section.bfr-card' ).filter( { hasText: 'Download database rehearsal report' } );
		await expect( staged ).toBeVisible( { timeout: 6 * 60 * 1000 } );
		expect( fs.existsSync( upload ) ).toBe( false );
		await staged.getByRole( 'button', { name: 'Inspect database rehearsal' } ).click();
		await expect( staged.getByRole( 'button', { name: 'Close database rehearsal' } ) ).toBeFocused();
		const options = staged.locator( '.bfr-database-tables tbody tr' ).filter( { has: destination.getByRole( 'cell', { name: 'wp_options', exact: true } ) } );
		await expect( options ).toBeVisible();
		expect( Number( await options.locator( 'td' ).nth( 1 ).innerText() ) ).toBeGreaterThan( 0 );

		// A setting of the receiving installation differs from the source.
		const settings = await context.newPage();
		await openBackups( settings, 'settings' );
		await settings.getByLabel( 'Keep one snapshot per day, for this many days' ).fill( '13' );
		await settings.getByLabel( 'Continue jobs with loopback requests' ).selectOption( 'off' );
		await settings.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( notice( settings, 'success', 'Settings saved.' ) ).toBeVisible();
		async function apply( resume = false ) {
			await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
			await expect( staged.getByLabel( 'Include wp-config.php in the preview.' ) ).toHaveCount( 0 );
			const preview = staged.getByRole( 'button', { name: 'Preview live file changes' } );
			if ( resume ) {
				const isPlan = ( response ) => response.request().method() === 'POST' && decodeURIComponent( response.url() ).includes( '/apply-plan' );
				const [ queued ] = await Promise.all( [ destination.waitForResponse( ( response ) => isPlan( response ) && 202 === response.status() ), preview.click() ] );
				const id = ( await queued.json() ).job.id;
				await expect( staged.getByRole( 'columnheader', { name: 'Snapshot path' } ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
				await destination.reload();
				await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
				await Promise.all( [
					destination.waitForResponse( ( response ) => isPlan( response ) && 200 === response.status() && response.request().postDataJSON().plan_job === id ),
					preview.click(),
				] );
			} else {
				await preview.click();
			}
			await expect( staged.getByRole( 'columnheader', { name: 'Snapshot path' } ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
			const actions = staged.locator( '.bfr-file-actions' );
			await expect( actions ).toContainText( /Showing file changes 1–100 of [1-9]\d{3,}\./ );
			const next = actions.getByRole( 'button', { name: 'Next file changes' } );
			const previous = actions.getByRole( 'button', { name: 'Previous file changes' } );
			await next.focus();
			if ( resume ) {
				// A transient page failure keeps the reviewed rows and focused retry control.
				const planURL = ( url ) => decodeURIComponent( url.href ).includes( '/apply-plan' );
				await destination.route( planURL, ( route ) => route.fulfill( { status: 503, json: { code: 'unavailable', message: 'Preview page temporarily unavailable.', data: { status: 503 } } } ), { times: 1 } );
				await next.press( 'Enter' );
				await expect( actions.getByRole( 'status' ) ).toHaveText( 'Preview page temporarily unavailable.' );
				await expect( next ).toBeFocused();
				await expect( actions.locator( 'tbody tr' ) ).toHaveCount( 100 );
			}
			await next.press( 'Enter' );
			await expect( actions.getByRole( 'status' ) ).toHaveText( /Showing file changes 101–200 of / );
			await expect( next ).toBeFocused();
			await previous.focus();
			await previous.press( 'Enter' );
			await expect( actions.getByRole( 'status' ) ).toHaveText( /Showing file changes 1–100 of / );
			await expect( next ).toBeFocused();
			await expect( staged.getByRole( 'columnheader', { name: 'Live path' } ) ).toBeVisible();
			await staged.getByLabel( 'I have reviewed these changes to the live site.' ).check();
			await staged.getByLabel( 'Type the site address to confirm' ).fill( target.url );
			await staged.getByLabel( 'Your WordPress password' ).fill( DESTINATION_ADMIN.password );
			await staged.getByRole( 'button', { name: 'Apply to live site' } ).click();
		}
		await apply( true );
		// This destination advances only through the open, authenticated control page.
		// The prepared marker is held between ticks, including while cancellation is requested.
		const marker = path.join( target.root, '.maintenance' );
		await expect.poll( () => fs.existsSync( marker ), { timeout: 60000, intervals: [ 50, 100 ] } ).toBe( true );
		const cookie = ( await context.cookies( target.url ) ).find( ( entry ) => entry.name === 'bfr_file_control' );
		expect( cookie ).toMatchObject( { httpOnly: true, sameSite: 'Strict', path: '/' } );
		expect( ( await request.get( target.url + '/' ) ).status() ).toBe( 503 );
		await destination.getByRole( 'button', { name: 'Cancel', exact: true } ).click();
		await expect( notice( destination, 'info', 'Cancelled' ) ).toBeVisible();
		expect( fs.existsSync( marker ) ).toBe( false );
		expect( ( await request.get( target.url + '/' ) ).status() ).toBe( 200 );
		await apply();
		await expect( notice( destination, 'success', 'Finished successfully.' ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
		expect( fs.readFileSync( upload, 'utf8' ) ).toBe( SOURCE_BYTES );
		expect( fs.readFileSync( configFile ) ).toEqual( configuration );
		await settings.reload();
		await expect( settings.getByLabel( 'Keep one snapshot per day, for this many days' ) ).toHaveValue( '13' );
		const db = staged.locator( 'details' ).filter( { has: destination.getByText( 'Restore tables to the live database', { exact: true } ) } );
		await db.getByText( 'Restore tables to the live database', { exact: true } ).click();
		const [ review ] = await Promise.all( [
			destination.waitForResponse( ( response ) => response.request().method() === 'POST' && decodeURIComponent( response.url() ).includes( '/database/plan' ) ),
			db.getByRole( 'button', { name: 'Preview live database changes' } ).click(),
		] );
		expect( review.status() ).toBe( 200 );
		expect( review.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		await expect( db.getByRole( 'status' ).filter( { hasText: 'Database preview ready.' } ) ).toBeVisible();
		await expect( db ).toContainText( 'wp_posts' );
		const applyDatabase = db.getByRole( 'button', { name: 'Apply database restore', exact: true } );
		if ( '1' === process.env.BFR_E2E_EXPECT_DATABASE_SWAP ) {
			await expect( applyDatabase ).toBeVisible();
			expect( await publicPost( request, target.url, 'bfr-migration-source' ) ).toEqual( [] );
			expect( await publicPost( request, target.url, 'bfr-migration-destination' ) ).toEqual( [ 'bfr-migration-destination' ] );
			async function exchange( area, submit, outcome ) {
				await area.getByLabel( 'I have reviewed these changes to the live site.' ).check();
				await area.getByLabel( 'Type the site address to confirm' ).fill( target.url );
				await area.getByLabel( 'Your WordPress password' ).fill( DESTINATION_ADMIN.password );
				const previous = destination.url();
				await submit.click();
				await destination.waitForURL( ( url ) => url.href !== previous && url.searchParams.has( 'job' ) );
				await expect( notice( destination, 'success', outcome ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
				await settings.reload();
				await expect( settings.getByLabel( 'Keep one snapshot per day, for this many days' ) ).toHaveValue( '13' );
				expect( wordpress( target.root, 'global $wpdb; echo $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name=\x27home\x27" );' ) ).toBe( target.url );
			}
			await db.getByLabel( 'I have reviewed these changes to the live site.' ).check();
			await db.getByLabel( 'Type the site address to confirm' ).fill( target.url );
			await db.getByLabel( 'Your WordPress password' ).fill( 'incorrect-acceptance-password' );
			await applyDatabase.click();
			await expect( db ).toContainText( 'Your WordPress password was not correct.' );
			await expect( db.getByLabel( 'Your WordPress password' ) ).toBeFocused();
			await exchange( db, applyDatabase, 'Database restore applied.' );
			expect( await publicPost( request, target.url, 'bfr-migration-source' ) ).toEqual( [ 'bfr-migration-source' ] );
			expect( await publicPost( request, target.url, 'bfr-migration-destination' ) ).toEqual( [] );
			const undo = destination.locator( 'section.bfr-card' ).filter( { has: destination.getByRole( 'button', { name: 'Preview database undo' } ) } );
			await undo.getByRole( 'button', { name: 'Preview database undo' } ).click();
			await exchange( undo, undo.getByRole( 'button', { name: 'Undo database restore', exact: true } ), 'Database restore undone.' );
			expect( await publicPost( request, target.url, 'bfr-migration-destination' ) ).toEqual( [ 'bfr-migration-destination' ] );
			expect( await publicPost( request, target.url, 'bfr-migration-source' ) ).toEqual( [] );
		} else if ( 'mariadb' === process.env.BFR_E2E_EXPECT_DATABASE_SWAP ) {
			await expect( db ).toContainText( 'MariaDB requires a manual live database restore' );
			await expect( applyDatabase ).toHaveCount( 0 );
		}
	} finally {
		await context.close();
		target.remove();
	}
} );

test( 'a running backup is cancelled from the dashboard', async ( { page } ) => {
	// More new data than one tick of twenty seconds gets through on any machine: a request to
	// cancel that arrives once the snapshot is written no longer stops the job.
	addUpload( 'cancelled.bin', 1024 );
	try {
		await openBackups( page );
		await page.getByRole( 'button', { name: 'Back up now' } ).click();
		const running = page.locator( '.bfr-running' );
		await expect( running.locator( '.bfr-step' ) ).toBeVisible( { timeout: 60 * 1000 } );
		// While a job runs, no other can be started.
		await expect( page.getByRole( 'button', { name: 'Back up now' } ) ).toBeDisabled();

		await running.getByRole( 'button', { name: 'Cancel' } ).click();

		// The dashboard comes back with the job in its list, and with the buttons free again.
		const jobs = page.locator( 'table' ).filter( { has: page.getByRole( 'columnheader', { name: 'Result' } ) } );
		await expect( jobs.locator( 'tbody tr' ).first() ).toContainText( 'Cancelled', { timeout: 3 * 60 * 1000 } );
		await expect( page.getByRole( 'button', { name: 'Back up now' } ) ).toBeEnabled();
		await expect( card( page, 'Last backup' ) ).toContainText( /Snapshot [0-9a-f]{8}/ );

		// No snapshot came of it.
		await page.locator( '.nav-tab-wrapper' ).getByRole( 'link', { name: 'Snapshots', exact: true } ).click();
		await expect( page.locator( 'table tbody tr' ) ).toHaveCount( 1 );
	} finally {
		removeUpload( 'cancelled.bin' );
	}
} );

test( 'a files-only snapshot still restores individual files', async ( { page } ) => {
	await openBackups( page, 'settings' );
	await page.getByLabel( 'Back up the database', { exact: true } ).uncheck();
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( notice( page, 'success', 'Settings saved.' ) ).toBeVisible();
	await openBackups( page );
	const last = card( page, 'Last backup' );
	const previous = ( await last.innerText() ).match( /Snapshot [0-9a-f]{8}/ )[ 0 ];
	await page.getByRole( 'button', { name: 'Back up now' } ).click();
	await expect( last ).not.toContainText( previous, { timeout: 6 * 60 * 1000 } );
	await expect( last ).toContainText( /Snapshot [0-9a-f]{8}/ );
	const id = ( await last.innerText() ).match( /Snapshot ([0-9a-f]{8})/ )[ 1 ];

	await openBackups( page, 'restores' );
	await page.getByRole( 'link', { name: 'Restore from an existing site snapshot' } ).click();
	const choose = page.getByRole( 'button', { name: 'Open snapshot ' + id } );
	await unlocked( page, choose );
	await choose.click();
	await unlocked( page, page.locator( '.bfr-manifest' ) );
	await expect( notice( page, 'warning', 'This import requires a site manifest and a database backup.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Stage site files and rehearse the database restore' } ) ).toHaveCount( 0 );

	await openBackups( page, 'snapshots' );
	await unlocked( page, choose );
	await choose.click();
	await unlocked( page, page.locator( '.bfr-manifest' ) );
	for ( const name of siteRoot().split( '/' ).filter( Boolean ) ) {
		const directory = page.locator( '.bfr-entries' ).getByRole( 'button', { name, exact: true } );
		await unlocked( page, directory );
		await directory.click();
	}
	const license = page.getByLabel( 'Select license.txt', { exact: true } );
	await unlocked( page, license );
	await license.check();
	await page.getByRole( 'button', { name: 'Restore to staging' } ).click();
	await expect( notice( page, 'success', 'Restored 1 file to staging' ) ).toBeVisible( { timeout: 120000 } );
	const staged = page.locator( 'section.bfr-card' ).filter( { hasText: id } ).first();
	const pending = page.waitForEvent( 'download' );
	await staged.getByRole( 'button', { name: 'Download file', exact: true } ).click();
	const downloaded = await pending;
	expect( await downloaded.failure() ).toBeNull();
	expect( fs.readFileSync( await downloaded.path() ) ).toEqual( fs.readFileSync( path.join( siteRoot(), 'license.txt' ) ) );
} );
