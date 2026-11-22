<?php
/**
 * One table as a stream of SQL.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Support\ResumableByteSource;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the dump file of one table page by page, without writing it to disk (ADR 0021):
 * the session settings, DROP and CREATE, INSERT statements of about 1 MiB, and a trailer
 * with the row and byte counts. A row too large to share a statement gets one of its own,
 * whatever its size: the backup keeps every row that PHP can hold, also when an importer's
 * limit is smaller, and the manifest records each table's largest statement so that an
 * importer can compare it with its max_allowed_packet. A row whose statement would not fit
 * into the memory limit stops the dump with its own code instead of ending PHP.
 *
 * Pages follow the table's primary key, or a unique key over NOT NULL columns, in full: each
 * page asks for the rows after the last key it wrote (KeyColumn), so an insert or a delete
 * elsewhere in the table cannot shift a row into or out of a page, and a row that exists
 * for the whole dump of its table, with the same key values, appears exactly once. The page size adapts so that one
 * page stays near 2 MiB of SQL. A page is the unit of resumption: the backup stops between
 * pages and continues with the next one in a later request.
 *
 * On a DedicatedConnection, DatabaseDump starts a read-only transaction for the table before
 * its definition is read, and this source ends it when it stops or finishes: an InnoDB table
 * is then read from one snapshot per tick. A table without a usable key is read there with
 * one unbuffered statement, which is consistent by itself; only when a tick ends before that
 * statement does, the next tick continues at the row's position with a new one. Its rows are
 * written one by one, each before the next is fetched. Elsewhere such a table is read in pages
 * by position.
 *
 * The pages of a table with values of any size first read the sizes in which their next rows
 * arrive and fetch only as many as fit into the memory left; the page query and the unbuffered
 * statement send the values of a row only if the row stays within its share of that memory.
 *
 * The source counts the read views its rows came from: one per tick inside a snapshot, one
 * per statement outside. The manifest records whether that was one, and a table without a key
 * that needed more than one gets a warning, because rows added or removed between the reads
 * can then be missing or repeated.
 *
 * The definition is pinned for the whole dump of the table: the cursor carries the digest of
 * the definition the file started with. If the table's definition differs when a later tick
 * opens it, the CREATE already written no longer fits the rows to come, and the table starts
 * over (restarted()), at most MAX_RESTARTS times. Inside a snapshot the metadata lock keeps the
 * definition for the tick; outside one, the definition is read again after every page, before
 * the page is written, before a stop and before the trailer, and a change, or a page that
 * fails because of one, stops the tick instead.
 */
final class TableSource implements ResumableByteSource {

	public const string FORMAT = 'backups-for-restic-dump/1';

	/**
	 * The plugin's importer reads statements up to this size, including the semicolon and
	 * excluding the physical newline (ADR 0035). The writer does not refuse larger ones.
	 */
	public const int MAX_STATEMENT_BYTES = 16777216;

	/** Memory left for the backup to read a large row's statement in chunks. */
	private const int ENCODING_RESERVE = 16777216;

	private const int STATEMENT_BYTES = 1048576;

	/** At most as many rows per INSERT as the importer accepts (InsertStatement::MAX_ROWS). */
	private const int STATEMENT_ROWS = 20000;

	private const int TARGET_PAGE_BYTES = 2097152;

	/** Cursors of earlier versions have no version; they cannot continue and the table starts over. */
	private const int CURSOR_VERSION = 2;

	/** How often a table may start over because its definition changed. */
	public const int MAX_RESTARTS = 3;

	/**
	 * How long a read of the dump waits for a lock that another connection holds on a table,
	 * for example with LOCK TABLES ... WRITE or during the last phase of an ALTER TABLE. The
	 * dump then stops with database_dump_locked, and the job tries again later.
	 */
	public const int LOCK_WAIT_SECONDS = 5;

	/** The server's error for a lock that was not granted in time, the same on MySQL and MariaDB. */
	private const int LOCK_WAIT_TIMEOUT = 1205;

	/**
	 * Produced but not yet read output, in the pieces it was produced in: a large statement is
	 * never copied into one string, and reading does not copy what is left.
	 *
	 * @var list<string>
	 */
	private array $pieces = [];

	/**
	 * Bytes of the first piece already read.
	 *
	 * @var int
	 */
	private int $head = 0;

	/**
	 * Bytes produced and not yet read.
	 *
	 * @var int
	 */
	private int $available = 0;

	/**
	 * Length of the longest statement so far, with its semicolon and without the newline.
	 *
	 * @var int
	 */
	private int $largest;

	/**
	 * Whether the header has been written.
	 *
	 * @var bool
	 */
	private bool $started;

	/**
	 * Whether all rows have been read.
	 *
	 * @var bool
	 */
	private bool $exhausted = false;

