<?php
/**
 * A zip archive of one directory tree, written in resumable calls.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Storage\Zip;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the files and directories below one directory to a zip archive without compressing
 * them, so that the plugin needs neither the zip extension nor memory for more than one
 * piece of a file.
 *
 * The work is done in calls of advance(), each of which stops at a deadline. Everything a
 * later request needs is in files beside the archive, named after it:
 *
 * - "<archive>.part" is the archive while it is written. The archive gets its own name only
 *   when its end record is written, so a file with that name is always complete. A file
 *   with that name and no checkpoint beside it is taken for the finished archive whatever
 *   it holds: the caller gives every build a name of its own or removes an earlier archive
 *   before it builds another one under the same name.
 * - "<archive>.entries" collects the records of the central directory, which stands at the
 *   end of an archive, so that they need not be kept in memory.
 * - "<archive>.state" is the checkpoint: how many bytes of the two files count, the last
 *   entry that is finished, and for a file that is being copied its place and the CRC-32 of
 *   what is copied. It is replaced atomically, at least once a second and at the end of
 *   every call. Bytes beyond the checkpoint are cut off when the work goes on, so a request
 *   that was killed at any point loses at most the work since the last checkpoint. A
 *   checkpoint whose working files are missing or shorter than it counts, as a removal cut
 *   short or a crash of the machine leaves them, counts as none.
 *
 * The tree is walked depth first with the names of each directory in byte order, which makes
 * the archive the same however the calls are cut. A directory gets an entry of its own, so
 * an empty one survives. The tree may hold directories and regular files with one link
 * only: every entry is inspected with lstat() before it is read, and a symbolic link or
 * anything else ends the work, as does a name that ZipRecords::name() refuses. The tree must
 * not change while it is archived; a file whose size or time changed is reported.
 *
 * The caller names the tree and the archive and keeps two requests from working on the same
 * archive at once, as the lock of the job runner does.
 *
 * @phpstan-type Entry array{path: string, size: int, mtime: int, header: int, copied: int, crc: int}
 * @phpstan-type State array{phase: 'entries'|'directory'|'complete', offset: int, central: int, entries: int, cursor: string, file: Entry|null, directory: int, copied: int}
 * @phpstan-type Found array{directory: bool, size: int, mtime: int, dev: int, ino: int}
 */
final class ZipWriter {

	/**
	 * Layout number of the checkpoint.
	 */
	private const int VERSION = 1;

	/**
	 * Largest checkpoint that is read: two names of the longest kind, escaped, and the numbers.
	 */
	private const int STATE_LIMIT = 1048576;

	/**
	 * Seconds of work that a kill may cost.
	 */
	private const float CHECKPOINT_INTERVAL = 1.0;

	/**
	 * Builds the records.
	 *
	 * @var ZipRecords
	 */
	private readonly ZipRecords $records;

	/**
	 * Time source in seconds.
	 *
	 * @var \Closure(): float
	 */
	private readonly \Closure $now;

	/**
	 * What the checkpoint says, with the work of this call.
	 *
	 * @var State
	 */
	private array $state = self::INITIAL;

	/**
	 * The directories from the root to the one being read, each with its names in order and
	 * the number of names that are done.
	 *
	 * @var list<array{path: string, names: list<string>, index: int}>
	 */
	private array $stack = [];

	/**
	 * The file that is being copied, while it is open.
	 *
	 * @var resource|null
	 */
	private $input = null;

	/**
	 * The checkpoint of an archive nothing was written to.
	 */
	private const array INITIAL = [
		'phase'     => 'entries',
		'offset'    => 0,
		'central'   => 0,
		'entries'   => 0,
		'cursor'    => '',
		'file'      => null,
		'directory' => 0,
		'copied'    => 0,
	];

	/**
	 * Names the tree and the archive.
	 *
	 * @param string                   $source     Directory whose content is archived.
	 * @param string                   $target     Archive file, in an existing directory outside the tree.
	 * @param int                      $chunk_size Largest piece of a file held in memory, in bytes.
	 * @param ZipRecords|null          $records    Record builder; one with the limits of the format by default.
	 * @param (\Closure(): float)|null $now        Time source; microtime(true) by default.
	 */
	public function __construct( private readonly string $source, private readonly string $target, private readonly int $chunk_size = 1048576, ?ZipRecords $records = null, ?\Closure $now = null ) {
		$this->records = $records ?? new ZipRecords();
		$this->now     = $now ?? static fn (): float => microtime( true );
	}

