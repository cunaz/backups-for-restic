<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\TestCase;

/**
 * The review's inspection runs in the restore's Session (ADR 0043, "Session settings"): when it
 * fails, the session gets its own settings back and the inspection's failure is the one
 * reported.
 */
final class SwapCatalogReviewTest extends TestCase {

	/**
	 * A server whose session can be set and whose first statement of the inspection fails.
	 *
	 * @param string $refuse A statement of the session's cleanup that fails as well, or ''.
	 */
	private static function server( string $refuse = '' ): object {
		return new class( $refuse ) implements Connection {
			/** @var list<string> */
			public array $writes = [];

			public DatabaseFailed $failure;

			public function __construct( private string $refuse ) {
				$this->failure = new DatabaseFailed( 'The inspection failed.', [ 'code' => 1142 ] );
			}

			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT @@SESSION.autocommit AS autocommit, ' ) ) {
					return [
						[
							'autocommit'               => '1',
							'character_set_client'     => 'latin1',
							'character_set_connection' => 'latin1',
							'character_set_results'    => 'latin1',
							'collation_connection'     => 'latin1_swedish_ci',
							'time_zone'                => 'SYSTEM',
							'foreign_key_checks'       => '1',
							'unique_checks'            => '1',
							'sql_quote_show_create'    => '1',
							'sql_select_limit'         => '1',
							'sql_notes'                => '1',
							'sql_safe_updates'         => '0',
							'completion_type'          => 'NO_CHAIN',
							'sql_mode'                 => '',
						],
					];
				}
				throw $this->failure;
			}

			public function execute( string $sql ): int {
				$this->writes[] = $sql;
				if ( '' !== $this->refuse && $this->refuse === $sql ) {
					throw new DatabaseFailed( 'The cleanup failed.' );
				}
				return 0;
			}
		};
	}

	/**
	 * Reviews with the given server and returns what it reported.
	 *
	 * @param object $server server().
	 */
	private static function reported( object $server ): DatabaseFailed {
		self::assertInstanceOf( Connection::class, $server );
		try {
			( new SwapCatalog( $server ) )->review( [ 'wp_posts' ] );
		} catch ( DatabaseFailed $reported ) {
			return $reported;
		}
		self::fail( 'A failed inspection was not reported.' );
	}

	public function test_a_failed_inspection_gives_the_session_back_and_is_reported(): void {
		$server   = self::server();
		$reported = self::reported( $server );
		self::assertSame( $server->failure, $reported );
		$cleanup = array_slice( $server->writes, array_search( 'ROLLBACK AND NO CHAIN NO RELEASE', array_slice( $server->writes, 3 ), true ) + 3 );
		self::assertSame( 'ROLLBACK AND NO CHAIN NO RELEASE', $cleanup[0] );
		self::assertContains( 'SET SESSION sql_select_limit = 1', $cleanup, 'The session has its own row limit back.' );
		self::assertCount( 14, $cleanup );
	}

	public function test_a_cleanup_that_fails_after_a_failed_inspection_does_not_hide_it(): void {
		$server = self::server( 'SET SESSION sql_select_limit = 1' );
		self::assertSame( $server->failure, self::reported( $server ) );
		self::assertContains( 'SET SESSION sql_select_limit = 1', $server->writes );
	}
}
