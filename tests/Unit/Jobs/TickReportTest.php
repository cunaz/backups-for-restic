<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\TickReport;

/**
 * Only a tick that did work chains the next one; busy and waiting ticks would start a storm.
 */
final class TickReportTest extends TestCase {

	private static function job( JobState $state ): Job {
		$job        = new Job( '20261109T120000Z-abcdef', JobType::Backup, 'manual', 1794225600 );
		$job->state = $state;
		return $job;
	}

	public function test_an_advanced_unfinished_job_chains(): void {
		self::assertTrue( ( new TickReport( 'advanced', self::job( JobState::Running ) ) )->should_chain() );
	}

	public function test_busy_and_waiting_ticks_do_not_chain(): void {
		foreach ( [ 'busy', 'waiting' ] as $status ) {
			$report = new TickReport( $status, self::job( JobState::Running ) );
			self::assertTrue( $report->has_more_work(), $status );
			self::assertFalse( $report->should_chain(), $status );
		}
	}

	public function test_finished_jobs_and_idle_runners_do_not_chain(): void {
		self::assertFalse( ( new TickReport( 'advanced', self::job( JobState::Succeeded ) ) )->should_chain() );
		self::assertFalse( ( new TickReport( 'idle' ) )->should_chain() );
	}
}
