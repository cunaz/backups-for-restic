<?php
/**
 * Plugin Name:       Backups for restic
 * Description:       Encrypted, deduplicated backups of files and database in the restic repository format, written in pure PHP.
 * Version:           0.1.0-dev
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       backups-for-restic
 *
 * This file must stay parseable by old PHP versions: everything that needs PHP 8.3 lives in src/.
 *
 * @package BackupsForRestic
 */

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	// WordPress refuses activation below "Requires PHP"; this guard covers a later PHP downgrade.
	return;
}

require_once __DIR__ . '/src/autoload.php';

\BackupsForRestic\Plugin::boot( __FILE__ );
