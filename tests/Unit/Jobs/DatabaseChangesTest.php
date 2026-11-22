<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\Apply\DatabaseGeneration;
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

	private const string OTHER = '7c4dd2a2-d1b4-11f1-9b6f-0242ac120002';

	/** The applied-and-failed import of another server, as a failover leaves it behind. */
	private function stranded( string $id = '20260101T000001Z-aaaaaa', string $uuid = self::OTHER ): Job {
		return $this->owner( $id, [ 'database_owner' => self::binding( str_repeat( 'c', 32 ), $uuid ) ] );
	}

	/**
	 * Expects one refusal code, or a refusal without a code.
	 *
	 * @param \Closure():mixed $work Work that must be refused.
	 */
	private static function refused( ?string $code, \Closure $work ): void {
		try {
			$work();
			self::fail( 'Expected the refusal ' . ( $code ?? 'without a code' ) );
		} catch ( JobConflict | DatabaseFailed $refusal ) {
			self::assertSame( $code, $refusal->context()['code'] ?? null, $refusal->getMessage() );
		}
	}

	public function test_a_binding_of_another_server_lets_backups_start_and_holds_only_a_new_database_apply(): void {
		$owner = $this->stranded();
		self::refused( 'database_exchange_cleanup', fn () => $this->jobs->create( JobType::Backup, 'schedule', $this->clock->seconds ) );
		self::assertTrue( $this->changes( $this->server() )->expire( false, microtime( true ) + 5 ) );
		$saved = $this->jobs->get( $owner->id );
		self::assertNotNull( $saved );
		self::assertSame( $this->clock->seconds, $saved->data['database_foreign'] ?? null );
		self::assertArrayHasKey( 'database_owner', $saved->data, 'Nothing is forgotten without a decision.' );
		self::assertCount( 1, $saved->warnings );
		self::assertStringContainsString( 'database-discard ' . $owner->id, $saved->warnings[0] );
		self::refused( 'database_exchange_foreign', fn () => $this->jobs->create( JobType::DatabaseApply, 'manual', $this->clock->seconds ) );
		self::assertTrue( $this->jobs->while_idle( static fn (): bool => true ) );
		$backup = $this->jobs->create( JobType::Backup, 'schedule', $this->clock->seconds );
		self::assertSame( JobState::Queued, $backup->state );
		self::assertSame( [], DatabaseGeneration::exclusions( $this->server(), $saved ), 'Nothing here can be proven to be one of its tables.' );
		// The heartbeat reports the binding once.
		$backup->state = JobState::Succeeded;
		$this->jobs->save( $backup );
		self::assertTrue( $this->changes( $this->server() )->expire( false, microtime( true ) + 5 ) );
		self::assertCount( 1, $this->jobs->get( $owner->id )?->warnings ?? [] );
	}

	public function test_a_failed_import_on_another_server_marks_its_binding_in_cleanup(): void {
		$owner       = $this->stranded();
		$owner->step = 'cleaning_up';
		$this->jobs->save( $owner );
		self::assertTrue( $this->changes( $this->server() )->step( JobType::DatabaseApply, 'cleaning_up' )->advance( $owner, $this->jobs->log( $owner ), microtime( true ) + 5 ) );
		self::assertIsInt( $this->jobs->get( $owner->id )?->data['database_foreign'] ?? null );
		$backup = $this->jobs->create( JobType::Backup, 'schedule', $this->clock->seconds );
		self::assertSame( JobState::Queued, $backup->state );
	}

	public function test_uninstall_forgets_a_binding_of_another_server_without_touching_any_table(): void {
		$owner = $this->stranded();
		self::assertTrue( $this->changes( $this->server() )->expire( true, microtime( true ) + 5 ) );
		self::assertArrayNotHasKey( 'database_owner', $this->jobs->get( $owner->id )?->data ?? [ 'database_owner' => true ] );
	}

	public function test_a_server_that_names_another_database_or_no_uuid(): void {
		$moved = $this->stranded( '20260101T000001Z-aaaaaa', self::UUID );
		self::assertTrue( $this->changes( $this->server( self::UUID, 'renamed' ) )->expire( false, microtime( true ) + 5 ) );
		self::assertArrayHasKey( 'database_foreign', $this->jobs->get( $moved->id )?->data ?? [] );
		// Without a readable UUID the server cannot be told apart from the original one.
		$unknown = $this->stranded( '20260101T000002Z-bbbbbb', self::UUID );
		self::refused( null, fn () => $this->changes( $this->server( '' ) )->expire( false, microtime( true ) + 5 ) );
		$saved = $this->jobs->get( $unknown->id );
		self::assertArrayNotHasKey( 'database_foreign', $saved?->data ?? [] );
		self::assertTrue( $saved?->result['database_cleanup_pending'] ?? false );
		self::refused( 'database_exchange_cleanup', fn () => $this->jobs->create( JobType::Backup, 'schedule', $this->clock->seconds ) );
	}

	public function test_a_foreign_binding_is_discarded_with_its_private_names_and_nothing_is_dropped(): void {
		$owner     = $this->stranded();
		$preview   = $this->changes( $this->server() )->foreign_binding( $owner->id );
		$discarded = $this->changes( $this->server() )->discard( $owner->id, 7, 'rest' );
		self::assertSame( $preview, $discarded );
		$base = '_bfr_r_' . str_repeat( 'c', 32 );
		self::assertSame(
			[
				'job'      => $owner->id,
				'database' => 'site',
				'server'   => self::OTHER,
				'tables'   => [ $base . '_p', $base . '_a', $base . '_u', $base . '_t0', $base . '_b0', $base . '_t1', $base . '_b1' ],
			],
			$discarded
		);
		$saved = $this->jobs->get( $owner->id );
		self::assertNotNull( $saved );
		self::assertArrayNotHasKey( 'database_owner', $saved->data );
		self::assertSame(
			[
				'at'   => $this->clock->seconds,
				'user' => 7,
				'via'  => 'rest',
			] + $discarded,
			$saved->data['database_discarded'] ?? null
		);
		self::assertStringContainsString( 'Nothing was dropped', $saved->warnings[0] ?? '' );
		self::assertContains( 'database_exchange_foreign', array_column( array_column( $this->jobs->log( $saved )->read( 5 ), 'context' ), 'code' ) );
		$backup = $this->jobs->create( JobType::DatabaseApply, 'manual', $this->clock->seconds );
		self::assertSame( JobState::Queued, $backup->state, 'Once discarded, another database apply may start.' );
		self::refused( 'database_binding_missing', fn () => $this->changes( $this->server() )->discard( $owner->id, 7, 'rest' ) );
	}

	public function test_only_a_finished_binding_that_this_server_disowns_can_be_discarded(): void {
		$here = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ) ) ] );
		self::refused( 'database_binding_verified', fn () => $this->changes( $this->server() )->discard( $here->id, 1, 'wp-cli' ) );
		self::assertArrayHasKey( 'database_owner', $this->jobs->get( $here->id )?->data ?? [] );
		self::refused( 'database_binding_missing', fn () => $this->changes( $this->server() )->discard( '20260101T000009Z-cccccc', 1, 'wp-cli' ) );
		self::refused( 'database_binding_missing', fn () => $this->changes( $this->server() )->discard( 'not-a-job', 1, 'wp-cli' ) );
		$running        = $this->stranded( '20260101T000002Z-bbbbbb' );
		$running->state = JobState::Running;
		$this->jobs->save( $running );
		self::refused( 'job_conflict', fn () => $this->changes( $this->server() )->discard( $running->id, 1, 'wp-cli' ) );
		$running->state = JobState::Failed;
		$this->jobs->save( $running );
		$undo       = new Job( '20260101T000003Z-dddddd', JobType::DatabaseUndo, 'manual', $this->clock->seconds );
		$undo->data = [ 'apply' => $running->id ];
		$this->jobs->save( $undo );
		self::refused( 'job_conflict', fn () => $this->changes( $this->server() )->discard( $running->id, 1, 'wp-cli' ) );
		self::refused( null, fn () => $this->changes( $this->server( '' ) )->foreign_binding( $here->id ) );
	}
}
