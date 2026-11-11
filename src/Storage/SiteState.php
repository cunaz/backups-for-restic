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
	 * @param Options       $options Options.
	 * @param FileLock|null $lock    Held while the document is read, changed and written back.
	 */
	public function __construct( private readonly Options $options, private readonly ?FileLock $lock = null ) {
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
		$this->change(
			static function ( array $state ) use ( $key, $value ): array {
				$state[ $key ] = $value;
				return $state;
			}
		);
	}

	/**
	 * Changes one entry, starting from what is stored now: for an entry that more than one
	 * request adds to, such as a list.
	 *
	 * @param string                                               $key    Entry name.
	 * @param \Closure(array<string, mixed>): array<string, mixed> $change Gets the entry, empty if unknown, and returns what replaces it.
	 */
	public function update( string $key, \Closure $change ): void {
		$this->change(
			static function ( array $state ) use ( $key, $change ): array {
				$entry = [];
				foreach ( is_array( $state[ $key ] ?? null ) ? $state[ $key ] : [] as $field => $value ) {
					$entry[ (string) $field ] = $value;
				}
				$state[ $key ] = $change( $entry );
				return $state;
			}
		);
	}

	/**
	 * Records which repository the remembered results belong to. When that is another one
	 * than before, the results of the old one are dropped: a damaged repository's alert or the
	 * last backup's time must not pass for facts about the new one.
	 *
	 * State from before this record existed is taken to describe the repository that is
	 * opened first, so an update of the plugin drops nothing. Unless the settings knew the
	 * repository by another ID: then the first one opened replaced it at the same address, and
	 * the results are not its own.
	 *
	 * @param string $repository Repository ID, or '' while the settings name a repository that was never opened.
	 * @param int    $now        Unix time.
	 * @param string $known      Repository ID the settings held until now, or '' if none.
	 * @return bool True if the state now describes another repository than before.
	 */
	public function bind( string $repository, int $now, string $known = '' ): bool {
		$rebound = false;
		$this->change(
			static function ( array $state ) use ( $repository, $now, $known, &$rebound ): ?array {
				$bound = is_array( $state['repository'] ?? null ) ? $state['repository'] : null;
				if ( null !== $bound && ( $bound['id'] ?? null ) === $repository ) {
					return null;
				}
				$adopted = null === $bound && '' !== $repository && in_array( $known, [ '', $repository ], true );
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
				$rebound             = ! $adopted;
				return $state;
			}
		);
		return $rebound;
	}

	/**
	 * Changes the document. Every entry is in the one option, so a request that wrote back
	 * the document as it had read it earlier would undo what other requests stored since: a
	 * job's tick runs for many seconds. The change therefore starts from the database, and the
	 * lock keeps two changes from crossing.
	 *
	 * @param \Closure(array<mixed>): (array<mixed>|null) $change Gets the document and returns what to store; null to store nothing.
	 */
	private function change( \Closure $change ): void {
		$apply = function () use ( $change ): void {
			$stored = $this->options->fresh( self::OPTION );
			$next   = $change( is_array( $stored ) ? $stored : [] );
			if ( null !== $next ) {
				$this->options->set( self::OPTION, $next, false );
			}
		};
		if ( null === $this->lock ) {
			$apply();
		} else {
			$this->lock->hold( $apply );
		}
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
