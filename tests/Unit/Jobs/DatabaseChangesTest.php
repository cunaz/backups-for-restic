<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Apply\DatabaseChanges;
use BackupsForRestic\Jobs\Apply\DatabaseGeneration;
use BackupsForRestic\Jobs\Apply\DatabaseReview;
use BackupsForRestic\Jobs\Apply\ExchangeSite;
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
			static function (): void {},
			new ExchangeSite( 'backups-for-restic/backups-for-restic.php', static fn (): array => [ 'manage_options' ], static fn ( string $plugin ): bool => true )
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
		// The command shows what to drop; it drops nothing itself, so it comes first.
		self::assertStringContainsString( 'drops nothing', $saved->warnings[0] );
		self::assertLessThan( strpos( $saved->warnings[0], 'by hand' ), strpos( $saved->warnings[0], 'database-discard' ) );
		// Whether a new database apply waits is for its review, which asks the server
		// (DatabaseExchangeTest); the mark of another server holds no other job.
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
		$preview   = $this->changes( $this->server() )->discard_review( $owner->id, 7 );
		$discarded = $this->changes( $this->server() )->discard( $owner->id, 7, 'rest', $preview['digest'] );
		self::assertSame( array_intersect_key( $preview, $discarded ) + [ 'until' => $this->clock->seconds + JobStore::DISCARD_LISTED_DAYS * 86400 ], $discarded );
		$base = '_bfr_r_' . str_repeat( 'c', 32 );
		self::assertSame(
			[
				'job'      => $owner->id,
				'database' => 'site',
				'server'   => self::OTHER,
				'tables'   => [ $base . '_p', $base . '_a', $base . '_u', $base . '_t0', $base . '_b0', $base . '_t1', $base . '_b1' ],
				// The Restores tab and WP-CLI list them until then.
				'until'    => $this->clock->seconds + JobStore::DISCARD_LISTED_DAYS * 86400,
			],
			$discarded
		);
		$saved = $this->jobs->get( $owner->id );
		self::assertNotNull( $saved );
		self::assertArrayNotHasKey( 'database_owner', $saved->data );
		// The confirmed digest is kept with the decision, not only in the log, which is written at best effort.
		self::assertSame(
			[
				'at'     => $this->clock->seconds,
				'user'   => 7,
				'via'    => 'rest',
				'digest' => $preview['digest'],
			] + array_diff_key( $discarded, [ 'until' => true ] ),
			$saved->data['database_discarded'] ?? null
		);
		self::assertStringContainsString( 'Nothing was dropped', $saved->warnings[0] ?? '' );
		self::assertContains( 'database_exchange_foreign', array_column( array_column( $this->jobs->log( $saved )->read( 5 ), 'context' ), 'code' ) );
		$backup = $this->jobs->create( JobType::DatabaseApply, 'manual', $this->clock->seconds );
		self::assertSame( JobState::Queued, $backup->state, 'Once discarded, another database apply may start.' );
		self::refused( 'database_binding_discarded', fn () => $this->changes( $this->server() )->discard( $owner->id, 7, 'rest', $preview['digest'] ) );
	}

	public function test_only_a_finished_binding_that_this_server_disowns_can_be_discarded(): void {
		$here = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ) ) ] );
		self::refused( 'database_binding_verified', fn () => $this->changes( $this->server() )->discard( $here->id, 1, 'wp-cli', str_repeat( 'd', 64 ) ) );
		self::assertArrayHasKey( 'database_owner', $this->jobs->get( $here->id )?->data ?? [] );
		self::refused( 'database_binding_missing', fn () => $this->changes( $this->server() )->discard( '20260101T000009Z-cccccc', 1, 'wp-cli', str_repeat( 'd', 64 ) ) );
		self::refused( 'database_binding_missing', fn () => $this->changes( $this->server() )->discard( 'not-a-job', 1, 'wp-cli', str_repeat( 'd', 64 ) ) );
		$running        = $this->stranded( '20260101T000002Z-bbbbbb' );
		$running->state = JobState::Running;
		$this->jobs->save( $running );
		self::refused( 'database_binding_busy', fn () => $this->changes( $this->server() )->discard( $running->id, 1, 'wp-cli', str_repeat( 'd', 64 ) ) );
		$running->state = JobState::Failed;
		$this->jobs->save( $running );
		$undo       = new Job( '20260101T000003Z-dddddd', JobType::DatabaseUndo, 'manual', $this->clock->seconds );
		$undo->data = [ 'apply' => $running->id ];
		$this->jobs->save( $undo );
		self::refused( 'database_binding_busy', fn () => $this->changes( $this->server() )->discard( $running->id, 1, 'wp-cli', str_repeat( 'd', 64 ) ) );
		self::refused( 'database_binding_unverified', fn () => $this->changes( $this->server( '' ) )->discard_review( $here->id, 1 ) );
	}

	/**
	 * A server whose replies are lost: every statement fails as after a dropped session.
	 *
	 * @param \ArrayObject<int,string> $queries Statements that reached it.
	 */
	private function lost( \ArrayObject $queries ): Connection {
		return new class( $queries ) implements Connection {
			/** @param \ArrayObject<int,string> $queries */
			public function __construct( private \ArrayObject $queries ) {}

			public function rows( string $sql ): array {
				$this->queries[] = $sql;
				throw new DatabaseFailed( 'Lost connection to the server.', [ 'code' => 2013 ] );
			}

			public function execute( string $sql ): int {
				$this->queries[] = $sql;
				throw new DatabaseFailed( 'Lost connection to the server.', [ 'code' => 2013 ] );
			}
		};
	}

	public function test_cleanup_after_a_lost_session_waits_for_a_later_tick_and_a_bound(): void {
		$job       = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ) ) ], JobState::Running );
		$job->step = 'swapping_database';
		$queries   = new \ArrayObject();
		$changes   = $this->changes( $this->lost( $queries ) );
		$log       = $this->jobs->log( $job );
		try {
			$changes->step( JobType::DatabaseApply, 'swapping_database' )->advance( $job, $log, microtime( true ) + 30 );
			self::fail( 'A lost session was not reported.' );
		} catch ( DatabaseFailed $busy ) {
			self::assertSame( 'database_restore_busy', $busy->context()['code'] ?? null );
		}
		$asked   = count( $queries );
		$cleanup = $changes->step( JobType::DatabaseApply, 'cleaning_up' );
		self::assertFalse( $cleanup->advance( $job, $log, microtime( true ) + 5 ), 'The cleanup of that request does not use the lost session.' );
		self::assertCount( $asked, $queries );
		// A later tick tries again; while the server stays unreachable it waits ten minutes at most.
		self::assertFalse( $cleanup->advance( $job, $log, microtime( true ) + 5 ) );
		self::assertGreaterThan( $asked, count( $queries ) );
		self::assertSame( $this->clock->seconds + 600, $job->data['database_reconcile_until'] ?? null );
		$this->clock->seconds += 599;
		self::assertFalse( $cleanup->advance( $job, $log, microtime( true ) + 5 ) );
		$this->clock->seconds += 1;
		try {
			$cleanup->advance( $job, $log, microtime( true ) + 5 );
			self::fail( 'The cleanup waited beyond its bound.' );
		} catch ( DatabaseFailed $busy ) {
			self::assertSame( 'database_restore_busy', $busy->context()['code'] ?? null );
		}
	}

	public function test_cleanup_reports_other_failures_at_once(): void {
		$job       = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => array_replace( self::binding( str_repeat( 'c', 32 ) ), [ 'version' => 2 ] ) ], JobState::Running );
		$job->step = 'cleaning_up';
		try {
			$this->changes( $this->server() )->step( JobType::DatabaseApply, 'cleaning_up' )->advance( $job, $this->jobs->log( $job ), microtime( true ) + 5 );
			self::fail( 'A damaged binding was not reported.' );
		} catch ( DatabaseFailed $failed ) {
			self::assertSame( 'database_exchange_cleanup', $failed->context()['code'] ?? null );
		}
		self::assertArrayNotHasKey( 'database_reconcile_until', $job->data );
	}

	public function test_a_packet_the_server_refused_is_reported_as_such_and_ends_the_session(): void {
		$job       = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ) ) ], JobState::Running );
		$job->step = 'importing_database';
		$server    = new class() implements Connection {
			public int $asked = 0;

			public function rows( string $sql ): array {
				++$this->asked;
				throw new DatabaseFailed( 'Got a packet bigger than max_allowed_packet bytes.', [ 'code' => 1153 ] );
			}

			public function execute( string $sql ): int {
				++$this->asked;
				throw new DatabaseFailed( 'Got a packet bigger than max_allowed_packet bytes.', [ 'code' => 1153 ] );
			}
		};
		$changes   = $this->changes( $server );
		try {
			$changes->step( JobType::DatabaseApply, 'importing_database' )->advance( $job, $this->jobs->log( $job ), microtime( true ) + 5 );
			self::fail( 'The refused packet was not reported.' );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( 'database_restore_packet', $refused->context()['code'] ?? null );
		}
		$asked = $server->asked;
		self::assertFalse( $changes->step( JobType::DatabaseApply, 'cleaning_up' )->advance( $job, $this->jobs->log( $job ), microtime( true ) + 5 ), 'The server closed that session.' );
		self::assertSame( $asked, $server->asked );
	}

	/** A third server, to which the site could move after the review. */
	private const string THIRD = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

	/**
	 * The service on a connection that a closure gives, as WordPress gives it.
	 *
	 * @param \Closure():Connection $connection Connection.
	 */
	private function changes_through( \Closure $connection ): DatabaseChanges {
		$site = realpath( $this->directory . '/site' );
		self::assertIsString( $site );
		return new DatabaseChanges(
			$this->jobs,
			new Staging( $this->directory . '/restores', $this->jobs, $this->clock ),
			$this->clock,
			new FixedRandom(),
			24,
			'wp_',
			$connection,
			static fn ( int $user ): array => [],
			new FileMaintenance( $site ),
			static function (): void {},
			new ExchangeSite( 'backups-for-restic/backups-for-restic.php', static fn (): array => [ 'manage_options' ], static fn ( string $plugin ): bool => true )
		);
	}

	/**
	 * A finished database apply whose binding names another server, with what its exchange recorded.
	 *
	 * @param array<string,mixed> $data Further job data.
	 */
	private function moved( string $id, array $data = [], JobState $state = JobState::Succeeded ): Job {
		return $this->owner( $id, [ 'database_owner' => self::binding( str_repeat( 'c', 32 ), self::OTHER ) ] + $data, $state );
	}

	/**
	 * What a review of a discard shows, without its digest.
	 *
	 * @param array<string,mixed> $review Review.
	 * @return array<string,mixed> Shown facts.
	 */
	private static function shown( array $review ): array {
		return array_diff_key( $review, array_flip( [ 'digest', 'applicable' ] ) );
	}

	public function test_the_review_of_a_discard_names_what_it_forgets_and_what_stays(): void {
		$until   = $this->clock->seconds + 3600;
		$applied = $this->moved(
			'20260101T000001Z-aaaaaa',
			[
				'database_generation' => 'applied',
				'retained_until'      => $until,
			]
		);
		// The server answers who it is and nothing else; it would refuse any other statement.
		$review = $this->changes( $this->server( self::UUID, 'moved' ) )->discard_review( $applied->id, 7 );
		$base   = '_bfr_r_' . str_repeat( 'c', 32 );
		self::assertSame(
			[
				'job'            => $applied->id,
				'restore'        => '20251231T000000Z-000000',
				'database'       => 'site',
				'server'         => self::OTHER,
				'connected'      => [
					'database' => 'moved',
					'server'   => self::UUID,
				],
				'tables'         => [ $base . '_p', $base . '_a', $base . '_u', $base . '_t0', $base . '_b0', $base . '_t1', $base . '_b1' ],
				'generation'     => 'applied',
				'retained_until' => $until,
				'undo_until'     => $until,
				'kept'           => DatabaseChanges::DISCARD_KEPT,
			],
			self::shown( $review )
		);
		self::assertSame( [ 'live_database', 'private_tables', 'job_record' ], DatabaseChanges::DISCARD_KEPT );
		self::assertTrue( $review['applicable'] );
		// Versioned and keyed to the confirming user as the review of an exchange is, by the same function.
		self::assertSame( DatabaseReview::digest( 1, [ 'discard', 7, self::shown( $review ) ] ), $review['digest'] );
		self::assertSame( $applied->data, $this->jobs->get( $applied->id )?->data, 'A review changes nothing.' );
		self::assertSame( [], $this->jobs->log( $applied )->read(), 'A review writes nothing to the log.' );
		// An undo whose window has ended, or whose expiry has begun, no longer ends with the discard.
		$ended = $this->jobs->get( $applied->id );
		self::assertNotNull( $ended );
		$ended->data['retained_until'] = $this->clock->seconds;
		$this->jobs->save( $ended );
		$late = $this->changes( $this->server() )->discard_review( $applied->id, 7 );
		self::assertSame( [ 'applied', $this->clock->seconds, null ], [ $late['generation'], $late['retained_until'], $late['undo_until'] ] );
		$ended->data['retained_until']              = $until;
		$ended->data['database_cleanup_generation'] = 'applied';
		$this->jobs->save( $ended );
		self::assertNull( $this->changes( $this->server() )->discard_review( $applied->id, 7 )['undo_until'] );
		// An undone exchange keeps the generation that its undo replaced; an unfinished import has none.
		$undone = $this->moved(
			'20260101T000002Z-bbbbbb',
			[
				'database_generation' => 'undone',
				'retained_until'      => $until,
			]
		);
		$review = $this->changes( $this->server() )->discard_review( $undone->id, 7 );
		self::assertSame( [ 'undone', $until, null ], [ $review['generation'], $review['retained_until'], $review['undo_until'] ] );
		$review = $this->changes( $this->server() )->discard_review( $this->stranded( '20260101T000003Z-cccccc' )->id, 7 );
		self::assertSame( [ null, null, null ], [ $review['generation'], $review['retained_until'], $review['undo_until'] ] );
		self::assertSame( 'site', $review['connected']['database'] );
		// A generation that the plugin does not record is shown as none.
		$unknown = $this->moved( '20260101T000004Z-dddddd', [ 'database_generation' => 'prepared' ] );
		self::assertNull( $this->changes( $this->server() )->discard_review( $unknown->id, 7 )['generation'] );
	}

	public function test_the_digest_of_a_discard_covers_everything_that_its_review_shows(): void {
		$until  = $this->clock->seconds + 3600;
		$job    = $this->moved(
			'20260101T000001Z-aaaaaa',
			[
				'database_generation' => 'applied',
				'retained_until'      => $until,
			]
		);
		$digest = fn ( int $user = 7, ?Connection $server = null ): string => $this->changes( $server ?? $this->server() )->discard_review( $job->id, $user )['digest'];
		$first  = $digest();
		self::assertSame( $first, $digest(), 'The same facts give the same digest.' );
		$digests = [
			'first'              => $first,
			'user'               => $digest( 8 ),
			'connected database' => $digest( 7, $this->server( self::UUID, 'moved' ) ),
			'connected server'   => $digest( 7, $this->server( self::THIRD ) ),
		];
		$changes = [
			'database'   => static function ( Job $changed ): void {
				$changed->data['database_owner']['database'] = 'elsewhere';
			},
			'server'     => static function ( Job $changed ): void {
				$changed->data['database_owner']['uuid'] = self::THIRD;
			},
			'tables'     => static function ( Job $changed ): void {
				$changed->data['database_owner']['count'] = 3;
			},
			'names'      => static function ( Job $changed ): void {
				$changed->data['database_owner']['token'] = str_repeat( 'e', 32 );
			},
			'restore'    => static function ( Job $changed ): void {
				$changed->data['restore'] = '20251231T000000Z-111111';
			},
			'generation' => static function ( Job $changed ): void {
				$changed->data['database_generation'] = 'undone';
			},
			'window'     => static function ( Job $changed ) use ( $until ): void {
				$changed->data['retained_until'] = $until + 1;
			},
		];
		foreach ( $changes as $name => $change ) {
			$changed = $this->jobs->get( $job->id );
			self::assertNotNull( $changed );
			$change( $changed );
			$this->jobs->save( $changed );
			$digests[ $name ] = $digest();
			$this->jobs->save( $job );
		}
		$this->clock->seconds  = $until;
		$digests['undo ended'] = $digest();
		self::assertSame( array_keys( $digests ), array_keys( array_unique( $digests ) ), 'Each fact that the review shows is in its digest.' );
	}

	public function test_a_discard_needs_the_digest_of_its_review_and_refuses_a_binding_that_changed_since(): void {
		$until  = $this->clock->seconds + 3600;
		$job    = $this->moved(
			'20260101T000001Z-aaaaaa',
			[
				'database_generation' => 'applied',
				'retained_until'      => $until,
			]
		);
		$review = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		$kept   = function () use ( $job ): void {
			$saved = $this->jobs->get( $job->id );
			self::assertNotNull( $saved );
			self::assertSame( $job->data, $saved->data, 'Nothing is forgotten.' );
			self::assertSame( [], $saved->warnings );
			self::assertSame( [], $this->jobs->log( $saved )->read() );
		};
		// Another review's digest, and this review confirmed by another user.
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', str_repeat( '0', 64 ) ) );
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server() )->discard( $job->id, 8, 'rest', $review['digest'] ) );
		$kept();
		// The heartbeat recorded an undo after the review.
		$undone = $this->jobs->get( $job->id );
		self::assertNotNull( $undone );
		$undone->data['database_generation'] = 'undone';
		$this->jobs->save( $undone );
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] ) );
		$this->jobs->save( $job );
		// The site moved on to a third server, and the undo window ended.
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server( self::THIRD ) )->discard( $job->id, 7, 'rest', $review['digest'] ) );
		$this->clock->seconds = $until;
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'wp-cli', $review['digest'] ) );
		$this->clock->seconds = $until - 3600;
		$kept();
		$discarded = $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] );
		self::assertSame( array_intersect_key( $review, $discarded ) + [ 'until' => $this->clock->seconds + JobStore::DISCARD_LISTED_DAYS * 86400 ], $discarded );
		$saved = $this->jobs->get( $job->id );
		self::assertNotNull( $saved );
		foreach ( [ 'database_owner', 'database_generation', 'retained_until' ] as $forgotten ) {
			self::assertArrayNotHasKey( $forgotten, $saved->data );
		}
		// The job keeps the names where the administrator finds them again, with who confirmed which review.
		self::assertCount( 1, $saved->warnings );
		foreach ( [ 'database site on the server ' . self::OTHER, 'Nothing was dropped', implode( ', ', $review['tables'] ) ] as $part ) {
			self::assertStringContainsString( $part, $saved->warnings[0] );
		}
		$entries = $this->jobs->log( $saved )->read();
		self::assertSame(
			[
				'user'   => 7,
				'via'    => 'rest',
				'digest' => $review['digest'],
				'code'   => 'database_exchange_foreign',
			],
			end( $entries )['context'] ?? null
		);
	}

	public function test_the_review_and_the_discard_answer_a_binding_that_cannot_be_discarded_with_what_is_the_case(): void {
		$review = fn ( string $id, ?Connection $server = null ): \Closure => fn () => $this->changes( $server ?? $this->server() )->discard_review( $id, 7 );
		self::refused( 'database_binding_missing', $review( '20260101T000009Z-cccccc' ) );
		self::refused( 'database_binding_missing', $review( 'not-a-job' ) );
		$backup        = new Job( '20260101T000008Z-eeeeee', JobType::Backup, 'manual', $this->clock->seconds );
		$backup->state = JobState::Succeeded;
		$this->jobs->save( $backup );
		self::refused( 'database_binding_missing', $review( $backup->id ) );
		self::refused( 'database_binding_missing', $review( $this->owner( '20260101T000007Z-ffffff', [] )->id ) );
		// A document that this version cannot read is a job all the same.
		mkdir( $this->directory . '/jobs/20260101T000006Z-abcabc' );
		file_put_contents( $this->directory . '/jobs/20260101T000006Z-abcabc/job.json', '{' );
		self::refused( 'job_unreadable', $review( '20260101T000006Z-abcabc' ) );
		// One that the PHP user cannot open is refused with its own code and the job's ID, as
		// every other request is: a link in the place of the document is such a job.
		mkdir( $this->directory . '/jobs/20260101T000005Z-abcabc' );
		symlink( $this->directory . '/jobs/20260101T000006Z-abcabc/job.json', $this->directory . '/jobs/20260101T000005Z-abcabc/job.json' );
		try {
			$review( '20260101T000005Z-abcabc' )();
			self::fail( 'A job that cannot be opened was reviewed.' );
		} catch ( JobConflict $refusal ) {
			self::assertSame( [ 'job_inaccessible', '20260101T000005Z-abcabc' ], [ $refusal->context()['code'] ?? null, $refusal->context()['job'] ?? null ] );
		}
		// While a stored job cannot be read, no binding is reviewed at all: the cases below need them gone.
		unlink( $this->directory . '/jobs/20260101T000005Z-abcabc/job.json' );
		rmdir( $this->directory . '/jobs/20260101T000005Z-abcabc' );
		TempDir::remove( $this->directory . '/jobs/20260101T000006Z-abcabc' );
		// A binding of this database, and a server that does not say which one it is.
		$here = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ) ) ] );
		self::refused( 'database_binding_verified', $review( $here->id ) );
		self::refused( 'database_binding_unverified', $review( $here->id, $this->server( '' ) ) );
		self::refused( 'database_binding_unverified', $review( $here->id, $this->server( 'not-a-uuid' ) ) );
		// Records of a binding that cannot be read: on another server, and on the recorded one.
		$token = $this->owner( '20260101T000002Z-bbbbbb', [ 'database_owner' => array_replace( self::binding( str_repeat( 'c', 32 ), self::OTHER ), [ 'token' => 'not-a-token' ] ) ] );
		self::refused( 'database_binding_unreadable', $review( $token->id ) );
		$version = $this->owner( '20260101T000003Z-dddddd', [ 'database_owner' => array_replace( self::binding( str_repeat( 'c', 32 ), self::OTHER ), [ 'version' => 2 ] ) ] );
		self::refused( 'database_binding_unreadable', $review( $version->id ) );
		$pair    = static fn ( string $name, int $id ): array => [
			'name'          => $name,
			'before'        => (string) $id,
			'after'         => (string) ( $id + 1 ),
			'before_schema' => str_repeat( 'a', 64 ),
			'after_schema'  => str_repeat( 'b', 64 ),
		];
		$planned = $this->owner(
			'20260101T000004Z-aaaaab',
			[
				'database_owner' => self::binding( str_repeat( 'c', 32 ) ),
				'exchange_plan'  => [
					'version'  => 1,
					'database' => 'site',
					'uuid'     => self::UUID,
					'prefix'   => 'wp_',
					'witness'  => '1',
					'pairs'    => [ $pair( 'wp_options', 2 ), $pair( 'wp_users', 4 ), $pair( 'wp_usermeta', 6 ) ],
				],
			]
		);
		self::refused( 'database_binding_unreadable', $review( $planned->id ) );
		// The job, or an undo of it, has not ended.
		$running        = $this->stranded( '20260101T000005Z-aaaaac' );
		$running->state = JobState::Running;
		$this->jobs->save( $running );
		self::refused( 'database_binding_busy', $review( $running->id ) );
		$running->state = JobState::Failed;
		$this->jobs->save( $running );
		self::assertIsArray( $review( $running->id )() );
		$undo       = new Job( '20260101T000010Z-aaaaad', JobType::DatabaseUndo, 'manual', $this->clock->seconds );
		$undo->data = [ 'apply' => $running->id ];
		$this->jobs->save( $undo );
		self::refused( 'database_binding_busy', $review( $running->id ) );
		$undo->state = JobState::Cancelled;
		$this->jobs->save( $undo );
		// Discarded already: said as such, also to a confirmation that arrives twice.
		$digest = $this->changes( $this->server() )->discard_review( $running->id, 7 )['digest'];
		$this->changes( $this->server() )->discard( $running->id, 7, 'rest', $digest );
		self::refused( 'database_binding_discarded', $review( $running->id ) );
		self::refused( 'database_binding_discarded', fn () => $this->changes( $this->server() )->discard( $running->id, 7, 'rest', $digest ) );
	}

	public function test_a_database_failure_is_written_to_the_log_of_the_job_that_holds_the_binding(): void {
		$job = $this->stranded();
		// The server does not answer at all, and one that answers who it is once and then fails.
		$once     = new class( self::UUID ) implements Connection {
			private int $asked = 0;

			public function __construct( private string $uuid ) {}

			public function rows( string $sql ): array {
				if ( ++$this->asked > 2 ) {
					throw new DatabaseFailed( 'The database refused a restore statement.', [ 'code' => 1142 ] );
				}
				return str_starts_with( $sql, 'SELECT DATABASE() AS db' ) ? [
					[
						'db'        => 'site',
						'version'   => '8.4.11',
						'read_only' => '0',
						'name_case' => '0',
					],
				] : [ [ 'Value' => $this->uuid ] ];
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'No statement may change this server: ' . $sql );
			}
		};
		$failures = [
			2013                    => $this->changes( $this->lost( new \ArrayObject() ) ),
			1142                    => $this->changes( $once ),
			'database_restore_busy' => $this->changes_through( static fn (): Connection => throw new DatabaseFailed( 'The database connection could not be opened again.', [ 'code' => 'database_restore_busy' ] ) ),
		];
		foreach ( $failures as $error => $changes ) {
			foreach ( [ fn () => $changes->discard_review( $job->id, 7 ), fn () => $changes->discard( $job->id, 7, 'rest', str_repeat( 'd', 64 ) ) ] as $work ) {
				self::refused( 'database_discard_failed', $work );
				$entries = $this->jobs->log( $job )->read();
				self::assertSame( 'error', end( $entries )['level'] ?? null );
				self::assertSame(
					[
						'code'  => 'database_discard_failed',
						'cause' => 'database_restore_busy' === $error ? 'The database connection could not be opened again.' : ( 2013 === $error ? 'Lost connection to the server.' : 'The database refused a restore statement.' ),
						'error' => $error,
					],
					end( $entries )['context'] ?? null
				);
			}
		}
		self::assertSame( $job->data, $this->jobs->get( $job->id )?->data, 'Nothing was discarded.' );
		self::assertCount( 6, $this->jobs->log( $job )->read() );
	}

	public function test_a_discard_that_cannot_be_recorded_keeps_the_binding_and_says_so(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Directory permissions do not bind root.' );
		}
		$job       = $this->stranded();
		$review    = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		$directory = $this->jobs->directory( $job );
		chmod( $directory, 0500 );
		try {
			$this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] );
			self::fail( 'A discard was recorded in a read-only directory.' );
		} catch ( StorageFailed $unsaved ) {
			self::assertSame( 'database_discard_storage', $unsaved->context()['code'] ?? null );
		} finally {
			chmod( $directory, 0700 );
		}
		self::assertSame( $job->data, $this->jobs->get( $job->id )?->data, 'The binding stays.' );
		self::assertSame( $review, $this->changes( $this->server() )->discard_review( $job->id, 7 ) );
	}

	public function test_the_list_of_bindings_to_discard_asks_about_each_finished_binding_as_its_review_does(): void {
		// Without a binding the database is not asked, nor its connection opened, also not for a
		// finished database restore whose binding has ended.
		$this->owner( '20251231T000001Z-0f0f0f', [], JobState::Succeeded );
		self::assertSame(
			[
				'jobs'      => [],
				'complete'  => true,
				'discarded' => [],
			],
			$this->changes_through( static fn (): Connection => throw new \LogicException( 'The connection was asked for without a binding.' ) )->foreign_bindings()
		);
		$until  = $this->clock->seconds + 3600;
		$moved  = $this->moved(
			'20260101T000001Z-aaaaaa',
			[
				'database_generation' => 'applied',
				'retained_until'      => $until,
			]
		);
		$failed = $this->stranded( '20260101T000002Z-bbbbbb' );
		$this->owner( '20260101T000003Z-cccccc', [ 'database_owner' => self::binding( str_repeat( 'd', 32 ) ) ] );
		$this->moved( '20260101T000004Z-dddddd', [], JobState::Running );
		$this->owner( '20260101T000005Z-eeeeee', [ 'database_owner' => array_replace( self::binding( str_repeat( 'e', 32 ), self::OTHER ), [ 'version' => 2 ] ) ] );
		$listed = [
			[
				'job'            => $failed->id,
				'restore'        => '20251231T000000Z-000000',
				'generation'     => null,
				'retained_until' => null,
			],
			[
				'job'            => $moved->id,
				'restore'        => '20251231T000000Z-000000',
				'generation'     => 'applied',
				'retained_until' => $until,
			],
		];
		self::assertSame(
			[
				'jobs'      => $listed,
				'complete'  => true,
				'discarded' => [],
			],
			$this->changes( $this->server() )->foreign_bindings()
		);
		// A server that cannot be identified or asked: what the jobs recorded, and the list says so.
		$flagged = $this->jobs->get( $failed->id );
		self::assertNotNull( $flagged );
		$flagged->data['database_foreign'] = $this->clock->seconds;
		$this->jobs->save( $flagged );
		$unknown = [
			'jobs'      => [ $listed[0] ],
			'complete'  => false,
			'discarded' => [],
		];
		self::assertSame( $unknown, $this->changes( $this->server( '' ) )->foreign_bindings() );
		self::assertSame( $unknown, $this->changes( $this->lost( new \ArrayObject() ) )->foreign_bindings() );
		self::assertSame( $unknown, $this->changes_through( static fn (): Connection => throw new DatabaseFailed( 'The database connection could not be opened again.', [ 'code' => 'database_restore_busy' ] ) )->foreign_bindings() );
	}

	public function test_the_warning_of_a_discard_quotes_a_name_that_does_not_print(): void {
		// Another database chose the name: a right-to-left override must not turn the warning around.
		$job    = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ), self::OTHER, "bfr_\u{202E}site" ) ] );
		$review = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		self::assertSame( "bfr_\u{202E}site", $review['database'], 'The review gives the stored name; the page escapes it.' );
		$this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] );
		$warning = $this->jobs->get( $job->id )?->warnings[0] ?? '';
		self::assertStringContainsString( 'in the database "bfr_\u202esite" on the server ' . self::OTHER . ' was discarded.', $warning );
		self::assertStringNotContainsString( "\u{202E}", $warning );
	}

	public function test_the_warning_of_a_discard_is_kept_in_a_job_that_holds_as_many_warnings_as_it_keeps(): void {
		$job = $this->owner( '20260101T000001Z-aaaaaa', [ 'database_owner' => self::binding( str_repeat( 'c', 32 ), self::OTHER ) ] );
		for ( $number = 1; $number <= Job::MAX_WARNINGS + 1; ++$number ) {
			$job->warn( 'Warning ' . $number );
		}
		self::assertCount( Job::MAX_WARNINGS, $job->warnings );
		$this->jobs->save( $job );
		$review = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		$this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] );
		$warnings = $this->jobs->get( $job->id )?->warnings ?? [];
		// Before, the limit dropped the one warning that names what is left to remove by hand.
		// It comes after the limit, and takes the place of no other warning.
		self::assertCount( Job::MAX_WARNINGS + 1, $warnings );
		self::assertSame( 'Warning ' . Job::MAX_WARNINGS, $warnings[ Job::MAX_WARNINGS - 1 ] );
		self::assertStringContainsString( " was discarded. Nothing was dropped. Remove by hand only those that exist there and that you can verify as this restore's copies: a table with one of these names may be another one, such as a copy of the site's data on a new server: " . implode( ', ', $review['tables'] ), $warnings[ Job::MAX_WARNINGS ] );
	}

	public function test_a_warning_of_what_is_left_to_do_by_hand_never_takes_the_place_of_another(): void {
		$job = new Job( '20260101T000001Z-aaaaaa', JobType::DatabaseApply, 'manual', $this->clock->seconds );
		for ( $number = 1; $number <= Job::MAX_WARNINGS; ++$number ) {
			$job->warn( 'Warning ' . $number );
		}
		// The tables that an expiry kept, then those of a discard: both lists stay, and so do the others.
		$job->warn_kept( 'Rebuilt tables to drop by hand: a' );
		$job->warn_kept( 'Discarded tables to remove by hand: b' );
		$job->warn( 'Beyond the limit' );
		self::assertSame( [ ...array_map( static fn ( int $number ): string => 'Warning ' . $number, range( 1, Job::MAX_WARNINGS ) ), 'Rebuilt tables to drop by hand: a', 'Discarded tables to remove by hand: b' ], $job->warnings );
	}

	public function test_a_kept_warning_takes_no_place_of_a_later_one_also_after_the_job_was_saved(): void {
		$job = $this->owner( '20260101T000001Z-aaaaaa', [] );
		for ( $number = 1; $number < Job::MAX_WARNINGS; ++$number ) {
			$job->warn( 'Warning ' . $number );
		}
		// The rebuilt tables that an expiry keeps, then, after a save, the warning of a cleanup that fails.
		$job->warn_kept( 'Rebuilt tables to drop by hand: a' );
		$this->jobs->save( $job );
		$saved = $this->jobs->get( $job->id );
		self::assertNotNull( $saved );
		$saved->warn( 'Cleanup is overdue.' );
		$saved->warn( 'Beyond the limit' );
		// Before, the kept warning counted against the limit of the others, and the overdue cleanup was dropped.
		self::assertSame( [ ...array_map( static fn ( int $number ): string => 'Warning ' . $number, range( 1, Job::MAX_WARNINGS - 1 ) ), 'Rebuilt tables to drop by hand: a', 'Cleanup is overdue.' ], $saved->warnings );
		self::assertSame( 1, $saved->warnings_kept );
		// A document that says more were kept than it has warnings counts no more than it has.
		$document                  = $saved->to_array();
		$document['warnings_kept'] = 99;
		self::assertSame( Job::MAX_WARNINGS + 1, Job::from_array( $document )->warnings_kept );
	}

	public function test_the_list_of_bindings_asks_the_server_who_it_is_once_for_all_of_them(): void {
		$this->moved( '20260101T000001Z-aaaaaa' );
		$this->stranded( '20260101T000002Z-bbbbbb' );
		$this->owner( '20260101T000003Z-cccccc', [ 'database_owner' => self::binding( str_repeat( 'd', 32 ) ) ] );
		$asked  = new \ArrayObject();
		$server = new class( $this->server(), $asked ) implements Connection {
			/** @param \ArrayObject<int,string> $asked */
			public function __construct( private Connection $server, private \ArrayObject $asked ) {}

			public function rows( string $sql ): array {
				$this->asked[] = $sql;
				return $this->server->rows( $sql );
			}

			public function execute( string $sql ): int {
				return $this->server->execute( $sql );
			}
		};
		self::assertSame( [ '20260101T000002Z-bbbbbb', '20260101T000001Z-aaaaaa' ], array_column( $this->changes( $server )->foreign_bindings()['jobs'], 'job' ) );
		// Before, each of the three bindings asked again, on each load of the Restores tab.
		self::assertCount( 2, $asked, implode( "\n", (array) $asked ) );
	}

	public function test_a_connected_database_whose_name_is_not_utf8_is_reviewed_and_the_binding_discarded(): void {
		// A connection in latin1 to a database with a name that is not ASCII.
		$job    = $this->stranded();
		$server = $this->server( self::UUID, "m\xF6bel" );
		$review = $this->changes( $server )->discard_review( $job->id, 7 );
		self::assertSame( "m\xF6bel", $review['connected']['database'], 'The review gives the bytes; the REST answer and the page escape them.' );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $review['digest'] );
		self::assertNotSame( $review['digest'], $this->changes( $this->server( self::UUID, "m\xF7bel" ) )->discard_review( $job->id, 7 )['digest'] );
		self::refused( 'database_discard_changed', fn () => $this->changes( $this->server( self::UUID, "m\xF7bel" ) )->discard( $job->id, 7, 'rest', $review['digest'] ) );
		self::assertSame( 'site', $this->changes( $server )->discard( $job->id, 7, 'rest', $review['digest'] )['database'] );
		self::assertArrayNotHasKey( 'database_owner', $this->jobs->get( $job->id )?->data ?? [ 'database_owner' => true ] );
	}

	public function test_a_review_or_discard_whose_jobs_cannot_be_listed_says_so_and_points_to_no_log(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Directory permissions do not bind root.' );
		}
		$job    = $this->stranded();
		$review = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		// The job's own document can still be read; the list of the jobs cannot, and PHP warns about it.
		chmod( $this->directory . '/jobs', 0300 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			foreach ( [ fn () => $this->changes( $this->server() )->discard_review( $job->id, 7 ), fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] ), fn () => $this->changes( $this->server() )->discarded_bindings(), fn () => $this->changes( $this->server() )->foreign_bindings() ] as $work ) {
				try {
					$work();
					self::fail( 'An answer was made without the list of the jobs.' );
				} catch ( StorageFailed $failure ) {
					// Before, this was answered as a database failure that the log of the job names.
					self::assertSame( 'database_discard_storage', $failure->context()['code'] ?? null );
					self::assertContains( 'database_discard_storage', DatabaseChanges::DISCARD_FAILURES );
				}
			}
		} finally {
			restore_error_handler();
			chmod( $this->directory . '/jobs', 0700 );
		}
		self::assertSame( [], $this->jobs->log( $job )->read(), 'Nothing was written to a log, and no answer points to one.' );
		self::assertSame( $job->data, $this->jobs->get( $job->id )?->data, 'The binding stays.' );
	}

	public function test_the_tables_of_a_discarded_binding_stay_listed_and_its_job_kept_until_they_are_taken_care_of(): void {
		$job   = $this->stranded();
		$older = $this->stranded( '20251201T000001Z-aaaaab' );
		// 30 later jobs, as backups leave them: advanced.keep_jobs is 30 by default.
		for ( $number = 0; $number < 30; ++$number ) {
			$backup        = new Job( sprintf( '20260102T0000%02dZ-bbbb%02d', $number, $number ), JobType::Backup, 'schedule', $this->clock->seconds );
			$backup->state = JobState::Succeeded;
			$this->jobs->save( $backup );
		}
		$changes = $this->changes( $this->server() );
		self::assertSame( [], $changes->discarded_bindings() );
		$review    = $changes->discard_review( $job->id, 7 );
		$at        = $this->clock->seconds;
		$discarded = $changes->discard( $job->id, 7, 'rest', $review['digest'] );
		$until     = $at + JobStore::DISCARD_LISTED_DAYS * 86400;
		self::assertSame( $until, $discarded['until'] );
		$this->clock->advance( 86400 );
		$changes->discard( $older->id, 7, 'wp-cli', $changes->discard_review( $older->id, 7 )['digest'] );
		$entry = [
			'job'      => $job->id,
			'restore'  => '20251231T000000Z-000000',
			'at'       => $at,
			'via'      => 'rest',
			'database' => $review['database'],
			'server'   => $review['server'],
			'tables'   => $review['tables'],
			'until'    => $until,
		];
		self::assertSame( [ $entry, $older->id ], [ $changes->discarded_bindings()[0], $changes->discarded_bindings()[1]['job'] ] );
		// Before, the next backup's cleanup deleted the job with its record of the discard and its log.
		self::assertSame( 0, $this->jobs->prune( 30, $this->clock->seconds ), 'The job history keeps both jobs while they list their tables.' );
		self::refused( 'database_binding_discarded', fn () => $changes->discard_review( $job->id, 7 ) );
		// The administrator says that the tables of one are taken care of.
		self::assertSame( $entry, $changes->discarded_done( $job->id, 9, 'rest' ) );
		self::assertSame(
			[
				'at'   => $this->clock->seconds,
				'user' => 9,
				'via'  => 'rest',
			],
			$this->jobs->get( $job->id )?->data['database_discarded']['done'] ?? null
		);
		self::assertSame( $review['tables'], $this->jobs->get( $job->id )?->data['database_discarded']['tables'] ?? null, 'The record of the discard stays as it was.' );
		self::assertSame( [ $older->id ], array_column( $changes->discarded_bindings(), 'job' ) );
		$logged = $this->jobs->log( $job )->read();
		self::assertSame(
			[
				'The private tables of the discarded binding were marked as taken care of.',
				[
					'user' => 9,
					'via'  => 'rest',
				],
			],
			[ end( $logged )['message'] ?? null, end( $logged )['context'] ?? null ]
		);
		self::refused( 'database_discarded_missing', fn () => $changes->discarded_done( $job->id, 9, 'rest' ) );
		self::refused( 'database_discarded_missing', fn () => $changes->discarded_done( '20991231T235959Z-ffffff', 9, 'rest' ) );
		self::assertSame( 1, $this->jobs->prune( 30, $this->clock->seconds ), 'Then the job history prunes it as any other.' );
		self::assertNull( $this->jobs->get( $job->id ) );
		// The other is listed until its stated time has passed.
		$this->clock->advance( JobStore::DISCARD_LISTED_DAYS * 86400 - 1 );
		self::assertSame( [ $older->id ], array_column( $changes->discarded_bindings(), 'job' ) );
		// The Restores tab reads the same list, with the bindings to discard.
		self::assertSame( $changes->discarded_bindings(), $changes->foreign_bindings()['discarded'] );
		self::assertSame( 0, $this->jobs->prune( 30, $this->clock->seconds ) );
		$this->clock->advance( 1 );
		self::assertSame( [], $changes->discarded_bindings() );
		self::assertSame( [], $changes->foreign_bindings()['discarded'] );
		self::refused( 'database_discarded_missing', fn () => $changes->discarded_done( $older->id, 9, 'rest' ) );
		self::assertSame( 1, $this->jobs->prune( 30, $this->clock->seconds ) );
	}

	public function test_a_discard_recorded_without_its_listing_time_is_listed_from_the_time_it_records(): void {
		// The record as a discard wrote it before the listing existed: no time of its own for the list.
		$job                             = $this->stranded();
		$job->data['database_discarded'] = [
			'at'       => $this->clock->seconds - 86400,
			'user'     => 1,
			'via'      => 'rest',
			'job'      => $job->id,
			'database' => 'site',
			'server'   => self::OTHER,
			'tables'   => [ 'bfr_r_x', 'bfr_r_y' ],
		];
		unset( $job->data['database_owner'] );
		$this->jobs->save( $job );
		self::assertSame( [ [ $job->id, $this->clock->seconds - 86400 + JobStore::DISCARD_LISTED_DAYS * 86400, [ 'bfr_r_x', 'bfr_r_y' ] ] ], array_map( static fn ( array $entry ): array => [ $entry['job'], $entry['until'], $entry['tables'] ], $this->changes( $this->server() )->discarded_bindings() ) );
		// A record without its time or names lists nothing and keeps nothing.
		$job->data['database_discarded'] = [ 'tables' => [ 'bfr_r_x' ] ];
		$this->jobs->save( $job );
		self::assertSame( [], $this->changes( $this->server() )->discarded_bindings() );
		self::assertNull( JobStore::discard_listed_until( $job ) );
	}

	public function test_a_discarded_binding_whose_record_does_not_name_its_tables_is_not_listed_and_cannot_be_marked(): void {
		$record = [
			'at'       => $this->clock->seconds,
			'user'     => 1,
			'via'      => 'rest',
			'database' => 'site',
			'server'   => self::OTHER,
			'tables'   => [ 'bfr_r_x', 5, 'bfr_r_y' ],
		];
		$broken = [];
		foreach ( [
			[ 'database', 7 ],
			[ 'server', null ],
			[ 'tables', 'bfr_r_x' ],
			// Before, a list of entries that are no table names was listed empty, and could be marked.
			[ 'tables', [ null, 5 ] ],
			[ 'tables', [ '' ] ],
		] as [ $field, $value ] ) {
			$job = $this->stranded( sprintf( '20260101T00000%dZ-cccccc', count( $broken ) + 2 ) );
			unset( $job->data['database_owner'] );
			$job->data['database_discarded'] = [ $field => $value ] + $record;
			$this->jobs->save( $job );
			$broken[] = $job->id;
		}
		// A record without the way it was confirmed, and with a table entry that is no name.
		$job = $this->stranded();
		unset( $job->data['database_owner'] );
		$job->data['database_discarded'] = array_diff_key( $record, [ 'via' => true ] );
		$this->jobs->save( $job );
		$changes = $this->changes( $this->server() );
		self::assertSame( [ [ $job->id, '', [ 'bfr_r_x', 'bfr_r_y' ] ] ], array_map( static fn ( array $entry ): array => [ $entry['job'], $entry['via'], $entry['tables'] ], $changes->discarded_bindings() ) );
		foreach ( $broken as $id ) {
			self::refused( 'database_discarded_missing', fn () => $changes->discarded_done( $id, 9, 'rest' ) );
		}
		self::assertSame( [ 'bfr_r_x', 'bfr_r_y' ], $changes->discarded_done( $job->id, 9, 'rest' )['tables'] );
	}

	public function test_the_end_of_a_listing_that_cannot_be_recorded_keeps_it_listed_and_says_so(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Directory permissions do not bind root.' );
		}
		$job     = $this->stranded();
		$changes = $this->changes( $this->server() );
		$changes->discard( $job->id, 7, 'rest', $changes->discard_review( $job->id, 7 )['digest'] );
		$directory = $this->jobs->directory( $job );
		chmod( $directory, 0500 );
		try {
			$changes->discarded_done( $job->id, 9, 'rest' );
			self::fail( 'The end of a listing was recorded in a read-only directory.' );
		} catch ( StorageFailed $unsaved ) {
			self::assertSame( 'database_discard_storage', $unsaved->context()['code'] ?? null );
		} finally {
			chmod( $directory, 0700 );
		}
		self::assertSame( [ $job->id ], array_column( $changes->discarded_bindings(), 'job' ), 'It is still listed.' );
	}

	public function test_a_binding_is_neither_reviewed_nor_discarded_while_a_stored_job_cannot_be_read(): void {
		$job    = $this->stranded();
		$review = $this->changes( $this->server() )->discard_review( $job->id, 7 );
		// A queued undo of this apply, whose document can no longer be read after the review.
		$undo       = new Job( '20260101T000002Z-bbbbbb', JobType::DatabaseUndo, 'manual', $this->clock->seconds );
		$undo->data = [ 'apply' => $job->id ];
		$this->jobs->save( $undo );
		$document = $this->jobs->directory( $undo ) . '/job.json';
		$readable = (string) file_get_contents( $document );
		file_put_contents( $document, '{"schema":9999}' );
		$refusal = function ( \Closure $work ): array {
			try {
				$work();
			} catch ( JobConflict $refused ) {
				return [ $refused->context()['code'] ?? null, $refused->context()['job'] ?? null ];
			}
			return [];
		};
		// Before, JobStore::active() did not see the undo, and the discard forgot the binding that it needs.
		foreach ( [ fn () => $this->changes( $this->server() )->discard_review( $job->id, 7 ), fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] ) ] as $work ) {
			self::assertSame( [ 'job_unreadable', $undo->id ], $refusal( $work ) );
		}
		self::assertArrayHasKey( 'database_owner', $this->jobs->get( $job->id )?->data ?? [] );
		if ( ! function_exists( 'posix_geteuid' ) || 0 !== posix_geteuid() ) {
			// A document that the PHP user cannot open is refused the same way, with its own code.
			file_put_contents( $document, $readable );
			chmod( $document, 0000 );
			set_error_handler( static fn (): bool => true, E_WARNING );
			try {
				self::assertSame( [ 'job_inaccessible', $undo->id ], $refusal( fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] ) ) );
			} finally {
				restore_error_handler();
				chmod( $document, 0600 );
			}
		}
		// Once it can be read again, the undo of this binding is what keeps it.
		file_put_contents( $document, $readable );
		self::refused( 'database_binding_busy', fn () => $this->changes( $this->server() )->discard( $job->id, 7, 'rest', $review['digest'] ) );
		self::assertArrayHasKey( 'database_owner', $this->jobs->get( $job->id )?->data ?? [] );
	}

	public function test_a_binding_without_a_complete_saved_identity_is_never_foreign(): void {
		$number = 2;
		foreach ( [
			'no server UUID'                    => [ 'uuid' => null ],
			'a server UUID that is no string'   => [ 'uuid' => 5 ],
			'a malformed server UUID'           => [ 'uuid' => 'not-a-uuid' ],
			'no database name'                  => [ 'database' => null ],
			'an empty database name'            => [ 'database' => '' ],
			'a database name that is no string' => [ 'database' => 5 ],
		] as $case => $change ) {
			$binding = array_filter( $change + self::binding( str_repeat( 'c', 32 ) ), static fn ( mixed $value ): bool => null !== $value );
			$job     = $this->owner( sprintf( '20260101T00000%dZ-aaaaaa', $number++ ), [ 'database_owner' => $binding ] );
			// The server and the database that made it are connected; another server would be no different.
			foreach ( [ self::UUID, self::OTHER ] as $uuid ) {
				self::refused( 'database_binding_unreadable', fn () => $this->changes( $this->server( $uuid ) )->discard_review( $job->id, 7 ) );
				self::assertSame( [], $this->changes( $this->server( $uuid ) )->foreign_bindings()['jobs'], $case );
			}
			self::assertFalse( DatabaseGeneration::readable( $job ), $case );
			TempDir::remove( $this->jobs->directory( $job ) );
		}
		// A complete identity of another server is foreign, and one of this server is here.
		$foreign = $this->stranded();
		self::assertTrue( DatabaseGeneration::readable( $foreign ) );
		self::assertSame( [ $foreign->id ], array_column( $this->changes( $this->server() )->foreign_bindings()['jobs'], 'job' ) );
		self::assertSame( [], $this->changes( $this->server( self::OTHER ) )->foreign_bindings()['jobs'] );
	}

	public function test_a_binding_whose_record_cannot_name_its_tables_is_not_offered_for_a_discard(): void {
		$number = 2;
		foreach ( [
			'a token of another form'       => [ 'token' => 'not-a-token' ],
			'a fingerprint of another form' => [ 'fingerprint' => 'not-a-fingerprint' ],
			'another layout'                => [ 'version' => 2 ],
		] as $case => $change ) {
			$job = $this->owner( sprintf( '20260101T00000%dZ-aaaaaa', $number++ ), [ 'database_owner' => array_replace( self::binding( str_repeat( 'c', 32 ), self::OTHER ), $change ) ] );
			// Before, the list offered a record of another server whose token or fingerprint its review refuses.
			self::assertSame( [], $this->changes( $this->server() )->foreign_bindings()['jobs'], $case );
			self::refused( 'database_binding_unreadable', fn () => $this->changes( $this->server() )->discard_review( $job->id, 7 ) );
			self::assertFalse( DatabaseGeneration::readable( $job ), $case );
			TempDir::remove( $this->jobs->directory( $job ) );
		}
	}

	public function test_both_lists_of_bindings_come_from_one_reading_of_the_jobs(): void {
		$job                                   = $this->stranded();
		$discarded                             = clone $job;
		$discarded->data['database_discarded'] = [
			'at'       => $this->clock->seconds,
			'user'     => 1,
			'via'      => 'rest',
			'job'      => $job->id,
			'database' => 'site',
			'server'   => self::OTHER,
			'tables'   => [ '_bfr_r_' . str_repeat( 'c', 32 ) . '_p' ],
		];
		unset( $discarded->data['database_owner'] );
		$inner = $this->server();
		$jobs  = $this->jobs;
		// Another request records the discard while the list asks the server who it is.
		$server = new class( $inner, static fn () => $jobs->save( $discarded ) ) implements Connection {
			private bool $asked = false;

			public function __construct( private Connection $inner, private \Closure $meanwhile ) {}

			public function rows( string $sql ): array {
				if ( ! $this->asked ) {
					$this->asked = true;
					( $this->meanwhile )();
				}
				return $this->inner->rows( $sql );
			}

			public function execute( string $sql ): int {
				return $this->inner->execute( $sql );
			}
		};
		$lists  = $this->changes( $server )->foreign_bindings();
		// Read before the discard: still to discard, not yet discarded. The route read the two
		// lists in two passes before, so that a discard between them could leave it in neither.
		self::assertSame( [ [ $job->id ], [] ], [ array_column( $lists['jobs'], 'job' ), array_column( $lists['discarded'], 'job' ) ] );
		$next = $this->changes( $this->server() )->foreign_bindings();
		self::assertSame( [ [], [ $job->id ] ], [ array_column( $next['jobs'], 'job' ), array_column( $next['discarded'], 'job' ) ] );
	}
}
