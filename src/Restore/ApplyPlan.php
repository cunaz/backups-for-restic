<?php
/**
 * Read-only plan for copying staged files back to their original locations.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Maps snapshot paths to the same absolute locations on this installation. Only the live
 * site's explicitly allowed directories authorize a destination. A plan describes what
 * exists during inspection; a writer must check again immediately before changing it.
 *
 * @phpstan-type Action array{path: string, destination: string|null, action: string, reason: string}
 */
final class ApplyPlan {

	/** Number of sibling spellings retained across a read-only batch. */
	private const int CACHED_NAMES = 16384;

	/** Wider folders share a disk index across read-only worker requests. */
	private const int DISK_THRESHOLD = 2048;

	/**
	 * Bounded sibling indexes shared only within one read-only worker.
	 *
	 * @var array<string,array<string,string|false>>
	 */
	private array $review_names = [];

	/**
	 * Live directories in their canonical form.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * Locations that must never be replaced, in every spelling that a canonical live target
	 * can have, each with the reason code of the entries it skips.
	 *
	 * @var array<string, string>
	 */
	private array $guards = [];

	/**
	 * Opens the destination policy.
	 *
	 * @param list<string>    $roots          Allowed live roots, absolute existing directories.
	 * @param list<string>    $excluded      Private state and the running plugin.
	 * @param list<string>    $config_files   Live configuration files skipped by default.
	 * @param bool            $include_config Whether configuration files were explicitly requested.
	 * @param SiteLayout|null $layout Optional mapping for a verified fresh-site restore.
	 * @param list<string>    $bootstrap Bootstrap locations requiring an offline restore.
	 * @throws InvalidArgument If the site has no usable live root or a protected location is not
	 *                         an absolute path without dot segments.
	 */
	public function __construct( array $roots, array $excluded, array $config_files = [], bool $include_config = false, private readonly ?SiteLayout $layout = null, array $bootstrap = [] ) {
		if ( $include_config && null !== $layout ) {
			throw new InvalidArgument( 'A fresh-site import preserves the current installation configuration.' );
		}
		foreach ( $roots as $root ) {
			$root = rtrim( $root, '/' );
			if ( '' !== $root && str_starts_with( $root, '/' ) && is_dir( $root ) && realpath( $root ) === $root ) {
				$this->roots[] = $root;
			}
		}
		if ( [] === $this->roots ) {
			throw new InvalidArgument( 'No canonical live directory is available for file apply.' );
		}
		foreach ( array_merge( $excluded, $include_config ? [] : $config_files ) as $path ) {
			$this->protect( $path, 'protected' );
		}
		foreach ( $bootstrap as $path ) {
			$this->protect( $path, 'offline_restore_required' );
		}
	}

	/**
	 * Walks the actual staged tree, ancestors first. A refused directory is never entered.
	 *
	 * @param string $files Canonical private staging files/ directory.
	 * @param bool   $compare_content Compare existing regular files for the streaming CLI preview.
	 * @param bool   $inline Whether a wide folder must defer this synchronous request.
	 * @return \Generator<int, Action> Actions for staged entries.
	 * @throws StorageFailed If the staging directory is missing or unreadable.
	 */
	public function actions( string $files, bool $compare_content = false, bool $inline = false ): \Generator {
		clearstatcache( true );
		if ( ! is_dir( $files ) || realpath( $files ) !== $files || '/' === $files ) {
			throw new StorageFailed( 'The staged tree is unavailable.' );
		}
		$cache = [];
		foreach ( $this->walk( $files, '', $cache, $inline ) as $entry ) {
			if ( $compare_content && 'replace' === $entry['action'] && null !== $entry['destination'] ) {
				$original = hash_file( 'sha256', $entry['destination'] );
				$source   = hash_file( 'sha256', $files . $entry['path'] );
				if ( false === $source || false === $original ) {
					throw new StorageFailed( 'A regular file could not be compared during the preview.' );
				}
				if ( hash_equals( $original, $source ) ) {
					$entry['action'] = 'keep';
					$entry['reason'] = 'identical_contents';
				}
			}
			yield $entry;
		}
	}

	/**
	 * Rechecks an entry. Read-only previews can share sibling indexes within a worker.
	 * Every mutation uses a fresh enumeration immediately before publication.
	 *
	 * @param string                  $files Canonical private staging files/ directory.
	 * @param string                  $path  Absolute snapshot path.
	 * @param DirectorySpellings|null $review Name index for a read-only worker.
	 * @return Action Current decision.
	 */
	public function inspect( string $files, string $path, ?DirectorySpellings $review = null ): array {
		$cache = [];
		clearstatcache( true );
		if ( ! is_dir( $files ) || realpath( $files ) !== $files || '/' === $files ) {
			return self::decision( $path, null, 'refuse', 'staging_unavailable' );
		}
		if ( null !== $review ) {
			return $this->check( $files, $path, $this->review_names, $review );
		}
		return $this->check( $files, $path, $cache );
	}

