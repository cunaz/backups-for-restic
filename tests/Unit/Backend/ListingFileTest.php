<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Backend;

use BackupsForRestic\Backend\ListingFile;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListingFileTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'listing' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_resumes_at_every_separator_and_keeps_duplicate_order(): void {
		$a       = str_repeat( 'a', 64 );
		$b       = str_repeat( 'b', 64 );
		$entries = [
			$a,
			[
				'name' => 'ignored',
				'size' => 7,
			],
			[
				'name' => $b,
				'size' => 42,
			],
			[
				'name' => $a,
				'size' => 13,
			],
		];
		$file    = $this->directory . '/list';
		file_put_contents( $file, " \n" . json_encode( $entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\t" );
		$offset = 0;
		$actual = [];
		$steps  = 0;
		do {
			$batch = ListingFile::batch( $file, $offset, 1 );
			self::assertGreaterThan( $offset, $batch['offset'] );
			self::assertLessThanOrEqual( 1, count( $batch['entries'] ) );
			$actual = array_merge( $actual, $batch['entries'] );
			$offset = $batch['offset'];
			++$steps;
		} while ( ! $batch['done'] );
		self::assertSame( [ [ $a, -1 ], [ $b, 42 ], [ $a, 13 ] ], $actual );
		self::assertSame( 5, $steps, 'Ignored names consume a work unit too.' );
	}

	public function test_empty_listings_and_null_are_accepted(): void {
		foreach ( [ '[]', 'null', " \n[ ]\t" ] as $json ) {
			file_put_contents( $this->directory . '/list', $json );
			$batch = ListingFile::batch( $this->directory . '/list', 0 );
			self::assertSame( [], $batch['entries'] );
			self::assertTrue( $batch['done'] );
		}
	}

	public function test_escaped_names_and_braces_in_unknown_fields_do_not_confuse_boundaries(): void {
		$name = str_repeat( 'a', 64 );
		$json = json_encode(
			[
				[
					'name'  => $name,
					'size'  => PHP_INT_MAX,
					'extra' => [
						'text'  => '},[\\"',
						'array' => [ 1, 2 ],
					],
				],
			],
			JSON_THROW_ON_ERROR
		);
		$json = str_replace( $name, str_repeat( '\\u0061', 64 ), $json );
		file_put_contents( $this->directory . '/list', $json );
		self::assertSame( [ [ $name, PHP_INT_MAX ] ], ListingFile::batch( $this->directory . '/list', 0 )['entries'] );
	}

	#[DataProvider( 'invalid_listings' )]
	public function test_refuses_invalid_listings_even_across_batches( string $json ): void {
		file_put_contents( $this->directory . '/list', $json );
		$this->expectException( BackendException::class );
		$offset = 0;
		do {
			$batch  = ListingFile::batch( $this->directory . '/list', $offset, 1 );
			$offset = $batch['offset'];
		} while ( ! $batch['done'] );
	}

	public static function invalid_listings(): array {
		$name = '"' . str_repeat( 'a', 64 ) . '"';
		return array_map(
			static fn ( string $json ): array => [ $json ],
			[
				'',
				'{"a":1}',
				'n',
				'nul',
				'null null',
				'[',
				'[,]',
				'[' . $name . ',]',
				'[' . $name,
				'[' . $name . ' ' . $name . ']',
				'[' . $name . ',false]',
				'[' . $name . ',null]',
				'[' . $name . ']x',
				'[' . $name . ',{"name":' . $name . ',"size":-1}]',
				'[{"name":' . $name . ',"size":1.5}]',
				'["' . str_repeat( 'a', 65537 ) . '"]',
				'[' . str_repeat( ' ', 65537 ) . ']',
				'[' . $name . ',{"name":' . $name . ',"size":1',
			]
		);
	}
}