	/**
	 * Whether the trailer has been written.
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Whether the backup asked to stop at the next page.
	 *
	 * @var bool
	 */
	private bool $stopping = false;

	/**
	 * Whether the stream ended early at a page boundary.
	 *
	 * @var bool
	 */
	private bool $stopped = false;

	/**
	 * Key values of the last row written, in key order, or null before the first page.
	 *
	 * @var list<string>|null
	 */
	private ?array $after = null;

	/**
	 * Whether the cursor this source was opened with could not be continued.
	 *
	 * @var bool
	 */
	private bool $restarted = false;

	/**
	 * How often the table started over.
	 *
	 * @var int
	 */
	private int $restarts;

	/**
	 * Whether the definition changed during this tick, so that the next one starts over.
	 *
	 * @var bool
	 */
	private bool $changed = false;

	/**
	 * Aliases of the cursor expressions in the page query, by key position.
	 *
	 * @var array<int, string>
	 */
	private array $aliases = [];

	/**
	 * Rows read so far, for offset paging.
	 *
	 * @var int
	 */
	private int $offset;

	/**
	 * Rows written so far.
	 *
	 * @var int
	 */
	private int $rows;

	/**
	 * Bytes written so far.
	 *
	 * @var int
	 */
	private int $bytes;

	/**
	 * Rows per query; adapts to the row size.
	 *
	 * @var int
	 */
	private int $page;

	/**
	 * Read views the rows written so far came from.
	 *
	 * @var int
	 */
	private int $views;

	/**
	 * Whether every read of this source sees one snapshot: an InnoDB table inside begin().
	 *
	 * @var bool
	 */
	private bool $snapshot;

	/**
	 * Whether this source's snapshot was counted as a read view.
	 *
	 * @var bool
	 */
	private bool $viewed = false;

	/**
	 * Whether an unbuffered statement is being read.
	 *
	 * @var bool
	 */
	private bool $streaming = false;

	/**
	 * Alias of the size of each row in a query that reads values of any size.
	 *
	 * @var string
	 */
	private string $size_alias = '__bfr_size';

	/**
	 * The most bytes that the unbuffered read of this tick fetches of one row; null without values of any size.
	 *
	 * @var int|null
	 */
	private ?int $cap = null;

	/**
	 * Length of the INSERT statement that the stream is still filling, 0 if none is open.
	 *
	 * @var int
	 */
	private int $open = 0;

	/**
	 * Rows of the INSERT statement that the stream is still filling.
	 *
	 * @var int
	 */
	private int $open_rows = 0;

	/**
	 * Pages in a row that ended before their first row because it had grown.
	 *
	 * @var int
	 */
	private int $cut = 0;

	/**
	 * Start of every INSERT statement of the table.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Opens the table at a cursor.
	 *
	 * @param Connection                                                                                                   $connection  Database; a DedicatedConnection inside begin() for one snapshot.
	 * @param Table                                                                                                        $table       Table.
	 * @param string                                                                                                       $preamble    Session settings, one statement per line.
	 * @param string|null                                                                                                  $cursor      Cursor from an earlier source, or null to start.
	 * @param \Closure(array{rows: int, bytes: int, snapshot: bool, key: list<string>, restarts: int, largest: int}): void $on_complete Receives the counts and how the rows were read, once the trailer is written.
	 * @param (\Closure(): Table)|null                                                                                     $describe    Reads the definition again, to see whether it changed during a tick outside a snapshot.
	 * @throws DatabaseFailed If the table has started over too often.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Table $table,
		private readonly string $preamble,
		?string $cursor,
		private readonly \Closure $on_complete,
		private readonly ?\Closure $describe = null
	) {
		foreach ( array_keys( $table->order ) as $position ) {
			$alias = '__bfr_key_' . $position;
			while ( isset( $table->columns[ $alias ] ) ) {
				$alias .= '_';
			}
			$this->aliases[ $position ] = $alias;
		}
		while ( isset( $table->columns[ $this->size_alias ] ) || in_array( $this->size_alias, $this->aliases, true ) ) {
			$this->size_alias .= '_';
		}
		$state    = self::saved( $cursor, $table );
		$restarts = self::restarts( $cursor );
		if ( null !== $cursor && ( null === $state || $state['changed'] || $state['digest'] !== $table->digest ) ) {
			// The file was started with another definition, by an earlier version, or the
			// cursor is damaged: what was written cannot be continued.
			$state           = null;
			$this->restarted = true;
			++$restarts;
		}
		if ( $restarts > self::MAX_RESTARTS ) {
			DatabaseFailed::raise(
				'The definition of a database table kept changing while it was backed up.',
				[
					'table' => $table->name,
					'code'  => 'database_dump_unstable',
				]
			);
		}
		$this->restarts = $restarts;
		$this->started  = null !== $state;
		$this->after    = $state['after'] ?? null;
		$this->offset   = $state['offset'] ?? 0;
		$this->rows     = $state['rows'] ?? 0;
		$this->bytes    = $state['bytes'] ?? 0;
		// Fewer rows in the first page of a table whose values have no bound.
		$this->page     = $state['page'] ?? ( $table->wide ? 50 : 500 );
		$this->largest  = $state['largest'] ?? 0;
		$this->views    = $state['views'] ?? 0;
		$this->snapshot = $connection instanceof DedicatedConnection && $connection->in_snapshot() && $table->versioned();
		$this->prefix   = 'INSERT INTO ' . SqlFormatter::identifier( $table->name ) . ' (' . implode( ',', array_map( static fn ( int|string $name ): string => SqlFormatter::identifier( (string) $name ), array_keys( $table->columns ) ) ) . ') VALUES ';
	}

	/**
	 * How often the table started over before this cursor was handed out. Read on its own, so
	 * that a cursor rejected for its key values, for example after the key changed, still counts
	 * towards MAX_RESTARTS.
	 *
	 * @param string|null $cursor Cursor.
	 * @return int Restarts, or 0 for no cursor or one of an earlier version.
	 */
	private static function restarts( ?string $cursor ): int {
		$state = null === $cursor ? null : json_decode( $cursor, true );
		$count = is_array( $state ) && self::CURSOR_VERSION === ( $state['v'] ?? null ) ? $state['restarts'] ?? 0 : 0;
		return is_int( $count ) && $count > 0 ? $count : 0;
	}

