<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Jobs\Maintenance\RetentionGuard;
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

	private function guard( string $mode ): RetentionGuard {
		return new RetentionGuard(
			$this->state,
			$mode,
			function ( string $mode ): void {
				$this->saved[] = $mode;
			},
			$this->clock
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
			],
			$this->state->get( 'append_only' )
		);
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
