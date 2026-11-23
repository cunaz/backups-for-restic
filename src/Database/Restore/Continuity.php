<?php
/**
 * What a database exchange has to leave working on the site.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * The generation that an exchange brings live keeps its own roles and its own list of active
 * plugins (ADR 0043, decision 5). Two things must hold in it all the same, or nobody could
 * finish, report or undo the exchange: the approving administrator, whose account the exchange
 * keeps, has every capability that a database restore needs under that generation's roles, and
 * this plugin is in that generation's list of active plugins.
 */
final readonly class Continuity {

	/**
	 * Holds checked names.
	 *
	 * @param list<string> $capabilities Capabilities that the approving administrator keeps.
	 * @param string|null  $plugin This plugin's path in active_plugins, null if WordPress loads it otherwise.
	 */
	private function __construct( public array $capabilities, public ?string $plugin ) {
	}

	/**
	 * Checks the names before an exchange relies on them.
	 *
	 * @param mixed       $capabilities Capability names, from WordPress or from a job document.
	 * @param string|null $plugin This plugin's path in active_plugins, null if WordPress loads it otherwise.
	 * @return self Requirements.
	 * @throws InvalidArgument If no capability is named, or a name or the path is not one that the review reads.
	 */
	public static function of( mixed $capabilities, ?string $plugin ): self {
		if ( ! is_array( $capabilities ) || [] === $capabilities || ! array_is_list( $capabilities ) || ( null !== $plugin && ! PluginList::entry( $plugin ) ) ) {
			throw new InvalidArgument( 'A database exchange needs the capabilities of its administrator and a valid plugin path.' );
		}
		$names = [];
		foreach ( $capabilities as $name ) {
			if ( ! is_string( $name ) || ! RoleMap::name( $name ) ) {
				throw new InvalidArgument( 'A capability that a database exchange checks has an invalid name.' );
			}
			$names[ $name ] = $name;
		}
		return new self( array_values( $names ), $plugin );
	}
}
