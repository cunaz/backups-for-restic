<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\SiteLayout;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlannerPolicyTest extends TestCase {

	private string $root;
	private string $live;
	private string $files;
	private string $staged;

	protected function setUp(): void {
		$this->root   = TempDir::create( 'planner-policy' );
		$this->live   = $this->root . '/live';
		$this->files  = $this->root . '/stage';
		$this->staged = $this->files . $this->live;
		mkdir( $this->live, 0700 );
		mkdir( $this->staged, 0700, true );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	public function test_policy_keeps_real_and_alias_spellings_protected_and_excludes_bootstrap_trees(): void {
		mkdir( $this->live . '/state' );
		symlink( $this->live . '/state', $this->root . '/state-alias' );
		$plan = new ApplyPlan( [ $this->live . '/' ], [ $this->root . '/state-alias/' ], [ $this->live . '/config' ], true, null, [ $this->live . '/must-use' ] );
		foreach ( [ '/state', '/state/secret', '/STATE/secret' ] as $path ) {
			self::assertSame( 'protected', $plan->inspect( $this->files, $this->live . $path )['reason'] );
		}
		foreach ( [ '/must-use', '/MUST-USE/bootstrap.php' ] as $path ) {
			self::assertSame( 'offline_restore_required', $plan->inspect( $this->files, $this->live . $path )['reason'] );
		}
		foreach ( [ '/stateful', '/must-useful', '/config' ] as $path ) {
			file_put_contents( $this->staged . $path, 'stage' );
			self::assertSame( 'create', $plan->inspect( $this->files, $this->live . $path )['action'] );
		}
	}

	public function test_bootstrap_and_private_locations_are_protected_in_every_spelling_of_a_canonical_target(): void {
		mkdir( $this->live . '/content/private', 0700, true );
		mkdir( $this->live . '/content/advanced' );
		symlink( $this->live . '/content', $this->root . '/content-link' );
		symlink( '/', $this->root . '/top' );
		$missing = '/bfr-missing-' . bin2hex( random_bytes( 6 ) );
		$plan    = new ApplyPlan(
			[ $this->live ],
			[ $this->live . '//content//private/', $this->root . '/top' . $missing . '/state' ],
			[],
			false,
			null,
			[
				// WP_CONTENT_DIR with a trailing slash makes WordPress derive WPMU_PLUGIN_DIR with "//".
				$this->live . '/content//mu-plugins',
				$this->live . '/content/must-use/',
				// Drop-ins and directories named through a link, whether they exist or not.
				$this->root . '/content-link/object-cache.php',
				$this->root . '/content-link/advanced',
				$this->root . '/content-link/private',
			]
		);
		foreach ( [ '/content/mu-plugins', '/content/MU-PLUGINS/loader.php', '/content/must-use', '/content/must-use/loader.php', '/content/object-cache.php', '/content/advanced/cache.php' ] as $suffix ) {
			self::assertSame( 'offline_restore_required', $plan->inspect( $this->files, $this->live . $suffix )['reason'], $suffix );
		}
		foreach ( [ '/content/must-useful', '/content/object-cache.php.bak', '/content/advanced-cache.php' ] as $suffix ) {
			self::assertSame( 'missing_or_linked_source', $plan->inspect( $this->files, $this->live . $suffix )['reason'], $suffix );
		}
		// The configured spelling stays protected as well, although it can never be written.
		self::assertSame( 'offline_restore_required', $plan->inspect( $this->files, $this->root . '/content-link/object-cache.php' )['reason'] );
		// A location listed twice keeps the reason it was listed with first.
		self::assertSame( 'protected', $plan->inspect( $this->files, $this->live . '/content/private/spool' )['reason'] );
		self::assertSame( 'offline_restore_required', $plan->inspect( $this->files, $this->root . '/content-link/private' )['reason'] );
		// A link to the filesystem root resolves to the root itself.
		self::assertSame( 'protected', $plan->inspect( $this->files, $missing . '/state/key' )['reason'] );
		self::assertSame( 'outside_roots', $plan->inspect( $this->files, $missing . '/stateful' )['reason'] );
	}

	public function test_a_protected_location_that_is_not_a_plain_absolute_path_refuses_the_plan(): void {
		foreach ( [ 'wp-content/mu-plugins', $this->live . '/../mu-plugins', $this->live . '/./mu-plugins', '/', '', $this->live . "/mu\0plugins" ] as $location ) {
			foreach ( [ [ [ $location ], [], [] ], [ [], [ $location ], [] ], [ [], [], [ $location ] ] ] as [ $excluded, $config, $bootstrap ] ) {
				try {
					new ApplyPlan( [ $this->live ], $excluded, $config, false, null, $bootstrap );
					self::fail( 'A plan was built that cannot protect ' . $location );
				} catch ( InvalidArgument $error ) {
					self::assertSame( 'protected_path_invalid', $error->context()['code'] ?? null, $location );
					self::assertSame( 'A location that file apply protects is not an absolute path without dot segments.', $error->getMessage() );
				}
			}
		}
		// A configuration file that the review includes is not protected, so it is not checked.
		self::assertSame( 'live_root', ( new ApplyPlan( [ $this->live ], [], [ 'wp-config.php' ], true ) )->inspect( $this->files, $this->live )['reason'] );
	}

	/**
	 * @return iterable<string, array{bool}>
	 */
	public static function normalizers(): iterable {
		yield 'with intl' => [ true ];
		yield 'without intl' => [ false ];
	}

	#[DataProvider( 'normalizers' )]
	public function test_protected_names_are_compared_as_loosely_as_case_insensitive_filesystems_compare_them( bool $normalizer ): void {
		if ( $normalizer && ! class_exists( \Normalizer::class ) ) {
			self::markTestSkipped( 'The intl extension is not loaded.' );
		}
		$plan     = new ApplyPlan( [ $this->live ], [ $this->live . '/plugins/backups-for-restic', $this->live . '/privé', $this->live . "/\xff-dir", $this->live . '/a;b' ], [ $this->live . '/wp-config.php' ], false, null, [ $this->live . '/mu-plugins', $this->live . '/.htaccess' ], false, $normalizer );
		$expected = [
			// Full case folding, as ext4 and f2fs casefolding apply it.
			'/WP-CONFIG.PHP'                       => 'protected',
			"/wp-con\u{FB01}g.php"                 => 'protected',
			"/plugins/backups-for-re\u{FB06}ic/x"  => 'protected',
			"/plugins/backups-for-re\u{FB05}ic"    => 'protected',
			"/plugins/bac\u{212A}ups-for-restic/x" => 'protected',
			"/mu-plugin\u{017F}/loader.php"        => 'offline_restore_required',
			"/.htacce\u{00DF}"                     => 'offline_restore_required',
			// Characters that HFS+ and older casefolding kernels ignore.
			"/wp-con\u{200C}fig.php"               => 'protected',
			"/mu-\u{FEFF}plugins/loader.php"       => 'offline_restore_required',
			// NTFS and SMB uppercase a dotless i to I; decomposition maps the Greek question mark.
			"/wp-conf\u{0131}g.php"                => 'protected',
			"/a\u{037E}b/c"                        => 'protected',
			// Names without valid UTF-8 differ only in ASCII case.
			"/\xFF-DIR/x"                          => 'protected',
			"/\xfe-dir/x"                          => 'missing_or_linked_source',
			// The same spelling needs no normalization.
			'/privé/key'                           => 'protected',
			// Neighbours stay ordinary names.
			'/wp-config.php.bak'                   => 'missing_or_linked_source',
			"/wp-con\u{FB02}g.php"                 => 'missing_or_linked_source',
			'/plugins/backups-for-restic-old/x'    => 'missing_or_linked_source',
			"/uploads/caf\u{00E9}.jpg"             => 'missing_or_linked_source',
		];
		// Compatibility and canonical equivalence need intl; without it, two names that both
		// keep other characters are refused rather than guessed.
		$expected["/prive\u{0301}/key"]    = $normalizer ? 'protected' : 'unverified_name';
		$expected['/wp-config.ｐｈｐ']        = $normalizer ? 'protected' : 'missing_or_linked_source';
		$expected["/priv\u{00E8}/key"]     = $normalizer ? 'missing_or_linked_source' : 'unverified_name';
		$expected["/priv\u{00E9}\u{00E9}"] = $normalizer ? 'missing_or_linked_source' : 'unverified_name';
		// What canonical equivalence leaves alone still tells other names apart.
		$expected["/pr\u{00EF}vate/key"] = 'missing_or_linked_source';
		$expected["/caf\u{00E9}/key"]    = 'missing_or_linked_source';
		foreach ( $expected as $suffix => $reason ) {
			$entry = $plan->inspect( $this->files, $this->live . $suffix );
			self::assertSame( $reason, $entry['reason'], $suffix );
			self::assertSame( in_array( $reason, [ 'unverified_name', 'missing_or_linked_source' ], true ) ? 'refuse' : 'skip', $entry['action'], $suffix );
		}
		// Outside the live roots nothing is written, so an uncertain name needs no refusal.
		$outside = new ApplyPlan( [ $this->live ], [ $this->root . '/privé' ], [], false, null, [], false, $normalizer );
		self::assertSame( $normalizer ? 'protected' : 'outside_roots', $outside->inspect( $this->files, $this->root . "/prive\u{0301}/key" )['reason'] );
		self::assertSame( 'outside_roots', $outside->inspect( $this->files, $this->root . "/priv\u{00EA}/key" )['reason'] );
		// A name that keeps other characters never equals an ASCII name, in either direction,
		// and a parent directory of a protected path is not protected itself.
		$nested = new ApplyPlan( [ $this->live ], [ $this->live . '/priv', $this->live . '/café/deep' ], [], false, null, [], false, $normalizer );
		foreach ( [ "/priv\u{00E9}/key", "/caf/\u{00E9}", "/cafe\u{0301}", "/caf\u{00E9}" ] as $suffix ) {
			self::assertSame( 'missing_or_linked_source', $nested->inspect( $this->files, $this->live . $suffix )['reason'], $suffix );
		}
		self::assertSame( $normalizer ? 'protected' : 'unverified_name', $nested->inspect( $this->files, $this->live . "/cafe\u{0301}/deep/x" )['reason'] );
	}

	public function test_names_that_read_as_dot_entries_without_invisible_characters_are_refused(): void {
		$plan = new ApplyPlan( [ $this->live ], [] );
		foreach ( [ "/\u{200B}", "/\u{FEFF}.", "/..\u{200D}", "/x/\u{2060}\u{00AD}../y" ] as $suffix ) {
			self::assertSame( 'invalid_path', $plan->inspect( $this->files, $this->live . $suffix )['reason'], bin2hex( $suffix ) );
		}
		foreach ( [ "/a\u{200B}b", "/\u{200B}.php", '/...' ] as $suffix ) {
			self::assertSame( 'missing_or_linked_source', $plan->inspect( $this->files, $this->live . $suffix )['reason'], bin2hex( $suffix ) );
		}
	}

	public function test_names_are_normalized_wherever_the_intl_extension_is_loaded(): void {
		$plan   = new ApplyPlan( [ $this->live ], [ $this->live . '/privé' ] );
		$reason = $plan->inspect( $this->files, $this->live . "/prive\u{0301}/key" )['reason'];
		self::assertSame( class_exists( \Normalizer::class ) ? 'protected' : 'unverified_name', $reason );
	}

	public function test_roots_and_staging_are_checked_again_after_the_plan_was_created(): void {
		symlink( $this->live, $this->root . '/alias' );
		foreach ( [ '', '/', $this->root . '/absent', $this->root . '/alias', '.' ] as $root ) {
			try {
				new ApplyPlan( [ $root ], [] );
				self::fail( 'An unverified directory became a live root.' );
			} catch ( InvalidArgument $error ) {
				self::assertSame( 'No canonical live directory is available for file apply.', $error->getMessage() );
			}
		}
		$plan = new ApplyPlan( [ $this->live ], [] );
		symlink( $this->files, $this->root . '/stage-alias' );
		foreach ( [ $this->root . '/absent', $this->root . '/stage-alias' ] as $files ) {
			self::assertSame( 'staging_unavailable', $plan->inspect( $files, $this->live )['reason'] );
			try {
				iterator_to_array( $plan->actions( $files ) );
				self::fail( 'An unverified staging root was traversed.' );
			} catch ( StorageFailed $error ) {
				self::assertSame( 'The staged tree is unavailable.', $error->getMessage() );
			}
		}
		self::assertSame( 'staging_unavailable', $plan->inspect( '/', $this->live )['reason'] );
		self::assertSame( 'live_root', $plan->inspect( $this->files, $this->live )['reason'] );
		self::assertSame( 'root_ancestor', $plan->inspect( $this->files, $this->root )['reason'] );
		rename( $this->live, $this->root . '/previous' );
		self::assertSame( 'live_root_changed', $plan->inspect( $this->files, $this->live )['reason'] );
		symlink( $this->root . '/previous', $this->live );
		self::assertSame( 'live_root_changed', $plan->inspect( $this->files, $this->live )['reason'] );
	}

	public function test_existing_destinations_and_staged_files_must_have_the_expected_object_type(): void {
		$plan = new ApplyPlan( [ $this->live ], [] );
		file_put_contents( $this->staged . '/file', 'source' );
		link( $this->staged . '/file', $this->staged . '/second' );
		self::assertSame( 'unsafe_source', $plan->inspect( $this->files, $this->live . '/file' )['reason'] );
		unlink( $this->staged . '/second' );
		mkdir( $this->live . '/file' );
		self::assertSame( 'type_conflict', $plan->inspect( $this->files, $this->live . '/file' )['reason'] );
		rmdir( $this->live . '/file' );
		file_put_contents( $this->live . '/file', 'live' );
		link( $this->live . '/file', $this->live . '/alias' );
		self::assertSame( 'type_conflict', $plan->inspect( $this->files, $this->live . '/file' )['reason'] );
		unlink( $this->live . '/alias' );
		unlink( $this->staged . '/file' );
		mkdir( $this->staged . '/file' );
		self::assertSame( 'type_conflict', $plan->inspect( $this->files, $this->live . '/file' )['reason'] );
		unlink( $this->live . '/file' );
		mkdir( $this->live . '/file' );
		self::assertSame( 'existing_directory', $plan->inspect( $this->files, $this->live . '/file' )['reason'] );
		chmod( $this->live . '/file', 0555 );
		try {
			if ( ! is_writable( $this->live . '/file' ) ) {
				touch( $this->staged . '/file/new' );
				self::assertSame( 'unwritable_destination', $plan->inspect( $this->files, $this->live . '/file/new' )['reason'] );
			}
		} finally {
			chmod( $this->live . '/file', 0700 );
		}
	}

	public function test_preview_compares_identical_bytes_only_on_request_and_reports_exact_destination(): void {
		$plan = new ApplyPlan( [ $this->live ], [] );
		foreach ( [ 'equal', 'changed' ] as $file ) {
			file_put_contents( $this->staged . '/' . $file, 'stage' );
			file_put_contents( $this->live . '/' . $file, 'equal' === $file ? 'stage' : 'other' );
		}
		$path     = $this->live . '/equal';
		$fast     = array_column( iterator_to_array( $plan->actions( $this->files ) ), null, 'path' );
		$compared = array_column( iterator_to_array( $plan->actions( $this->files, true ) ), null, 'path' );
		self::assertSame(
			[
				'path'        => $path,
				'destination' => $path,
				'action'      => 'replace',
				'reason'      => 'existing_file',
			],
			$fast[ $path ]
		);
		self::assertSame(
			[
				'path'        => $path,
				'destination' => $path,
				'action'      => 'keep',
				'reason'      => 'identical_contents',
			],
			$compared[ $path ]
		);
		self::assertSame( $fast[ $this->live . '/changed' ], $compared[ $this->live . '/changed' ] );
		self::assertSame( 'k', ApplyPlan::fold( 'K' ) );
		self::assertSame( "\xffa", ApplyPlan::fold( "\xffA" ) );
	}

	public function test_a_fresh_site_maps_only_into_its_authorized_live_roots(): void {
		$snapshot  = str_repeat( 'a', 64 );
		$job       = new Job( '20270115T030000Z-abcdef', JobType::Restore, 'manual', 1800000000 );
		$job->data = [
			'fresh_install' => true,
			'snapshot'      => $snapshot,
			'site_layout'   => SiteLayout::bind(
				$snapshot,
				[
					'database' => true,
					'content'  => '/old/content',
					'uploads'  => '/media/files',
				],
				'/old/private/spool/manifest.json'
			),
		];
		$layout    = SiteLayout::from_job( $job, $this->live, $this->root . '/uploads' );
		self::assertNotNull( $layout );
		$plan = new ApplyPlan( [ $this->live ], [], [], false, $layout );
		mkdir( $this->files . '/old/content/themes', 0700, true );
		mkdir( $this->files . '/media/files', 0700, true );
		touch( $this->files . '/old/content/themes/site' );
		self::assertSame( 'root_ancestor', $plan->inspect( $this->files, '/media' )['reason'] );
		self::assertSame( 'outside_roots', $plan->inspect( $this->files, '/old/private/archive' )['reason'] );
		self::assertSame( 'unavailable_destination_root', $plan->inspect( $this->files, '/media/files/file' )['reason'] );
		$choice = $plan->inspect( $this->files, '/old/content/themes/site' );
		self::assertSame(
			[
				'path'        => '/old/content/themes/site',
				'destination' => $this->live . '/themes/site',
				'action'      => 'create',
				'reason'      => 'missing_destination',
			],
			$choice
		);
		$oversized = SiteLayout::from_job( $job, $this->live . '/' . str_repeat( 'x', 256 ), $this->root . '/uploads' );
		self::assertNotNull( $oversized );
		$invalid = new ApplyPlan( [ $this->live ], [], [], false, $oversized );
		self::assertSame( 'ambiguous_destination', $invalid->inspect( $this->files, '/old/content/themes/site' )['reason'] );
		$this->expectException( InvalidArgument::class );
		new ApplyPlan( [ $this->live ], [], [], true, $layout );
	}

	public function test_a_fresh_site_never_maps_a_spool_into_its_content(): void {
		$snapshot  = str_repeat( 'b', 64 );
		$job       = new Job( '20270115T030000Z-abcdef', JobType::Restore, 'manual', 1800000000 );
		$job->data = [
			'fresh_install' => true,
			'snapshot'      => $snapshot,
			'site_layout'   => SiteLayout::bind(
				$snapshot,
				[
					'database' => true,
					'content'  => '/old/content',
					'uploads'  => '/old/content/uploads',
				],
				'/old/private/spool/manifest.json'
			),
		];
		$layout    = SiteLayout::from_job( $job, $this->live, $this->live . '/uploads' );
		self::assertNotNull( $layout );
		$plan = new ApplyPlan( [ $this->live ], [], [], false, $layout );
		// The snapshot's own spool, and a copy that an earlier apply left in the content tree.
		foreach ( [ '/old/private/spool', '/old/content/backups-for-restic/spool' ] as $spool ) {
			mkdir( $this->files . $spool . '/database', 0700, true );
			file_put_contents( $this->files . $spool . '/database/010-wp_users.sql', 'INSERT INTO wp_users VALUES (1);' );
		}
		file_put_contents( $this->files . '/old/content/theme.css', 'body {}' );
		$rows = array_column( iterator_to_array( $plan->actions( $this->files ) ), null, 'path' );
		self::assertSame( 'create', $rows['/old/content/theme.css']['action'] );
		self::assertSame( 'mkdir', $rows['/old/content/backups-for-restic']['action'] );
		self::assertSame( 'outside_roots', $rows['/old/private']['reason'] );
		self::assertArrayNotHasKey( '/old/private/spool', $rows );
		self::assertSame(
			[
				'path'        => '/old/private/spool/database',
				'destination' => null,
				'action'      => 'skip',
				'reason'      => 'snapshot_spool',
			],
			$plan->inspect( $this->files, '/old/private/spool/database' )
		);
		self::assertSame(
			[
				'path'        => '/old/content/backups-for-restic/spool',
				'destination' => $this->live . '/backups-for-restic/spool',
				'action'      => 'skip',
				'reason'      => 'snapshot_spool',
			],
			$rows['/old/content/backups-for-restic/spool']
		);
		self::assertSame( 'snapshot_spool', $plan->inspect( $this->files, '/old/content/backups-for-restic/spool/database/010-wp_users.sql' )['reason'] );
	}

	public function test_live_links_pipes_and_executable_files_receive_the_exact_policy_decision(): void {
		$plan   = new ApplyPlan( [ $this->live ], [] );
		$target = $this->live . '/service';
		touch( $this->staged . '/service' );
		symlink( $this->staged . '/service', $target );
		self::assertSame( 'linked_destination', $plan->inspect( $this->files, $target )['reason'] );
		unlink( $target );
		if ( function_exists( 'posix_mkfifo' ) ) {
			self::assertTrue( posix_mkfifo( $target, 0600 ) );
			self::assertSame( 'type_conflict', $plan->inspect( $this->files, $target )['reason'] );
			unlink( $target );
		}
		touch( $target );
		chmod( $target, 0755 );
		self::assertSame( 'existing_file', $plan->inspect( $this->files, $target )['reason'] );
	}

	public function test_a_replacement_is_refused_when_the_apply_could_not_keep_owner_group_or_mode(): void {
		$target = $this->live . '/deployed.php';
		file_put_contents( $this->staged . '/deployed.php', 'restored' );
		file_put_contents( $target, 'live' );
		clearstatcache( true );
		$stat = lstat( $target );
		self::assertIsArray( $stat );
		$decide = fn ( array|false $process ): string => ( new ApplyPlan( [ $this->live ], [], [], false, null, [], $process ) )->inspect( $this->files, $target )['reason'];
		// Files deployed by another user: the apply would create its copy as itself.
		self::assertSame( 'foreign_owner', $decide( self::writer( $stat['uid'] + 1, null ) ) );
		// A group the process is not a member of, and that it could not give the copy.
		self::assertSame( 'foreign_group', $decide( self::writer( $stat['uid'], [ $stat['gid'] + 1 ] ) ) );
		self::assertSame( 'existing_file', $decide( self::writer( $stat['uid'], [ $stat['gid'] + 1, $stat['gid'] ] ) ) );
		// Root can give any group to a file it owns.
		self::assertSame( 'existing_file', $decide( self::writer( $stat['uid'], null ) ) );
		// Without the POSIX extension the identity is unknown; the apply checks before each replacement.
		self::assertSame( 'existing_file', $decide( false ) );
		self::assertSame( 'refuse', ( new ApplyPlan( [ $this->live ], [], [], false, null, [], self::writer( $stat['uid'] + 1, null ) ) )->inspect( $this->files, $target )['action'] );
		// The apply never replaces a file with setuid, setgid or sticky bits.
		foreach ( [ 04644, 02644, 01644 ] as $mode ) {
			chmod( $target, $mode );
			clearstatcache( true );
			if ( ( lstat( $target )['mode'] & 07777 ) !== $mode ) {
				continue;
			}
			self::assertSame( 'special_permissions', $decide( false ), sprintf( '%o', $mode ) );
			self::assertSame( 'special_permissions', $decide( self::writer( $stat['uid'] + 1, null ) ), sprintf( '%o', $mode ) );
		}
		chmod( $target, 04644 );
		clearstatcache( true );
		self::assertSame( 04644, lstat( $target )['mode'] & 07777, 'The owner can always set the setuid bit.' );
		// A new file has no owner to keep; only its directory must be writable.
		file_put_contents( $this->staged . '/new.php', 'new' );
		self::assertSame( 'missing_destination', ( new ApplyPlan( [ $this->live ], [], [], false, null, [], self::writer( $stat['uid'] + 1, [] ) ) )->inspect( $this->files, $this->live . '/new.php' )['reason'] );
	}

	public function test_the_writing_identity_comes_from_the_posix_extension(): void {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			self::assertFalse( ApplyPlan::process() );
			return;
		}
		$groups = posix_getgroups();
		self::assertIsArray( $groups );
		self::assertSame(
			[
				'uid'    => posix_geteuid(),
				'groups' => 0 === posix_geteuid() ? null : array_merge( [ posix_getegid() ], $groups ),
			],
			ApplyPlan::process()
		);
		// The default identity is this process, which owns the files the tests create.
		file_put_contents( $this->staged . '/own.php', 'restored' );
		file_put_contents( $this->live . '/own.php', 'live' );
		self::assertSame( 'existing_file', ( new ApplyPlan( [ $this->live ], [] ) )->inspect( $this->files, $this->live . '/own.php' )['reason'] );
	}

	/**
	 * An identity that writes the replacements.
	 *
	 * @param int            $uid    Effective user.
	 * @param list<int>|null $groups Groups it can give a file, null for any.
	 * @return array{uid: int, groups: list<int>|null} Identity.
	 */
	private static function writer( int $uid, ?array $groups ): array {
		return [
			'uid'    => $uid,
			'groups' => $groups,
		];
	}

	public function test_comparing_an_unreadable_regular_file_reports_the_comparison_failure(): void {
		$plan   = new ApplyPlan( [ $this->live ], [] );
		$target = $this->live . '/unreadable';
		touch( $this->staged . '/unreadable' );
		touch( $target );
		chmod( $target, 0000 );
		try {
			if ( is_readable( $target ) ) {
				self::markTestSkipped( 'This process can read files with zero permission bits.' );
			}
			$this->expectException( StorageFailed::class );
			$this->expectExceptionMessage( 'A regular file could not be compared during the preview.' );
			// hash_file also emits a native warning for this expected permission refusal.
			set_error_handler( static fn ( int $severity ): bool => E_WARNING === $severity );
			try {
				iterator_to_array( $plan->actions( $this->files, true ) );
			} finally {
				restore_error_handler();
			}
		} finally {
			chmod( $target, 0600 );
		}
	}

	public function test_a_same_second_case_change_is_visible_to_the_next_read_only_inspection(): void {
		$plan      = new ApplyPlan( [ $this->live ], [] );
		$workspace = $this->root . '/index';
		mkdir( $workspace );
		$review = new DirectorySpellings( $workspace, microtime( true ) + 10 );
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$bucket = '/album' . $attempt;
			mkdir( $this->live . $bucket );
			mkdir( $this->staged . $bucket );
			touch( $this->staged . $bucket . '/Photo' );
			$target = $this->live . $bucket . '/Photo';
			touch( $target );
			clearstatcache( true );
			$before = lstat( dirname( $target ) );
			self::assertSame( 'existing_file', $plan->inspect( $this->files, $target, $review )['reason'] );
			rename( $target, $this->live . $bucket . '/PHOTO' );
			clearstatcache( true );
			if ( $before['ctime'] !== time() || lstat( dirname( $target ) )['ctime'] !== $before['ctime'] ) {
				continue;
			}
			self::assertSame( 'ambiguous_destination', $plan->inspect( $this->files, $target, $review )['reason'] );
			return;
		}
		self::fail( 'No fixture rename fit inside a single filesystem timestamp second.' );
	}

	public function test_listing_stops_if_a_previously_reviewed_directory_becomes_a_regular_file(): void {
		$plan = new ApplyPlan( [ $this->live ], [] );
		mkdir( $this->staged . '/moving' );
		$rows = $plan->actions( $this->files );
		foreach ( $rows as $row ) {
			if ( $this->live . '/moving' === $row['path'] ) {
				self::assertSame( 'mkdir', $row['action'] );
				rmdir( $this->staged . '/moving' );
				file_put_contents( $this->staged . '/moving', 'changed during traversal' );
				$this->expectException( StorageFailed::class );
				$this->expectExceptionMessage( 'A staged directory changed during the preview.' );
				$rows->next();
			}
		}
		self::fail( 'The replaced staged directory was traversed.' );
	}

	public function test_invalid_or_unavailable_sources_have_precise_decisions(): void {
		$plan = new ApplyPlan( [ $this->live ], [] );
		foreach ( [ '/', $this->live . '/', $this->live . '/' . str_repeat( 'x', 256 ) ] as $path ) {
			self::assertSame(
				[
					'path'        => $path,
					'destination' => null,
					'action'      => 'refuse',
					'reason'      => 'invalid_path',
				],
				$plan->inspect( $this->files, $path )
			);
		}
		self::assertSame( 'missing_or_linked_source', $plan->inspect( $this->files, $this->live . '/absent' )['reason'] );
		rename( $this->staged, $this->staged . '-previous' );
		touch( $this->staged );
		self::assertSame( 'type_conflict', $plan->inspect( $this->files, $this->live )['reason'] );
		unlink( $this->staged );
		TempDir::remove( $this->files . $this->root );
		touch( $this->files . $this->root );
		self::assertSame( 'root_ancestor_not_directory', $plan->inspect( $this->files, $this->root )['reason'] );
	}

	public function test_large_live_directory_uses_background_preview_and_rechecks_aliases_after_changes(): void {
		$wide = $this->live . '/wide';
		mkdir( $wide );
		mkdir( $this->staged . '/wide' );
		mkdir( $this->root . '/review' );
		touch( $this->staged . '/wide/photo' );
		touch( $wide . '/Photo' );
		for ( $i = 0; $i < 2050; ++$i ) {
			touch( $wide . '/entry-' . $i );
		}
		$plan = new ApplyPlan( [ $this->live ], [] );
		try {
			iterator_to_array( $plan->actions( $this->files, false, true ) );
			self::fail( 'The inline preview did not defer the large directory.' );
		} catch ( InvalidArgument $error ) {
			self::assertSame( 'file_plan_large', $error->context()['code'] ?? null );
		}
		$path   = $wide . '/photo';
		$review = new DirectorySpellings( $this->root . '/review', microtime( true ) + 15 );
		self::assertSame( 'ambiguous_destination', $plan->inspect( $this->files, $path, $review )['reason'] );
		self::assertTrue( $review->known( $wide ) );
		unlink( $wide . '/Photo' );
		self::assertSame( 'missing_destination', $plan->inspect( $this->files, $path, $review )['reason'] );
		// The final writer-facing check never reuses a read-only parent's old index.
		touch( $wide . '/PHOTO' );
		self::assertSame( 'ambiguous_destination', $plan->inspect( $this->files, $path )['reason'] );
	}
}
