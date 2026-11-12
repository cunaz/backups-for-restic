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
 * Pages follow the integer primary key where the table has one, otherwise an offset in the
 * order of the primary key columns. The page size adapts so that one page stays near 2 MiB of
 * SQL. A page is the unit of resumption: the backup stops between pages and continues with
 * the next one in a later request, so every row appears exactly once.
 */
final class TableSource implements ResumableByteSource {

	public const string FORMAT = 'backups-for-restic-dump/1';

	private const int STATEMENT_BYTES = 1048576;

	private const int TARGET_PAGE_BYTES = 2097152;

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
	 * Primary key value of the last row, for keyset paging.
	 *
	 * @var string|null
	 */
	private ?string $after;

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
		$state         = null === $cursor ? null : json_decode( $cursor, true );
		$state         = is_array( $state ) ? $state : null;
		$this->started = null !== $state;
		$after         = $state['after'] ?? null;
		$this->after   = is_string( $after ) && 1 === preg_match( '/\A-?\d+(\.\d+)?\z/', $after ) ? $after : null;
		$this->offset  = is_int( $state['offset'] ?? null ) ? $state['offset'] : 0;
		$this->rows    = is_int( $state['rows'] ?? null ) ? $state['rows'] : 0;
		$this->bytes   = is_int( $state['bytes'] ?? null ) ? $state['bytes'] : 0;
		$this->page    = is_int( $state['page'] ?? null ) ? max( 1, $state['page'] ) : 500;
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
				'after'  => $this->after,
				'offset' => $this->offset,
				'rows'   => $this->rows,
				'bytes'  => $this->bytes,
				'page'   => $this->page,
			]
		);
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
	 * @throws DatabaseFailed If the query fails.
	 */
	private function page(): void {
		$table = SqlFormatter::identifier( $this->table->name );
		// SELECT * omits invisible columns even though SHOW COLUMNS includes them.
		$selection = implode( ', ', array_map( SqlFormatter::identifier( ... ), array_keys( $this->table->columns ) ) );
		if ( null !== $this->table->key ) {
			$key   = SqlFormatter::identifier( $this->table->key );
			$where = null === $this->after ? '' : ' WHERE ' . $key . ' > ' . $this->after;
			$sql   = 'SELECT ' . $selection . ' FROM ' . $table . $where . ' ORDER BY ' . $key . ' LIMIT ' . $this->page;
		} else {
			$order = [] === $this->table->order ? '' : ' ORDER BY ' . implode( ', ', array_map( SqlFormatter::identifier( ... ), $this->table->order ) );
			$sql   = 'SELECT ' . $selection . ' FROM ' . $table . $order . ' LIMIT ' . $this->page . ' OFFSET ' . $this->offset;
		}
		$rows = $this->connection->rows( $sql );
		if ( [] === $rows ) {
			$this->exhausted = true;
			return;
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
		if ( null !== $this->table->key ) {
			$last        = $rows[ $count - 1 ][ $this->table->key ] ?? null;
			$this->after = is_string( $last ) ? $last : $this->after;
		}
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
