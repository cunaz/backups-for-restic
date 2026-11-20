<?php
/**
 * A base-table restore rehearsal inside the active staging job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Database\Restore\RehearsalReport;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * The runner saves the token, prefix, table count and exact manifest binding in the job
 * before the first SQL mutation. Cleanup can use that binding even if staging is lost.
 */
final readonly class DatabasePreview {

	public const string REPORT = 'database-preview.json';

	/**
	 * Uses the site's connection for this request.
	 *
	 * @param Connection $connection Non-reconnecting SQL session.
	 * @param string     $prefix Current site prefix.
	 */
	public function __construct( private Connection $connection, private string $prefix ) {
	}

	/**
	 * Records the source, ownership and bounded cleanup range before any SQL work.
	 *
	 * @param RestoreServices $services Job paths and randomness.
	 * @param Job             $job Restore job.
	 * @param string          $path Absolute snapshot path of the site manifest.
	 * @param string          $site Original site address.
	 * @param mixed           $versions Original WordPress, plugin and PHP versions.
	 */
	public function bind( RestoreServices $services, Job $job, string $path, string $site, mixed $versions ): void {
		$subdir          = rtrim( dirname( $path ), '/' ) . '/database';
		$manifest        = $this->source( $services, $job, $subdir )->manifest;
		$source_versions = [];
		foreach ( [ 'wordpress', 'plugin', 'php' ] as $key ) {
			if ( is_array( $versions ) && is_string( $versions[ $key ] ?? null ) ) {
				$source_versions[ $key ] = substr( $versions[ $key ], 0, 128 );
			}
		}
		$destination                    = $this->destination();
		$job->data['database_versions'] = $source_versions;
		$job->data['database_binding']  = [
			'destination' => $destination,
			'path'        => $subdir,
			'site'        => $site,
			'prefix'      => $this->prefix,
			'fingerprint' => $manifest->fingerprint,
			'count'       => count( $manifest->tables ),
			'token'       => bin2hex( $services->common->random->bytes( 16 ) ),
		];
	}

	/**
	 * Verifies a bounded number of import units and produces the report after completion.
	 *
	 * @param RestoreServices $services Job paths.
	 * @param Job             $job Restore job.
	 * @param float           $deadline End of tick.
	 * @return bool Whether all base tables were verified.
	 * @throws FormatException If inventory changes or exceeds its bounds.
	 */
	public function advance( RestoreServices $services, Job $job, float $deadline ): bool {
		$binding  = $this->binding( $job );
		$source   = $this->source( $services, $job, $binding['path'] );
		$manifest = $source->manifest;
		if ( $binding['fingerprint'] !== $manifest->fingerprint || $binding['prefix'] !== $this->prefix ) {
			throw new FormatException( 'The staged database inventory or site prefix changed during the preview.' );
		}
		$importer = new TableImporter( $this->connection, $manifest, $source->directory, $binding['token'] );
		try {
			$progress = $importer->tick( 16, max( 0.001, min( 20.0, $deadline - microtime( true ) ) ) );
		} catch ( DatabaseFailed $error ) {
			$code = $error->context()['code'] ?? null;
			if ( in_array( $code, [ 1205, 1213, 2006, 2013, 2055 ], true ) ) {
				DatabaseFailed::raise( 'The database rehearsal is waiting for a lock or a fresh connection.', [ 'code' => 'database_restore_busy' ] );
			}
			if ( in_array( $code, [ 1115, 1253, 1273 ], true ) ) {
				DatabaseFailed::raise( 'The destination database cannot use a character set or collation required by the snapshot.', [ 'code' => 'database_restore_collation' ] );
			}
			if ( in_array( $code, [ 1044, 1142, 1143, 1227 ], true ) ) {
				DatabaseFailed::raise( 'The database account lacks a permission needed to rehearse the snapshot.', [ 'code' => 'database_restore_privilege' ] );
			}
			DatabaseFailed::raise( $error->getMessage(), [ 'code' => 'database_restore_failed' ] );
		} catch ( FormatException $error ) {
			DatabaseFailed::raise( $error->getMessage(), [ 'code' => 'database_restore_format' ] );
		}
		$job->progress['database_tables'] = $progress['tables'];
		$job->progress['database_total']  = $progress['total_tables'];
		if ( ! $progress['done'] ) {
			return false;
		}

		$report = [
			'snapshot'         => $job->data['snapshot'],
			'home_url'         => $binding['site'],
			'source_directory' => $binding['path'],
			'source_versions'  => $job->data['database_versions'] ?? [],
			'manifest_sha256'  => $manifest->fingerprint,
			'prefix'           => $this->prefix,
		] + ( new RehearsalReport( $this->connection ) )->read( $manifest, $importer->tables );
		$bytes  = json_encode( $report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
		Files::write_atomic( $services->staging_dir( $job ) . '/' . self::REPORT, $bytes );
		$job->result['database_report_sha256'] = hash( 'sha256', $bytes );
		$job->result['database_tables']        = count( $manifest->tables );
		$job->result['database_rows']          = $report['rows'];
		$job->result['database_report']        = $services->staging_name( $job ) . '/' . self::REPORT;
		return true;
	}

	/**
	 * Removes only tables with the original job's ownership, including after cancellation.
	 *
	 * @param Job   $job Restore job with persisted binding.
	 * @param float $deadline End of tick.
	 * @return bool Whether cleanup reached the progress table.
	 */
	public function clean( Job $job, float $deadline ): bool {
		$binding                       = $this->binding( $job );
		$owned                         = new OwnedTables( $this->connection, $binding['prefix'], $binding['fingerprint'], $binding['token'] );
		$next                          = $owned->clean( $binding['count'], Decoded::int( $job->data['database_cleanup'] ?? 0 ), $deadline );
		$job->data['database_cleanup'] = $next;
		if ( $next <= $binding['count'] ) {
			return false;
		}
		unset( $job->data['database_binding'], $job->data['database_cleanup'] );
		return true;
	}

	/**
	 * Locates the staged database under this job's private files directory.
	 *
	 * @param RestoreServices $services Job paths.
	 * @param Job             $job Original restore.
	 * @param string          $path Absolute snapshot directory.
	 * @return StagedDatabase Validated source.
	 * @throws FormatException If the working directory is missing.
	 */
	private function source( RestoreServices $services, Job $job, string $path ): StagedDatabase {
		$private = realpath( $services->common->private_dir->path );
		if ( false === $private ) {
			throw new FormatException( 'The private staging directory is unavailable.' );
		}
		return StagedDatabase::open( $private . '/' . $services->staging_name( $job ) . '/files', $path, $this->prefix );
	}

	/**
	 * Validates the saved fields before constructing table names or opening files.
	 *
	 * @param Job $job Restore job.
	 * @return array{path: string, site: string, prefix: string, fingerprint: string, count: int, token: string} Original identity.
	 * @throws FormatException If the binding is missing or invalid.
	 */
	private function binding( Job $job ): array {
		$data = $job->data['database_binding'] ?? null;
		if ( ! is_array( $data ) || ! is_string( $data['path'] ?? null ) || ! is_string( $data['site'] ?? null ) || ! is_string( $data['prefix'] ?? null ) || ! is_string( $data['fingerprint'] ?? null ) || ! is_int( $data['count'] ?? null ) || ! is_string( $data['token'] ?? null ) ) {
			throw new FormatException( 'The database preview lost its persisted ownership information.' );
		}
		if ( array_key_exists( 'destination', $data ) && $this->destination() !== $data['destination'] ) {
			throw new DatabaseFailed( 'The database rehearsal belongs to another server or database. Reconnect the original database to clean its tables.' );
		}
		return [
			'path'        => $data['path'],
			'site'        => $data['site'],
			'prefix'      => $data['prefix'],
			'fingerprint' => $data['fingerprint'],
			'count'       => $data['count'],
			'token'       => $data['token'],
		];
	}
	/**
	 * Binds new rehearsal ownership to its current target, including the server UUID or
	 * MariaDB server UID when available. An empty destination cannot discard evidence
	 * belonging to a renamed database or a different server.
	 *
	 * @return array{schema:string,server:string} Durable server and database identity.
	 * @throws DatabaseFailed If the current schema is unavailable.
	 */
	private function destination(): array {
		$schema = $this->connection->rows( 'SELECT DATABASE() AS db' )[0]['db'] ?? '';
		if ( '' === $schema ) {
			throw new DatabaseFailed( 'The current database could not be identified for the restore rehearsal.' );
		}
		$server = $this->connection->rows( "SHOW VARIABLES WHERE Variable_name IN ('server_uid','server_uuid')" )[0]['Value'] ?? '';
		return [
			'schema' => $schema,
			'server' => $server,
		];
	}
}
