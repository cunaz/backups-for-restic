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
 * with the row and byte counts.
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
 */
final class TableSource implements ResumableByteSource {

	public const string FORMAT = 'backups-for-restic-dump/1';

	/** Hard format limit including the semicolon, excluding the physical newline. */
	public const int MAX_STATEMENT_BYTES = 16777216;

	private const int STATEMENT_BYTES = 1048576;

	/** At most as many rows per INSERT as the importer accepts (InsertStatement::MAX_ROWS). */
	private const int STATEMENT_ROWS = 20000;

	private const int TARGET_PAGE_BYTES = 2097152;

	/** Cursors of earlier versions have no version; they cannot continue and the table starts over. */
	private const int CURSOR_VERSION = 2;

	/**
	 * Produced but not yet read output.
	 *
	 * @var string
	 */
	private string $buffer = '';

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
	 * @var string|null
	 */
	private ?string $carry = null;

	/**
	 * Start of every INSERT statement of the table.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Opens the table at a cursor.
	 *
	 * @param Connection                                                                      $connection  Database; a DedicatedConnection inside begin() for one snapshot.
	 * @param Table                                                                           $table       Table.
	 * @param string                                                                          $preamble    Session settings, one statement per line.
	 * @param string|null                                                                     $cursor      Cursor from an earlier source, or null to start.
	 * @param \Closure(array{rows: int, bytes: int, snapshot: bool, key: list<string>}): void $on_complete Receives the counts and how the rows were read, once the trailer is written.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Table $table,
		private readonly string $preamble,
		?string $cursor,
		private readonly \Closure $on_complete
	) {
		foreach ( array_keys( $table->order ) as $position ) {
			$alias = '__bfr_key_' . $position;
			while ( isset( $table->columns[ $alias ] ) ) {
				$alias .= '_';
			}
			$this->aliases[ $position ] = $alias;
		}
		$state           = self::saved( $cursor, $table );
		$this->restarted = null !== $cursor && null === $state;
		$this->started   = null !== $state;
		$this->after     = $state['after'] ?? null;
		$this->offset    = $state['offset'] ?? 0;
		$this->rows      = $state['rows'] ?? 0;
		$this->bytes     = $state['bytes'] ?? 0;
		$this->page      = $state['page'] ?? 500;
		$this->views     = $state['views'] ?? 0;
		$this->snapshot  = $connection instanceof DedicatedConnection && $connection->in_snapshot() && $table->versioned();
		$this->prefix    = 'INSERT INTO ' . SqlFormatter::identifier( $table->name ) . ' (' . implode( ',', array_map( SqlFormatter::identifier( ... ), array_keys( $table->columns ) ) ) . ') VALUES ';
	}

	/**
	 * The state of a cursor that this table can continue. A cursor of an earlier version, a
	 * damaged one and one whose key values do not fit the table's key cannot be continued.
	 *
	 * @param string|null $cursor Cursor.
	 * @param Table       $table  Table.
	 * @return array{after: list<string>|null, offset: int, rows: int, bytes: int, page: int, views: int}|null State, or null to start the table from its beginning.
	 */
	private static function saved( ?string $cursor, Table $table ): ?array {
		$state = null === $cursor ? null : json_decode( $cursor, true );
		if ( ! is_array( $state ) || self::CURSOR_VERSION !== ( $state['v'] ?? null ) ) {
			return null;
		}
		foreach ( [ 'offset', 'rows', 'bytes', 'page', 'views' ] as $field ) {
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
			'after'  => $after,
			'offset' => $state['offset'],
			'rows'   => $state['rows'],
			'bytes'  => $state['bytes'],
			'page'   => max( 1, min( 20000, $state['page'] ) ),
			'views'  => $state['views'],
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
		$available = strlen( $this->buffer );
		while ( $available < $length && ! $this->finished && ! $this->stopped ) {
			$this->produce();
			$available = strlen( $this->buffer );
		}
		$block        = substr( $this->buffer, 0, $length );
		$this->buffer = substr( $this->buffer, strlen( $block ) );
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
				'v'      => self::CURSOR_VERSION,
				'after'  => $this->after,
				'offset' => $this->offset,
				'rows'   => $this->rows,
				'bytes'  => $this->bytes,
				'page'   => $this->page,
				'views'  => $this->views,
			]
		);
	}

	/**
	 * Whether the table starts over: the cursor is from an earlier version or damaged.
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
				$this->stopped = true;
				// A row carried over from the stream was not written; the next tick reads it again.
				$this->carry = null;
				$this->release();
				return;
			}
			if ( [] === $this->table->order && $this->snapshot ) {
				$this->stream();
			} else {
				$this->page();
			}
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
			]
		);
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
	 * Counts a statement that returned rows: one view per snapshot, one per statement outside.
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
			$column      = SqlFormatter::identifier( $name );
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
			$this->connection->stream( 'SELECT ' . $this->selection() . ' FROM ' . $table . ( $this->offset > 0 ? ' LIMIT 18446744073709551615 OFFSET ' . $this->offset : '' ) );
			$this->streaming = true;
		}
		$tuples      = null === $this->carry ? [] : [ $this->carry ];
		$length      = null === $this->carry ? 0 : strlen( $this->carry ) + 1;
		$this->carry = null;
		while ( true ) {
			$row = $this->connection instanceof DedicatedConnection ? $this->connection->next() : null;
			if ( null === $row ) {
				$this->streaming = false;
				$this->exhausted = true;
				break;
			}
			$tuple = $this->tuple( $row );
			if ( [] !== $tuples && ( $length + strlen( $tuple ) > self::STATEMENT_BYTES || count( $tuples ) >= self::STATEMENT_ROWS ) ) {
				$this->carry = $tuple;
				break;
			}
			$tuples[] = $tuple;
			$length  += strlen( $tuple ) + 1;
		}
		if ( [] !== $tuples ) {
			$this->viewed();
			$this->statement( $tuples );
			$this->rows   += count( $tuples );
			$this->offset += count( $tuples );
		}
	}

	/**
	 * One row as an INSERT tuple.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return string Tuple.
	 * @throws DatabaseFailed If the row exceeds the importer's statement limit.
	 */
	private function tuple( array $row ): string {
		$values = [];
		foreach ( $this->table->columns as $column => $kind ) {
			$values[] = SqlFormatter::value( $row[ $column ] ?? null, $kind );
		}
		$tuple = '(' . implode( ',', $values ) . ')';
		if ( strlen( $this->prefix ) + strlen( $tuple ) + 1 > self::MAX_STATEMENT_BYTES ) {
			DatabaseFailed::raise( 'A database row exceeds the 16 MiB SQL statement limit and cannot be backed up.', [ 'table' => $this->table->name ] );
		}
		return $tuple;
	}

	/**
	 * Writes tuples as one INSERT statement.
	 *
	 * @param list<string> $tuples Tuples.
	 * @return int Bytes written.
	 */
	private function statement( array $tuples ): int {
		return $this->emit( $this->prefix . implode( ',', $tuples ) . ";\n" );
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
		if ( [] === $rows ) {
			$this->exhausted = true;
			return;
		}
		$this->viewed();

		if ( [] !== $this->table->order ) {
			$last  = $rows[ array_key_last( $rows ) ];
			$after = [];
			foreach ( $this->table->order as $position => $column ) {
				$value = $last[ $column->computed() ? $this->aliases[ $position ] : $column->name ] ?? null;
				// Checked before anything is written: a column whose type changed since the
				// catalog read it must not produce a cursor that misses or repeats rows.
				$column->literal( $value );
				$after[] = (string) $value;
			}
			$this->after = $after;
		}
		$tuples = [];
		$length = 0;
		$size   = 0;
		foreach ( $rows as $row ) {
			$tuple = $this->tuple( $row );
			if ( [] !== $tuples && $length + strlen( $tuple ) > self::STATEMENT_BYTES ) {
				$size  += $this->statement( $tuples );
				$tuples = [];
				$length = 0;
			}
			$tuples[] = $tuple;
			$length  += strlen( $tuple ) + 1;
		}
		$size += $this->statement( $tuples );

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
	}

	/**
	 * Appends text to the output.
	 *
	 * @param string $text Text.
	 * @return int Its length.
	 */
	private function emit( string $text ): int {
		$this->buffer .= $text;
		$this->bytes  += strlen( $text );
		return strlen( $text );
	}
}
