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
 * @phpstan-type Table array{identity:string,count:int,complete:bool,salt:string,heads:string,records:WorkFile}
 */
final class DirectorySpellings {

	private const string MAGIC     = "BFRNAMES1\0";
	private const int HEAD_BYTES   = 262144;
	private const int RECORD_BYTES = 68;
	private const int MAX_NAMES    = 1000002; // Includes . and .. in the iterator.
	private const int MAX_CHAIN    = 512;

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
	 * @throws StorageFailed If the workspace is not canonical.
	 */
	public function __construct( private readonly string $workspace, private readonly float $deadline ) {
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
	 * @return bool Whether every existing name with this fold has the requested spelling.
	 * @throws FileWorkPending If further bounded work is required.
	 * @throws StorageFailed If the index is damaged or the directory changes mid-scan.
	 */
	public function unique( string $directory, string $name ): bool {
		$identity = hash( 'sha256', ApplyManifest::identity( $directory ), true );
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
	 * Restores the table's exact position or starts a new generation after a directory edit.
	 *
	 * @param string $directory Indexed folder.
	 * @param string $identity Hash of stable metadata.
	 * @return Table Private working state.
	 * @throws StorageFailed If a private component or cursor is invalid.
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
		if ( is_file( $marker ) ) {
			$body = file_get_contents( $marker, false, null, 0, $header_bytes + self::HEAD_BYTES + 1 );
			if ( ! is_string( $body ) || strlen( $body ) !== $header_bytes + self::HEAD_BYTES || ! str_starts_with( $body, self::MAGIC ) ) {
				throw new StorageFailed( 'The checkpoint of a directory spelling index is invalid.' );
			}
			if ( substr( $body, strlen( self::MAGIC ), 32 ) === $identity ) {
				$count = Bytes::read_uint32_le( $body, $header_bytes - 5 );
				$flag  = $body[ $header_bytes - 1 ];
				if ( $count > self::MAX_NAMES || $file->size() < $count * self::RECORD_BYTES || ! in_array( $flag, [ "\0", "\1" ], true ) ) {
					throw new StorageFailed( 'The directory spelling cursor exceeds its stored records.' );
				}
				return [
					'identity' => $identity,
					'count'    => $count,
					'complete' => "\1" === $flag,
					'salt'     => substr( $body, strlen( self::MAGIC ) + 32, 16 ),
					'heads'    => substr( $body, $header_bytes ),
					'records'  => $file,
				];
			}
		}
		$table = [
			'identity' => $identity,
			'count'    => 0,
			'complete' => false,
			'salt'     => random_bytes( 16 ),
			'heads'    => str_repeat( "\0", self::HEAD_BYTES ),
			'records'  => $file,
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
	 * @throws FileWorkPending If this tick reaches its deadline.
	 * @throws StorageFailed If the folder or bounded table is invalid.
	 */
	private function advance( string $directory, array &$table ): void {
		$ctime = filectime( $directory );
		if ( false === $ctime || $ctime > time() + 1 ) {
			throw new StorageFailed( 'The directory change timestamp is unavailable or ahead of the worker clock.' );
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
		$entries = new \DirectoryIterator( $directory );
		if ( $table['count'] > 0 ) {
			// Reopening an iterator must recover the same boundary on the host
			// filesystem. Compare exact bytes through the previous committed record.
			$last = $table['count'] - 1;
			$entries->seek( $last );
			$table['records']->seek( $last * self::RECORD_BYTES + 32 );
			if ( ! $entries->valid() || $table['records']->read( 32 ) !== hash( 'sha256', $entries->getFilename(), true ) ) {
				throw new StorageFailed( 'A directory enumeration changed between file preview workers.' );
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
		if ( hash( 'sha256', ApplyManifest::identity( $directory ), true ) !== $table['identity'] ) {
			throw new StorageFailed( 'A directory changed while its names were being reviewed.' );
		}
		$table['complete'] = ! $entries->valid();
		$table['records']->flush();
		$this->save( $directory, $table );
		if ( ! $table['complete'] ) {
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
		Files::write_atomic( $this->prefix( $directory ) . '.head', self::MAGIC . $table['identity'] . $table['salt'] . Bytes::uint32_le( $table['count'] ) . ( $table['complete'] ? "\1" : "\0" ) . $table['heads'] );
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
