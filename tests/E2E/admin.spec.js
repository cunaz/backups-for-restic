/**
 * Exercises the packaged admin UI on independent WordPress installations. Setup, backup,
 * file restore, database exchange and undo run through the same controls administrators use.
 * Fixture helpers create source and destination content. Guest requests and direct reads
 * verify maintenance, public posts and retained configuration after the jobs complete.
 */
const fs = require( 'node:fs' );
const { spawn } = require( 'node:child_process' );
const { URL } = require( 'node:url' );
const path = require( 'node:path' );
const { test, expect } = require( '@playwright/test' );
const { ADMIN, freshSite, signIn, openBackups, repositoryServer, siteRoot, wordpress, addUpload, removeUpload, noLoopback } = require( './site' );

test.describe.configure( { mode: 'serial' } );

const SOURCE_UPLOAD = 'bfr-acceptance-source.txt';
const SOURCE_BYTES = 'Uploaded to the source installation before its first backup.\n';

// A name and a link target with a right-to-left override: shown as they are, the file would
// read "bfr-namephp.txt" and the target "bfr-tegrat". They lie in the WordPress directory,
// which no restore in these tests applies.
const REVERSED_NAME = 'bfr-name\u202Etxt.php';
const REVERSED_LINK = 'bfr-link';
const REVERSED_TARGET = 'bfr-\u202Etarget';

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
	fs.writeFileSync( path.join( siteRoot(), REVERSED_NAME ), 'A name that reads otherwise.\n' );
	fs.symlinkSync( REVERSED_TARGET, path.join( siteRoot(), REVERSED_LINK ) );
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
		fs.unlinkSync( path.join( siteRoot(), REVERSED_NAME ) );
		fs.unlinkSync( path.join( siteRoot(), REVERSED_LINK ) );
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

