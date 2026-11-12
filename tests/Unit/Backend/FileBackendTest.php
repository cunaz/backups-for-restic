<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Backend;

use BackupsForRestic\Backend\ListingFile;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Tests\Support\FileBackend;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The disk fixture must preserve the backend's object contracts. */
final class FileBackendTest extends TestCase {

	private string $directory;

	private FileBackend $backend;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'file-backend' );
		$this->backend   = new FileBackend( $this->directory . '/repository' );
		$this->backend->create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/** @return iterable<string, array{ObjectType, string}> */
	public static function repeated_writes(): iterable {
		foreach ( [ ObjectType::Config, ObjectType::Index, ObjectType::Data ] as $type ) {
			yield $type->value . ' with different bytes' => [ $type, 'replacement' ];
			yield $type->value . ' with identical bytes' => [ $type, 'original' ];
		}
	}

	#[DataProvider( 'repeated_writes' )]
	public function test_an_existing_object_cannot_be_replaced( ObjectType $type, string $replacement ): void {
		$name = Id::of( 'original' )->hex();
		$this->backend->save( $type, $name, 'original' );
		try {
			$this->backend->save( $type, $name, $replacement );
			self::fail( 'The existing name must be refused.' );
		} catch ( AlreadyExists ) {
			self::assertSame( 'original', $this->backend->load( $type, $name ) );
		}
	}

	/** @return iterable<string, array{ObjectType}> */
	public static function listed_types(): iterable {
		yield 'flat index directory' => [ ObjectType::Index ];
		yield 'sharded data directory' => [ ObjectType::Data ];
	}

	#[DataProvider( 'listed_types' )]
	public function test_listings_skip_non_object_names( ObjectType $type ): void {
		$name = Id::of( 'original' )->hex();
		$this->backend->save( $type, $name, 'original' );
		$path = $this->backend->directory . '/' . $type->value . ( ObjectType::Data === $type ? '/' . substr( $name, 0, 2 ) : '' );
		foreach ( [ 'README', str_repeat( 'A', 64 ), str_repeat( 'a', 63 ), str_repeat( 'g', 64 ) ] as $stray ) {
			file_put_contents( $path . '/' . $stray, 'stray' );
		}

		self::assertSame( [ $name => 8 ], $this->backend->list( $type ) );
		$file = $this->directory . '/listing.json';
		$this->backend->list_to_file( $type, $file );
		$listing = ListingFile::batch( $file, 0 );
		self::assertSame( [ [ $name, 8 ] ], $listing['entries'] );
		self::assertTrue( $listing['done'] );
	}
}
