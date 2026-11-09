<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\ServerProfile;
use BackupsForRestic\Exception\InvalidArgument;

final class ServerProfileTest extends TestCase {

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function accepted(): iterable {
		yield 'BorgBase as shown in the control panel' => [ 'borgbase', 'rest:https://abc123.repo.borgbase.com', 'https://abc123.repo.borgbase.com/' ];
		yield 'BorgBase with upper-case host' => [ 'borgbase', 'https://ABC123.Repo.BorgBase.com/', 'https://abc123.repo.borgbase.com/' ];
		yield 'rest-server with port and path' => [ 'rest-server', ' https://backup.example.net:8000/sites/blog ', 'https://backup.example.net:8000/sites/blog/' ];
		yield 'rest-server on a private address' => [ 'rest-server', 'https://192.168.1.20:8000/', 'https://192.168.1.20:8000/' ];
	}

	#[DataProvider( 'accepted' )]
	public function test_accepted_urls_are_normalized( string $server, string $input, string $expected ): void {
		self::assertSame( $expected, ServerProfile::normalize( $server, $input ) );
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function rejected(): iterable {
		yield 'plain HTTP' => [ 'rest-server', 'http://backup.example.net/', 'not_https' ];
		yield 'credentials in the URL' => [ 'rest-server', 'https://user:pass@backup.example.net/', 'credentials_in_url' ];
		yield 'query string' => [ 'rest-server', 'https://backup.example.net/?x=1', 'query_in_url' ];
		yield 'BorgBase preset with another host' => [ 'borgbase', 'https://evil.example/repo.borgbase.com/', 'not_borgbase' ];
		yield 'BorgBase suffix only' => [ 'borgbase', 'https://.repo.borgbase.com/', 'not_borgbase' ];
		yield 'look-alike host' => [ 'borgbase', 'https://abc.repo.borgbase.com.evil.example/', 'not_borgbase' ];
		yield 'no host' => [ 'rest-server', 'https:///path', 'invalid_url' ];
		yield 'unknown server type' => [ 's3', 'https://example.com/', 'unknown_server' ];
	}

	#[DataProvider( 'rejected' )]
	public function test_rejected_urls_carry_a_code( string $server, string $input, string $code ): void {
		try {
			ServerProfile::normalize( $server, $input );
			self::fail( 'Expected a rejection.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( $code, $e->context()['code'] ?? null );
		}
	}

	public function test_detects_the_server_type_of_a_url(): void {
		self::assertSame( 'borgbase', ServerProfile::detect( 'rest:https://abc.repo.borgbase.com' ) );
		self::assertSame( 'rest-server', ServerProfile::detect( 'https://backup.example.net/' ) );
	}
}
