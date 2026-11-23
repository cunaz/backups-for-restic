<?php
/**
 * The durable, paged list of the entries that an undo kept.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the entries that an undo kept one by one while the undo's job document counts them,
 * and seals them in the format that DiskFilePlan reads (summary version 2): page versions
 * named by the number of entries on them (`rows-0.3`), an index of page digests (`rows.idx`)
 * and a summary that authenticates the index.
 *
 * The job document is the one commit point, and it names what it counts: the number of entries
 * and the digest of the last page's version that holds them, which append() returns when it
 * writes that version. The list holds four invariants:
 *
 * - I1: at every moment the files on disk hold at least the entries that the document counts,
 *   and no write replaces a version that a count names. A retry writes the uncounted version
 *   again, the next entry a version with one more, and only versions below the counted one are
 *   removed.
 * - I2: the digest of a full page enters the index only once the document counts that page,
 *   and it is the digest that the document held, not one read from the file later. Every slot
 *   of the index is a digest that a saved document held.
 * - I3: sealing reads one page, the last counted one, and the index, whatever the length of
 *   the list.
 * - I4: a counted page that changed on disk after it was written is refused. The last page is
 *   checked against the document's digest by the seal, and by an append that builds on it;
 *   every other page is checked against its slot of the index when DiskFilePlan reads it.
 *
 * The entry at position c, while the document counts c entries with the digest d, is written
 * in this order: when c begins a page p > 0, the index gets slot p-1 = d unless it holds it
 * already, and is replaced as a whole on disk; the page's version with the new entry is written
 * on disk on top of the counted version, whose digest must be d; the version below the counted
 * one is removed; then the caller saves the document with c+1 and the new digest. A stop
 * between any two of these leaves either the earlier document with an index slot that it
 * names and an uncounted version, or the new document.
 *
 * @phpstan-import-type Row from ApplyManifest
 */
final class KeptList {

	private const int PAGE_BYTES  = 2097152;
	private const int INDEX_BYTES = 640000;

	/**
	 * Opens the list's existing canonical private directory.
	 *
	 * @param string                                $directory List directory.
	 * @param (\Closure(string, string): void)|null $observer Optional observer of each synchronisation, rename and
	 *                                                        removal, with the path, in order.
	 * @throws StorageFailed If the directory has become an alias.
	 */
	public function __construct( private readonly string $directory, private readonly ?\Closure $observer = null ) {
		if ( realpath( $directory ) !== $directory || ! is_dir( $directory ) ) {
			throw new StorageFailed( 'The private file plan directory is unavailable.' );
		}
	}

	/**
	 * Writes the entry after the ones that the job document counts, on disk, and returns the
	 * digest of the page version that holds it, for the document to save with the new count.
	 *
	 * @param int                 $count Entries that the document counts.
	 * @param string|null         $digest Digest of the counted version of the last page, as the
	 *                                    document holds it; null while it counts none.
	 * @param array<string,mixed> $row The kept entry.
	 * @return string Digest of the version written.
	 * @throws StorageFailed If the files do not hold what the document counts, or a write fails.
	 */
	public function append( int $count, ?string $digest, array $row ): string {
		self::require_position( $count, $digest );
		$page    = intdiv( $count, DiskFilePlan::PAGE );
		$slot    = $count % DiskFilePlan::PAGE;
		$records = [];
		if ( 0 !== $slot ) {
			$records = $this->counted( $page, $slot, (string) $digest );
		} elseif ( null !== $digest ) {
			// The document counts the full page before this one: its slot enters the index now.
			$this->require_slot( $page - 1, $digest );
		}
		$records[] = $row;
		$body      = json_encode( $records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( strlen( $body ) > self::PAGE_BYTES ) {
			throw new StorageFailed( 'A private file plan page exceeds its storage limit.' );
		}
		Files::write_durable( $this->directory . '/' . DiskFilePlan::page_version( 'rows', $page, $slot + 1 ), $body, $this->observer );
		$this->remove_obsolete( $count );
		return hash( 'sha256', $body );
	}

	/**
	 * Seals the entries that the job document counts. Only the last counted page is read, and
	 * checked against the digest that the document names; the index then holds the slots of
	 * the full pages, written as their pages were counted, and the last page's digest as its
	 * last slot. A seal after a stop of an earlier one writes the same summary again.
	 *
	 * @param string $subject Apply job.
	 * @param string $restore Staged restore of the apply.
	 * @param int    $count Entries that the document counts, at least one.
	 * @param string $digest Digest of the counted version of the last page.
	 * @return string Digest of the summary, to save in the job document.
	 * @throws StorageFailed If the files do not hold what the document counts, or a write fails.
	 */
	public function seal( string $subject, string $restore, int $count, string $digest ): string {
		self::require_position( $count, $digest );
		$pages = intdiv( $count - 1, DiskFilePlan::PAGE ) + 1;
		$this->counted( $pages - 1, $count - ( $pages - 1 ) * DiskFilePlan::PAGE, $digest );
		$index = $this->index();
		$slots = intdiv( strlen( $index ), 64 );
		if ( $slots === $pages - 1 ) {
			$index .= $digest;
			Files::write_durable( $this->directory . '/rows.idx', $index, $this->observer );
		} elseif ( $slots !== $pages || ! hash_equals( substr( $index, ( $pages - 1 ) * 64 ), $digest ) ) {
			throw new StorageFailed( 'The index of the kept entries does not hold the pages that the job counts.' );
		}
		$header = [
			'version' => 2,
			'subject' => $subject,
			'restore' => $restore,
			'undo'    => true,
			'config'  => false,
			'entries' => $count,
			'changes' => 0,
			'refused' => 0,
			'counts'  => [ 'rows' => $count ],
			'indexes' => [ 'rows' => hash( 'sha256', $index ) ],
			'actions' => [ 'keep' => $count ],
		];
		$body   = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		Files::write_durable( $this->directory . '/sealed.json', $body, $this->observer );
		return hash( 'sha256', $body );
	}

	/**
	 * Requires a count and the digest that a document holds with it.
	 *
	 * @param int         $count Counted entries.
	 * @param string|null $digest Digest of the counted version of the last page.
	 * @throws StorageFailed If they do not belong together.
	 */
	private static function require_position( int $count, ?string $digest ): void {
		$named = null !== $digest && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $digest );
		if ( $count < 0 || $count >= DiskFilePlan::MAX_ENTRIES || ( 0 === $count ) !== ( null === $digest ) || ( $count > 0 && ! $named ) ) {
			throw new StorageFailed( 'The job does not name the counted page of its kept entries.' );
		}
	}

