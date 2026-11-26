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
		$preview   = $this->changes( $this->server() )->discard_review( $owner->id, 7 );
		$discarded = $this->changes( $this->server() )->discard( $owner->id, 7, 'rest', $preview['digest'] );
		self::assertSame( array_intersect_key( $preview, $discarded ), $discarded );
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
		self::assertSame( array_intersect_key( $review, $discarded ), $discarded );
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
			self::assertSame( 'database_discard_unsaved', $unsaved->context()['code'] ?? null );
		} finally {
			chmod( $directory, 0700 );
		}
		self::assertSame( $job->data, $this->jobs->get( $job->id )?->data, 'The binding stays.' );
		self::assertSame( $review, $this->changes( $this->server() )->discard_review( $job->id, 7 ) );
	}

	public function test_the_list_of_bindings_to_discard_asks_about_each_finished_binding_as_its_review_does(): void {
		// Without a binding the database is not asked, nor its connection opened.
		self::assertSame(
			[
				'jobs'     => [],
				'complete' => true,
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
				'jobs'     => $listed,
				'complete' => true,
			],
			$this->changes( $this->server() )->foreign_bindings()
		);
		// A server that cannot be identified or asked: what the jobs recorded, and the list says so.
		$flagged = $this->jobs->get( $failed->id );
		self::assertNotNull( $flagged );
		$flagged->data['database_foreign'] = $this->clock->seconds;
		$this->jobs->save( $flagged );
		$unknown = [
			'jobs'     => [ $listed[0] ],
			'complete' => false,
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
}