test( 'a cancelled apply or undo whose cleanup failed shows the failure beside its state and counts', async ( { page } ) => {
	// Ended jobs, written as the runner leaves them: cancelled, and then the site could not leave
	// maintenance mode, which the runner records as the job's error. Nothing ticks an ended job.
	const jobs = [
		[ '20261126T170000Z-0c5001', 'undo', { restored: 1, removed: 2, kept: 0, unchanged: 0, apply_state: 'partly_undone' }, true ],
		[ '20261126T170000Z-0c5002', 'apply', { entries_completed: 2, entries_attempted: 3 }, true ],
		[ '20261126T170000Z-0c5003', 'apply', { entries_completed: 0, entries_attempted: 0 }, false ],
	];
	const container = '$container = new BackupsForRestic\\WordPress\\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ); ';
	wordpress(
		siteRoot(),
		container + 'foreach ( json_decode( $argv[2], true ) as [ $id, $type, $result, $failed ] ) {' +
			' $job = new BackupsForRestic\\Jobs\\Job( $id, BackupsForRestic\\Jobs\\JobType::from( $type ), "manual", time() - 120 );' +
			' $job->state = BackupsForRestic\\Jobs\\JobState::Cancelled; $job->finished_at = time() - 60; $job->result = $result;' +
			' $job->data = [ "apply" => "20261120T000000Z-0a0b07", "restore" => "20261120T000000Z-0a0b08" ];' +
			' $job->error = $failed ? new BackupsForRestic\\Jobs\\JobError( "maintenance_stuck", "The maintenance marker could not be removed.", false ) : null;' +
			' $container->jobs()->save( $job ); }',
		[ JSON.stringify( jobs ) ],
	);
	try {
		const open = ( id ) => page.goto( '/wp-admin/admin.php?page=backups-for-restic&tab=restores&job=' + id );
		// Before, a job that was not "failed" showed its state alone, and the failure was hidden.
		await open( jobs[ 0 ][ 0 ] );
		await expect( notice( page, 'error', 'The site could not leave maintenance mode.' ) ).toBeVisible();
		await expect( notice( page, 'error', 'The site could not leave maintenance mode.' ) ).toContainText( 'Delete that file if no update is running.' );
		await expect( notice( page, 'info', 'Cancelled' ) ).toBeVisible();
		await expect( notice( page, 'warning', 'The apply is partly undone: 1 restored, 2 removed, 0 kept.' ) ).toBeVisible();
		await open( jobs[ 1 ][ 0 ] );
		await expect( notice( page, 'error', 'The site could not leave maintenance mode.' ) ).toBeVisible();
		await expect( notice( page, 'info', 'Cancelled' ) ).toBeVisible();
		await expect( page.locator( '.bfr-apply-stopped' ) ).toContainText( 'The apply completed 2 of the 3 file changes that it attempted before it stopped.' );
		// A cancelled job without an error shows its state, as before.
		await open( jobs[ 2 ][ 0 ] );
		await expect( page.locator( '.bfr-apply-stopped' ) ).toContainText( 'The apply stopped before it changed any live file.' );
		await expect( notice( page, 'info', 'Cancelled' ) ).toBeVisible();
		await expect( page.locator( '#backups-for-restic-app .notice-error' ) ).toHaveCount( 0 );
	} finally {
		wordpress( siteRoot(), container + 'foreach ( array_slice( $argv, 2 ) as $id ) { BackupsForRestic\\Storage\\Files::remove_tree( $container->jobs()->storage_directory() . "/" . $id ); }', jobs.map( ( job ) => job[ 0 ] ) );
	}
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

	// A name and a link target with a right-to-left override are shown quoted with Go's
	// escapes, in an element of their own, and so is the path in the selection.
	const reversed = entries.locator( 'tr' ).filter( { hasText: 'bfr-name' } );
	await expect( reversed.locator( 'td.bfr-name bdi' ) ).toHaveText( '"bfr-name\\u202etxt.php"' );
	expect( await entries.innerText() ).not.toContain( '\u202E' );
	await expect( entries.locator( 'tr' ).filter( { hasText: REVERSED_LINK } ).locator( 'td.bfr-name' ) ).toContainText( '→ "bfr-\\u202etarget"' );
	const escaped = page.getByLabel( 'Select "bfr-name\\u202etxt.php"', { exact: true } );
	await escaped.check();
	await expect( page.locator( '.bfr-selection code' ) ).toHaveText( '"' + siteRoot() + '/bfr-name\\u202etxt.php"' );
	await escaped.uncheck();
	await expect( page.locator( '.bfr-selection' ) ).toContainText( 'Nothing is selected.' );

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
	// The entire snapshot holds every entry of the directory that is open, and the boxes say so.
	await expect( license ).toBeChecked();
	await expect( license ).toBeDisabled();
	await expect( page.locator( '.bfr-covered' ) ).toHaveText( 'The entire snapshot is selected: a restore brings back everything in it.' );
	await expect( selection ).toContainText( '1 path selected:' );
	await page.getByRole( 'button', { name: 'Clear selection' } ).click();
	await expect( license ).toBeEnabled();
	await expect( page.locator( '.bfr-covered' ) ).toBeHidden();

	await license.check();
	await expect( page.getByLabel( 'Rehearse the database import in temporary tables.' ) ).toHaveCount( 0 );
	await page.getByLabel( 'Build a ZIP for download', { exact: false } ).check();
	await page.getByRole( 'button', { name: 'Restore to staging' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Staged restores' } ) ).toBeVisible();
	const staged = page.locator( 'section.bfr-card' ).filter( { hasText: 'Download ZIP' } );
	await expect( staged ).toBeVisible( { timeout: 4 * 60 * 1000 } );
	await expect( staged.getByLabel( 'Absolute file path in the snapshot' ) ).toHaveValue( siteRoot() + '/license.txt' );

	// A restored file or ZIP needs the WordPress password for every download; the report does not.
	const password = staged.getByLabel( 'WordPress password for downloads' );
	await staged.getByRole( 'button', { name: 'Download file', exact: true } ).click();
	await expect( staged ).toContainText( 'Your WordPress password was not correct.' );
	await password.fill( 'not the password' );
	await staged.getByRole( 'button', { name: 'Download file', exact: true } ).click();
	await expect( staged ).toContainText( 'Your WordPress password was not correct.' );
	await expect( password ).toHaveValue( '' );
	await expect( password ).toBeFocused();
	const downloads = {};
	for ( const name of [ 'Download file', 'Download ZIP', 'Download restore report' ] ) {
		if ( 'Download restore report' !== name ) {
			await password.fill( ADMIN.password );
		}
		const pending = page.waitForEvent( 'download', { timeout: 60 * 1000 } );
		await staged.getByRole( 'button', { name, exact: true } ).click();
		const downloaded = await pending;
		expect( await downloaded.failure() ).toBeNull();
		downloads[ name ] = fs.readFileSync( await downloaded.path() );
		await expect( password ).toHaveValue( '' );
	}
	expect( downloads[ 'Download file' ] ).toEqual( fs.readFileSync( siteRoot() + '/license.txt' ) );
	expect( downloads[ 'Download ZIP' ].subarray( 0, 4 ).toString( 'hex' ) ).toBe( '504b0304' );
	expect( downloads[ 'Download restore report' ].toString() ).toContain( 'license.txt' );
} );

test( 'live apply and undo require a current preview and confirmation', async ( { page } ) => {
	// Request interception stays on for the whole test. When the last route of a page ends,
	// used up or removed, Playwright switches interception off, and a request that the page
	// sends in that moment can stay paused in the browser for good, neither sent nor failed.
	// This route lets every request pass and is never the last one.
	await page.route( '**/*', ( route ) => route.fallback() );
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
		await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
		const preview = staged.getByRole( 'button', { name: 'Preview live file changes' } );
		// The live file is as the snapshot holds it: the review keeps it and says why in words.
		await preview.click();
		await expect( staged.getByRole( 'cell', { name: 'Same content as the live file', exact: true } ) ).toBeVisible();
		await expect( staged.getByRole( 'cell', { name: 'identical_contents', exact: true } ) ).toHaveCount( 0 );
		await expect( staged.getByRole( 'button', { name: 'Apply to live site' } ) ).toHaveCount( 0 );

		fs.writeFileSync( file, 'Before the preview.\n' );
		await page.route( ( requestURL ) => decodeURIComponent( requestURL.href ).includes( '/apply-plan' ), async ( route ) => {
			const response = await route.fetch();
			const plan = await response.json();
			await route.fulfill( { response, json: { ...plan, required_bytes: 1048576, available_bytes: 0, enough_space: false, applicable: false } } );
		}, { times: 1 } );
		await preview.click();
		await expect( staged.locator( '.bfr-file-space' ) ).toContainText( '1.0 MB' );
		await expect( staged ).toContainText( 'There is too little disk space for these file changes.' );
		await expect( staged.getByRole( 'button', { name: 'Apply to live site' } ) ).toHaveCount( 0 );

		await preview.click();
		await expect( staged.locator( '.bfr-file-space' ) ).toContainText( 'Available disk space' );
		await expect( staged.getByRole( 'cell', { name: 'Replace file', exact: true } ) ).toBeVisible();
		// What the review does as a whole stands above the confirmation.
		await expect( staged.locator( '.bfr-apply-totals' ) ).toContainText( 'To create: 0 files and 0 directories. To replace: 1.' );

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
		// The answer to the confirmation is lost before the request reaches the server: no job
		// started, and the page says that the request may still have been accepted.
		const isApply = ( requestURL ) => /\/restores\/[^/?&]+\/apply(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
		await page.route( isApply, ( route ) => route.abort( 'connectionreset' ), { times: 1 } );
		await confirm( staged, apply, url, ADMIN.password );
		await expect( staged ).toContainText( 'The request may still have been accepted' );
		expect( fs.readFileSync( file, 'utf8' ) ).toBe( revision );
		// The server accepts the confirmation, and a proxy answers with an error page of its own:
		// the page finds the job that the confirmation started and follows it. The status, which
		// it asks first, cannot be read either, so it finds the job in the list of jobs. The route
		// of the status is in place before the confirmation, loses the first request once the
		// server has accepted it and lets every other one pass.
		let started = null;
		let accepted = false;
		let lost = 0;
		const isStatus = ( requestURL ) => /\/backups-for-restic\/v1\/status(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
		const loseStatus = ( route ) => {
			if ( ! accepted ) {
				return route.fallback();
			}
			accepted = false;
			lost++;
			return route.abort( 'connectionreset' );
		};
		await page.route( isStatus, loseStatus );
		await page.route( isApply, async ( route ) => {
			const response = await route.fetch();
			started = ( await response.json() ).job.id;
			accepted = true;
			await route.fulfill( { status: 504, contentType: 'text/html', body: '<html><body>Gateway Timeout</body></html>' } );
		}, { times: 1 } );
		await confirm( staged, apply, url, ADMIN.password );
		await expect.poll( () => started, { message: 'the server accepts the confirmation and starts a job', timeout: 60000 } ).not.toBeNull();
		await expect( page, 'the page follows the job that the confirmation started' ).toHaveURL( ( current ) => started === current.searchParams.get( 'job' ), { timeout: 60000 } );
		expect( lost, 'requests for the status lost after the server accepted the confirmation' ).toBe( 1 );
		await page.unroute( isStatus, loseStatus );
		await expect.poll( () => fs.readFileSync( file ), { timeout: 120000 } ).toEqual( original );
		await expect( notice( page, 'success', 'Finished successfully.' ) ).toBeVisible( { timeout: 120000 } );

		// The undo of the apply needs the staged files: they are kept, and the page says until when.
		await staged.getByRole( 'button', { name: 'Delete staged files' } ).click();
		await staged.getByRole( 'button', { name: 'Confirm deletion' } ).click();
		await expect( staged ).toContainText( 'These staged files are kept until ' );
		await expect( staged ).not.toContainText( 'This restore is still running.' );

		// Reading the lists again keeps an open review and what was typed into it. The page
		// follows the apply once more, held as running until the review is open.
		let hold = true;
		const isFollowed = ( requestURL ) => decodeURIComponent( requestURL.href ).includes( '/jobs/' + started );
		await page.route( isFollowed, async ( route ) => {
			const response = await route.fetch();
			const answer = await response.json();
			await route.fulfill( { response, json: hold && answer.job ? { ...answer, job: { ...answer.job, state: 'running' } } : answer } );
		} );
		await page.reload();
		const undo = page.locator( 'section.bfr-card' ).filter( { hasText: 'Preview undo' } );
		await undo.getByRole( 'button', { name: 'Preview undo' } ).click();
		const address = undo.getByLabel( 'Type the site address to confirm' );
		await address.fill( url );
		const isList = ( name ) => ( response ) => 'GET' === response.request().method() && new RegExp( 'backups-for-restic/v1/' + name + '(?:[?&]|$)' ).test( decodeURIComponent( response.url() ) );
		const reread = Promise.all( [ page.waitForResponse( isList( 'undo' ), { timeout: 60 * 1000 } ), page.waitForResponse( isList( 'restores' ), { timeout: 60 * 1000 } ) ] );
		hold = false;
		await reread;
		await expect( notice( page, 'success', 'Finished successfully.' ) ).toBeVisible();
		// The answers are handled in the page before a script of the test runs there.
		await page.evaluate( () => 0 );
		await expect( address ).toHaveValue( url );
		await expect( undo.locator( '.bfr-file-actions' ) ).toBeVisible();
		await page.unroute( isFollowed );

		await confirm( undo, 'Undo live file changes', url, ADMIN.password );
		await expect.poll( () => fs.readFileSync( file, 'utf8' ), { timeout: 120000 } ).toBe( revision );
		// The file can be back before the answer to the confirmation arrives; the page then opens
		// the undo's job, and a click on the page that it leaves is lost. The test goes on once
		// the page shows how the undo ended.
		await expect( notice( page, 'success', 'The apply is fully undone' ) ).toBeVisible( { timeout: 120000 } );
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

	// That it was shown is on record, and so are the downloads of restored files.
	await page.reload();
	await expect( page.locator( '.bfr-kit-views' ) ).toContainText( /The recovery kit was last shown on .+ to admin\./ );
	await expect( page.locator( '.bfr-download-grants' ) ).toContainText( /: admin asked for the ZIP of restore \d{8}T\d{6}Z-[0-9a-f]{6}\./ );
	await expect( page.locator( '.bfr-download-grants' ) ).toContainText( /: admin asked for \/.*\/license\.txt from restore / );
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
		const reported = page.waitForResponse( ( response ) => response.url().includes( 'environment%2Fprobe%2Freport' ) || response.url().includes( 'environment/probe/report' ), { timeout: 120 * 1000 } );
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
			'echo wp_insert_user( [ "user_login" => $argv[2], "user_pass" => $argv[3], "user_email" => "destination-owner@example.com", "role" => "administrator" ] );', [ DESTINATION_ADMIN.login, DESTINATION_ADMIN.password ] );
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
		// Connecting derives the key of the repository with scrypt in PHP; the step after it has
		// two minutes to appear, not the 30 seconds of an action.
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
		async function apply( resume = false, beforeConfirm = null ) {
			await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
			await expect( staged.getByLabel( 'Include wp-config.php in the preview.' ) ).toHaveCount( 0 );
			const preview = staged.getByRole( 'button', { name: 'Preview live file changes' } );
			if ( resume ) {
				const isPlan = ( response ) => response.request().method() === 'POST' && decodeURIComponent( response.url() ).includes( '/apply-plan' );
				const [ queued ] = await Promise.all( [ destination.waitForResponse( ( response ) => isPlan( response ) && 202 === response.status(), { timeout: 60 * 1000 } ), preview.click() ] );
				const id = ( await queued.json() ).job.id;
				await expect( staged.getByRole( 'columnheader', { name: 'Snapshot path' } ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
				await destination.reload();
				await staged.getByText( 'Restore files to the live site', { exact: true } ).click();
				await Promise.all( [
					destination.waitForResponse( ( response ) => isPlan( response ) && 200 === response.status() && response.request().postDataJSON().plan_job === id, { timeout: 60 * 1000 } ),
					preview.click(),
				] );
				// The stored preview is shown as what it is, with the time it was finished, a way to
				// inspect the live site again, and the confirmation.
				const stored = staged.locator( '.bfr-stored-preview' );
				await expect( stored ).toContainText( 'This preview was finished on' );
				await expect( staged.getByRole( 'button', { name: 'Apply to live site' } ) ).toBeVisible();
				const [ again ] = await Promise.all( [
					destination.waitForResponse( ( response ) => isPlan( response ) && 202 === response.status(), { timeout: 60 * 1000 } ),
					stored.getByRole( 'button', { name: 'Inspect again' } ).click(),
				] );
				expect( ( await again.json() ).job.id ).not.toBe( id );
				await expect( staged.getByRole( 'columnheader', { name: 'Snapshot path' } ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
				await expect( staged.locator( '.bfr-stored-preview' ) ).toHaveCount( 0 );
			} else {
				await preview.click();
			}
			await expect( staged.getByRole( 'columnheader', { name: 'Snapshot path' } ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
			// A preview of more than one page says what it does as a whole above the confirmation.
			await expect( staged.locator( '.bfr-apply-totals' ) ).toContainText( /To create: \d+ files and \d+ directories\. To replace: \d+\. Left as they are: [1-9]\d*\. Skipped: \d+\. Refused: 0\./ );
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
			if ( beforeConfirm ) {
				beforeConfirm();
			}
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
		// How far the apply got: what it changed can be undone.
		await expect( destination.locator( '.bfr-apply-stopped' ) ).toHaveText( /^The apply (stopped before it changed any live file\.|completed \d+ of the \d+ file changes? that it attempted before it stopped\. They can be undone on the Restores tab while the recovery window lasts, a change that was interrupted included\.)$/ );
		expect( fs.existsSync( marker ) ).toBe( false );
		expect( ( await request.get( target.url + '/' ) ).status() ).toBe( 200 );
		// A file that the reviewed plan creates appears between the confirmation and the job: the
		// apply stops before its first change and says why, not that another job is running.
		const uploadsDirectory = path.dirname( upload );
		const hadUploads = fs.existsSync( uploadsDirectory );
		await apply( false, () => {
			fs.mkdirSync( uploadsDirectory, { recursive: true } );
			fs.writeFileSync( upload, 'Written on the destination after the review.\n' );
		} );
		await expect( notice( destination, 'error', 'changed after the review, so the apply stopped.' ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
		await expect( notice( destination, 'error', 'Another backup job is still running.' ) ).toHaveCount( 0 );
		await expect( destination.locator( '.bfr-apply-stopped' ) ).toHaveText( 'The apply stopped before it changed any live file.' );
		expect( fs.readFileSync( upload, 'utf8' ) ).toBe( 'Written on the destination after the review.\n' );
		fs.rmSync( upload );
		if ( ! hadUploads ) {
			fs.rmSync( uploadsDirectory, { recursive: true } );
		}
		await apply();
		await expect( notice( destination, 'success', 'Finished successfully.' ) ).toBeVisible( { timeout: 6 * 60 * 1000 } );
		expect( fs.readFileSync( upload, 'utf8' ) ).toBe( SOURCE_BYTES );
		expect( fs.readFileSync( configFile ) ).toEqual( configuration );
		await settings.reload();
		await expect( settings.getByLabel( 'Keep one snapshot per day, for this many days' ) ).toHaveValue( '13' );
		// A role whose only difference is a stored denial, which the review has to show as well.
		wordpress( target.root, 'get_role( "editor" )->add_cap( "update_core", false );' );
		const db = staged.locator( 'details' ).filter( { has: destination.getByText( 'Restore tables to the live database', { exact: true } ) } );
		await db.getByText( 'Restore tables to the live database', { exact: true } ).click();
		const [ review ] = await Promise.all( [
			destination.waitForResponse( ( response ) => response.request().method() === 'POST' && decodeURIComponent( response.url() ).includes( '/database/plan' ), { timeout: 120 * 1000 } ),
			db.getByRole( 'button', { name: 'Preview live database changes' } ).click(),
		] );
		expect( review.status() ).toBe( 200 );
		expect( review.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		await expect( db.getByRole( 'status' ).filter( { hasText: 'Database preview ready.' } ) ).toBeVisible();
		await expect( db ).toContainText( 'wp_posts' );
		// What the exchange keeps from this site, and that roles, plugins and events come from the backup.
		await expect( db ).toContainText( 'The exchange keeps only this from the running site:' );
		await expect( db ).toContainText( 'Your user account with all of its user metadata, including your active sessions.' );
		await expect( db ).toContainText( 'Everything else comes from the backup, among it:' );
		await expect( db ).toContainText( 'The user roles and their capabilities, for every account.' );
		const editor = db.locator( 'table.bfr-database-roles tr' ).filter( { hasText: 'editor' } );
		await expect( editor ).toContainText( 'Capabilities change' );
		await expect( editor ).toContainText( 'Denied now, not afterwards:' );
		await expect( editor ).toContainText( 'update_core' );
		await expect( db ).toContainText( 'A denial takes the capability from an account that holds this role together with another role that grants it' );
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
				await expect( destination, 'the page follows the job that the confirmation started' ).toHaveURL( ( url ) => url.href !== previous && url.searchParams.has( 'job' ), { timeout: 120 * 1000 } );
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
			await expect( undo ).toContainText( 'Everything else comes back with the earlier database, among it:' );
			await expect( undo.locator( 'table.bfr-database-roles tr' ).filter( { hasText: 'editor' } ) ).toContainText( 'Denied afterwards, not now:' );
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
	// A restored file is site content: its download needs the WordPress password.
	await staged.getByLabel( 'WordPress password for downloads' ).fill( ADMIN.password );
	const pending = page.waitForEvent( 'download', { timeout: 60 * 1000 } );
	await staged.getByRole( 'button', { name: 'Download file', exact: true } ).click();
	const downloaded = await pending;
	expect( await downloaded.failure() ).toBeNull();
	expect( fs.readFileSync( await downloaded.path() ) ).toEqual( fs.readFileSync( path.join( siteRoot(), 'license.txt' ) ) );
} );

test( 'a restore of a snapshot that another client forgot says so', async ( { page } ) => {
	await openBackups( page );
	const id = ( await card( page, 'Last backup' ).innerText() ).match( /Snapshot ([0-9a-f]{8})/ )[ 1 ];
	await openBackups( page, 'snapshots' );
	const open = page.getByRole( 'button', { name: 'Open snapshot ' + id } );
	await unlocked( page, open );
	await open.click();
	await unlocked( page, page.locator( '.bfr-manifest' ) );
	for ( const name of siteRoot().split( '/' ).filter( Boolean ) ) {
		const directory = page.locator( '.bfr-entries' ).getByRole( 'button', { name, exact: true } );
		await unlocked( page, directory );
		await directory.click();
	}
	const license = page.getByLabel( 'Select license.txt', { exact: true } );
	await unlocked( page, license );
	await license.check();
	// After the page listed it, another client forgets the snapshot. It is put back afterwards.
	const snapshots = path.join( repositoryServer().directory, 'data', 'site', 'snapshots' );
	const file = fs.readdirSync( snapshots ).find( ( name ) => name.startsWith( id ) );
	const aside = path.join( repositoryServer().directory, 'forgotten-' + file );
	fs.renameSync( path.join( snapshots, file ), aside );
	try {
		await page.getByRole( 'button', { name: 'Restore to staging' } ).click();
		// Before: "Another program kept changing the repository while this job worked on it.
		// The job started over three times and then stopped."
		const failed = notice( page, 'error', 'The snapshot of this restore is not in the repository.' );
		await expect( failed ).toBeVisible( { timeout: 2 * 60 * 1000 } );
		await expect( failed ).toContainText( 'The restore stopped and kept no files.' );
		await expect( failed ).not.toContainText( 'started over' );
	} finally {
		fs.renameSync( aside, path.join( snapshots, file ) );
	}
} );

test( 'a binding to private tables on another database server is reviewed and discarded on the Restores tab', async ( { page } ) => {
	// A database restore that keeps the earlier tables for its undo, on a database server that
	// the site no longer uses, as after a failover. The name of its database holds a right-to-left
	// override, which the page shows escaped. A second one has no undo left.
	const job = '20261126T140000Z-0d1700';
	const other = '20261126T140000Z-0d1701';
	const jobs = path.join( siteRoot(), 'wp-content/backups-for-restic/jobs' );
	const stored = ( id ) => JSON.parse( fs.readFileSync( path.join( jobs, id, 'job.json' ), 'utf8' ) );
	const create = 'global $wpdb; $container = new BackupsForRestic\\WordPress\\Container( WP_PLUGIN_DIR . "/backups-for-restic/backups-for-restic.php" ); $apply = new BackupsForRestic\\Jobs\\Job( $argv[2], BackupsForRestic\\Jobs\\JobType::DatabaseApply, "manual", time() - 120 ); $apply->state = BackupsForRestic\\Jobs\\JobState::Succeeded; $apply->finished_at = time() - 60; $apply->data = [ "restore" => "20261126T130000Z-0d16ff", "user" => 1, "digest" => str_repeat( "d", 64 ), "database_owner" => [ "version" => 1, "prefix" => $wpdb->prefix, "fingerprint" => str_repeat( "f", 64 ), "count" => 2, "token" => str_repeat( $argv[4], 32 ), "database" => $argv[3], "uuid" => "7c4dd2a2-d1b4-11f1-9b6f-0242ac120002" ] ] + ( "c" === $argv[4] ? [ "database_generation" => "applied", "retained_until" => time() + 3600 ] : [] ); $container->jobs()->save( $apply );';
	wordpress( siteRoot(), create, [ job, 'bfr_‮site', 'c' ] );
	wordpress( siteRoot(), create, [ other, 'bfr_moved', 'e' ] );
	// Request interception stays on for the whole test (see the test of live apply and undo).
	await page.route( '**/*', ( route ) => route.fallback() );
	const url = new URL( page.url() ).origin;
	const undo = card( page, job ).filter( { has: page.getByRole( 'button', { name: 'Preview database undo' } ) } );
	const foreign = ( id ) => card( page, id ).filter( { has: page.getByRole( 'button', { name: 'Review the discard of this binding' } ) } ).filter( { hasNot: page.getByRole( 'button', { name: 'Preview database undo' } ) } );
	const listed = ( id ) => page.locator( '.bfr-discarded-binding' ).filter( { has: page.getByRole( 'heading', { name: id, exact: true } ) } );
	async function confirm( id, address, password ) {
		await foreign( id ).getByLabel( 'I have reviewed what this discard forgets and noted the table names.' ).check();
		await foreign( id ).getByLabel( 'Type the site address to confirm' ).fill( address );
		await foreign( id ).getByLabel( 'Your WordPress password' ).fill( password );
		await foreign( id ).getByRole( 'button', { name: 'Discard binding', exact: true } ).click();
	}
	const isDiscard = ( requestURL ) => /\/database-changes\/[^/?&]+\/discard(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
	const isReview = ( requestURL ) => /\/database-changes\/[^/?&]+\/discard-review(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
	const isList = ( requestURL ) => /\/database-changes\/foreign(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
	// What the answers say on a site that allows no changes to its files, as under DISALLOW_FILE_MODS.
	const refused = { write_allowed: false, write_lacking: [ 'update_core' ], file_mods_disallowed: true };
	try {
		await openBackups( page, 'restores' );
		await expect( page.getByRole( 'heading', { name: 'Private tables on another database server', exact: true } ) ).toBeVisible();
		// The undo of the exchange cannot be reviewed while its tables are elsewhere; its card offers the discard.
		await undo.getByRole( 'button', { name: 'Preview database undo' } ).click();
		await expect( undo ).toContainText( 'A database restore keeps private tables on another database server or in another database.' );
		await expect( undo.getByRole( 'button', { name: 'Review the discard of this binding' } ) ).toBeVisible();

		await foreign( job ).getByRole( 'button', { name: 'Review the discard of this binding' } ).click();
		const review = foreign( job ).locator( '.bfr-database-discard' );
		await expect( review.locator( 'bdi' ).filter( { hasText: '"bfr_\\u202esite"' } ) ).toBeVisible();
		await expect( review ).not.toContainText( '‮' );
		await expect( review ).toContainText( '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002' );
		const tables = foreign( job ).locator( '.bfr-database-discard-tables code' );
		await expect( tables ).toHaveCount( 7 );
		await expect( tables.first() ).toHaveText( '_bfr_r_' + 'c'.repeat( 32 ) + '_p' );
		await expect( foreign( job ) ).toContainText( 'The discard ends the undo of this database restore for good.' );
		await expect( foreign( job ).locator( '.bfr-discard-kept li' ) ).toHaveText( [
			'The database that this site uses now: the discard changes none of its tables.',
			'The private tables, wherever they are: the plugin drops none of them, now or later.',
			'The database restore job and its log, which record the discard, who confirmed it and these table names. For 90 days after the discard, or until you mark the tables as taken care of, the job history keeps them, and the Restores tab and "wp backups-for-restic database-discarded" list these table names.',
		] );

		await confirm( job, url + '/another-site', ADMIN.password );
		await expect( foreign( job ) ).toContainText( 'type the exact site address' );
		await expect( foreign( job ).getByLabel( 'Type the site address to confirm' ) ).toBeFocused();
		await confirm( job, url, 'incorrect' );
		await expect( foreign( job ) ).toContainText( 'Your WordPress password was not correct.' );
		await expect( foreign( job ).getByLabel( 'Your WordPress password' ) ).toBeFocused();
		// The answer is lost before the request reaches the server: nothing is discarded, the page
		// asks again and the binding stays listed with its review.
		await page.route( isDiscard, ( route ) => route.abort( 'connectionreset' ), { times: 1 } );
		const asked = page.waitForResponse( ( response ) => isList( new URL( response.url() ) ) );
		await confirm( job, url, ADMIN.password );
		await expect( foreign( job ) ).toContainText( 'The answer to the confirmation did not arrive, so the binding may or may not have been discarded.' );
		await asked;
		await expect( foreign( job ).locator( '.bfr-database-discard-tables code' ) ).toHaveCount( 7 );
		expect( stored( job ).data ).toHaveProperty( 'database_owner' );
		// Another request holds the runner lock: the review stays valid, and so does the form as it
		// was filled in. Before, the page removed it as if the review had changed.
		const holder = spawn( 'php', [ '-r', '$lock = fopen( $argv[1], "c" ); flock( $lock, LOCK_EX ); echo "held\n"; fgets( STDIN );', path.join( siteRoot(), 'wp-content/backups-for-restic/runner.lock' ) ], { stdio: [ 'pipe', 'pipe', 'inherit' ] } );
		const released = new Promise( ( resolve ) => holder.once( 'exit', resolve ) );
		await new Promise( ( resolve ) => holder.stdout.once( 'data', resolve ) );
		try {
			await confirm( job, url, ADMIN.password );
			await expect( foreign( job ) ).toContainText( 'The review is still valid: confirm it again in a moment.' );
		} finally {
			holder.stdin.end();
			await released;
		}
		await expect( foreign( job ).getByLabel( 'I have reviewed what this discard forgets and noted the table names.' ) ).toBeChecked();
		await expect( foreign( job ).getByLabel( 'Type the site address to confirm' ) ).toHaveValue( url );
		await expect( foreign( job ).getByLabel( 'Your WordPress password' ) ).toBeFocused();
		expect( stored( job ).data ).toHaveProperty( 'database_owner' );

		// The list that the page reads after the discard says that this account may not end it.
		await page.route( isList, async ( route ) => {
			const response = await route.fetch();
			await route.fulfill( { response, json: { ...( await response.json() ), ...refused } } );
		}, { times: 1 } );
		await confirm( job, url, ADMIN.password );
		const result = page.locator( '.bfr-discarded' );
		await expect( result ).toContainText( 'The binding was discarded. Nothing was dropped.' );
		await expect( result ).toBeFocused();
		await expect( result.locator( 'bdi' ).filter( { hasText: '"bfr_\\u202esite"' } ) ).toBeVisible();
		await expect( result.locator( '.bfr-database-discarded-tables code' ) ).toHaveCount( 7 );
		// The binding leaves the list of those to discard; its tables stay listed until they are taken care of.
		await expect( foreign( job ) ).toHaveCount( 0 );
		await expect( undo ).toHaveCount( 0 );
		await expect( listed( job ).locator( '.bfr-discarded-binding-tables code' ) ).toHaveCount( 7 );
		await expect( listed( job ).locator( 'bdi' ).filter( { hasText: '"bfr_\\u202esite"' } ) ).toBeVisible();
		await expect( listed( job ) ).toContainText( 'so this page cannot mark these tables as taken care of here. On the command line, "wp backups-for-restic database-discarded ' + job + ' --done" can, run without --user.' );
		await expect( listed( job ).getByRole( 'button', { name: 'Mark these tables as taken care of' } ) ).toHaveCount( 0 );
		await expect( result ).toBeVisible();
		const recorded = stored( job );
		expect( recorded.data ).not.toHaveProperty( 'database_owner' );
		expect( recorded.data.database_discarded.via ).toBe( 'rest' );
		expect( recorded.warnings.at( -1 ) ).toContain( '_bfr_r_' + 'c'.repeat( 32 ) + '_b1' );
		// A log that cannot be read is announced: the focus stays on the button. Before, its
		// notice had no role, and a screen reader said nothing.
		const isLog = ( requestURL ) => /\/jobs\/[^/?&]+\/log(?:[?&]|$)/.test( decodeURIComponent( requestURL.href ) );
		await page.route( isLog, ( route ) => route.fulfill( { status: 500, json: { code: 'backups_for_restic_environment', message: 'The log could not be read.', data: { status: 500, hint: '' } } } ), { times: 1 } );
		await listed( job ).getByRole( 'button', { name: 'Show the log of this job' } ).click();
		await expect( listed( job ).locator( '.bfr-job-log' ).getByRole( 'alert' ) ).toContainText( 'The log could not be read.' );
		// The log of that job opens here, whether or not the Logs tab still lists the job.
		await listed( job ).getByRole( 'button', { name: 'Show the log of this job' } ).click();
		await expect( listed( job ).locator( '.bfr-log' ) ).toContainText( 'Database restore binding discarded.' );

		// On a site that allows no changes to its files, the review says why this page cannot discard
		// and names the command that can. Before, it said only that the account could not.
		await page.route( isReview, async ( route ) => {
			const response = await route.fetch();
			await route.fulfill( { response, json: { ...( await response.json() ), ...refused, review_token: null } } );
		}, { times: 1 } );
		await foreign( other ).getByRole( 'button', { name: 'Review the discard of this binding' } ).click();
		await expect( foreign( other ) ).toContainText( 'This site does not allow changes to its files (DISALLOW_FILE_MODS, or a filter of file_mod_allowed).' );
		await expect( foreign( other ) ).toContainText( 'On the command line, "wp backups-for-restic database-discard ' + other + '" can, run without --user.' );
		await expect( foreign( other ).getByRole( 'button', { name: 'Discard binding', exact: true } ) ).toHaveCount( 0 );
		// The answer is lost after the server recorded the discard: the page asks again and shows the result.
		await foreign( other ).getByRole( 'button', { name: 'Review the discard of this binding' } ).click();
		await expect( foreign( other ).locator( '.bfr-database-discard-tables code' ) ).toHaveCount( 7 );
		await page.route( isDiscard, async ( route ) => {
			await route.fetch();
			await route.abort( 'connectionreset' );
		}, { times: 1 } );
		await confirm( other, url, ADMIN.password );
		await expect( result ).toContainText( 'The answer to the confirmation was lost, but the binding was discarded. Nothing was dropped.' );
		await expect( result ).toBeFocused();
		await expect( result.locator( '.bfr-database-discarded-tables code' ).first() ).toHaveText( '_bfr_r_' + 'e'.repeat( 32 ) + '_p' );
		await expect( foreign( other ) ).toHaveCount( 0 );
		await expect( listed( other ).locator( '.bfr-discarded-binding-tables code' ) ).toHaveCount( 7 );
		expect( stored( other ).data ).not.toHaveProperty( 'database_owner' );

		// Taken care of: the tables are no longer listed, and the job history prunes the job as any other.
		await listed( job ).getByRole( 'button', { name: 'Mark these tables as taken care of' } ).click();
		await expect( listed( job ) ).toHaveCount( 0 );
		await expect( listed( other ) ).toHaveCount( 1 );
		expect( stored( job ).data.database_discarded.done.via ).toBe( 'rest' );
	} finally {
		for ( const id of [ job, other ] ) {
			fs.rmSync( path.join( jobs, id ), { recursive: true, force: true } );
		}
	}
} );
