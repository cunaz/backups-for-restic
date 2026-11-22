<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use BackupsForRestic\Database\Restore\Connection;
use BackupsForRestic\Database\Restore\ExchangePlan;
use BackupsForRestic\Database\Restore\OwnedTables;
use BackupsForRestic\Exception\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The saved map of physical table generations: validation, positions and rebinding. */
final class ExchangePlanTest extends TestCase {

	private const string TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private static function owned(): OwnedTables {
		$none = new class() implements Connection {
			public function rows( string $sql ): array {
				throw new \LogicException( 'Naming needs no database: ' . $sql );
			}

			public function execute( string $sql ): int {
				throw new \LogicException( 'Naming needs no database: ' . $sql );
			}
		};
		return new OwnedTables( $none, 'wp_', str_repeat( 'f', 64 ), self::TOKEN );
	}

	/** @return array<string,mixed> A valid document: three WordPress tables and one new table. */
	private static function document(): array {
		return [
			'version'  => 1,
			'database' => 'site_db',
			'uuid'     => '08a6c803-e508-11f1-b766-1a4b24ab2573',
			'prefix'   => 'wp_',
			'witness'  => '100',
			'pairs'    => [
				self::pair( 'wp_options', '11', '21' ),
				self::pair( 'wp_users', '12', '22' ),
				self::pair( 'wp_usermeta', '13', '23' ),
				self::pair( 'wp_new', null, '24' ),
			],
		];
	}

	/** @return array<string,string|null> */
	private static function pair( string $name, ?string $before, string $after ): array {
		return [
			'name'          => $name,
			'before'        => $before,
			'after'         => $after,
			'before_schema' => null === $before ? null : str_repeat( 'b', 64 ),
			'after_schema'  => str_repeat( 'a', 64 ),
		];
	}

	private static function plan(): ExchangePlan {
		return ExchangePlan::load( self::document(), self::owned(), 'wp_' );
	}

	public function test_a_valid_document_is_kept_exactly(): void {
		self::assertSame( self::document(), self::plan()->document );
	}

	public function test_positions_name_every_identity_of_each_generation(): void {
		$base     = '_bfr_r_' . self::TOKEN;
		$plan     = self::plan();
		$prepared = [
			$base . '_p'  => '100',
			$base . '_t0' => '21',
			$base . '_t1' => '22',
			$base . '_t2' => '23',
			$base . '_t3' => '24',
			'wp_options'  => '11',
			'wp_usermeta' => '13',
			'wp_users'    => '12',
		];
		ksort( $prepared, SORT_STRING );
		self::assertSame( $prepared, $plan->positions( 'prepared' ) );
		$applied = [
			$base . '_a'  => '100',
			$base . '_b0' => '11',
			$base . '_b1' => '12',
			$base . '_b2' => '13',
			'wp_new'      => '24',
			'wp_options'  => '21',
			'wp_usermeta' => '23',
			'wp_users'    => '22',
		];
		ksort( $applied, SORT_STRING );
		self::assertSame( $applied, $plan->positions( 'applied' ) );
		self::assertSame( $base . '_u', array_key_first( array_filter( $plan->positions( 'undone' ), static fn ( string $id ): bool => '100' === $id ) ) );
		self::assertSame( [ $base . '_p', $base . '_a', $base . '_u', 'wp_options', $base . '_t0', $base . '_b0', 'wp_users', $base . '_t1', $base . '_b1', 'wp_usermeta', $base . '_t2', $base . '_b2', 'wp_new', $base . '_t3', $base . '_b3' ], $plan->all_names() );
	}

	public function test_rebinding_replaces_only_the_named_identities(): void {
		$plan                        = self::plan();
		$rebound                     = $plan->rebind( [ 1 => '32' ], '101' );
		$expect                      = self::document();
		$expect['pairs'][1]['after'] = '32';
		$expect['witness']           = '101';
		self::assertSame( $expect, $rebound->document );
		self::assertSame( self::document(), $plan->document, 'The original plan stays as it was.' );
		self::assertSame( self::document(), $plan->rebind( [], null )->document );
		self::assertSame( '102', $plan->rebind( [], '102' )->document['witness'] );
		self::assertSame( [ '21', '22', '23', '34' ], array_column( $plan->rebind( [ 3 => '34' ], null )->document['pairs'], 'after' ) );
	}

	/** @return iterable<string, array{array<int,string>, string|null}> */
	public static function bad_rebinds(): iterable {
		yield 'unknown ordinal' => [ [ 4 => '31' ], null ];
		yield 'negative ordinal' => [ [ -1 => '31' ], null ];
		yield 'not an identity' => [ [ 0 => 'x' ], null ];
		yield 'leading zero' => [ [ 0 => '031' ], null ];
		yield 'a recorded identity again' => [ [ 0 => '11' ], null ];
		yield 'two tables, one identity' => [
			[
				0 => '31',
				1 => '31',
			],
			null,
		];
		yield 'the witness identity' => [ [ 0 => '100' ], null ];
		yield 'an invalid witness' => [ [], '0' ];
		yield 'a witness that is a table' => [ [], '21' ];
	}

	/** @param array<int,string> $after */
	#[DataProvider( 'bad_rebinds' )]
	public function test_rebinding_refuses_what_a_document_could_not_hold( array $after, ?string $witness ): void {
		$this->expectException( FormatException::class );
		self::plan()->rebind( $after, $witness );
	}

