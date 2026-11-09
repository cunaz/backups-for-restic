<?php
/**
 * Tree node.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Tree;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\RawJson;

defined( 'ABSPATH' ) || exit;

/**
 * One entry of a directory listing (restic's data.Node).
 *
 * JSON fields appear in the order of restic's Go struct and follow its omitempty rules, so a
 * node produces the same bytes as restic would for the same metadata. Timestamps are kept as
 * the stored RFC 3339 text to preserve nanoseconds written by restic. Inode and device numbers
 * may exceed PHP's integer range and are then kept as digit strings.
 */
final readonly class Node {

	/** Go's zero time.Time as JSON. */
	public const string ZERO_TIME = '0001-01-01T00:00:00Z';

	/**
	 * Creates a node.
	 *
	 * @param string        $name        Raw name bytes.
	 * @param NodeType      $type        Type.
	 * @param int           $mode        Go FileMode bits.
	 * @param string        $mtime       Modification time (RFC 3339).
	 * @param string        $atime       Access time (RFC 3339).
	 * @param string        $ctime       Change time (RFC 3339).
	 * @param int           $uid         Owner ID.
	 * @param int           $gid         Group ID.
	 * @param string        $user        Owner name.
	 * @param string        $group       Group name.
	 * @param int|string    $inode       Inode number.
	 * @param int|string    $device_id   Device ID (only stored for hard links).
	 * @param int           $size        Size of a file.
	 * @param int           $links       Link count.
	 * @param string        $link_target Raw target of a symlink.
	 * @param int|string    $device      Device number of a device node.
	 * @param list<Id>|null $content     Data blobs of a file; null for other types.
	 * @param Id|null       $subtree     Tree blob of a directory.
	 * @param string        $error       Error recorded while reading the entry.
	 */
	public function __construct(
		public string $name,
		public NodeType $type,
		public int $mode = 0,
		public string $mtime = self::ZERO_TIME,
		public string $atime = self::ZERO_TIME,
		public string $ctime = self::ZERO_TIME,
		public int $uid = 0,
		public int $gid = 0,
		public string $user = '',
		public string $group = '',
		public int|string $inode = 0,
		public int|string $device_id = 0,
		public int $size = 0,
		public int $links = 0,
		public string $link_target = '',
		public int|string $device = 0,
		public ?array $content = null,
		public ?Id $subtree = null,
		public string $error = ''
	) {
	}

	/**
	 * Serializes the node like restic's Node.MarshalJSON().
	 *
	 * @return string JSON object.
	 */
	public function to_json(): string {
		$fields = [
			'name' => GoQuote::quote( $this->name ),
			'type' => $this->type->value,
		];
		if ( 0 !== $this->mode ) {
			$fields['mode'] = $this->mode;
		}
		$fields['mtime'] = $this->mtime;
		$fields['atime'] = $this->atime;
		$fields['ctime'] = $this->ctime;
		$fields['uid']   = $this->uid;
		$fields['gid']   = $this->gid;
		if ( '' !== $this->user ) {
			$fields['user'] = $this->user;
		}
		if ( '' !== $this->group ) {
			$fields['group'] = $this->group;
		}
		foreach ( [
			'inode'     => $this->inode,
			'device_id' => $this->device_id,
		] as $key => $number ) {
			if ( ! self::is_zero( $number ) ) {
				$fields[ $key ] = self::number( $number );
			}
		}
		if ( 0 !== $this->size ) {
			$fields['size'] = $this->size;
		}
		if ( 0 !== $this->links ) {
			$fields['links'] = $this->links;
		}
		if ( '' !== $this->link_target ) {
			// Go writes the lossy UTF-8 form and, for invalid UTF-8, the exact bytes as base64.
			$fields['linktarget'] = $this->link_target;
			if ( ! mb_check_encoding( $this->link_target, 'UTF-8' ) ) {
				$fields['linktarget_raw'] = Bytes::base64_encode( $this->link_target );
			}
		}
		if ( ! self::is_zero( $this->device ) ) {
			$fields['device'] = self::number( $this->device );
		}
		$fields['content'] = null === $this->content ? null : array_map( static fn ( Id $id ): string => $id->hex(), $this->content );
		if ( null !== $this->subtree ) {
			$fields['subtree'] = $this->subtree->hex();
		}
		if ( '' !== $this->error ) {
			$fields['error'] = $this->error;
		}
		return GoJson::encode( $fields );
	}

	/**
	 * Builds a node from decoded JSON.
	 *
	 * @param array<mixed> $data Decoded node object.
	 * @return self Node.
	 * @throws FormatException If required fields are missing or invalid.
	 */
	public static function from_array( array $data ): self {
		if ( ! is_string( $data['name'] ?? null ) || ! is_string( $data['type'] ?? null ) ) {
			throw new FormatException( 'Tree node lacks name or type.' );
		}
		$type = NodeType::tryFrom( $data['type'] );
		if ( null === $type ) {
			throw new FormatException( 'Tree node has an unknown type.' );
		}

		$link_target = self::string_field( $data, 'linktarget' );
		if ( is_string( $data['linktarget_raw'] ?? null ) ) {
			$link_target = Bytes::base64_decode( $data['linktarget_raw'] );
		}

		$content = null;
		if ( is_array( $data['content'] ?? null ) ) {
			$content = [];
			foreach ( $data['content'] as $id ) {
				if ( ! is_string( $id ) ) {
					throw new FormatException( 'Tree node content is invalid.' );
				}
				$content[] = Id::from_hex( $id );
			}
		}

		$subtree = is_string( $data['subtree'] ?? null ) ? Id::from_hex( $data['subtree'] ) : null;
		if ( NodeType::Dir === $type && null === $subtree ) {
			throw new FormatException( 'Directory node lacks a subtree.' );
		}

		return new self(
			GoQuote::unquote( $data['name'] ),
			$type,
			self::int_field( $data, 'mode' ),
			self::string_field( $data, 'mtime', self::ZERO_TIME ),
			self::string_field( $data, 'atime', self::ZERO_TIME ),
			self::string_field( $data, 'ctime', self::ZERO_TIME ),
			self::int_field( $data, 'uid' ),
			self::int_field( $data, 'gid' ),
			self::string_field( $data, 'user' ),
			self::string_field( $data, 'group' ),
			self::big_int_field( $data, 'inode' ),
			self::big_int_field( $data, 'device_id' ),
			self::int_field( $data, 'size' ),
			self::int_field( $data, 'links' ),
			$link_target,
			self::big_int_field( $data, 'device' ),
			$content,
			$subtree,
			self::string_field( $data, 'error' )
		);
	}

	/**
	 * Whether a numeric field is zero.
	 *
	 * @param int|string $value Value.
	 * @return bool True for zero.
	 */
	private static function is_zero( int|string $value ): bool {
		return 0 === $value || '0' === $value;
	}

	/**
	 * Encodes a number that may exceed PHP's integer range.
	 *
	 * @param int|string $value Value.
	 * @return int|RawJson JSON number.
	 */
	private static function number( int|string $value ): int|RawJson {
		return is_int( $value ) ? $value : new RawJson( $value );
	}

	/**
	 * Reads an optional string field.
	 *
	 * @param array<mixed> $data    Object.
	 * @param string       $key     Field.
	 * @param string       $fallback Value when the field is absent.
	 * @return string Value.
	 * @throws FormatException If the field has the wrong type.
	 */
	private static function string_field( array $data, string $key, string $fallback = '' ): string {
		$value = $data[ $key ] ?? $fallback;
		if ( ! is_string( $value ) ) {
			FormatException::raise( 'Tree node field has the wrong type.', [ 'field' => $key ] );
		}
		return $value;
	}

	/**
	 * Reads an optional non-negative integer field.
	 *
	 * @param array<mixed> $data Object.
	 * @param string       $key  Field.
	 * @return int Value.
	 * @throws FormatException If the field has the wrong type.
	 */
	private static function int_field( array $data, string $key ): int {
		$value = $data[ $key ] ?? 0;
		if ( ! is_int( $value ) || $value < 0 ) {
			FormatException::raise( 'Tree node field has the wrong type.', [ 'field' => $key ] );
		}
		return $value;
	}

	/**
	 * Reads an optional unsigned 64-bit field.
	 *
	 * @param array<mixed> $data Object.
	 * @param string       $key  Field.
	 * @return int|string Value.
	 * @throws FormatException If the field has the wrong type.
	 */
	private static function big_int_field( array $data, string $key ): int|string {
		$value = $data[ $key ] ?? 0;
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,20}$/', $value ) ) {
			return $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			FormatException::raise( 'Tree node field has the wrong type.', [ 'field' => $key ] );
		}
		return $value;
	}
}
