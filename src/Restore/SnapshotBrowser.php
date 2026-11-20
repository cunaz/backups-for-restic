<?php
/**
 * Snapshots, their manifests and their directories as data for a response.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Engine\Snapshot\Path;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\ManifestNotLocated;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\UnreadableManifest;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Clock;
use BackupsForRestic\Support\GoQuote;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what the snapshot reader returns into arrays that JSON can carry, for the REST routes
 * and the command line (docs/02-architecture.md §13.2). Names in a repository are raw bytes
 * and JSON takes only UTF-8, so every entry carries a text to show and a token that names its
 * path without loss (ADR 0039). A directory is answered in pages, because a repository may
 * hold one with more entries than a response should carry. The list of this site's snapshots
 * also says for each one what the next forget would do with it; that preview only reads.
 *
 * Requests are refused with an InvalidArgument whose context names a code of this class;
 * failure() turns any error of a read into the code and the HTTP status of the answer.
 *
 * @phpstan-type Entry array{name: string, exact: bool, token: string, type: string, size: int|null, mtime: string|null, mode: string, target: string|null, target_raw?: string, incomplete: bool}
 */
final class SnapshotBrowser {

	public const string SCOPE_SITE       = 'site';
	public const string SCOPE_REPOSITORY = 'repository';

	/** The next forget of this site keeps the snapshot. */
	public const string RETENTION_KEEP = 'keep';

	/** The next forget of this site removes the snapshot. */
	public const string RETENTION_REMOVE = 'remove';

	/** Entries of a directory in one answer unless the request names a limit. */
	public const int DEFAULT_LIMIT = 200;

	/** Most entries of a directory in one answer; a larger limit is lowered to it. */
	public const int MAX_LIMIT = 1000;

	/** A path token is this prefix and the base64url form of the path's bytes. */
	public const string TOKEN_PREFIX = 'b64:';

	/** Longest token: the prefix and the base64url form of the longest path. */
	private const int MAX_TOKEN_LENGTH = 5466;

	/** Fewest characters of a shortened snapshot ID, as many as the snapshot list prints. */
	private const int MIN_ID_PREFIX = 8;

	/** HTTP status of the refusals that this class raises. */
	private const array STATUS = [
		'invalid_snapshot_id'     => 400,
		'invalid_snapshot_path'   => 400,
		'invalid_snapshot_query'  => 400,
		'snapshot_not_found'      => 404,
		'snapshot_path_not_found' => 404,
	];

	/**
	 * Creates the browser.
	 *
	 * @param Reader               $reader    Reader of the open repository.
	 * @param string               $hostname  Host name of this site's snapshots.
	 * @param list<string>         $site_tags Tags that this site's snapshots carry, as the retention selects them.
	 * @param RetentionPolicy|null $policy    Policy of the next forget of this site; null if it would remove nothing.
	 * @param Clock|null           $clock     Time that the policy counts back from; null for the system clock.
	 */
	public function __construct(
		private readonly Reader $reader,
		private readonly string $hostname,
		private readonly array $site_tags,
		private readonly ?RetentionPolicy $policy = null,
		private readonly ?Clock $clock = null
	) {
	}

	/**
	 * Lists snapshots, newest first. In the site scope every snapshot says under retention
	 * whether the next forget keeps or removes it. The field is null where no forget of this
	 * site decides: in the repository scope, which also lists snapshots that the retention
	 * never touches, and while the site has no policy to apply.
	 *
	 * @param string $scope site for the snapshots of this site, repository for all of them.
	 * @return list<array<string, mixed>> Snapshots with the fields of summary() and retention.
	 * @throws InvalidArgument If the scope is neither of the two.
	 */
	public function snapshots( string $scope = self::SCOPE_SITE ): array {
		self::scope( $scope );
		$site      = self::SCOPE_SITE === $scope;
		$found     = $site ? $this->reader->snapshots( $this->hostname, $this->site_tags ) : $this->reader->snapshots();
		$retention = $site ? $this->retention( $found ) : [];
		$list      = [];
		foreach ( $found as $id => $snapshot ) {
			$list[] = self::summary( $id, $snapshot ) + [ 'retention' => $retention[ $id ] ?? null ];
		}
		return $list;
	}

