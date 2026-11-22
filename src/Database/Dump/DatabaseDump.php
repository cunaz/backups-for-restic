<?php
/**
 * The database as a directory of virtual files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\ByteSource;
use BackupsForRestic\Support\Rfc3339;
use BackupsForRestic\Support\StringByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * Builds spool/database/ of a snapshot (ADR 0021): 000-header.sql, one 010-<table>.sql per
 * table, 900-views.sql and manifest.json. Names sort in import order, and the backup reads
 * them in that order, so manifest.json is produced after every table is done.
 *
 * The table list is fixed when the backup first lists the directory and kept in a state file
 * in the job's directory, together with the row and byte counts of finished tables, so the
 * dump stays consistent across ticks.
 *
 * The dump reads through a connection of its own where one can be opened and verified
 * (DedicatedConnection): each table's part of a tick is then one read-only transaction, and
 * InnoDB serves all of it from one snapshot. Otherwise it reads through the site's connection
 * (SharedConnection), without snapshots. Either way every read runs with utf8mb4 results and
 * the time zone +00:00, and the files say so: TIMESTAMP values are UTC, as with mysqldump
 * --tz-utc, and text arrives in the one character set the manifest names. A dump that an
 * earlier version started recorded the session's offset instead; it keeps reading in the
 * session's time zone until it is done, so that all of its files agree with the one offset in
 * its manifest.
 */
final class DatabaseDump {

	public const string DIRECTORY = 'database';

	/** State files of earlier versions have no version and recorded the session's offset. */
	private const int STATE_VERSION = 2;

	/**
	 * Connection with the dump's session settings, made on first use.
	 *
	 * @var Connection|null
	 */
	private ?Connection $reader = null;

	/**
	 * Prepares the dump.
	 *
	 * @param Connection                                     $connection The site's connection.
	 * @param string                                         $state_file JSON state file in the job's directory.
	 * @param string                                         $prefix     Only tables with this prefix; empty for all.
	 * @param list<string>                                   $exclude    Tables to leave out.
	 * @param int                                            $time       Unix time of the backup, used as file mtime.
	 * @param (\Closure(?string): ?DedicatedConnection)|null $dedicated Opens a connection of the dump's own with the given time zone, or returns null if it cannot; null to read through $connection only.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly string $state_file,
		private readonly string $prefix,
		private readonly array $exclude,
		private readonly int $time,
		private readonly ?\Closure $dedicated = null
	) {
	}

	/**
	 * The files of the database directory.
	 *
	 * @return list<VirtualFile> Files in name order.
	 * @throws DatabaseFailed If the tables cannot be listed.
	 */
	public function files(): array {
		$state    = $this->state();
		$version  = $state['version'];
		$preamble = $this->preamble( $state['time_zone'] );
		$files    = [];

		$header  = '-- ' . TableSource::FORMAT . "\n";
		$header .= "-- Database dump written by the backup plugin. Import every .sql file of this directory in name order,\n";
		$header .= "-- for example: cat *.sql | mariadb <database>\n";
		$files[] = $this->file( '000-header.sql', static fn (): ByteSource => new StringByteSource( $header . $preamble ) );

		foreach ( $state['tables'] as $name ) {
			$file    = ( new Table( $name, '', [], null, [] ) )->file();
			$files[] = $this->file( $file, fn ( ?string $cursor ): ByteSource => $this->table( $version, $name, $file, $preamble, $cursor ) );
		}

		$views   = $state['views'];
		$files[] = $this->file(
			'900-views.sql',
			function () use ( $version, $views, $preamble ): ByteSource {
				$catalog = new Catalog( $this->reader( $version ) );
				$sql     = '-- ' . TableSource::FORMAT . " views\n" . $preamble;
				foreach ( $views as $view ) {
					$sql .= 'DROP VIEW IF EXISTS ' . SqlFormatter::identifier( $view ) . ";\n" . $catalog->view( $view ) . ";\n";
				}
				return new StringByteSource( $sql );
			}
		);
		$files[] = $this->file( 'manifest.json', fn (): ByteSource => new StringByteSource( $this->manifest() ) );
		return $files;
	}

	/**
	 * Opens one table's file at a cursor. On a connection of the dump's own, its transaction
	 * starts before the definition is read, so that definition and rows belong together.
	 *
	 * @param int         $version  Version of the dump's state.
	 * @param string      $name     Table name.
	 * @param string      $file     File name.
	 * @param string      $preamble Session settings.
	 * @param string|null $cursor   Cursor, or null to start.
	 * @return TableSource Source.
	 * @throws DatabaseFailed If the table cannot be described.
	 */
	private function table( int $version, string $name, string $file, string $preamble, ?string $cursor ): TableSource {
		$reader = $this->reader( $version );
		if ( $reader instanceof DedicatedConnection ) {
			$reader->begin( $name );
		}
		$catalog = new Catalog( $reader );
		return new TableSource( $reader, $catalog->table( $name ), $preamble, $cursor, fn ( array $result ) => $this->record( $file, $name, $result ), static fn (): Table => $catalog->table( $name ) );
	}

	/**
	 * The connection with the dump's session settings: one of its own if it can be opened and
	 * verified, otherwise the site's. A connection of its own that a table closed after an
	 * unfinished read is replaced.
	 *
	 * @param int $version Version of the dump's state.
	 * @return Connection Reader.
	 */
	private function reader( int $version ): Connection {
		if ( null === $this->reader || ( $this->reader instanceof DedicatedConnection && $this->reader->closed() ) ) {
			$time_zone    = self::STATE_VERSION === $version ? '+00:00' : null;
			$dedicated    = null === $this->dedicated ? null : ( $this->dedicated )( $time_zone );
			$this->reader = $dedicated instanceof DedicatedConnection ? $dedicated : new SharedConnection( $this->connection, $time_zone );
		}
		return $this->reader;
	}

