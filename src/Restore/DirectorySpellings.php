<?php
/**
 * Checkpointed name lookup for very wide directories during read-only file review.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * A directory's private table has 65,536 bucket heads. Each fixed-width record stores the
 * keyed hash of a folded name, the hash of its exact spelling and the previous record in
 * the same bucket. Readers can check a spelling without retaining a wide folder in RAM.
 * Only a read-only worker uses these indexes. Live publication enumerates the filesystem.
 *
 * An index holds one reading of a folder during which the folder did not change: a reading
 * begins after the second of the folder's last change, so that any later change moves the
 * folder's identity, which every lookup and the end of each tick's part of the reading compare.
 * A folder that changes before its reading is complete, within a tick or between two, is read
 * again from its first name, because names may have arrived in the part already read. Five
 * readings in a row that changes cut short end the reading: the folder keeps changing
 * (`file_folder_changing`, with the folder in the context). Before, the reading started again
 * as often as the folder changed between two ticks, so that the review of a folder that
 * changed between every two ticks never ended, and a change within a tick failed a preview.
 *
 * A folder that disappears, cannot be read, or keeps changing cannot be indexed. A preview and
 * the apply's own check fail then. An undo review answers that another spelling may exist, so
 * that the site policy keeps the entry with a reason and the review goes on with the next
 * one (ADR 0041); a folder that keeps changing is answered so for the rest of the review. A
 * damaged index fails in both.
 *
 * @phpstan-type Table array{identity:string,count:int,complete:bool,salt:string,heads:string,records:WorkFile,failed:int}
 */
final class DirectorySpellings {

	private const string MAGIC     = "BFRNAMES1\0";
	private const int HEAD_BYTES   = 262144;
	private const int RECORD_BYTES = 68;
	private const int MAX_NAMES    = 1000002; // Includes . and .. in the iterator.
	private const int MAX_CHAIN    = 512;

	/**
	 * Readings of a folder in a row that a change may cut short before the review gives up on it.
	 */
	private const int READINGS = 5;

	/**
	 * Context of a failure that is about the folder itself, not about its index.
	 */
	private const array UNSETTLED = [ 'directory' => 'unsettled' ];

	/**
	 * Up to four open private indexes for this worker.
	 *
	 * @var array<string,Table>
	 */
	private array $tables = [];

	/**
	 * Binds a new worker to a private plan directory and its deadline.
	 *
	 * @param string $workspace Existing canonical private directory.
	 * @param float  $deadline Worker deadline.
	 * @param bool   $undo Whether an undo review reads the names, which answers a folder that cannot be
	 *                     indexed instead of failing.
	 * @throws StorageFailed If the workspace is not canonical.
	 */
	public function __construct( private readonly string $workspace, private readonly float $deadline, private readonly bool $undo = false ) {
		if ( ! is_dir( $workspace ) || realpath( $workspace ) !== $workspace ) {
			throw new StorageFailed( 'The file preview name-index directory is unavailable.' );
		}
	}

	/**
	 * Checks whether earlier work already classified this directory as wide.
	 *
	 * @param string $directory Canonical source or live directory.
	 * @return bool Whether an index exists or is in progress.
	 */
	public function known( string $directory ): bool {
		return isset( $this->tables[ $directory ] ) || is_file( $this->prefix( $directory ) . '.head' );
	}

