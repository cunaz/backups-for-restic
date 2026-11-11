<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Notifications\Notice;
use BackupsForRestic\Notifications\NoticeKind;
use BackupsForRestic\Notifications\Notifier;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\FileLock;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\RecordingMailer;

final class NotifierTest extends TestCase {

	private const int HOUR = 3600;

	/** Setup finished at this time. */
	private const int SETUP = 1_800_000_000;

	private InMemoryOptions $options;

	private RecordingMailer $mailer;

	private int $jobs = 0;

	protected function setUp(): void {
		$this->options = new InMemoryOptions();
		$this->mailer  = new RecordingMailer();
	}

	/**
	 * @param array<string, mixed> $settings Settings besides a finished setup.
	 */
	private function notifier( array $settings = [] ): Notifier {
		$settings += [
			'setup.completed'                    => true,
			'setup.recovery_kit_acknowledged_at' => gmdate( 'c', self::SETUP ),
		];
		return new Notifier( $this->options, new SiteState( $this->options ), Settings::from_stored( $settings ), $this->mailer );
	}

	/**
	 * @param array<string, mixed> $result Job result.
	 */
	private function job( JobType $type, JobState $state, int $finished, string $error = '', array $result = [] ): Job {
		$job              = new Job( sprintf( '20270115T0300%02dZ-abcdef', ++$this->jobs ), $type, 'schedule', $finished - 60 );
		$job->state       = $state;
		$job->finished_at = $finished;
		$job->result      = $result;
		if ( '' !== $error ) {
			$job->error = new JobError( $error, 'Details stay in the log.', false );
		}
		return $job;
	}

	/**
	 * Records a successful backup as the backup's recording step does, then reports it.
	 */
	private function backup_succeeded( Notifier $notifier, int $at ): void {
		$job = $this->job( JobType::Backup, JobState::Succeeded, $at );
		( new SiteState( $this->options ) )->set(
			'last_backup',
			[
				'job'      => $job->id,
				'finished' => $at,
			]
		);
		$notifier->job_finished( $job, $at );
	}