	/**
	 * Tells whether the archive exists completely.
	 *
	 * @return bool True once the archive has its name and no working file is left.
	 */
	public function is_complete(): bool {
		return self::exists( $this->target ) && ! self::exists( $this->target . '.state' );
	}

	/**
	 * Reports how far the archive is.
	 *
	 * @return array{entries: int, bytes: int} Finished entries and bytes of the archive written so far, as the checkpoint counts them; zero before the first call and once the archive is complete.
	 */
	public function progress(): array {
		$state = $this->read_state() ?? self::INITIAL;
		return [
			'entries' => $state['entries'],
			'bytes'   => $state['offset'],
		];
	}

	/**
	 * Removes the unfinished archive and its working files. A complete archive stays. The
	 * checkpoint goes first, so that a request killed here leaves no checkpoint without its
	 * files.
	 *
	 * @throws StorageFailed If a file cannot be removed.
	 */
	public function discard(): void {
		foreach ( [ '.state', '.part', '.entries' ] as $suffix ) {
			self::remove( $this->target . $suffix );
		}
	}

	/**
	 * Works on the archive until it is complete or the deadline has passed. Every call makes
	 * some progress, also one whose deadline has passed already.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return bool True when the archive is complete, false when it needs another call.
	 * @throws InvalidArgument If the tree holds a link, a special file or a name that cannot be stored, or the archive would lie in the tree.
	 * @throws StorageFailed If a file cannot be read or written, or the tree changed.
	 */
	public function advance( float $deadline ): bool {
		if ( $this->is_complete() ) {
			return true;
		}
		$this->check_places();
		$this->state = $this->read_state() ?? $this->begin();
		if ( ! $this->backed() ) {
			$this->state = $this->begin();
		}
		if ( 'complete' === $this->state['phase'] ) {
			return $this->publish();
		}
		$archive = new WorkFile( $this->target . '.part' );
		$spool   = new WorkFile( $this->target . '.entries' );
		try {
			self::cut( $archive, $this->state['offset'] );
			self::cut( $spool, $this->state['central'] );
			$this->position();
			$saved = ( $this->now )();
			do {
				$this->unit( $archive, $spool );
				$time = ( $this->now )();
				if ( $time - $saved >= self::CHECKPOINT_INTERVAL ) {
					$this->save( $archive, $spool );
					$saved = $time;
				}
			} while ( 'complete' !== $this->state['phase'] && $time < $deadline );
			$this->save( $archive, $spool );
			$archive->close();
			$spool->close();
		} finally {
			$this->close_input();
		}
		return 'complete' === $this->state['phase'] && $this->publish();
	}

	/**
	 * Does one piece of work: an entry for a directory, the header of a file, a piece of a
	 * file, or a piece of the central directory.
	 *
	 * @param WorkFile $archive The archive being written.
	 * @param WorkFile $spool   The records of the central directory.
	 * @throws InvalidArgument If the next entry cannot be archived.
	 * @throws StorageFailed If a file cannot be read or written.
	 */
	private function unit( WorkFile $archive, WorkFile $spool ): void {
		if ( 'directory' === $this->state['phase'] ) {
			$this->copy_directory( $archive, $spool );
			return;
		}
		if ( null !== $this->state['file'] ) {
			$this->copy_file( $this->state['file'], $archive, $spool );
			return;
		}
		$relative = $this->next();
		if ( null === $relative ) {
			$this->state['phase']     = 'directory';
			$this->state['directory'] = $this->state['offset'];
			return;
		}
		$found = $this->inspect( $relative );
		$name  = ZipRecords::name( $relative, $found['directory'] );
		if ( ! $found['directory'] ) {
			// The checksum is filled in when the content is copied, so a file is read once.
			$local               = $this->records->local( $name, $found['size'], $found['mtime'], 0 );
			$this->state['file'] = [
				'path'   => $relative,
				'size'   => $found['size'],
				'mtime'  => $found['mtime'],
				'header' => $this->state['offset'],
				'copied' => 0,
				'crc'    => 0,
			];
			$archive->write( $local );
			$this->state['offset'] += strlen( $local );
			return;
		}
		$local   = $this->records->local( $name, 0, $found['mtime'], 0 );
		$central = $this->records->central( $name, 0, $found['mtime'], 0, $this->state['offset'], true );
		$archive->write( $local );
		$spool->write( $central );
		$this->state['offset']  += strlen( $local );
		$this->state['central'] += strlen( $central );
		$this->state['cursor']   = $relative;
		++$this->state['entries'];
		$this->stack[] = [
			'path'  => $relative,
			'names' => $this->names( $relative ),
			'index' => 0,
		];
	}