	/**
	 * Checks one spelling, resuming enumeration across requests when necessary.
	 *
	 * @param string $directory Existing canonical directory.
	 * @param string $name Exact sought name.
	 * @return bool Whether every existing name with this fold has the requested spelling; for an undo
	 *              review false also when the folder cannot be indexed.
	 * @throws FileWorkPending If further bounded work is required.
	 * @throws StorageFailed If the index is damaged, or outside an undo review if the folder disappears,
	 *                       cannot be read or changes mid-scan.
	 */
	public function unique( string $directory, string $name ): bool {
		try {
			$identity = hash( 'sha256', self::identity( $directory ), true );
			if ( ! isset( $this->tables[ $directory ] ) || $this->tables[ $directory ]['identity'] !== $identity ) {
				if ( count( $this->tables ) >= 4 ) {
					array_shift( $this->tables );
				}
				$this->tables[ $directory ] = $this->open( $directory, $identity );
			}
			$table = &$this->tables[ $directory ];
			if ( ! $table['complete'] ) {
				$this->advance( $directory, $table );
			}
		} catch ( StorageFailed $failure ) {
			if ( $this->undo && self::UNSETTLED['directory'] === ( $failure->context()['directory'] ?? null ) ) {
				// Another spelling may have been there: the entry is kept, and the review goes on.
				return false;
			}
			throw $failure;
		}
		$folded = hash_hmac( 'sha256', ApplyPlan::fold( $name ), $table['salt'], true );
		$exact  = hash( 'sha256', $name, true );
		$head   = Bytes::read_uint32_le( $table['heads'], self::slot( $folded ) );
		for ( $probe = 0; 0 !== $head && $probe < self::MAX_CHAIN; ++$probe ) {
			if ( $head > $table['count'] ) {
				throw new StorageFailed( 'A directory spelling lookup points outside its records.' );
			}
			$table['records']->seek( ( $head - 1 ) * self::RECORD_BYTES );
			$record = $table['records']->read( self::RECORD_BYTES );
			if ( self::RECORD_BYTES !== strlen( $record ) ) {
				throw new StorageFailed( 'A directory spelling record is incomplete.' );
			}
			if ( substr( $record, 0, 32 ) === $folded && substr( $record, 32, 32 ) !== $exact ) {
				return false;
			}
			$previous = Bytes::read_uint32_le( $record, 64 );
			if ( $previous >= $head ) {
				throw new StorageFailed( 'A directory spelling record has an invalid predecessor.' );
			}
			$head = $previous;
		}
		if ( 0 !== $head ) {
			throw new StorageFailed( 'A directory spelling lookup exceeds its bounded search.' );
		}
		return true;
	}

	/**
	 * The identity of a folder whose names are read.
	 *
	 * @param string $directory Folder.
	 * @return string Identity.
	 * @throws StorageFailed If the folder is gone.
	 */
	private static function identity( string $directory ): string {
		try {
			return ApplyManifest::identity( $directory );
		} catch ( StorageFailed $gone ) {
			StorageFailed::raise( 'A directory disappeared while its names were being reviewed.', self::UNSETTLED, $gone );
		}
	}

