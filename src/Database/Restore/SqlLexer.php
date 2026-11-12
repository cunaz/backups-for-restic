<?php
/**
 * Bounded lexer for SQL emitted by the plugin's dump writer.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * This is not a general MySQL script reader. Comments, executable comments, variables,
 * qualified names and multiple statements are not part of the import language. Strings
 * use the dump's backslash-escape mode; the importer must establish that session mode.
 */
final class SqlLexer {

	/** One oversized row may exceed the writer's usual one MiB statement target. */
	public const int MAX_BYTES = 16777216;

	/**
	 * First unread source byte.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Whether lookahead, including end-of-input, has been scanned.
	 *
	 * @var bool
	 */
	private bool $looked = false;

	/**
	 * One lookahead token, without an array of the entire statement.
	 *
	 * @var SqlToken|null
	 */
	private ?SqlToken $lookahead = null;

	/**
	 * Reads one physical line, without its final newline.
	 *
	 * @param string $sql One statement from a table file.
	 * @param bool   $create Whether to recognize MySQL's exact INVISIBLE column marker.
	 * @throws FormatException If the line is oversized or contains an unescaped line break/NUL.
	 */
	public function __construct( private readonly string $sql, private readonly bool $create = false ) {
		if ( strlen( $sql ) > self::MAX_BYTES || strpbrk( $sql, "\r\n\0" ) !== false ) {
			throw new FormatException( 'A database dump statement exceeds its limit or is not one physical line.' );
		}
	}

	/**
	 * Looks at the next token without consuming it.
	 *
	 * @return SqlToken|null Next token, or null at the end.
	 */
	public function peek(): ?SqlToken {
		if ( ! $this->looked ) {
			$this->lookahead = $this->scan();
			$this->looked    = true;
		}
		return $this->lookahead;
	}

	/**
	 * Consumes a token.
	 *
	 * @return SqlToken|null Next token, or null at the end.
	 */
	public function next(): ?SqlToken {
		$token        = $this->peek();
		$this->looked = false;
		return $token;
	}

	/**
	 * Consumes required syntax.
	 *
	 * @param string $syntax Keyword or punctuation.
	 * @throws FormatException If the statement differs from the supported grammar.
	 */
	public function expect( string $syntax ): void {
		if ( ! ( $this->next()?->is( $syntax ) ?? false ) ) {
			throw new FormatException( 'A database dump statement has unsupported syntax.' );
		}
	}

	/**
	 * Reads a quoted identifier, as emitted by SHOW CREATE and SqlFormatter.
	 *
	 * @return SqlToken Identifier and original positions.
	 * @throws FormatException If the next token is not a quoted identifier.
	 */
	public function identifier(): SqlToken {
		$token = $this->next();
		if ( null === $token || SqlToken::IDENTIFIER !== $token->kind || '' === $token->value ) {
			throw new FormatException( 'A database dump identifier must be nonempty and quoted.' );
		}
		return $token;
	}

	/**
	 * Requires the one terminating semicolon and nothing after it.
	 *
	 * @throws FormatException If the statement is unterminated or followed by other content.
	 */
	public function end(): void {
		$this->expect( ';' );
		if ( null !== $this->next() ) {
			throw new FormatException( 'A database dump line contains more than one statement.' );
		}
	}

	/**
	 * Scans without retaining an array proportional to the number of values in the line.
	 *
	 * @return SqlToken|null Token, or end.
	 * @throws FormatException If a byte sequence is outside the dump language.
	 */
	private function scan(): ?SqlToken {
		$this->offset += strspn( $this->sql, " \t", $this->offset );
		$start         = $this->offset;
		if ( $start === strlen( $this->sql ) ) {
			return null;
		}
		$first = $this->sql[ $start ];
		if ( "'" === $first || '`' === $first ) {
			return $this->quoted( $first );
		}
		// SHOW CREATE emits this exact compatibility marker. Render its one known keyword
		// unconditionally, never pass an executable comment or its version logic through.
		$invisible = '/*!80023 INVISIBLE */';
		if ( $this->create && 0 === substr_compare( $this->sql, $invisible, $start, strlen( $invisible ) ) ) {
			$this->offset += strlen( $invisible );
			return new SqlToken( SqlToken::WORD, 'INVISIBLE', 'INVISIBLE', $start, $this->offset );
		}
		if ( 1 === preg_match( '/\G(?:0x[0-9a-fA-F]+|-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $this->sql, $match, 0, $start ) ) {
			return $this->token( SqlToken::NUMBER, $match[0], $match[0] );
		}
		if ( 1 === preg_match( '/\G[A-Za-z_][A-Za-z0-9_$]*/', $this->sql, $match, 0, $start ) ) {
			return $this->token( SqlToken::WORD, $match[0], strtoupper( $match[0] ) );
		}
		if ( in_array( substr( $this->sql, $start, 2 ), [ '--', '/*', '*/' ], true ) || ! str_contains( '(),;=+-*/%<>!&|^~', $first ) ) {
			throw new FormatException( 'A database dump statement contains unsupported SQL tokens.' );
		}
		foreach ( [ '<=>', '<=', '>=', '<>', '!=', '<<', '>>', '&&', '||' ] as $operator ) {
			if ( substr( $this->sql, $start, strlen( $operator ) ) === $operator ) {
				return $this->token( SqlToken::SYMBOL, $operator, $operator );
			}
		}
		return $this->token( SqlToken::SYMBOL, $first, $first );
	}

	/**
	 * Keeps delimiters inside values or quoted identifiers out of the grammar.
	 *
	 * @param string $quote Single quote or backtick.
	 * @return SqlToken Complete quoted token.
	 * @throws FormatException If the token has no closing quote or an incomplete escape.
	 */
	private function quoted( string $quote ): SqlToken {
		$start = $this->offset;
		$next  = $start + 1;
		$size  = strlen( $this->sql );
		while ( $next < $size ) {
			$next += strcspn( $this->sql, "'" === $quote ? "'\\" : '`', $next );
			if ( $next >= $size ) {
				break;
			}
			if ( "'" === $quote && '\\' === $this->sql[ $next ] ) {
				$next += 2;
				continue;
			}
			if ( $next + 1 < $size && $quote === $this->sql[ $next + 1 ] ) {
				$next += 2;
				continue;
			}
			$text  = substr( $this->sql, $start, $next + 1 - $start );
			$value = '`' === $quote ? str_replace( '``', '`', substr( $text, 1, -1 ) ) : $text;
			return $this->token( '`' === $quote ? SqlToken::IDENTIFIER : SqlToken::STRING, $text, $value );
		}
		throw new FormatException( 'A database dump statement has an unfinished quoted value.' );
	}

	/**
	 * Advances past one token.
	 *
	 * @param string $kind  Kind.
	 * @param string $text  Original bytes.
	 * @param string $value Semantic value for keywords/identifiers.
	 * @return SqlToken Token.
	 */
	private function token( string $kind, string $text, string $value ): SqlToken {
		$start         = $this->offset;
		$this->offset += strlen( $text );
		return new SqlToken( $kind, $text, $value, $start, $this->offset );
	}
}
