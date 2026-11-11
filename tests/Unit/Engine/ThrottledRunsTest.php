<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Check\CheckRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Support\Throttle;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * In the gentle CPU mode the engine rests between units of work and still finishes.
 */
final class ThrottledRunsTest extends TestCase {

	/** @var list<string> */
	private array $cleanup = [];

	private int $rests = 0;

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	/**
	 * A throttle that rests after every unit, without sleeping.
	 */
	private function throttle(): Throttle {
		return new Throttle(
			0.0,
			0.1,
			null,
			function (): void {
				++$this->rests;
			}
		);
	}

	public function test_a_large_file_rests_between_its_chunks(): void {
		$this->cleanup[] = $site = TempDir::create( 'large' );
		// The same content and the same chunker polynomial in every run: where the chunks end
		// depends on both, and with random ones the file now and then had too few chunks.
		file_put_contents( $site . '/large.bin', ( new FixedRandom( 'large file' ) )->bytes( 12 * 1048576 ) );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$identity        = new Identity( 'example.com', 'www-data' );
		$repository      = Repository::initialize( new InMemoryBackend(), 'throttle password', $identity, new FixedRandom( 'repository' ), new ScryptParameters( 1024, 8, 1 ) );

		$backup = BackupRun::start( $repository, new BackupPlan( [ $site ], [ 'backups-for-restic' ] ), $work . '/backup', $identity, new SystemClock() );
		while ( ! $backup->advance( INF, $this->throttle() ) ) {
			continue;
		}
		self::assertNotNull( $backup->snapshot_id() );
		// About twelve chunks of about 1 MiB; the few units of work around them add a few rests.
		self::assertGreaterThanOrEqual( 10, $this->rests, 'Rests between the chunks of one file, not only between files.' );
	}

	public function test_a_large_pack_rests_between_its_pieces_when_it_is_checked(): void {
		$this->cleanup[] = $site = TempDir::create( 'large' );
		file_put_contents( $site . '/large.bin', random_bytes( 12 * 1048576 ) );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$identity        = new Identity( 'example.com', 'www-data' );
		$repository      = Repository::initialize( new InMemoryBackend(), 'throttle password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$backup          = BackupRun::start( $repository, new BackupPlan( [ $site ], [ 'backups-for-restic' ] ), $work . '/backup', $identity, new SystemClock() );
		while ( ! $backup->advance( INF ) ) {
			continue;
		}

		$check = CheckRun::start( $repository, $work . '/check', $identity, new SystemClock(), new SystemRandom(), 100, null, 1048576 );
		while ( 'read' !== $check->progress()['phase'] ) {
			$check->advance( 0.0 );
		}
		while ( ! $check->advance( INF, $this->throttle() ) ) {
			continue;
		}

		self::assertSame( 0, $check->result()['errors'] );
		// One data pack of about 12 MiB read in pieces of 1 MiB, and a small tree pack.
		self::assertGreaterThanOrEqual( 10, $this->rests, 'Rests between the pieces of one pack, not only between packs.' );
	}

	public function test_backup_and_check_rest_between_units_and_finish(): void {
		$this->cleanup[] = $site = TempDir::create( 'site' );
		TempDir::fixture( $site, 1048576 );
		$this->cleanup[] = $work = TempDir::create( 'work' );
		$identity        = new Identity( 'example.com', 'www-data' );
		$repository      = Repository::initialize( new InMemoryBackend(), 'throttle password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );

		$backup = BackupRun::start( $repository, new BackupPlan( [ $site ], [ 'backups-for-restic' ] ), $work . '/backup', $identity, new SystemClock(), null, 262144 );
		while ( ! $backup->advance( INF, $this->throttle() ) ) {
			continue;
		}
		self::assertNotNull( $backup->snapshot_id() );
		$after_backup = $this->rests;
		self::assertGreaterThan( 5, $after_backup, 'The backup rested after its units of work.' );

		$check = CheckRun::start( $repository, $work . '/check', $identity, new SystemClock(), new SystemRandom(), 100 );
		while ( ! $check->advance( INF, $this->throttle() ) ) {
			continue;
		}
		self::assertSame( 0, $check->result()['errors'] );
		self::assertGreaterThan( $after_backup, $this->rests, 'The check rested too.' );
	}
}