	/**
	 * Checks the scope of a snapshot list before a cache or the repository is asked.
	 *
	 * @param string $scope Scope from a request.
	 * @return string The scope.
	 * @throws InvalidArgument If the scope is neither site nor repository.
	 */
	public static function scope( string $scope ): string {
		if ( self::SCOPE_SITE !== $scope && self::SCOPE_REPOSITORY !== $scope ) {
			InvalidArgument::raise( 'The scope of a snapshot list is site or repository.', [ 'code' => 'invalid_snapshot_query' ] );
		}
		return $scope;
	}

	/**
	 * One snapshot with the site's manifest. A manifest that cannot be located or read does
	 * not hide the snapshot: its files can still be browsed, and manifest_state says why the
	 * manifest is missing.
	 *
	 * @param string $id Full snapshot ID.
	 * @return array<string, mixed> The fields of summary(), paths, tree, manifest and manifest_state.
	 * @throws InvalidArgument If the ID is malformed or no snapshot has it.
	 */
	public function snapshot( string $id ): array {
		$parsed   = self::id( $id );
		$snapshot = $this->load( $parsed );
		$manifest = null;
		$state    = 'none';
		try {
			$found = $this->reader->manifest( $parsed );
			if ( null !== $found ) {
				$state    = 'found';
				$manifest = [
					'path'  => self::text( $found['path'] ),
					'token' => self::token( $found['path'] ),
					'data'  => $found['data'],
				];
			}
		} catch ( ManifestNotLocated | UnreadableManifest ) {
			// Several candidates, a search that is too large, or a file that is no valid manifest.
			$state = 'unreadable';
		}
		return self::summary( $parsed->hex(), $snapshot ) + [
			'paths'          => array_map( self::text( ... ), $snapshot->paths ),
			'tree'           => $snapshot->tree->hex(),
			'manifest'       => $manifest,
			'manifest_state' => $state,
		];
	}

	/**
	 * One page of one directory, in the order the snapshot stores it.
	 *
	 * @param string   $id     Full snapshot ID.
	 * @param string   $path   Absolute path inside the snapshot, or the token of an entry.
	 * @param int      $offset Entries to skip.
	 * @param int|null $limit  Most entries to return, lowered to MAX_LIMIT; null for all of them, which only the command line asks for.
	 * @return array{snapshot: string, path: string, exact: bool, token: string, parent: string|null, total: int, offset: int, limit: int, next: int|null, entries: list<Entry>} The page.
	 * @throws InvalidArgument If the ID or the path is malformed, or the snapshot or the directory does not exist.
	 * @throws PluginException If the repository is locked, cannot be reached or lacks data of the snapshot.
	 */
	public function directory( string $id, string $path = '/', int $offset = 0, ?int $limit = self::DEFAULT_LIMIT ): array {
		$parsed = self::id( $id );
		$raw    = self::path( $path );
		try {
			$nodes = $this->reader->directory( $parsed, $raw );
		} catch ( PluginException $error ) {
			if ( $error instanceof InvalidArgument ) {
				// The path is well-formed, so the reader refused what is absent or no directory.
				InvalidArgument::raise( 'The path is not a directory in this snapshot.', [ 'code' => 'snapshot_path_not_found' ], $error );
			}
			if ( $error instanceof NotFound ) {
				// The snapshot itself is missing, or one of its trees: only the first is the caller's mistake.
				$this->load( $parsed );
			}
			throw $error;
		}
		$total   = count( $nodes );
		$offset  = max( 0, $offset );
		$limit   = null === $limit ? max( 1, $total ) : max( 1, min( self::MAX_LIMIT, $limit ) );
		$parent  = '/' === $raw ? '' : rtrim( $raw, '/' );
		$entries = [];
		foreach ( array_slice( $nodes, $offset, $limit ) as $node ) {
			$entries[] = self::entry( $parent, $node );
		}
		$end = $offset + count( $entries );
		$own = '' === $parent ? '/' : $parent;
		$up  = substr( $parent, 0, (int) strrpos( $parent, '/' ) );
		return [
			'snapshot' => $parsed->hex(),
			'path'     => self::text( $own ),
			'exact'    => self::is_text( $own ),
			'token'    => self::token( $own ),
			'parent'   => '' === $parent ? null : self::token( '' === $up ? '/' : $up ),
			'total'    => $total,
			'offset'   => $offset,
			'limit'    => $limit,
			'next'     => $end < $total ? $end : null,
			'entries'  => $entries,
		];
	}

