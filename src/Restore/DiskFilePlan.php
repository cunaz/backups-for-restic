<?php
/**
 * Authenticated, paginated private storage for a large file preview.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Files;

defined( 'ABSPATH' ) || exit;

/**
 * A plan is a presentation stream and a stream of references at each destination depth.
 * A page's digest occupies one fixed-width slot in its stream index. The job authenticates
 * the sealed summary, which authenticates every index, which authenticates each page.
 * A directory can therefore be found and checked without loading the entire plan.
 *
 * @phpstan-import-type Row from ApplyManifest
 * @phpstan-type Header array{version:int,subject:string,restore:string,undo:bool,config:bool,entries:int,changes:int,refused:int,required_bytes:int|null,actions:array<string,int>|null,counts:array<string,int>,indexes:array<string,string>}
 */
final class DiskFilePlan {

	public const int PAGE        = 100;
	public const int MAX_ENTRIES = 1000000;

	private const int PAGE_BYTES   = 2097152;
	private const int INDEX_BYTES  = 640000;
	private const int BUCKET_BYTES = 1048576;

	/**
	 * Modified data pages, keyed by file name.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $dirty = [];

	/**
	 * Modified name buckets.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $buckets = [];

	/**
	 * Registry buckets changed by this builder.
	 *
	 * @var array<string,true>
	 */
	private array $changed = [];

	/**
	 * Verified packed ASCII indexes.
	 *
	 * @var array<string,string>
	 */
	private array $indexes = [];

	/**
	 * Verified read-only pages.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $pages = [];

	/**
	 * Verified sealed manifest.
	 *
	 * @var Header|null
	 */
	private ?array $header = null;

	/**
	 * Opens an existing canonical private directory.
	 *
	 * @param string $directory Per-plan directory.
	 * @param bool   $durable Whether every flush reaches the disk before it returns, for records
	 *                        that a durable job document counts, such as the entries an undo kept.
	 * @throws StorageFailed If the directory has become an alias.
	 */
	public function __construct( private readonly string $directory, private readonly bool $durable = false ) {
		if ( realpath( $directory ) !== $directory || ! is_dir( $directory ) ) {
			throw new StorageFailed( 'The private file plan directory is unavailable.' );
		}
	}

	/**
	 * Stores a record at its checkpointed ordinal. Replaying an interrupted batch
	 * truncates any uncommitted tail of that page.
	 *
	 * @param string              $stream Internal stream name.
	 * @param int                 $index Record ordinal.
	 * @param array<string,mixed> $record Row or mutation reference.
	 * @throws StorageFailed If storage or a cursor is invalid.
	 */
	public function put( string $stream, int $index, array $record ): void {
		self::require_stream( $stream, $index );
		$file = $stream . '-' . intdiv( $index, self::PAGE );
		if ( ! isset( $this->dirty[ $file ] ) ) {
			if ( count( $this->dirty ) >= 6 ) {
				$this->flush_pages();
			}
			$this->dirty[ $file ] = is_file( $this->directory . '/' . $file ) ? self::decode_page( self::read( $this->directory . '/' . $file, self::PAGE_BYTES ) ) : [];
		}
		$slot = $index % self::PAGE;
		if ( count( $this->dirty[ $file ] ) < $slot ) {
			throw new StorageFailed( 'A paged file plan has a missing record.' );
		}
		if ( count( $this->dirty[ $file ] ) > $slot ) {
			$this->dirty[ $file ] = array_slice( $this->dirty[ $file ], 0, $slot );
		}
		$this->dirty[ $file ][] = $record;
	}

	/**
	 * Reserves a source or destination identity. A replay at the same ordinal is harmless.
	 *
	 * @param string $kind Registry namespace.
	 * @param string $name Raw path.
	 * @param int    $ordinal Presentation index.
	 * @return int First ordinal claiming the path.
	 */
	public function claim( string $kind, string $name, int $ordinal ): int {
		$key    = hash( 'sha256', $kind . "\0" . $name );
		$bucket = substr( $key, 0, 3 );
		$this->load_bucket( $bucket );
		if ( ! isset( $this->buckets[ $bucket ][ $key ] ) ) {
			$this->buckets[ $bucket ][ $key ] = $ordinal;
			$this->changed[ $bucket ]         = true;
		}
		return $this->buckets[ $bucket ][ $key ];
	}

