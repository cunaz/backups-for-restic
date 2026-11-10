<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Jobs\Maintenance\RetentionGuard;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;

final class RetentionGuardTest extends TestCase {

	private SiteState $state;

	private FrozenClock $clock;

	/** @var list<string> */
	private array $saved = [];

	protected function setUp(): void {
		$this->state = new SiteState( new InMemoryOptions() );
		$this->clock = new FrozenClock();
	}

	private function guard( string $mode, string $repository = 'the repository' ): RetentionGuard {
		return new RetentionGuard(
			$this->state,
			$mode,
			function ( string $mode ): void {
				$this->saved[] = $mode;
			},
			$this->clock,
			$repository
		);
	}

	public function test_append_only_switches_to_external_once_and_is_recorded(): void {
		$guard = $this->guard( 'plugin' );
		self::assertTrue( $guard->detected( AppendOnlyStatus::AppendOnly, 'job-1' ) );
		self::assertFalse( $guard->detected( AppendOnlyStatus::AppendOnly, 'job-2' ) );
		self::assertFalse( $guard->refused( 'job-3' ) );
		self::assertTrue( $guard->is_external() );
		self::assertSame( [ 'external' ], $this->saved );
		self::assertSame(
			[
				'mode' => 'external',
				'at'   => $this->clock->seconds,
				'job'  => 'job-1',
			],
			$this->state->get( 'retention_switch' )
		);
		self::assertSame(
			[
				'status'     => 'append_only',
				'checked_at' => $this->clock->seconds,
				'conclusive' => 'append_only',
				'repository' => 'the repository',
			],
			$this->state->get( 'append_only' )
		);
	}

	public function test_an_inconclusive_probe_keeps_the_last_conclusive_answer_and_the_time_of_the_attempt(): void {
		$guard = $this->guard( 'plugin' );
		$guard->detected( AppendOnlyStatus::DeletionsAllowed );
		self::assertSame( 'no', $guard->append_only( 'yes' ), 'A probe with an answer outranks what the wizard found.' );

		$this->clock->advance( 86400 );
		$guard->detected( AppendOnlyStatus::Unknown );

		self::assertSame( 'no', $guard->append_only( 'yes' ), 'The server was unreachable for a moment; what is known about it did not change.' );
		self::assertSame(
			[
				'status'     => 'unknown',
				'checked_at' => $this->clock->seconds,
				'conclusive' => 'deletions_allowed',
				'repository' => 'the repository',
			],
			$this->state->get( 'append_only' ),
			'The heartbeat probes again an hour after an inconclusive answer, counted from this attempt.'
		);

		$this->clock->advance( 3600 );
		$guard->detected( AppendOnlyStatus::Unknown );
		self::assertSame( 'no', $guard->append_only( 'yes' ), 'Nor does it change after several inconclusive probes.' );

		$this->clock->advance( 3600 );
		$guard->detected( AppendOnlyStatus::AppendOnly );
		self::assertSame( 'yes', $guard->append_only( 'no' ) );
		self::assertSame( 'append_only', $this->state->get( 'append_only' )['conclusive'] ?? null );
	}

	public function test_without_a_conclusive_probe_the_wizards_answer_stands(): void {
		$guard = $this->guard( 'plugin' );
		self::assertSame( 'yes', $guard->append_only( 'yes' ), 'No probe yet.' );
		self::assertSame( 'unknown', $guard->append_only( 'unknown' ) );

		$guard->detected( AppendOnlyStatus::Unknown );
		self::assertSame( 'no', $guard->append_only( 'no' ) );
		self::assertSame( 'unknown', $this->state->get( 'append_only' )['conclusive'] ?? null );
	}

	public function test_what_a_probe_found_about_another_repository_does_not_count(): void {
		$this->guard( 'plugin', 'the old server' )->detected( AppendOnlyStatus::AppendOnly );
		self::assertSame( 'yes', $this->guard( 'external', 'the old server' )->append_only( 'no' ) );

		// An administrator saved another server; the wizard found that it allows deletions.
		$guard = $this->guard( 'external', 'the new server' );
		self::assertSame( 'no', $guard->append_only( 'no' ), 'The old server\'s answer says nothing about this one.' );
		self::assertTrue( $guard->probe_due(), 'Nor is its probe a reason to wait a day.' );

		$guard->detected( AppendOnlyStatus::Unknown );
		self::assertSame( 'no', $guard->append_only( 'no' ), 'An inconclusive probe does not bring the old answer back.' );
		self::assertSame( 'unknown', $this->state->get( 'append_only' )['conclusive'] ?? null );

		$guard->detected( AppendOnlyStatus::DeletionsAllowed );
		self::assertSame( 'no', $guard->append_only( 'unknown' ) );
		self::assertSame( 'unknown', $this->guard( 'external', 'the old server' )->append_only( 'unknown' ), 'And the other way round.' );
	}

