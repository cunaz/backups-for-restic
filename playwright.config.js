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
	reporter: [ [ 'list' ] ],
	outputDir: '.tools/e2e-results',
	globalSetup: require.resolve( './tests/E2E/global-setup.js' ),
	globalTeardown: require.resolve( './tests/E2E/global-teardown.js' ),
	use: {
		baseURL: 'http://127.0.0.1:8890',
		trace: 'off',
		screenshot: 'only-on-failure',
	},
} );