	/**
	 * Looks up an earlier directory or removal claim. A caller that depends on a claim
	 * before mutation also verifies the corresponding authenticated row; an undo review
	 * keeps its removal claims current with release() instead.
	 *
	 * @param string $kind Registry namespace.
	 * @param string $name Raw name.
	 * @return int|null First presentation index.
	 */
	public function lookup( string $kind, string $name ): ?int {
		$key    = hash( 'sha256', $kind . "\0" . $name );
		$bucket = substr( $key, 0, 3 );
		$this->load_bucket( $bucket );
		return $this->buckets[ $bucket ][ $key ] ?? null;
	}

	/**
	 * Withdraws the claim that a row made, for a row that is reviewed again with another
	 * decision. A claim of another ordinal stays.
	 *
	 * @param string $kind Registry namespace.
	 * @param string $name Raw name.
	 * @param int    $ordinal Presentation index of the row reviewed again.
	 */
	public function release( string $kind, string $name, int $ordinal ): void {
		$key    = hash( 'sha256', $kind . "\0" . $name );
		$bucket = substr( $key, 0, 3 );
		$this->load_bucket( $bucket );
		if ( $ordinal === ( $this->buckets[ $bucket ][ $key ] ?? null ) ) {
			unset( $this->buckets[ $bucket ][ $key ] );
			$this->changed[ $bucket ] = true;
		}
	}

	/**
	 * Loads one hash bucket. The complete registry can contain millions of names.
	 *
	 * @param string $bucket Three hexadecimal characters.
	 * @throws StorageFailed If a registry does not decode.
	 */
	private function load_bucket( string $bucket ): void {
		if ( isset( $this->buckets[ $bucket ] ) ) {
			return;
		}
		if ( count( $this->buckets ) >= 16 ) {
			$this->flush_buckets();
		}
		$file    = $this->directory . '/key-' . $bucket;
		$entries = is_file( $file ) ? json_decode( self::read( $file, self::BUCKET_BYTES ), true ) : [];
		if ( ! is_array( $entries ) ) {
			throw new StorageFailed( 'A file plan registry is invalid.' );
		}
		$result = [];
		foreach ( $entries as $key => $index ) {
			if ( ! is_string( $key ) || 64 !== strlen( $key ) || ! is_int( $index ) || $index < 0 || $index >= self::MAX_ENTRIES ) {
				throw new StorageFailed( 'A file plan registry entry is invalid.' );
			}
			$result[ $key ] = $index;
		}
		$this->buckets[ $bucket ] = $result;
	}

	/**
	 * Makes all pages and name claims durable before the worker advances its checkpoint.
	 *
	 * @throws StorageFailed If a page or index cannot be persisted.
	 */
	public function flush(): void {
		$this->flush_pages();
		$this->flush_buckets();
	}

