<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Dump\Connection;
use BackupsForRestic\Database\Dump\KeyColumn;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Dump\Table;
use BackupsForRestic\Database\Dump\TableSource;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Jobs\JobError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A table's definition is pinned for its whole dump: a change starts the table over, a bounded
 * number of times.
 */
final class DumpRestartTest extends TestCase {

	public function test_a_table_whose_definition_changed_since_its_cursor_starts_over_and_counts_it(): void {
		$first  = $this->source( $this->table( 'first' ), null );
		$cursor = $this->stop_after_first_page( $first );

		$same = $this->source( $this->table( 'first' ), $cursor );
		self::assertFalse( $same->restarted(), 'The same definition continues.' );

		$changed = $this->source( $this->table( 'second' ), $cursor );
		self::assertTrue( $changed->restarted() );
		$output = $this->read_all( $changed );
		self::assertStringStartsWith( '-- backups-for-restic-dump/1 table `wp_rows`', $output, 'The file starts again with its header.' );
		self::assertStringContainsString( 'CREATE TABLE `wp_rows` (`id` int, `second` int);', $output );
		self::assertSame( 1, $this->results[0]['restarts'] ?? null );
	}

	public function test_a_table_that_keeps_changing_stops_the_dump_with_its_own_code(): void {
		$cursor = $this->stop_after_first_page( $this->source( $this->table( 'v0' ), null ) );
		for ( $version = 1; $version <= TableSource::MAX_RESTARTS; ++$version ) {
			$source = $this->source( $this->table( 'v' . $version ), $cursor );
			self::assertTrue( $source->restarted() );
			$cursor = $this->stop_after_first_page( $source );
		}
		try {
			$this->source( $this->table( 'v-last' ), $cursor );
			self::fail( 'A table cannot start over without end.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_unstable', JobError::from_throwable( $error )->code );
			self::assertSame( 'wp_rows', $error->context()['table'] );
		}
	}

	public function test_a_cursor_whose_key_values_no_longer_fit_still_counts_its_restarts(): void {
		// The key had two columns when the cursor was handed out; the table now has one.
		$cursor = (string) json_encode(
			[
				'v'        => 2,
				'after'    => [ '1', '2' ],
				'offset'   => 500,
				'rows'     => 500,
				'bytes'    => 9000,
				'page'     => 500,
				'views'    => 1,
				'restarts' => TableSource::MAX_RESTARTS - 1,
				'digest'   => hash( 'sha256', 'first' ),
				'changed'  => false,
				'largest'  => 100,
			]
		);
		$source = $this->source( $this->table( 'first' ), $cursor );
		self::assertTrue( $source->restarted() );
		$next = json_decode( $this->stop_after_first_page( $source ), true );
		self::assertSame( TableSource::MAX_RESTARTS, is_array( $next ) ? $next['restarts'] : null, 'The count goes on from the rejected cursor.' );

		$this->expectException( DatabaseFailed::class );
		$this->source(
			$this->table( 'first' ),
			(string) json_encode(
				[
					'after'    => [ '1', '2' ],
					'restarts' => TableSource::MAX_RESTARTS,
				] + (array) json_decode( $cursor, true )
			)
		);
	}

	public function test_outside_a_snapshot_a_change_during_the_tick_holds_back_the_trailer(): void {
		$source = $this->source( $this->table( 'first' ), null, fn (): Table => $this->table( 'second' ) );
		$output = $this->read_all( $source );
		self::assertStringNotContainsString( '-- end table', $output, 'The rows were read with a definition the table no longer has.' );
		$cursor = $source->cursor();
		self::assertNotNull( $cursor );
		self::assertSame( [], $this->results );

		// The next tick starts over, even though the definition is the first one again.
		self::assertTrue( $this->source( $this->table( 'first' ), $cursor )->restarted() );
	}

	public function test_outside_a_snapshot_a_page_read_under_another_definition_is_not_written_even_if_it_is_undone(): void {
		$checks = 0;
		$source = $this->source(
			$this->table( 'first' ),
			null,
			function () use ( &$checks ): Table {
				// Changed when the second page was read, and back again before the trailer.
				return $this->table( 2 === ++$checks ? 'second' : 'first' );
			}
		);
		$output = $this->read_all( $source );

		self::assertStringContainsString( ',(500);', $output, 'The first page was read under the definition the file started with.' );
		self::assertStringNotContainsString( '(501)', $output, 'The second page was not.' );
		self::assertStringNotContainsString( '-- end table', $output );
		$cursor = $source->cursor();
		self::assertNotNull( $cursor );
		self::assertTrue( $this->source( $this->table( 'first' ), $cursor )->restarted(), 'The next tick starts over.' );
	}

