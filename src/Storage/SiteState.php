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
	 * Entries that describe one repository: the last backup and the last forget, prune and
	 * check, the alert of a check that found damage, and how much the repository holds. They
	 * are dropped when the jobs turn to another repository.
	 */
	public const array OF_THE_REPOSITORY = [ 'last_backup', 'last_forget', 'last_prune', 'last_check', 'check_alert', 'usage' ];

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
	 * Records which repository the remembered results belong to. When that is another one
	 * than before, the results of the old one are dropped: a damaged repository's alert or the
	 * last backup's time must not pass for facts about the new one.
	 *
	 * State from before this record existed is taken to describe the repository that is
	 * opened first, so an update of the plugin drops nothing.
	 *
	 * @param string $repository Repository ID, or '' while the settings name a repository that was never opened.
	 * @param int    $now        Unix time.
	 * @return bool True if the state now describes another repository than before.
	 */
	public function bind( string $repository, int $now ): bool {
		$state = $this->all();
		$bound = is_array( $state['repository'] ?? null ) ? $state['repository'] : null;
		if ( null !== $bound && ( $bound['id'] ?? null ) === $repository ) {
			return false;
		}
		$adopted = null === $bound && '' !== $repository;
		if ( ! $adopted ) {
			foreach ( self::OF_THE_REPOSITORY as $key ) {
				unset( $state[ $key ] );
			}
		}
		$state['repository'] = [
			'id'    => $repository,
			// Since when: without a backup yet, the stale notice counts from here.
			'since' => $adopted ? 0 : $now,
		];
		$this->options->set( self::OPTION, $state, false );
		return ! $adopted;
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
