<?php
/**
 * A bounded reader for one completed staged table dump.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Restore;

use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Only the writer's exact header and session preamble are accepted. Its DROP is consumed,
 * never executed. Each remaining line is a literal INSERT or the exact completion trailer.
 * The caller owns a private, immutable staging directory for the lifetime of the import.
 */
final class TableFile {

	/**
	 * Open regular file, or null after close.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Validated CREATE, redirected to the shadow.
	 *
	 * @var CreateStatement
	 */
	public readonly CreateStatement $create;

	/**
	 * Offset of the first INSERT, or the trailer of an empty table.
	 *
	 * @var int
	 */
	public readonly int $data_offset;

	/**
	 * File identity and header binding for a resumed import.
	 *
	 * @var string
	 */
	public readonly string $identity;

	/**
	 * Manifest's expected counts and table name.
	 *
	 * @var array{name: string, file: string, rows: int, bytes: int}
	 */
	private readonly array $table;

	/**
	 * Absolute path used to detect replacement while the handle is open.
	 *
	 * @var string
	 */
	private readonly string $path;

	/**
	 * Fingerprint of the initial regular-file metadata.
	 *
	 * @var string
	 */
	private readonly string $metadata;

	/**
	 * Opens and checks the writer's table header without executing any of it.
	 *
	 * @param string   $directory Resolved private staging directory, containing the dump files.
	 * @param Manifest $manifest Validated inventory.
	 * @param int      $number Table ordinal.
	 * @param string   $shadow Generated destination.
	 * @param string   $owner Import ownership comment.
	 * @throws FormatException If a path, count, header or definition differs from the format.
	 * @throws StorageFailed If a regular staged file cannot be read completely.
	 */
	public function __construct( string $directory, Manifest $manifest, int $number, private readonly string $shadow, string $owner ) {
		$directory = rtrim( $directory, '/\\' );
		if ( ! isset( $manifest->tables[ $number ] ) || is_link( $directory ) || realpath( $directory ) !== $directory ) {
			throw new FormatException( 'The database staging directory or table ordinal is invalid.' );
		}
		$this->table    = $manifest->tables[ $number ];
		$this->path     = $directory . '/' . $this->table['file'];
		$this->metadata = $this->stat();
		$handle         = @fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked StorageFailed replaces path-bearing PHP warnings.
		if ( false === $handle ) {
			throw new StorageFailed( 'A staged database table could not be opened.' );
		}
		$this->handle = $handle;
		try {
			$this->unchanged();
			$name   = SqlFormatter::identifier( $this->table['name'] );
			$header = [
				'-- ' . TableSource::FORMAT . ' table ' . $name,
				'SET NAMES utf8mb4;',
				"SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';",
				"SET SESSION time_zone = '" . $manifest->time_zone . "';",
				'SET FOREIGN_KEY_CHECKS = 0;',
				'SET UNIQUE_CHECKS = 0;',
				'DROP TABLE IF EXISTS ' . $name . ';',
			];
			foreach ( $header as $expected ) {
				if ( $expected !== $this->line() ) {
					throw new FormatException( 'The staged database table header differs from the dump format.' );
				}
			}
			$definition        = $this->line();
			$this->create      = CreateStatement::parse( $definition, $this->table['name'], $shadow, $owner );
			$this->data_offset = $this->position();
			$this->identity    = hash( 'sha256', $this->metadata . "\n" . implode( "\n", $header ) . "\n" . $definition );
		} catch ( \Throwable $error ) {
			$this->close();
			throw $error;
		}
	}

