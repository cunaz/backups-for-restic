<?php
/**
 * Which repository the plugin works with.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Format\Config;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the settings `repository.id` and `repository.format_version` and the remembered
 * results (SiteState) in step with the repository that the jobs really open: the one the
 * plugin created, or an existing one after its key was derived.
 */
final class RepositoryRecord {

	/**
	 * Creates the record.
	 *
	 * @param SettingsStore $settings Settings.
	 * @param SiteState     $state    Remembered results.
	 * @param Clock         $clock    Time source.
	 */
	public function __construct(
		private readonly SettingsStore $settings,
		private readonly SiteState $state,
		private readonly Clock $clock
	) {
	}

	/**
	 * Records the repository that was just opened. Results remembered for another repository
	 * are dropped.
	 *
	 * @param Config $config Configuration of the opened repository.
	 * @return bool True if anything was written.
	 */
	public function opened( Config $config ): bool {
		$rebound = $this->state->bind( $config->id, $this->clock->now()[0] );
		return $this->write(
			[
				'repository.id'             => $config->id,
				'repository.format_version' => $config->version,
			]
		) || $rebound;
	}

	/**
	 * The settings now name another address: which repository is there, and in which format,
	 * shows when it is opened, and what was remembered belongs to the old one.
	 */
	public function moved(): void {
		$this->state->bind( '', $this->clock->now()[0] );
		$this->write(
			[
				'repository.id'             => '',
				// Not the old repository's format: the Doctor would hold it against the new address.
				'repository.format_version' => 1,
			]
		);
	}

	/**
	 * Saves the settings that differ.
	 *
	 * @param array<string, int|string> $values Values by dotted path.
	 * @return bool True if anything was saved.
	 */
	private function write( array $values ): bool {
		$current = $this->settings->load();
		$changes = [];
		foreach ( $values as $path => $value ) {
			if ( ( is_int( $value ) ? $current->int( $path ) : $current->string( $path ) ) !== $value ) {
				$changes[ $path ] = $value;
			}
		}
		if ( [] !== $changes ) {
			$this->settings->update( $changes );
		}
		return [] !== $changes;
	}
}
