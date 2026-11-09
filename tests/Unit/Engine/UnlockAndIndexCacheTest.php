<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\KeyUnlock;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\CountingBackend;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

final class UnlockAndIndexCacheTest extends TestCase {

	private const string PASSWORD = 'unlock password';

	/** @var list<string> */
	private array $cleanup = [];

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	private function directory(): string {
		return $this->cleanup[] = TempDir::create( 'kdf' );
	}

	public function test_unlock_resumes_across_ticks_and_cleans_up(): void {
		$backend    = new InMemoryBackend();
		$repository = Repository::initialize( $backend, self::PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 16384, 8, 1 ) );
		$directory  = $this->directory() . '/unlock';

		$ticks  = 0;
		$master = null;
		while ( null === $master ) {
			$unlock = new KeyUnlock( $backend, new Secret( self::PASSWORD ), $directory, new SystemRandom() );
			$master = $unlock->run( microtime( true ) + 0.05 );
			if ( null === $master ) {
				self::assertGreaterThan( 0.0, $unlock->progress() );
				self::assertSame( 0600, fileperms( $directory . '/state.json' ) & 0777 );
			}
			++$ticks;
		}
		self::assertGreaterThan( 1, $ticks, 'The derivation spanned several ticks.' );
		self::assertSame( $repository->master_key()->to_json(), $master->to_json() );
		self::assertDirectoryDoesNotExist( $directory );
	}

	public function test_progress_at_a_whole_number_survives_the_saved_state(): void {
		$backend = new InMemoryBackend();
		Repository::initialize( $backend, self::PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$directory = $this->directory() . '/unlock';
		self::assertNull( ( new KeyUnlock( $backend, new Secret( self::PASSWORD ), $directory, new SystemRandom() ) )->run( 0.0 ) );

		// A pause at the end of a phase saves exactly 1.0, which PHP's JSON encoder writes as 1.
		$state = json_decode( (string) file_get_contents( $directory . '/state.json' ), true );
		self::assertIsArray( $state );
		$state['progress'] = 1.0;
		file_put_contents( $directory . '/state.json', (string) json_encode( $state ) );

		self::assertSame( 1.0, ( new KeyUnlock( $backend, new Secret( self::PASSWORD ), $directory, new SystemRandom() ) )->progress() );
	}

	public function test_wrong_password_tries_every_key_then_fails(): void {
		$backend = new InMemoryBackend();
		Repository::initialize( $backend, self::PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$directory = $this->directory() . '/unlock';
		$this->expectException( WrongPassword::class );
		try {
			( new KeyUnlock( $backend, new Secret( 'wrong' ), $directory, new SystemRandom() ) )->run( INF );
		} finally {
			self::assertDirectoryDoesNotExist( $directory );
		}
	}

	public function test_index_set_round_trips_through_its_binary_form(): void {
		$index = new IndexSet();
		$pack  = Id::from_hex( str_repeat( 'ab', 32 ) );
		$blob  = Id::from_hex( str_repeat( 'cd', 32 ) );
		$tree  = Id::from_hex( str_repeat( 'ef', 32 ) );
		$index->add_pack( $pack, [ new PackEntry( BlobType::Data, $blob, 0, 100 ), new PackEntry( BlobType::Tree, $tree, 100, 50 ) ] );

		$copy = IndexSet::from_binary( $index->to_binary() );
		self::assertSame( $index->to_binary(), $copy->to_binary() );
		self::assertSame( 100, $copy->find( $tree, BlobType::Tree )?->entry->offset );
		self::assertTrue( $copy->has_pack( $pack ) );

		$this->expectException( FormatException::class );
		IndexSet::from_binary( substr( $index->to_binary(), 0, -1 ) );
	}

	public function test_index_cache_downloads_only_new_index_files_and_rebuilds_after_removals(): void {
		$backend    = new CountingBackend();
		$repository = Repository::initialize( $backend, self::PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$site       = $this->cleanup[] = TempDir::create( 'cache-site' );
		TempDir::fixture( $site, 2 * 1048576 );
		$this->back_up( $repository, $site );

		$cache = new IndexCache( $this->directory() . '/cache' );
		$full  = $repository->load_index();
		self::assertSame( $full->to_binary(), $cache->load( $repository )->to_binary() );

		$backend->loads = [];
		self::assertSame( $full->to_binary(), $cache->load( $repository )->to_binary() );
		self::assertArrayNotHasKey( 'index', $backend->loads, 'Nothing new on the server, nothing downloaded.' );

		file_put_contents( $site . '/new.txt', 'new content' );
		$this->back_up( $repository, $site );
		$expected       = $repository->load_index();
		$backend->loads = [];
		self::assertSame( $expected->count(), $cache->load( $repository )->count() );
		self::assertSame( 1, $backend->loads['index'] ?? 0, 'Only the new index file was fetched.' );

		$names = array_keys( $backend->list( ObjectType::Index ) );
		$backend->delete( ObjectType::Index, (string) $names[0] );
		self::assertSame( $repository->load_index()->to_binary(), $cache->load( $repository )->to_binary(), 'A removed index file forces a rebuild.' );
	}

	public function test_an_index_larger_than_the_cache_limit_is_not_kept(): void {
		$backend    = new CountingBackend();
		$repository = Repository::initialize( $backend, self::PASSWORD, new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$site       = $this->cleanup[] = TempDir::create( 'limit-site' );
		TempDir::fixture( $site, 1048576 );
		$this->back_up( $repository, $site );
		$directory = $this->directory() . '/limited';
		$full      = $repository->load_index();

		$small = new IndexCache( $directory, 64 );
		self::assertSame( $full->to_binary(), $small->load( $repository )->to_binary(), 'The index is still complete.' );
		self::assertFileDoesNotExist( $directory . '/index.bin' );

		$roomy = new IndexCache( $directory, 1048576 );
		$roomy->load( $repository );
		self::assertFileExists( $directory . '/index.bin' );
		$small->load( $repository );
		self::assertFileExists( $directory . '/index.bin', 'Nothing new to write: the cache stays as it is.' );

		file_put_contents( $site . '/new.txt', 'new content' );
		$this->back_up( $repository, $site );
		self::assertSame( $repository->load_index()->to_binary(), $small->load( $repository )->to_binary() );
		self::assertFileDoesNotExist( $directory . '/index.bin', 'A cache over the limit is removed when it would grow.' );
	}

	private function back_up( Repository $repository, string $site ): void {
		$work = $this->cleanup[] = TempDir::create( 'work' );
		$run  = BackupRun::start( $repository, new BackupPlan( [ $site ] ), $work, new Identity( 'example.com' ), new SystemClock(), null, 1048576 );
		while ( ! $run->advance( microtime( true ) + 20 ) ) {
			continue;
		}
	}
}
