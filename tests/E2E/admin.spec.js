/**
 * The admin page in a browser, on a WordPress that was just installed: the setup wizard with
 * its first backup, then the dashboard, the snapshots, the settings and the recovery kit, and
 * a backup that is cancelled. What an administrator sees and clicks, nothing else: no request
 * is sent by the tests themselves.
 */
const { test, expect } = require( '@playwright/test' );
const { ADMIN, signIn, openBackups, repositoryServer, addUpload, removeUpload, noLoopback } = require( './site' );

test.describe.configure( { mode: 'serial' } );

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

	// The kit has to be acknowledged.
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();
	await expect( notice( page, 'error', 'Please confirm that you stored the recovery kit.' ) ).toBeVisible();
	await page.getByLabel( 'I have stored the recovery kit outside this server.' ).check();
	await page.getByRole( 'button', { name: 'Continue', exact: true } ).click();

	// Schedule, then the first backup with its progress.
	await page.getByLabel( 'Back up', { exact: true } ).selectOption( 'daily' );
	await page.getByLabel( 'Hour', { exact: true } ).fill( '3' );
	await page.getByLabel( 'Minute', { exact: true } ).fill( '17' );
	await page.getByRole( 'button', { name: 'Save and run the first backup' } ).click();
	await expect( page.getByText( 'The first backup uploads everything once' ) ).toBeVisible();
	await expect( notice( page, 'success', 'Finished successfully.' ) ).toBeVisible( { timeout: 8 * 60 * 1000 } );

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

	// The wizard's backup is in the list of recent jobs.
	const jobs = page.locator( 'table' ).filter( { has: page.getByRole( 'columnheader', { name: 'Result' } ) } );
	await expect( jobs.locator( 'tbody tr' ).filter( { hasText: 'Succeeded' } ).first() ).toBeVisible();

	await page.locator( '.nav-tab-wrapper' ).getByRole( 'link', { name: 'Snapshots', exact: true } ).click();
	const rows = page.locator( 'table tbody tr' );
	await expect( rows ).toHaveCount( 1 );
	await expect( rows.first().locator( 'code' ) ).toHaveText( snapshot );
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
	const password = page.getByLabel( 'Your WordPress password' );
	await password.fill( 'not the password' );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	await expect( notice( page, 'error', 'Your WordPress password was not correct.' ) ).toBeVisible();
	await expect( page.locator( '.bfr-kit' ) ).toHaveCount( 0 );
	await page.getByLabel( 'Your WordPress password' ).fill( ADMIN.password );
	await page.getByRole( 'button', { name: 'Show recovery kit' } ).click();
	await expect( page.locator( '.bfr-kit textarea' ) ).toHaveValue( /RESTIC_PASSWORD='[A-Za-z0-9_-]{32}'/ );
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
