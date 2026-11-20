<?php
/**
 * A base-table restore rehearsal inside the active staging job.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Restore;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Database\Restore\RehearsalReport;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Engine\Restore\Files as StagedFiles;
use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Engine\Snapshot\Path;
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
		$manifest        = $this->manifest( $this->directory( $services, $job, $subdir ) );
		$source_versions = [];
		foreach ( [ 'wordpress', 'plugin', 'php' ] as $key ) {
			if ( is_array( $versions ) && is_string( $versions[ $key ] ?? null ) ) {
				$source_versions[ $key ] = substr( $versions[ $key ], 0, 128 );
			}
		}
		$job->data['database_versions'] = $source_versions;
		$job->data['database_binding']  = [
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
		$binding   = $this->binding( $job );
		$directory = $this->directory( $services, $job, $binding['path'] );
		$manifest  = $this->manifest( $directory );
		if ( $binding['fingerprint'] !== $manifest->fingerprint || $binding['prefix'] !== $this->prefix ) {
			throw new FormatException( 'The staged database inventory or site prefix changed during the preview.' );
		}
		$importer = new TableImporter( $this->connection, $manifest, $directory, $binding['token'] );
		try {
			$progress = $importer->tick( 16, max( 0.001, min( 20.0, $deadline - microtime( true ) ) ) );
		} catch ( DatabaseFailed $error ) {
			$code = $error->context()['code'] ?? null;
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
		Files::write_atomic( $services->staging_dir( $job ) . '/' . self::REPORT, json_encode( $report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
		$job->result['database_tables'] = count( $manifest->tables );
		$job->result['database_rows']   = $report['rows'];
		$job->result['database_report'] = $services->staging_name( $job ) . '/' . self::REPORT;
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
	 * Opens a bounded staged inventory through a checked regular file.
	 *
	 * @param string $directory Resolved dump directory.
	 * @return Manifest Validated inventory.
	 * @throws FormatException If the staged inventory is unreadable or invalid.
	 */
	private function manifest( string $directory ): Manifest {
		$handle = StagedFiles::open( $directory . '/manifest.json' );
		try {
			$json = stream_get_contents( $handle, Manifest::MAX_BYTES + 1 );
			if ( false === $json ) {
				throw new FormatException( 'The staged database manifest could not be read.' );
			}
			return Manifest::parse( $json, $this->prefix );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Resolves the configured private directory once and requires every staging component
	 * below it to have the same physical and lexical name.
	 *
	 * @param RestoreServices $services Job paths.
	 * @param Job             $job Restore job.
	 * @param string          $path Snapshot directory.
	 * @return string Verified local staging directory.
	 * @throws FormatException If the selected directory cannot be safely opened.
	 */
	private function directory( RestoreServices $services, Job $job, string $path ): string {
		foreach ( Path::parts( $path ) as $part ) {
			Names::validate( $part );
		}
		$private = realpath( $services->common->private_dir->path );
		$dir     = $private . '/' . $services->staging_name( $job ) . '/files' . $path;
		clearstatcache( true );
		if ( false === $private || realpath( $dir ) !== $dir || ! is_dir( $dir ) ) {
			FormatException::raise( 'Select the complete database directory of the snapshot to preview its tables.', [ 'code' => 'database_preview_missing' ] );
		}
		return $dir;
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
		return [
			'path'        => $data['path'],
			'site'        => $data['site'],
			'prefix'      => $data['prefix'],
			'fingerprint' => $data['fingerprint'],
			'count'       => $data['count'],
			'token'       => $data['token'],
		];
	}
}