	/**
	 * Restores the table's exact position or starts a new generation after a directory edit.
	 * A generation that a change ended before it was complete counts as a reading cut short,
	 * and a folder whose last readings were all cut short gets no other one.
	 *
	 * @param string $directory Indexed folder.
	 * @param string $identity Hash of stable metadata.
	 * @return Table Private working state.
	 * @throws StorageFailed If a private component or cursor is invalid, or with the code
	 *                       `file_folder_changing` if the folder keeps changing.
	 */
	private function open( string $directory, string $identity ): array {
		$prefix  = $this->prefix( $directory );
		$marker  = $prefix . '.head';
		$records = $prefix . '.names';
		foreach ( [ $marker, $records ] as $file ) {
			clearstatcache( true, $file );
			if ( is_link( $file ) || ( file_exists( $file ) && ( realpath( $file ) !== $file || ! is_file( $file ) ) ) ) {
				throw new StorageFailed( 'A directory spelling index is no longer a private regular file.' );
			}
		}
		$file         = new WorkFile( $records, ! file_exists( $records ) );
		$header_bytes = strlen( self::MAGIC ) + 32 + 16 + 4 + 1;
		$failed       = 0;
		if ( is_file( $marker ) ) {
			$body = (string) file_get_contents( $marker, false, null, 0, $header_bytes + self::HEAD_BYTES + 2 );
			// The number of readings cut short follows the bucket heads. A checkpoint that an
			// earlier version wrote ends with the heads and counts none.
			$tail = substr( $body, $header_bytes + self::HEAD_BYTES );
			$cut  = '' === $tail ? 0 : ord( $tail );
			if ( strlen( $body ) < $header_bytes + self::HEAD_BYTES || strlen( $tail ) > 1 || $cut >= self::READINGS || ! str_starts_with( $body, self::MAGIC ) || ! in_array( $body[ $header_bytes - 1 ], [ "\0", "\1" ], true ) ) {
				throw new StorageFailed( 'The checkpoint of a directory spelling index is invalid.' );
			}
			$complete = "\1" === $body[ $header_bytes - 1 ];
			if ( substr( $body, strlen( self::MAGIC ), 32 ) === $identity ) {
				$count = Bytes::read_uint32_le( $body, $header_bytes - 5 );
				if ( $count > self::MAX_NAMES || $file->size() < $count * self::RECORD_BYTES ) {
					throw new StorageFailed( 'The directory spelling cursor exceeds its stored records.' );
				}
				return [
					'identity' => $identity,
					'count'    => $count,
					'complete' => $complete,
					'salt'     => substr( $body, strlen( self::MAGIC ) + 32, 16 ),
					'heads'    => substr( $body, $header_bytes, self::HEAD_BYTES ),
					'records'  => $file,
					'failed'   => $cut,
				];
			}
			// The folder changed since this generation began; a complete reading was no failed one.
			$failed = $complete ? 0 : $cut + 1;
		}
		if ( $failed >= self::READINGS ) {
			StorageFailed::raise(
				'A folder kept changing while its names were being reviewed.',
				self::UNSETTLED + [
					'code'   => 'file_folder_changing',
					'folder' => $directory,
				]
			);
		}
		$table = [
			'identity' => $identity,
			'count'    => 0,
			'complete' => false,
			'salt'     => random_bytes( 16 ),
			'heads'    => str_repeat( "\0", self::HEAD_BYTES ),
			'records'  => $file,
			'failed'   => $failed,
		];
		$this->save( $directory, $table );
		$file->truncate( 0 );
		return $table;
	}

