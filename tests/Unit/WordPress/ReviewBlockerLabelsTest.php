<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;

/** The database review shows each preflight blocker as a sentence, not as its code. */
final class ReviewBlockerLabelsTest extends TestCase {

	public function test_every_blocker_of_the_exchange_preflight_has_a_label_in_the_review(): void {
		$root    = dirname( __DIR__, 3 );
		$catalog = (string) file_get_contents( $root . '/src/Database/Restore/SwapCatalog.php' );
		preg_match_all( "/\\\$issues\[\]\s*=\s*'([a-z_]+)'|\? \[ '([a-z_]+)' \] : \[\]/", $catalog, $found );
		$codes = array_values( array_unique( array_filter( [ ...$found[1], ...$found[2] ] ) ) );
		self::assertContains( 'performance_schema', $codes );
		self::assertContains( 'wsrep_on', $codes );
		self::assertGreaterThan( 10, count( $codes ) );
		$script = (string) file_get_contents( $root . '/assets/js/admin.js' );
		self::assertSame( 1, preg_match( '/function databaseFindings\(\) \{\s*return \{(.*?)\n\t\t\};/s', $script, $labels ) );
		preg_match_all( '/^\t\t\t([a-z_]+): __\(/m', $labels[1], $named );
		self::assertSame( [], array_values( array_diff( $codes, $named[1] ) ) );
	}

	public function test_the_codes_of_roles_plugins_and_capabilities_have_labels_and_the_undo_its_own(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/admin.js' );
		$labels = static function ( string $name ) use ( $script ): array {
			self::assertSame( 1, preg_match( '/function ' . $name . '\(\) \{\s*return \{(.*?)\n\t\t\};/s', $script, $body ) );
			preg_match_all( "/^\t\t\t([a-z_]+): __\( '((?:[^'\\\\]|\\\\.)*)'/m", $body[1], $found );
			return array_combine( $found[1], $found[2] );
		};
		$apply  = $labels( 'databaseFindings' );
		$undo   = $labels( 'databaseUndoFindings' );
		$codes  = [ 'database_admin_capabilities', 'database_roles_invalid', 'database_roles_too_large', 'database_plugins_invalid', 'database_plugins_too_large' ];
		foreach ( [ ...$codes, 'database_admin_capabilities_unreadable' ] as $code ) {
			self::assertArrayHasKey( $code, $apply );
		}
		self::assertSame( $codes, array_keys( $undo ) );
		foreach ( $codes as $code ) {
			self::assertStringContainsString( 'the backup', $apply[ $code ], $code );
			self::assertStringContainsString( 'staged SQL files', $apply[ $code ], $code );
			self::assertStringContainsString( 'the earlier database', $undo[ $code ], $code );
			self::assertStringContainsString( 'Until the undo window ends, a database administrator can', $undo[ $code ], $code );
			self::assertStringNotContainsString( 'staged SQL', $undo[ $code ], $code );
		}
		self::assertStringContainsString( '1 MiB', $apply['database_roles_too_large'] . $undo['database_roles_too_large'] );
		self::assertStringContainsString( '256 KiB', $apply['database_plugins_too_large'] );
		self::assertStringContainsString( '64 KiB', $apply['database_admin_capabilities_unreadable'] );
	}
}
