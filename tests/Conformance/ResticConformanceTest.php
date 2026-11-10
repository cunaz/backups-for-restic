<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Connection\ConnectionTester;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\QuotaExceeded;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\UploadRejected;
use BackupsForRestic\Format\Compression;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\CurlHttpClient;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\SqlDumpSource;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The restic CLI is the oracle (ADR 0023): what the plugin writes must pass restic check
 * --read-data and restore byte for byte, and what restic writes must restore in the plugin.
 * Requires bin/install-test-tools.sh.
 */
final class ResticConformanceTest extends TestCase {

	private const string PASSWORD = 'conformance password';

	private static ?RestServer $server = null;

	private static ?RestServer $append_only = null;

	private static string $site = '';

	/** @var list<string> */
	private array $cleanup = [];

	public static function setUpBeforeClass(): void {
		if ( ! RestServer::available() ) {
			self::markTestSkipped( 'Run bin/install-test-tools.sh to install restic and rest-server.' );
		}
		self::$server = new RestServer();
		self::$site   = TempDir::create( 'conformance-site' );
		TempDir::fixture( self::$site, 20 * 1048576 );
	}

	public static function tearDownAfterClass(): void {
		self::$server?->stop();
		self::$append_only?->stop();
		if ( '' !== self::$site ) {
			TempDir::remove( self::$site );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function server(): RestServer {
		self::assertNotNull( self::$server );
		return self::$server;
	}

	private function identity(): Identity {
		return new Identity( 'example.com', 'www-data', 0, 0, 'backups-for-restic/test' );
	}

	private function backup( Repository $repository, string $site, ?Id $parent_id = null ): Id {
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$run             = BackupRun::start( $repository, new BackupPlan( [ $site ], [ 'backups-for-restic' ] ), $work, $this->identity(), new SystemClock(), $parent_id, 4 * 1048576 );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
		$id = $run->snapshot_id();
		self::assertNotNull( $id );
		return $id;
	}

	private function restore( Repository $repository, Id $snapshot ): string {
		$this->cleanup[] = $target = TempDir::create( 'target' );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$run             = RestoreRun::start( $repository, $snapshot, $target, $work );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
		return $target;
	}

	private function master_key( ResticCli $cli ): MasterKey {
		return MasterKey::from_json( $cli->must( [ 'cat', 'masterkey' ] ) );
	}

	public function test_restic_checks_and_restores_what_the_plugin_wrote(): void {
		$this->cleanup[] = $site = TempDir::create( 'site-copy' );
		TempDir::fixture( $site, 20 * 1048576 );

		$repository = Repository::initialize( $this->server()->backend( 'plugin' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$first      = $this->backup( $repository, $site );
		$cli        = new ResticCli( $this->server(), 'plugin', self::PASSWORD );

		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		foreach ( [ '0.18.1', '0.14.0' ] as $version ) {
			self::assertStringContainsString( 'no errors were found', ( new ResticCli( $this->server(), 'plugin', self::PASSWORD, $version ) )->must( [ 'check', '--read-data' ] ), 'restic ' . $version );
		}

		$this->cleanup[] = $target = TempDir::create( 'restic-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		self::assertSame( TempDir::describe( $site ), TempDir::describe( $target . $site ) );

		$listed = json_decode( $cli->must( [ 'snapshots', '--json' ] ), true );
		self::assertIsArray( $listed );
		self::assertSame( 'example.com', $listed[0]['hostname'] );
		self::assertSame( [ 'backups-for-restic' ], $listed[0]['tags'] );
		self::assertSame( [ $site ], $listed[0]['paths'] );

		// An incremental backup with a parent: restic sees exactly the one change.
		file_put_contents( $site . '/index.php', "<?php\n// Changed.\n" );
		touch( $site . '/index.php', 1767323100 );
		$second = $this->backup( $repository, $site, $first );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$diff = $cli->must( [ 'diff', $first->hex(), $second->hex() ] );
		self::assertMatchesRegularExpression( '#^M\s+' . preg_quote( $site, '#' ) . '/index\.php$#m', $diff );
	}

	/**
	 * Packs as restic's prune classifies them (restic prune --dry-run --max-unused unlimited -v).
	 *
	 * @param ResticCli $cli restic.
	 * @return array<string, int> Counts of totally used, partly used and unused packs.
	 */
	private function prune_statistics( ResticCli $cli ): array {
		$output = $cli->must( [ 'prune', '--dry-run', '--max-unused', 'unlimited', '-v' ] );
		preg_match_all( '/^(totally used packs|partly used packs|unused packs):\s+(\d+)$/m', $output, $matches, PREG_SET_ORDER );
		$statistics = [];
		foreach ( $matches as $match ) {
			$statistics[ $match[1] ] = (int) $match[2];
		}
		self::assertCount( 3, $statistics, $output );
		return $statistics;
	}

	public function test_prune_deletes_what_restic_calls_unused_and_restic_accepts_the_result(): void {
		$this->cleanup[] = $site = TempDir::create( 'site-prune' );
		TempDir::fixture( $site, 2 * 1048576 );
		$repository = Repository::initialize( $this->server()->backend( 'prune' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$cli        = new ResticCli( $this->server(), 'prune', self::PASSWORD );

		$this->backup( $repository, $site );
		file_put_contents( $site . '/unique.bin', random_bytes( 12 * 1048576 ) );
		$middle = $this->backup( $repository, $site );
		unlink( $site . '/unique.bin' );
		$latest = $this->backup( $repository, $site );
		$cli->must( [ 'forget', $middle->hex() ] );
		$before = $this->prune_statistics( $cli );
		self::assertGreaterThan( 0, $before['unused packs'] );

		$this->cleanup[] = $work = TempDir::create( 'prune' );
		$run             = PruneRun::start( $repository, $work . '/run', $this->identity(), new SystemClock(), new SystemRandom() );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}

		self::assertSame( $before['unused packs'], $run->result()['packs_deleted'], 'The plugin deletes exactly the packs restic calls unused.' );
		self::assertSame( $before['totally used packs'] + $before['partly used packs'], $run->result()['packs_kept'] );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		self::assertSame( 0, $this->prune_statistics( $cli )['unused packs'] );

		$this->cleanup[] = $target = TempDir::create( 'restic-restore' );
		$cli->must( [ 'restore', $latest->hex(), '--target', $target ] );
		self::assertSame( TempDir::describe( $site ), TempDir::describe( $target . $site ) );
	}

	public function test_check_finds_the_damage_restic_check_finds(): void {
		$this->cleanup[] = $site = TempDir::create( 'site-check' );
		TempDir::fixture( $site, 2 * 1048576 );
		$repository = Repository::initialize( $this->server()->backend( 'check' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$cli        = new ResticCli( $this->server(), 'check', self::PASSWORD );
		$this->backup( $repository, $site );
		file_put_contents( $site . '/index.php', "<?php\n// Changed.\n" );
		$this->backup( $repository, $site );

		self::assertSame( 0, $this->plugin_check( $repository )['errors'] );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );

		$packs = glob( $this->server()->directory . '/data/check/data/*/*' );
		self::assertIsArray( $packs );
		sort( $packs );
		[ $damaged, $deleted ]                  = $packs;
		$bytes                                  = (string) file_get_contents( $damaged );
		$bytes[ intdiv( strlen( $bytes ), 2 ) ] = chr( ord( $bytes[ intdiv( strlen( $bytes ), 2 ) ] ) ^ 0xff );
		file_put_contents( $damaged, $bytes );
		unlink( $deleted );

		$result = $this->plugin_check( $repository );
		$found  = array_map( static fn ( array $finding ): string => $finding['code'] . ' ' . $finding['object'], $result['findings'] );
		self::assertContains( 'pack_hash ' . basename( $damaged ), $found );
		self::assertContains( 'pack_missing ' . basename( $deleted ), $found );

		[ $status, $stdout, $stderr ] = $cli->run( [ 'check', '--read-data' ] );
		self::assertNotSame( 0, $status, 'restic agrees that the repository is damaged.' );
		self::assertStringContainsString( basename( $damaged ), $stdout . $stderr );
		self::assertStringContainsString( substr( basename( $deleted ), 0, 10 ), $stdout . $stderr );
	}

	/**
	 * Runs the plugin's check with every pack read.
	 *
	 * @param Repository $repository Repository.
	 * @return array{errors: int, warnings: int, counts: array<string, int>, findings: list<array{severity: string, code: string, object: string, message: string}>, stats: array<string, int>}
	 */
	private function plugin_check( Repository $repository ): array {
		$this->cleanup[] = $work = TempDir::create( 'check' );
		$run             = CheckRun::start( $repository, $work . '/run', $this->identity(), new SystemClock(), new SystemRandom(), 100 );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
		return $run->result();
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function restic_versions(): iterable {
		yield 'restic 0.19.1' => [ '0.19.1' ];
		yield 'restic 0.14.0' => [ '0.14.0' ];
	}

	#[DataProvider( 'restic_versions' )]
	public function test_plugin_restores_and_deduplicates_against_what_restic_wrote( string $version ): void {
		$name = 'restic-' . str_replace( '.', '-', $version );
		$cli  = new ResticCli( $this->server(), $name, self::PASSWORD, $version );
		$cli->must( [ 'init', '--repository-version', '1' ] );
		$cli->must( [ 'backup', '--host', 'restic-host', self::$site ] );

		$repository = Repository::open( $this->server()->backend( $name ), $this->master_key( $cli ), new SystemRandom() );
		$snapshots  = $repository->snapshots();
		self::assertCount( 1, $snapshots );
		$restored = $this->restore( $repository, Id::from_hex( (string) array_key_first( $snapshots ) ) );
		self::assertSame( TempDir::describe( self::$site ), TempDir::describe( $restored . self::$site ) );

		// Same chunker, same polynomial: the plugin's backup of the same files adds no data.
		$ours    = $this->backup( $repository, self::$site );
		$summary = Snapshot::from_json( $repository->load_unpacked( ObjectType::Snapshots, $ours ) )->summary;
		self::assertNotNull( $summary );
		self::assertSame( 0, $summary->data_blobs, 'Every chunk the plugin cut already existed in the restic repository.' );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
	}

	#[Group( 'slow' )]
	public function test_unlocks_a_key_that_restic_calibrated(): void {
		$cli = new ResticCli( $this->server(), 'restic-key', self::PASSWORD );
		$cli->must( [ 'init', '--repository-version', '1' ] );
		$master = Repository::unlock( $this->server()->backend( 'restic-key' ), self::PASSWORD, new SystemRandom() );
		self::assertSame( $this->master_key( $cli )->to_json(), $master->to_json() );
	}

	#[Group( 'slow' )]
	public function test_restic_restores_a_300_mb_database_dump_that_the_plugin_streamed(): void {
		$size            = 300 * 1048576;
		$this->cleanup[] = $site = TempDir::create( 'database-site' );
		mkdir( $site . '/database' );
		$dump = new VirtualFile( 'dump.sql', static fn (): SqlDumpSource => new SqlDumpSource( 'conformance', $size ), 1767323100 );
		$plan = new BackupPlan( [ $site ], [ 'backups-for-restic' ], null, static fn ( string $directory ): array => $site . '/database' === $directory ? [ $dump ] : [] );

		$repository      = Repository::initialize( $this->server()->backend( 'database' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		memory_reset_peak_usage();
		$baseline = memory_get_usage();
		$run      = BackupRun::start( $repository, $plan, $work, $this->identity(), new SystemClock() );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
		self::assertLessThan( 64 * 1048576, memory_get_peak_usage() - $baseline, 'The dump is streamed, not buffered.' );

		$cli = new ResticCli( $this->server(), 'database', self::PASSWORD );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$this->cleanup[] = $target = TempDir::create( 'restic-restore' );
		$cli->must( [ 'restore', 'latest', '--target', $target ] );
		$restored = $target . $site . '/database/dump.sql';
		self::assertSame( $size, filesize( $restored ) );
		self::assertSame( SqlDumpSource::digest( 'conformance', $size ), hash_file( 'sha256', $restored ) );
	}

	public function test_format_2_repositories_from_restic_need_zstd(): void {
		if ( Compression::available() ) {
			self::markTestSkipped( 'The zstd extension is installed.' );
		}
		$cli = new ResticCli( $this->server(), 'restic-v2', self::PASSWORD );
		$cli->must( [ 'init' ] );
		// Even with compression off, restic compresses index and snapshot files in format 2.
		$cli->must( [ 'backup', '--compression', 'off', self::$site . '/index.php' ] );

		$repository = Repository::open( $this->server()->backend( 'restic-v2' ), $this->master_key( $cli ), new SystemRandom() );
		self::assertSame( 2, $repository->config()->version );
		$this->expectException( UnsupportedRepository::class );
		$repository->load_index();
	}

	public function test_format_2_repositories_from_restic_restore_with_zstd(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$cli = new ResticCli( $this->server(), 'restic-v2-zstd', self::PASSWORD );
		$cli->must( [ 'init' ] );
		$cli->must( [ 'backup', self::$site ] );

		$repository = Repository::open( $this->server()->backend( 'restic-v2-zstd' ), $this->master_key( $cli ), new SystemRandom() );
		self::assertSame( 2, $repository->config()->version );
		$snapshots = $repository->snapshots();
		self::assertCount( 1, $snapshots );
		$restored = $this->restore( $repository, Id::from_hex( (string) array_key_first( $snapshots ) ) );
		self::assertSame( TempDir::describe( self::$site ), TempDir::describe( $restored . self::$site ) );

		// The plugin writes plain JSON and uncompressed blobs into format 2; restic accepts both.
		$this->cleanup[] = $site = TempDir::create( 'site-v2' );
		TempDir::fixture( $site );
		$this->backup( $repository, $site );
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		$this->cleanup[] = $target = TempDir::create( 'restic-restore' );
		$cli->must( [ 'restore', 'latest', '--host', 'example.com', '--target', $target ] );
		self::assertSame( TempDir::describe( $site ), TempDir::describe( $target . $site ) );
	}

	/**
	 * The status codes recorded in ADR 0025, observed on a real rest-server.
	 */
	public function test_rest_server_answers_map_to_typed_exceptions(): void {
		$backend = $this->server()->backend( 'statuses' );
		$backend->create();
		$name = hash( 'sha256', 'object' );

		self::assertNull( $backend->size( ObjectType::Snapshots, $name ), 'HEAD of a missing object is 404.' );
		self::assert_throws( NotFound::class, static fn () => $backend->load( ObjectType::Snapshots, $name ) );
		self::assert_throws( Unauthorized::class, fn () => $this->server()->backend( 'statuses', 'wrong password' )->list( ObjectType::Snapshots ) );

		$backend->save( ObjectType::Snapshots, $name, 'object' );
		$backend->save( ObjectType::Snapshots, $name, 'object' );
		self::assertSame( 'bj', $backend->load( ObjectType::Snapshots, $name, 1, 2 ), 'Ranged reads return 206.' );
		self::assertSame( [ $name => 6 ], $backend->list( ObjectType::Snapshots ) );
		$backend->delete( ObjectType::Snapshots, $name );
		$backend->delete( ObjectType::Snapshots, $name );
		self::assert_throws( UploadRejected::class, static fn () => $backend->save( ObjectType::Snapshots, $name, 'other content' ) );

		$backend->save( ObjectType::Config, 'config', 'first' );
		self::assert_throws( AlreadyExists::class, static fn () => $backend->save( ObjectType::Config, 'config', 'second' ) );

		$limited = new RestServer( false, 65536 );
		try {
			$small = $limited->backend( 'quota' );
			$small->create();
			$data = random_bytes( 131072 );
			self::assert_throws( QuotaExceeded::class, static fn () => $small->save( ObjectType::Data, hash( 'sha256', $data ), $data ) );
		} finally {
			$limited->stop();
		}
	}

	/**
	 * Asserts that a call throws an exception of the given class.
	 *
	 * @param class-string<\Throwable> $expected Exception class.
	 * @param \Closure(): mixed         $call     Call.
	 */
	private static function assert_throws( string $expected, \Closure $call ): void {
		try {
			$call();
		} catch ( \Throwable $thrown ) {
			self::assertInstanceOf( $expected, $thrown, $thrown->getMessage() );
			return;
		}
		self::fail( $expected . ' was not thrown.' );
	}

	public function test_connection_tester_reports_what_the_wizard_needs(): void {
		$tester = new ConnectionTester( new CurlHttpClient( $this->server()->certificate ), new SystemRandom(), 'backups-for-restic-tests' );
		$empty  = $tester->test( 'rest-server', 'rest:' . $this->server()->url( 'fresh' ), RestServer::USER, new Secret( RestServer::PASSWORD ) );
		self::assertFalse( $empty->exists );
		self::assertSame( AppendOnlyStatus::DeletionsAllowed, $empty->append_only );

		Repository::initialize( $this->server()->backend( 'tested' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$existing = $tester->test( 'rest-server', $this->server()->url( 'tested' ), RestServer::USER, new Secret( RestServer::PASSWORD ) );
		self::assertTrue( $existing->exists );
		self::assertSame( 1, $existing->keys );

		self::$append_only ??= new RestServer( true );
		$locked              = ( new ConnectionTester( new CurlHttpClient( self::$append_only->certificate ), new SystemRandom(), 'tests' ) )->test( 'rest-server', self::$append_only->url( 'probe' ), RestServer::USER, new Secret( RestServer::PASSWORD ) );
		self::assertSame( AppendOnlyStatus::AppendOnly, $locked->append_only );

		$this->expectException( Unauthorized::class );
		$tester->test( 'rest-server', $this->server()->url( 'tested' ), RestServer::USER, new Secret( 'wrong' ) );
	}

	public function test_append_only_server(): void {
		self::$append_only ??= new RestServer( true );
		$backend             = self::$append_only->backend( 'append-only' );
		$repository          = Repository::initialize( $backend, self::PASSWORD, $this->identity(), new SystemRandom() );
		$snapshot            = $this->backup( $repository, self::$site );

		self::assertSame( AppendOnlyStatus::AppendOnly, AppendOnlyProbe::detect( $backend, new SystemRandom() ) );
		self::assertSame( AppendOnlyStatus::DeletionsAllowed, AppendOnlyProbe::detect( $this->server()->backend( 'plugin' ), new SystemRandom() ) );
		self::assertSame( [], $backend->list( ObjectType::Locks ), 'Locks are released in append-only mode.' );
		self::assertStringContainsString( 'no errors were found', ( new ResticCli( self::$append_only, 'append-only', self::PASSWORD ) )->must( [ 'check' ] ) );

		$this->expectException( AppendOnlyRefused::class );
		Forget::delete( $repository, [ $snapshot->hex() ] );
	}

	public function test_restic_respects_the_plugin_lock(): void {
		$repository      = Repository::initialize( $this->server()->backend( 'locked' ), self::PASSWORD, $this->identity(), new SystemRandom() );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$run             = BackupRun::start( $repository, new BackupPlan( [ self::$site ] ), $work, $this->identity(), new SystemClock(), null, 4 * 1048576 );
		self::assertFalse( $run->advance( 0.0 ), 'One step only: the backup is still running.' );

		$cli                   = new ResticCli( $this->server(), 'locked', self::PASSWORD );
		[ $status, , $stderr ] = $cli->run( [ 'check' ] );
		self::assertSame( 11, $status, 'restic cannot take its exclusive lock: ' . $stderr );

		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
		self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
	}
}
