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