	/**
	 * Session settings at the top of every file, so that each one also imports on its own.
	 *
	 * @param string $time_zone Offset of the dumping session.
	 * @return string Statements, one per line.
	 */
	private function preamble( string $time_zone ): string {
		return "SET NAMES utf8mb4;\n"
			. "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET SESSION time_zone = '" . $time_zone . "';\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n"
			. "SET UNIQUE_CHECKS = 0;\n";
	}

	/**
	 * The database manifest: format, server, and per file the table, rows and bytes.
	 *
	 * @return string JSON.
	 */
	private function manifest(): string {
		$state  = $this->state();
		$tables = [];
		foreach ( $state['tables'] as $name ) {
			$file   = ( new Table( $name, '', [], null, [] ) )->file();
			$result = $state['results'][ $file ] ?? null;
			$entry  = [
				'name'  => $name,
				'file'  => $file,
				'rows'  => $result['rows'] ?? null,
				'bytes' => $result['bytes'] ?? null,
			];
			// How the rows were read, for tables that this version dumped: the columns of the
			// key that ordered the pages (empty without one), whether all rows come from one
			// read view, so that an import can explain a duplicate key, and how often the table
			// started over because its definition changed.
			if ( isset( $result['key'], $result['snapshot'], $result['restarts'] ) ) {
				$entry['key']      = $result['key'];
				$entry['snapshot'] = $result['snapshot'];
				$entry['restarts'] = $result['restarts'];
			}
			$tables[] = $entry;
		}
		return (string) json_encode(
			[
				'format'         => TableSource::FORMAT,
				'created'        => Rfc3339::format( $this->time ),
				'server'         => $state['server'],
				'charset'        => 'utf8mb4',
				'time_zone'      => $state['time_zone'],
				'prefix'         => $this->prefix,
				'tables'         => $tables,
				'views'          => $state['views'],
				'trigger_tables' => ( new Catalog( $this->reader( $state['version'] ) ) )->trigger_tables( $state['tables'] ),
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
	}

	/**
	 * Records a finished table.
	 *
	 * @param string                                                                         $file   File name.
	 * @param string                                                                         $name   Table name.
	 * @param array{rows: int, bytes: int, snapshot: bool, key: list<string>, restarts: int} $result Counts and how the rows were read.
	 */
	private function record( string $file, string $name, array $result ): void {
		$state                     = $this->state();
		$state['results'][ $file ] = [ 'table' => $name ] + $result;
		Files::write_atomic( $this->state_file, (string) json_encode( $state ) );
	}

	/**
	 * Loads the state, listing tables and views on first use.
	 *
	 * @return array{version: int, tables: list<string>, views: list<string>, time_zone: string, server: string, results: array<string, array{table: string, rows: int, bytes: int, snapshot?: bool, key?: list<string>, restarts?: int}>} State.
	 * @throws DatabaseFailed If the tables cannot be listed.
	 */
	private function state(): array {
		$json   = is_file( $this->state_file ) ? file_get_contents( $this->state_file ) : false;
		$stored = false === $json ? null : json_decode( $json, true );
		if ( is_array( $stored ) && is_array( $stored['tables'] ?? null ) ) {
			$results = [];
			foreach ( is_array( $stored['results'] ?? null ) ? $stored['results'] : [] as $file => $result ) {
				if ( is_string( $file ) && is_array( $result ) ) {
					$results[ $file ] = [
						'table' => is_string( $result['table'] ?? null ) ? $result['table'] : '',
						'rows'  => is_int( $result['rows'] ?? null ) ? $result['rows'] : 0,
						'bytes' => is_int( $result['bytes'] ?? null ) ? $result['bytes'] : 0,
					];
					$key              = $result['key'] ?? null;
					if ( is_bool( $result['snapshot'] ?? null ) && is_array( $key ) && array_is_list( $key ) && $key === array_filter( $key, 'is_string' ) && is_int( $result['restarts'] ?? null ) ) {
						$results[ $file ]['snapshot'] = $result['snapshot'];
						$results[ $file ]['key']      = $key;
						$results[ $file ]['restarts'] = $result['restarts'];
					}
				}
			}
			return [
				'version'   => is_int( $stored['version'] ?? null ) ? $stored['version'] : 1,
				'tables'    => array_values( array_filter( $stored['tables'], 'is_string' ) ),
				'views'     => array_values( array_filter( is_array( $stored['views'] ?? null ) ? $stored['views'] : [], 'is_string' ) ),
				'time_zone' => is_string( $stored['time_zone'] ?? null ) ? $stored['time_zone'] : '+00:00',
				'server'    => is_string( $stored['server'] ?? null ) ? $stored['server'] : '',
				'results'   => $results,
			];
		}
		$catalog = new Catalog( $this->reader( self::STATE_VERSION ) );
		$state   = [
			'version'   => self::STATE_VERSION,
			'tables'    => $catalog->table_names( $this->prefix, $this->exclude ),
			'views'     => $catalog->view_names( $this->prefix ),
			// The reads run in UTC, so TIMESTAMP values import unchanged whatever the session's zone.
			'time_zone' => '+00:00',
			'server'    => $catalog->server_version(),
			'results'   => [],
		];
		Files::write_atomic( $this->state_file, (string) json_encode( $state ) );
		return $state;
	}

	/**
	 * Creates a virtual file.
	 *
	 * @param string                        $name   File name.
	 * @param \Closure(?string): ByteSource $source Content.
	 * @return VirtualFile File.
	 */
	private function file( string $name, \Closure $source ): VirtualFile {
		return new VirtualFile( $name, $source, $this->time, 0600 );
	}
}
