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
 */
final class DatabaseDump {

	public const string DIRECTORY = 'database';

	/**
	 * Prepares the dump.
	 *
	 * @param Connection   $connection Database.
	 * @param string       $state_file JSON state file in the job's directory.
	 * @param string       $prefix     Only tables with this prefix; empty for all.
	 * @param list<string> $exclude    Tables to leave out.
	 * @param int          $time       Unix time of the backup, used as file mtime.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly string $state_file,
		private readonly string $prefix,
		private readonly array $exclude,
		private readonly int $time
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
		$preamble = $this->preamble( $state['time_zone'] );
		$catalog  = new Catalog( $this->connection );
		$files    = [];

		$header  = '-- ' . TableSource::FORMAT . "\n";
		$header .= "-- Database dump written by the backup plugin. Import every .sql file of this directory in name order,\n";
		$header .= "-- for example: cat *.sql | mariadb <database>\n";
		$files[] = $this->file( '000-header.sql', static fn (): ByteSource => new StringByteSource( $header . $preamble ) );

		foreach ( $state['tables'] as $name ) {
			$file    = ( new Table( $name, '', [], null, [] ) )->file();
			$files[] = $this->file(
				$file,
				fn ( ?string $cursor ): ByteSource => new TableSource(
					$this->connection,
					$catalog->table( $name ),
					$preamble,
					$cursor,
					fn ( int $rows, int $bytes ) => $this->record( $file, $name, $rows, $bytes )
				)
			);
		}

		$views   = $state['views'];
		$files[] = $this->file(
			'900-views.sql',
			static function () use ( $catalog, $views, $preamble ): ByteSource {
				$sql = '-- ' . TableSource::FORMAT . " views\n" . $preamble;
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
			$file     = ( new Table( $name, '', [], null, [] ) )->file();
			$result   = $state['results'][ $file ] ?? null;
			$tables[] = [
				'name'  => $name,
				'file'  => $file,
				'rows'  => $result['rows'] ?? null,
				'bytes' => $result['bytes'] ?? null,
			];
		}
		return (string) json_encode(
			[
				'format'    => TableSource::FORMAT,
				'created'   => Rfc3339::format( $this->time ),
				'server'    => $state['server'],
				'charset'   => 'utf8mb4',
				'time_zone' => $state['time_zone'],
				'prefix'    => $this->prefix,
				'tables'    => $tables,
				'views'     => $state['views'],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
	}

	/**
	 * Records a finished table.
	 *
	 * @param string $file  File name.
	 * @param string $name  Table name.
	 * @param int    $rows  Rows.
	 * @param int    $bytes Bytes of the file.
	 */
	private function record( string $file, string $name, int $rows, int $bytes ): void {
		$state                     = $this->state();
		$state['results'][ $file ] = [
			'table' => $name,
			'rows'  => $rows,
			'bytes' => $bytes,
		];
		Files::write_atomic( $this->state_file, (string) json_encode( $state ) );
	}

	/**
	 * Loads the state, listing tables and views on first use.
	 *
	 * @return array{tables: list<string>, views: list<string>, time_zone: string, server: string, results: array<string, array{table: string, rows: int, bytes: int}>} State.
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
				}
			}
			return [
				'tables'    => array_values( array_filter( $stored['tables'], 'is_string' ) ),
				'views'     => array_values( array_filter( is_array( $stored['views'] ?? null ) ? $stored['views'] : [], 'is_string' ) ),
				'time_zone' => is_string( $stored['time_zone'] ?? null ) ? $stored['time_zone'] : '+00:00',
				'server'    => is_string( $stored['server'] ?? null ) ? $stored['server'] : '',
				'results'   => $results,
			];
		}
		$catalog = new Catalog( $this->connection );
		$state   = [
			'tables'    => $catalog->table_names( $this->prefix, $this->exclude ),
			'views'     => $catalog->view_names( $this->prefix ),
			'time_zone' => $catalog->time_zone(),
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
