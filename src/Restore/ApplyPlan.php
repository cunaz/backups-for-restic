<?php
/**
 * Read-only plan for copying staged files back to their original locations.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Engine\Restore\Names;
use BackupsForRestic\Engine\Snapshot\Manifest;
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
 * @phpstan-type Writer array{uid: int, groups: list<int>|null}
 * @phpstan-type Guard array{reason: string, keys: list<string>, decided: list<bool>}
 */
final class ApplyPlan {

	/** Number of sibling spellings retained across a read-only batch. */
	private const int CACHED_NAMES = 16384;

	/** Number of staged directories named spool whose examination is remembered. */
	private const int CACHED_SPOOLS = 256;

	/** Number of files named like a dump's that are read in one database directory of a spool. */
	private const int SPOOL_FILES = 1024;

	/** Number of names that are listed in one database directory of a spool. */
	private const int SPOOL_NAMES = 65536;

	/** Wider folders share a disk index across read-only worker requests. */
	private const int DISK_THRESHOLD = 2048;

	/**
	 * Code points that a case-insensitive filesystem may ignore in a name: Unicode's
	 * Default_Ignorable_Code_Point property, which contains those that HFS+ skips.
	 */
	private const string IGNORABLE = '/[\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFF8}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}]/u';

	/**
	 * Characters that a filesystem equates with ASCII beyond full case folding: NTFS and SMB
	 * uppercase a dotless i to I, and canonical decomposition turns the Greek question mark
	 * and the varia into ASCII punctuation.
	 */
	private const array LOOKALIKES = [
		"\u{0131}" => 'i',
		"\u{037E}" => ';',
		"\u{1FEF}" => '`',
	];

	/**
	 * Bounded sibling indexes shared only within one read-only worker.
	 *
	 * @var array<string,array<string,string|false>>
	 */
	private array $review_names = [];

	/**
	 * Name index that a live writer's rechecks share within this request.
	 *
	 * @var LiveSpellings|null
	 */
	private ?LiveSpellings $writer = null;

	/**
	 * Whether the running check is a live writer's recheck.
	 *
	 * @var bool
	 */
	private bool $rechecking = false;

	/**
	 * Staged directories named spool, and whether a backup of this plugin wrote them.
	 *
	 * @var array<string, bool>
	 */
	private array $spools = [];

	/**
	 * Live directories in their canonical form.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * Locations that must never be replaced, keyed by every spelling that a canonical live
	 * target can have, with the reason code of the entries they skip and the comparison keys
	 * of their names.
	 *
	 * @var array<string, Guard>
	 */
	private array $guards = [];

	/**
	 * Effective user of this process and the groups a file it writes can have, null for any
	 * group; false where the POSIX extension cannot tell.
	 *
	 * @var Writer|false
	 */
	private array|false $process;

	/**
	 * Whether the intl extension normalizes names for the comparison with protected paths.
	 *
	 * @var bool
	 */
	private bool $normalizer;

	/**
	 * Opens the destination policy.
	 *
	 * @param list<string>      $roots          Allowed live roots, absolute existing directories.
	 * @param list<string>      $excluded      Private state and the running plugin.
	 * @param list<string>      $config_files   Live configuration files skipped by default.
	 * @param bool              $include_config Whether configuration files were explicitly requested.
	 * @param SiteLayout|null   $layout Optional mapping for a verified fresh-site restore.
	 * @param list<string>      $bootstrap Bootstrap locations requiring an offline restore.
	 * @param Writer|false|null $process Identity that writes the files, false when it is unknown, null to ask the POSIX extension.
	 * @param bool|null         $normalizer Whether to compare names with intl's normalization, null where it is loaded.
	 * @throws InvalidArgument If the site has no usable live root or a protected location is not
	 *                         an absolute path without dot segments.
	 */
	public function __construct( array $roots, array $excluded, array $config_files = [], bool $include_config = false, private readonly ?SiteLayout $layout = null, array $bootstrap = [], array|false|null $process = null, ?bool $normalizer = null ) {
		if ( $include_config && null !== $layout ) {
			throw new InvalidArgument( 'A fresh-site import preserves the current installation configuration.' );
		}
		$this->process = $process ?? self::process();
		// intl defines NFKC_Casefold only when it was built with ICU 56 or later.
		$this->normalizer = $normalizer ?? ( class_exists( \Normalizer::class ) && defined( 'Normalizer::FORM_KC_CF' ) );
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
	 * A live writer's rechecks in one request share an index that lists each folder once
	 * for each of its identities and learns the writer's own changes (LiveSpellings); every
	 * recheck still reads each folder's identity and the entry itself from the filesystem.
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
		$this->writer   ??= new LiveSpellings();
		$this->rechecking = true;
		try {
			return $this->check( $files, $path, $cache );
		} finally {
			$this->rechecking = false;
		}
	}