	/** @return iterable<string, array{\Closure(array<string,mixed>):array<string,mixed>}> */
	public static function invalid(): iterable {
		yield 'only a version' => [ static fn ( array $d ): array => [ 'version' => 1 ] ];
		yield 'another version' => [ static fn ( array $d ): array => [ 'version' => 2 ] + $d ];
		yield 'another prefix' => [ static fn ( array $d ): array => [ 'prefix' => 'xx_' ] + $d ];
		yield 'no uuid' => [ static fn ( array $d ): array => [ 'uuid' => null ] + $d ];
		yield 'uuid in capitals' => [ static fn ( array $d ): array => [ 'uuid' => '08A6C803-E508-11F1-B766-1A4B24AB2573' ] + $d ];
		yield 'uuid with a suffix' => [ static fn ( array $d ): array => [ 'uuid' => '08a6c803-e508-11f1-b766-1a4b24ab2573x' ] + $d ];
		yield 'no database' => [ static fn ( array $d ): array => [ 'database' => null ] + $d ];
		yield 'database with a dot' => [ static fn ( array $d ): array => [ 'database' => 'site.db' ] + $d ];
		yield 'database too long' => [ static fn ( array $d ): array => [ 'database' => str_repeat( 'd', 65 ) ] + $d ];
		yield 'empty database' => [ static fn ( array $d ): array => [ 'database' => '' ] + $d ];
		yield 'invalid witness' => [ static fn ( array $d ): array => [ 'witness' => '0' ] + $d ];
		yield 'no pairs' => [ static fn ( array $d ): array => [ 'pairs' => [] ] + $d ];
		yield 'pairs as a map' => [ static fn ( array $d ): array => [ 'pairs' => array_combine( [ 'a', 'b', 'c', 'd' ], $d['pairs'] ) ] + $d ];
		yield 'pairs not an array' => [ static fn ( array $d ): array => [ 'pairs' => 'x' ] + $d ];
		yield 'too many pairs' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], ...array_map( static fn ( int $n ): array => self::pair( 'wp_t' . $n, null, (string) ( 1000 + $n ) ), range( 1, 125 ) ) ] ] + $d ];
		yield 'a pair that is not an array' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], 'x' ] ] + $d ];
		yield 'a pair without a name' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], [ 'name' => null ] + self::pair( 'wp_x', null, '30' ) ] ] + $d ];
		yield 'a name outside the prefix' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], self::pair( 'other', null, '30' ) ] ] + $d ];
		yield 'a name with a dot' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], self::pair( 'wp_a.b', null, '30' ) ] ] + $d ];
		yield 'a name twice' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], self::pair( 'wp_new', null, '30' ) ] ] + $d ];
		yield 'a pair without after' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], [ 'after' => null ] + self::pair( 'wp_x', null, '30' ) ] ] + $d ];
		yield 'an invalid after schema' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], [ 'after_schema' => 'x' ] + self::pair( 'wp_x', null, '30' ) ] ] + $d ];
		yield 'a pair without before' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], array_diff_key( self::pair( 'wp_x', null, '30' ), [ 'before' => true ] ) ] ] + $d ];
		yield 'a pair without before schema' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], array_diff_key( self::pair( 'wp_x', null, '30' ), [ 'before_schema' => true ] ) ] ] + $d ];
		yield 'a schema for a new table' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], [ 'before_schema' => str_repeat( 'b', 64 ) ] + self::pair( 'wp_x', null, '30' ) ] ] + $d ];
		yield 'no schema for a replaced table' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], [ 'before_schema' => null ] + self::pair( 'wp_x', '31', '30' ) ] ] + $d ];
		yield 'an invalid before' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], self::pair( 'wp_x', 'x', '30' ) ] ] + $d ];
		yield 'before and after alike' => [ static fn ( array $d ): array => [ 'pairs' => [ ...$d['pairs'], self::pair( 'wp_x', '30', '30' ) ] ] + $d ];
		yield 'no users table' => [ static fn ( array $d ): array => [ 'pairs' => [ $d['pairs'][0], $d['pairs'][2], $d['pairs'][3] ] ] + $d ];
		yield 'a new options table' => [ static fn ( array $d ): array => [ 'pairs' => [ self::pair( 'wp_options', null, '21' ), $d['pairs'][1], $d['pairs'][2] ] ] + $d ];
	}

	/** @param \Closure(array<string,mixed>):array<string,mixed> $change */
	#[DataProvider( 'invalid' )]
	public function test_an_invalid_document_is_refused( \Closure $change ): void {
		$this->expectException( FormatException::class );
		ExchangePlan::load( $change( self::document() ), self::owned(), 'wp_' );
	}

	public function test_a_document_is_refused_for_another_site_or_an_empty_prefix(): void {
		try {
			ExchangePlan::load( self::document(), self::owned(), 'xx_' );
			self::fail( 'A plan of another prefix was accepted.' );
		} catch ( FormatException ) {
			self::assertTrue( true );
		}
		$document           = self::document();
		$document['prefix'] = '';
		foreach ( $document['pairs'] as $number => $pair ) {
			$document['pairs'][ $number ]['name'] = substr( $pair['name'], 3 );
		}
		$this->expectException( FormatException::class );
		ExchangePlan::load( $document, self::owned(), '' );
	}

	public function test_only_an_array_is_a_document(): void {
		$this->expectException( FormatException::class );
		ExchangePlan::load( 'plan', self::owned(), 'wp_' );
	}

	public function test_the_largest_document_is_accepted(): void {
		$document          = self::document();
		$document['pairs'] = [ ...$document['pairs'], ...array_map( static fn ( int $n ): array => self::pair( 'wp_t' . $n, null, (string) ( 1000 + $n ) ), range( 1, 124 ) ) ];
		self::assertCount( 128, ExchangePlan::load( $document, self::owned(), 'wp_' )->document['pairs'] );
		$document['database'] = str_repeat( 'd', 64 );
		$document['witness']  = '18446744073709551615';
		self::assertSame( str_repeat( 'd', 64 ), ExchangePlan::load( $document, self::owned(), 'wp_' )->document['database'] );
	}
}
