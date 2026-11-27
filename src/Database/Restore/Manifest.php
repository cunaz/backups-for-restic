<?php
/**
 * Validated inventory of a staged database dump.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Every table file is named by the writer's reversible encoding, never by a supplied path.
 * Importing a different site's prefix remains refused in 0.3.0 (milestone decision D3).
 */
final readonly class Manifest {

	public const int MAX_BYTES  = 2097152;
	public const int MAX_TABLES = 10000;

	/** The fields in which a table's entry records how the dump read it (ADR 0021): all or none. */
	private const array READ_FIELDS = [ 'key', 'snapshot', 'restarts', 'largest_statement' ];

	/**
	 * Characters that print as themselves by their category but are invisible where a text is
	 * rendered: the default-ignorable code points of the Basic Multilingual Plane that are
	 * letters or marks (DerivedCoreProperties.txt). The other default-ignorable ones are format
	 * characters or unassigned, which the category alone refuses.
	 */
	private const string INVISIBLE = '\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180D}\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}';

	/**
	 * Keeps an inventory whose shapes and table/file associations have already been checked.
	 *
	 * @param string                                                                                     $prefix    Expected live table prefix.
	 * @param string                                                                                     $server Original database server version.
	 * @param string                                                                                     $time_zone Session offset used when the dump was produced.
	 * @param list<array{name: string, file: string, rows: int, bytes: int}>                             $tables Completed table files, in dump order.
	 * @param list<string>                                                                               $views Views kept for later restore planning, never executed as table rows.
	 * @param list<string>|null                                                                          $trigger_tables Tables with source triggers, or null when unknown.
	 * @param string                                                                                     $fingerprint Hash of this exact inventory, for binding resumable import state.
	 * @param list<array{key: list<string>, snapshot: bool, restarts: int, largest_statement: int}|null> $reads How the dump read each table, by ordinal: the columns of the key that ordered its pages, empty without one; whether all of its rows come from one read view; how often it started over because its definition changed; its longest statement in bytes, with the semicolon and without the line break. Null for a table that a version before these fields dumped, and for every table of a manifest that left them out to stay within MAX_BYTES.
	 */
	private function __construct( public string $prefix, public string $server, public string $time_zone, public array $tables, public array $views, public ?array $trigger_tables, public string $fingerprint, public array $reads ) {
	}

	/**
	 * Validates metadata before a filename or name can influence an import.
	 *
	 * @param string $json Complete staged manifest.
	 * @param string $prefix Prefix of the destination site.
	 * @return self Inventory, with complete row/byte counts for every table.
	 * @throws FormatException If the version, prefix, names, limits or completion counts differ.
	 */
	public static function parse( string $json, string $prefix ): self {
		if ( strlen( $json ) > self::MAX_BYTES || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $prefix ) ) {
			throw new FormatException( 'The database manifest exceeds its limit or the destination prefix is invalid.' );
		}
		$data = GoJson::decode_object( $json );
		if ( ( $data['format'] ?? null ) !== TableSource::FORMAT || ( $data['charset'] ?? null ) !== 'utf8mb4' ) {
			throw new FormatException( 'The database dump format or connection character set is unsupported.' );
		}
		if ( ( $data['prefix'] ?? null ) !== $prefix ) {
			FormatException::raise( 'The database dump table prefix differs from this site; prefix conversion is not supported.', [ 'code' => 'database_restore_prefix' ] );
		}
		if ( ! is_string( $data['created'] ?? null ) || ! is_string( $data['server'] ?? null ) || str_contains( $data['server'], "\0" ) ) {
			throw new FormatException( 'The database dump creation metadata is invalid.' );
		}
		Rfc3339::parse( $data['created'] );
		$zone = $data['time_zone'] ?? null;
		if ( ! is_string( $zone ) || 1 !== preg_match( '/\A[+-](?:0[0-9]|1[0-4]):[0-5][0-9]\z/', $zone ) || ( '14' === substr( $zone, 1, 2 ) && '00' !== substr( $zone, 4 ) ) ) {
			throw new FormatException( 'The database dump time zone must be a numeric offset.' );
		}
		$stored = $data['tables'] ?? null;
		$views  = $data['views'] ?? null;
		if ( ! is_array( $stored ) || ! array_is_list( $stored ) || ! is_array( $views ) || ! array_is_list( $views ) || count( $stored ) + count( $views ) > self::MAX_TABLES ) {
			throw new FormatException( 'The database dump inventory is invalid or contains too many tables.' );
		}
		$tables = [];
		$reads  = [];
		$seen   = [];
		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['name'] ?? null ) ) {
				throw new FormatException( 'A database dump table entry is invalid.' );
			}
			$name = $entry['name'];
			self::name( $name, $prefix );
			$file = ( new Table( $name, '', [], null, [] ) )->file();
			if ( isset( $seen[ $name ] ) || ( $entry['file'] ?? null ) !== $file || strlen( $file ) > 255 ) {
				throw new FormatException( 'A database dump table is repeated or its file name does not match.' );
			}
			if ( ! is_int( $entry['rows'] ?? null ) || $entry['rows'] < 0 || ! is_int( $entry['bytes'] ?? null ) || $entry['bytes'] <= 0 ) {
				throw new FormatException( 'A database dump table has no valid completion counts.' );
			}
			$seen[ $name ] = true;
			$tables[]      = [
				'name'  => $name,
				'file'  => $file,
				'rows'  => $entry['rows'],
				'bytes' => $entry['bytes'],
			];
			$reads[]       = self::read( $entry );
		}
		$names = [];
		foreach ( $views as $name ) {
			if ( ! is_string( $name ) ) {
				throw new FormatException( 'A database dump view name is invalid.' );
			}
			self::name( $name, $prefix );
			if ( isset( $seen[ $name ] ) ) {
				throw new FormatException( 'A database dump table or view name is repeated.' );
			}
			$seen[ $name ] = true;
			$names[]       = $name;
		}
		$triggers = $data['trigger_tables'] ?? null;
		if ( null !== $triggers ) {
			if ( ! is_array( $triggers ) || ! array_is_list( $triggers ) || count( $triggers ) > count( $tables ) ) {
				throw new FormatException( 'The database trigger inventory is invalid.' );
			}
			$base = array_fill_keys( array_column( $tables, 'name' ), true );
			foreach ( $triggers as $name ) {
				if ( ! is_string( $name ) || ! isset( $base[ $name ] ) ) {
					throw new FormatException( 'The database trigger inventory names a repeated or unknown table.' );
				}
				unset( $base[ $name ] );
			}
		}
		return new self( $prefix, $data['server'], $zone, $tables, $names, $triggers, hash( 'sha256', $json ), $reads );
	}

	/**
	 * How the dump read one table, as the version that added the fields records it: all four
	 * of them, or none for a table of an earlier version and a manifest that left them out.
	 *
	 * @param array<mixed> $entry Table entry with checked name, file and counts.
	 * @return array{key: list<string>, snapshot: bool, restarts: int, largest_statement: int}|null Fields, or null without them.
	 * @throws FormatException With the code database_restore_format if only some of them are there or one is not of its kind.
	 */
	private static function read( array $entry ): ?array {
		$fields = array_intersect_key( $entry, array_flip( self::READ_FIELDS ) );
		if ( [] === $fields ) {
			return null;
		}
		$key     = $fields['key'] ?? null;
		$columns = [];
		foreach ( is_array( $key ) && array_is_list( $key ) ? $key : [ null ] as $column ) {
			if ( ! is_string( $column ) || '' === $column ) {
				$columns = null;
				break;
			}
			$columns[] = $column;
		}
		$snapshot = $fields['snapshot'] ?? null;
		$restarts = $fields['restarts'] ?? null;
		$largest  = $fields['largest_statement'] ?? null;
		$bytes    = $entry['bytes'] ?? null;
		// The largest statement is a line of the table's file, whose last line is the trailer.
		if ( count( self::READ_FIELDS ) !== count( $fields ) || null === $columns || ! is_bool( $snapshot ) || ! is_int( $restarts ) || $restarts < 0 || ! is_int( $largest ) || $largest < 0 || ! is_int( $bytes ) || $largest >= $bytes ) {
			FormatException::raise( 'A database dump table records how it was read in an invalid form.', [ 'code' => 'database_restore_format' ] );
		}
		return [
			'key'               => $columns,
			'snapshot'          => $snapshot,
			'restarts'          => $restarts,
			'largest_statement' => $largest,
		];
	}

	/**
	 * Accepts only names that can belong to this site's database and its staged file layout.
	 *
	 * @param string $name Table or view name.
	 * @param string $prefix Expected prefix.
	 * @throws FormatException If the name is invalid or belongs to another table prefix.
	 */
	private static function name( string $name, string $prefix ): void {
		if ( ! str_starts_with( $name, $prefix ) || ! mb_check_encoding( $name, 'UTF-8' ) || mb_strlen( $name, 'UTF-8' ) > 64 || 1 === preg_match( '/[.\\\\\/\x00-\x1f\x7f]/', $name ) ) {
			throw new FormatException( 'The database dump contains a table name outside this site or the supported name format.' );
		}
		if ( ! self::printable( $name ) ) {
			FormatException::raise(
				'The database dump contains a table or view whose name has a character that does not print as itself; it is not restored.',
				[
					'code'  => 'database_restore_name',
					'table' => self::quoted( $name ),
				]
			);
		}
	}

	/**
	 * Whether every character of a valid UTF-8 name prints as itself, so that the name reads
	 * the same wherever it is shown unescaped: in a terminal, a log or another tool (ADR 0035).
	 * A letter, mark, number, punctuation, symbol or the ASCII space, as Go's strconv.IsPrint()
	 * accepts and the admin page and the command line show as it is; not one of the invisible
	 * characters among them (INVISIBLE); in the Basic Multilingual Plane, the only characters
	 * that MySQL allows in an identifier; and no space at the end, which MySQL refuses. Format
	 * characters such as a right-to-left override or a zero-width space, controls, other
	 * spaces, line and paragraph separators, private-use and unassigned characters fail.
	 *
	 * @param string $name Table or view name, valid UTF-8.
	 * @return bool Whether the name prints as itself.
	 */
	public static function printable( string $name ): bool {
		return 1 === preg_match( '/\A(?:(?![\x{10000}-\x{10FFFF}' . self::INVISIBLE . '])[\p{L}\p{M}\p{N}\p{P}\p{S} ])*+\z/u', $name ) && ! str_ends_with( $name, ' ' );
	}

	/**
	 * A name as the admin page and the command line show one that does not print as itself: in
	 * double quotes with Go's escapes (GoQuote), and the invisible characters that Go prints
	 * escaped as well.
	 *
	 * @param string $name Table or view name, valid UTF-8.
	 * @return string Text of printable characters only.
	 */
	public static function quoted( string $name ): string {
		return '"' . (string) preg_replace_callback( '/[' . self::INVISIBLE . ']/u', static fn ( array $character ): string => sprintf( '\\u%04x', mb_ord( $character[0], 'UTF-8' ) ), GoQuote::quote( $name ) ) . '"';
	}
}
