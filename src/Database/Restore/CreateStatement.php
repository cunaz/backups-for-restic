<?php
/**
 * Constrained table definitions for transactional shadow imports.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Exception\FormatException;

defined( 'ABSPATH' ) || exit;

/**
 * Permits table-local definitions, indexes and expressions using explicitly named builtins.
 * No server-selected engine, external table, file location, SELECT or executable comment
 * is passed through. Shadows use InnoDB so row batches and their progress can commit together.
 */
final readonly class CreateStatement {

	public const int MAX_BYTES = 262144;

	private const array TYPES     = [ 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT', 'DECIMAL', 'DEC', 'NUMERIC', 'FIXED', 'FLOAT', 'DOUBLE', 'REAL', 'BOOL', 'BOOLEAN', 'BIT', 'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BINARY', 'VARBINARY', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB', 'ENUM', 'SET', 'JSON', 'GEOMETRY', 'POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT', 'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION' ];
	private const array WORDS     = [ 'UNSIGNED', 'SIGNED', 'ZEROFILL', 'NOT', 'NULL', 'DEFAULT', 'AUTO_INCREMENT', 'PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'USING', 'BTREE', 'HASH', 'FULLTEXT', 'SPATIAL', 'VISIBLE', 'INVISIBLE', 'COMMENT', 'COLLATE', 'CHARACTER', 'GENERATED', 'ALWAYS', 'AS', 'VIRTUAL', 'STORED', 'PERSISTENT', 'ON', 'UPDATE', 'CHECK', 'ENFORCED', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'AND', 'OR', 'XOR', 'IS', 'TRUE', 'FALSE', 'BETWEEN', 'IN', 'LIKE', 'ESCAPE', 'REGEXP', 'DIV', 'MOD', 'ASC', 'DESC', 'PRECISION' ];
	private const array FUNCTIONS = [ 'ABS', 'CEIL', 'CEILING', 'FLOOR', 'ROUND', 'TRUNCATE', 'SIGN', 'MOD', 'IF', 'IFNULL', 'NULLIF', 'COALESCE', 'GREATEST', 'LEAST', 'CONCAT', 'CONCAT_WS', 'LOWER', 'LCASE', 'UPPER', 'UCASE', 'LENGTH', 'CHAR_LENGTH', 'CHARACTER_LENGTH', 'OCTET_LENGTH', 'TRIM', 'LTRIM', 'RTRIM', 'SUBSTRING', 'SUBSTR', 'LEFT', 'RIGHT', 'REPLACE', 'REVERSE', 'LOCATE', 'INSTR', 'HEX', 'UNHEX', 'ASCII', 'ORD', 'CAST', 'CONVERT', 'JSON_VALID', 'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_TYPE', 'JSON_LENGTH', 'JSON_CONTAINS', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'LOCALTIME', 'LOCALTIMESTAMP' ];
	private const array ENGINES   = [ 'INNODB', 'MYISAM', 'ARIA', 'MEMORY' ];

	/**
	 * Keeps a safe CREATE and the original local-engine/comment options for later finalization.
	 *
	 * @param string       $sql CREATE for the shadow table, with its ownership marker.
	 * @param string       $engine Original local storage engine.
	 * @param string       $comment Original table comment as a validated SQL literal.
	 * @param list<string> $columns Non-generated columns, in declaration order.
	 */
	private function __construct( public string $sql, public string $engine, public string $comment, public array $columns ) {
	}

	/**
	 * Parses a SHOW CREATE line and redirects its one table to a generated private name.
	 *
	 * @param string $sql Original table definition.
	 * @param string $source Expected source table.
	 * @param string $shadow Importer's generated destination.
	 * @param string $owner Ownership marker supplied by the importer.
	 * @return self Checked table-local definition.
	 * @throws FormatException If the statement uses syntax outside the supported safe subset.
	 */
	public static function parse( string $sql, string $source, string $shadow, string $owner ): self {
		if ( strlen( $sql ) > self::MAX_BYTES || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,48}\z/', $shadow ) ) {
			throw new FormatException( 'The database table definition is too large or its shadow name is invalid.' );
		}
		$lexer = new SqlLexer( $sql, true );
		$lexer->expect( 'CREATE' );
		$lexer->expect( 'TABLE' );
		if ( $lexer->identifier()->value !== $source ) {
			throw new FormatException( 'The database CREATE names an unexpected table.' );
		}
		$lexer->expect( '(' );
		$definitions = [];
		$columns     = [];
		$seen        = [];
		do {
			[ $tokens, $more ] = self::definition_tokens( $lexer );
			if ( count( $definitions ) >= 4096 ) {
				throw new FormatException( 'The database table has too many definitions.' );
			}
			[ $clause, $column, $generated ] = self::definition( $tokens, $shadow, count( $definitions ) );
			if ( null !== $column ) {
				$key = strtolower( $column );
				if ( isset( $seen[ $key ] ) ) {
					throw new FormatException( 'The database table repeats a column name.' );
				}
				$seen[ $key ] = true;
				if ( ! $generated ) {
					$columns[] = $column;
				}
			}
			$definitions[] = $clause;
		} while ( $more );
		if ( [] === $seen ) {
			throw new FormatException( 'The database table has no columns.' );
		}
		[ $options, $engine, $comment ] = self::options( $lexer );
		$lexer->end();
		return new self(
			'CREATE TABLE ' . SqlFormatter::identifier( $shadow ) . ' (' . implode( ', ', $definitions ) . ') ENGINE=InnoDB ' . $options . ' COMMENT=' . SqlFormatter::value( $owner, SqlFormatter::TEXT ) . ';',
			$engine,
			$comment,
			$columns
		);
	}

	/**
	 * Reads one definition, preserving nested expressions without allowing a second statement.
	 *
	 * @param SqlLexer $lexer Statement.
	 * @return array{list<SqlToken>, bool} Tokens and whether a comma introduces another definition.
	 * @throws FormatException If a definition is empty, unfinished or too deeply nested.
	 */
	private static function definition_tokens( SqlLexer $lexer ): array {
		$tokens      = [];
		$parentheses = '';
		for ( $token = $lexer->next(); null !== $token; $token = $lexer->next() ) {
			if ( '' === $parentheses && ( $token->is( ',' ) || $token->is( ')' ) ) ) {
				if ( [] === $tokens ) {
					throw new FormatException( 'The database CREATE contains an empty definition.' );
				}
				return [ $tokens, $token->is( ',' ) ];
			}
			if ( $token->is( '(' ) ) {
				$parentheses .= '(';
			} elseif ( $token->is( ')' ) ) {
				$parentheses = substr( $parentheses, 0, -1 );
			}
			if ( $token->is( ';' ) || strlen( $parentheses ) > 64 || count( $tokens ) >= 16384 ) {
				throw new FormatException( 'The database table definition exceeds its grammar limits.' );
			}
			$tokens[] = $token;
		}
		throw new FormatException( 'The database table definition is unfinished.' );
	}

	/**
	 * Allows table-local column, index and check definitions only.
	 *
	 * @param list<SqlToken> $tokens Definition tokens.
	 * @param string         $shadow Generated table name.
	 * @param int            $number Definition ordinal, for unique check names.
	 * @return array{string, string|null, bool} Rendered definition, column name and generated flag.
	 * @throws FormatException If a definition could refer to external objects or execute unknown functions.
	 */
	private static function definition( array $tokens, string $shadow, int $number ): array {
		$first     = $tokens[0];
		$column    = null;
		$generated = false;
		$index     = false;
		if ( SqlToken::IDENTIFIER === $first->kind && isset( $tokens[1] ) && SqlToken::WORD === $tokens[1]->kind && in_array( $tokens[1]->value, self::TYPES, true ) ) {
			$column = $first->value;
		} elseif ( SqlToken::WORD === $first->kind && in_array( $first->value, [ 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL' ], true ) ) {
			$index = true;
		} elseif ( $first->is( 'CONSTRAINT' ) && isset( $tokens[1], $tokens[2] ) && SqlToken::IDENTIFIER === $tokens[1]->kind && $tokens[2]->is( 'CHECK' ) ) {
			// Constraint symbols must not collide with a live table's symbols.
			$name      = $shadow . '_c' . $number;
			$tokens[1] = new SqlToken( SqlToken::IDENTIFIER, SqlFormatter::identifier( $name ), $name, 0, 0 );
		} elseif ( ! $first->is( 'CHECK' ) ) {
			throw new FormatException( 'The database table uses an unsupported definition; foreign keys require a separate mapped restore step.' );
		}
		$depth = 0;
		foreach ( $tokens as $position => $token ) {
			$previous = $tokens[ $position - 1 ] ?? null;
			$next     = $tokens[ $position + 1 ] ?? null;
			if ( SqlToken::WORD === $token->kind ) {
				$charset    = ( $previous?->is( 'COLLATE' ) ?? false ) || ( $previous?->is( 'USING' ) ?? false ) || ( ( $previous?->is( 'SET' ) ?? false ) && ( ( $tokens[ $position - 2 ] ?? null )?->is( 'CHARACTER' ) ?? false ) );
				$introducer = SqlToken::STRING === $next?->kind && ( in_array( $token->value, [ 'B', 'X' ], true ) || 1 === preg_match( '/\A_[A-Z0-9_]+\z/', $token->value ) );
				if ( ! $charset && ! $introducer && ! in_array( $token->value, [ ...self::TYPES, ...self::WORDS, ...self::FUNCTIONS, 'CONSTRAINT' ], true ) ) {
					throw new FormatException( 'The database table uses an unsupported keyword or function.' );
				}
				if ( ( $next?->is( '(' ) ?? false ) && ! in_array( $token->value, [ ...self::TYPES, ...self::FUNCTIONS, 'AS', 'CHECK', 'KEY', 'INDEX', 'IN', 'AND', 'OR', 'XOR', 'NOT', 'WHEN', 'THEN', 'ELSE' ], true ) ) {
					throw new FormatException( 'The database table calls an unsupported function.' );
				}
				$generated = $generated || ( null !== $column && 0 === $depth && $token->is( 'AS' ) );
			}
			if ( SqlToken::IDENTIFIER === $token->kind && ( $next?->is( '(' ) ?? false ) ) {
				$prefix = 1 === $depth && isset( $tokens[ $position + 2 ], $tokens[ $position + 3 ] ) && SqlToken::NUMBER === $tokens[ $position + 2 ]->kind && ctype_digit( $tokens[ $position + 2 ]->text ) && $tokens[ $position + 3 ]->is( ')' );
				if ( ! $index || ( 0 !== $depth && ! $prefix ) ) {
					throw new FormatException( 'Quoted function calls are not supported in database table definitions.' );
				}
			}
			if ( $token->is( '(' ) ) {
				++$depth;
			} elseif ( $token->is( ')' ) ) {
				--$depth;
			}
		}
		return [ self::render( $tokens ), $column, $generated ];
	}

	/**
	 * Allows options that describe local storage, never file paths, table unions or plugins.
	 *
	 * @param SqlLexer $lexer Remainder after the closing definition parenthesis.
	 * @return array{string, string, string} Safe options, original engine and original comment literal.
	 * @throws FormatException If an option is duplicated, missing or unsupported.
	 */
	private static function options( SqlLexer $lexer ): array {
		$options = [];
		$seen    = [];
		$engine  = null;
		$comment = "''";
		while ( ! ( $lexer->peek()?->is( ';' ) ?? false ) ) {
			$token = $lexer->next();
			if ( $token?->is( 'DEFAULT' ) ?? false ) {
				$token = $lexer->next();
			}
			if ( $token?->is( 'CHARACTER' ) ?? false ) {
				$lexer->expect( 'SET' );
				$name = 'CHARSET';
			} else {
				$name = SqlToken::WORD === $token?->kind ? $token->value : '';
			}
			if ( isset( $seen[ $name ] ) || ! in_array( $name, [ 'ENGINE', 'AUTO_INCREMENT', 'CHARSET', 'COLLATE', 'COMMENT', 'ROW_FORMAT', 'KEY_BLOCK_SIZE', 'STATS_PERSISTENT', 'STATS_AUTO_RECALC', 'STATS_SAMPLE_PAGES', 'CHECKSUM', 'DELAY_KEY_WRITE' ], true ) ) {
				throw new FormatException( 'The database table uses a repeated or unsupported table option.' );
			}
			$seen[ $name ] = true;
			if ( $lexer->peek()?->is( '=' ) ?? false ) {
				$lexer->next();
			}
			$value = $lexer->next();
			if ( null === $value ) {
				throw new FormatException( 'The database table option has no value.' );
			}
			if ( 'ENGINE' === $name ) {
				if ( SqlToken::WORD !== $value->kind || ! in_array( $value->value, self::ENGINES, true ) ) {
					throw new FormatException( 'The database table uses an unsupported storage engine.' );
				}
				$engine = $value->value;
			} elseif ( 'COMMENT' === $name ) {
				if ( SqlToken::STRING !== $value->kind ) {
					throw new FormatException( 'The database table comment is not a literal.' );
				}
				$comment = $value->text;
			} else {
				$word = in_array( $name, [ 'CHARSET', 'COLLATE', 'ROW_FORMAT' ], true );
				if ( $word ? SqlToken::WORD !== $value->kind : ( ! ( SqlToken::NUMBER === $value->kind && ctype_digit( $value->text ) ) && ! $value->is( 'DEFAULT' ) ) ) {
					throw new FormatException( 'The database table option has an unsupported value.' );
				}
				// These physical layouts cannot describe an InnoDB shadow. The original
				// engine is retained separately for the later, confirmed finalization.
				if ( 'ROW_FORMAT' === $name && in_array( $value->value, [ 'FIXED', 'PAGE' ], true ) ) {
					continue;
				}
				if ( ! in_array( $name, [ 'CHECKSUM', 'DELAY_KEY_WRITE' ], true ) ) {
					$options[ $name ] = $name . '=' . $value->text;
				}
			}
		}
		if ( null === $engine ) {
			throw new FormatException( 'The database table does not name its original storage engine.' );
		}
		if ( 'INNODB' !== $engine ) {
			unset( $options['KEY_BLOCK_SIZE'] );
		}
		return [ implode( ' ', $options ), $engine, $comment ];
	}

	/**
	 * Emits checked tokens and puts builtin names directly next to their opening parenthesis.
	 *
	 * @param list<SqlToken> $tokens Definition.
	 * @return string Normalized SQL, with literal contents untouched.
	 */
	private static function render( array $tokens ): string {
		$sql      = '';
		$previous = null;
		foreach ( $tokens as $token ) {
			$joined   = null !== $previous && SqlToken::WORD === $previous->kind && ( $token->is( '(' ) || SqlToken::STRING === $token->kind );
			$sql     .= ( '' === $sql || $joined ? '' : ' ' ) . $token->text;
			$previous = $token;
		}
		return $sql;
	}
}
