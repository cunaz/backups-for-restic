<?php
/**
 * The index, cached locally.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the merged index in cache/<repository-id>/index.bin, encrypted with the repository's
 * own key, together with the names of the index files it covers. A tick then lists the
 * index files and downloads only new ones instead of all of them.
 *
 * The file is a sequence of pieces of about one megabyte, each sealed on its own and
 * preceded by its sealed length as pack( 'V' ). Reading and writing therefore need one
 * piece in memory besides the index, not the whole file two or three times (ADR 0031). The
 * plaintext of every piece starts with 16 random bytes that are the same throughout one
 * file and the number of the piece, so that pieces cannot be left out, repeated, reordered
 * or mixed with those of another file without the cache being discarded. The first piece
 * holds the names of the covered index files, the others are those of IndexSet::export().
 *
 * If an index file disappeared from the server (restic prune or rebuild-index ran elsewhere),
 * the cache cannot tell which entries became stale, so it is rebuilt from scratch. An index
 * larger than the cache limit (advanced.cache_limit_mib) is not kept: every tick then reads
 * the index files again, which is slower but never fills the disk.
 */
final class IndexCache {

	/** Bytes of the random value that marks the pieces of one file. */
	private const int MARK_BYTES = 16;

	/** No sealed piece is larger than this; export() makes pieces of about one megabyte plus one bucket. */
	private const int MAX_PIECE = 67108864;

	/**
	 * Uses a cache directory.
	 *
	 * @param string $directory cache/<repository-id> in the private directory.
	 * @param int    $limit     Largest cache file to keep, in bytes.
	 */
	public function __construct( private readonly string $directory, private readonly int $limit = PHP_INT_MAX ) {
	}

