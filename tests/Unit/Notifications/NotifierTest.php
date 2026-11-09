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
use BackupsForRestic\Storage\SiteState;
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
	}

	public function test_refused_notices_are_retried_five_times(): void {
		$notifier             = $this->notifier();
		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		self::assertSame( 1, $notifier->pending() );
		for ( $attempt = 2; $attempt <= 6; $attempt++ ) {
			$notifier->heartbeat( self::SETUP + 10 + $attempt * 300 );
		}
		self::assertSame( 5, $this->mailer->refused );
		self::assertSame( 0, $notifier->pending(), 'Dropped after five attempts.' );
		self::assertFalse( $this->options->autoload[ Notifier::OPTION ] );
	}

	public function test_a_notice_waits_for_the_mail_server_and_old_ones_are_dropped(): void {
		$notifier             = $this->notifier( [ 'notifications.email.on_stale' => false ] );
		$this->mailer->accept = false;
		$notifier->job_finished( $this->job( JobType::Backup, JobState::Failed, self::SETUP + 10, 'internal' ), self::SETUP + 10 );
		$this->mailer->accept = true;
		$notifier->heartbeat( self::SETUP + 310 );
		self::assertSame( [ 'failure' ], $this->mailer->kinds(), 'Delivered at the next heartbeat.' );

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
}
