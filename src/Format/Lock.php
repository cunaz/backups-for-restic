<?php
/**
 * Repository locks.
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
 * An encrypted JSON file in locks/. Backups hold non-exclusive locks; forget, prune and check
 * hold exclusive ones. Every restic client treats a lock older than 30 minutes as stale, so
 * holders refresh their lock regularly.
 */
final readonly class Lock {

	public const int STALE_AFTER_SECONDS = 1800;

	/**
	 * Creates a lock.
	 *
	 * @param string $time      Creation or refresh time (RFC 3339).
	 * @param bool   $exclusive Whether the lock is exclusive.
	 * @param string $hostname  Host name.
	 * @param string $username  User name.
	 * @param int    $pid       Process ID.
	 * @param int    $uid       User ID.
	 * @param int    $gid       Group ID.
	 */
	public function __construct(
		public string $time,
		public bool $exclusive,
		public string $hostname,
		public string $username,
		public int $pid,
		public int $uid = 0,
		public int $gid = 0
	) {
	}

	/**
	 * Serializes the lock like restic.
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		$fields = [
			'time'      => $this->time,
			'exclusive' => $this->exclusive,
			'hostname'  => $this->hostname,
			'username'  => $this->username,
			'pid'       => $this->pid,
		];
		if ( 0 !== $this->uid ) {
			$fields['uid'] = $this->uid;
		}
		if ( 0 !== $this->gid ) {
			$fields['gid'] = $this->gid;
		}
		return GoJson::encode( $fields );
	}

	/**
	 * Parses a lock.
	 *
	 * @param string $json JSON.
	 * @return self Lock.
	 * @throws FormatException If required fields are missing.
	 */
	public static function from_json( string $json ): self {
		$data = GoJson::decode_object( $json );
		if ( ! is_string( $data['time'] ?? null ) || ! is_bool( $data['exclusive'] ?? null ) ) {
			throw new FormatException( 'Lock lacks time or type.' );
		}
		return new self(
			$data['time'],
			$data['exclusive'],
			is_string( $data['hostname'] ?? null ) ? $data['hostname'] : '',
			is_string( $data['username'] ?? null ) ? $data['username'] : '',
			is_int( $data['pid'] ?? null ) ? $data['pid'] : 0,
			is_int( $data['uid'] ?? null ) ? $data['uid'] : 0,
			is_int( $data['gid'] ?? null ) ? $data['gid'] : 0
		);
	}

	/**
	 * Whether the lock is older than restic's stale threshold.
	 *
	 * @param int $now Current Unix time.
	 * @return bool True if stale.
	 */
	public function is_stale( int $now ): bool {
		return $now - Rfc3339::parse( $this->time )[0] > self::STALE_AFTER_SECONDS;
	}
}