	public function test_the_first_failure_is_reported_once_and_the_recovery_once(): void {
		$notifier = $this->notifier();
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 100, 'repository_unreachable' ), self::SETUP + 100 );
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 200, 'quota_exceeded' ), self::SETUP + 200 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds() );
		$failure = $this->mailer->sent[0];
		self::assertSame( 'backup', $failure->job_type );
		self::assertSame( 'repository_unreachable', $failure->code, 'The code is sent, never the message of the log.' );

		$this->backup_succeeded( $notifier, self::SETUP + 300 );
		$this->backup_succeeded( $notifier, self::SETUP + 400 );
		self::assertSame( [ 'failure', 'recovery' ], $this->mailer->kinds() );
		self::assertSame( self::SETUP + 100, $this->mailer->sent[1]->since );
	}

	public function test_the_stale_notice_fires_once_at_36_hours_and_not_again_until_recovery(): void {
		$notifier = $this->notifier();
		$this->backup_succeeded( $notifier, self::SETUP + self::HOUR );
		$last = self::SETUP + self::HOUR;

		$notifier->heartbeat( $last + 36 * self::HOUR - 1 );
		self::assertSame( [], $this->mailer->kinds() );
		$notifier->heartbeat( $last + 36 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );
		self::assertSame( $last, $this->mailer->sent[0]->since );
		foreach ( [ 37, 48, 100, 500 ] as $hours ) {
			$notifier->heartbeat( $last + $hours * self::HOUR );
		}
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );

		$recovered = $last + 501 * self::HOUR;
		$this->backup_succeeded( $notifier, $recovered );
		self::assertSame( [ 'stale', 'recovery' ], $this->mailer->kinds() );
		self::assertSame( $last + 36 * self::HOUR, $this->mailer->sent[1]->since );

		$notifier->heartbeat( $recovered + 36 * self::HOUR );
		self::assertSame( [ 'stale', 'recovery', 'stale' ], $this->mailer->kinds(), 'Stale again after the next 36 hours.' );
	}

	public function test_without_any_backup_the_stale_time_counts_from_the_setup(): void {
		$notifier = $this->notifier();
		$notifier->heartbeat( self::SETUP + 36 * self::HOUR - 1 );
		$notifier->heartbeat( self::SETUP + 36 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );
		self::assertSame( '', $this->mailer->sent[0]->job_id );
		self::assertSame( self::SETUP, $this->mailer->sent[0]->since );
	}

	public function test_without_a_backup_in_a_new_repository_the_stale_time_counts_from_the_day_it_was_opened(): void {
		$state = new SiteState( $this->options );
		$state->bind( 'first', self::SETUP );
		$notifier = $this->notifier();
		$this->backup_succeeded( $notifier, self::SETUP + self::HOUR );

		// A year later the jobs turn to another repository; what was remembered is dropped.
		$moved = self::SETUP + 365 * 24 * self::HOUR;
		$state->bind( 'second', $moved );

		$notifier->heartbeat( $moved + 36 * self::HOUR - 1 );
		self::assertSame( [], $this->mailer->kinds(), 'Not at once: the new repository had no time for a backup yet.' );
		$notifier->heartbeat( $moved + 36 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );
		self::assertSame( $moved, $this->mailer->sent[0]->since );
	}

	public function test_a_stale_notice_for_one_repository_does_not_hold_back_the_one_for_the_next(): void {
		$state = new SiteState( $this->options );
		$state->bind( 'first', self::SETUP );
		$notifier = $this->notifier();
		$notifier->heartbeat( self::SETUP + 36 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );

		$moved = self::SETUP + 100 * self::HOUR;
		$state->bind( 'second', $moved );
		$notifier->heartbeat( $moved + 36 * self::HOUR );

		self::assertSame( [ 'stale', 'stale' ], $this->mailer->kinds() );
		self::assertSame( $moved, $this->mailer->sent[1]->since );
	}

	public function test_a_success_in_another_repository_ends_no_failure_streak_of_the_old_one(): void {
		$state = new SiteState( $this->options );
		$state->bind( 'first', self::SETUP );
		$notifier = $this->notifier();
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 100, 'repository_unreachable' ), self::SETUP + 100 );

		$state->bind( 'second', self::SETUP + 200 );
		$this->backup_succeeded( $notifier, self::SETUP + 300 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds(), 'No recovery: nothing failed in this repository.' );

		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 400, 'quota_exceeded' ), self::SETUP + 400 );
		self::assertSame( [ 'failure', 'failure' ], $this->mailer->kinds(), 'Its own first failure is reported.' );
	}

	public function test_the_stale_threshold_follows_the_schedule(): void {
		$this->notifier( [ 'schedule.backup.frequency' => 'manual' ] )->heartbeat( self::SETUP + 1000 * self::HOUR );
		self::assertSame( [], $this->mailer->kinds(), 'Manual backups never become stale.' );

		$weekly = $this->notifier( [ 'schedule.backup.frequency' => 'weekly' ] );
		$weekly->heartbeat( self::SETUP + 252 * self::HOUR - 1 );
		self::assertSame( [], $this->mailer->kinds(), 'A weekly schedule waits one and a half weeks.' );
		$weekly->heartbeat( self::SETUP + 252 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );

		$this->options = new InMemoryOptions();
		$this->mailer  = new RecordingMailer();
		$longer        = $this->notifier( [ 'notifications.email.stale_after_hours' => 72 ] );
		$longer->heartbeat( self::SETUP + 72 * self::HOUR - 1 );
		$longer->heartbeat( self::SETUP + 72 * self::HOUR );
		self::assertSame( [ 'stale' ], $this->mailer->kinds() );
	}

	public function test_no_stale_notice_before_the_setup_is_finished(): void {
		$this->notifier( [ 'setup.completed' => false ] )->heartbeat( self::SETUP + 1000 * self::HOUR );
		self::assertSame( [], $this->mailer->kinds() );
	}

	public function test_failure_streaks_are_kept_per_job_type(): void {
		$notifier = $this->notifier();
		$notifier->job_finished( $this->job( JobType::Check, JobState::Failed, self::SETUP + 10, 'repository_unreachable' ), self::SETUP + 10 );
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 20, 'repository_unreachable' ), self::SETUP + 20 );
		$notifier->job_finished( $this->job( JobType::Prune, JobState::Failed, self::SETUP + 30, 'repository_damaged' ), self::SETUP + 30 );
		$notifier->job_finished( $this->job( JobType::Forget, JobState::Failed, self::SETUP + 40, 'repository_locked' ), self::SETUP + 40 );
		self::assertSame( [ 'check', 'backup', 'prune', 'forget' ], array_map( static fn ( Notice $notice ): string => $notice->job_type, $this->mailer->sent ) );

		$this->backup_succeeded( $notifier, self::SETUP + 50 );
		$notifier->job_finished( $this->job( JobType::Prune, JobState::Succeeded, self::SETUP + 60 ), self::SETUP + 60 );
		self::assertSame( [ 'failure', 'failure', 'failure', 'failure', 'recovery', 'recovery' ], $this->mailer->kinds() );
		self::assertSame( [ 'backup', 'prune' ], [ $this->mailer->sent[4]->job_type, $this->mailer->sent[5]->job_type ] );
	}

	public function test_check_findings_are_reported_each_time(): void {
		$notifier = $this->notifier();
		$damaged  = [
			'errors'   => 3,
			'warnings' => 1,
		];
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 10, '', $damaged ), self::SETUP + 10 );
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 20, '', $damaged ), self::SETUP + 20 );
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 30, '', [ 'errors' => 0 ] ), self::SETUP + 30 );
		self::assertSame( [ 'check_findings', 'check_findings' ], $this->mailer->kinds() );
		self::assertSame( 3, $this->mailer->sent[0]->count );
	}

	public function test_findings_replace_the_recovery_notice_of_a_check(): void {
		$notifier = $this->notifier();
		$notifier->job_finished( $this->job( JobType::Check, JobState::Failed, self::SETUP + 10, 'repository_unreachable' ), self::SETUP + 10 );
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 20, '', [ 'errors' => 2 ] ), self::SETUP + 20 );
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 30, '', [ 'errors' => 0 ] ), self::SETUP + 30 );
		self::assertSame( [ 'failure', 'check_findings' ], $this->mailer->kinds(), 'The streak ended with the findings.' );
	}

	public function test_a_switch_to_external_retention_is_reported(): void {
		$notifier = $this->notifier();
		$notifier->job_finished(
			$this->job(
				JobType::Forget,
				JobState::Succeeded,
				self::SETUP + 10,
				'',
				[
					'skipped'            => 'append_only',
					'retention_switched' => true,
				]
			),
			self::SETUP + 10
		);
		$notifier->job_finished( $this->job( JobType::Prune, JobState::Succeeded, self::SETUP + 20, '', [ 'skipped' => 'append_only' ] ), self::SETUP + 20 );
		$notifier->retention_switched( self::SETUP + 30 );
		self::assertSame( [ 'retention_external', 'retention_external' ], $this->mailer->kinds() );
		self::assertSame( 'forget', $this->mailer->sent[0]->job_type );
		self::assertSame( '', $this->mailer->sent[1]->job_type, 'The heartbeat probe has no job.' );
	}

	public function test_disabled_notifications_send_nothing_and_arm_no_recovery(): void {
		$notifier = $this->notifier(
			[
				'notifications.email.on_failure' => false,
				'notifications.email.on_stale'   => false,
			]
		);
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		$notifier->job_finished( $this->job( JobType::Check, JobState::Succeeded, self::SETUP + 20, '', [ 'errors' => 5 ] ), self::SETUP + 20 );
		$notifier->retention_switched( self::SETUP + 30 );
		$notifier->heartbeat( self::SETUP + 1000 * self::HOUR );
		$this->backup_succeeded( $notifier, self::SETUP + 1001 * self::HOUR );
		self::assertSame( [], $this->mailer->kinds() );
	}

	public function test_unlock_and_cancelled_jobs_are_not_reported(): void {
		$notifier = $this->notifier();
		$notifier->job_finished( $this->job( JobType::Unlock, JobState::Failed, self::SETUP + 10, 'wrong_password' ), self::SETUP + 10 );
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Cancelled, self::SETUP + 20 ), self::SETUP + 20 );
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Running, self::SETUP + 30 ), self::SETUP + 30 );
		self::assertSame( [], $this->mailer->kinds() );
		self::assertSame( 0, $notifier->pending() );
		self::assertArrayNotHasKey( Notifier::OPTION, $this->options->values, 'Nothing was even written.' );
	}

	public function test_refused_notices_are_retried_five_times_with_growing_pauses(): void {
		$notifier             = $this->notifier( [ 'notifications.email.on_stale' => false ] );
		$this->mailer->accept = false;
		$failed               = self::SETUP + 10;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, $failed, 'internal' ), $failed );
		self::assertSame( 1, $this->mailer->refused, 'The first attempt is made at once.' );
		self::assertSame( 1, $notifier->pending() );

		// The heartbeat comes every five minutes, for two days.
		$attempts = [ 0 ];
		for ( $after = 300; $after <= 2 * 24 * self::HOUR; $after += 300 ) {
			$before = $this->mailer->refused;
			$notifier->heartbeat( $failed + $after );
			if ( $this->mailer->refused > $before ) {
				$attempts[] = $after;
			}
		}

		$quarter = 900;
		self::assertSame(
			[ 0, $quarter, $quarter + self::HOUR, $quarter + 7 * self::HOUR, $quarter + 31 * self::HOUR ],
			$attempts,
			'15 minutes, one hour, six hours and a day lie between the attempts.'
		);
		self::assertSame( 0, $notifier->pending(), 'Dropped after the fifth attempt.' );
		self::assertFalse( $this->options->autoload[ Notifier::OPTION ] );
	}

	public function test_jobs_that_finish_in_between_do_not_use_up_the_attempts(): void {
		$notifier             = $this->notifier( [ 'notifications.email.on_stale' => false ] );
		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		self::assertSame( 1, $this->mailer->refused );

		// Six more jobs and a heartbeat within the first quarter of an hour: each flushes the outbox.
		for ( $minute = 1; $minute <= 6; $minute++ ) {
			$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10 + 60 * $minute, 'internal' ), self::SETUP + 10 + 60 * $minute );
		}
		$notifier->heartbeat( self::SETUP + 10 + 899 );
		self::assertSame( 1, $this->mailer->refused, 'The notice waits for its pause to end.' );
		self::assertSame( 1, $notifier->pending() );

		$this->mailer->accept = true;
		$notifier->heartbeat( self::SETUP + 10 + 900 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds() );
		self::assertSame( 0, $notifier->pending() );
	}

	public function test_a_new_notice_is_tried_at_once_while_an_older_one_waits(): void {
		$notifier             = $this->notifier( [ 'notifications.email.on_stale' => false ] );
		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		$this->mailer->accept = true;

		$notifier->job_finished( $this->job( JobType::Check, JobState::Failed, self::SETUP + 100, 'internal' ), self::SETUP + 100 );

		self::assertSame( [ 'check' ], array_map( static fn ( Notice $notice ): string => $notice->job_type, $this->mailer->sent ) );
		self::assertSame( 1, $notifier->pending(), 'The backup notice waits for its second attempt.' );
		$notifier->heartbeat( self::SETUP + 10 + 900 );
		self::assertSame( [ 'check', 'backup' ], array_map( static fn ( Notice $notice ): string => $notice->job_type, $this->mailer->sent ) );
	}

	public function test_a_notice_stored_without_the_time_of_its_last_attempt_is_tried_again(): void {
		$notice                                    = new Notice( NoticeKind::Failure, self::SETUP + 10, 'backup', 'job', 'internal' );
		$this->options->values[ Notifier::OPTION ] = [
			'outbox' => [
				[ 'attempts' => 2 ] + $notice->to_array(),
				[ 'attempts' => 5 ] + $notice->to_array(),
			],
		];
		$this->notifier( [ 'notifications.email.on_stale' => false ] )->heartbeat( self::SETUP + 20 );
		self::assertEquals( [ $notice ], $this->mailer->sent, 'An entry from an earlier version is due; one that used up its attempts is dropped.' );
	}

	public function test_a_notice_waits_for_the_mail_server_and_old_ones_are_dropped(): void {
		$notifier             = $this->notifier( [ 'notifications.email.on_stale' => false ] );
		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		$this->mailer->accept = true;
		$notifier->heartbeat( self::SETUP + 310 );
		self::assertSame( [], $this->mailer->kinds(), 'Five minutes later the notice still waits.' );
		$notifier->heartbeat( self::SETUP + 910 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds(), 'Delivered once the mail server is back and the pause is over.' );

		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Check, JobState::Failed, self::SETUP + 400, 'internal' ), self::SETUP + 400 );
		$this->mailer->accept = true;
		$notifier->heartbeat( self::SETUP + 400 + 2 * 24 * self::HOUR + 1 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds(), 'Older than two days: dropped unsent.' );
		self::assertSame( 0, $notifier->pending() );
	}

	public function test_stored_notices_survive_a_round_trip(): void {
		$notice = new Notice( NoticeKind::Recovery, 5, 'prune', 'job', 'code', 4, 3 );
		self::assertEquals( $notice, Notice::from_array( $notice->to_array() ) );
		self::assertNull( Notice::from_array( [ 'kind' => 'gossip' ] ) );
	}

	/**
	 * A notifier as a request on a site has it: with the locks in the private directory.
	 */
	private function request( string $directory ): Notifier {
		$settings = [
			'setup.completed'              => true,
			'notifications.email.on_stale' => false,
		];
		return new Notifier( $this->options, new SiteState( $this->options ), Settings::from_stored( $settings ), $this->mailer, new FileLock( $directory . '/state.lock' ), new FileLock( $directory . '/notify.lock' ) );
	}

	public function test_a_notice_queued_while_a_mail_goes_out_is_not_lost_and_not_sent_twice(): void {
		$directory = TempDir::create( 'notify' );
		try {
			$notifier = $this->request( $directory );
			$failed   = self::SETUP + 10;
			// The mail server is slow; meanwhile the tick of another request ends a check that found damage.
			$this->mailer->meanwhile = function () use ( $directory, $failed ): void {
				$this->request( $directory )->job_finished( $this->job( JobType::Check, JobState::Succeeded, $failed + 5, '', [ 'errors' => 2 ] ), $failed + 5 );
			};

			$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, $failed, 'internal' ), $failed );

			self::assertSame( [ 'failure' ], $this->mailer->kinds(), 'The other request left the sending to the one that was at it.' );
			self::assertSame( 1, $notifier->pending(), 'The notice about the damage waits for the next heartbeat.' );
			$notifier->heartbeat( $failed + 300 );
			self::assertSame( [ 'failure', 'check_findings' ], $this->mailer->kinds() );
			self::assertSame( 0, $notifier->pending() );
		} finally {
			TempDir::remove( $directory );
		}
	}
}
