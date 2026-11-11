<?php
/**
 * Loads and saves the settings.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Settings;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Storage\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the settings in one small, autoloaded option (docs/02-architecture.md §10).
 */
final class SettingsStore {

	public const string OPTION = 'backups_for_restic_settings';

	/**
	 * Creates the store.
	 *
	 * @param Options               $options   Options table.
	 * @param array<string, string> $constants Defined override constants by constant name.
	 */
	public function __construct( private readonly Options $options, private readonly array $constants = [] ) {
	}

	/**
	 * Loads the settings.
	 *
	 * @return Settings Settings, with defaults for anything missing or invalid.
	 */
	public function load(): Settings {
		$stored = $this->options->get( self::OPTION );
		$values = [];
		if ( is_array( $stored ) ) {
			foreach ( $stored as $path => $value ) {
				if ( is_string( $path ) ) {
					$values[ $path ] = $value;
				}
			}
		}
		return Settings::from_stored( $values, $this->constants );
	}

	/**
	 * Applies validated changes and saves them.
	 *
	 * @param array<string, mixed> $changes New values by dotted path.
	 * @return Settings The saved settings.
	 * @throws ValidationFailed If a value is invalid; nothing is saved then.
	 * @throws StorageFailed If the options table did not take the settings: who saves them goes on only with what is stored.
	 */
	public function update( array $changes ): Settings {
		$settings = $this->load()->with( $changes );
		if ( ! $this->options->set( self::OPTION, $settings->to_stored(), true ) ) {
			StorageFailed::raise( 'The settings could not be saved.', [ 'option' => self::OPTION ] );
		}
		return $settings;
	}
}
