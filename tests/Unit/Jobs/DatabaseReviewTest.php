<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\PluginList;
use BackupsForRestic\Database\Restore\RoleMap;
use BackupsForRestic\Jobs\Apply\DatabaseReview;
use BackupsForRestic\Jobs\Apply\ExchangeSite;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\WordPressRoles;
use PHPUnit\Framework\TestCase;

/**
 * What a database review tells the administrator before they confirm: what the exchange keeps
 * from the running site, that roles, active plugins and scheduled events come from the other
 * generation, how the two differ, and whether the administrator keeps what a restore needs.
 */
final class DatabaseReviewTest extends TestCase {

	private const string OWN = 'backups-for-restic/backups-for-restic.php';

	/** The default backup capability and the four that a database restore adds, as WordPress names them. */
	private const array ASKED = [ 'manage_options', ...WordPressRoles::DATABASE ];

	/** The same, each once: manage_options is the default backup capability as well. */
	private const array REQUIRED = WordPressRoles::DATABASE;

	private string $directory;

	/** @var list<string> Plugin paths whose file the review looked for. */
	private array $looked = [];

	protected function setUp(): void {
		$this->directory = TempDir::create( 'database-review' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * A reviewer whose site has the given files of plugins.
	 *
	 * @param list<string> $files Plugin paths whose file exists.
	 * @param list<string> $capabilities Capabilities that a restore needs.
	 */
	private function reviewer( array $files = [], array $capabilities = self::ASKED, ?string $plugin = self::OWN ): DatabaseReview {
		$jobs = new JobStore( $this->directory . '/jobs', new Redactor(), new FixedRandom() );
		return new DatabaseReview(
			$jobs,
			new Staging( $this->directory . '/restores', $jobs, new FrozenClock() ),
			24,
			'wp_',
			new ExchangeSite(
				$plugin,
				static fn (): array => $capabilities,
				function ( string $path ) use ( $files ): bool {
					$this->looked[] = $path;
					return in_array( $path, $files, true );
				}
			)
		);
	}

	/**
	 * The live site's options and the approving administrator's own capabilities, read as
	 * WordPressState reads them: a value longer than the bound as its length.
	 *
	 * @param array<string,string>     $options Stored option values by name.
	 * @param string|list<string>|null $own The administrator's record of capabilities; a list for several records.
	 */
	private static function site( array $options, string|array|null $own ): Connection {
		return new class( $options, $own ) implements Connection {
			/**
			 * @param array<string,string>     $options
			 * @param string|list<string>|null $own
			 */
			public function __construct( private array $options, private string|array|null $own ) {}

			public function rows( string $sql ): array {
				if ( 1 === preg_match( '/\ASELECT LENGTH\(option_value\) AS stored_bytes,IF\(LENGTH\(option_value\)<=(\d+),option_value,NULL\) AS stored_value FROM `wp_options` WHERE option_name=0x([0-9a-f]+) LIMIT 2\z/', $sql, $match ) ) {
					$name = (string) hex2bin( $match[2] );
					return array_key_exists( $name, $this->options ) ? [ self::bounded( $this->options[ $name ], (int) $match[1] ) ] : [];
				}
				if ( 1 === preg_match( '/\ASELECT LENGTH\(meta_value\) AS stored_bytes,IF\(LENGTH\(meta_value\)<=(\d+),meta_value,NULL\) AS stored_value FROM `wp_usermeta` WHERE user_id=1 AND meta_key=0x([0-9a-f]+) LIMIT 2\z/', $sql, $match ) ) {
					TestCase::assertSame( 'wp_capabilities', hex2bin( $match[2] ) );
					$records = is_array( $this->own ) ? $this->own : ( null === $this->own ? [] : [ $this->own ] );
					return array_map( static fn ( string $value ): array => self::bounded( $value, (int) $match[1] ), array_slice( $records, 0, 2 ) );
				}
				throw new \LogicException( 'Unexpected query: ' . $sql );
			}

			/** @return array{stored_bytes:string,stored_value:string|null} */
			private static function bounded( string $value, int $limit ): array {
				return [
					'stored_bytes' => (string) strlen( $value ),
					'stored_value' => strlen( $value ) <= $limit ? $value : null,
				];
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'A review changes nothing: ' . $sql );
			}
		};
	}

	/**
	 * A live site with the given roles and plugins and an administrator account.
	 *
	 * @param array<array-key,mixed> $roles Role map.
	 * @param list<string>           $plugins Active plugins.
	 * @param array<array-key,mixed> $own The administrator's own roles and capabilities.
	 */
	private static function live( array $roles, array $plugins, array $own = [ 'administrator' => true ] ): Connection {
		return self::site(
			[
				'wp_user_roles'  => serialize( $roles ),
				'active_plugins' => serialize( $plugins ),
			],
			serialize( $own )
		);
	}

	public function test_names_what_the_exchange_keeps_and_what_comes_with_the_other_generation(): void {
		$facts = $this->reviewer()->state( self::live( WordPressRoles::defaults(), [ self::OWN ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [ self::OWN ] ) );
		self::assertSame( [], $facts['blockers'] );
		self::assertSame( self::REQUIRED, $facts['capabilities'] );
		self::assertSame( DatabaseReview::blank( self::OWN ), $facts['state'] );
		self::assertSame( [ 'site_address', 'database_version', 'cron_lock', 'plugin_data', 'administrator' ], $facts['state']['kept'] );
		self::assertSame( [ 'roles', 'active_plugins', 'scheduled_events' ], $facts['state']['restored'] );
		self::assertSame( [], $this->looked );
	}

	public function test_shows_the_capabilities_and_plugins_that_the_backup_takes_away_and_brings(): void {
		// The finding: after the backup, the subscriber role gained two capabilities and a plugin was activated.
		$live  = self::live( WordPressRoles::with( 'subscriber', [ 'manage_options', 'edit_users' ] ), [ self::OWN, 'activated-later/plugin.php' ] );
		$facts = $this->reviewer( [ 'activated-later/plugin.php' ] )->state( $live, 1, serialize( WordPressRoles::defaults() ), serialize( [ 'akismet/akismet.php', self::OWN ] ) );
		self::assertSame( [], $facts['blockers'] );
		self::assertSame(
			[
				'entries'  => [
					[
						'role'            => 'subscriber',
						'change'          => 'changed',
						'added'           => [
							'entries' => [],
							'more'    => 0,
						],
						'removed'         => [
							'entries' => [ 'edit_users', 'manage_options' ],
							'more'    => 0,
						],
						'denials_added'   => [
							'entries' => [],
							'more'    => 0,
						],
						'denials_removed' => [
							'entries' => [],
							'more'    => 0,
						],
					],
				],
				'more'     => 0,
				'readable' => true,
			],
			$facts['state']['roles']
		);
		self::assertSame(
			[
				'deactivated' => [
					'entries' => [
						[
							'plugin' => 'activated-later/plugin.php',
							'file'   => true,
						],
					],
					'more'    => 0,
				],
				'activated'   => [
					'entries' => [
						[
							'plugin' => 'akismet/akismet.php',
							'file'   => false,
						],
					],
					'more'    => 0,
				],
				'readable'    => true,
			],
			$facts['state']['plugins']
		);
		self::assertSame(
			[
				'entry' => self::OWN,
				'added' => false,
			],
			$facts['state']['plugin']
		);
	}

	public function test_this_plugin_is_added_when_the_backup_lacks_it_and_is_not_listed_as_a_change(): void {
		$facts = $this->reviewer()->state( self::live( WordPressRoles::defaults(), [ self::OWN ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [] ) );
		self::assertSame( [], $facts['blockers'] );
		self::assertTrue( $facts['state']['plugin']['added'] );
		self::assertSame( [], $facts['state']['plugins']['deactivated']['entries'] );
		self::assertSame( [], $facts['state']['plugins']['activated']['entries'] );
		// Loaded by other means: nothing is added, and the backup's list is taken as it is.
		$other = $this->reviewer( plugin: null )->state( self::live( WordPressRoles::defaults(), [ self::OWN ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [] ) );
		self::assertSame(
			[
				'entry' => null,
				'added' => false,
			],
			$other['state']['plugin']
		);
		self::assertSame( self::OWN, $other['state']['plugins']['deactivated']['entries'][0]['plugin'] );
	}

	public function test_each_list_shows_fifty_entries_and_counts_the_rest(): void {
		$live    = array_map( static fn ( int $i ): string => sprintf( 'live-%03d/plugin.php', $i ), range( 1, 120 ) );
		$backup  = array_map( static fn ( int $i ): string => sprintf( 'backup-%03d/plugin.php', $i ), range( 1, 51 ) );
		$roles   = WordPressRoles::defaults();
		$changed = $roles;
		foreach ( range( 1, 55 ) as $i ) {
			$changed[ sprintf( 'role-%02d', $i ) ] = [
				'name'         => 'Role',
				'capabilities' => array_fill_keys( array_map( static fn ( int $c ): string => sprintf( 'cap-%02d', $c ), range( 1, 60 ) ), true ),
			];
		}
		$facts = $this->reviewer()->state( self::live( $roles, [ self::OWN, ...$live ] ), 1, serialize( $changed ), serialize( [ self::OWN, ...$backup ] ) );
		self::assertSame( DatabaseReview::LIST_LIMIT, count( $facts['state']['plugins']['deactivated']['entries'] ) );
		self::assertSame( 70, $facts['state']['plugins']['deactivated']['more'] );
		self::assertSame( 'live-001/plugin.php', $facts['state']['plugins']['deactivated']['entries'][0]['plugin'] );
		self::assertSame( 'live-050/plugin.php', $facts['state']['plugins']['deactivated']['entries'][49]['plugin'] );
		self::assertSame( 1, $facts['state']['plugins']['activated']['more'] );
		self::assertCount( 50, $facts['state']['roles']['entries'] );
		self::assertSame( 5, $facts['state']['roles']['more'] );
		$first = $facts['state']['roles']['entries'][0];
		self::assertSame( [ 'role-01', 'added' ], [ $first['role'], $first['change'] ] );
		self::assertSame( 50, count( $first['added']['entries'] ) );
		self::assertSame( 10, $first['added']['more'] );
		// Only the plugins that the review shows are looked for on the site.
		self::assertCount( 100, $this->looked );
	}

	public function test_an_administrator_whom_the_backup_roles_leave_without_a_needed_capability_is_refused(): void {
		$roles = WordPressRoles::defaults() + [
			'site_owner' => [
				'name'         => 'Site owner',
				'capabilities' => array_fill_keys( self::REQUIRED, true ),
			],
		];
		$facts = $this->reviewer()->state( self::live( $roles, [ self::OWN ], [ 'site_owner' => true ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [ self::OWN ] ) );
		self::assertSame( [ 'database_admin_capabilities' ], $facts['blockers'] );
		self::assertSame( [ 'manage_options', 'edit_users', 'promote_users', 'update_core' ], $facts['state']['missing_capabilities'] );
		self::assertSame( 'site_owner', $facts['state']['roles']['entries'][0]['role'] );
		self::assertSame( 'removed', $facts['state']['roles']['entries'][0]['change'] );
		// An editor whose own list grants the rest is enough; one capability short is not.
		$editor = $this->reviewer()->state(
			self::live(
				$roles,
				[ self::OWN ],
				[
					'editor'         => true,
					'manage_options' => true,
					'edit_users'     => true,
					'promote_users'  => true,
				]
			),
			1,
			serialize( WordPressRoles::defaults() ),
			serialize( [ self::OWN ] )
		);
		self::assertSame( [ 'update_core' ], $editor['state']['missing_capabilities'] );
		$backup = $this->reviewer( capabilities: [ 'backups' ] )->state( self::live( $roles, [ self::OWN ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [ self::OWN ] ) );
		self::assertSame( [ 'backups' ], $backup['state']['missing_capabilities'] );
	}

	public function test_values_of_the_generation_that_becomes_live_are_refused_with_codes_that_name_them(): void {
		$live  = self::live( WordPressRoles::defaults(), [ self::OWN ] );
		$roles = serialize( WordPressRoles::defaults() );
		$list  = serialize( [ self::OWN ] );
		$cases = [
			'roles not as WordPress writes them' => [ 'O:8:"stdClass":0:{}', $list, [ 'database_roles_invalid' ] ],
			'roles longer than the bound'        => [ RoleMap::MAX_BYTES + 1, $list, [ 'database_roles_too_large' ] ],
			'plugins not as WordPress writes'    => [ $roles, serialize( [ 'k' => self::OWN ] ), [ 'database_plugins_invalid' ] ],
			'plugins longer than the bound'      => [ $roles, PluginList::MAX_BYTES + 1, [ 'database_plugins_too_large' ] ],
			'both absent'                        => [ null, null, [ 'database_roles_invalid', 'database_plugins_invalid' ] ],
		];
		foreach ( $cases as $case => [ $next_roles, $next_plugins, $blockers ] ) {
			$facts = $this->reviewer()->state( $live, 1, $next_roles, $next_plugins );
			self::assertSame( $blockers, $facts['blockers'], $case );
			self::assertSame( [], $facts['state']['missing_capabilities'], $case );
			self::assertTrue( $facts['state']['roles']['readable'], $case );
			self::assertTrue( $facts['state']['plugins']['readable'], $case );
		}
	}

	public function test_the_running_sites_roles_and_plugins_that_cannot_be_read_are_not_compared_and_do_not_block(): void {
		// Forms that WordPress reads and honours, but the strict reader refuses: the exchange discards
		// these values, so they are only not compared. The values of the backup are all listed.
		$extra                = WordPressRoles::with( 'subscriber', [ 'manage_options' ] );
		$extra['subscriber'] += [ 'note' => 'x' ];
		$float                = WordPressRoles::defaults();
		$float['subscriber']['capabilities']['manage_options'] = 1.0;
		$control = WordPressRoles::with( 'subscriber', [ "promote_users\t" ] );
		$plugins = serialize( [ self::OWN, 'later/later.php' ] );
		$roles   = [
			'an extra field in a role' => serialize( $extra ),
			'a float grant'            => serialize( $float ),
			'a control character'      => serialize( $control ),
			'a role map over 1 MiB'    => serialize( WordPressRoles::defaults() ) . str_repeat( ' ', RoleMap::MAX_BYTES ),
		];
		$backup  = [
			'reader' => [
				'name'         => 'Reader',
				'capabilities' => [
					'read'       => true,
					'edit_posts' => false,
				],
			],
		] + WordPressRoles::defaults();
		foreach ( $roles as $case => $stored ) {
			$site  = self::site(
				[
					'wp_user_roles'  => $stored,
					'active_plugins' => $plugins,
				],
				serialize( [ 'administrator' => true ] )
			);
			$facts = $this->reviewer()->state( $site, 1, serialize( $backup ), serialize( [ self::OWN ] ) );
			self::assertSame( [], $facts['blockers'], $case );
			self::assertFalse( $facts['state']['roles']['readable'], $case );
			self::assertTrue( $facts['state']['plugins']['readable'], $case );
			self::assertSame( [ 'administrator', 'author', 'contributor', 'editor', 'reader', 'subscriber' ], array_column( $facts['state']['roles']['entries'], 'role' ), $case );
			self::assertSame( [ 'added' ], array_values( array_unique( array_column( $facts['state']['roles']['entries'], 'change' ) ) ), $case );
			self::assertSame( [ 'edit_posts' ], $facts['state']['roles']['entries'][4]['denials_added']['entries'], $case );
		}
		$site  = self::site( [ 'active_plugins' => $plugins ], serialize( [ 'administrator' => true ] ) );
		$facts = $this->reviewer()->state( $site, 1, serialize( $backup ), serialize( [ self::OWN ] ) );
		self::assertSame( [], $facts['blockers'], 'A running site without a role map.' );
		self::assertFalse( $facts['state']['roles']['readable'] );
		$lists = [
			'a named entry'       => serialize( [ 'k' => self::OWN ] ),
			'a repeated entry'    => serialize( [ self::OWN, 'later/later.php', self::OWN ] ),
			'a list over 256 KiB' => serialize( [ self::OWN ] ) . str_repeat( ' ', PluginList::MAX_BYTES ),
			'no list at all'      => null,
		];
		foreach ( $lists as $case => $stored ) {
			$options = [ 'wp_user_roles' => serialize( WordPressRoles::defaults() ) ] + ( null === $stored ? [] : [ 'active_plugins' => $stored ] );
			$facts   = $this->reviewer( [ 'b/b.php' ] )->state( self::site( $options, serialize( [ 'administrator' => true ] ) ), 1, serialize( WordPressRoles::defaults() ), serialize( [ 'b/b.php', 'a/a.php' ] ) );
			self::assertSame( [], $facts['blockers'], $case );
			self::assertFalse( $facts['state']['plugins']['readable'], $case );
			self::assertTrue( $facts['state']['roles']['readable'], $case );
			self::assertSame( [], $facts['state']['plugins']['deactivated']['entries'], $case );
			self::assertSame(
				[
					[
						'plugin' => 'b/b.php',
						'file'   => true,
					],
					[
						'plugin' => 'a/a.php',
						'file'   => false,
					],
				],
				$facts['state']['plugins']['activated']['entries'],
				$case
			);
			self::assertTrue( $facts['state']['plugin']['added'], $case );
		}
	}

	public function test_an_unreadable_record_of_the_administrators_capabilities_is_named_not_evaluated(): void {
		$records = [
			'absent'              => null,
			'stored twice'        => [ serialize( [ 'administrator' => true ] ), serialize( [ 'administrator' => true ] ) ],
			'not serialized'      => 'administrator',
			'longer than 64 KiB'  => serialize( [ 'administrator' => true ] ) . str_repeat( ' ', RoleMap::MAX_OWN_BYTES ),
			'not a list of names' => serialize( [ 'administrator' => [ true ] ] ),
		];
		$options = [
			'wp_user_roles'  => serialize( WordPressRoles::defaults() ),
			'active_plugins' => serialize( [ self::OWN ] ),
		];
		foreach ( $records as $case => $own ) {
			$facts = $this->reviewer()->state( self::site( $options, $own ), 1, serialize( WordPressRoles::defaults() ), serialize( [ self::OWN ] ) );
			self::assertSame( [ 'database_admin_capabilities_unreadable' ], $facts['blockers'], $case );
			self::assertSame( [], $facts['state']['missing_capabilities'], $case . ': nothing was evaluated.' );
		}
	}

	public function test_a_capability_without_a_valid_name_refuses_the_account(): void {
		$facts = $this->reviewer( capabilities: [ '' ] )->state( self::live( WordPressRoles::defaults(), [ self::OWN ] ), 1, serialize( WordPressRoles::defaults() ), serialize( [ self::OWN ] ) );
		self::assertSame( [ 'database_account' ], $facts['blockers'] );
		self::assertSame( [], $facts['capabilities'] );
	}

	public function test_the_digest_covers_the_facts_and_no_review_from_before_them_matches(): void {
		$state   = DatabaseReview::blank( self::OWN );
		$entries = [
			[
				'table'  => 'wp_posts',
				'action' => 'replace',
			],
		];
		$review  = DatabaseReview::summary( 'restore', 1, $entries, [], [], [ 'apply', 'binding' ], $state );
		self::assertTrue( $review['applicable'] );
		self::assertSame( $state['kept'], $review['kept'] );
		self::assertSame( $state['plugins'], $review['plugins'] );
		self::assertArrayNotHasKey( 'preserve_admin', $review );
		self::assertSame( $review['digest'], DatabaseReview::summary( 'restore', 1, $entries, [], [], [ 'apply', 'binding' ], $state )['digest'] );
		// The digest that the review had before it covered roles and plugins.
		self::assertNotSame( hash( 'sha256', json_encode( [ 1, 'restore', 1, $entries, [], [], [ 'apply', 'binding' ] ], JSON_THROW_ON_ERROR ) ), $review['digest'] );
		$changed                                        = $state;
		$changed['plugins']['deactivated']['entries'][] = [
			'plugin' => 'activated-later/plugin.php',
			'file'   => true,
		];
		$file = $changed;
		$file['plugins']['deactivated']['entries'][0]['file'] = false;
		$added                           = $state;
		$added['plugin']['added']        = true;
		$more                            = $state;
		$more['roles']['more']           = 1;
		$unread                          = $state;
		$unread['roles']['readable']     = false;
		$unlisted                        = $state;
		$unlisted['plugins']['readable'] = false;
		foreach ( [ $changed, $file, $added, $more, $unread, $unlisted ] as $other ) {
			self::assertNotSame( $review['digest'], DatabaseReview::summary( 'restore', 1, $entries, [], [], [ 'apply', 'binding' ], $other )['digest'] );
		}
		$refused = DatabaseReview::summary( 'restore', 1, $entries, [ 'database_admin_capabilities' ], [], [ 'apply', 'binding' ], $state );
		self::assertFalse( $refused['applicable'] );
	}
}