	/**
	 * Lets the following rechecks in this request account for an entry that the writer has
	 * just created, replaced or removed, after a recheck validated its folder immediately
	 * before the operation.
	 *
	 * @param string $path Live entry.
	 * @param bool   $exists Whether the entry exists after the operation.
	 */
	public function changed( string $path, bool $exists ): void {
		$this->writer?->changed( $path, $exists );
	}

	/**
	 * Number of folder listings that the writer's rechecks made in this request, for
	 * diagnostics.
	 *
	 * @param string|null $directory One folder, or null for all.
	 * @return int Folder listings.
	 */
	public function listings( ?string $directory = null ): int {
		return null === $this->writer ? 0 : $this->writer->listings( $directory );
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
	 * Whether the site maps a snapshot path to a live path now. A path that the site's layout
	 * refuses to map, because the mapping would be ambiguous or the path is not a valid
	 * snapshot path, maps to no live path; those are the only refusals of destination().
	 *
	 * @param string $path Absolute snapshot path.
	 * @param string $target Live path.
	 * @return bool Whether the path maps to the live path.
	 */
	public function maps( string $path, string $target ): bool {
		try {
			return $this->destination( $path ) === $target;
		} catch ( InvalidArgument ) {
			return false;
		}
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
				// A filesystem that ignores invisible characters would read these as dot entries.
				if ( in_array( preg_replace( self::IGNORABLE, '', $name ), [ '', '.', '..' ], true ) ) {
					return self::decision( $path, $target, 'refuse', 'invalid_path' );
				}
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
		$guard = null === $target ? null : $this->protection( $target );
		if ( null !== $guard && 'unverified_name' !== $guard ) {
			return self::decision( $path, $target, 'skip', $guard );
		}
		if ( $this->spool( $files, $parts ) ) {
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
		if ( null !== $guard && null !== $root ) {
			return self::decision( $path, $target, 'refuse', 'unverified_name' );
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
		if ( ! is_writable( dirname( $target ) ) ) {
			return self::decision( $path, $target, 'refuse', 'unwritable_destination' );
		}
		$conflict = $this->metadata_conflict( $stat );
		return null === $conflict ? self::decision( $path, $target, 'replace', 'existing_file' ) : self::decision( $path, $target, 'refuse', $conflict );
	}

	/**
	 * The identity that writes the replacements: the process's effective user, which owns
	 * every file it creates, and the groups such a file can have. These are the groups the
	 * user can give a file it owns, and the group of the directory the apply builds its
	 * copies in: below a setgid directory, and on filesystems with BSD group semantics, a
	 * new file takes the group of its directory even when the user is not a member of it.
	 * A file that the apply created is published with that group, and the checks after
	 * publication and during undo must accept it. Root can give any group.
	 *
	 * @param string|null $storage Directory in which the apply builds its copies, the private directory.
	 * @return Writer|false Identity, or false where the POSIX extension cannot tell.
	 */
	public static function process( ?string $storage = null ): array|false {
		// A host can disable single functions, and PHP then leaves them undefined.
		if ( ! function_exists( 'posix_geteuid' ) || ! function_exists( 'posix_getegid' ) || ! function_exists( 'posix_getgroups' ) ) {
			return false;
		}
		$uid = posix_geteuid();
		if ( 0 === $uid ) {
			return [
				'uid'    => $uid,
				'groups' => null,
			];
		}
		$member = posix_getgroups();
		$groups = array_merge( [ posix_getegid() ], false === $member ? [] : $member );
		$stat   = null === $storage || ! is_dir( $storage ) ? false : stat( $storage );
		if ( false !== $stat ) {
			$groups[] = $stat['gid'];
		}
		return [
			'uid'    => $uid,
			'groups' => $groups,
		];
	}

	/**
	 * Predicts whether the apply can keep the owner, group and mode of a file it replaces.
	 * The apply builds each replacement as its own effective user, gives it the group of
	 * the live file, and refuses to publish it when either differs; it never replaces a file
	 * with setuid, setgid or sticky bits. The review refuses such a file up front, before an
	 * apply could stop half-way. Without the POSIX extension the user and its groups are
	 * unknown, and the apply-time check before each replacement remains the only one.
	 *
	 * @param array<int|string, int> $stat Live regular file.
	 * @return string|null Reason of the refusal, or null when the replacement keeps them.
	 */
	private function metadata_conflict( array $stat ): ?string {
		if ( 0 !== ( $stat['mode'] & 07000 ) ) {
			return 'special_permissions';
		}
		if ( false === $this->process ) {
			return null;
		}
		if ( $stat['uid'] !== $this->process['uid'] ) {
			return 'foreign_owner';
		}
		return null === $this->process['groups'] || in_array( $stat['gid'], $this->process['groups'], true ) ? null : 'foreign_group';
	}

	/**
	 * Checks names by exact bytes and simple Unicode case folding. Bounds the aggregate and
	 * individual sibling indexes. Larger directories are scanned in bounded memory. A live
	 * writer's recheck asks the request's LiveSpellings instead. check() calls this method
	 * through self::, which binds this plan.
	 *
	 * @param string                                     $directory Existing parent directory.
	 * @param string                                     $name      Requested spelling.
	 * @param array<string, array<string, string|false>> $cache Case indexes for this walk.
	 * @param DirectorySpellings|null                    $review Private index for wide directories during read-only review.
	 * @param bool                                       $inline Whether a wide folder must defer this synchronous request.
	 * @return bool Whether no other spelling of this name exists.
	 */
	private function unambiguous( string $directory, string $name, array &$cache, ?DirectorySpellings $review = null, bool $inline = false ): bool {
		if ( $this->rechecking && null !== $this->writer ) {
			return $this->writer->unique( $directory, $name );
		}
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
		$handle = @opendir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A folder that cannot be read is answered by the return value.
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
	 * Recognizes a snapshot's spool. Every backup records the site manifest and the
	 * plaintext database dump in spool/ of the private directory, at the location the
	 * directory had then. Once the private directory has moved, nothing else protects that
	 * location, and the dump carries none of the deny files of a private directory. So no
	 * apply writes a spool anywhere; the database rehearsal reads it from staging instead.
	 *
	 * @param string       $files Canonical staging directory.
	 * @param list<string> $parts Names of the snapshot path.
	 * @return bool Whether the path is a staged spool or lies in one.
	 */
	private function spool( string $files, array $parts ): bool {
		$prefix = $files;
		foreach ( $parts as $name ) {
			$prefix .= '/' . $name;
			if ( 'spool' !== $name ) {
				continue;
			}
			if ( ! isset( $this->spools[ $prefix ] ) ) {
				if ( count( $this->spools ) >= self::CACHED_SPOOLS ) {
					$this->spools = [];
				}
				$this->spools[ $prefix ] = self::written_by_backup( $prefix );
			}
			if ( $this->spools[ $prefix ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells a backup's spool from another directory with that name by what only the backup
	 * writes: a site manifest that declares its format, or a database directory with a dump
	 * manifest or an SQL file that starts with the dump's format line. Every file of a
	 * dump starts with that line, so a selection of one table file is recognized as well.
	 * The database directory is listed, and the files named as a dump names its own
	 * (manifest.json, and three digits, a hyphen and a name ending in .sql) are read. Both
	 * are bounded, so that one directory cannot use up a request. What cannot be decided
	 * counts as the backup's: a file that cannot be read, and a directory in which neither
	 * SPOOL_NAMES listed names nor SPOOL_FILES read files showed a dump.
	 *
	 * @param string $directory Staged directory named spool.
	 * @return bool Whether a backup of this plugin wrote it.
	 */
	private static function written_by_backup( string $directory ): bool {
		if ( is_link( $directory ) || ! is_dir( $directory ) ) {
			return false;
		}
		if ( self::declares( $directory . '/manifest.json', Manifest::FORMAT ) ) {
			return true;
		}
		$database = $directory . '/database';
		if ( is_link( $database ) || ! is_dir( $database ) ) {
			return false;
		}
		$handle = @opendir( $database ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable staged directory is reported by the return value.
		if ( false === $handle ) {
			return true;
		}
		$line   = '-- ' . explode( '/', TableSource::FORMAT )[0] . '/';
		$read   = 0;
		$listed = 0;
		try {
			while ( true ) {
				$name = readdir( $handle );
				if ( false === $name ) {
					return false;
				}
				++$listed;
				if ( $listed > self::SPOOL_NAMES + 2 ) {
					// Too many to list them all, and a later one could be a dump's.
					return true;
				}
				// The names the dump gives its files: 000-header.sql, 010-<table>.sql, 900-views.sql.
				$manifest = 'manifest.json' === $name;
				if ( ! $manifest && 1 !== preg_match( '/\A[0-9]{3}-.+\.sql\z/s', $name ) ) {
					continue;
				}
				$file = $database . '/' . $name;
				if ( is_link( $file ) || ! is_file( $file ) ) {
					continue;
				}
				// An unreadable file is not shown to be something else.
				if ( $manifest ? self::declares( $file, TableSource::FORMAT ) : in_array( self::head( $file, strlen( $line ) ), [ $line, null ], true ) ) {
					return true;
				}
				++$read;
				if ( $read >= self::SPOOL_FILES ) {
					// Too many to read them all, and a later one could be a dump's.
					return true;
				}
			}
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Whether a JSON file declares the format name of one of the backup's manifests near its
	 * start, whatever the version. Both manifests are written with the format as their
	 * first member.
	 *
	 * @param string $file   Staged file.
	 * @param string $format Format name and version, for example backups-for-restic-site/1.
	 * @return bool Whether the file declares that format, or cannot be read.
	 */
	private static function declares( string $file, string $format ): bool {
		if ( is_link( $file ) || ! is_file( $file ) ) {
			return false;
		}
		$head = self::head( $file, 4096 );
		// json_encode() writes the slash escaped; a manifest written by hand need not.
		return null === $head || 1 === preg_match( '/"format"\s*:\s*"' . preg_quote( explode( '/', $format )[0], '/' ) . '\\\\?\/[0-9]+"/', $head );
	}

	/**
	 * Reads the first bytes of a staged file.
	 *
	 * @param string      $file   Regular file.
	 * @param int<1, max> $length Number of bytes.
	 * @return string|null Bytes, or null when the file cannot be read.
	 */
	private static function head( string $file, int $length ): ?string {
		$head = @file_get_contents( $file, false, null, 0, $length ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable staged file is reported by its return value.
		return false === $head ? null : $head;
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
		$resolved = self::resolved( $path );
		if ( '' === $resolved ) {
			// A location that is a link to the filesystem root would cover every target.
			InvalidArgument::raise( 'A location that file apply protects resolves to the filesystem root.', [ 'code' => 'protected_path_invalid' ] );
		}
		foreach ( [ $path, $resolved ] as $spelling ) {
			[ $keys, $decided ]          = $this->keys( $spelling );
			$this->guards[ $spelling ] ??= [
				'reason'  => $reason,
				'keys'    => $keys,
				'decided' => $decided,
			];
		}
	}

	/**
	 * Finds the protection that covers a live target.
	 *
	 * @param string $target Canonical live path.
	 * @return string|null Reason of the first protection that covers the target, unverified_name
	 *                     when one may cover it but only normalization could tell, or null.
	 */
	private function protection( string $target ): ?string {
		[ $keys, $decided ] = $this->keys( $target );
		foreach ( $this->guards as $guard ) {
			// Name by name: a key can contain a slash that normalization made of another
			// character, which is no boundary between names.
			if ( array_slice( $keys, 0, count( $guard['keys'] ) ) === $guard['keys'] ) {
				return $guard['reason'];
			}
		}
		if ( in_array( false, $decided, true ) ) {
			foreach ( $this->guards as $guard ) {
				if ( self::undecided( $guard, $keys, $decided ) ) {
					return 'unverified_name';
				}
			}
		}
		return null;
	}

	/**
	 * Whether a protection may cover a target whose keys differ from its own: each pair of
	 * names is equal, or both keep characters that only normalization could compare and
	 * agree in everything that canonical equivalence leaves alone.
	 *
	 * @param Guard        $guard   Protected path.
	 * @param list<string> $keys    Keys of the target's names.
	 * @param list<bool>   $decided Whether each of them decides a comparison.
	 * @return bool Whether the target must be refused rather than guessed.
	 */
	private static function undecided( array $guard, array $keys, array $decided ): bool {
		if ( count( $keys ) < count( $guard['keys'] ) ) {
			return false;
		}
		foreach ( $guard['keys'] as $index => $key ) {
			if ( $key !== $keys[ $index ] && ( $guard['decided'][ $index ] || $decided[ $index ] || self::skeleton( $key ) !== self::skeleton( $keys[ $index ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Keeps what canonical equivalence cannot change in a folded name: the ASCII characters
	 * that no combining mark follows. A decomposed letter is its base followed by marks, so
	 * two names whose skeletons differ are different on every filesystem.
	 *
	 * @param string $key Folded name in valid UTF-8.
	 * @return string Skeleton.
	 */
	private static function skeleton( string $key ): string {
		return preg_replace( '/[\x00-\x7f](?=\p{M})|[^\x00-\x7f]/u', '', $key ) ?? '';
	}

	/**
	 * Splits an absolute path into the comparison keys of its names.
	 *
	 * @param string $path Absolute path without empty or dot components.
	 * @return array{list<string>, list<bool>} Keys, and whether each decides every comparison.
	 */
	private function keys( string $path ): array {
		$keys    = [];
		$decided = [];
		foreach ( explode( '/', substr( $path, 1 ) ) as $name ) {
			[ $key, $exact ] = $this->key( $name );
			$keys[]          = $key;
			$decided[]       = $exact;
		}
		return [ $keys, $decided ];
	}

	/**
	 * Builds the key that compares a name with protected names at least as loosely as any
	 * case-insensitive filesystem does. ext4 and f2fs casefolding decompose to NFD and fold
	 * fully, HFS+ and APFS also ignore normalization and some invisible characters, and NTFS
	 * uppercases a dotless i to I. The key folds fully, drops default-ignorable code points
	 * and, with the intl extension, applies compatibility normalization (NFKC_Casefold).
	 * Without intl an ASCII key still decides every comparison, because each character that
	 * a filesystem equates with ASCII is folded or mapped; two keys that both keep other
	 * characters could only be compared normalized.
	 *
	 * @param string $name One name.
	 * @return array{string, bool} Key, and whether it decides every comparison.
	 */
	private function key( string $name ): array {
		if ( 1 !== preg_match( '/[\x80-\xff]/', $name ) || ! mb_check_encoding( $name, 'UTF-8' ) ) {
			return [ strtolower( $name ), true ];
		}
		$name = strtr( $name, self::LOOKALIKES );
		if ( $this->normalizer ) {
			$key = \Normalizer::normalize( $name, \Normalizer::FORM_KC_CF );
			if ( is_string( $key ) ) {
				return [ $key, true ];
			}
		}
		$key = mb_convert_case( preg_replace( self::IGNORABLE, '', $name ) ?? '', MB_CASE_FOLD, 'UTF-8' );
		return [ $key, 1 !== preg_match( '/[\x80-\xff]/', $key ) ];
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