	/**
	 * Copies the next piece of a file and finishes its entry after the last one.
	 *
	 * @param Entry    $file    The file being copied.
	 * @param WorkFile $archive The archive being written.
	 * @param WorkFile $spool   The records of the central directory.
	 * @throws InvalidArgument If the file was replaced by something that is not a file.
	 * @throws StorageFailed If the file changed or cannot be read, or the archive cannot be written.
	 */
	private function copy_file( array $file, WorkFile $archive, WorkFile $spool ): void {
		$input = $this->input( $file );
		$left  = $file['size'] - $file['copied'];
		if ( $left > 0 ) {
			$piece  = fread( $input, max( 1, min( $left, $this->chunk_size ) ) );
			$length = false === $piece ? 0 : strlen( $piece );
			if ( false === $piece || 0 === $length ) {
				$this->changed( $file['path'] );
			}
			$archive->write( $piece );
			$file['crc']            = Crc32::combine( $file['crc'], crc32( $piece ), $length );
			$file['copied']        += $length;
			$this->state['offset'] += $length;
			$this->state['file']    = $file;
			if ( $file['copied'] < $file['size'] ) {
				return;
			}
		}
		$stat = fstat( $input );
		if ( false === $stat || $stat['size'] !== $file['size'] ) {
			$this->changed( $file['path'] );
		}
		$this->close_input();
		$archive->seek( $file['header'] + ZipRecords::CRC_OFFSET );
		$archive->write( pack( 'V', $file['crc'] ) );
		$archive->seek( $this->state['offset'] );
		$central = $this->records->central( ZipRecords::name( $file['path'], false ), $file['size'], $file['mtime'], $file['crc'], $file['header'], false );
		$spool->write( $central );
		$this->state['central'] += strlen( $central );
		$this->state['cursor']   = $file['path'];
		$this->state['file']     = null;
		++$this->state['entries'];
	}

	/**
	 * Copies the next piece of the central directory to the end of the archive, and writes
	 * the end records after the last one.
	 *
	 * @param WorkFile $archive The archive being written.
	 * @param WorkFile $spool   The records of the central directory.
	 * @throws StorageFailed If a file cannot be read or written.
	 */
	private function copy_directory( WorkFile $archive, WorkFile $spool ): void {
		$left = $this->state['central'] - $this->state['copied'];
		if ( $left > 0 ) {
			$spool->seek( $this->state['copied'] );
			$piece  = $spool->read( max( 1, min( $left, $this->chunk_size ) ) );
			$length = strlen( $piece );
			if ( 0 === $length ) {
				StorageFailed::raise( 'A working file of the zip archive is shorter than its checkpoint.', [ 'file' => basename( $this->target ) ] );
			}
			$archive->write( $piece );
			$this->state['copied'] += $length;
			$this->state['offset'] += $length;
			if ( $this->state['copied'] < $this->state['central'] ) {
				return;
			}
		}
		$end = $this->records->end( $this->state['entries'], $this->state['central'], $this->state['directory'] );
		$archive->write( $end );
		$this->state['offset'] += strlen( $end );
		$this->state['phase']   = 'complete';
	}

	/**
	 * Returns the next path of the walk and moves the walk past it.
	 *
	 * @return string|null Path relative to the tree; null when every entry is done.
	 */
	private function next(): ?string {
		for ( $top = count( $this->stack ) - 1; $top >= 0; $top = count( $this->stack ) - 1 ) {
			$frame = $this->stack[ $top ];
			if ( $frame['index'] < count( $frame['names'] ) ) {
				++$this->stack[ $top ]['index'];
				$name = $frame['names'][ $frame['index'] ];
				return '' === $frame['path'] ? $name : $frame['path'] . '/' . $name;
			}
			array_pop( $this->stack );
		}
		return null;
	}