	/**
	 * The state of a cursor that this table can continue. A cursor of an earlier version, a
	 * damaged one and one whose key values do not fit the table's key cannot be continued.
	 *
	 * @param string|null $cursor Cursor.
	 * @param Table       $table  Table.
	 * @return array{after: list<string>|null, offset: int, rows: int, bytes: int, page: int, views: int, restarts: int, digest: string, changed: bool, largest: int}|null State, or null to start the table from its beginning.
	 */
	private static function saved( ?string $cursor, Table $table ): ?array {
		$state = null === $cursor ? null : json_decode( $cursor, true );
		if ( ! is_array( $state ) || self::CURSOR_VERSION !== ( $state['v'] ?? null ) || ! is_string( $state['digest'] ?? null ) || ! is_bool( $state['changed'] ?? null ) ) {
			return null;
		}
		foreach ( [ 'offset', 'rows', 'bytes', 'page', 'views', 'restarts', 'largest' ] as $field ) {
			if ( ! is_int( $state[ $field ] ?? null ) || $state[ $field ] < 0 ) {
				return null;
			}
		}
		$after = $state['after'] ?? null;
		if ( null === $after && [] !== $table->order && $state['rows'] > 0 ) {
			// Pages of a keyed table record their key values before any row is written: rows
			// without them would be read again from the first key.
			return null;
		}
		if ( null !== $after ) {
			if ( ! is_array( $after ) || ! array_is_list( $after ) ) {
				return null;
			}
			$values = [];
			foreach ( $after as $value ) {
				if ( ! is_string( $value ) ) {
					return null;
				}
				$values[] = $value;
			}
			try {
				KeyColumn::after( $table->order, $values );
			} catch ( DatabaseFailed ) {
				return null;
			}
			$after = $values;
		}
		return [
			'after'    => $after,
			'offset'   => $state['offset'],
			'rows'     => $state['rows'],
			'bytes'    => $state['bytes'],
			'page'     => max( 1, min( 20000, $state['page'] ) ),
			'views'    => $state['views'],
			'restarts' => $state['restarts'],
			'digest'   => $state['digest'],
			'changed'  => $state['changed'],
			'largest'  => $state['largest'],
		];
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; empty at the end of the table or after a stop.
	 * @throws DatabaseFailed If a query fails.
	 */
	public function read( int $length ): string {
		while ( $this->available < $length && ! $this->finished && ! $this->stopped ) {
			$this->produce();
		}
		$block = '';
		$taken = 0;
		while ( $taken < $length && [] !== $this->pieces ) {
			$piece       = $this->pieces[0];
			$size        = strlen( $piece );
			$take        = min( $size - $this->head, $length - $taken );
			$block      .= 0 === $this->head && $take === $size ? $piece : substr( $piece, $this->head, $take );
			$taken      += $take;
			$this->head += $take;
			if ( $this->head === $size ) {
				array_shift( $this->pieces );
				$this->head = 0;
			}
		}
		$this->available -= $taken;
		return $block;
	}

	/**
	 * Ends the stream before the next page.
	 */
	public function stop_at_boundary(): void {
		$this->stopping = true;
	}

	/**
	 * Where to continue after a stop.
	 *
	 * @return string|null Cursor, or null once the table is complete.
	 */
	public function cursor(): ?string {
		if ( ! $this->stopped ) {
			return null;
		}
		return (string) json_encode(
			[
				'v'        => self::CURSOR_VERSION,
				'after'    => $this->after,
				'offset'   => $this->offset,
				'rows'     => $this->rows,
				'bytes'    => $this->bytes,
				'page'     => $this->page,
				'views'    => $this->views,
				'restarts' => $this->restarts,
				'digest'   => $this->table->digest,
				'changed'  => $this->changed,
				'largest'  => $this->largest,
			]
		);
	}

	/**
	 * Whether the table starts over: its definition changed since the cursor was handed out,
	 * or the cursor is from an earlier version or damaged.
	 *
	 * @return bool True if the content kept from earlier sources must be discarded.
	 */
	public function restarted(): bool {
		return $this->restarted;
	}

	/**
	 * A complete table without a key that was read in more than one view can miss rows or
	 * repeat them; the backup says so.
	 *
	 * @return list<string> Warning, or none.
	 */
	public function warnings(): array {
		if ( ! $this->finished || [] !== $this->table->order || $this->views < 2 ) {
			return [];
		}
		return [ sprintf( 'Database table %s has no primary key and no unique key over NOT NULL columns and was read at %d moments: rows that were added or removed meanwhile can be missing or repeated in the backup.', $this->table->name, $this->views ) ];
	}

	/**
	 * Produces the next piece: the header, a page, or the trailer.
	 *
	 * @throws DatabaseFailed If a query fails.
	 */
	private function produce(): void {
		if ( ! $this->started ) {
			$name = SqlFormatter::identifier( $this->table->name );
			$this->emit( '-- ' . self::FORMAT . ' table ' . $name . "\n" . $this->preamble . 'DROP TABLE IF EXISTS ' . $name . ";\n" . $this->table->create . ";\n" );
			$this->started = true;
			return;
		}
		if ( ! $this->exhausted ) {
			if ( $this->stopping ) {
				$this->changed = $this->definition_changed();
				$this->stop();
				return;
			}
			try {
				if ( [] === $this->table->order && $this->snapshot ) {
					$this->stream();
				} else {
					$this->page();
				}
			} catch ( DatabaseFailed $error ) {
				// A column that was dropped or changed makes a page fail: the table starts over.
				// A refusal of the dump's own, such as a row too large, is no failed query.
				if ( isset( $error->context()['code'] ) || self::waited( $error ) || ! $this->definition_changed() ) {
					self::rethrow( $error, $this->table->name );
				}
				$this->changed = true;
				$this->stop();
			}
			return;
		}
		if ( $this->definition_changed() ) {
			// The rows were read with a definition the table no longer has.
			$this->changed = true;
			$this->stop();
			return;
		}
		$this->close_statement();
		$this->emit( '-- end table rows=' . $this->rows . ' bytes=' . $this->bytes . "\n" );
		$this->finished = true;
		$this->release();
		( $this->on_complete )(
			[
				'rows'     => $this->rows,
				'bytes'    => $this->bytes,
				'snapshot' => $this->views <= 1,
				'key'      => array_map( static fn ( KeyColumn $column ): string => $column->name, $this->table->order ),
				'restarts' => $this->restarts,
				'largest'  => $this->largest,
			]
		);
	}

	/**
	 * Whether a query failed because a lock that another connection holds was not granted
	 * within LOCK_WAIT_SECONDS.
	 *
	 * @param DatabaseFailed $error Failure of a query.
	 * @return bool True for a lock wait timeout.
	 */
	public static function waited( DatabaseFailed $error ): bool {
		return self::LOCK_WAIT_TIMEOUT === ( $error->context()['errno'] ?? null );
	}

	/**
	 * Reports the failure of a query of a table: a lock wait timeout as database_dump_locked,
	 * which names the table and which the job tries again later; anything else as it is.
	 *
	 * @param DatabaseFailed $error Failure of a query.
	 * @param string         $table Table name.
	 * @throws DatabaseFailed Always.
	 */
	public static function rethrow( DatabaseFailed $error, string $table ): never {
		if ( self::waited( $error ) ) {
			DatabaseFailed::raise(
				'A database table stayed locked by another connection for longer than the dump waits.',
				[
					'table' => $table,
					'code'  => 'database_dump_locked',
				],
				$error
			);
		}
		throw $error;
	}

	/**
	 * Ends the stream at a boundary, with a cursor.
	 *
	 * @throws DatabaseFailed If the transaction cannot be ended.
	 */
	private function stop(): void {
		$this->stopped = true;
		$this->close_statement();
		$this->release();
	}

	/**
	 * Whether the table's definition differs from the one this file started with. Inside a
	 * snapshot the metadata lock keeps it, so only reads outside one look again.
	 *
	 * @return bool True if it changed.
	 * @throws DatabaseFailed If the definition cannot be read.
	 */
	private function definition_changed(): bool {
		if ( null === $this->describe || ( $this->connection instanceof DedicatedConnection && $this->connection->in_snapshot() ) ) {
			return false;
		}
		try {
			return ( $this->describe )()->digest !== $this->table->digest;
		} catch ( DatabaseFailed $error ) {
			// The definition is read like a page: a lock that another connection holds
			// makes the job try again later.
			self::rethrow( $error, $this->table->name );
		}
	}

	/**
	 * Ends this tick's snapshot: a stream that stopped early closes its connection.
	 *
	 * @throws DatabaseFailed If the transaction cannot be ended.
	 */
	private function release(): void {
		if ( $this->connection instanceof DedicatedConnection ) {
			if ( $this->streaming ) {
				$this->streaming = false;
				$this->connection->abandon();
			}
			$this->connection->end();
		}
	}

	/**
	 * Counts a read of rows: one view per snapshot, one per statement outside.
	 */
	private function viewed(): void {
		if ( ! $this->snapshot || ! $this->viewed ) {
			++$this->views;
			$this->viewed = true;
		}
	}

	/**
	 * The selected columns: every insertable column, a FLOAT as the DOUBLE it converts to
	 * exactly, and the cursor values that need an expression. With a cap, a row larger than
	 * the cap comes with NULL in place of its values of any size, and every row with its size:
	 * the server checks each row before it is sent, so PHP never fetches one it cannot hold.
	 *
	 * @param int|null $cap Bytes of the largest row to fetch whole, or null for every row.
	 * @return string Selection list.
	 */
	private function selection( ?int $cap = null ): string {
		$size    = null === $cap ? '' : $this->size();
		$guarded = [];
		if ( null !== $cap ) {
			foreach ( [] !== $this->table->unbounded ? $this->table->unbounded : array_keys( array_filter( $this->table->columns, static fn ( string $kind ): bool => in_array( $kind, [ SqlFormatter::TEXT, SqlFormatter::BINARY ], true ) ) ) as $name ) {
				$guarded[ (string) $name ] = true;
			}
		}
		// SELECT * omits invisible columns even though SHOW COLUMNS includes them.
		$selection = [];
		foreach ( $this->table->columns as $name => $kind ) {
			$column = SqlFormatter::identifier( (string) $name );
			$value  = SqlFormatter::FLOAT === $kind ? $column . ' + 0e0' : $column;
			if ( isset( $guarded[ (string) $name ] ) ) {
				$value = 'IF(' . $size . ' <= ' . $cap . ', ' . $value . ', NULL)';
			}
			$selection[] = $column === $value ? $column : $value . ' AS ' . $column;
		}
		foreach ( $this->table->order as $position => $column ) {
			if ( $column->computed() ) {
				$selection[] = $column->value() . ' AS ' . SqlFormatter::identifier( $this->aliases[ $position ] );
			}
		}
		if ( null !== $cap ) {
			$selection[] = $size . ' AS ' . SqlFormatter::identifier( $this->size_alias );
		}
		// A generated-only table still has rows: one empty INSERT tuple recreates each one.
		return [] === $selection ? '1' : implode( ', ', $selection );
	}

	/**
	 * Reads the next row of a table without a key from the unbuffered read of this tick, which
	 * starts at the first row not yet written, and writes it before the next one is fetched:
	 * no other row of the table is held then, so a row that fits into the memory left on its
	 * own is backed up. Values of any size come only for rows that fit (selection()), so the
	 * read needs no scan of the table to find its largest row.
	 *
	 * @throws DatabaseFailed If the read fails or a row does not fit into the memory limit.
	 */
	private function stream(): void {
		if ( ! $this->streaming && $this->connection instanceof DedicatedConnection ) {
			$room      = self::room();
			$this->cap = $this->table->wide && PHP_INT_MAX !== $room ? $this->alone( $room ) : null;
			// An explicit limit, so that no sql_select_limit of the session applies.
			$this->connection->stream( 'SELECT ' . $this->selection( $this->cap ) . ' FROM ' . SqlFormatter::identifier( $this->table->name ) . ' LIMIT 18446744073709551615' . ( $this->offset > 0 ? ' OFFSET ' . $this->offset : '' ) );
			$this->streaming = true;
			$this->viewed();
		}
		$row = $this->connection instanceof DedicatedConnection ? $this->connection->next() : null;
		if ( null === $row ) {
			$this->close_statement();
			$this->streaming = false;
			$this->exhausted = true;
			return;
		}
		$size = (int) ( $row[ $this->size_alias ] ?? 0 );
		if ( null !== $this->cap && $size > $this->cap ) {
			$this->close_statement();
			$this->refuse( $this->estimate( $size ) );
		}
		$this->append( $row );
		++$this->rows;
		++$this->offset;
	}

	/**
	 * Writes a row of the stream into the INSERT statement it is filling, of about
	 * STATEMENT_BYTES and at most STATEMENT_ROWS rows; a large row gets a statement of its own.
	 *
	 * @param array<string, string|null> $row Row.
	 * @throws DatabaseFailed If the row cannot be encoded within the memory limit.
	 */
	private function append( array $row ): void {
		$bound = $this->bound( $row );
		if ( $bound > 2 * self::STATEMENT_BYTES ) {
			$this->close_statement();
			$this->large( $row, $bound );
			return;
		}
		$tuple = $this->tuple( $row );
		if ( $this->open > 0 && ( $this->open + 1 + strlen( $tuple ) > self::STATEMENT_BYTES || $this->open_rows >= self::STATEMENT_ROWS ) ) {
			$this->close_statement();
		}
		$this->open += 0 === $this->open ? $this->emit( $this->prefix . $tuple ) : $this->emit( ',' . $tuple );
		++$this->open_rows;
	}

	/**
	 * Ends the INSERT statement that the stream is filling, if any.
	 */
	private function close_statement(): void {
		if ( $this->open > 0 ) {
			$this->emit( ";\n" );
			$this->largest   = max( $this->largest, $this->open + 1 );
			$this->open      = 0;
			$this->open_rows = 0;
		}
	}

	/**
	 * At most how many bytes a row takes in an INSERT statement: every value at most twice its
	 * length plus quotes, as escaping or hex can make it, or NULL.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return int Bytes.
	 */
	private function bound( array $row ): int {
		$bytes = strlen( $this->prefix ) + 4 + count( $this->table->columns );
		foreach ( array_keys( $this->table->columns ) as $column ) {
			$value  = $row[ $column ] ?? null;
			$bytes += null === $value ? 4 : 2 + 2 * strlen( $value );
		}
		return $bytes;
	}

	/**
	 * One row as an INSERT tuple.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return string Tuple.
	 */
	private function tuple( array $row ): string {
		$values = [];
		foreach ( $this->table->columns as $column => $kind ) {
			$values[] = SqlFormatter::value( $row[ $column ] ?? null, $kind );
		}
		return '(' . implode( ',', $values ) . ')';
	}

	/**
	 * Writes rows as INSERT statements of about STATEMENT_BYTES and at most STATEMENT_ROWS
	 * rows. A row larger than a statement gets one of its own.
	 *
	 * @param list<array<string, string|null>> $rows Rows.
	 * @return array{int, int} Bytes written, and the most that one row took.
	 * @throws DatabaseFailed If a row cannot be encoded within the memory limit.
	 */
	private function write( array $rows ): array {
		$size   = 0;
		$widest = 0;
		$tuples = [];
		$length = 0;
		foreach ( $rows as $row ) {
			$bound = $this->bound( $row );
			// A row that certainly needs a statement of its own is written in pieces.
			if ( $bound > 2 * self::STATEMENT_BYTES ) {
				if ( [] !== $tuples ) {
					$size  += $this->statement( $tuples );
					$tuples = [];
					$length = 0;
				}
				$size  += $this->large( $row, $bound );
				$widest = max( $widest, $bound );
				continue;
			}
			$tuple  = $this->tuple( $row );
			$widest = max( $widest, strlen( $tuple ) );
			if ( [] !== $tuples && ( $length + strlen( $tuple ) > self::STATEMENT_BYTES || count( $tuples ) >= self::STATEMENT_ROWS ) ) {
				$size  += $this->statement( $tuples );
				$tuples = [];
				$length = 0;
			}
			$tuples[] = $tuple;
			$length  += strlen( $tuple ) + 1;
		}
		if ( [] !== $tuples ) {
			$size += $this->statement( $tuples );
		}
		return [ $size, $widest ];
	}

	/**
	 * Writes one large row as a statement of its own, in pieces of about 2 MiB, after checking
	 * that its statement and what the backup needs to read it fit into the memory limit.
	 *
	 * @param array<string, string|null> $row   Row.
	 * @param int                        $bound At most its bytes in the statement.
	 * @return int Bytes written.
	 * @throws DatabaseFailed If the statement would not fit into the memory limit.
	 */
	private function large( array $row, int $bound ): int {
		if ( $bound > self::room() ) {
			$this->refuse( $bound );
		}
		$size  = $this->emit( $this->prefix . '(' );
		$first = true;
		foreach ( $this->table->columns as $column => $kind ) {
			if ( ! $first ) {
				$size += $this->emit( ',' );
			}
			$first = false;
			foreach ( SqlFormatter::pieces( $row[ $column ] ?? null, $kind ) as $piece ) {
				$size += $this->emit( $piece );
			}
		}
		$size         += $this->emit( ");\n" );
		$this->largest = max( $this->largest, $size - 1 );
		return $size;
	}

	/**
	 * Writes tuples as one INSERT statement.
	 *
	 * @param list<string> $tuples Tuples.
	 * @return int Bytes written.
	 */
	private function statement( array $tuples ): int {
		$size          = $this->emit( $this->prefix . implode( ',', $tuples ) . ";\n" );
		$this->largest = max( $this->largest, $size - 1 );
		return $size;
	}

	/**
	 * Fetches one page and turns it into INSERT statements.
	 *
	 * @throws DatabaseFailed If the query fails or a key value is not what its column holds.
	 */
	private function page(): void {
		$table = SqlFormatter::identifier( $this->table->name );
		if ( [] !== $this->table->order ) {
			$where = null === $this->after ? '' : ' WHERE ' . KeyColumn::after( $this->table->order, $this->after );
			// Qualified, so that ORDER BY names the column even where the selection has an alias of the same name.
			$order  = implode( ', ', array_map( static fn ( KeyColumn $column ): string => $table . '.' . SqlFormatter::identifier( $column->name ), $this->table->order ) );
			$range  = ' FROM ' . $table . $where . ' ORDER BY ' . $order . ' LIMIT ';
			$offset = '';
		} else {
			$range  = ' FROM ' . $table . ' LIMIT ';
			$offset = ' OFFSET ' . $this->offset;
		}
		// Values of any size: the server first says how large the next rows are, so that the
		// page fetches no more of them than PHP can hold.
		[ $limit, $more, $cap ] = $this->table->wide ? $this->fitting( $range, $offset ) : [ $this->page, true, null ];
		$rows                   = 0 === $limit ? [] : $this->connection->rows( 'SELECT ' . $this->selection( $cap ) . $range . $limit . $offset );
		// Also a read that finds nothing more is a moment of its own outside a snapshot.
		$this->viewed();
		if ( [] === $rows ) {
			$this->exhausted = true;
			return;
		}
		// Outside a snapshot the sizes and the page are two reads: a row that has grown beyond
		// the cap since came without its values, and the page ends before it.
		$grown = null;
		foreach ( null === $cap ? [] : $rows as $index => $row ) {
			if ( (int) ( $row[ $this->size_alias ] ?? 0 ) > $cap ) {
				$grown = (int) $row[ $this->size_alias ];
				$rows  = array_slice( $rows, 0, $index );
				break;
			}
		}
		if ( [] === $rows ) {
			// The first row grew: its size is read again with the next page, unless it no
			// longer fits on its own.
			if ( null !== $grown && ( 1 === $limit || ++$this->cut >= 3 ) ) {
				$this->refuse( $this->estimate( $grown ) );
			}
			return;
		}
		$this->cut = 0;
		// Outside a snapshot nothing holds the definition from one statement to the next: a
		// page read under another one is not written, even if the change is undone later.
		if ( $this->definition_changed() ) {
			$this->changed = true;
			$this->stop();
			return;
		}

		if ( [] !== $this->table->order ) {
			$last  = $rows[ array_key_last( $rows ) ];
			$after = [];
			foreach ( $this->table->order as $position => $column ) {
				$value = $column->cursor( $last[ $column->computed() ? $this->aliases[ $position ] : $column->name ] ?? null );
				// Checked before anything is written: a column whose type changed since the
				// catalog read it must not produce a cursor that misses or repeats rows.
				$column->literal( $value );
				$after[] = (string) $value;
			}
			$this->after = $after;
		}
		[ $size, $widest ] = $this->write( $rows );

		$count         = count( $rows );
		$this->rows   += $count;
		$this->offset += $count;
		if ( null === $grown && ( $count < $limit || ! $more ) ) {
			$this->exhausted = true;
		}
		if ( $size > 2 * self::TARGET_PAGE_BYTES ) {
			$this->page = max( 1, intdiv( $this->page, 2 ) );
		} elseif ( $size < intdiv( self::TARGET_PAGE_BYTES, 2 ) && $count === $this->page ) {
			$this->page = min( self::STATEMENT_ROWS, $this->page * 2 );
		}
		// A page is held in memory whole: no more of the widest rows seen than fit twice the target.
		$this->page = max( 1, min( $this->page, intdiv( 2 * self::TARGET_PAGE_BYTES, max( 1, $widest ) ) ) );
	}

	/**
	 * How many of the next rows of a table with values of any size one page fetches: as many
	 * as fit, with their SQL, into the memory left even if each were as large as the largest
	 * of them, and about TARGET_PAGE_BYTES of SQL, at least one. The server reports their sizes
	 * first. The cap is the share of the memory left that each row of the page may take.
	 *
	 * @param string $range  FROM, WHERE, ORDER BY and LIMIT keyword of the page.
	 * @param string $offset OFFSET clause, or empty.
	 * @return array{int, bool, int|null} Rows to fetch, 0 if none are left; whether more may follow them; the cap of each row, null without a memory limit.
	 * @throws DatabaseFailed If the next row does not fit into the memory limit on its own.
	 */
	private function fitting( string $range, string $offset ): array {
		$sizes  = $this->connection->rows( 'SELECT ' . $this->size() . ' AS size' . $range . $this->page . $offset );
		$room   = self::room();
		$count  = 0;
		$sql    = 0;
		$widest = 0;
		foreach ( $sizes as $row ) {
			$bytes = (int) ( $row['size'] ?? 0 );
			$bound = $this->estimate( $bytes );
			if ( 0 === $count && $bytes + $bound > $room ) {
				$this->refuse( $bound );
			}
			$larger = max( $widest, $bytes );
			if ( $count > 0 && ( $sql + $bound > self::TARGET_PAGE_BYTES || ( $count + 1 ) * ( $larger + $this->estimate( $larger ) ) > $room ) ) {
				break;
			}
			$widest = $larger;
			$sql   += $bound;
			++$count;
		}
		// Without a memory limit nothing needs a cap.
		$cap = 0 === $count || PHP_INT_MAX === $room ? null : max( $widest, $this->alone( intdiv( $room, $count ) ) );
		return [ $count, $count < count( $sizes ) || count( $sizes ) === $this->page, $cap ];
	}

	/**
	 * The bytes that the reader receives for the selected values of a row: text converted to
	 * utf8mb4, binary and spatial values byte for byte, numbers as their digits.
	 *
	 * @return string SQL expression.
	 */
	private function size(): string {
		$converted = array_flip( $this->table->converted );
		$sizes     = [];
		foreach ( $this->table->columns as $name => $kind ) {
			$column = SqlFormatter::identifier( (string) $name );
			$value  = match ( true ) {
				isset( $converted[ (string) $name ] ) => 'CONVERT(' . $column . ' USING utf8mb4)',
				SqlFormatter::FLOAT === $kind        => $column . ' + 0e0',
				SqlFormatter::BIT === $kind          => $column . ' + 0',
				default                              => $column,
			};
			$sizes[] = 'IFNULL(OCTET_LENGTH(' . $value . '), 0)';
		}
		foreach ( $this->table->order as $column ) {
			if ( $column->computed() ) {
				$sizes[] = 'IFNULL(OCTET_LENGTH(' . $column->value() . '), 0)';
			}
		}
		return [] === $sizes ? '0' : implode( ' + ', $sizes );
	}

	/**
	 * The size of the largest row that fits, with its SQL, into the given memory.
	 *
	 * @param int $room Bytes.
	 * @return int Bytes of its values; 0 if none fits.
	 */
	private function alone( int $room ): int {
		return max( 0, intdiv( $room - $this->estimate( 0 ), 3 ) );
	}

	/**
	 * At most how many bytes a row of the given size takes in an INSERT statement, as bound()
	 * counts them.
	 *
	 * @param int $bytes Bytes of its values.
	 * @return int Bytes.
	 */
	private function estimate( int $bytes ): int {
		return strlen( $this->prefix ) + 4 + 5 * count( $this->table->columns ) + 2 * $bytes;
	}

	/**
	 * The memory that a page's rows and their SQL may take: what the memory limit leaves,
	 * less what the backup needs to read the SQL in chunks.
	 *
	 * @return int Bytes; PHP_INT_MAX without a limit.
	 */
	private static function room(): int {
		$limit = ini_parse_quantity( ini_get( 'memory_limit' ) );
		return $limit > 0 ? $limit - memory_get_usage( true ) - self::ENCODING_RESERVE : PHP_INT_MAX;
	}

	/**
	 * Stops the dump for a row that PHP cannot hold with its SQL.
	 *
	 * @param int $bound At most the bytes of its statement.
	 * @throws DatabaseFailed Always.
	 */
	private function refuse( int $bound ): never {
		if ( $this->streaming && $this->connection instanceof DedicatedConnection ) {
			// Freed at the end of the request, the read would make the client fetch every row left.
			$this->streaming = false;
			$this->connection->abandon();
		}
		DatabaseFailed::raise(
			'A database row is too large to be backed up within the PHP memory limit.',
			[
				'table' => $this->table->name,
				'bytes' => $bound,
				'code'  => 'database_dump_row_memory',
			]
		);
	}

	/**
	 * Appends text to the output.
	 *
	 * @param string $text Text.
	 * @return int Its length.
	 */
	private function emit( string $text ): int {
		$length = strlen( $text );
		if ( $length > 0 ) {
			$this->pieces[]   = $text;
			$this->available += $length;
			$this->bytes     += $length;
		}
		return $length;
	}
}
