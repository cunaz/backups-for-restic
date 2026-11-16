<?php
/**
 * The retention policy that the settings describe.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Maintenance;

use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Settings\Schema;
use BackupsForRestic\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the policy in one place, so that the forget job and the preview in the snapshot
 * list (docs/02-architecture.md §13.2) cannot come to different decisions.
 */
final class RetentionSettings {

	/**
	 * The retention policy from the settings; keep-within is mandatory (ADR 0010), and the
	 * policy must not fall below the retention floor, whatever the stored settings say.
	 *
	 * @param Settings $settings Settings.
	 * @return RetentionPolicy Policy.
	 * @throws InvalidArgument If keep-within is missing or zero, or the policy is below the floor.
	 */
	public static function policy( Settings $settings ): RetentionPolicy {
		$within = Duration::parse( $settings->string( 'retention.keep.within' ) );
		if ( $within->is_zero() ) {
			InvalidArgument::raise( 'The retention policy needs a keep-within duration.', [ 'code' => 'configuration' ] );
		}
		if ( ! Schema::retention_floor( $settings->string( 'retention.keep.within' ), $settings->int( 'retention.keep.last' ) ) ) {
			InvalidArgument::raise( 'The retention policy must keep every snapshot of at least one day, or at least the newest one.', [ 'code' => 'configuration' ] );
		}
		return new RetentionPolicy(
			$settings->int( 'retention.keep.last' ),
			0,
			$settings->int( 'retention.keep.daily' ),
			$settings->int( 'retention.keep.weekly' ),
			$settings->int( 'retention.keep.monthly' ),
			$settings->int( 'retention.keep.yearly' ),
			$within
		);
	}

	/**
	 * The policy that the next forget of this site would apply, for a preview of what it
	 * removes. There is none while retention is external, because a forget job then ends
	 * without deleting, and none for settings that a forget job refuses before it deletes.
	 *
	 * @param Settings $settings Settings.
	 * @return RetentionPolicy|null Policy, or null if a forget of this site would remove nothing.
	 */
	public static function preview( Settings $settings ): ?RetentionPolicy {
		if ( 'plugin' !== $settings->string( 'retention.mode' ) ) {
			return null;
		}
		try {
			return self::policy( $settings );
		} catch ( PluginException ) {
			return null;
		}
	}

	/**
	 * Names everything that preview() depends on, so that a stored preview can say which
	 * settings it was computed with. A cached list is only as good as the policy behind it,
	 * and deleting the cache when a setting is saved misses a list that is still being read.
	 *
	 * @param Settings $settings Settings.
	 * @return string The rules of the previewed policy, or "none" if there is no preview.
	 */
	public static function fingerprint( Settings $settings ): string {
		if ( null === self::preview( $settings ) ) {
			return 'none';
		}
		$rules = [];
		foreach ( [ 'last', 'daily', 'weekly', 'monthly', 'yearly' ] as $rule ) {
			$rules[] = $rule . '=' . $settings->int( 'retention.keep.' . $rule );
		}
		$rules[] = 'within=' . $settings->string( 'retention.keep.within' );
		return implode( ',', $rules );
	}
}