	public function test_a_probe_result_without_its_repository_is_not_attributed_to_any(): void {
		$this->state->set(
			'append_only',
			[
				'status'     => 'append_only',
				'checked_at' => $this->clock->seconds,
				'conclusive' => 'append_only',
			]
		);
		$guard = $this->guard( 'external' );
		self::assertSame( 'unknown', $guard->append_only( 'unknown' ) );
		self::assertTrue( $guard->probe_due() );
	}

	public function test_the_heartbeat_probes_daily_and_an_hour_after_a_probe_without_an_answer(): void {
		$guard = $this->guard( 'plugin' );
		self::assertTrue( $guard->probe_due(), 'Never probed.' );

		$guard->detected( AppendOnlyStatus::DeletionsAllowed );
		self::assertFalse( $guard->probe_due() );
		$this->clock->advance( 86399 );
		self::assertFalse( $guard->probe_due() );
		$this->clock->advance( 1 );
		self::assertTrue( $guard->probe_due(), 'A day after a probe with an answer.' );

		$guard->detected( AppendOnlyStatus::Unknown );
		$this->clock->advance( 3599 );
		self::assertFalse( $guard->probe_due() );
		$this->clock->advance( 1 );
		self::assertTrue( $guard->probe_due(), 'An hour after a probe without one.' );
	}

	public function test_the_repository_of_a_probe_is_its_address_and_user_name(): void {
		$site = static fn ( string $url, string $username ): string => RetentionGuard::repository(
			Settings::from_stored(
				[
					'repository.url'           => $url,
					'repository.rest_username' => $username,
				]
			)
		);
		$name = $site( 'https://backup.example.net:8000/site/', 'backup' );
		self::assertSame( $name, $site( 'https://backup.example.net:8000/site/', 'backup' ) );
		self::assertNotSame( $name, $site( 'https://backup.example.net:8000/another-site/', 'backup' ) );
		self::assertNotSame( $name, $site( 'https://backup.example.net:8000/site/', 'someone-else' ) );
		self::assertStringNotContainsString( 'backup', $name, 'Neither the address nor the user name is repeated in the state.' );
	}

	public function test_a_refused_deletion_switches_like_a_detection(): void {
		$guard = $this->guard( 'plugin' );
		self::assertTrue( $guard->refused( 'job-1' ) );
		self::assertSame( [ 'external' ], $this->saved );
		self::assertSame( [], $this->state->get( 'append_only' ), 'A refusal is not a probe.' );
	}

	public function test_deletions_allowed_again_only_propose_the_switch_back(): void {
		$guard = $this->guard( 'external' );
		$since = $this->clock->seconds;
		self::assertFalse( $guard->detected( AppendOnlyStatus::DeletionsAllowed ) );
		$this->clock->advance( 86400 );
		self::assertFalse( $guard->detected( AppendOnlyStatus::DeletionsAllowed ) );
		self::assertSame( [], $this->saved, 'Nothing switches back without an administrator.' );
		self::assertTrue( $guard->is_external() );
		self::assertSame(
			[
				'mode'  => 'plugin',
				'since' => $since,
			],
			$this->state->get( 'retention_proposal' ),
			'The proposal keeps the time of the first detection.'
		);

		self::assertFalse( $guard->detected( AppendOnlyStatus::AppendOnly ) );
		self::assertSame( [], $this->state->get( 'retention_proposal' ), 'Append-only again withdraws the proposal.' );
	}

	public function test_inconclusive_or_ordinary_answers_change_nothing(): void {
		$this->guard( 'external' )->detected( AppendOnlyStatus::Unknown );
		$this->guard( 'plugin' )->detected( AppendOnlyStatus::DeletionsAllowed );
		self::assertSame( [], $this->saved );
		self::assertSame( [], $this->state->get( 'retention_proposal' ) );
		self::assertSame( 'deletions_allowed', $this->state->get( 'append_only' )['status'] ?? null );
	}
}
