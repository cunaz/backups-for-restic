<?php
/**
 * Deduplicating blob writer.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Pack\PackEntry;
use BackupsForRestic\Format\Pack\PackWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Stores blobs that the repository does not have yet.
 *
 * Blobs go into one open pack per type (data and tree blobs never share a pack). A pack is
 * uploaded when it reaches the target size or when flush() is called, for example at the end of
 * a time slice. Uploaded packs are added to the in-memory index at once and written to an index
 * file later; write order guarantees that a snapshot never references a blob that is not
 * stored: packs first, then index files, then trees and the snapshot.
 */
final class BlobStore {

	/** Default target size of a pack. */
	public const int DEFAULT_PACK_SIZE = 16777216;

	/**
	 * Target size of a pack in bytes.
	 *
	 * @var int
	 */
	private readonly int $pack_size;

	/**
	 * Open packs by blob type value.
	 *
	 * @var array<string, PackWriter>
	 */
	private array $writers = [];

	/**
	 * Blobs in open packs: type char + raw ID.
	 *
	 * @var array<string, true>
	 */
	private array $pending = [];

	/**
	 * Uploaded packs that no index file lists yet.
	 *
	 * @var array<string, list<PackEntry>>
	 */
	private array $unindexed = [];

	/**
	 * Counters for the snapshot summary.
	 *
	 * @var array{data_blobs: int, tree_blobs: int, data_added: int, data_added_packed: int}
	 */
	private array $stats = [
		'data_blobs'        => 0,
		'tree_blobs'        => 0,
		'data_added'        => 0,
		'data_added_packed' => 0,
	];

	/**
	 * Creates a store.
	 *
	 * @param Repository $repository Repository.
	 * @param IndexSet   $index      Index of everything already stored.
	 * @param int        $pack_size  Target pack size in bytes.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly IndexSet $index,
		int $pack_size = self::DEFAULT_PACK_SIZE
	) {
		// With the index in memory, what is left decides how large a pack may become.
		$this->pack_size = $repository->memory()?->pack_size( $pack_size ) ?? $pack_size;
	}

	/**
	 * The size at which a pack is uploaded: the one asked for, or a smaller one if the
	 * repository's memory budget does not allow it.
	 *
	 * @return int Bytes.
	 */
	public function pack_size(): int {
		return $this->pack_size;
	}

	/**
	 * Stores a blob unless it exists already.
	 *
	 * @param BlobType $type      Blob type.
	 * @param string   $plaintext Content.
	 * @return Id Blob ID.
	 */
	public function save( BlobType $type, string $plaintext ): Id {
		$id  = Id::of( $plaintext );
		$key = $type->value[0] . $id->bytes();
		if ( isset( $this->pending[ $key ] ) || $this->index->has( $id, $type ) ) {
			return $id;
		}

		$writer = $this->writers[ $type->value ] ?? new PackWriter( $this->repository->cipher() );
		if ( $writer->header_full() ) {
			$this->upload( $type, $writer );
			$writer = new PackWriter( $this->repository->cipher() );
		}

		$stored                        = $writer->add( $type, $id, $plaintext );
		$this->writers[ $type->value ] = $writer;
		$this->pending[ $key ]         = true;
		++$this->stats[ BlobType::Tree === $type ? 'tree_blobs' : 'data_blobs' ];
		$this->stats['data_added']        += strlen( $plaintext );
		$this->stats['data_added_packed'] += $stored;

		if ( $writer->size() >= $this->pack_size ) {
			$this->upload( $type, $writer );
		}
		return $id;
	}

	/**
	 * Whether a blob is stored or pending.
	 *
	 * @param BlobType $type Blob type.
	 * @param Id       $id   Blob ID.
	 * @return bool True if known.
	 */
	public function has( BlobType $type, Id $id ): bool {
		return isset( $this->pending[ $type->value[0] . $id->bytes() ] ) || $this->index->has( $id, $type );
	}