	/** @return iterable<string, array{int, bool}> */
	public static function definition_reads(): iterable {
		// Which read of the definition waits too long for its lock, and whether the tick stops
		// first. Each page is followed by one: the pages hold 500 and 1,000 of the 1,500 rows.
		yield 'the read before a stop' => [ 2, true ];
		yield 'the read before the trailer' => [ 3, false ];
	}

	#[DataProvider( 'definition_reads' )]
	public function test_outside_a_snapshot_a_lock_on_the_definition_makes_the_job_try_again( int $locked, bool $stop ): void {
		$reads  = 0;
		$source = $this->source(
			$this->table( 'first' ),
			null,
			function () use ( &$reads, $locked ): Table {
				if ( ++$reads === $locked ) {
					DatabaseFailed::raise( 'A query of the database dump failed.', [ 'errno' => 1205 ] );
				}
				return $this->table( 'first' );
			}
		);
		try {
			if ( $stop ) {
				$this->stop_after_first_page( $source );
			} else {
				$this->read_all( $source );
			}
			self::fail( 'The definition could not be read.' );
		} catch ( DatabaseFailed $error ) {
			self::assertSame( 'database_dump_locked', JobError::from_throwable( $error )->code );
			self::assertTrue( JobError::from_throwable( $error )->retryable );
			self::assertSame( 'wp_rows', $error->context()['table'] ?? null );
		}
		self::assertSame( $locked, $reads );
	}

	public function test_outside_a_snapshot_a_page_that_fails_because_of_a_change_starts_the_table_over(): void {
		$this->fail_pages = true;
		$source           = $this->source( $this->table( 'first' ), null, fn (): Table => $this->table( 'second' ) );
		$this->read_all( $source );
		self::assertNotNull( $source->cursor() );
		self::assertTrue( $this->source( $this->table( 'first' ), $source->cursor() )->restarted() );

		$this->fail_pages = true;
		$unchanged        = $this->source( $this->table( 'first' ), null, fn (): Table => $this->table( 'first' ) );
		$this->expectExceptionMessage( 'page failed' );
		$this->read_all( $unchanged );
	}

	/** @var list<array<string, mixed>> Results handed to the completion callback. */
	private array $results = [];

	private bool $fail_pages = false;

	private function table( string $column ): Table {
		return new Table( 'wp_rows', 'CREATE TABLE `wp_rows` (`id` int, `' . $column . '` int)', [ 'id' => SqlFormatter::NUMERIC ], 'PRIMARY', [ new KeyColumn( 'id', KeyColumn::INTEGER ) ], 'InnoDB', hash( 'sha256', $column ) );
	}

	/**
	 * A source over a connection with 1,500 rows, three first pages of 500.
	 */
	private function source( Table $table, ?string $cursor, ?\Closure $describe = null ): TableSource {
		$db = new class( $this ) implements Connection {
			public function __construct( private readonly DumpRestartTest $test ) {
			}

			public function rows( string $sql ): array {
				if ( $this->test->failing() ) {
					throw new DatabaseFailed( 'page failed' );
				}
				$after = 1 === preg_match( '/`id` > (\d+)/', $sql, $match ) ? (int) $match[1] : 0;
				$limit = 1 === preg_match( '/LIMIT (\d+)/', $sql, $match ) ? (int) $match[1] : 0;
				return array_map( static fn ( int $id ): array => [ 'id' => (string) $id ], $after >= 1500 ? [] : range( $after + 1, min( 1500, $after + $limit ) ) );
			}
		};
		return new TableSource( $db, $table, '', $cursor, fn ( array $result ) => $this->results[] = $result, $describe );
	}

	public function failing(): bool {
		return $this->fail_pages;
	}

	private function stop_after_first_page( TableSource $source ): string {
		// More than the header, so that the first page is read before the stop.
		self::assertStringContainsString( 'INSERT INTO', $source->read( 200 ) );
		$source->stop_at_boundary();
		$this->read_all( $source );
		$cursor = $source->cursor();
		self::assertNotNull( $cursor );
		return $cursor;
	}

	private function read_all( TableSource $source ): string {
		$output = '';
		for ( $block = $source->read( 65536 ); '' !== $block; $block = $source->read( 65536 ) ) {
			$output .= $block;
		}
		return $output;
	}
}