	/**
	 * The full ID for what a command line names: a full ID, or the start of exactly one.
	 *
	 * @param string $given Full ID, or at least its first eight characters.
	 * @return string Full ID in hex.
	 * @throws InvalidArgument If the text is no ID, matches several snapshots or matches none.
	 */
	public function resolve( string $given ): string {
		if ( 64 === strlen( $given ) ) {
			return self::id( $given )->hex();
		}
		if ( strlen( $given ) < self::MIN_ID_PREFIX || strlen( $given ) > 64 || strspn( $given, '0123456789abcdef' ) !== strlen( $given ) ) {
			InvalidArgument::raise( 'A snapshot ID has 64 hex digits; a shortened one has at least 8.', [ 'code' => 'invalid_snapshot_id' ] );
		}
		$matches = [];
		foreach ( array_keys( $this->reader->snapshots() ) as $id ) {
			if ( str_starts_with( $id, $given ) ) {
				$matches[] = $id;
			}
		}
		if ( [] === $matches ) {
			InvalidArgument::raise( 'The repository has no snapshot with this ID.', [ 'code' => 'snapshot_not_found' ] );
		}
		if ( count( $matches ) > 1 ) {
			InvalidArgument::raise( 'Several snapshots start with this ID.', [ 'code' => 'invalid_snapshot_id' ] );
		}
		return $matches[0];
	}

	/**
	 * Offset and limit of a request. A limit above the maximum is lowered to it, so a client
	 * may ask for more than it gets and reads the limit that applied from the answer.
	 *
	 * @param mixed $offset Request value; null or an empty string for the start.
	 * @param mixed $limit  Request value; null or an empty string for the default.
	 * @return array{0: int, 1: int} Offset and limit.
	 * @throws InvalidArgument If a value is no whole number, or the limit is 0.
	 */
	public static function paging( mixed $offset, mixed $limit ): array {
		$offset = self::number( $offset, 0 );
		$limit  = self::number( $limit, self::DEFAULT_LIMIT );
		if ( 0 === $limit ) {
			InvalidArgument::raise( 'The limit of a directory page is at least 1.', [ 'code' => 'invalid_snapshot_query' ] );
		}
		return [ $offset, min( self::MAX_LIMIT, $limit ) ];
	}

	/**
	 * The raw path that a request names: an absolute path as it is, or a token decoded.
	 *
	 * @param string $given Path starting with a slash, or a token.
	 * @return string Raw path bytes that the reader accepts.
	 * @throws InvalidArgument If the token or the path is malformed.
	 */
	public static function path( string $given ): string {
		$raw = $given;
		try {
			if ( str_starts_with( $given, self::TOKEN_PREFIX ) ) {
				if ( strlen( $given ) > self::MAX_TOKEN_LENGTH ) {
					throw new InvalidArgument( 'The path token is longer than the longest path.' );
				}
				$raw = Bytes::base64url_decode( substr( $given, strlen( self::TOKEN_PREFIX ) ) );
			}
			Path::parts( $raw );
		} catch ( InvalidArgument | FormatException $refusal ) {
			InvalidArgument::raise( 'The snapshot path is malformed.', [ 'code' => 'invalid_snapshot_path' ], $refusal );
		}
		return $raw;
	}

	/**
	 * The token of a raw path: what a client sends back to name the path byte for byte.
	 *
	 * @param string $raw Raw path bytes.
	 * @return string Token.
	 */
	public static function token( string $raw ): string {
		return self::TOKEN_PREFIX . Bytes::base64url_encode( $raw );
	}

	/**
	 * One line of a directory listing for a terminal. The name comes from the token, so it
	 * is the stored one; a name with control characters, a quote, a backslash or invalid
	 * UTF-8 is printed quoted with escapes, because a stored name must not be able to send
	 * control sequences to the terminal. The token is only decoded and not checked as a
	 * path: an entry beyond the limits of a path cannot be opened, but its directory lists it.
	 *
	 * @param Entry $entry Entry of a directory page.
	 * @return string Line: type, mode, size, modification time, name and the target of a link.
	 * @throws FormatException If the token is not one that directory() issued.
	 */
	public static function line( array $entry ): string {
		$path = Bytes::base64url_decode( substr( $entry['token'], strlen( self::TOKEN_PREFIX ) ) );
		$name = self::printable( substr( $path, (int) strrpos( $path, '/' ) + 1 ) );
		if ( 'dir' === $entry['type'] ) {
			$name .= '/';
		}
		if ( null !== $entry['target'] ) {
			$target = isset( $entry['target_raw'] ) ? Bytes::base64_decode( $entry['target_raw'] ) : $entry['target'];
			$name  .= ' -> ' . self::printable( $target );
		}
		$letter = match ( $entry['type'] ) {
			'file'    => '-',
			'dir'     => 'd',
			'symlink' => 'l',
			default   => '?',
		};
		return sprintf( '%s %s %12s  %-20s  %s', $letter, $entry['mode'], null === $entry['size'] ? '-' : (string) $entry['size'], $entry['mtime'] ?? '-', $name );
	}

