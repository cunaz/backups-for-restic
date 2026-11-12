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
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Every table file is named by the writer's reversible encoding, never by a supplied path.
 * Importing a different site's prefix remains refused in 0.3.0 (milestone decision D3).
 */
final readonly class Manifest {

	public const int MAX_BYTES  = 2097152;
	public const int MAX_TABLES = 10000;

	/**
	 * Keeps an inventory whose shapes and table/file associations have already been checked.
	 *
	 * @param string                                                         $prefix    Expected live table prefix.
	 * @param string                                                         $time_zone Session offset used when the dump was produced.
	 * @param list<array{name: string, file: string, rows: int, bytes: int}> $tables Completed table files, in dump order.
	 * @param list<string>                                                   $views Views kept for later restore planning, never executed as table rows.
	 * @param string                                                         $fingerprint Hash of this exact inventory, for binding resumable import state.
	 */
	private function __construct( public string $prefix, public string $time_zone, public array $tables, public array $views, public string $fingerprint ) {
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
			throw new FormatException( 'The database dump table prefix differs from this site; prefix conversion is not supported.' );
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
		return new self( $prefix, $zone, $tables, $names, hash( 'sha256', $json ) );
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
	}
}
