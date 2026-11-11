<?php
/**
 * Checks whether this host can run backups.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * The environment checks of the wizard, the dashboard and Site Health
 * (docs/02-architecture.md §13). Critical findings block backups; recommendations explain how
 * to make them faster or safer.
 */
final class Doctor {

	public const int MIN_MEMORY         = 100663296;
	public const int GOOD_MEMORY        = 134217728;
	public const int MIN_FREE_SPACE     = 134217728;
	public const int GOOD_FREE_SPACE    = 536870912;
	public const int SHORT_TIME_LIMIT   = 30;
	public const int ALL_SALT_CONSTANTS = 8;

	/**
	 * Runs all checks.
	 *
	 * @param Environment $environment Environment.
	 * @return list<Check> Results in display order.
	 */
	public static function check( Environment $environment ): array {
		return [
			self::extensions( $environment ),
			self::memory( $environment ),
			self::time_limit( $environment ),
			self::disk( $environment ),
			self::private_directory( $environment ),
			self::secrets( $environment ),
			self::cron( $environment ),
			self::zstd( $environment ),
			self::jit( $environment ),
			self::repository_server( $environment ),
		];
	}

	/**
	 * Whether any result is critical.
	 *
	 * @param list<Check> $checks Results.
	 * @return bool True if a backup cannot run.
	 */
	public static function blocked( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( Check::CRITICAL === $check->status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Required PHP extensions.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function extensions( Environment $environment ): Check {
		return [] === $environment->missing_extensions
			? new Check( 'extensions', Check::GOOD, 'present' )
			: new Check( 'extensions', Check::CRITICAL, 'missing', [ 'extensions' => implode( ', ', $environment->missing_extensions ) ] );
	}

	/**
	 * PHP memory limit.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function memory( Environment $environment ): Check {
		$limit = $environment->memory_limit;
		if ( $limit >= 0 && $limit < self::MIN_MEMORY ) {
			return new Check( 'memory_limit', Check::CRITICAL, 'too_low', [ 'bytes' => $limit ] );
		}
		if ( $limit >= 0 && $limit < self::GOOD_MEMORY ) {
			return new Check( 'memory_limit', Check::RECOMMENDED, 'low', [ 'bytes' => $limit ] );
		}
		return new Check( 'memory_limit', Check::GOOD, 'enough', [ 'bytes' => $limit ] );
	}

	/**
	 * PHP time limit.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function time_limit( Environment $environment ): Check {
		$seconds = $environment->max_execution_time;
		return $seconds > 0 && $seconds < self::SHORT_TIME_LIMIT
			? new Check( 'time_limit', Check::RECOMMENDED, 'short', [ 'seconds' => $seconds ] )
			: new Check( 'time_limit', Check::GOOD, 'enough', [ 'seconds' => $seconds ] );
	}

	/**
	 * Free disk space for caches and packs under construction.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function disk( Environment $environment ): Check {
		$free = $environment->free_space;
		if ( null === $free ) {
			return new Check( 'disk_space', Check::GOOD, 'unknown' );
		}
		if ( $free < self::MIN_FREE_SPACE ) {
			return new Check( 'disk_space', Check::CRITICAL, 'too_low', [ 'bytes' => $free ] );
		}
		return $free < self::GOOD_FREE_SPACE
			? new Check( 'disk_space', Check::RECOMMENDED, 'low', [ 'bytes' => $free ] )
			: new Check( 'disk_space', Check::GOOD, 'enough', [ 'bytes' => $free ] );
	}

	/**
	 * Protection of the private directory (ADR 0007).
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function private_directory( Environment $environment ): Check {
		if ( true === $environment->exposed ) {
			return new Check( 'private_directory', Check::CRITICAL, 'exposed' );
		}
		if ( ! $environment->inside_web_root ) {
			return new Check( 'private_directory', Check::GOOD, 'outside_web_root' );
		}
		return null === $environment->exposed
			? new Check( 'private_directory', Check::RECOMMENDED, 'unverified' )
			: new Check( 'private_directory', Check::RECOMMENDED, 'protected_inside_web_root' );
	}

	/**
	 * Stored secrets and the salt constants that protect them (ADR 0006).
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function secrets( Environment $environment ): Check {
		if ( ! $environment->secrets_readable ) {
			return new Check( 'secrets', Check::CRITICAL, 'undecryptable' );
		}
		return $environment->salt_constants < self::ALL_SALT_CONSTANTS
			? new Check( 'secrets', Check::RECOMMENDED, 'salts_missing', [ 'defined' => $environment->salt_constants ] )
			: new Check( 'secrets', Check::GOOD, 'protected' );
	}

	/**
	 * How ticks get triggered (ADR 0011).
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function cron( Environment $environment ): Check {
		if ( false === $environment->loopback ) {
			return new Check( 'cron', Check::RECOMMENDED, 'loopback_blocked' );
		}
		return $environment->cron_disabled
			? new Check( 'cron', Check::RECOMMENDED, 'wp_cron_disabled' )
			: new Check( 'cron', Check::GOOD, 'loopback_works' );
	}

	/**
	 * The zstd extension, needed only for format 2 repositories (ADR 0019).
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function zstd( Environment $environment ): Check {
		if ( $environment->repository_format >= 2 && ! $environment->zstd ) {
			return new Check( 'zstd', Check::CRITICAL, 'needed' );
		}
		return new Check( 'zstd', Check::GOOD, $environment->zstd ? 'present' : 'not_needed' );
	}

	/**
	 * PHP's JIT compiler. With the tracing JIT, processes of PHP 8.3 died by SIGSEGV in the
	 * middle of backup ticks (docs/04-development-and-quality.md §5.3): a job then stops
	 * without an error and goes on with the next tick, slower and for no visible reason.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function jit( Environment $environment ): Check {
		return $environment->jit
			? new Check( 'jit', Check::RECOMMENDED, 'enabled' )
			: new Check( 'jit', Check::GOOD, 'off' );
	}

	/**
	 * Whether the repository server answers from here. Hosts that block outgoing HTTPS to
	 * other ports or hosts are the usual reason when it does not.
	 *
	 * @param Environment $environment Environment.
	 * @return Check Result.
	 */
	private static function repository_server( Environment $environment ): Check {
		if ( null === $environment->repository_reached ) {
			return new Check( 'repository_server', Check::GOOD, 'not_tested' );
		}
		return $environment->repository_reached
			? new Check( 'repository_server', Check::GOOD, 'reached' )
			: new Check( 'repository_server', Check::RECOMMENDED, 'unreachable' );
	}
}
