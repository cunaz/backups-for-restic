<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Restore\TableImporter;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The importer compares the largest statement that the dump recorded for each table with what
 * it reads and what the server takes, before it sends a statement (ADR 0035).
 */
final class TableImporterLimitsTest extends TestCase {

	private const string TOKEN = '0123456789abcdef0123456789abcdef';

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'importer-limits' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * A server that tells its packet limit and nothing else: an import that gets past the
	 * check meets the next statement as an error.
	 */
	private static function server( int $packet ): object {
		return new class( $packet ) implements Connection {
			/** @var list<string> */
			public array $statements = [];

			public function __construct( private int $packet ) {}

			public function rows( string $sql ): array {
				$this->statements[] = $sql;
				if ( 'SELECT @@SESSION.max_allowed_packet AS packet' === $sql ) {
					return [ [ 'packet' => (string) $this->packet ] ];
				}
				throw new \LogicException( 'The import went on: ' . $sql );
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'The import sent a statement: ' . $sql );
			}
		};
	}

	/**
	 * A manifest of tables with the given largest statements; null for a table whose dump
	 * recorded none.
	 *
	 * @param array<string,int|null> $largest Largest statement by table name.
	 */
	private static function manifest( array $largest ): Manifest {
		$tables = [];
		foreach ( $largest as $name => $bytes ) {
			$tables[] = [
				'name'  => $name,
				'file'  => '010-' . $name . '.sql',
				'rows'  => 1,
				'bytes' => ( $bytes ?? 0 ) + 4096,
			] + ( null === $bytes ? [] : [
				'key'               => [ 'id' ],
				'snapshot'          => true,
				'restarts'          => 0,
				'largest_statement' => $bytes,
			] );
		}
		return Manifest::parse(
			json_encode(
				[
					'format'    => 'backups-for-restic-dump/1',
					'created'   => '2026-01-01T00:00:00Z',
					'server'    => 'fixture',
					'charset'   => 'utf8mb4',
					'time_zone' => '+00:00',
					'prefix'    => 'wp_',
					'tables'    => $tables,
					'views'     => [],
				],
				JSON_THROW_ON_ERROR
			),
			'wp_'
		);
	}

	/**
	 * Runs one tick and returns its refusal.
	 *
	 * @param object $server server().
	 */
	private function refusal( Manifest $manifest, object $server ): DatabaseFailed {
		self::assertInstanceOf( Connection::class, $server );
		try {
			( new TableImporter( $server, $manifest, $this->directory, self::TOKEN ) )->tick( 16 );
		} catch ( DatabaseFailed $refused ) {
			self::assertSame( [ 'SELECT @@SESSION.max_allowed_packet AS packet' ], $server->statements, 'Nothing but the limit was asked for, and no statement was sent.' );
			return $refused;
		}
		self::fail( 'The import was not refused.' );
	}

	/** The length of a statement of the given table once the importer has put its shadow's name in. */
	private static function sent( int $number, string $table, int $bytes ): int {
		return $bytes - strlen( '`' . $table . '`' ) + strlen( '`_bfr_r_' . self::TOKEN . '_t' . $number . '`' );
	}

	public function test_a_statement_beyond_what_the_import_reads_is_refused_before_any_statement(): void {
		$refused = $this->refusal(
			self::manifest(
				[
					'wp_small'  => 100,
					'wp_large'  => 16777217,
					'wp_larger' => 16777216,
				]
			),
			self::server( 1073741824 )
		);
		self::assertSame( 'database_restore_too_large', $refused->context()['code'] ?? null );
		self::assertSame( 'wp_large', $refused->context()['table'] ?? null );
		self::assertStringContainsString( '16777217 bytes, more than the 16777216 bytes', $refused->getMessage() );
	}

	public function test_a_statement_beyond_the_servers_packet_limit_is_refused_with_the_limit_that_the_largest_needs(): void {
		$sent    = self::sent( 1, 'wp_bb', 2000000 );
		$refused = $this->refusal(
			self::manifest(
				[
					'wp_a'  => 1000000,
					'wp_bb' => 2000000,
					'wp_c'  => 900000,
				]
			),
			self::server( 1048576 )
		);
		self::assertSame( 'database_restore_packet_limit', $refused->context()['code'] ?? null );
		self::assertSame( 'wp_bb', $refused->context()['table'] ?? null );
		$needed = intdiv( $sent + 64 + 1023, 1024 ) * 1024;
		self::assertSame( 0, $needed % 1024 );
		self::assertGreaterThanOrEqual( $sent + 64, $needed );
		self::assertStringContainsString( 'needs ' . $sent . ' bytes of SQL and a max_allowed_packet of at least ' . $needed . ' bytes; the database server allows 1048576 bytes', $refused->getMessage() );
	}

	public function test_the_shadows_name_counts_and_a_statement_that_just_fits_goes_ahead(): void {
		$sent = self::sent( 0, 'wp_a', 500000 );
		// Its margin of 64 bytes and the shadow's longer name just fit.
		$server = self::server( $sent + 64 );
		try {
			( new TableImporter( $server, self::manifest( [ 'wp_a' => 500000 ] ), $this->directory, self::TOKEN ) )->tick( 16 );
			self::fail( 'The import of a statement that fits did not go on.' );
		} catch ( \LogicException $went_on ) {
			self::assertStringStartsWith( 'The import went on: ', $went_on->getMessage() );
		}
		self::assertSame( 'database_restore_packet_limit', $this->refusal( self::manifest( [ 'wp_a' => 500000 ] ), self::server( $sent + 63 ) )->context()['code'] ?? null );
	}

	public function test_a_table_whose_dump_recorded_nothing_is_left_to_the_check_of_each_batch(): void {
		try {
			( new TableImporter(
				self::server( 1024 ),
				self::manifest(
					[
						'wp_old' => null,
						'wp_new' => 10,
					]
				),
				$this->directory,
				self::TOKEN
			) )->tick( 16 );
			self::fail( 'The import of a dump without a record of its statements did not go on.' );
		} catch ( \LogicException $went_on ) {
			self::assertStringStartsWith( 'The import went on: ', $went_on->getMessage() );
		}
		// The tables after it are still checked.
		$refused = $this->refusal(
			self::manifest(
				[
					'wp_old' => null,
					'wp_new' => 2000,
				]
			),
			self::server( 1024 )
		);
		self::assertSame( 'database_restore_packet_limit', $refused->context()['code'] ?? null );
		self::assertSame( 'wp_new', $refused->context()['table'] ?? null );
	}

	/** @return iterable<string, array{int, int}> */
	public static function packet_limits(): iterable {
		// What the statement of wp_x, at ordinal 0, adds with the shadow's name and the margin.
		$extra = self::sent( 0, 'wp_x', 0 ) + 64;
		yield 'one byte above a multiple of 1024' => [ 1024 * 1000 + 1 - $extra, 1024 * 1001 ];
		yield 'a multiple of 1024' => [ 1024 * 1000 - $extra, 1024 * 1000 ];
	}

	#[DataProvider( 'packet_limits' )]
	public function test_the_limit_that_the_refusal_names_is_the_next_multiple_of_1024( int $largest, int $limit ): void {
		$refused = $this->refusal( self::manifest( [ 'wp_x' => $largest ] ), self::server( 4096 ) );
		self::assertStringContainsString( 'a max_allowed_packet of at least ' . $limit . ' bytes; the database server allows 4096 bytes', $refused->getMessage() );
	}

	public function test_a_statement_of_exactly_what_the_import_reads_goes_ahead(): void {
		try {
			( new TableImporter( self::server( 1073741824 ), self::manifest( [ 'wp_a' => 16777216 ] ), $this->directory, self::TOKEN ) )->tick( 16 );
			self::fail( 'The import of a statement of 16 MiB did not go on.' );
		} catch ( \LogicException $went_on ) {
			self::assertStringStartsWith( 'The import went on: ', $went_on->getMessage() );
		}
	}

	public function test_of_two_statements_of_the_same_size_the_first_table_is_named(): void {
		$large = $this->refusal(
			self::manifest(
				[
					'wp_a' => 16777300,
					'wp_b' => 16777300,
				]
			),
			self::server( 1073741824 )
		);
		self::assertSame( 'wp_a', $large->context()['table'] ?? null );
		$packet = $this->refusal(
			self::manifest(
				[
					'wp_a' => 900000,
					'wp_b' => 900000,
				]
			),
			self::server( 524288 )
		);
		self::assertSame( 'database_restore_packet_limit', $packet->context()['code'] ?? null );
		self::assertSame( 'wp_a', $packet->context()['table'] ?? null );
	}
}
