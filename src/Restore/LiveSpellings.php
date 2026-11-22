<?php
/**
 * Name index that a live writer's rechecks share within one request.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * A live writer rechecks both directory chains of an entry several times around each
 * publication. Listing every folder of the chains for each recheck made a change in a folder
 * of N names cost N, and a restore of N files into one folder N². This index lists a folder
 * once for each identity the folder has during the request.
 *
 * Every recheck reads the folder's identity: device, inode, permissions, owner, link count,
 * size and both change times, which PHP reports in whole seconds. A change by another process
 * alters the identity, unless it falls in the second of the folder's last change. A listing
 * that began a CLOCK_MARGIN after the end of that second can therefore be kept for as long as
 * the identity holds. The margin covers the file system's change clock, which on Linux
 * advances once per timer tick and can show the previous second just after PHP's clock has
 * passed it.
 *
 * A folder of up to WIDE names is listed again after each of the writer's own changes, as
 * every recheck listed it before. In a wider folder the index learns the writer's own changes
 * and also keeps a listing that began within the second of the folder's last change. It then
 * trusts that folder only until that second and the margin have passed: a change by another
 * process in the same second as the writer's own can stay hidden from the rechecks that long,
 * at most about one and a quarter seconds, and the first recheck after it lists the folder
 * again.
 *
 * Names are kept as salted 64-bit codes of the folded name and of the exact spelling, so that
 * a wide folder fits in memory: a code collision can only refuse a name or, for two spellings
 * of the same folded name, let a recheck miss the second one, which the publication itself
 * still meets on a case-insensitive file system. At most MAX_NAMES names are kept; a folder
 * that alone holds more is listed for each recheck, as before.
 *
 * @phpstan-type Folder array{identity: string, until: int|null, names: array<int, int|false>}
 * @phpstan-type Observation array{key: string, ctime: int, now: float}
 */
final class LiveSpellings {

	/** Names kept across all folders, about 20 MiB of memory. */
	public const int MAX_NAMES = 524288;

	/** Folders with more names learn the writer's own changes instead of being listed again. */
	public const int WIDE = 2048;

	/** Seconds by which a folder's change time can trail PHP's clock. */
	public const float CLOCK_MARGIN = 0.25;

	/**
	 * Indexed folders by path, the least recently used first.
	 *
	 * @var array<string, Folder>
	 */
	private array $folders = [];

	/**
	 * Names currently kept.
	 *
	 * @var int
	 */
	private int $held = 0;

	/**
	 * Folder listings performed.
	 *
	 * @var int
	 */
	private int $listings = 0;

	/**
	 * Seed of the name codes, new for every request.
	 *
	 * @var int
	 */
	private readonly int $seed;

	/**
	 * Starts an empty index.
	 *
	 * @param int $capacity Names kept across all folders.
	 * @param int $wide Names above which a folder learns the writer's own changes.
	 */
	public function __construct( private readonly int $capacity = self::MAX_NAMES, private readonly int $wide = self::WIDE ) {
		$this->seed = random_int( PHP_INT_MIN, PHP_INT_MAX );
	}

	/**
	 * Checks that no other spelling of a name exists in a folder, by exact bytes and simple
	 * case folding, as ApplyPlan's own listing does.
	 *
	 * @param string $directory Existing canonical directory.
	 * @param string $name Requested spelling.
	 * @return bool Whether no other spelling of this name exists.
	 */
	public function unique( string $directory, string $name ): bool {
		$before = self::observe( $directory );
		if ( null === $before ) {
			return false;
		}
		$folder = $this->folders[ $directory ] ?? null;
		if ( null !== $folder && ( $folder['identity'] !== $before['key'] || ( null !== $folder['until'] && $before['now'] >= $folder['until'] + 1 + self::CLOCK_MARGIN ) ) ) {
			$this->forget( $directory );
			$folder = null;
		}
		if ( null === $folder ) {
			return $this->scan( $directory, $name, $before );
		}
		// The most recently used folder moves to the end, so the oldest leaves first.
		unset( $this->folders[ $directory ] );
		$this->folders[ $directory ] = $folder;
		$spelling                    = $folder['names'][ $this->code( ApplyPlan::fold( $name ) ) ] ?? null;
		return null === $spelling || $spelling === $this->code( $name );
	}

	/**
	 * Accounts for a name this writer has just created, replaced or removed in a folder that
	 * a recheck validated immediately before the operation.
	 *
	 * @param string $path Live entry.
	 * @param bool   $exists Whether the name exists after the operation.
	 */
	public function changed( string $path, bool $exists ): void {
		$directory = dirname( $path );
		if ( ! isset( $this->folders[ $directory ] ) ) {
			return;
		}
		$after = self::observe( $directory );
		// Listing a narrow folder again is cheap and sees every other change as well.
		if ( null === $after || count( $this->folders[ $directory ]['names'] ) <= $this->wide ) {
			$this->forget( $directory );
			return;
		}
		$name     = basename( $path );
		$code     = $this->code( ApplyPlan::fold( $name ) );
		$spelling = $this->code( $name );
		// Changed in place: a copy of a wide folder for every publication would cost N².
		$folder  = &$this->folders[ $directory ];
		$current = $folder['names'][ $code ] ?? null;
		if ( $exists ) {
			$this->held              += null === $current ? 1 : 0;
			$folder['names'][ $code ] = null === $current || $current === $spelling ? $spelling : false;
		} elseif ( $current === $spelling ) {
			unset( $folder['names'][ $code ] );
			--$this->held;
		}
		$folder['identity'] = $after['key'];
		// A folder that is already trusted only until an earlier second keeps that deadline.
		if ( null === $folder['until'] && ! self::settled( $after ) ) {
			$folder['until'] = $after['ctime'];
		}
		unset( $folder );
		if ( $this->held > $this->capacity ) {
			$this->forget( $directory );
		}
	}