	/**
	 * Reads one batch at a committed byte offset, or verifies completion at the trailer.
	 *
	 * @param int $offset Committed position, initially data_offset.
	 * @param int $rows Number of rows already committed for this table.
	 * @return InsertStatement|null Checked batch, or null at a complete trailer and EOF.
	 * @throws FormatException If the statement or completion counts differ.
	 * @throws StorageFailed If the source changes or cannot be read or sought.
	 */
	public function batch( int $offset, int $rows ): ?InsertStatement {
		$handle = $this->handle;
		if ( null === $handle || $offset < $this->data_offset || $offset >= $this->table['bytes'] || $rows < 0 || $rows > $this->table['rows'] ) {
			throw new FormatException( 'The database import cursor is outside its table.' );
		}
		$this->unchanged();
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new StorageFailed( 'The staged database table could not be sought.' );
		}
		$line = $this->line();
		if ( str_starts_with( $line, '--' ) ) {
			if ( $line !== '-- end table rows=' . $rows . ' bytes=' . $offset || $rows !== $this->table['rows'] || $this->position() !== $this->table['bytes'] ) {
				throw new FormatException( 'The database table trailer does not match its imported rows and bytes.' );
			}
			$tail = fread( $handle, 1 );
			if ( '' !== $tail || ! feof( $handle ) ) {
				throw new FormatException( 'The database table contains data after its completion trailer.' );
			}
			$this->unchanged();
			return null;
		}
		$batch = InsertStatement::parse( $line, $this->table['name'], $this->shadow );
		if ( $batch->columns !== $this->create->columns || $batch->rows > $this->table['rows'] - $rows ) {
			throw new FormatException( 'A database INSERT differs from its table columns or completed row count.' );
		}
		$this->unchanged();
		return $batch;
	}

	/**
	 * Returns the byte position to commit alongside an inserted batch.
	 *
	 * @return int Position after the last complete line.
	 * @throws StorageFailed If the handle is closed or cannot report its position.
	 */
	public function position(): int {
		$offset = null === $this->handle ? false : ftell( $this->handle );
		if ( false === $offset ) {
			throw new StorageFailed( 'The staged database table position could not be read.' );
		}
		return $offset;
	}

	/**
	 * Closes the checked file, including on an import error.
	 *
	 * @throws StorageFailed If closing fails.
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			$handle       = $this->handle;
			$this->handle = null;
			if ( ! fclose( $handle ) ) {
				throw new StorageFailed( 'The staged database table could not be closed.' );
			}
		}
	}

	/**
	 * Refuses a changed path, inode, size or timestamp before progress is committed.
	 *
	 * @throws StorageFailed If the file changed or its metadata cannot be read.
	 */
	public function unchanged(): void {
		$stat = null === $this->handle ? false : fstat( $this->handle );
		if ( false === $stat || $this->fingerprint( $stat ) !== $this->metadata || $this->stat() !== $this->metadata ) {
			throw new StorageFailed( 'A staged database table changed during the import.' );
		}
	}

	/**
	 * Reads exactly one bounded physical line; embedded newlines must be SQL escapes.
	 *
	 * @return string Complete line without LF.
	 * @throws FormatException If a line is missing, oversized or unfinished.
	 */
	private function line(): string {
		$line = null === $this->handle ? false : fgets( $this->handle, SqlLexer::MAX_BYTES + 2 );
		if ( false === $line || ! str_ends_with( $line, "\n" ) ) {
			throw new FormatException( 'A staged database statement is missing, too large or unfinished.' );
		}
		return substr( $line, 0, -1 );
	}

	/**
	 * Checks regular-file type without following a symlink.
	 *
	 * @return string Metadata fingerprint.
	 * @throws StorageFailed If the file is absent, changed in size, or not a regular file.
	 */
	private function stat(): string {
		clearstatcache( true, $this->path );
		$stat = @lstat( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing staged file is reported below.
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] !== $this->table['bytes'] ) {
			throw new StorageFailed( 'A staged database table is missing, incomplete or not a regular file.' );
		}
		return $this->fingerprint( $stat );
	}

	/**
	 * Binds a checkpoint to this exact staged file and its metadata.
	 *
	 * @param array{dev: int, ino: int, mode: int, size: int, mtime: int, ctime: int} $stat File metadata.
	 * @return string SHA-256 of the identity fields and resolved path.
	 */
	private function fingerprint( array $stat ): string {
		return hash( 'sha256', $this->path . ':' . $stat['dev'] . ':' . $stat['ino'] . ':' . $stat['mode'] . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] );
	}
}
