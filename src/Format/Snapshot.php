<?php
/**
 * Snapshots.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * An encrypted JSON file in snapshots/ that points to the root tree of one backup.
 */
final readonly class Snapshot {

	/**
	 * Creates a snapshot.
	 *
	 * @param string               $time            Backup time (RFC 3339).
	 * @param Id                   $tree            Root tree.
	 * @param list<string>         $paths           Backed-up paths.
	 * @param string               $hostname        Host name used for grouping.
	 * @param string               $username        User name.
	 * @param int                  $uid             User ID.
	 * @param int                  $gid             Group ID.
	 * @param list<string>         $excludes        Exclude patterns.
	 * @param list<string>         $tags            Tags.
	 * @param Id|null              $parent_id       Parent snapshot.
	 * @param Id|null              $original        Original snapshot when metadata was rewritten.
	 * @param string               $program_version Writer, for example "backups-for-restic 0.1.0".
	 * @param SnapshotSummary|null $summary         Statistics.
	 */
	public function __construct(
		public string $time,
		public Id $tree,
		public array $paths,
		public string $hostname = '',
		public string $username = '',
		public int $uid = 0,
		public int $gid = 0,
		public array $excludes = [],
		public array $tags = [],
		public ?Id $parent_id = null,
		public ?Id $original = null,
		public string $program_version = '',
		public ?SnapshotSummary $summary = null
	) {
	}

	/**
	 * Serializes the snapshot like restic.
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		$fields = [ 'time' => $this->time ];
		if ( null !== $this->parent_id ) {
			$fields['parent'] = $this->parent_id->hex();
		}
		$fields['tree']  = $this->tree->hex();
		$fields['paths'] = $this->paths;
		$optional        = [
			'hostname'        => $this->hostname,
			'username'        => $this->username,
			'uid'             => $this->uid,
			'gid'             => $this->gid,
			'excludes'        => $this->excludes,
			'tags'            => $this->tags,
			'original'        => $this->original?->hex() ?? '',
			'program_version' => $this->program_version,
		];
		foreach ( $optional as $key => $value ) {
			if ( '' !== $value && 0 !== $value && [] !== $value ) {
				$fields[ $key ] = $value;
			}
		}
		if ( null !== $this->summary ) {
			$fields['summary'] = $this->summary->to_array();
		}
		return GoJson::encode( $fields );
	}

	/**
	 * Parses a snapshot.
	 *
	 * @param string $json JSON.
	 * @return self Snapshot.
	 * @throws FormatException If required fields are missing.
	 */
	public static function from_json( string $json ): self {
		$data = GoJson::decode_object( $json );
		if ( ! is_string( $data['time'] ?? null ) || ! is_string( $data['tree'] ?? null ) ) {
			throw new FormatException( 'Snapshot lacks time or tree.' );
		}
		Rfc3339::parse( $data['time'] );

		return new self(
			$data['time'],
			Id::from_hex( $data['tree'] ),
			self::string_list( $data['paths'] ?? [] ),
			is_string( $data['hostname'] ?? null ) ? $data['hostname'] : '',
			is_string( $data['username'] ?? null ) ? $data['username'] : '',
			is_int( $data['uid'] ?? null ) ? $data['uid'] : 0,
			is_int( $data['gid'] ?? null ) ? $data['gid'] : 0,
			self::string_list( $data['excludes'] ?? [] ),
			self::string_list( $data['tags'] ?? [] ),
			is_string( $data['parent'] ?? null ) ? Id::from_hex( $data['parent'] ) : null,
			is_string( $data['original'] ?? null ) ? Id::from_hex( $data['original'] ) : null,
			is_string( $data['program_version'] ?? null ) ? $data['program_version'] : '',
			is_array( $data['summary'] ?? null ) ? SnapshotSummary::from_array( $data['summary'] ) : null
		);
	}

	/**
	 * Returns the snapshot time as seconds since the epoch.
	 *
	 * @return int Unix time.
	 */
	public function unix_time(): int {
		return Rfc3339::parse( $this->time )[0];
	}

	/**
	 * Whether the snapshot carries all of the given tags.
	 *
	 * @param list<string> $tags Tags.
	 * @return bool True if every tag is present.
	 */
	public function has_tags( array $tags ): bool {
		return [] === array_diff( $tags, $this->tags );
	}

	/**
	 * Validates a list of strings.
	 *
	 * @param mixed $value Decoded JSON (null is treated as empty).
	 * @return list<string> Strings.
	 * @throws FormatException If an element is not a string.
	 */
	private static function string_list( mixed $value ): array {
		if ( null === $value ) {
			return [];
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new FormatException( 'Snapshot list field is invalid.' );
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new FormatException( 'Snapshot list field is invalid.' );
			}
		}
		return $value;
	}
}
