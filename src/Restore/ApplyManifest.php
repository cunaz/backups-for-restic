<?php
/**
 * Binds a bounded live file change to the staged and live files seen at confirmation.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a deterministic, byte-preserving manifest. Hashes and filesystem identities belong
 * to the digest. The UI receives the exact proposed operations in printable form.
 *
 * @phpstan-type Row array{path:string, destination:string|null, action:string, reason:string, mode:int|null, source:string|null, original:string|null, parent:string, identity:string}
 */
final class ApplyManifest {

	public const int MAX_ENTRIES = 1000;

	public const int MAX_BYTES = 67108864;

	/**
	 * Builds the manifest from the published report and actual staging.
	 *
	 * @param ApplyPlan $plan Policy.
	 * @param string    $files Canonical staging root.
	 * @param resource  $report Staging report.
	 * @param int       $device Private journal filesystem.
	 * @param int       $byte_limit Maximum content read synchronously.
	 * @param bool      $inline Whether to defer wide directory scans to a background worker.
	 * @return list<Row> Sorted, encoded entries.
	 * @throws InvalidArgument If the report is incomplete or exceeds a limit.
	 * @throws StorageFailed If a file cannot be verified.
	 */
	public static function build( ApplyPlan $plan, string $files, $report, int $device, int $byte_limit = self::MAX_BYTES, bool $inline = false ): array {
		$inventory = [];
		$blocked   = [];
		$seen      = [];
		$rows      = [];
		$bytes     = 0;
		foreach ( self::report( $report ) as $entry ) {
			$key = Bytes::base64url_encode( $entry['path'] );
			if ( strlen( $entry['path'] ) > 4095 || count( $inventory ) >= self::MAX_ENTRIES || isset( $inventory[ $key ] ) ) {
				InvalidArgument::raise( 'This selection needs a background file preview.', [ 'code' => 'file_plan_large' ] );
			}
			$inventory[ $key ] = $entry;
			if ( ! in_array( $entry['type'], [ 'file', 'dir' ], true ) ) {
				$rows[]       = self::row( $key, self::excluded_target( $plan, $entry['path'] ), 'skip', 'unstaged_special_entry' );
				$seen[ $key ] = true;
			}
		}
		foreach ( $plan->actions( $files, false, $inline ) as $action ) {
			$path = $action['path'];
			$key  = Bytes::base64url_encode( $path );
			if ( count( $rows ) >= self::MAX_ENTRIES || ! isset( $inventory[ $key ] ) || isset( $seen[ $key ] ) ) {
				throw new InvalidArgument( 'The staged files do not match the restore report or exceed the 1000-entry apply limit.' );
			}
			$row          = self::row( $key, $action['destination'], $action['action'], $action['reason'] );
			$seen[ $key ] = true;
			if ( in_array( $row['action'], [ 'skip', 'refuse' ], true ) ) {
				$blocked[ $path ] = $row['action'];
			}
			if ( self::mutates( $row ) ) {
				$entry = $inventory[ $key ];
				if ( ( 'mkdir' === $row['action'] ? 'dir' : 'file' ) !== $entry['type'] ) {
					throw new InvalidArgument( 'An entry changed type after staging.' );
				}
				$row['mode']   = $entry['mode'] & 0777;
				$target        = $action['destination'] ?? throw new InvalidArgument( 'An unmapped entry cannot be applied.' );
				$row['parent'] = self::parent_identity( $target );
				if ( (string) $device !== explode( ':', $row['parent'] )[1] ) {
					$rows[] = self::row( $key, $target, 'refuse', 'different_filesystem' );
					continue;
				}
				$row['identity'] = self::identity( $files . $path );
				if ( 'mkdir' !== $row['action'] ) {
					$row['source'] = self::hash( $files . $path, $bytes, $byte_limit );
				}
				if ( 'replace' === $row['action'] ) {
					$row['original']  = self::hash( $target, $bytes, $byte_limit );
					$row['identity'] .= '/' . self::identity( $target );
					if ( $row['original'] === $row['source'] ) {
						$row['action'] = 'keep';
						$row['reason'] = 'identical_contents';
					}
				}
			}
			$rows[] = $row;
		}
		// A source that vanished must not quietly disappear from the confirmed plan.
		foreach ( $inventory as $key => $entry ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$parent = dirname( $entry['path'] );
			while ( '/' !== $parent && ! isset( $blocked[ $parent ] ) ) {
				$parent = dirname( $parent );
			}
			if ( ! isset( $blocked[ $parent ] ) ) {
				throw new InvalidArgument( 'An entry in the restore report is missing from staging.' );
			}
			$rows[] = self::row( $key, self::excluded_target( $plan, $entry['path'] ), $blocked[ $parent ], 'parent_excluded' );
		}
		$destinations = [];
		foreach ( $rows as $index => $row ) {
			if ( null === $row['destination'] || 'skip' === $row['action'] ) {
				continue;
			}
			$key = ApplyPlan::fold( Bytes::base64url_decode( $row['destination'] ) );
			if ( isset( $destinations[ $key ] ) ) {
				$previous          = $destinations[ $key ];
				$target            = Bytes::base64url_decode( $row['destination'] );
				$prior             = $rows[ $previous ];
				$prior_target      = null === $prior['destination'] ? null : Bytes::base64url_decode( $prior['destination'] );
				$rows[ $previous ] = self::row( $prior['path'], $prior_target, 'refuse', 'overlapping_destinations' );
				$rows[ $index ]    = self::row( $row['path'], $target, 'refuse', 'overlapping_destinations' );
			} else {
				$destinations[ $key ] = $index;
			}
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$target = strcmp( Bytes::base64url_decode( $a['destination'] ?? $a['path'] ), Bytes::base64url_decode( $b['destination'] ?? $b['path'] ) );
				return 0 === $target ? strcmp( $a['path'], $b['path'] ) : $target;
			}
		);
		return $rows;
	}

	/**
	 * Reads bounded, strictly typed lines. No file path is interpreted before validation.
	 *
	 * @param resource $handle Report stream.
	 * @return \Generator<int, array{path:string,type:string,mode:int}> Staged or skipped entry.
	 * @throws InvalidArgument If a report line is incomplete.
	 */
	public static function report( $handle ): \Generator {
		for ( $line = fgets( $handle, 131073 ); false !== $line; $line = fgets( $handle, 131073 ) ) {
			$entry = json_decode( $line, true );
			if ( ! str_ends_with( $line, "\n" ) || ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) || ! is_string( $entry['type'] ?? null ) || ! is_int( $entry['mode'] ?? null ) ) {
				throw new InvalidArgument( 'The restore report has an invalid or oversized entry.' );
			}
			$path = GoQuote::unquote( $entry['path'] );
			if ( [] === Path::parts( $path ) || str_ends_with( $path, '/' ) ) {
				throw new InvalidArgument( 'An entry in the restore report has an invalid path.' );
			}
			yield [
				'path' => $path,
				'type' => $entry['type'],
				'mode' => $entry['mode'],
			];
		}
		if ( ! feof( $handle ) ) {
			throw new InvalidArgument( 'The restore report could not be read completely.' );
		}
	}

	/**
	 * An excluded subtree may have multiple possible destinations. Its refusal is shown
	 * without a live destination when no single safe location can be identified.
	 *
	 * @param ApplyPlan $plan Site policy.
	 * @param string    $path Snapshot path.
	 * @return string|null Identifiable destination.
	 */
	private static function excluded_target( ApplyPlan $plan, string $path ): ?string {
		try {
			return $plan->destination( $path );
		} catch ( InvalidArgument ) {
			return null;
		}
	}

	/**
	 * Records an entry with no payload by default.
	 *
	 * @param string      $key Encoded path.
	 * @param string|null $target Live destination, null for unprojected entries.
	 * @param string      $action Operation.
	 * @param string      $reason Decision.
	 * @return Row Entry.
	 */
	public static function row( string $key, ?string $target, string $action, string $reason ): array {
		return [
			'path'        => $key,
			'destination' => null === $target ? null : Bytes::base64url_encode( $target ),
			'action'      => $action,
			'reason'      => $reason,
			'mode'        => null,
			'source'      => null,
			'original'    => null,
			'parent'      => '',
			'identity'    => '',
		];
	}

	/**
	 * Verifies the shape of a row read from private disk. Previous inline manifests
	 * stored the same absolute path for source and destination in a single field.
	 *
	 * @param mixed $row Decoded record.
	 * @return Row Verified row.
	 * @throws StorageFailed If a field is invalid.
	 */
	public static function checked( mixed $row ): array {
		if ( ! is_array( $row ) || ! is_string( $row['path'] ?? null ) || ! is_string( $row['action'] ?? null ) || ! is_string( $row['reason'] ?? null ) || ! is_string( $row['parent'] ?? null ) || ! is_string( $row['identity'] ?? null ) ) {
			throw new StorageFailed( 'The confirmed file plan has an invalid record.' );
		}
		$target   = array_key_exists( 'destination', $row ) ? $row['destination'] : $row['path'];
		$mode     = $row['mode'] ?? null;
		$source   = $row['source'] ?? null;
		$original = $row['original'] ?? null;
		if ( ( null !== $target && ! is_string( $target ) ) || ( null !== $mode && ! is_int( $mode ) ) || ( null !== $source && ! is_string( $source ) ) || ( null !== $original && ! is_string( $original ) ) ) {
			throw new StorageFailed( 'The confirmed file plan has invalid metadata.' );
		}
		return [
			'path'        => $row['path'],
			'destination' => $target,
			'action'      => $row['action'],
			'reason'      => $row['reason'],
			'mode'        => $mode,
			'source'      => $source,
			'original'    => $original,
			'parent'      => $row['parent'],
			'identity'    => $row['identity'],
		];
	}

	/**
	 * Returns the printable source and destination without exposing private file hashes.
	 *
	 * @param Row $row Reviewed entry.
	 * @return array{action:string,path:string,source:string,destination:string|null,reason:string} Public entry.
	 */
	public static function display( array $row ): array {
		$source = GoQuote::quote( Bytes::base64url_decode( $row['path'] ) );
		$target = null === $row['destination'] ? null : GoQuote::quote( Bytes::base64url_decode( $row['destination'] ) );
		return [
			'action'      => $row['action'],
			'path'        => $target ?? $source,
			'source'      => $source,
			'destination' => $target,
			'reason'      => $row['reason'],
		];
	}

	/**
	 * Whether a row has an associated live mutation.
	 *
	 * @param Row $row Confirmed entry.
	 * @return bool Whether the file change primitive is needed.
	 */
	public static function mutates( array $row ): bool {
		return in_array( $row['action'], [ 'mkdir', 'create', 'replace' ], true );
	}

	/**
	 * Identity of a directory or regular file, excluding atime, which reading can change.
	 *
	 * @param string $path Checked path.
	 * @return string Stable identity and metadata.
	 * @throws StorageFailed If the path disappeared.
	 */
	public static function identity( string $path ): string {
		clearstatcache( true, $path );
		$s = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing entry is reported by the exception below.
		if ( false === $s ) {
			throw new StorageFailed( 'A file disappeared during apply preflight.' );
		}
		return implode( ':', [ $s['dev'], $s['ino'], $s['uid'], $s['gid'], $s['mode'], $s['size'], $s['mtime'], $s['ctime'] ] );
	}

	/**
	 * Binds a missing destination to its nearest existing parent. New child directories
	 * can appear during this apply, so store the exact original parent's name and identity.
	 *
	 * @param string $path Destination.
	 * @return string Encoded path and identity.
	 * @throws StorageFailed If the parent is inaccessible or changed to a link.
	 */
	public static function parent_identity( string $path ): string {
		$parent = dirname( $path );
		while ( '/' !== $parent && ! file_exists( $parent ) && ! is_link( $parent ) ) {
			$parent = dirname( $parent );
		}
		clearstatcache( true );
		$s = lstat( $parent );
		if ( false === $s || realpath( $parent ) !== $parent || 0040000 !== ( $s['mode'] & 0170000 ) ) {
			throw new StorageFailed( 'A live destination parent is unavailable.' );
		}
		return implode( ':', [ Bytes::base64url_encode( $parent ), $s['dev'], $s['ino'], $s['mode'], $s['uid'], $s['gid'] ] );
	}

	/**
	 * Computes SHA-256 on a verified one-link regular file with a bounded read. Growing or
	 * changing the file while it is being read invalidates the entire preview.
	 *
	 * @param string $path File to verify.
	 * @param int    $bytes Bytes charged to this preview.
	 * @param int    $limit Sync byte allowance.
	 * @return string Hex digest.
	 * @throws InvalidArgument If the selection exceeds the byte budget.
	 * @throws StorageFailed If the file changed or cannot be read.
	 */
	private static function hash( string $path, int &$bytes, int $limit ): string {
		$expected = self::identity( $path );
		$size     = filesize( $path );
		if ( false === $size || $size > $limit - $bytes ) {
			InvalidArgument::raise( 'This selection needs a background file preview.', [ 'code' => 'file_plan_large' ] );
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new StorageFailed( 'A file in the apply preview cannot be opened.' );
		}
		try {
			$hash = hash_init( 'sha256' );
			$read = hash_update_stream( $hash, $handle, $size + 1 );
			if ( $read !== $size || $expected !== self::identity( $path ) ) {
				throw new StorageFailed( 'A file changed during the apply preview.' );
			}
			$bytes += $read;
			return hash_final( $hash );
		} finally {
			fclose( $handle );
		}
	}
}