	/**
	 * Writes modified pages and their fixed-width authentication slots.
	 *
	 * @throws StorageFailed If a data page or its digest cannot be saved.
	 */
	private function flush_pages(): void {
		foreach ( $this->dirty as $file => $page ) {
			$body = json_encode( $page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			if ( strlen( $body ) > self::PAGE_BYTES ) {
				throw new StorageFailed( 'A private file plan page exceeds its storage limit.' );
			}
			if ( $this->durable ) {
				Files::write_durable( $this->directory . '/' . $file, $body );
			} else {
				Files::write_atomic( $this->directory . '/' . $file, $body );
			}
			[ $stream, $page_number ] = explode( '-', $file, 2 );
			$index                    = $this->directory . '/' . $stream . '.idx';
			$offset                   = (int) $page_number * 64;
			if ( $this->durable ) {
				// A durable plan's index is replaced as a whole, on disk before a document counts
				// its records: a stop or a short write leaves the slots that were last committed,
				// and a page written before its slot can still be cut back to what they authenticate.
				$slots = is_file( $index ) ? self::read( $index, self::INDEX_BYTES ) : '';
				Files::write_durable( $index, str_pad( substr( $slots, 0, $offset ), $offset, "\0" ) . hash( 'sha256', $body ) . substr( $slots, $offset + 64 ) );
				continue;
			}
			$handle = fopen( $index, 'c+b' );
			if ( false === $handle ) {
				throw new StorageFailed( 'A private file plan index could not be opened.' );
			}
			try {
				if ( 0 !== fseek( $handle, $offset ) || 64 !== fwrite( $handle, hash( 'sha256', $body ) ) || ! fflush( $handle ) ) {
					throw new StorageFailed( 'A private file plan index could not be written.' );
				}
			} finally {
				fclose( $handle );
			}
		}
		$this->dirty = [];
	}

	/**
	 * Releases name lookups independently of the current data page.
	 *
	 * @throws StorageFailed If a bounded registry cannot be saved.
	 */
	private function flush_buckets(): void {
		foreach ( $this->changed as $bucket => $_changed ) {
			$entries = $this->buckets[ $bucket ];
			$text    = json_encode( $entries, JSON_THROW_ON_ERROR );
			if ( strlen( $text ) > self::BUCKET_BYTES ) {
				throw new StorageFailed( 'A private file plan registry exceeds its storage limit.' );
			}
			Files::write_atomic( $this->directory . '/key-' . $bucket, $text );
		}
		$this->buckets = [];
		$this->changed = [];
	}

	/**
	 * Authenticates all stream indexes and publishes the immutable plan summary.
	 *
	 * @param string                 $subject Restore or apply job ID.
	 * @param string                 $restore Staged restore job.
	 * @param bool                   $undo Undo preview.
	 * @param bool                   $config Configuration option.
	 * @param array<string,int>      $counts Records per stream.
	 * @param int                    $changes Applicable mutations.
	 * @param int                    $refused Refused actions.
	 * @param int|null               $required_bytes Estimated peak file data on the journal volume.
	 * @param array<string,int>|null $actions Rows by action, which an undo review shows as its totals.
	 * @return string Digest to persist in the job document.
	 * @throws StorageFailed If a stream is incomplete.
	 */
	public function seal( string $subject, string $restore, bool $undo, bool $config, array $counts, int $changes, int $refused, ?int $required_bytes = null, ?array $actions = null ): string {
		$this->flush();
		$indexes = [];
		ksort( $counts, SORT_STRING );
		foreach ( $counts as $name => $count ) {
			if ( 0 === $count ) {
				continue;
			}
			$index    = $this->directory . '/' . $name . '.idx';
			$data     = self::read( $index, self::INDEX_BYTES );
			$expected = (int) ceil( $count / self::PAGE ) * 64;
			if ( strlen( $data ) > $expected ) {
				// A worker stopped after it wrote the first record of a page and before it
				// counted it leaves that page's slot behind; no counted record is on it.
				$data = substr( $data, 0, $expected );
				if ( $this->durable ) {
					Files::write_durable( $index, $data );
				} else {
					Files::write_atomic( $index, $data );
				}
			}
			if ( strlen( $data ) !== $expected ) {
				throw new StorageFailed( 'A private file plan index is incomplete.' );
			}
			$this->agree( $name, $count, $data );
			$indexes[ $name ] = hash( 'sha256', $data );
		}
		$header = [
			'version' => 1,
			'subject' => $subject,
			'restore' => $restore,
			'undo'    => $undo,
			'config'  => $config,
			'entries' => $counts['rows'] ?? 0,
			'changes' => $changes,
			'refused' => $refused,
			'counts'  => $counts,
			'indexes' => $indexes,
		];
		if ( null !== $required_bytes ) {
			$header['required_bytes'] = $required_bytes;
		}
		if ( null !== $actions ) {
			ksort( $actions, SORT_STRING );
			$header['actions'] = $actions;
		}
		$body = json_encode( $header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( $this->durable ) {
			Files::write_durable( $this->directory . '/sealed.json', $body );
		} else {
			Files::write_atomic( $this->directory . '/sealed.json', $body );
		}
		return hash( 'sha256', $body );
	}

	/**
	 * Makes the last counted page of a stream agree with its slot before the index is sealed.
	 * A worker stopped after it wrote that page with a record beyond the counted ones and
	 * before it updated the page's slot leaves a page that the slot no longer authenticates;
	 * its counted records are still the ones the slot was written for. Such a page is cut to
	 * them. A page that does not match its slot in any other way is refused.
	 *
	 * @param string $stream Stream name.
	 * @param int    $count Counted records.
	 * @param string $index Index of the stream, cut to the counted pages.
	 * @throws StorageFailed If the page does not match its slot.
	 */
	private function agree( string $stream, int $count, string $index ): void {
		$page = intdiv( $count - 1, self::PAGE );
		$file = $this->directory . '/' . $stream . '-' . $page;
		$body = self::read( $file, self::PAGE_BYTES );
		$slot = substr( $index, $page * 64, 64 );
		if ( hash_equals( $slot, hash( 'sha256', $body ) ) ) {
			return;
		}
		$counted = json_encode( array_slice( self::decode_page( $body ), 0, $count - $page * self::PAGE ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( ! hash_equals( $slot, hash( 'sha256', $counted ) ) ) {
			throw new StorageFailed( 'A private file plan page does not match its index.' );
		}
		if ( $this->durable ) {
			Files::write_durable( $file, $counted );
		} else {
			Files::write_atomic( $file, $counted );
		}
	}

	/**
	 * Verifies the summary obtained from a successful background job.
	 *
	 * @param string $digest Trusted digest from the job.
	 * @return Header Verified metadata.
	 * @throws StorageFailed If the private plan changed or is incomplete.
	 */
	public function open( string $digest ): array {
		$text  = self::read( $this->directory . '/sealed.json', 131072 );
		$value = json_decode( $text, true );
		if ( ! hash_equals( $digest, hash( 'sha256', $text ) ) || ! is_array( $value ) || 1 !== ( $value['version'] ?? null ) || ! is_string( $value['subject'] ?? null ) || ! is_string( $value['restore'] ?? null ) || ! is_bool( $value['undo'] ?? null ) || ! is_bool( $value['config'] ?? null ) || ! is_int( $value['entries'] ?? null ) || ! is_int( $value['changes'] ?? null ) || ! is_int( $value['refused'] ?? null ) || ! is_array( $value['counts'] ?? null ) || ! is_array( $value['indexes'] ?? null ) ) {
			throw new StorageFailed( 'The sealed private file plan cannot be verified.' );
		}
		$counts  = [];
		$indexes = [];
		foreach ( $value['counts'] as $name => $count ) {
			if ( ! is_string( $name ) || ! is_int( $count ) || $count < 0 || $count > self::MAX_ENTRIES ) {
				throw new StorageFailed( 'The sealed file plan contains an invalid stream.' );
			}
			self::require_stream( $name, max( 0, $count - 1 ) );
			$counts[ $name ] = $count;
			if ( $count > 0 ) {
				$hash = $value['indexes'][ $name ] ?? null;
				if ( ! is_string( $hash ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ) {
					throw new StorageFailed( 'The private file plan lacks an index digest.' );
				}
				$indexes[ $name ] = $hash;
			}
		}
		$required = $value['required_bytes'] ?? null;
		if ( null !== $required && ( ! is_int( $required ) || $required < 0 ) ) {
			throw new StorageFailed( 'The sealed plan contains an invalid file-space estimate.' );
		}
		// A plan sealed before the totals by action existed has none.
		$actions = null;
		if ( isset( $value['actions'] ) ) {
			if ( ! is_array( $value['actions'] ) ) {
				throw new StorageFailed( 'The sealed plan contains invalid totals.' );
			}
			$actions = [];
			foreach ( $value['actions'] as $action => $count ) {
				if ( ! is_string( $action ) || ! is_int( $count ) || $count < 0 || $count > self::MAX_ENTRIES ) {
					throw new StorageFailed( 'The sealed plan contains invalid totals.' );
				}
				$actions[ $action ] = $count;
			}
		}
		$this->header = [
			'required_bytes' => $required,
			'actions'        => $actions,
			'version'        => 1,
			'subject'        => $value['subject'],
			'restore'        => $value['restore'],
			'undo'           => $value['undo'],
			'config'         => $value['config'],
			'entries'        => $value['entries'],
			'changes'        => $value['changes'],
			'refused'        => $value['refused'],
			'counts'         => $counts,
			'indexes'        => $indexes,
		];
		return $this->header;
	}

	/**
	 * Returns metadata of the authenticated plan currently open.
	 *
	 * @return Header Verified summary.
	 * @throws StorageFailed If the plan has not been opened.
	 */
	public function header(): array {
		return $this->header ?? throw new StorageFailed( 'The file plan is not sealed.' );
	}

	/**
	 * Reads a bounded page of reviewed rows.
	 *
	 * @param int $offset Presentation ordinal.
	 * @return list<Row> Up to PAGE rows.
	 */
	public function page( int $offset ): array {
		$rows  = [];
		$total = $this->header['entries'] ?? 0;
		$end   = min( $total, $offset + self::PAGE );
		for ( $i = $offset; $i < $end; ++$i ) {
			$rows[] = $this->row( $i );
		}
		return $rows;
	}

	/**
	 * Looks up one reviewed row by its presentation ordinal.
	 *
	 * @param int $index Row index.
	 * @return Row Authenticated row.
	 */
	public function row( int $index ): array {
		return ApplyManifest::checked( $this->read_record( 'rows', $index ) );
	}

	/**
	 * Finds the journal entry at a given mutation ordinal. Parents have lower destination
	 * depth than children; reverse iteration therefore undoes children first.
	 *
	 * @param int $ordinal Mutation index.
	 * @return array{index:int,row:Row} Presentation index and verified row.
	 * @throws StorageFailed If the ordinal is outside the plan.
	 */
	public function mutation( int $ordinal ): array {
		$counts = $this->header['counts'] ?? [];
		foreach ( $counts as $name => $count ) {
			if ( ! str_starts_with( $name, 'd' ) ) {
				continue;
			}
			if ( $ordinal >= $count ) {
				$ordinal -= $count;
				continue;
			}
			$index = $this->read_record( $name, $ordinal )['row'] ?? null;
			if ( ! is_int( $index ) ) {
				break;
			}
			return [
				'index' => $index,
				'row'   => $this->row( $index ),
			];
		}
		throw new StorageFailed( 'A file mutation ordinal is outside the approved plan.' );
	}

	/**
	 * Reads one authenticated record from a fixed-size page.
	 *
	 * @param string $stream Internal name.
	 * @param int    $index Ordinal.
	 * @return array<string,mixed> Row or reference.
	 * @throws StorageFailed If an index or page was edited.
	 */
	private function read_record( string $stream, int $index ): array {
		self::require_stream( $stream, $index );
		$expected = $this->header['indexes'][ $stream ] ?? null;
		if ( null === $expected || $index >= ( $this->header['counts'][ $stream ] ?? 0 ) ) {
			throw new StorageFailed( 'A file plan record is outside the verified index.' );
		}
		if ( ! isset( $this->indexes[ $stream ] ) ) {
			$text = self::read( $this->directory . '/' . $stream . '.idx', self::INDEX_BYTES );
			if ( ! hash_equals( $expected, hash( 'sha256', $text ) ) ) {
				throw new StorageFailed( 'A sealed file plan index changed.' );
			}
			$this->indexes[ $stream ] = $text;
		}
		$page = intdiv( $index, self::PAGE );
		$name = $stream . '-' . $page;
		if ( ! isset( $this->pages[ $name ] ) ) {
			$body = self::read( $this->directory . '/' . $name, self::PAGE_BYTES );
			if ( ! hash_equals( substr( $this->indexes[ $stream ], $page * 64, 64 ), hash( 'sha256', $body ) ) ) {
				throw new StorageFailed( 'A sealed file plan page changed.' );
			}
			if ( count( $this->pages ) >= 4 ) {
				array_shift( $this->pages );
			}
			$this->pages[ $name ] = self::decode_page( $body );
		}
		return $this->pages[ $name ][ $index % self::PAGE ] ?? throw new StorageFailed( 'A sealed file plan row is missing.' );
	}

	/**
	 * Decodes only bounded, string-keyed record objects.
	 *
	 * @param string $body JSON.
	 * @return list<array<string,mixed>> Records.
	 * @throws StorageFailed If the page is malformed.
	 */
	private static function decode_page( string $body ): array {
		$rows = json_decode( $body, true );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > self::PAGE ) {
			throw new StorageFailed( 'A file plan page is invalid.' );
		}
		$records = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new StorageFailed( 'A file plan record is invalid.' );
			}
			$record = [];
			foreach ( $row as $name => $value ) {
				if ( ! is_string( $name ) ) {
					throw new StorageFailed( 'A file plan record has an invalid field.' );
				}
				$record[ $name ] = $value;
			}
			$records[] = $record;
		}
		return $records;
	}

	/**
	 * Restricts paths constructed from stored metadata to known streams.
	 *
	 * @param string $stream Name.
	 * @param int    $index Ordinal.
	 * @throws StorageFailed If the cursor exceeds the supported range.
	 */
	private static function require_stream( string $stream, int $index ): void {
		if ( ( 'rows' !== $stream && 1 !== preg_match( '/\Ad[0-9]{3}\z/', $stream ) ) || $index < 0 || $index >= self::MAX_ENTRIES ) {
			throw new StorageFailed( 'A file plan cursor is outside its supported range.' );
		}
	}

	/**
	 * Reads one bounded private file without following a link.
	 *
	 * @param string     $file File.
	 * @param int<0,max> $limit Maximum bytes.
	 * @return string Complete contents.
	 * @throws StorageFailed If the file is missing, oversized or not regular.
	 */
	private static function read( string $file, int $limit ): string {
		clearstatcache( true, $file );
		$text = ! is_link( $file ) && is_file( $file ) && realpath( $file ) === $file ? file_get_contents( $file, false, null, 0, $limit + 1 ) : false;
		if ( false === $text || strlen( $text ) > $limit ) {
			throw new StorageFailed( 'A bounded private file plan component is unreadable.' );
		}
		return $text;
	}
}
