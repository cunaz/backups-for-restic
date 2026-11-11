<?php
/**
 * What the Doctor looks at.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot of the hosting environment, collected by the WordPress layer so that the checks
 * themselves are plain functions.
 */
final readonly class Environment {

	/**
	 * Describes the environment.
	 *
	 * @param list<string> $missing_extensions Required PHP extensions that are missing.
	 * @param bool         $zstd               Whether ext-zstd is loaded.
	 * @param int          $memory_limit       memory_limit in bytes, -1 for unlimited.
	 * @param int          $max_execution_time Seconds, 0 for unlimited.
	 * @param int|null     $free_space         Free disk space of the private directory, if known.
	 * @param bool|null    $exposed            Whether the private directory answered over HTTP; null if not checkable.
	 * @param bool         $inside_web_root    Whether the private directory lies inside ABSPATH.
	 * @param int          $salt_constants     Number of the eight salt constants defined in wp-config.php.
	 * @param bool         $cron_disabled      Whether DISABLE_WP_CRON is set.
	 * @param bool|null    $loopback           Whether a loopback request worked; null if not tested.
	 * @param bool         $secrets_readable   Whether the two passwords can be read (RepositoryAccess::passwords_readable()).
	 * @param int          $repository_format  Format version of the connected repository, 0 if none.
	 * @param bool         $jit                Whether PHP's JIT compiler is switched on.
	 * @param bool|null    $repository_reached Whether the repository server answered a request; null if none is configured or none was sent yet.
	 */
	public function __construct(
		public array $missing_extensions,
		public bool $zstd,
		public int $memory_limit,
		public int $max_execution_time,
		public ?int $free_space,
		public ?bool $exposed,
		public bool $inside_web_root,
		public int $salt_constants,
		public bool $cron_disabled,
		public ?bool $loopback,
		public bool $secrets_readable,
		public int $repository_format,
		public bool $jit = false,
		public ?bool $repository_reached = null
	) {
	}
}