	/**
	 * Code and HTTP status for an error of a read: the refusals of this class with their
	 * own status, a locked repository as a conflict, and everything else as an answer of
	 * the repository that the plugin could not use.
	 *
	 * @param PluginException $error Error.
	 * @return array{0: string, 1: int} Code of docs/02-architecture.md §14 and HTTP status.
	 */
	public static function failure( PluginException $error ): array {
		$code = $error->context()['code'] ?? null;
		if ( $error instanceof InvalidArgument && is_string( $code ) && isset( self::STATUS[ $code ] ) ) {
			return [ $code, self::STATUS[ $code ] ];
		}
		$code = JobError::from_throwable( $error )->code;
		return [ $code, 'repository_locked' === $code ? 409 : 502 ];
	}

	/**
	 * Checks a full snapshot ID, so that a route refuses a malformed one before it opens
	 * the repository.
	 *
	 * @param string $id Request value.
	 * @return string The ID.
	 * @throws InvalidArgument If the text is not 64 lower-case hex digits.
	 */
	public static function full_id( string $id ): string {
		return self::id( $id )->hex();
	}

	/**
	 * Parses a full snapshot ID.
	 *
	 * @param string $id Request value.
	 * @return Id ID.
	 * @throws InvalidArgument If the text is not 64 lower-case hex digits.
	 */
	private static function id( string $id ): Id {
		if ( 64 !== strlen( $id ) || 64 !== strspn( $id, '0123456789abcdef' ) ) {
			InvalidArgument::raise( 'A snapshot ID has 64 hex digits.', [ 'code' => 'invalid_snapshot_id' ] );
		}
		return Id::from_hex( $id );
	}

	/**
	 * What the next forget of this site would do with each of its snapshots. The decision is
	 * the engine's own, over the group that ForgetRun forms: the snapshots with this site's
	 * host name and the required tags are one group, whatever other tags they carry. Nothing
	 * is written here, so a snapshot that the answer marks is still in the repository.
	 *
	 * @param array<string, Snapshot> $snapshots This site's snapshots by hex ID.
	 * @return array<string, string> RETENTION_KEEP or RETENTION_REMOVE by hex ID; empty without a policy.
	 */
	private function retention( array $snapshots ): array {
		// An empty policy keeps nothing and is never applied, so it previews nothing either.
		if ( null === $this->policy || $this->policy->is_empty() ) {
			return [];
		}
		$decisions = [];
		foreach ( Forget::group( $snapshots, true, false, false ) as $group ) {
			$decision = Forget::apply( $group, $this->policy, $this->clock?->now() );
			foreach ( $decision['keep'] as $id ) {
				$decisions[ $id ] = self::RETENTION_KEEP;
			}
			foreach ( $decision['remove'] as $id ) {
				$decisions[ $id ] = self::RETENTION_REMOVE;
			}
		}
		return $decisions;
	}

	/**
	 * Loads a snapshot and tells a missing one from a repository that cannot be reached.
	 *
	 * @param Id $id Snapshot ID.
	 * @return Snapshot Snapshot.
	 * @throws InvalidArgument If the repository has no snapshot with this ID.
	 */
	private function load( Id $id ): Snapshot {
		try {
			return $this->reader->snapshot( $id );
		} catch ( NotFound $missing ) {
			InvalidArgument::raise( 'The repository has no snapshot with this ID.', [ 'code' => 'snapshot_not_found' ], $missing );
		}
	}

