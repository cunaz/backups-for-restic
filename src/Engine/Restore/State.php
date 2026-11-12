<?php
/**
 * Strict, versioned restore checkpoint.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine\Restore;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

/**
 * State contains cursors and counters, never a growing list of restored entries.
 *
 * @phpstan-type Directory array{path: string, dev: int, ino: int}
 * @phpstan-type Frame array{tree: string, dir: string, next: int, mode: int, mtime: string}
 * @phpstan-type Planning array{path: string, blob: int, bytes: int}
 * @phpstan-type File array{path: string, blob: int, written: int, dev: int|null, ino: int|null}
 */
final class State {

	public const int VERSION   = 2;
	public const int MAX_BYTES = 4194304;

	/**
	 * Current pass or terminal outcome.
	 *
	 * @var string
	 */
	public string $phase = 'plan';
	/**
	 * Immutable root tree after loading the selected snapshot.
	 *
	 * @var string|null
	 */
	public ?string $tree = null;
	/**
	 * Recorded shared lock, including one whose cleanup is pending.
	 *
	 * @var string|null
	 */
	public ?string $lock = null;
	/**
	 * Time the current lease acquisition or refresh began.
	 *
	 * @var int
	 */
	public int $locked_at = 0;

	/**
	 * Bounded depth-first traversal stack.
	 *
	 * @var list<Frame>
	 */
	public array $stack = [];

	/**
	 * The one file whose indexed blob sizes are still being counted.
	 *
	 * @var Planning|null
	 */
	public ?array $planning = null;

	/**
	 * The one partial file, identified before writing content.
	 *
	 * @var File|null
	 */
	public ?array $file = null;

	/**
	 * Directory creation intent persisted before mkdir.
	 *
	 * @var string|null
	 */
	public ?string $creating = null;

	/**
	 * Requested paths found by the planning pass.
	 *
	 * @var list<string>
	 */
	public array $found = [];

	/**
	 * Regular files counted in the planning pass.
	 *
	 * @var int
	 */
	public int $planned_files = 0;
	/**
	 * Logical data bytes counted in the planning pass.
	 *
	 * @var int
	 */
	public int $planned_bytes = 0;
	/**
	 * Selected entries including ancestor directories.
	 *
	 * @var int
	 */
	public int $planned_entries = 0;
	/**
	 * Completely restored regular files.
	 *
	 * @var int
	 */
	public int $files = 0;
	/**
	 * Flushed and checkpointed payload bytes.
	 *
	 * @var int
	 */
	public int $bytes = 0;
	/**
	 * Links and special entries recorded without creating them.
	 *
	 * @var int
	 */
	public int $skipped = 0;
	/**
	 * Committed report length; a later suffix is discarded on resume.
	 *
	 * @var int
	 */
	public int $report_bytes = 0;

	/**
	 * Creates a run identity before any repository request.
	 *
	 * @param string    $repository Repository configuration ID.
	 * @param Id        $snapshot Snapshot ID.
	 * @param Selection $selection Disjoint absolute snapshot paths.
	 * @param Directory $target Canonical destination and filesystem identity.
	 * @param Directory $work Canonical private working directory and identity.
	 * @param string    $hostname Lock owner hostname.
	 * @param int       $pid Random run number, stable across PHP requests.
	 */
	public function __construct(
		public readonly string $repository,
		public readonly Id $snapshot,
		public readonly Selection $selection,
		public readonly array $target,
		public readonly array $work,
		public readonly string $hostname,
		public readonly int $pid
	) {
	}

