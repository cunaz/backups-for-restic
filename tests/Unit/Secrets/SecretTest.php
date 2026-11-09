<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Secrets;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Secrets\Secret;

final class SecretTest extends TestCase {

	private const string VALUE = 'correct horse battery staple';

	public function test_reveals_only_on_request(): void {
		$secret = new Secret( self::VALUE );
		self::assertSame( self::VALUE, $secret->reveal() );
		self::assertSame( '[redacted]', (string) $secret );
		self::assertFalse( $secret->is_empty() );
		self::assertTrue( ( new Secret( '' ) )->is_empty() );
		self::assertTrue( $secret->equals( new Secret( self::VALUE ) ) );
		self::assertFalse( $secret->equals( new Secret( 'other' ) ) );
	}

	public function test_no_dump_format_shows_the_value(): void {
		$secret = new Secret( self::VALUE );
		ob_start();
		var_dump( $secret );
		$dumps = [ (string) ob_get_clean(), print_r( $secret, true ), var_export( $secret, true ), print_r( [ 'nested' => $secret ], true ) ];
		foreach ( $dumps as $dump ) {
			self::assertStringNotContainsString( self::VALUE, $dump );
		}
	}

	public function test_cannot_be_serialized_or_encoded(): void {
		$secret = new Secret( self::VALUE );
		try {
			serialize( $secret );
			self::fail( 'serialize() must fail.' );
		} catch ( \Exception $e ) {
			self::assertStringNotContainsString( self::VALUE, $e->getMessage() );
		}
		$this->expectException( \LogicException::class );
		json_encode( [ 'password' => $secret ], JSON_THROW_ON_ERROR );
	}

	public function test_constructor_argument_is_hidden_from_stack_traces(): void {
		$parameters = ( new \ReflectionMethod( Secret::class, '__construct' ) )->getParameters();
		self::assertNotSame( [], $parameters[0]->getAttributes( \SensitiveParameter::class ) );
	}
}
