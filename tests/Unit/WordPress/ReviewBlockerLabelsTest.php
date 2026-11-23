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
}
