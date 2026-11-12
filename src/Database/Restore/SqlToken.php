<?php
/**
 * One token of the plugin's database dump language.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Retains source positions so that validated values need not be decoded and escaped again.
 */
final readonly class SqlToken {

	public const string WORD       = 'word';
	public const string IDENTIFIER = 'identifier';
	public const string STRING     = 'string';
	public const string NUMBER     = 'number';
	public const string SYMBOL     = 'symbol';

	/**
	 * Describes a token.
	 *
	 * @param string $kind  Token kind.
	 * @param string $text  Original SQL bytes.
	 * @param string $value Uppercase keyword, unquoted identifier or original literal.
	 * @param int    $start First byte in the source.
	 * @param int    $end   Byte immediately after the token.
	 */
	public function __construct( public string $kind, public string $text, public string $value, public int $start, public int $end ) {
	}

	/**
	 * Matches syntax only, never the contents of an identifier or a string.
	 *
	 * @param string $syntax Uppercase keyword or punctuation.
	 * @return bool Whether this token is that syntax.
	 */
	public function is( string $syntax ): bool {
		return in_array( $this->kind, [ self::WORD, self::SYMBOL ], true ) && $this->value === $syntax;
	}
}