	/**
	 * Returns the current index of the repository.
	 *
	 * @param Repository $repository Open repository.
	 * @return IndexSet Index.
	 * @throws IndexTooLarge If the index does not fit into the repository's memory budget.
	 */
	public function load( Repository $repository ): IndexSet {
		$listed = array_map( 'strval', array_keys( $repository->backend()->list( ObjectType::Index ) ) );
		sort( $listed );
		$cached   = $this->read( $repository, $listed );
		$readable = null !== $cached;
		$index    = new IndexSet();
		$covered  = [];
		if ( null !== $cached ) {
			[ $index, $covered ] = $cached;
		}
		unset( $cached );
		$new = array_values( array_diff( $listed, $covered ) );
		foreach ( $new as $name ) {
			foreach ( IndexFile::packs( $repository->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
				$index->add_pack( Id::from_hex( $pack ), $entries );
				$repository->memory()?->check_index();
			}
		}
		if ( [] !== $new || ! $readable || count( $covered ) !== count( $listed ) ) {
			$this->write( $repository, $index, $listed );
		}
		// After the cache is written: an index that leaves no room is at least not read from
		// the index files again when the job is tried with more memory.
		$repository->memory()?->check_loaded_index();
		return $index;
	}

	/**
	 * Deletes the cache.
	 */
	public function clear(): void {
		Files::remove_tree( $this->directory );
	}

	/**
	 * Reads the cache.
	 *
	 * @param Repository   $repository Repository whose key encrypts the cache.
	 * @param list<string> $listed     Current index files; a missing covered file invalidates the cache.
	 * @return array{IndexSet, list<string>}|null Index and covered index file names, or null.
	 */
	private function read( Repository $repository, array $listed ): ?array {
		$file = $this->directory . '/index.bin';
		if ( is_file( $file ) && (int) filesize( $file ) > $this->limit ) {
			// Written under a higher limit than the one set now.
			$this->clear();
			return null;
		}
		$handle = is_file( $file ) ? fopen( $file, 'rb' ) : false;
		if ( false === $handle ) {
			return null;
		}
		try {
			$pieces = $this->pieces( $handle, $repository->cipher() );
			$names  = $pieces->valid() ? $pieces->current() : '';
			if ( 'N' !== ( $names[0] ?? '' ) || 1 !== strlen( $names ) % 32 ) {
				return null;
			}
			$covered = 1 === strlen( $names ) ? [] : array_map( 'bin2hex', str_split( substr( $names, 1 ), 32 ) );
			if ( [] !== array_diff( $covered, $listed ) ) {
				// Do not spend memory importing entries that cannot be used after a prune.
				return null;
			}
			unset( $names );
			$pieces->next();
			$memory = $repository->memory();
			$rest   = static function () use ( $pieces, $memory ): \Generator {
				while ( $pieces->valid() ) {
					yield $pieces->current();
					$memory?->check_index();
					$pieces->next();
				}
			};
			$index  = IndexSet::import( $rest() );
			return [ $index, $covered ];
		} catch ( AuthenticationFailed | CryptoException | FormatException ) {
			return null;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Reads, opens and checks the pieces of a cache file one after another.
	 *
	 * @param resource $handle Open cache file.
	 * @param Cipher   $cipher Cipher with the repository key.
	 * @return \Generator<int, string> The content of each piece, without mark and number.
	 * @throws FormatException If the file is truncated or its pieces do not belong together.
	 * @throws AuthenticationFailed If a piece was changed.
	 */
	private function pieces( $handle, Cipher $cipher ): \Generator {
		$mark = null;
		for ( $number = 0; ; $number++ ) {
			$head = (string) fread( $handle, 4 );
			if ( '' === $head && 0 < $number ) {
				return;
			}
			$fields = 4 === strlen( $head ) ? unpack( 'Vlength', $head ) : false;
			$length = is_array( $fields ) ? $fields['length'] : 0;
			if ( $length < Cipher::OVERHEAD + self::MARK_BYTES + 4 || $length > self::MAX_PIECE ) {
				throw new FormatException( 'The cached index is damaged.' );
			}
			$sealed = '';
			for ( $missing = $length; $missing > 0; $missing -= strlen( $part ) ) {
				$part = fread( $handle, $missing );
				if ( false === $part || '' === $part ) {
					throw new FormatException( 'The cached index is truncated.' );
				}
				$sealed .= $part;
			}
			$plain = $cipher->open( $sealed );
			unset( $sealed );
			$mark ??= substr( $plain, 0, self::MARK_BYTES );
			if ( substr( $plain, 0, self::MARK_BYTES ) !== $mark || pack( 'V', $number ) !== substr( $plain, self::MARK_BYTES, 4 ) ) {
				throw new FormatException( 'The pieces of the cached index do not belong together.' );
			}
			yield substr( $plain, self::MARK_BYTES + 4 );
		}
	}

	/**
	 * Writes the cache.
	 *
	 * @param Repository   $repository Repository whose key encrypts the cache.
	 * @param IndexSet     $index      Index.
	 * @param list<string> $names      Index files it covers.
	 * @throws StorageFailed If the cache directory cannot be created or the file not written.
	 */
	private function write( Repository $repository, IndexSet $index, array $names ): void {
		if ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
			StorageFailed::raise( 'The cache directory could not be created.' );
		}
		$cipher = $repository->cipher();
		$mark   = $repository->random()->bytes( self::MARK_BYTES );
		$sealed = static function () use ( $cipher, $mark, $index, $names ): \Generator {
			$number = 0;
			$seal   = static function ( string $content ) use ( $cipher, $mark, &$number ): string {
				$piece = $cipher->seal( $mark . pack( 'V', $number++ ) . $content );
				return pack( 'V', strlen( $piece ) ) . $piece;
			};
			yield $seal( 'N' . implode( '', array_map( static fn ( string $name ): string => Id::from_hex( $name )->bytes(), $names ) ) );
			foreach ( $index->export() as $content ) {
				yield $seal( $content );
			}
		};
		if ( ! Files::write_atomic_pieces( $this->directory . '/index.bin', $sealed(), $this->limit ) ) {
			$this->clear();
		}
	}
}