	/**
	 * Projects a snapshot name onto the live installation. A fresh-site restore can
	 * write only the content and uploads trees identified by its verified manifest.
	 *
	 * @param string $path Absolute snapshot path.
	 * @return string|null Absolute live path or null for an unmapped name.
	 */
	public function destination( string $path ): ?string {
		return null === $this->layout ? $path : $this->layout->destination( $path );
	}

	/**
	 * Enumerates the directory without materializing the plan or following snapshot links.
	 *
	 * @param string                                     $files Canonical staging directory.
	 * @param string                                     $path  Snapshot path of this directory, empty at its root.
	 * @param array<string, array<string, string|false>> $cache Case indexes for this walk.
	 * @param bool                                       $inline Whether a wide folder must defer this synchronous request.
	 * @return \Generator<int, Action> Planned entries.
	 * @throws StorageFailed If a staged directory cannot be listed.
	 */
	private function walk( string $files, string $path, array &$cache, bool $inline = false ): \Generator {
		$dir = $files . $path;
		clearstatcache( true );
		if ( realpath( $dir ) !== $dir || ! is_dir( $dir ) ) {
			throw new StorageFailed( 'A staged directory changed during the preview.' );
		}
		$handle = opendir( $dir );
		if ( false === $handle ) {
			throw new StorageFailed( 'A staged directory could not be read.' );
		}
		try {
			for ( $name = readdir( $handle ); false !== $name; $name = readdir( $handle ) ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$child  = $path . '/' . $name;
				$action = $this->check( $files, $child, $cache, null, $inline );
				yield $action;
				if ( in_array( $action['action'], [ 'keep', 'mkdir' ], true ) ) {
					yield from $this->walk( $files, $child, $cache, $inline );
				}
			}
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Checks path structure, both chains of parent directories, name aliases and file types.
	 *
	 * @param string                                     $files Canonical staging directory.
	 * @param string                                     $path  Absolute snapshot path.
	 * @param array<string, array<string, string|false>> $cache Case indexes for this walk.
	 * @param DirectorySpellings|null                    $review Private index for wide directories during read-only review.
	 * @param bool                                       $inline Whether a wide folder must defer this synchronous request.
	 * @return Action Current decision.
	 */
	private function check( string $files, string $path, array &$cache, ?DirectorySpellings $review = null, bool $inline = false ): array {
		$target = null;
		try {
			$parts = Path::parts( $path );
			if ( [] === $parts || str_ends_with( $path, '/' ) ) {
				return self::decision( $path, $target, 'refuse', 'invalid_path' );
			}
			foreach ( $parts as $name ) {
				Names::validate( $name );
			}
		} catch ( FormatException | InvalidArgument ) {
			return self::decision( $path, $target, 'refuse', 'invalid_path' );
		}
		try {
			$target = $this->destination( $path );
			if ( null !== $target && $target !== $path ) {
				foreach ( Path::parts( $target ) as $name ) {
					Names::validate( $name );
				}
			}
		} catch ( FormatException | InvalidArgument ) {
			return self::decision( $path, null, 'refuse', 'ambiguous_destination' );
		}
		$folded = null === $target ? null : self::fold( $target );
		foreach ( $this->guards as $guard => $reason ) {
			if ( null !== $folded && self::within( $folded, self::fold( $guard ) ) ) {
				return self::decision( $path, $target, 'skip', $reason );
			}
		}
		if ( self::spool( $files, $parts ) ) {
			return self::decision( $path, $target, 'skip', 'snapshot_spool' );
		}
		$root     = null;
		$ancestor = $this->layout?->is_ancestor( $path ) ?? false;
		foreach ( $this->roots as $allowed ) {
			if ( null !== $target && self::within( $target, $allowed ) ) {
				$root = $allowed;
				break;
			}
			$ancestor = $ancestor || ( null !== $target && self::within( $allowed, $target ) );
		}
		if ( null === $root && ! $ancestor ) {
			if ( null !== $this->layout && null !== $target ) {
				return self::decision( $path, $target, 'refuse', 'unavailable_destination_root' );
			}
			return self::decision( $path, $target, 'skip', 'outside_roots' );
		}
		clearstatcache( true );
		$source = $files . $path;
		if ( realpath( $source ) !== $source ) {
			return self::decision( $path, $target, 'refuse', 'missing_or_linked_source' );
		}
		$directory   = is_dir( $source );
		$source_stat = lstat( $source );
		if ( false === $source_stat || ( ! $directory && ( 0100000 !== ( $source_stat['mode'] & 0170000 ) || 1 !== $source_stat['nlink'] ) ) ) {
			return self::decision( $path, $target, 'refuse', 'unsafe_source' );
		}
		$prefix = '';
		foreach ( $parts as $name ) {
			if ( ! self::unambiguous( $files . $prefix, $name, $cache, $review, $inline ) ) {
				return self::decision( $path, $target, 'refuse', 'ambiguous_source' );
			}
			$prefix .= '/' . $name;
		}
		if ( null === $root || null === $target ) {
			return $directory ? self::decision( $path, $target, 'keep', 'root_ancestor' ) : self::decision( $path, $target, 'refuse', 'root_ancestor_not_directory' );
		}
		if ( ! is_dir( $root ) || realpath( $root ) !== $root ) {
			return self::decision( $path, $target, 'refuse', 'live_root_changed' );
		}
		if ( $target === $root ) {
			return $directory ? self::decision( $path, $target, 'keep', 'live_root' ) : self::decision( $path, $target, 'refuse', 'type_conflict' );
		}
		$parent = $root;
		foreach ( explode( '/', substr( $target, strlen( $root ) + 1 ) ) as $name ) {
			if ( ! self::unambiguous( $parent, $name, $cache, $review, $inline ) ) {
				return self::decision( $path, $target, 'refuse', 'ambiguous_destination' );
			}
			$next = $parent . '/' . $name;
			if ( is_link( $next ) ) {
				return self::decision( $path, $target, 'refuse', 'linked_destination' );
			}
			if ( ! file_exists( $next ) ) {
				if ( ! is_writable( $parent ) ) {
					return self::decision( $path, $target, 'refuse', 'unwritable_destination' );
				}
				return self::decision( $path, $target, $directory ? 'mkdir' : 'create', 'missing_destination' );
			}
			if ( realpath( $next ) !== $next || ( $next !== $target && ! is_dir( $next ) ) ) {
				return self::decision( $path, $target, 'refuse', 'unsafe_destination_parent' );
			}
			$parent = $next;
		}
		if ( $directory && is_dir( $target ) ) {
			return self::decision( $path, $target, 'keep', 'existing_directory' );
		}
		$stat = lstat( $target );
		if ( $directory || false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink'] ) {
			return self::decision( $path, $target, 'refuse', 'type_conflict' );
		}
		return is_writable( dirname( $target ) ) ? self::decision( $path, $target, 'replace', 'existing_file' ) : self::decision( $path, $target, 'refuse', 'unwritable_destination' );
	}

	/**
	 * Checks names by exact bytes and simple Unicode case folding. Bounds the aggregate and
	 * individual sibling indexes. Larger directories are scanned in bounded memory.
	 *
	 * @param string                                     $directory Existing parent directory.
	 * @param string                                     $name      Requested spelling.
	 * @param array<string, array<string, string|false>> $cache Case indexes for this walk.
	 * @param DirectorySpellings|null                    $review Private index for wide directories during read-only review.
	 * @param bool                                       $inline Whether a wide folder must defer this synchronous request.
	 * @return bool Whether no other spelling of this name exists.
	 */
	private static function unambiguous( string $directory, string $name, array &$cache, ?DirectorySpellings $review = null, bool $inline = false ): bool {
		$key       = self::fold( $name );
		$cache_key = $directory;
		if ( null !== $review ) {
			clearstatcache( true, $directory );
			$stat = lstat( $directory );
			if ( false === $stat ) {
				return false;
			}
			// A rename can preserve whole-second stat values during the directory's
			// change second. A later change invalidates the key of a stable directory.
			$cache_key = time() > $stat['ctime'] ? $directory . "\0" . implode( ':', [ $stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'], $stat['ctime'] ] ) : null;
		}
		if ( null !== $cache_key && isset( $cache[ $cache_key ] ) ) {
			return ( $cache[ $cache_key ][ $key ] ?? $name ) === $name;
		}
		if ( null !== $review && $review->known( $directory ) ) {
			return $review->unique( $directory, $name );
		}
		$handle = opendir( $directory );
		if ( false === $handle ) {
			return false;
		}
		$names = [];
		$count = 0;
		$small = true;
		$valid = true;
		try {
			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
				++$count;
				if ( $inline && $count > self::DISK_THRESHOLD ) {
					InvalidArgument::raise( 'This directory needs a background file preview.', [ 'code' => 'file_plan_large' ] );
				}
				if ( null !== $review && $count > self::DISK_THRESHOLD ) {
					return $review->unique( $directory, $name );
				}
				$folded = self::fold( $entry );
				if ( $folded === $key && $entry !== $name ) {
					$valid = false;
				}
				if ( $small ) {
					$names[ $folded ] = array_key_exists( $folded, $names ) ? false : $entry;
					if ( $count > self::CACHED_NAMES ) {
						$small = false;
						$names = [];
					}
				}
			}
		} finally {
			closedir( $handle );
		}
		if ( $small && null !== $cache_key ) {
			if ( count( $cache ) >= 32 ) {
				array_shift( $cache );
			}
			// Bound the aggregate as well as an individual directory. A large flat
			// uploads folder should fit without retaining dozens of similar indexes.
			$total = count( $names );
			foreach ( $cache as $index ) {
				$total += count( $index );
			}
			while ( $total > 2 * self::CACHED_NAMES && [] !== $cache ) {
				$total -= count( array_shift( $cache ) );
			}
			$cache[ $cache_key ] = $names;
		}
		return $valid;
	}

	/**
	 * Recognizes a snapshot's spool by its fixed layout. Every backup records the site
	 * manifest and the plaintext database dump in spool/ of the private directory, at the
	 * location the directory had then. Once the private directory has moved, nothing else
	 * protects that location, and the dump carries none of the deny files of a private
	 * directory. So no apply writes a spool anywhere; the database rehearsal reads it from
	 * staging instead.
	 *
	 * @param string       $files Canonical staging directory.
	 * @param list<string> $parts Names of the snapshot path.
	 * @return bool Whether the path is a staged spool or lies in one.
	 */
	private static function spool( string $files, array $parts ): bool {
		$prefix = $files;
		foreach ( $parts as $name ) {
			$prefix .= '/' . $name;
			if ( 'spool' === $name && ( is_file( $prefix . '/manifest.json' ) || is_dir( $prefix . '/database' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Protects a location under each spelling that a writable target can have. Every target
	 * is canonical, so repeated and trailing slashes are dropped, and the deepest existing
	 * ancestor is resolved: a location configured through a symbolic link, or a drop-in that
	 * does not exist yet, then also names the directory that WordPress actually loads from.
	 * A location listed twice keeps the reason it was listed with first.
	 *
	 * @param string $path   Configured location.
	 * @param string $reason Reason code of the entries it skips.
	 * @throws InvalidArgument If the location is not an absolute path without dot segments.
	 */
	private function protect( string $path, string $reason ): void {
		while ( str_contains( $path, '//' ) ) {
			$path = str_replace( '//', '/', $path );
		}
		$path = rtrim( $path, '/' );
		try {
			Path::parts( $path );
		} catch ( InvalidArgument $error ) {
			// A location that cannot be compared would be silently unprotected.
			InvalidArgument::raise( 'A location that file apply protects is not an absolute path without dot segments.', [ 'code' => 'protected_path_invalid' ], $error );
		}
		foreach ( [ $path, self::resolved( $path ) ] as $spelling ) {
			$this->guards[ $spelling ] ??= $reason;
		}
	}

	/**
	 * Resolves the deepest existing ancestor of a path and keeps the names below it.
	 *
	 * @param string $path Absolute path without empty or dot components.
	 * @return string Canonical spelling, or the path itself when no ancestor resolves.
	 */
	private static function resolved( string $path ): string {
		clearstatcache( true );
		$parts   = explode( '/', substr( $path, 1 ) );
		$missing = '';
		while ( [] !== $parts ) {
			// An ancestor outside open_basedir does not resolve; the spelling as configured stays.
			$real = @realpath( '/' . implode( '/', $parts ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warns for every ancestor above the site.
			if ( false !== $real ) {
				return rtrim( $real, '/' ) . $missing;
			}
			$missing = '/' . array_pop( $parts ) . $missing;
		}
		return $path;
	}

	/**
	 * Folds a filename for collision detection, preserving arbitrary bytes on Unix.
	 *
	 * @param string $name File name.
	 * @return string Comparison key.
	 */
	public static function fold( string $name ): string {
		return mb_check_encoding( $name, 'UTF-8' ) ? mb_convert_case( $name, MB_CASE_FOLD_SIMPLE, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Tests containment on a component boundary.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Absolute root.
	 * @return bool Whether the path is the root or one of its children.
	 */
	private static function within( string $path, string $root ): bool {
		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Describes a decision.
	 *
	 * @param string      $path Snapshot path.
	 * @param string|null $target Live path, or null when unmapped.
	 * @param string      $action Operation.
	 * @param string      $reason Stable reason code.
	 * @return Action Decision.
	 */
	private static function decision( string $path, ?string $target, string $action, string $reason ): array {
		return [
			'path'        => $path,
			'destination' => $target,
			'action'      => $action,
			'reason'      => $reason,
		];
	}
}