	/**
	 * Encodes raw path bytes explicitly; Go JSON's UTF-8 replacement must not change them.
	 *
	 * @return string Checkpoint JSON.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	public function to_json(): string {
		$data              = get_object_vars( $this );
		$data['version']   = self::VERSION;
		$data['snapshot']  = $this->snapshot->hex();
		$data['selection'] = array_map( GoQuote::quote( ... ), $this->selection->paths );
		$data['target']    = array_replace( $this->target, [ 'path' => GoQuote::quote( $this->target['path'] ) ] );
		$data['work']      = array_replace( $this->work, [ 'path' => GoQuote::quote( $this->work['path'] ) ] );
		$data['found']     = array_map( GoQuote::quote( ... ), $this->found );
		$data['stack']     = array_map(
			static function ( array $frame ): array {
				$frame['dir'] = GoQuote::quote( $frame['dir'] );
				return $frame;
			},
			$this->stack
		);
		if ( null !== $this->file ) {
			$data['file'] = array_replace( $this->file, [ 'path' => GoQuote::quote( $this->file['path'] ) ] );
		}
		if ( null !== $this->planning ) {
			$data['planning'] = array_replace( $this->planning, [ 'path' => GoQuote::quote( $this->planning['path'] ) ] );
		}
		$data['creating'] = null === $this->creating ? null : GoQuote::quote( $this->creating );
		$json             = GoJson::encode( $data );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new FormatException( 'The restore checkpoint exceeds its size limit.' );
		}
		return $json;
	}

	/**
	 * Refuses incomplete or differently versioned state instead of resetting its cursors.
	 *
	 * @param string $json Checkpoint JSON.
	 * @return self Validated state.
	 * @throws FormatException If the checkpoint cannot safely be resumed.
	 */
	public static function from_json( string $json ): self {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new FormatException( 'The restore checkpoint exceeds its size limit.' );
		}
		$data = GoJson::decode_object( $json );
		if ( self::VERSION !== ( $data['version'] ?? null ) ) {
			throw new FormatException( 'The restore checkpoint is from another version or exceeds its size limit.' );
		}
		$state        = new self(
			self::text( $data, 'repository' ),
			Id::from_hex( self::text( $data, 'snapshot' ) ),
			new Selection( self::paths( $data['selection'] ?? null ) ),
			self::directory( $data['target'] ?? null ),
			self::directory( $data['work'] ?? null ),
			self::text( $data, 'hostname' ),
			self::number( $data, 'pid' )
		);
		$state->phase = self::text( $data, 'phase' );
		if ( ! in_array( $state->phase, [ 'plan', 'restore', 'complete', 'aborted' ], true ) || 0 === $state->pid ) {
			throw new FormatException( 'The restore checkpoint has an invalid phase or run identity.' );
		}
		foreach ( [ 'tree', 'lock' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ! is_string( $data[ $key ] ) ) ) {
				throw new FormatException( 'The restore checkpoint has an invalid repository reference.' );
			}
			$state->$key = null === $data[ $key ] ? null : Id::from_hex( $data[ $key ] )->hex();
		}
		foreach ( [ 'locked_at', 'planned_files', 'planned_bytes', 'planned_entries', 'files', 'bytes', 'skipped', 'report_bytes' ] as $key ) {
			$state->$key = self::number( $data, $key );
		}
		$state->found = self::paths( $data['found'] ?? null );
		if ( [] !== array_diff( $state->found, $state->selection->paths ) ) {
			throw new FormatException( 'The restore checkpoint has an invalid selection cursor.' );
		}
		$frames = $data['stack'] ?? null;
		if ( ! is_array( $frames ) || ! array_is_list( $frames ) || count( $frames ) > 257 ) {
			throw new FormatException( 'The restore checkpoint has an invalid traversal stack.' );
		}
		foreach ( $frames as $frame ) {
			if ( ! is_array( $frame ) ) {
				throw new FormatException( 'The restore checkpoint has an invalid directory cursor.' );
			}
			$state->stack[] = [
				'tree'  => Id::from_hex( self::text( $frame, 'tree' ) )->hex(),
				'dir'   => GoQuote::unquote( self::text( $frame, 'dir' ) ),
				'next'  => self::number( $frame, 'next' ),
				'mode'  => self::number( $frame, 'mode' ),
				'mtime' => self::text( $frame, 'mtime' ),
			];
		}
		if ( ! array_key_exists( 'planning', $data ) || ( null !== $data['planning'] && ! is_array( $data['planning'] ) ) ) {
			throw new FormatException( 'The restore checkpoint has an invalid planning cursor.' );
		}
		if ( null !== $data['planning'] ) {
			$state->planning = [
				'path'  => GoQuote::unquote( self::text( $data['planning'], 'path' ) ),
				'blob'  => self::number( $data['planning'], 'blob' ),
				'bytes' => self::number( $data['planning'], 'bytes' ),
			];
			if ( ! in_array( $state->phase, [ 'plan', 'aborted' ], true ) || [] === $state->stack ) {
				throw new FormatException( 'The restore checkpoint has a planning cursor outside its traversal.' );
			}
		}
		$file = $data['file'] ?? null;
		if ( null !== $file ) {
			if ( ! is_array( $file ) ) {
				throw new FormatException( 'The restore checkpoint has an invalid file cursor.' );
			}
			$state->file = [
				'path'    => GoQuote::unquote( self::text( $file, 'path' ) ),
				'blob'    => self::number( $file, 'blob' ),
				'written' => self::number( $file, 'written' ),
				'dev'     => self::nullable_integer( $file, 'dev' ),
				'ino'     => self::nullable_integer( $file, 'ino' ),
			];
			if ( ( null === $state->file['dev'] ) !== ( null === $state->file['ino'] ) ) {
				throw new FormatException( 'The restore checkpoint has an incomplete file identity.' );
			}
		}
		$creating = $data['creating'] ?? null;
		if ( null !== $creating && ! is_string( $creating ) ) {
			throw new FormatException( 'The restore checkpoint has an invalid directory intent.' );
		}
		$state->creating = null === $creating ? null : GoQuote::unquote( $creating );
		if ( $state->files > $state->planned_files || $state->bytes > $state->planned_bytes ) {
			throw new FormatException( 'The restore checkpoint exceeds its planned totals.' );
		}
		return $state;
	}

	/**
	 * Reads required string fields without coercion.
	 *
	 * @param array<mixed> $data Object.
	 * @param string       $key Field name.
	 * @return string Value.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function text( array $data, string $key ): string {
		$value = $data[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			throw new FormatException( 'The restore checkpoint is missing a required string.' );
		}
		return $value;
	}

	/**
	 * Reads required nonnegative counters without a fallback.
	 *
	 * @param array<mixed> $data Object.
	 * @param string       $key Field name.
	 * @return int Value.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function number( array $data, string $key ): int {
		$value = $data[ $key ] ?? null;
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new FormatException( 'The restore checkpoint has an invalid counter.' );
		}
		return $value;
	}

	/**
	 * Filesystem identities may use signed machine integers.
	 *
	 * @param array<mixed> $data Object.
	 * @param string       $key Field name.
	 * @return int|null Value.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function nullable_integer( array $data, string $key ): ?int {
		if ( ! array_key_exists( $key, $data ) || ( null !== $data[ $key ] && ! is_int( $data[ $key ] ) ) ) {
			throw new FormatException( 'The restore checkpoint has an invalid filesystem identity.' );
		}
		return $data[ $key ];
	}

	/**
	 * Decodes a bounded path list.
	 *
	 * @param mixed $value Encoded paths.
	 * @return list<string> Raw paths.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function paths( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > Selection::MAX_PATHS ) {
			throw new FormatException( 'The restore checkpoint has an invalid path list.' );
		}
		$result = [];
		foreach ( $value as $path ) {
			if ( ! is_string( $path ) ) {
				throw new FormatException( 'The restore checkpoint has an invalid path.' );
			}
			$result[] = GoQuote::unquote( $path );
		}
		return $result;
	}

	/**
	 * Reads a bound directory.
	 *
	 * @param mixed $value Encoded directory.
	 * @return Directory Directory identity.
	 * @throws FormatException If checkpoint data or local restore I/O is invalid.
	 */
	private static function directory( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new FormatException( 'The restore checkpoint has no bound directory.' );
		}
		$dev = self::nullable_integer( $value, 'dev' );
		$ino = self::nullable_integer( $value, 'ino' );
		if ( null === $dev || null === $ino ) {
			throw new FormatException( 'The restore checkpoint has an incomplete directory identity.' );
		}
		return [
			'path' => GoQuote::unquote( self::text( $value, 'path' ) ),
			'dev'  => $dev,
			'ino'  => $ino,
		];
	}
}
