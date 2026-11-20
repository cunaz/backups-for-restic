<?php
/**
 * Read-only review of a rehearsed database and its current destination.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs\Apply;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\Session;
use BackupsForRestic\Database\Restore\SwapCatalog;
use BackupsForRestic\Database\Restore\WordPressState;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\StagedDatabase;
use BackupsForRestic\Restore\Staging;

defined( 'ABSPATH' ) || exit;

/**
 * The public preview never contains row values, private table names or SQL. Its digest
 * covers the actual source transcript and reviewed physical targets independently of
 * estimated counts, which change during ordinary WordPress activity.
 *
 * @phpstan-type Entry array{table:string,action:string}
 * @phpstan-type Preview array{restore:string,digest:string,applicable:bool,entries:list<Entry>,blockers:list<string>,missing_privileges:list<string>,preserve_admin:true,preserve_address:true}
 * @phpstan-type Target array{database:string,uuid:string,ids:array<string,string>,schemas:array<string,string>}
 */
final readonly class DatabaseReview {

	/** A swap supports at most 128 source tables. Bound the report before JSON decoding. */
	private const int MAX_REPORT = 2097152;

	/**
	 * Creates a read-only reviewer for this installation.
	 *
	 * @param JobStore $jobs Original job documents.
	 * @param Staging  $staging Checked private files.
	 * @param int      $hours Staging TTL.
	 * @param string   $prefix Current WordPress prefix.
	 */
	public function __construct( private JobStore $jobs, private Staging $staging, private int $hours, private string $prefix ) {
	}

	/**
	 * Reads a successful rehearsal and binds it to fresh live identities and definitions.
	 *
	 * @param Connection   $connection Current single database session.
	 * @param string       $restore Original restore job.
	 * @param int          $user Confirming WordPress user ID.
	 * @param list<string> $policy Site-specific blockers.
	 * @param bool         $retained Whether a queued change pins the source.
	 * @return array{preview:Preview,source:StagedDatabase,path:string,content:string,authority:string,target:Target}
	 * @throws JobConflict If the report or staging lacks its original verified binding.
	 */
	public function read( Connection $connection, string $restore, int $user, array $policy, bool $retained ): array {
		$job   = $this->jobs->get( $restore );
		$sha   = $job?->result['database_report_sha256'] ?? '';
		$files = $this->staging->files_directory( $restore, $this->hours, $retained );
		if ( null === $job || JobType::Restore !== $job->type || JobState::Succeeded !== $job->state || ! is_string( $sha ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $sha ) || null === $files ) {
			JobConflict::raise( 'Stage and rehearse the complete database before applying it.', [ 'code' => 'database_review_required' ] );
		}
		$handle = $this->staging->open_download( $restore, $this->hours, 'database_report', '', $retained );
		if ( null === $handle ) {
			JobConflict::raise( 'The verified rehearsal report is unavailable.', [ 'code' => 'database_review_required' ] );
		}
		try {
			$json = stream_get_contents( $handle, self::MAX_REPORT + 1 );
		} finally {
			fclose( $handle );
		}
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_REPORT || ! hash_equals( $sha, hash( 'sha256', $json ) ) ) {
			JobConflict::raise( 'The saved rehearsal report changed or exceeds the live review limit.', [ 'code' => 'database_review_required' ] );
		}
		$report = json_decode( $json, true, 32 );
		if ( ! is_array( $report ) || ! is_string( $report['source_directory'] ?? null ) || ! is_string( $report['content_digest'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $report['content_digest'] ) || ! is_array( $report['findings'] ?? null ) || $this->prefix !== ( $report['prefix'] ?? null ) || ( $job->data['snapshot'] ?? null ) !== ( $report['snapshot'] ?? null ) ) {
			JobConflict::raise( 'This rehearsal does not bind the verified SQL content to the reviewed snapshot.', [ 'code' => 'database_review_required' ] );
		}
		$source = StagedDatabase::open( $files, $report['source_directory'], $this->prefix );
		if ( $source->manifest->fingerprint !== ( $report['manifest_sha256'] ?? null ) ) {
			JobConflict::raise( 'The source database manifest changed after rehearsal.', [ 'code' => 'database_exchange_changed' ] );
		}
		$names      = array_column( $source->manifest->tables, 'name' );
		$review     = ( new SwapCatalog( $connection ) )->review( $names );
		$inspection = $review['inspection'];
		$blockers   = [ ...$policy, ...$inspection['blockers'] ];
		// Keep only source findings from the rehearsal. The live catalog may have changed.
		foreach ( $report['findings'] as $finding ) {
			if ( is_array( $finding ) && is_string( $finding['code'] ?? null ) && str_starts_with( $finding['code'], 'snapshot_' ) ) {
				$blockers[] = $finding['code'];
			}
		}
		$rows = $connection->rows( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND CAST(LEFT(TABLE_NAME,' . strlen( $this->prefix ) . ') AS BINARY)=' . Session::literal( $this->prefix ) . ' ORDER BY TABLE_NAME LIMIT ' . ( Manifest::MAX_TABLES + 1 ) );
		if ( count( $rows ) > Manifest::MAX_TABLES ) {
			$blockers[] = 'table_count';
		}
		$live = array_fill_keys( array_values( array_filter( array_column( $rows, 'TABLE_NAME' ), 'is_string' ) ), true );
		foreach ( WordPressState::TABLES as $suffix ) {
			$name = $this->prefix . $suffix;
			if ( ! isset( $live[ $name ] ) || ! in_array( $name, $names, true ) ) {
				$blockers[] = 'wordpress_tables';
			}
		}
		$entries = [];
		foreach ( $names as $name ) {
			$entries[] = [
				'table'  => $name,
				'action' => isset( $live[ $name ] ) ? 'replace' : 'create',
			];
			unset( $live[ $name ] );
		}
		foreach ( array_keys( $live ) as $name ) {
			$entries[] = [
				'table'  => $name,
				'action' => 'keep',
			];
		}
		$authority = '';
		if ( [] === $blockers ) {
			try {
				$authority = ( new WordPressState( $connection ) )->authority( $this->prefix, $user );
			} catch ( DatabaseFailed ) {
				$blockers[] = 'database_admin_collision';
			}
		}
		$target = [
			'database' => $inspection['facts']['database'],
			'uuid'     => $inspection['facts']['uuid'],
			'ids'      => $inspection['ids'],
			'schemas'  => $review['schemas'],
		];
		return [
			'preview'   => self::summary( $restore, $user, $entries, $blockers, $inspection['missing_privileges'], [ 'apply', $sha, $target, $authority ] ),
			'source'    => $source,
			'authority' => $authority,
			'path'      => $report['source_directory'],
			'content'   => $report['content_digest'],
			'target'    => $target,
		];
	}

	/**
	 * Binds one visible plan and its private physical inputs to its confirming user.
	 *
	 * @param string       $restore Restore identifier.
	 * @param int          $user Current user ID.
	 * @param list<Entry>  $entries Table-level operations.
	 * @param list<string> $blockers Refusal codes.
	 * @param list<string> $missing Missing privileges.
	 * @param array<mixed> $binding Private source and destination bindings.
	 * @return Preview Review for the administrator.
	 */
	public static function summary( string $restore, int $user, array $entries, array $blockers, array $missing, array $binding ): array {
		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );
		sort( $missing, SORT_STRING );
		return [
			'restore'            => $restore,
			'digest'             => hash( 'sha256', json_encode( [ 1, $restore, $user, $entries, $blockers, $missing, $binding ], JSON_THROW_ON_ERROR ) ),
			'applicable'         => $user > 0 && [] === $blockers && [] !== $entries,
			'entries'            => $entries,
			'blockers'           => $blockers,
			'missing_privileges' => $missing,
			'preserve_admin'     => true,
			'preserve_address'   => true,
		];
	}
}
