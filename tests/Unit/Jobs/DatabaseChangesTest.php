<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Job-level recovery of database generations. The server here only identifies itself;
 * every other statement fails the test, so nothing can be dropped or changed by mistake.
 */
final class DatabaseChangesTest extends TestCase {

	private const string UUID = '08a6c803-e508-11f1-b766-1a4b24ab2573';

	private string $directory;

	private JobStore $jobs;

	private FrozenClock $clock;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'database-changes' );
		$this->clock     = new FrozenClock();
		$this->jobs      = new JobStore( $this->directory . '/jobs', new Redactor(), new FixedRandom() );
		mkdir( $this->directory . '/site' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/** A MySQL server that answers only who it is. */
	private function server( string $uuid = self::UUID, string $database = 'site' ): Connection {
		return new class( $uuid, $database ) implements Connection {
			public function __construct( private string $uuid, private string $database ) {}

			public function rows( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT DATABASE() AS db' ) ) {
					return [
						[
							'db'        => $this->database,
							'version'   => '8.4.11',
							'read_only' => '0',
							'name_case' => '0',
						],
					];
				}
				if ( "SHOW VARIABLES LIKE 'server_uuid'" === $sql ) {
					return [ [ 'Value' => $this->uuid ] ];
				}
				throw new \LogicException( 'Unexpected query: ' . $sql );
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'No statement may change this server: ' . $sql );
			}
		};
	}

	private function changes( Connection $server ): DatabaseChanges {
		$site = realpath( $this->directory . '/site' );
		self::assertIsString( $site );
		return new DatabaseChanges(
			$this->jobs,
			new Staging( $this->directory . '/restores', $this->jobs, $this->clock ),
			$this->clock,
			new FixedRandom(),
			24,
			'wp_',
			static fn (): Connection => $server,
			static fn ( int $user ): array => [],
			new FileMaintenance( $site ),
			static function (): void {}
		);
	}

	/**
	 * The private tables of an import that one server holds.
	 *
	 * @return array<string,int|string>
	 */
	private static function binding( string $token, string $uuid = self::UUID, string $database = 'site' ): array {
		return [
			'version'     => 1,
			'prefix'      => 'wp_',
			'fingerprint' => str_repeat( 'f', 64 ),
			'count'       => 2,
			'token'       => $token,
			'database'    => $database,
			'uuid'        => $uuid,
		];
	}

	/**
	 * A finished database apply with its saved data.
	 *
	 * @param array<string,mixed> $data Job data.
	 */
	private function owner( string $id, array $data, JobState $state = JobState::Failed ): Job {
		$job        = new Job( $id, JobType::DatabaseApply, 'manual', $this->clock->seconds );
		$job->state = $state;
		$job->data  = [
			'restore' => '20251231T000000Z-000000',
			'user'    => 1,
			'digest'  => str_repeat( 'd', 64 ),
		] + $data;
		$this->jobs->save( $job );
		return $job;
	}

	public function test_a_stuck_generation_does_not_keep_older_ones_from_their_cleanup(): void {
		$older = $this->owner(
			'20260101T000001Z-aaaaaa',
			[
				'database_owner'   => self::binding( str_repeat( '1', 32 ) ),
				'database_cleanup' => 'unreadable',
			]
		);
		$newer = $this->owner(
			'20260101T000002Z-bbbbbb',
			[
				'database_owner'   => self::binding( str_repeat( '2', 32 ) ),
				'database_cleanup' => 'unreadable',
			]
		);
		try {
			$this->changes( $this->server() )->expire( false, microtime( true ) + 5 );
			self::fail( 'A stuck cleanup was not reported.' );
		} catch ( FormatException $stuck ) {
			self::assertSame( 'The database cleanup cursor is unreadable.', $stuck->getMessage() );
		}
		foreach ( [ $newer, $older ] as $job ) {
			$saved = $this->jobs->get( $job->id );
			self::assertNotNull( $saved );
			self::assertTrue( $saved->result['database_cleanup_pending'] ?? false, 'Every due generation gets its turn: ' . $job->id );
			self::assertCount( 1, $saved->warnings );
		}
	}

	public function test_a_spent_request_reports_the_failure_it_met_and_leaves_the_rest_for_later(): void {
		$older = $this->owner(
			'20260101T000001Z-aaaaaa',
			[
				'database_owner'   => self::binding( str_repeat( '1', 32 ) ),
				'database_cleanup' => 'unreadable',
			]
		);
		$this->owner(
			'20260101T000002Z-bbbbbb',
			[
				'database_owner'   => self::binding( str_repeat( '2', 32 ) ),
				'database_cleanup' => 'unreadable',
			]
		);
		try {
			$this->changes( $this->server() )->expire( false, microtime( true ) - 1 );
			self::fail( 'A stuck cleanup was not reported.' );
		} catch ( FormatException ) {
			self::assertArrayNotHasKey( 'database_cleanup_pending', $this->jobs->get( $older->id )?->result ?? [] );
		}
	}
}
