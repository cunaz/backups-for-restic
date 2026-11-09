<?php
/**
 * One field of the settings schema.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Settings;

use BackupsForRestic\Exception\InvalidArgument;

defined( 'ABSPATH' ) || exit;

/**
 * Type, default and constraints of a setting. normalize() turns input from a form, the REST
 * API or a stored option into the canonical value, or rejects it with an error code that the
 * WordPress layer translates.
 */
final readonly class Field {

	/**
	 * Describes a field; use the named constructors.
	 *
	 * @param string       $kind     choice, integer, boolean, text, duration, list or emails.
	 * @param mixed        $fallback Default value.
	 * @param list<string> $choices  Allowed values of a choice.
	 * @param int          $min      Minimum of an integer.
	 * @param int          $max      Maximum of an integer, or of the number of list entries.
	 * @param int          $length   Maximum length of a text or of each list entry.
	 */
	private function __construct(
		public string $kind,
		public mixed $fallback,
		public array $choices = [],
		public int $min = 0,
		public int $max = 0,
		public int $length = 0
	) {
	}

	/**
	 * One of a fixed set of strings.
	 *
	 * @param string       $fallback Default.
	 * @param list<string> $choices  Allowed values.
	 * @return self Field.
	 */
	public static function choice( string $fallback, array $choices ): self {
		return new self( 'choice', $fallback, $choices );
	}

	/**
	 * An integer within bounds.
	 *
	 * @param int $fallback Default.
	 * @param int $min      Minimum.
	 * @param int $max      Maximum.
	 * @return self Field.
	 */
	public static function integer( int $fallback, int $min, int $max ): self {
		return new self( 'integer', $fallback, [], $min, $max );
	}

	/**
	 * A boolean.
	 *
	 * @param bool $fallback Default.
	 * @return self Field.
	 */
	public static function boolean( bool $fallback ): self {
		return new self( 'boolean', $fallback );
	}

	/**
	 * A single line of text.
	 *
	 * @param string $fallback Default.
	 * @param int    $length   Maximum length in bytes.
	 * @return self Field.
	 */
	public static function text( string $fallback = '', int $length = 2048 ): self {
		return new self( 'text', $fallback, [], 0, 0, $length );
	}

	/**
	 * A restic duration such as 2d or 1y6m, which must not be zero: restic's keep-within
	 * grammar of years, months, days and hours.
	 *
	 * @param string $fallback Default.
	 * @return self Field.
	 */
	public static function duration( string $fallback ): self {
		return new self( 'duration', $fallback, [], 0, 0, 32 );
	}

	/**
	 * A list of single-line strings, without duplicates or empty entries.
	 *
	 * @param int $length Maximum length of each entry in bytes.
	 * @return self Field.
	 */
	public static function list( int $length = 4096 ): self {
		return new self( 'list', [], [], 0, 500, $length );
	}

	/**
	 * A list of e-mail addresses.
	 *
	 * @return self Field.
	 */
	public static function emails(): self {
		return new self( 'emails', [], [], 0, 20, 254 );
	}

	/**
	 * Validates and normalizes a value.
	 *
	 * @param mixed $value Input.
	 * @return mixed Canonical value.
	 * @throws InvalidArgument With the error code in the context under "code".
	 */
	public function normalize( mixed $value ): mixed {
		return match ( $this->kind ) {
			'choice'   => $this->choice_value( $value ),
			'integer'  => $this->integer_value( $value ),
			'boolean'  => $this->boolean_value( $value ),
			'text'     => $this->line( $value ),
			'duration' => $this->duration_value( $value ),
			'emails'   => $this->emails_value( $value ),
			default    => $this->list_value( $value ),
		};
	}

	/**
	 * Validates a duration.
	 *
	 * @param mixed $value Input.
	 * @return string Duration as entered, without surrounding blanks.
	 * @throws InvalidArgument If the value is not a duration or is zero.
	 */
	private function duration_value( mixed $value ): string {
		$value = trim( $this->line( $value ) );
		preg_match_all( '/[ymdh]/', $value, $units );
		// Each unit at most once: restic's parser keeps only the last value of a repeated unit,
		// so "5d0d" would mean zero days.
		if ( 1 !== preg_match( '/\A(?:\d{1,6}[ymdh])+\z/', $value ) || 1 !== preg_match( '/[1-9]/', $value ) || count( $units[0] ) !== count( array_unique( $units[0] ) ) ) {
			InvalidArgument::raise( 'Value is not a duration such as 2d.', [ 'code' => 'invalid_duration' ] );
		}
		return $value;
	}

	/**
	 * Validates a list of e-mail addresses.
	 *
	 * @param mixed $value Input.
	 * @return list<string> Addresses.
	 * @throws InvalidArgument If an entry is not an e-mail address.
	 */
	private function emails_value( mixed $value ): array {
		$addresses = $this->list_value( $value );
		foreach ( $addresses as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_EMAIL ) ) {
				InvalidArgument::raise( 'Value is not an e-mail address.', [ 'code' => 'invalid_email' ] );
			}
		}
		return $addresses;
	}

	/**
	 * Validates a choice.
	 *
	 * @param mixed $value Input.
	 * @return string Choice.
	 * @throws InvalidArgument If the value is not allowed.
	 */
	private function choice_value( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $this->choices, true ) ) {
			InvalidArgument::raise( 'Value is not one of the allowed choices.', [ 'code' => 'not_a_choice' ] );
		}
		return $value;
	}

	/**
	 * Validates an integer; digit strings from forms are accepted.
	 *
	 * @param mixed $value Input.
	 * @return int Integer.
	 * @throws InvalidArgument If the value is not a number within the bounds.
	 */
	private function integer_value( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/\A-?\d{1,9}\z/', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < $this->min || $value > $this->max ) {
			InvalidArgument::raise(
				'Value is not a number within the allowed range.',
				[
					'code' => 'out_of_range',
					'min'  => $this->min,
					'max'  => $this->max,
				]
			);
		}
		return $value;
	}

	/**
	 * Validates a boolean; the usual form values are accepted.
	 *
	 * @param mixed $value Input.
	 * @return bool Boolean.
	 * @throws InvalidArgument If the value is not recognisable as a boolean.
	 */
	private function boolean_value( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( in_array( $value, [ 1, '1', 'true', 'yes', 'on' ], true ) ) {
			return true;
		}
		if ( ! in_array( $value, [ 0, '0', 'false', 'no', 'off', '' ], true ) ) {
			InvalidArgument::raise( 'Value is not a boolean.', [ 'code' => 'not_a_boolean' ] );
		}
		return false;
	}

	/**
	 * Validates a list; a string is split into lines, as a textarea sends it.
	 *
	 * @param mixed $value Input.
	 * @return list<string> Entries without duplicates or empty lines.
	 * @throws InvalidArgument If the value is not a list of valid lines.
	 */
	private function list_value( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = (array) preg_split( '/\R/', $value );
		}
		if ( ! is_array( $value ) ) {
			InvalidArgument::raise( 'Value is not a list.', [ 'code' => 'not_a_list' ] );
		}
		$items = [];
		foreach ( $value as $item ) {
			$item = trim( $this->line( $item ) );
			if ( '' !== $item && ! in_array( $item, $items, true ) ) {
				$items[] = $item;
			}
		}
		if ( count( $items ) > $this->max ) {
			InvalidArgument::raise( 'The list is too long.', [ 'code' => 'too_long' ] );
		}
		return $items;
	}

	/**
	 * Validates one line of text.
	 *
	 * @param mixed $value Input.
	 * @return string Text.
	 * @throws InvalidArgument If the value is not a valid single line.
	 */
	private function line( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			InvalidArgument::raise( 'Value is not text.', [ 'code' => 'not_text' ] );
		}
		if ( strlen( $value ) > $this->length ) {
			InvalidArgument::raise( 'The text is too long.', [ 'code' => 'too_long' ] );
		}
		if ( ! mb_check_encoding( $value, 'UTF-8' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			InvalidArgument::raise( 'The text contains invalid characters.', [ 'code' => 'invalid_characters' ] );
		}
		return $value;
	}
}
