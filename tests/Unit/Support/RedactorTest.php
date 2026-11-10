<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Support\Redactor;

/**
 * Known secrets leave no trace in logs and job files, in any form that HTTP or a log gives them.
 */
final class RedactorTest extends TestCase {

	private const string PASSWORD = 'p@ss w/rd+1&more';

	private function redactor(): Redactor {
		$redactor = new Redactor();
		$redactor->add( new Secret( self::PASSWORD ) );
		return $redactor;
	}

	public function test_a_secret_is_removed_wherever_it_appears(): void {
		$text = 'The server said: wrong password ' . self::PASSWORD . ' (twice: ' . self::PASSWORD . ').';
		self::assertSame( 'The server said: wrong password [redacted] (twice: [redacted]).', $this->redactor()->redact( $text ) );
		self::assertSame( 'Nothing to hide here.', $this->redactor()->redact( 'Nothing to hide here.' ) );
	}

	public function test_a_secret_is_removed_as_a_url_writes_it(): void {
		$encoded = rawurlencode( self::PASSWORD );
		self::assertNotSame( self::PASSWORD, $encoded, 'The password has characters that a URL encodes.' );
		self::assertSame( 'GET https://backup:[redacted]@backup.example.net/ failed', $this->redactor()->redact( 'GET https://backup:' . $encoded . '@backup.example.net/ failed' ) );
	}

	public function test_a_secret_is_removed_in_base64(): void {
		self::assertSame( '{"password":"[redacted]"}', $this->redactor()->redact( '{"password":"' . base64_encode( self::PASSWORD ) . '"}' ) );
	}

	public function test_basic_authentication_is_removed_as_the_header_carries_it(): void {
		$header = 'Authorization: Basic ' . base64_encode( 'backup:' . self::PASSWORD );
		self::assertSame( $header, $this->redactor()->redact( $header ), 'The base64 of "user:password" contains neither the password nor its base64.' );

		$redactor = $this->redactor();
		$redactor->add_credentials( 'backup', new Secret( self::PASSWORD ) );
		self::assertSame( 'Authorization: Basic [redacted]', $redactor->redact( $header ) );
		self::assertSame( 'curl -u backup:[redacted] https://backup.example.net/', $redactor->redact( 'curl -u backup:' . self::PASSWORD . ' https://backup.example.net/' ) );
	}

	public function test_several_secrets_are_all_removed(): void {
		$redactor = $this->redactor();
		$redactor->add( new Secret( 'repository password' ) );
		self::assertSame( '[redacted] and [redacted]', $redactor->redact( 'repository password and ' . self::PASSWORD ) );
	}

	public function test_values_shorter_than_four_bytes_are_ignored(): void {
		$redactor = new Redactor();
		$redactor->add( new Secret( '' ) );
		$redactor->add( new Secret( 'abc' ) );
		$redactor->add_credentials( 'a', new Secret( 'b' ) );
		$text = 'abc is part of abcdef, a:b of a:bc, and an empty secret is part of everything.';
		self::assertSame( $text, $redactor->redact( $text ), 'Replacing them would destroy ordinary text and protect nothing.' );

		$redactor->add( new Secret( 'abcd' ) );
		self::assertSame( 'abc is part of [redacted]ef, a:b of a:bc, and an empty secret is part of everything.', $redactor->redact( $text ), 'Four bytes are a secret.' );
	}
}
