<?php
/**
 * Input mutations for the fuzzer.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Fuzz;

use Random\Randomizer;

/**
 * Turns a valid input into a slightly broken one. Byte mutations find framing and length
 * bugs; structure-aware mutations keep JSON well-formed and change types, values and keys,
 * which reaches the shape validation behind json_decode().
 */
final class Mutator {

	/** Upper bound for a mutated input. */
	public const int MAX_LENGTH = 1048576;

	private const array BYTES = [ "\x00", "\x01", "\x7f", "\x80", "\xff", '"', '\\', '{', '}', '[', ']', ',', ':', '0', '9', '-', '.', 'e', ' ', "\n" ];

	private const array TOKENS = [
		'null',
		'true',
		'false',
		'0',
		'-0',
		'-1',
		'1e400',
		'1.5',
		'9223372036854775807',
		'9223372036854775808',
		'-9223372036854775809',
		'4294967296',
		'""',
		'"\u0000"',
		'"\ud800"',
		'"\udc00\ud800"',
		'[]',
		'{}',
		'[[[[[[[[[[[[[[[[]]]]]]]]]]]]]]]]',
		"\"\xc3\x28\"",
		"\xef\xbb\xbf",
		'"\x00"',
		'"\\',
		'\u',
		'%00',
	];

	/**
	 * Replacement values for structure-aware mutations.
	 *
	 * @return list<mixed> Values.
	 */
	private static function values(): array {
		return [
			null,
			true,
			false,
			0,
			-1,
			1,
			255,
			256,
			65535,
			4294967295,
			4294967296,
			PHP_INT_MAX,
			PHP_INT_MIN,
			1.5,
			-0.0,
			1e300,
			'',
			'x',
			'0',
			'-1',
			'..',
			'/',
			'a/b',
			'\\',
			'\\x',
			"\u{0}",
			"\u{FFFD}",
			str_repeat( 'f', 64 ),
			str_repeat( 'g', 64 ),
			str_repeat( '0', 63 ),
			'2026-13-45T25:61:61Z',
			'0001-01-01T00:00:00Z',
			'9999-12-31T23:59:59.999999999+14:00',
			[],
			[ [] ],
			[ 'a' => 1 ],
			[ null ],
			str_repeat( 'a', 70000 ),
		];
	}

	/**
	 * Creates the mutator.
	 *
	 * @param Randomizer $random Seeded source of randomness, so runs can be repeated.
	 */
	public function __construct( private readonly Randomizer $random ) {
	}

	/**
	 * Applies one to four mutations.
	 *
	 * @param string       $input  Input to start from.
	 * @param bool         $json   Whether structure-aware mutations apply.
	 * @param list<string> $corpus Other inputs for splicing.
	 * @return string Mutated input.
	 */
	public function mutate( string $input, bool $json, array $corpus ): string {
		$rounds = $this->random->getInt( 1, 4 );
		for ( $i = 0; $i < $rounds; $i++ ) {
			$structured = $json && 0 === $this->random->getInt( 0, 1 ) ? $this->mutate_json( $input ) : null;
			$input      = $structured ?? $this->mutate_bytes( $input, $corpus );
		}
		return substr( $input, 0, self::MAX_LENGTH );
	}