	/**
	 * The fields of a snapshot in a list; the admin page reads them.
	 *
	 * @param string   $id       Full ID in hex.
	 * @param Snapshot $snapshot Snapshot.
	 * @return array<string, mixed> Fields.
	 */
	private static function summary( string $id, Snapshot $snapshot ): array {
		return [
			'id'         => $id,
			'short_id'   => substr( $id, 0, 8 ),
			'time'       => $snapshot->time,
			'hostname'   => self::text( $snapshot->hostname ),
			'tags'       => array_map( self::text( ... ), $snapshot->tags ),
			'data_added' => $snapshot->summary?->data_added,
			'files'      => null === $snapshot->summary ? null : $snapshot->summary->files_new + $snapshot->summary->files_changed + $snapshot->summary->files_unmodified,
			'bytes'      => $snapshot->summary?->total_bytes_processed,
		];
	}

	/**
	 * One entry of a directory. Times and modes come from the repository and are brought
	 * into the form the answer promises instead of being passed on as they are; the tree
	 * decoder already refuses a size below zero.
	 *
	 * @param string $directory Raw path of the directory without a trailing slash; empty for the root.
	 * @param Node   $node      Entry.
	 * @return Entry Fields.
	 */
	private static function entry( string $directory, Node $node ): array {
		try {
			Rfc3339::parse( $node->mtime );
			$mtime = $node->mtime;
		} catch ( FormatException ) {
			$mtime = null;
		}
		$entry = [
			'name'       => self::text( $node->name ),
			'exact'      => self::is_text( $node->name ),
			'token'      => self::token( $directory . '/' . $node->name ),
			'type'       => match ( $node->type ) {
				NodeType::File    => 'file',
				NodeType::Dir     => 'dir',
				NodeType::Symlink => 'symlink',
				default           => 'other',
			},
			'size'       => NodeType::File === $node->type ? $node->size : null,
			'mtime'      => $mtime,
			'mode'       => sprintf( '%04o', FileMode::to_posix_permissions( $node->mode ) ),
			'target'     => NodeType::Symlink === $node->type ? self::text( $node->link_target ) : null,
			'incomplete' => '' !== $node->error,
		];
		if ( NodeType::Symlink === $node->type && $entry['target'] !== $node->link_target ) {
			$entry['target_raw'] = Bytes::base64_encode( $node->link_target );
		}
		return $entry;
	}

	/**
	 * Whether bytes are valid UTF-8, so that JSON carries them unchanged.
	 *
	 * @param string $bytes Bytes from the repository.
	 * @return bool True if they are text.
	 */
	private static function is_text( string $bytes ): bool {
		return 1 === preg_match( '//u', $bytes );
	}

	/**
	 * Text to show for bytes from the repository: the bytes when they are valid UTF-8,
	 * otherwise with U+FFFD in place of every byte that is not part of a valid sequence.
	 *
	 * @param string $bytes Bytes from the repository.
	 * @return string Valid UTF-8.
	 */
	private static function text( string $bytes ): string {
		if ( self::is_text( $bytes ) ) {
			return $bytes;
		}
		$text   = '';
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			// A valid run from here, then one byte that belongs to none. The run is matched in
			// bounded, possessive steps: one unbounded repetition of the group exhausts the
			// stack of the matcher on a long name, and a failed match would read as no run.
			$found = preg_match( '/\G(?:[\x00-\x7F]++|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}){1,32}+/', $bytes, $match, 0, $offset );
			if ( 1 !== $found ) {
				$text .= "\u{FFFD}";
				++$offset;
				continue;
			}
			$text   .= $match[0];
			$offset += strlen( $match[0] );
		}
		return $text;
	}

	/**
	 * A name or link target for a terminal: as it is when it consists of printable
	 * characters only, otherwise in double quotes with Go's escapes.
	 *
	 * @param string $bytes Bytes from the repository.
	 * @return string Text without control characters.
	 */
	private static function printable( string $bytes ): string {
		$quoted = GoQuote::quote( $bytes );
		return $quoted === $bytes ? $bytes : '"' . $quoted . '"';
	}

	/**
	 * A count from a request.
	 *
	 * @param mixed $value   Request value.
	 * @param int   $fallback Value if the request names none.
	 * @return int Count, 0 or more.
	 * @throws InvalidArgument If the value is no whole number of at most nine digits.
	 */
	private static function number( mixed $value, int $fallback ): int {
		if ( null === $value || '' === $value ) {
			return $fallback;
		}
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && strlen( $value ) <= 9 && strspn( $value, '0123456789' ) === strlen( $value ) ) {
			return (int) $value;
		}
		InvalidArgument::raise( 'Offset and limit are whole numbers.', [ 'code' => 'invalid_snapshot_query' ] );
	}
}
