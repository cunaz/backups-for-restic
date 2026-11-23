<?php
/**
 * What a database review and exchange need to know about the WordPress installation.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Database\Restore\Continuity;
use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * What the database cannot tell: this plugin's path in the list of active plugins, the
 * capabilities that a database restore needs of its administrator, and whether WordPress finds
 * the file of a plugin path. WordPress supplies all three (Container::database_changes()).
 */
final readonly class ExchangeSite {

	/**
	 * Holds the answers of this installation.
	 *
	 * @param string|null             $plugin This plugin's path in active_plugins, or null if WordPress loads it otherwise.
	 * @param \Closure():list<string> $capabilities The configured backup capability and the four that a database restore adds.
	 * @param \Closure(string):bool   $installed Whether WordPress would load a path of active_plugins: valid, a PHP file, present.
	 */
	public function __construct( public ?string $plugin, public \Closure $capabilities, public \Closure $installed ) {
	}

	/**
	 * The capabilities and the path, checked.
	 *
	 * @return Continuity What an exchange has to leave working.
	 * @throws InvalidArgument If a capability name or the path is not one that the review reads.
	 */
	public function continuity(): Continuity {
		return Continuity::of( ( $this->capabilities )(), $this->plugin );
	}
}
