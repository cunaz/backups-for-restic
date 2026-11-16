<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Jobs\Maintenance\RetentionSettings;
use BackupsForRestic\Settings\Settings;

final class RetentionSettingsTest extends TestCase {

	public function test_the_policy_holds_the_rules_of_the_settings(): void {
		$policy = RetentionSettings::policy(
			Settings::from_stored(
				[
					'retention.keep.last'    => 3,
					'retention.keep.daily'   => 5,
					'retention.keep.weekly'  => 6,
					'retention.keep.monthly' => 7,
					'retention.keep.yearly'  => 8,
					'retention.keep.within'  => '1y2m3d4h',
				]
			)
		);

		self::assertEquals( new RetentionPolicy( 3, 0, 5, 6, 7, 8, Duration::parse( '1y2m3d4h' ) ), $policy );
	}

	public function test_the_policy_of_the_defaults_keeps_two_days_and_is_not_empty(): void {
		$policy = RetentionSettings::policy( Settings::from_stored( [] ) );

		self::assertEquals( new RetentionPolicy( 2, 0, 7, 4, 6, 0, Duration::parse( '2d' ) ), $policy );
		self::assertFalse( $policy->is_empty() );
	}

	public function test_a_stored_keep_within_of_nothing_never_reaches_the_policy(): void {
		// The settings replace a duration of zero by the default, so the policy always has a window.
		$policy = RetentionSettings::policy( Settings::from_stored( [ 'retention.keep.within' => '0d' ] ) );

		self::assertEquals( Duration::parse( '2d' ), $policy->within );
	}

	public function test_a_policy_below_the_retention_floor_is_refused(): void {
		$settings = Settings::from_stored(
			[
				'retention.keep.within' => '12h',
				'retention.keep.last'   => 0,
			]
		);

		try {
			RetentionSettings::policy( $settings );
			self::fail( 'The policy must be refused.' );
		} catch ( InvalidArgument $refusal ) {
			self::assertSame( 'configuration', $refusal->context()['code'] ?? null );
		}
		self::assertNull( RetentionSettings::preview( $settings ), 'A forget with these settings fails before it removes anything.' );
	}

	public function test_the_preview_uses_the_policy_of_the_forget_job(): void {
		$settings = Settings::from_stored( [ 'retention.keep.daily' => 9 ] );

		self::assertEquals( RetentionSettings::policy( $settings ), RetentionSettings::preview( $settings ) );
	}

	public function test_there_is_no_preview_while_another_computer_removes_old_snapshots(): void {
		self::assertNull( RetentionSettings::preview( Settings::from_stored( [ 'retention.mode' => 'external' ] ) ) );
	}
}
