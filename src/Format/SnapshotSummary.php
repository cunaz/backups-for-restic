<?php
/**
 * Backup statistics stored with a snapshot.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

defined( 'ABSPATH' ) || exit;

/**
 * The "summary" object restic stores in snapshots since 0.17.
 */
final readonly class SnapshotSummary {

	/**
	 * Creates a summary.
	 *
	 * @param string $backup_start          Start time (RFC 3339).
	 * @param string $backup_end            End time (RFC 3339).
	 * @param int    $files_new             New files.
	 * @param int    $files_changed         Changed files.
	 * @param int    $files_unmodified      Unmodified files.
	 * @param int    $dirs_new              New directories.
	 * @param int    $dirs_changed          Changed directories.
	 * @param int    $dirs_unmodified       Unmodified directories.
	 * @param int    $data_blobs            Data blobs added.
	 * @param int    $tree_blobs            Tree blobs added.
	 * @param int    $data_added            Bytes added before packing.
	 * @param int    $data_added_packed     Bytes added to packs.
	 * @param int    $total_files_processed Files processed.
	 * @param int    $total_bytes_processed Bytes processed.
	 */
	public function __construct(
		public string $backup_start,
		public string $backup_end,
		public int $files_new = 0,
		public int $files_changed = 0,
		public int $files_unmodified = 0,
		public int $dirs_new = 0,
		public int $dirs_changed = 0,
		public int $dirs_unmodified = 0,
		public int $data_blobs = 0,
		public int $tree_blobs = 0,
		public int $data_added = 0,
		public int $data_added_packed = 0,
		public int $total_files_processed = 0,
		public int $total_bytes_processed = 0
	) {
	}

	/**
	 * Returns the fields in restic's order.
	 *
	 * @return array<string, int|string> Fields.
	 */
	public function to_array(): array {
		return [
			'backup_start'          => $this->backup_start,
			'backup_end'            => $this->backup_end,
			'files_new'             => $this->files_new,
			'files_changed'         => $this->files_changed,
			'files_unmodified'      => $this->files_unmodified,
			'dirs_new'              => $this->dirs_new,
			'dirs_changed'          => $this->dirs_changed,
			'dirs_unmodified'       => $this->dirs_unmodified,
			'data_blobs'            => $this->data_blobs,
			'tree_blobs'            => $this->tree_blobs,
			'data_added'            => $this->data_added,
			'data_added_packed'     => $this->data_added_packed,
			'total_files_processed' => $this->total_files_processed,
			'total_bytes_processed' => $this->total_bytes_processed,
		];
	}

	/**
	 * Builds a summary from decoded JSON, tolerating missing fields.
	 *
	 * @param array<mixed> $data Decoded object.
	 * @return self Summary.
	 */
	public static function from_array( array $data ): self {
		$int = static fn ( string $key ): int => is_int( $data[ $key ] ?? null ) ? $data[ $key ] : 0;
		return new self(
			is_string( $data['backup_start'] ?? null ) ? $data['backup_start'] : '',
			is_string( $data['backup_end'] ?? null ) ? $data['backup_end'] : '',
			$int( 'files_new' ),
			$int( 'files_changed' ),
			$int( 'files_unmodified' ),
			$int( 'dirs_new' ),
			$int( 'dirs_changed' ),
			$int( 'dirs_unmodified' ),
			$int( 'data_blobs' ),
			$int( 'tree_blobs' ),
			$int( 'data_added' ),
			$int( 'data_added_packed' ),
			$int( 'total_files_processed' ),
			$int( 'total_bytes_processed' )
		);
	}
}
