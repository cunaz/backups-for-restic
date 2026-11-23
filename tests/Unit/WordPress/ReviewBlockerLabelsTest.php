<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;

/**
 * The reviews show each blocker, finding and reason as a sentence, not as its code. The codes
 * are read from the sources that give them, so that a new one without a sentence fails here.
 */
final class ReviewBlockerLabelsTest extends TestCase {

	/**
	 * A source file of the plugin.
	 *
	 * @param string $path Path below the repository root.
	 */
	private static function source( string $path ): string {
		$text = file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
		self::assertIsString( $text, $path );
		return $text;
	}

	/**
	 * The keys of an object literal of the admin script that maps codes to sentences.
	 *
	 * @param string $pattern Regular expression whose first group is the literal's body.
	 * @return list<string> Codes.
	 */
	private static function labelled( string $pattern ): array {
		self::assertSame( 1, preg_match( $pattern, self::source( 'assets/js/admin.js' ), $labels ) );
		preg_match_all( '/^\t\t\t([a-z_]+): __\(/m', $labels[1], $named );
		self::assertNotEmpty( $named[1] );
		return $named[1];
	}

	public function test_every_blocker_of_the_exchange_preflight_has_a_label_in_the_review(): void {
		$catalog = self::source( 'src/Database/Restore/SwapCatalog.php' );
		preg_match_all( "/\\\$issues\[\]\s*=\s*'([a-z_]+)'|\? \[ '([a-z_]+)' \] : \[\]/", $catalog, $found );
		$codes = array_values( array_unique( array_filter( [ ...$found[1], ...$found[2] ] ) ) );
		self::assertContains( 'performance_schema', $codes );
		self::assertContains( 'wsrep_on', $codes );
		self::assertGreaterThan( 10, count( $codes ) );
		self::assertSame( [], array_values( array_diff( $codes, self::labelled( '/function databaseFindings\(\) \{\s*return \{(.*?)\n\t\t\};/s' ) ) ) );
	}

	public function test_every_other_blocker_and_finding_of_a_database_review_has_a_label(): void {
		$codes = [];
		// Blockers that the reviews of an apply and an undo add themselves.
		foreach ( [ 'src/Jobs/Apply/DatabaseChanges.php', 'src/Jobs/Apply/DatabaseReview.php', 'src/WordPress/Container.php' ] as $path ) {
			preg_match_all( "/\\\$blockers\[\]\s*=\s*'([a-z_]+)'/", self::source( $path ), $found );
			array_push( $codes, ...$found[1] );
		}
		// What the rehearsal reports, and the codes that an undo review takes over as blockers
		// from the checks of the generation, the administrator and the exchange.
		foreach ( [ 'src/Database/Restore/RehearsalReport.php', 'src/Database/Restore/TableExchange.php', 'src/Database/Restore/WordPressState.php', 'src/Jobs/Apply/DatabaseGeneration.php' ] as $path ) {
			preg_match_all( "/'code'\s*=>\s*'([a-z_]+)'/", self::source( $path ), $found );
			array_push( $codes, ...$found[1] );
		}
		$codes = array_values( array_unique( $codes ) );
		foreach ( [ 'database_exchange_foreign', 'database_exchange_rebuilt', 'persistent_object_cache', 'snapshot_check', 'wordpress_tables' ] as $code ) {
			self::assertContains( $code, $codes );
		}
		self::assertSame( [], array_values( array_diff( $codes, self::labelled( '/function databaseFindings\(\) \{\s*return \{(.*?)\n\t\t\};/s' ) ) ) );
	}

	public function test_every_reason_of_a_file_preview_or_an_undo_review_has_a_label(): void {
		$codes = [];
		// The planner, the manifests and the undo of one entry give reasons and few other
		// names with an underscore: every such literal is a reason but these.
		$other = [ 'file_changed_after_review', 'file_plan_large', 'file_plan_too_large', 'opcache_invalidate', 'posix_getegid', 'posix_geteuid', 'posix_getgroups', 'protected_path_invalid', 'required_bytes' ];
		foreach ( [ 'src/Restore/ApplyPlan.php', 'src/Restore/ApplyManifest.php', 'src/Restore/FileChange.php', 'src/Jobs/Apply/PlanBuild.php' ] as $path ) {
			foreach ( token_get_all( self::source( $path ) ) as $token ) {
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( "/\A'([a-z]+(?:_[a-z]+)+)'\z/", $token[1], $literal ) && ! in_array( $literal[1], $other, true ) ) {
					$codes[] = $literal[1];
				}
			}
		}
		// A reason of a single word, and the reasons that the service of the jobs gives.
		preg_match_all( "/protect\( \\\$path, '([a-z_]+)' \)|decision\( [^;]*?'(?:keep|skip|refuse|create|replace|mkdir)', '([a-z_]+)' \)/", self::source( 'src/Restore/ApplyPlan.php' ), $found );
		array_push( $codes, ...array_filter( [ ...$found[1], ...$found[2] ] ) );
		preg_match_all( "/'reason'\s*=>\s*'([a-z_]+)',|return '([a-z_]+)';/", self::source( 'src/Jobs/Apply/FileChanges.php' ), $found );
		array_push( $codes, ...array_filter( [ ...$found[1], ...$found[2] ] ) );
		$codes = array_values( array_unique( $codes ) );
		foreach ( [ 'protected', 'identical_contents', 'different_filesystem', 'overlapping_destinations', 'parent_excluded', 'staged_type_changed', 'new_children', 'undo_journal' ] as $code ) {
			self::assertContains( $code, $codes );
		}
		$labels = self::labelled( '/const reasons = \{(.*?)\n\t\t\};/s' );
		self::assertSame( [], array_values( array_diff( $codes, $labels ) ), 'A reason that the review would show as its code.' );
		self::assertSame( [], array_values( array_diff( $labels, $codes ) ), 'A label for a reason that nothing gives.' );
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