	/**
	 * The entries of a counted page version, which must still have the digest that the job
	 * document holds for it.
	 *
	 * @param int    $page Page.
	 * @param int    $records Entries on the counted version.
	 * @param string $digest Digest that the document holds.
	 * @return list<array<string,mixed>> Entries.
	 * @throws StorageFailed If the version is missing, damaged or changed after it was written.
	 */
	private function counted( int $page, int $records, string $digest ): array {
		$body = self::read( $this->directory . '/' . DiskFilePlan::page_version( 'rows', $page, $records ), self::PAGE_BYTES );
		if ( ! hash_equals( $digest, hash( 'sha256', $body ) ) ) {
			throw new StorageFailed( 'A page of the kept entries changed after it was written.' );
		}
		$rows = json_decode( $body, true );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) !== $records ) {
			throw new StorageFailed( 'A page of the kept entries does not hold the entries that its name counts.' );
		}
		$entries = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || [] === $row ) {
				throw new StorageFailed( 'A page of the kept entries holds an invalid entry.' );
			}
			$entry = [];
			foreach ( $row as $name => $value ) {
				if ( ! is_string( $name ) ) {
					throw new StorageFailed( 'A page of the kept entries holds an invalid entry.' );
				}
				$entry[ $name ] = $value;
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Gives the index the slot of a full page that the document counts, with the digest that
	 * the document holds, unless the index holds that slot already, as after a stop that
	 * followed its write.
	 *
	 * @param int    $page Full page.
	 * @param string $digest Its digest, as the document holds it.
	 * @throws StorageFailed If the index holds other slots.
	 */
	private function require_slot( int $page, string $digest ): void {
		$index = $this->index();
		$slots = intdiv( strlen( $index ), 64 );
		if ( $slots === $page ) {
			Files::write_durable( $this->directory . '/rows.idx', $index . $digest, $this->observer );
		} elseif ( $slots !== $page + 1 || ! hash_equals( substr( $index, $page * 64 ), $digest ) ) {
			throw new StorageFailed( 'The index of the kept entries does not hold the pages that the job counts.' );
		}
	}

	/**
	 * The index of full pages as it is on disk: empty before the first page is full.
	 *
	 * @return string Packed digests.
	 * @throws StorageFailed If the index is not whole slots of digests.
	 */
	private function index(): string {
		$file = $this->directory . '/rows.idx';
		clearstatcache( true, $file );
		if ( ! file_exists( $file ) && ! is_link( $file ) ) {
			return '';
		}
		$index = self::read( $file, self::INDEX_BYTES );
		if ( 0 !== strlen( $index ) % 64 || 1 !== preg_match( '/\A[0-9a-f]*\z/', $index ) ) {
			throw new StorageFailed( 'The index of the kept entries is damaged.' );
		}
		return $index;
	}

	/**
	 * Removes the version that the counted one replaced: the one with an entry less on the
	 * page of the last counted entry, which is the page before when the count fills a page. A
	 * version above the counted one may be written again; one that a stop leaves behind is
	 * never read.
	 *
	 * @param int $count Entries that the document counts.
	 */
	private function remove_obsolete( int $count ): void {
		$slot = $count % DiskFilePlan::PAGE;
		$page = intdiv( $count, DiskFilePlan::PAGE );
		if ( $slot >= 2 ) {
			$file = DiskFilePlan::page_version( 'rows', $page, $slot - 1 );
		} elseif ( 0 === $slot && $page > 0 ) {
			$file = DiskFilePlan::page_version( 'rows', $page - 1, DiskFilePlan::PAGE - 1 );
		} else {
			return;
		}
		$path = $this->directory . '/' . $file;
		clearstatcache( true, $path );
		if ( is_file( $path ) && unlink( $path ) && null !== $this->observer ) {
			( $this->observer )( 'unlink', $path );
		}
	}

	/**
	 * Reads one bounded private file without following a link.
	 *
	 * @param string     $file File.
	 * @param int<0,max> $limit Maximum bytes.
	 * @return string Complete contents.
	 * @throws StorageFailed If the file is missing, oversized or not regular.
	 */
	private static function read( string $file, int $limit ): string {
		clearstatcache( true, $file );
		$text = ! is_link( $file ) && is_file( $file ) && realpath( $file ) === $file ? file_get_contents( $file, false, null, 0, $limit + 1 ) : false;
		if ( false === $text || strlen( $text ) > $limit ) {
			throw new StorageFailed( 'A file of the kept entries is missing or unreadable.' );
		}
		return $text;
	}
}