	/**
	 * Puts the walk where the checkpoint says it is: behind the last finished entry, or
	 * behind the file that is being copied.
	 *
	 * @throws InvalidArgument If a directory on the way was replaced by a link.
	 * @throws StorageFailed If a directory on the way cannot be read.
	 */
	private function position(): void {
		$this->stack = [];
		$file        = $this->state['file'];
		$path        = null === $file ? $this->state['cursor'] : $file['path'];
		$directory   = '';
		foreach ( '' === $path ? [] : explode( '/', $path ) as $part ) {
			$names = $this->names( $directory );
			$index = 0;
			foreach ( $names as $name ) {
				$index += strcmp( $name, $part ) <= 0 ? 1 : 0;
			}
			$this->stack[] = [
				'path'  => $directory,
				'names' => $names,
				'index' => $index,
			];
			$directory     = '' === $directory ? $part : $directory . '/' . $part;
		}
		// The last finished entry is a directory whose content comes next, or the walk starts.
		$found = null === $file && '' !== $path ? $this->find( $path ) : null;
		if ( null === $file && ( '' === $path || ( null !== $found && $found['directory'] ) ) ) {
			$this->stack[] = [
				'path'  => $path,
				'names' => $this->names( $path ),
				'index' => 0,
			];
		}
	}

