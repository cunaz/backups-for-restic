<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Fuzz;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use BackupsForRestic\Tests\Fuzz\Fuzzer;
use BackupsForRestic\Tests\Fuzz\Targets;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * A short, deterministic fuzzing round per parser, plus a replay of every past finding in
 * tests/Fixtures/fuzz/<target>/. The long campaigns run nightly with bin/fuzz.php.
 */
final class FuzzTargetsTest extends TestCase {

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function targets(): iterable {
		foreach ( array_keys( Targets::all() ) as $name ) {
			yield $name => [ $name ];
		}
	}

	#[DataProvider( 'targets' )]
	public function test_parser_rejects_malformed_input_only_with_plugin_exceptions( string $name ): void {
		$fuzzer      = new Fuzzer( Targets::all()[ $name ], new Randomizer( new Xoshiro256StarStar( 20261109 ) ) );
		$regressions = glob( Vectors::fixtures() . '/fuzz/' . $name . '/*' );
		foreach ( false === $regressions ? [] : $regressions as $file ) {
			$fuzzer->execute( (string) file_get_contents( $file ) );
		}
		$fuzzer->run( 30.0, 2000 );

		$findings = array_map( static fn ( array $finding ): string => (string) $finding[1], $fuzzer->findings() );
		self::assertSame( [], $findings );
	}
}
