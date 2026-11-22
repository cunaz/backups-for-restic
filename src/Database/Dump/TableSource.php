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
 * for the whole dump of its table appears exactly once. A table without such a key is read
 * by its position in the table. The page size adapts so that one page stays near 2 MiB of
 * SQL. A page is the unit of resumption: the backup stops between pages and continues with
 * the next one in a later request.
 */
final class TableSource implements ResumableByteSource {

	public const string FORMAT = 'backups-for-restic-dump/1';

	/** Hard format limit including the semicolon, excluding the physical newline. */
	public const int MAX_STATEMENT_BYTES = 16777216;

	private const int STATEMENT_BYTES = 1048576;

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
	 * Opens the table at a cursor.
	 *
	 * @param Connection               $connection  Database.
	 * @param Table                    $table       Table.
	 * @param string                   $preamble    Session settings, one statement per line.
	 * @param string|null              $cursor      Cursor from an earlier source, or null to start.
	 * @param \Closure(int, int): void $on_complete Receives rows and bytes once the trailer is written.
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
	}

	/**
	 * The state of a cursor that this table can continue. A cursor of an earlier version, a
	 * damaged one and one whose key values do not fit the table's key cannot be continued.
	 *
	 * @param string|null $cursor Cursor.
	 * @param Table       $table  Table.
	 * @return array{after: list<string>|null, offset: int, rows: int, bytes: int, page: int}|null State, or null to start the table from its beginning.
	 */
	private static function saved( ?string $cursor, Table $table ): ?array {
		$state = null === $cursor ? null : json_decode( $cursor, true );
		if ( ! is_array( $state ) || self::CURSOR_VERSION !== ( $state['v'] ?? null ) ) {
			return null;
		}
		foreach ( [ 'offset', 'rows', 'bytes', 'page' ] as $field ) {
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
	 * A table has no warnings.
	 *
	 * @return list<string> None.
	 */
	public function warnings(): array {
		return [];
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
				return;
			}
			$this->page();
			return;
		}
		$this->emit( '-- end table rows=' . $this->rows . ' bytes=' . $this->bytes . "\n" );
		$this->finished = true;
		( $this->on_complete )( $this->rows, $this->bytes );
	}

	/**
	 * Fetches one page and turns it into INSERT statements.
	 *
	 * @throws DatabaseFailed If the query fails or a key value is not what its column holds.
	 */
	private function page(): void {
		$table = SqlFormatter::identifier( $this->table->name );
		// SELECT * omits invisible columns even though SHOW COLUMNS includes them.
		$selection = array_map( SqlFormatter::identifier( ... ), array_keys( $this->table->columns ) );
		foreach ( $this->table->order as $position => $column ) {
			if ( $column->computed() ) {
				$selection[] = $column->value() . ' AS ' . SqlFormatter::identifier( $this->aliases[ $position ] );
			}
		}
		// A generated-only table still has rows: one empty INSERT tuple recreates each one.
		$selection = [] === $selection ? '1' : implode( ', ', $selection );
		if ( [] !== $this->table->order ) {
			$where = null === $this->after ? '' : ' WHERE ' . KeyColumn::after( $this->table->order, $this->after );
			$order = implode( ', ', array_map( static fn ( KeyColumn $column ): string => SqlFormatter::identifier( $column->name ), $this->table->order ) );
			$sql   = 'SELECT ' . $selection . ' FROM ' . $table . $where . ' ORDER BY ' . $order . ' LIMIT ' . $this->page;
		} else {
			$sql = 'SELECT ' . $selection . ' FROM ' . $table . ' LIMIT ' . $this->page . ' OFFSET ' . $this->offset;
		}
		$rows = $this->connection->rows( $sql );
		if ( [] === $rows ) {
			$this->exhausted = true;
			return;
		}

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
		$columns = $this->table->columns;
		$prefix  = 'INSERT INTO ' . $table . ' (' . implode( ',', array_map( SqlFormatter::identifier( ... ), array_keys( $columns ) ) ) . ') VALUES ';
		$tuples  = [];
		$length  = 0;
		$size    = 0;
		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $columns as $column => $kind ) {
				$values[] = SqlFormatter::value( $row[ $column ] ?? null, $kind );
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( strlen( $prefix ) + strlen( $tuple ) + 1 > self::MAX_STATEMENT_BYTES ) {
				DatabaseFailed::raise( 'A database row exceeds the 16 MiB SQL statement limit and cannot be backed up.', [ 'table' => $this->table->name ] );
			}
			if ( [] !== $tuples && $length + strlen( $tuple ) > self::STATEMENT_BYTES ) {
				$size  += $this->emit( $prefix . implode( ',', $tuples ) . ";\n" );
				$tuples = [];
				$length = 0;
			}
			$tuples[] = $tuple;
			$length  += strlen( $tuple ) + 1;
		}
		$size += $this->emit( $prefix . implode( ',', $tuples ) . ";\n" );

		$count         = count( $rows );
		$this->rows   += $count;
		$this->offset += $count;
		if ( $count < $this->page ) {
			$this->exhausted = true;
		}
		if ( $size > 2 * self::TARGET_PAGE_BYTES ) {
			$this->page = max( 1, intdiv( $this->page, 2 ) );
		} elseif ( $size < intdiv( self::TARGET_PAGE_BYTES, 2 ) && $count === $this->page ) {
			$this->page = min( 20000, $this->page * 2 );
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
