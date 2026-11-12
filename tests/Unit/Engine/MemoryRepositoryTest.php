<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Prune\PruneRun;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\MemoryRepository;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** The memory benchmark must exercise valid history and actual prune work. */
final class MemoryRepositoryTest extends TestCase {

	public function test_the_fixture_has_the_requested_index_size_and_survives_prune_and_full_verification(): void {
		$directory = TempDir::create( 'memory-fixture' );
		try {
			$backend    = new FileBackend( $directory . '/repository' );
			$identity   = new Identity( 'memory-fixture.example' );
			$random     = new SystemRandom();
			$clock      = new SystemClock();
			$repository = Repository::initialize( $backend, MemoryRepository::PASSWORD, $identity, $random, new ScryptParameters( 1024, 8, 1 ) );
			$fixture    = ( new MemoryRepository( $repository ) )->build( 500, $directory );
			self::assertSame( 500, $fixture['blobs'] );
			self::assertSame( 500, $repository->load_index()->count() );
			self::assertGreaterThan( 1, $fixture['trees'] );
			self::assertGreaterThan( 0, $fixture['unused'] );
			$check = CheckRun::start( $repository, $directory . '/before', $identity, $clock, $random, 100 );
			self::assertTrue( $check->advance( INF ) );
			self::assertSame( 0, $check->result()['errors'] );
			self::assertSame( 500, $check->result()['stats']['blobs_verified'] );
			$prune = PruneRun::start( $repository, $directory . '/prune', $identity, $clock, $random );
			self::assertTrue( $prune->advance( INF ) );
			self::assertSame( 1, $prune->result()['packs_deleted'] );
			self::assertSame( 500 - $fixture['unused'], $repository->load_index()->count() );
			$check = CheckRun::start( $repository, $directory . '/after', $identity, $clock, $random, 100 );
			self::assertTrue( $check->advance( INF ) );
			self::assertSame( 0, $check->result()['errors'] );
			self::assertSame( 0, $check->result()['warnings'] );
			self::assertSame( [], $backend->list( ObjectType::Locks ) );
		} finally {
			TempDir::remove( $directory );
		}
	}
}
