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
 * for the whole dump of its table appears exactly once. The page size adapts so that one
 * page stays near 2 MiB of SQL. A page is the unit of resumption: the backup stops between
 * pages and continues with the next one in a later request.
 *
 * On a DedicatedConnection, DatabaseDump starts a read-only transaction for the table before
 * its definition is read, and this source ends it when it stops or finishes: an InnoDB table
 * is then read from one snapshot per tick. A table without a usable key is read there with
 * one unbuffered statement, which is consistent by itself; only when a tick ends before that
 * statement does, the next tick continues at the row's position with a new one. Elsewhere such
 * a table is read in pages by position.
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
 * definition for the tick; outside one, the definition is read again before a stop and before
 * the trailer, and a change, or a page that fails because of one, stops the tick instead.
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
	 * A row read from the stream that did not fit into the statement before.
	 *
	 * @var array<string, string|null>|null
	 */
	private ?array $carry = null;

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
				if ( self::waited( $error ) || ! $this->definition_changed() ) {
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
		// A row carried over from the stream was not written; the next tick reads it again.
		$this->carry = null;
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
		return ( $this->describe )()->digest !== $this->table->digest;
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
	 * exactly, and the cursor values that need an expression.
	 *
	 * @return string Selection list.
	 */
	private function selection(): string {
		// SELECT * omits invisible columns even though SHOW COLUMNS includes them.
		$selection = [];
		foreach ( $this->table->columns as $name => $kind ) {
			$column      = SqlFormatter::identifier( (string) $name );
			$selection[] = SqlFormatter::FLOAT === $kind ? $column . ' + 0e0 AS ' . $column : $column;
		}
		foreach ( $this->table->order as $position => $column ) {
			if ( $column->computed() ) {
				$selection[] = $column->value() . ' AS ' . SqlFormatter::identifier( $this->aliases[ $position ] );
			}
		}
		// A generated-only table still has rows: one empty INSERT tuple recreates each one.
		return [] === $selection ? '1' : implode( ', ', $selection );
	}

	/**
	 * Reads one statement's worth of rows of a table without a key from the unbuffered read of
	 * this tick, which starts at the first row not yet written.
	 *
	 * @throws DatabaseFailed If the read fails.
	 */
	private function stream(): void {
		if ( ! $this->streaming && $this->connection instanceof DedicatedConnection ) {
			$table = SqlFormatter::identifier( $this->table->name );
			// An explicit limit, so that no sql_select_limit of the session applies.
			$this->connection->stream( 'SELECT ' . $this->selection() . ' FROM ' . $table . ' LIMIT 18446744073709551615' . ( $this->offset > 0 ? ' OFFSET ' . $this->offset : '' ) );
			$this->streaming = true;
			$this->viewed();
		}
		$rows        = null === $this->carry ? [] : [ $this->carry ];
		$length      = null === $this->carry ? 0 : $this->bound( $this->carry );
		$this->carry = null;
		while ( true ) {
			$row = $this->connection instanceof DedicatedConnection ? $this->connection->next() : null;
			if ( null === $row ) {
				$this->streaming = false;
				$this->exhausted = true;
				break;
			}
			$bound = $this->bound( $row );
			// About a page's worth, which write() puts into statements of about 1 MiB.
			if ( [] !== $rows && ( $length + $bound > self::TARGET_PAGE_BYTES || count( $rows ) >= self::STATEMENT_ROWS ) ) {
				$this->carry = $row;
				break;
			}
			$rows[]  = $row;
			$length += $bound;
		}
		if ( [] !== $rows ) {
			$this->write( $rows );
			$this->rows   += count( $rows );
			$this->offset += count( $rows );
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
	 * Writes one large row as a statement of its own, value by value, after checking that its
	 * pieces and what the backup needs to read them fit into the memory limit.
	 *
	 * @param array<string, string|null> $row   Row.
	 * @param int                        $bound At most its bytes in the statement.
	 * @return int Bytes written.
	 * @throws DatabaseFailed If the statement would not fit into the memory limit.
	 */
	private function large( array $row, int $bound ): int {
		$limit = ini_parse_quantity( ini_get( 'memory_limit' ) );
		if ( $limit > 0 && memory_get_usage( true ) + $bound + self::ENCODING_RESERVE > $limit ) {
			DatabaseFailed::raise(
				'A database row is too large to be backed up within the PHP memory limit.',
				[
					'table' => $this->table->name,
					'bytes' => $bound,
					'code'  => 'database_dump_row_memory',
				]
			);
		}
		$size  = $this->emit( $this->prefix . '(' );
		$first = true;
		foreach ( $this->table->columns as $column => $kind ) {
			if ( ! $first ) {
				$size += $this->emit( ',' );
			}
			$first = false;
			$size += $this->emit( SqlFormatter::value( $row[ $column ] ?? null, $kind ) );
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
		$table     = SqlFormatter::identifier( $this->table->name );
		$selection = $this->selection();
		if ( [] !== $this->table->order ) {
			$where = null === $this->after ? '' : ' WHERE ' . KeyColumn::after( $this->table->order, $this->after );
			// Qualified, so that ORDER BY names the column even where the selection has an alias of the same name.
			$order = implode( ', ', array_map( static fn ( KeyColumn $column ): string => $table . '.' . SqlFormatter::identifier( $column->name ), $this->table->order ) );
			$sql   = 'SELECT ' . $selection . ' FROM ' . $table . $where . ' ORDER BY ' . $order . ' LIMIT ' . $this->page;
		} else {
			$sql = 'SELECT ' . $selection . ' FROM ' . $table . ' LIMIT ' . $this->page . ' OFFSET ' . $this->offset;
		}
		$rows = $this->connection->rows( $sql );
		// Also a read that finds nothing more is a moment of its own outside a snapshot.
		$this->viewed();
		if ( [] === $rows ) {
			$this->exhausted = true;
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
		if ( $count < $this->page ) {
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
