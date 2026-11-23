<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Exception\DatabaseFailed;
use PHPUnit\Framework\TestCase;

/**
 * The authority that a database review binds and the exchange compares under its write
 * barrier: the approving account and its own record of capabilities, the live role map and the
 * live list of active plugins, each by an SQL fingerprint.
 */
final class WordPressStateAuthorityTest extends TestCase {

	private const string USER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private const string CAPABILITIES = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	private const string ROLES = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr';

	private const string PLUGINS = 'pppppppppppppppppppppppppppppppppppppppppppppppppppppppppppppppp';

	/**
	 * A site whose rows have the given fingerprints; null for a missing row.
	 *
	 * @param list<string> $capabilities Fingerprints of the account's capability records.
	 */
	private static function site( ?string $user, array $capabilities, ?string $roles, ?string $plugins ): Connection {
		return new class( $user, $capabilities, $roles, $plugins ) implements Connection {
			/** @param list<string> $capabilities */
			public function __construct( private ?string $user, private array $capabilities, private ?string $roles, private ?string $plugins ) {}

			public function rows( string $sql ): array {
				$row = static fn ( ?string $value ): array => null === $value ? [] : [ [ 'f' => $value ] ];
				return match ( $sql ) {
					'SELECT SHA2(JSON_ARRAY(user_login,user_pass,user_email),256) AS f FROM `wp_users` WHERE ID=1' => $row( $this->user ),
					'SELECT SHA2(meta_value,256) AS f FROM `wp_usermeta` WHERE user_id=1 AND meta_key=0x' . bin2hex( 'wp_capabilities' ) . ' ORDER BY umeta_id LIMIT 2' => array_map( static fn ( string $value ): array => [ 'f' => $value ], $this->capabilities ),
					'SELECT SHA2(option_value,256) AS f FROM `wp_options` WHERE option_name=0x' . bin2hex( 'wp_user_roles' ) => $row( $this->roles ),
					'SELECT SHA2(option_value,256) AS f FROM `wp_options` WHERE option_name=0x' . bin2hex( 'active_plugins' ) => $row( $this->plugins ),
					default => throw new \LogicException( 'Unexpected query: ' . $sql ),
				};
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'The authority changes nothing: ' . $sql );
			}
		};
	}

	private static function authority( Connection $site ): string {
		return ( new WordPressState( $site ) )->authority( 'wp_', 1 );
	}

	public function test_an_authority_from_before_the_live_plugin_list_was_bound_never_matches(): void {
		// Before, the authority was the SHA-256 of these three fingerprints alone. Without a live
		// list of active plugins only the version prefix keeps a job confirmed then from matching.
		$before = hash( 'sha256', self::USER . self::ROLES . self::CAPABILITIES );
		$now    = self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, null ) );
		self::assertNotSame( $before, $now );
		self::assertSame( hash( 'sha256', 'backups-for-restic-authority/2:' . self::USER . self::ROLES . self::CAPABILITIES ), $now );
		self::assertSame( hash( 'sha256', 'backups-for-restic-authority/2:' . self::USER . self::ROLES . self::CAPABILITIES . self::PLUGINS ), self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, self::PLUGINS ) ) );
	}

	public function test_each_live_value_that_the_review_compared_changes_the_authority(): void {
		$base    = self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, self::PLUGINS ) );
		$changed = [
			self::authority( self::site( strrev( self::USER . 'x' ), [ self::CAPABILITIES ], self::ROLES, self::PLUGINS ) ),
			self::authority( self::site( self::USER, [ self::CAPABILITIES . 'x' ], self::ROLES, self::PLUGINS ) ),
			self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES . 'x', self::PLUGINS ) ),
			self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, self::PLUGINS . 'x' ) ),
			self::authority( self::site( self::USER, [ self::CAPABILITIES ], null, self::PLUGINS ) ),
			self::authority( self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, null ) ),
		];
		foreach ( $changed as $authority ) {
			self::assertNotSame( $base, $authority );
		}
		self::assertCount( count( $changed ), array_unique( $changed ) );
	}

	public function test_the_account_and_its_own_record_are_required_and_the_live_role_map_is_not(): void {
		// The exchange discards the running site's role map: a site without one is not refused here.
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', self::authority( self::site( self::USER, [ self::CAPABILITIES ], null, null ) ) );
		$cases   = [
			'database_admin_collision'               => self::site( null, [ self::CAPABILITIES ], self::ROLES, self::PLUGINS ),
			'database_admin_capabilities_unreadable' => self::site( self::USER, [], self::ROLES, self::PLUGINS ),
		];
		$cases[] = self::site( self::USER, [ self::CAPABILITIES, self::CAPABILITIES ], self::ROLES, self::PLUGINS );
		foreach ( $cases as $code => $site ) {
			try {
				self::authority( $site );
				self::fail( 'An authority was bound without one account and one record of its capabilities.' );
			} catch ( DatabaseFailed $refused ) {
				self::assertSame( is_string( $code ) ? $code : 'database_admin_capabilities_unreadable', $refused->context()['code'] ?? null );
			}
		}
	}

	public function test_an_administrator_and_a_table_prefix_are_required(): void {
		$site = self::site( self::USER, [ self::CAPABILITIES ], self::ROLES, self::PLUGINS );
		foreach ( [ [ 'wp_', 0 ], [ 'wp-', 1 ], [ '', 1 ], [ str_repeat( 'p', 65 ), 1 ] ] as [ $prefix, $admin ] ) {
			try {
				( new WordPressState( $site ) )->authority( $prefix, $admin );
				self::fail( 'An authority was bound for an unidentified administrator or prefix.' );
			} catch ( DatabaseFailed $refused ) {
				self::assertSame( 'The current database administrator cannot be identified.', $refused->getMessage() );
			}
		}
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', ( new WordPressState( $site ) )->authority( 'wp_', 1 ) );
	}
}
