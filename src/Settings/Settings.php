<?php
/**
 * The current settings.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Settings;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\ValidationFailed;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable, validated set of all settings, flat by dotted path. Values that are missing
 * or invalid in storage fall back to their defaults, so reading settings never fails.
 */
final readonly class Settings {

	/**
	 * Creates the settings; use SettingsStore or with().
	 *
	 * @param array<string, mixed> $values     Canonical values by dotted path, complete.
	 * @param list<string>         $overridden Paths set by wp-config.php constants.
	 */
	private function __construct( private array $values, public array $overridden ) {
	}

	/**
	 * Builds settings from stored values, falling back to defaults field by field.
	 *
	 * @param array<string, mixed>  $stored    Stored values by dotted path.
	 * @param array<string, string> $constants Defined override constants by constant name.
	 * @return self Settings.
	 */
	public static function from_stored( array $stored, array $constants = [] ): self {
		$values = [];
		foreach ( Schema::fields() as $path => $field ) {
			try {
				$values[ $path ] = array_key_exists( $path, $stored ) ? $field->normalize( $stored[ $path ] ) : $field->fallback;
			} catch ( InvalidArgument ) {
				$values[ $path ] = $field->fallback;
			}
		}
		$overridden = [];
		foreach ( Schema::CONSTANTS as $path => $constant ) {
			if ( isset( $constants[ $constant ] ) && '' !== $constants[ $constant ] ) {
				$values[ $path ] = $constants[ $constant ];
				$overridden[]    = $path;
			}
		}
		return new self( $values, $overridden );
	}

	/**
	 * Returns new settings with validated changes. Overridden fields cannot change.
	 *
	 * @param array<string, mixed> $changes New values by dotted path.
	 * @return self Settings.
	 * @throws ValidationFailed With one error code per invalid path.
	 */
	public function with( array $changes ): self {
		$fields = Schema::fields();
		$values = $this->values;
		$errors = [];
		foreach ( $changes as $path => $value ) {
			if ( ! isset( $fields[ $path ] ) ) {
				$errors[ $path ] = [ 'code' => 'unknown_setting' ];
				continue;
			}
			if ( in_array( $path, $this->overridden, true ) ) {
				$errors[ $path ] = [ 'code' => 'defined_in_wp_config' ];
				continue;
			}
			try {
				$values[ $path ] = $fields[ $path ]->normalize( $value );
			} catch ( InvalidArgument $e ) {
				$errors[ $path ] = $e->context();
			}
		}
		if ( [] !== $errors ) {
			ValidationFailed::raise_for( $errors );
		}
		return new self( $values, $this->overridden );
	}

	/**
	 * All values, for storage and the REST API.
	 *
	 * @return array<string, mixed> Values by dotted path.
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * Values to store: everything except fields that constants override.
	 *
	 * @return array<string, mixed> Values by dotted path.
	 */
	public function to_stored(): array {
		return array_diff_key( $this->values, array_flip( $this->overridden ) );
	}

	/**
	 * Reads a text or choice setting.
	 *
	 * @param string $path Dotted path.
	 * @return string Value.
	 */
	public function string( string $path ): string {
		$value = $this->values[ $path ] ?? null;
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Reads an integer setting.
	 *
	 * @param string $path Dotted path.
	 * @return int Value.
	 */
	public function int( string $path ): int {
		$value = $this->values[ $path ] ?? null;
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Reads a boolean setting.
	 *
	 * @param string $path Dotted path.
	 * @return bool Value.
	 */
	public function bool( string $path ): bool {
		return true === ( $this->values[ $path ] ?? null );
	}

	/**
	 * Reads a list setting.
	 *
	 * @param string $path Dotted path.
	 * @return list<string> Value.
	 */
	public function list( string $path ): array {
		$value = $this->values[ $path ] ?? null;
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * Whether a wp-config.php constant sets the field.
	 *
	 * @param string $path Dotted path.
	 * @return bool True if overridden.
	 */
	public function is_overridden( string $path ): bool {
		return in_array( $path, $this->overridden, true );
	}
}
