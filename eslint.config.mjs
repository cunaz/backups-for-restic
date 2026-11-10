/**
 * ESLint for assets/js (docs/04-development-and-quality.md §3). Run with: npm run lint:js
 *
 * The admin script is one classic script without a build step (ADR 0012). Of the rulesets of
 * @wordpress/eslint-plugin it gets the ones that apply to such a script: esnext (with jsdoc),
 * custom and i18n. Left out, each for its reason:
 *
 * - "recommended": it switches Prettier on as soon as the package is installed, and npm
 *   installs it as a peer of the plugin. Prettier would rewrite two thirds of the file.
 * - "react" and "jsx-a11y": the script has no JSX and uses no React.
 * - the import rules and the Node.js globals of "recommended-with-formatting": a classic
 *   script imports nothing and runs in a browser.
 */
import wordpress from '@wordpress/eslint-plugin';

export default [
	...wordpress.configs.esnext,
	...wordpress.configs.custom,
	...wordpress.configs.i18n,
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			// ES2020 is the baseline of ADR 0012; newer syntax is a parse error here.
			ecmaVersion: 2020,
			// Loaded with wp_enqueue_script(), not as a module.
			sourceType: 'script',
			// Core's scripts and the browser objects the script uses. Anything else is
			// reported by no-undef, so a new global is added here on purpose.
			globals: {
				wp: 'readonly',
				window: 'readonly',
				document: 'readonly',
				Blob: 'readonly',
				Node: 'readonly',
				URL: 'readonly',
			},
		},
		rules: {
			// Every string belongs to the plugin's text domain, written as a literal.
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: 'backups-for-restic' },
			],
			// The script writes "function () {" throughout, as the PHP code writes its
			// closures and as the plugin's Prettier preset would; "function() {" would
			// change 85 lines for nothing.
			'space-before-function-paren': [
				'error',
				{ anonymous: 'always', named: 'never', asyncArrow: 'always' },
			],
		},
	},
];