	/**
	 * Commits new records before their header, so replay overwrites an interrupted tail.
	 * Starting after the directory's ctime second makes renames visible between workers.
	 *
	 * @param string $directory Indexed folder.
	 * @param Table  $table Open table.
	 * @throws FileWorkPending If this tick reaches its deadline, or the folder changed since the reading began.
	 * @throws StorageFailed If the folder or bounded table is invalid.
	 */
	private function advance( string $directory, array &$table ): void {
		$ctime = @filectime( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A folder that is gone is reported below.
		if ( false === $ctime || $ctime > time() + 1 ) {
			StorageFailed::raise( 'The directory change timestamp is unavailable or ahead of the worker clock.', self::UNSETTLED );
		}
		if ( time() <= $ctime ) {
			$delay = min( $this->deadline, $ctime + 1.0 ) - microtime( true );
			if ( $delay > 0.0 ) {
				usleep( (int) ( min( $delay, 1.0 ) * 1000000 ) );
			}
			if ( time() <= $ctime ) {
				throw new FileWorkPending();
			}
		}
		try {
			$entries = new \DirectoryIterator( $directory );
		} catch ( \UnexpectedValueException $unreadable ) {
			StorageFailed::raise( 'A directory could not be read while its names were being reviewed.', self::UNSETTLED, $unreadable );
		}
		if ( $table['count'] > 0 ) {
			// Reopening an iterator must recover the same boundary on the host
			// filesystem. Compare exact bytes through the previous committed record.
			$last = $table['count'] - 1;
			try {
				$entries->seek( $last );
			} catch ( \OutOfBoundsException $shorter ) {
				$this->interrupted( $directory, $table );
				StorageFailed::raise( 'A directory enumeration changed between file preview workers.', self::UNSETTLED, $shorter );
			}
			$table['records']->seek( $last * self::RECORD_BYTES + 32 );
			if ( ! $entries->valid() || $table['records']->read( 32 ) !== hash( 'sha256', $entries->getFilename(), true ) ) {
				$this->interrupted( $directory, $table );
				StorageFailed::raise( 'A directory enumeration changed between file preview workers.', self::UNSETTLED );
			}
			$entries->next();
		}
		$table['records']->seek( $table['count'] * self::RECORD_BYTES );
		while ( $entries->valid() ) {
			if ( $table['count'] >= self::MAX_NAMES ) {
				InvalidArgument::raise( 'A single directory exceeds the file preview limit of one million names.', [ 'code' => 'file_plan_too_large' ] );
			}
			$name   = $entries->getFilename();
			$folded = hash_hmac( 'sha256', ApplyPlan::fold( $name ), $table['salt'], true );
			$slot   = self::slot( $folded );
			$table['records']->write( $folded . hash( 'sha256', $name, true ) . substr( $table['heads'], $slot, 4 ) );
			$pointer = Bytes::uint32_le( ++$table['count'] );
			for ( $byte = 0; $byte < 4; ++$byte ) {
				$table['heads'][ $slot + $byte ] = $pointer[ $byte ];
			}
			$entries->next();
			if ( 0 === $table['count'] % 64 && microtime( true ) >= $this->deadline ) {
				break;
			}
		}
		$this->interrupted( $directory, $table );
		$table['complete'] = ! $entries->valid();
		$table['records']->flush();
		$this->save( $directory, $table );
		if ( ! $table['complete'] ) {
			throw new FileWorkPending();
		}
	}

	/**
	 * Ends this tick's part of a reading if the folder changed since the reading began, also
	 * after this tick looked at it: at the end of the part, and where the iterator no longer
	 * reaches the last name that the checkpoint holds, because names were removed or renamed in
	 * the meantime. What this tick read is not saved: the next lookup finds the folder's new
	 * identity, counts this reading as cut short and begins another one. Before, such a change
	 * at the boundary failed the review at once. A boundary that does not match a folder whose
	 * identity is unchanged is a damaged index, which the caller reports.
	 *
	 * @param string $directory Indexed folder.
	 * @param Table  $table Open table.
	 * @throws FileWorkPending If the folder's identity is no longer the reading's.
	 * @throws StorageFailed If the folder is gone.
	 */
	private function interrupted( string $directory, array $table ): void {
		if ( hash( 'sha256', self::identity( $directory ), true ) !== $table['identity'] ) {
			unset( $this->tables[ $directory ] );
			throw new FileWorkPending();
		}
	}

	/**
	 * Atomically publishes the bucket heads and matching committed record count.
	 *
	 * @param string $directory Directory key.
	 * @param Table  $table Working state.
	 */
	private function save( string $directory, array $table ): void {
		Files::write_atomic( $this->prefix( $directory ) . '.head', self::MAGIC . $table['identity'] . $table['salt'] . Bytes::uint32_le( $table['count'] ) . ( $table['complete'] ? "\1" : "\0" ) . $table['heads'] . chr( $table['failed'] ) );
	}

	/**
	 * A fixed-length private filename independent of the source's raw filesystem bytes.
	 *
	 * @param string $directory Indexed folder.
	 * @return string Private file prefix.
	 */
	private function prefix( string $directory ): string {
		return $this->workspace . '/spell-' . hash( 'sha256', $directory );
	}

	/**
	 * Finds a four-byte head by the first 16 bits of the keyed name digest.
	 *
	 * @param string $folded Binary digest.
	 * @return int Byte offset into the head vector.
	 */
	private static function slot( string $folded ): int {
		return ( ord( $folded[0] ) * 256 + ord( $folded[1] ) ) * 4;
	}
}
