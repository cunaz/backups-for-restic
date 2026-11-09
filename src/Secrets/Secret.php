<?php
/**
 * A secret value that does not leak.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a password or key so that it cannot end up in logs, dumps or responses by accident
 * (docs/03-security-and-threat-model.md §5.3).
 *
 * The value is captured by a closure instead of a property: var_dump(), print_r(),
 * var_export() and serialize() see no string, and serialize() fails outright. json_encode()
 * fails too. The raw value is only available through reveal().
 */
final class Secret implements \JsonSerializable, \Stringable {

	/**
	 * Returns the value.
	 *
	 * @var \Closure(): string
	 */
	private readonly \Closure $value;

	/**
	 * Wraps a value.
	 *
	 * @param string $value Raw secret.
	 */
	public function __construct( #[\SensitiveParameter] string $value ) {
		$this->value = static fn (): string => $value;
	}

	/**
	 * Returns the raw value. Every call site is a deliberate exposure point.
	 *
	 * @return string Raw secret.
	 */
	public function reveal(): string {
		return ( $this->value )();
	}

	/**
	 * Whether the secret is the empty string.
	 *
	 * @return bool True if empty.
	 */
	public function is_empty(): bool {
		return '' === $this->reveal();
	}

	/**
	 * Compares in constant time.
	 *
	 * @param Secret $other Other secret.
	 * @return bool True if both hold the same value.
	 */
	public function equals( Secret $other ): bool {
		return hash_equals( $this->reveal(), $other->reveal() );
	}

	/**
	 * Redacted text.
	 *
	 * @return string The string "[redacted]".
	 */
	public function __toString(): string {
		return '[redacted]';
	}

	/**
	 * Redacted debug output for var_dump() and print_r().
	 *
	 * @return array{value: string} Redacted fields.
	 */
	public function __debugInfo(): array {
		return [ 'value' => '[redacted]' ];
	}

	/**
	 * Refuses JSON encoding.
	 *
	 * @throws \LogicException Always.
	 */
	public function jsonSerialize(): never {
		throw new \LogicException( 'A secret must never be encoded as JSON.' );
	}
}
