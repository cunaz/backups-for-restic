<?php
/**
 * Small facts the plugin remembers between requests.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The backups_for_restic_state option (ADR 0014): last backup, last exposure probe and similar
 * results for the dashboard and Site Health. Not autoloaded; never holds secrets.
 */
final class SiteState {

	public const string OPTION = 'backups_for_restic_state';

	/**
	 * Uses the options table.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private readonly Options $options ) {
	}

	/**
	 * Reads one entry.
	 *
	 * @param string $key Entry name.
	 * @return array<string, mixed> Entry, empty if unknown.
	 */
	public function get( string $key ): array {
		$state = $this->all();
		$entry = $state[ $key ] ?? null;
		if ( ! is_array( $entry ) ) {
			return [];
		}
		$result = [];
		foreach ( $entry as $field => $value ) {
			$result[ (string) $field ] = $value;
		}
		return $result;
	}

	/**
	 * Replaces one entry.
	 *
	 * @param string               $key   Entry name.
	 * @param array<string, mixed> $value Entry.
	 */
	public function set( string $key, array $value ): void {
		$state         = $this->all();
		$state[ $key ] = $value;
		$this->options->set( self::OPTION, $state, false );
	}

	/**
	 * The whole document.
	 *
	 * @return array<mixed> State.
	 */
	private function all(): array {
		$state = $this->options->get( self::OPTION );
		return is_array( $state ) ? $state : [];
	}
}