	/**
	 * Lists a directory of the tree in byte order, which does not depend on the locale.
	 *
	 * @param string $relative Directory relative to the tree; empty for the tree itself.
	 * @return list<string> Names without the two dot entries.
	 * @throws InvalidArgument If the directory is a link or a special file.
	 * @throws StorageFailed If it is not a directory or cannot be read.
	 */
	private function names( string $relative ): array {
		$names = $this->inspect( $relative )['directory'] ? scandir( $this->path( $relative ), SCANDIR_SORT_NONE ) : false;
		if ( false === $names ) {
			$this->changed( $relative );
		}
		$names = array_values( array_diff( $names, [ '.', '..' ] ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Opens the file that is being copied at the place the checkpoint names.
	 *
	 * @param Entry $file The file being copied.
	 * @return resource Handle that reads the next piece.
	 * @throws InvalidArgument If the file was replaced by something that is not a file.
	 * @throws StorageFailed If the file changed since its header was written or cannot be opened.
	 */
	private function input( array $file ) {
		if ( null !== $this->input ) {
			return $this->input;
		}
		$found = $this->inspect( $file['path'] );
		if ( $found['directory'] || $found['size'] !== $file['size'] || $found['mtime'] !== $file['mtime'] ) {
			$this->changed( $file['path'] );
		}
		$handle = fopen( $this->path( $file['path'] ), 'rb' );
		if ( false === $handle ) {
			$this->changed( $file['path'] );
		}
		// What was opened must be what was inspected, not something put there in between.
		$stat = fstat( $handle );
		if ( false === $stat || $stat['dev'] !== $found['dev'] || $stat['ino'] !== $found['ino'] || 0 !== fseek( $handle, $file['copied'] ) ) {
			fclose( $handle );
			$this->changed( $file['path'] );
		}
		$this->input = $handle;
		return $handle;
	}

	/**
	 * Closes the file that is being copied.
	 */
	private function close_input(): void {
		if ( null !== $this->input ) {
			fclose( $this->input );
			$this->input = null;
		}
	}

	/**
	 * Inspects an entry of the tree that must exist.
	 *
	 * @param string $relative Path relative to the tree; empty for the tree itself.
	 * @return Found What the entry is.
	 * @throws InvalidArgument If the entry is neither a directory nor a regular file with one link.
	 * @throws StorageFailed If the entry is gone.
	 */
	private function inspect( string $relative ): array {
		return $this->find( $relative ) ?? $this->changed( $relative );
	}

	/**
	 * Inspects an entry of the tree without following a link.
	 *
	 * @param string $relative Path relative to the tree; empty for the tree itself.
	 * @return Found|null What the entry is; null if there is none.
	 * @throws InvalidArgument If the entry is neither a directory nor a regular file with one link.
	 */
	private function find( string $relative ): ?array {
		$path = $this->path( $relative );
		$stat = self::exists( $path ) ? lstat( $path ) : false;
		if ( false === $stat ) {
			return null;
		}
		$type = $stat['mode'] & 0170000;
		if ( 0040000 !== $type && ( 0100000 !== $type || 1 !== $stat['nlink'] ) ) {
			InvalidArgument::raise( 'The directory to archive holds a link or a special file.', [ 'name' => substr( rawurlencode( $relative ), 0, 300 ) ] );
		}
		return [
			'directory' => 0040000 === $type,
			'size'      => $stat['size'],
			'mtime'     => $stat['mtime'],
			'dev'       => $stat['dev'],
			'ino'       => $stat['ino'],
		];
	}

	/**
	 * Refuses an archive inside the tree, which would become an entry of itself.
	 *
	 * @throws InvalidArgument If the tree is not a directory or holds the archive.
	 */
	private function check_places(): void {
		$tree   = realpath( $this->source );
		$folder = realpath( dirname( $this->target ) );
		if ( false === $tree || false === $folder || is_link( $this->source ) || ! is_dir( $tree ) || $folder === $tree || str_starts_with( $folder, $tree . DIRECTORY_SEPARATOR ) ) {
			InvalidArgument::raise( 'A zip archive needs an existing directory to read and a place outside it to be written to.' );
		}
	}

	/**
	 * Starts an archive: empty working files and the first checkpoint, in this order, so
	 * that a checkpoint never names files of an earlier attempt.
	 *
	 * @return State The first checkpoint.
	 * @throws StorageFailed If a file cannot be created.
	 */
	private function begin(): array {
		$this->discard();
		( new WorkFile( $this->target . '.part', true ) )->close();
		( new WorkFile( $this->target . '.entries', true ) )->close();
		$this->state = self::INITIAL;
		$this->write_state();
		return $this->state;
	}

	/**
	 * Makes the work so far count: the bytes reach the files, then the checkpoint names them.
	 *
	 * @param WorkFile $archive The archive being written.
	 * @param WorkFile $spool   The records of the central directory.
	 * @throws StorageFailed If a file cannot be written.
	 */
	private function save( WorkFile $archive, WorkFile $spool ): void {
		$archive->flush();
		$spool->flush();
		$this->write_state();
	}

	/**
	 * Gives the finished archive its name and removes the working files. The checkpoint goes
	 * last, so that a request killed here does the rest again.
	 *
	 * @return bool True if the archive exists under its name.
	 * @throws StorageFailed If a file cannot be renamed or removed.
	 */
	private function publish(): bool {
		if ( self::exists( $this->target . '.part' ) && ! rename( $this->target . '.part', $this->target ) ) {
			StorageFailed::raise( 'The finished zip archive could not be given its name.', [ 'file' => basename( $this->target ) ] );
		}
		self::remove( $this->target . '.entries' );
		self::remove( $this->target . '.state' );
		return self::exists( $this->target );
	}

	/**
	 * Tells whether the working files hold what the checkpoint counts. They do not after a
	 * removal that was cut short, or after a crash of the machine that kept the checkpoint
	 * and lost bytes written before it.
	 *
	 * @return bool True if the work can go on from the checkpoint.
	 */
	private function backed(): bool {
		$archive = self::size( $this->target . '.part' );
		if ( 'complete' === $this->state['phase'] ) {
			// Without the working file the archive has its name already, or there is none.
			return null === $archive ? self::exists( $this->target ) : $archive === $this->state['offset'];
		}
		$spool = self::size( $this->target . '.entries' );
		return null !== $archive && null !== $spool && $archive >= $this->state['offset'] && $spool >= $this->state['central'];
	}

	/**
	 * Returns the size of a working file.
	 *
	 * @param string $path Path.
	 * @return int|null Bytes; null if there is no regular file of that name.
	 */
	private static function size( string $path ): ?int {
		clearstatcache( true, $path );
		$size = is_file( $path ) && ! is_link( $path ) ? filesize( $path ) : false;
		return false === $size ? null : $size;
	}

	/**
	 * Cuts a working file back to the bytes the checkpoint counts and goes to its end.
	 *
	 * @param WorkFile $file   Working file.
	 * @param int      $length Bytes that count.
	 * @throws StorageFailed If the file is shorter than that or cannot be changed.
	 */
	private static function cut( WorkFile $file, int $length ): void {
		if ( $file->size() < $length ) {
			StorageFailed::raise( 'A working file of the zip archive is shorter than its checkpoint.' );
		}
		$file->truncate( $length );
		$file->seek( $length );
	}

	/**
	 * Writes the checkpoint.
	 *
	 * @throws StorageFailed If the file cannot be written.
	 */
	private function write_state(): void {
		$json = json_encode( [ 'version' => self::VERSION ] + $this->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			StorageFailed::raise( 'The checkpoint of the zip archive could not be written.', [ 'file' => basename( $this->target ) ] );
		}
		Files::write_atomic( $this->target . '.state', $json );
	}

	/**
	 * Reads the checkpoint. One that is missing, damaged or contradicts itself counts as
	 * none: the archive is derived from the tree and is then written again from the start.
	 *
	 * @return State|null The checkpoint, or null.
	 */
	private function read_state(): ?array {
		$path = $this->target . '.state';
		$json = self::exists( $path ) && is_file( $path ) ? file_get_contents( $path, false, null, 0, self::STATE_LIMIT + 1 ) : false;
		$data = false === $json || strlen( $json ) > self::STATE_LIMIT ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || self::VERSION !== ( $data['version'] ?? null ) ) {
			return null;
		}
		$phase  = $data['phase'] ?? null;
		$cursor = $data['cursor'] ?? null;
		$file   = $data['file'] ?? null;
		if ( ! in_array( $phase, [ 'entries', 'directory', 'complete' ], true ) || ! is_string( $cursor ) || ! self::storable( $cursor ) ) {
			return null;
		}
		$state = [
			'phase'     => $phase,
			'offset'    => self::natural( $data['offset'] ?? null ),
			'central'   => self::natural( $data['central'] ?? null ),
			'entries'   => self::natural( $data['entries'] ?? null ),
			'cursor'    => $cursor,
			'file'      => null,
			'directory' => self::natural( $data['directory'] ?? null ),
			'copied'    => self::natural( $data['copied'] ?? null ),
		];
		$sound = min( $state['offset'], $state['central'], $state['entries'], $state['directory'], $state['copied'] ) >= 0 && $state['copied'] <= $state['central'] && $state['directory'] <= $state['offset'];
		if ( is_array( $file ) ) {
			$name  = $file['path'] ?? null;
			$mtime = $file['mtime'] ?? null;
			$entry = [
				'path'   => is_string( $name ) ? $name : '',
				'size'   => self::natural( $file['size'] ?? null ),
				'mtime'  => is_int( $mtime ) ? $mtime : 0,
				'header' => self::natural( $file['header'] ?? null ),
				'copied' => self::natural( $file['copied'] ?? null ),
				'crc'    => self::natural( $file['crc'] ?? null ),
			];
			$sound = $sound && 'entries' === $phase && is_int( $mtime ) && '' !== $entry['path'] && self::storable( $entry['path'] ) && min( $entry['size'], $entry['header'], $entry['copied'], $entry['crc'] ) >= 0 && $entry['copied'] <= $entry['size'] && $entry['header'] < $state['offset'] && $entry['crc'] <= 0xFFFFFFFF;

			$state['file'] = $entry;
		} elseif ( null !== $file ) {
			$sound = false;
		}
		return $sound ? $state : null;
	}

	/**
	 * Tells whether a path from the checkpoint may be joined to the tree.
	 *
	 * @param string $relative Path from the checkpoint; empty before the first entry.
	 * @return bool True if it is empty or a name the archive may hold.
	 */
	private static function storable( string $relative ): bool {
		try {
			return '' === $relative || '' !== ZipRecords::name( $relative, false );
		} catch ( InvalidArgument ) {
			return false;
		}
	}

	/**
	 * Reads a count or an offset from the checkpoint.
	 *
	 * @param mixed $value Decoded value.
	 * @return int The value; -1 if it is not a whole number from zero up.
	 */
	private static function natural( mixed $value ): int {
		return is_int( $value ) && $value >= 0 ? $value : -1;
	}

	/**
	 * Returns the path of an entry of the tree.
	 *
	 * @param string $relative Path relative to the tree; empty for the tree itself.
	 * @return string Path in the file system.
	 */
	private function path( string $relative ): string {
		return '' === $relative ? $this->source : $this->source . '/' . $relative;
	}

	/**
	 * Tells whether a path names anything, also a link that leads nowhere.
	 *
	 * @param string $path Path.
	 * @return bool True if there is an entry.
	 */
	private static function exists( string $path ): bool {
		clearstatcache( true, $path );
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Removes a working file if it exists.
	 *
	 * @param string $path Path.
	 * @throws StorageFailed If the file cannot be removed.
	 */
	private static function remove( string $path ): void {
		if ( self::exists( $path ) && ! unlink( $path ) ) {
			StorageFailed::raise( 'A working file of the zip archive could not be removed.', [ 'file' => basename( $path ) ] );
		}
	}

	/**
	 * Reports that the tree is not what it was when the archive was started.
	 *
	 * @param string $relative Path relative to the tree.
	 * @throws StorageFailed Always.
	 */
	private function changed( string $relative ): never {
		StorageFailed::raise( 'A file changed or cannot be read while a zip archive is written.', [ 'name' => substr( rawurlencode( $relative ), 0, 300 ) ] );
	}
}