	/**
	 * One byte-level mutation.
	 *
	 * @param string       $input  Input.
	 * @param list<string> $corpus Other inputs for splicing.
	 * @return string Mutated input.
	 */
	private function mutate_bytes( string $input, array $corpus ): string {
		$length = strlen( $input );
		$at     = $this->random->getInt( 0, $length );
		$span   = $this->random->getInt( 1, max( 1, min( 64, $length - $at ) ) );
		switch ( $this->random->getInt( 0, 8 ) ) {
			case 0:
				if ( $at < $length ) {
					$input[ $at ] = chr( ord( $input[ $at ] ) ^ ( 1 << $this->random->getInt( 0, 7 ) ) );
				}
				return $input;
			case 1:
				return substr_replace( $input, self::BYTES[ $this->random->getInt( 0, count( self::BYTES ) - 1 ) ], $at, $at < $length ? 1 : 0 );
			case 2:
				return substr_replace( $input, $this->random->getBytes( $this->random->getInt( 1, 8 ) ), $at, 0 );
			case 3:
				return substr_replace( $input, '', $at, $span );
			case 4:
				return substr_replace( $input, substr( $input, $at, $span ), $at, 0 );
			case 5:
				return substr( $input, 0, $at );
			case 6:
				$other = $corpus[ $this->random->getInt( 0, count( $corpus ) - 1 ) ] ?? '';
				return substr( $input, 0, $at ) . substr( $other, $this->random->getInt( 0, strlen( $other ) ) );
			case 7:
				return substr_replace( $input, self::TOKENS[ $this->random->getInt( 0, count( self::TOKENS ) - 1 ) ], $at, $this->random->getInt( 0, 1 ) * $span );
			default:
				// Replace a run of digits, the usual home of lengths, offsets and sizes.
				$count = preg_match_all( '/\d+/', $input, $found, PREG_OFFSET_CAPTURE );
				if ( ! is_int( $count ) || 0 === $count ) {
					return $input;
				}
				[ $digits, $offset ] = $found[0][ $this->random->getInt( 0, $count - 1 ) ];
				$numbers             = [ '0', '1', '-1', '255', '4294967295', '4294967296', '9223372036854775807', '99999999999999999999', '1e3', '0.5' ];
				return substr_replace( $input, $numbers[ $this->random->getInt( 0, count( $numbers ) - 1 ) ], $offset, strlen( $digits ) );
		}
	}

	/**
	 * One structure-aware mutation of a JSON document.
	 *
	 * @param string $input JSON.
	 * @return string|null Mutated JSON, or null if the input is not JSON.
	 */
	private function mutate_json( string $input ): ?string {
		try {
			$document = json_decode( $input, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}
		$paths = [];
		self::collect( $document, [], $paths );
		$path   = $paths[ $this->random->getInt( 0, count( $paths ) - 1 ) ];
		$values = self::values();
		switch ( $this->random->getInt( 0, 4 ) ) {
			case 0:
				$replacement = $values[ $this->random->getInt( 0, count( $values ) - 1 ) ];
				$document    = self::replace_at( $document, $path, static fn (): mixed => $replacement );
				break;
			case 1:
				$document = self::remove_at( $document, $path );
				break;
			case 2:
				$document = self::replace_at( $document, $path, static fn ( mixed $value ): mixed => is_array( $value ) ? $value + [ 'unknown_field' => 1 ] : [ $value ] );
				break;
			case 3:
				$document = self::replace_at( $document, $path, static fn ( mixed $value ): mixed => is_array( $value ) && array_is_list( $value ) ? array_merge( $value, $value ) : $value );
				break;
			default:
				$document = self::replace_at( $document, $path, static fn ( mixed $value ): mixed => is_string( $value ) ? strrev( $value ) : ( is_int( $value ) ? -$value - 1 : $value ) );
		}
		$encoded = json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * Collects the paths of all values.
	 *
	 * @param mixed                  $value Value.
	 * @param list<int|string>       $path  Path to the value.
	 * @param list<list<int|string>> $paths Collected paths.
	 */
	private static function collect( mixed $value, array $path, array &$paths ): void {
		$paths[] = $path;
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				self::collect( $child, [ ...$path, $key ], $paths );
			}
		}
	}

	/**
	 * Returns the document with the value at a path replaced.
	 *
	 * @param mixed                  $document Document.
	 * @param list<int|string>       $path     Path.
	 * @param \Closure(mixed): mixed $change   Returns the new value for the old one.
	 * @return mixed New document.
	 */
	private static function replace_at( mixed $document, array $path, \Closure $change ): mixed {
		if ( [] === $path ) {
			return $change( $document );
		}
		if ( ! is_array( $document ) ) {
			return $document;
		}
		$key              = array_shift( $path );
		$document[ $key ] = self::replace_at( $document[ $key ] ?? null, $path, $change );
		return $document;
	}

	/**
	 * Returns the document without the value at a path. Lists stay lists.
	 *
	 * @param mixed            $document Document.
	 * @param list<int|string> $path     Path; empty removes the whole document.
	 * @return mixed New document.
	 */
	private static function remove_at( mixed $document, array $path ): mixed {
		if ( [] === $path ) {
			return null;
		}
		if ( ! is_array( $document ) ) {
			return $document;
		}
		$key = array_shift( $path );
		if ( [] !== $path ) {
			$document[ $key ] = self::remove_at( $document[ $key ] ?? null, $path );
			return $document;
		}
		$was_list = array_is_list( $document );
		unset( $document[ $key ] );
		return $was_list ? array_values( $document ) : $document;
	}
}