	/**
	 * Uploads all open packs.
	 */
	public function flush(): void {
		foreach ( $this->writers as $type => $writer ) {
			$this->upload( BlobType::from( $type ), $writer );
		}
	}

	/**
	 * Writes index files for all uploaded packs that are not indexed yet.
	 *
	 * @return list<Id> Names of the written index files.
	 */
	public function write_index(): array {
		$written = [];
		$batch   = [];
		$count   = 0;
		foreach ( $this->unindexed as $pack => $entries ) {
			if ( $count > 0 && $count + count( $entries ) > IndexFile::MAX_BLOBS ) {
				$written[] = $this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( $batch ) );
				$batch     = [];
				$count     = 0;
			}
			$batch[ $pack ] = $entries;
			$count         += count( $entries );
		}
		if ( [] !== $batch ) {
			$written[] = $this->repository->save_unpacked( ObjectType::Index, IndexFile::encode( $batch ) );
		}
		$this->unindexed = [];
		return $written;
	}

	/**
	 * Number of blobs in uploaded but unindexed packs.
	 *
	 * @return int Count.
	 */
	public function unindexed_blob_count(): int {
		return array_sum( array_map( 'count', $this->unindexed ) );
	}

	/**
	 * Exports the unindexed packs for a checkpoint.
	 *
	 * @return array<string, list<array{0: string, 1: string, 2: int, 3: int}>> Entries by pack ID.
	 */
	public function export_unindexed(): array {
		$export = [];
		foreach ( $this->unindexed as $pack => $entries ) {
			$export[ $pack ] = array_map(
				static fn ( PackEntry $entry ): array => [ $entry->type->value, $entry->id->hex(), $entry->offset, $entry->length ],
				$entries
			);
		}
		return $export;
	}

	/**
	 * Restores unindexed packs from a checkpoint and makes their blobs known for deduplication.
	 *
	 * @param array<mixed> $export Value from export_unindexed().
	 */
	public function import_unindexed( array $export ): void {
		foreach ( $export as $pack => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			$list = [];
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) && is_string( $entry[0] ?? null ) && is_string( $entry[1] ?? null ) && is_int( $entry[2] ?? null ) && is_int( $entry[3] ?? null ) ) {
					$list[] = new PackEntry( BlobType::from( $entry[0] ), Id::from_hex( $entry[1] ), $entry[2], $entry[3] );
				}
			}
			$pack_id                            = Id::from_hex( (string) $pack );
			$this->unindexed[ $pack_id->hex() ] = $list;
			$this->index->add_pack( $pack_id, $list );
		}
	}

	/**
	 * Returns the counters.
	 *
	 * @return array{data_blobs: int, tree_blobs: int, data_added: int, data_added_packed: int} Counters.
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Restores counters from a checkpoint.
	 *
	 * @param array<mixed> $stats Saved counters.
	 */
	public function import_stats( array $stats ): void {
		foreach ( array_keys( $this->stats ) as $key ) {
			$this->stats[ $key ] = is_int( $stats[ $key ] ?? null ) ? $stats[ $key ] : 0;
		}
	}

	/**
	 * Finishes and uploads one pack.
	 *
	 * @param BlobType   $type   Blob type.
	 * @param PackWriter $writer Pack.
	 */
	private function upload( BlobType $type, PackWriter $writer ): void {
		unset( $this->writers[ $type->value ] );
		if ( 0 === $writer->count() ) {
			return;
		}

		$pack = $writer->finish();
		$this->repository->backend()->save( ObjectType::Data, $pack->id->hex(), $pack->bytes );
		$this->index->add_pack( $pack->id, $pack->entries );
		$this->unindexed[ $pack->id->hex() ] = $pack->entries;
		foreach ( $pack->entries as $entry ) {
			unset( $this->pending[ $entry->type->value[0] . $entry->id->bytes() ] );
		}
	}
}
