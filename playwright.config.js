/**
 * Browser tests of the admin page (docs/04-development-and-quality.md §5.5), run against the
 * test site of bin/test-site.sh: npm run test:e2e
 *
 * One worker and no retries: the tests share one WordPress and one repository, and each
 * builds on what the one before it left. A test that only passes on a second try has found
 * something.
 */
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: 'tests/E2E',
	testMatch: '*.spec.js',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: true,
	// A first backup on a small CI machine takes a few minutes.
	timeout: 10 * 60 * 1000,
	expect: { timeout: 30 * 1000 },
	// The tests take about four minutes. The run ends before CI's job limit of 45 minutes, so
	// that the job still collects the logs of a run that hangs.
	globalTimeout: 40 * 60 * 1000,
	reporter: [ [ 'list' ] ],
	outputDir: '.tools/e2e-results',
	globalSetup: require.resolve( './tests/E2E/global-setup.js' ),
	globalTeardown: require.resolve( './tests/E2E/global-teardown.js' ),
	use: {
		baseURL: 'http://127.0.0.1:8890',
		// Without these, an action, a wait for a response or an event, a request and a navigation
		// that hang wait out the whole test. No action took more than 1.2 seconds and no
		// navigation more than 0.4 in a local run (2026-11-26) of four minutes, where CI's take
		// 3.3 to 3.7. An action may wait as long as an expectation; a navigation may wait for a
		// worker of the site to end a request at its max_execution_time of 30 seconds. A step that
		// waits for a backup, a restore or an exchange has a timeout of its own.
		actionTimeout: 30 * 1000,
		navigationTimeout: 60 * 1000,
		trace: 'off',
		screenshot: 'only-on-failure',
	},
} );