	/**
	 * Number of folder listings so far, for diagnostics and tests.
	 *
	 * @return int Listings.
	 */
	public function listings(): int {
		return $this->listings;
	}

	/**
	 * Lists a folder, answers the recheck from the exact names and keeps the listing for
	 * later rechecks when the folder kept its identity throughout.
	 *
	 * @param string      $directory Folder.
	 * @param string      $name Requested spelling.
	 * @param Observation $before Folder identity just before the listing.
	 * @return bool Whether no other spelling of this name exists.
	 */
	private function scan( string $directory, string $name, array $before ): bool {
		$handle = opendir( $directory );
		if ( false === $handle ) {
			return false;
		}
		++$this->listings;
		$key   = ApplyPlan::fold( $name );
		$valid = true;
		$names = [];
		$count = 0;
		$keep  = true;
		try {
			for ( $entry = readdir( $handle ); false !== $entry; $entry = readdir( $handle ) ) {
				$folded = ApplyPlan::fold( $entry );
				if ( $folded === $key && $entry !== $name ) {
					$valid = false;
				}
				if ( ! $keep ) {
					continue;
				}
				$code           = $this->code( $folded );
				$spelling       = $this->code( $entry );
				$names[ $code ] = array_key_exists( $code, $names ) && $names[ $code ] !== $spelling ? false : $spelling;
				if ( ++$count > $this->capacity ) {
					$keep  = false;
					$names = [];
				}
			}
		} finally {
			closedir( $handle );
		}
		$after   = self::observe( $directory );
		$settled = self::settled( $before );
		if ( $keep && null !== $after && $after['key'] === $before['key'] && ( $settled || $count > $this->wide ) ) {
			$this->retain( $directory, $before['key'], $settled ? null : $before['ctime'], $names );
		}
		return $valid;
	}

	/**
	 * Keeps a listing, removing the least recently used folders to stay in bounds.
	 *
	 * @param string                $directory Folder.
	 * @param string                $identity Identity that held before and after the listing.
	 * @param int|null              $until Second whose changes the listing may miss, or null.
	 * @param array<int, int|false> $names Codes of its names.
	 */
	private function retain( string $directory, string $identity, ?int $until, array $names ): void {
		$size = count( $names );
		while ( [] !== $this->folders && $this->held + $size > $this->capacity ) {
			$this->forget( array_key_first( $this->folders ) );
		}
		$this->folders[ $directory ] = [
			'identity' => $identity,
			'until'    => $until,
			'names'    => $names,
		];
		$this->held                 += $size;
	}

	/**
	 * Drops a folder from the index.
	 *
	 * @param string $directory Folder.
	 */
	private function forget( string $directory ): void {
		if ( isset( $this->folders[ $directory ] ) ) {
			$this->held -= count( $this->folders[ $directory ]['names'] );
			unset( $this->folders[ $directory ] );
		}
	}

	/**
	 * Whether any later change of the folder will alter the change time in its identity.
	 *
	 * @param Observation $observation Identity and when it was read.
	 * @return bool Whether the second of the last change and the margin have passed.
	 */
	private static function settled( array $observation ): bool {
		return $observation['now'] >= $observation['ctime'] + 1 + self::CLOCK_MARGIN;
	}

	/**
	 * Reads a folder's identity together with the time it was read.
	 *
	 * @param string $directory Folder.
	 * @return Observation|null Identity, or null if the path is not a directory.
	 */
	private static function observe( string $directory ): ?array {
		$now = microtime( true );
		clearstatcache( true, $directory );
		$stat = is_dir( $directory ) && ! is_link( $directory ) ? lstat( $directory ) : false;
		if ( false === $stat || 0040000 !== ( $stat['mode'] & 0170000 ) ) {
			return null;
		}
		return [
			'key'   => implode( ':', [ $stat['dev'], $stat['ino'], $stat['mode'], $stat['uid'], $stat['gid'], $stat['nlink'], $stat['size'], $stat['mtime'], $stat['ctime'] ] ),
			'ctime' => $stat['ctime'],
			'now'   => $now,
		];
	}

	/**
	 * Salted 64-bit code of a name.
	 *
	 * @param string $value Folded or exact name.
	 * @return int Code.
	 */
	private function code( string $value ): int {
		$code = unpack( 'q', hash( 'xxh3', $value, true, [ 'seed' => $this->seed ] ) );
		return false === $code ? 0 : $code[1];
	}
}
