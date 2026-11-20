<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Storage\Zip;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\Zip\ZipRecords;
use BackupsForRestic\Storage\Zip\ZipWriter;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Archives are read back by programs that share no code with the writer: Info-ZIP's unzip
 * where it is installed, PHP's ZipArchive where the extension is loaded, and always a small
 * reader of the central directory in this class.
 */
final class ZipWriterTest extends TestCase {

	private const int CHUNK = 4096;

	private string $base;

	private string $tree;

	protected function setUp(): void {
		$this->base = TempDir::create( 'zip' );
		$this->tree = $this->base . '/tree';
		mkdir( $this->tree, 0700 );
		mkdir( $this->base . '/out', 0700 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
	}

	public function test_a_tree_becomes_an_archive_that_independent_readers_accept(): void {
		$this->fixture();
		$archive = $this->base . '/out/files.zip';

		self::assertTrue( $this->writer( $archive )->advance( microtime( true ) + 60 ) );

		$this->assert_archive_holds_the_tree( $archive );
		self::assertSame(
			[
				'a/',
				'a/b/',
				'a/b/empty/',
				'a/b/large.bin',
				'a/zero.txt',
				'name with spaces.txt',
				'top.txt',
				'ümlaut ördner/',
				'ümlaut ördner/日本語 file.txt',
			],
			array_column( self::read_archive( $archive )['entries'], 'name' ),
			'Directories come before their content, names in byte order.'
		);
	}

	public function test_an_archive_is_private_and_has_its_name_only_when_it_is_complete(): void {
		$this->fixture();
		$archive = $this->base . '/out/files.zip';
		$writer  = $this->writer( $archive );

		self::assertFalse( $writer->is_complete() );
		self::assertFalse( $writer->advance( 0.0 ) );
		self::assertFileDoesNotExist( $archive );
		self::assertFileExists( $archive . '.part' );
		self::assertSame( 0600, fileperms( $archive . '.part' ) & 0777 );
		self::assertSame( 0600, fileperms( $archive . '.state' ) & 0777 );
		self::assertSame( 1, $writer->progress()['entries'] );

		self::assertTrue( $writer->advance( microtime( true ) + 60 ) );
		self::assertTrue( $writer->is_complete() );
		self::assertSame( 0600, fileperms( $archive ) & 0777 );
		self::assertSame( [ 'files.zip' ], array_values( array_diff( (array) scandir( $this->base . '/out' ), [ '.', '..' ] ) ) );
		self::assertTrue( $writer->advance( 0.0 ), 'A complete archive is not written again.' );
	}

	public function test_an_empty_tree_becomes_an_archive_without_entries(): void {
		$archive = $this->base . '/out/empty.zip';

		self::assertTrue( $this->writer( $archive )->advance( microtime( true ) + 60 ) );

		self::assertSame( 22, filesize( $archive ) );
		self::assertSame( [], self::read_archive( $archive )['entries'] );
	}

	public function test_calls_that_each_stop_at_once_write_the_same_archive_as_one_call(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$steps = $this->base . '/out/steps.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );

		$writer = $this->writer( $steps );
		$calls  = 1;
		while ( ! $writer->advance( 0.0 ) ) {
			self::assertLessThan( 200, ++$calls, 'Every call must make progress.' );
		}

		self::assertGreaterThan( 12, $calls, 'A call past its deadline does one piece of work.' );
		self::assertFileEquals( $whole, $steps );
		$this->assert_archive_holds_the_tree( $steps );
	}

	public function test_a_new_process_for_every_call_writes_the_same_archive(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$steps = $this->base . '/out/steps.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );

		$calls = 1;
		while ( ! $this->writer( $steps )->advance( 0.0 ) ) {
			self::assertLessThan( 200, ++$calls );
			// What a killed request wrote after its last checkpoint.
			file_put_contents( $steps . '.part', 'bytes that do not count', FILE_APPEND );
			file_put_contents( $steps . '.entries', 'nor do these', FILE_APPEND );
		}

		self::assertFileEquals( $whole, $steps );
	}

	public function test_a_request_killed_between_two_checkpoints_loses_only_the_work_since_the_first(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$steps = $this->base . '/out/steps.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );

		$kills = 0;
		for ( $done = false; ! $done; ) {
			// Two seconds pass with every look at the clock, so every piece of work is
			// followed by a checkpoint; the third look kills the request after a piece of
			// work that no checkpoint counts.
			$looks = 0;
			$clock = static function () use ( &$looks ): float {
				if ( ++$looks > 2 ) {
					throw new \RuntimeException( 'The request is killed.' );
				}
				return 2.0 * $looks;
			};
			try {
				$done = ( new ZipWriter( $this->tree, $steps, self::CHUNK, null, $clock ) )->advance( PHP_FLOAT_MAX );
			} catch ( \RuntimeException $killed ) {
				self::assertSame( 'The request is killed.', $killed->getMessage() );
				self::assertLessThan( 200, ++$kills );
			}
		}

		self::assertGreaterThan( 12, $kills );
		self::assertFileEquals( $whole, $steps );
	}

	public function test_a_damaged_checkpoint_starts_the_archive_again(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$again = $this->base . '/out/again.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );
		$writer = $this->writer( $again );
		for ( $call = 0; $call < 5; $call++ ) {
			self::assertFalse( $writer->advance( 0.0 ) );
		}

		foreach ( [ '{"version":1', '{"version":2}', '{"version":1,"phase":"entries","offset":10,"central":0,"entries":0,"cursor":"../outside","file":null,"directory":0,"copied":0}' ] as $damage ) {
			file_put_contents( $again . '.state', $damage );
			self::assertSame( 0, $this->writer( $again )->progress()['entries'] );
		}
		self::assertTrue( $this->writer( $again )->advance( microtime( true ) + 60 ) );

		self::assertFileEquals( $whole, $again );
	}

	public function test_a_checkpoint_without_its_working_files_starts_the_archive_again(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$again = $this->base . '/out/again.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );

		$losses = [
			static fn () => unlink( $again . '.part' ),
			static fn () => unlink( $again . '.entries' ),
			static fn () => file_put_contents( $again . '.part', 'short' ),
			static fn () => file_put_contents( $again . '.entries', '' ),
		];
		foreach ( $losses as $lose ) {
			for ( $call = 0; $call < 5; $call++ ) {
				self::assertFalse( $this->writer( $again )->advance( 0.0 ) );
			}
			self::assertGreaterThan( 0, $this->writer( $again )->progress()['entries'] );
			$lose();
			self::assertFalse( $this->writer( $again )->advance( 0.0 ) );
			self::assertSame( 1, $this->writer( $again )->progress()['entries'], 'The work starts with the first entry again.' );
		}
		self::assertTrue( $this->writer( $again )->advance( microtime( true ) + 60 ) );

		self::assertFileEquals( $whole, $again );
	}

	public function test_a_finished_checkpoint_gives_only_an_archive_of_its_size_the_name(): void {
		$this->fixture();
		$whole = $this->base . '/out/whole.zip';
		$again = $this->base . '/out/again.zip';
		self::assertTrue( $this->writer( $whole )->advance( microtime( true ) + 60 ) );
		$finished = '{"version":1,"phase":"complete","offset":' . filesize( $whole ) . ',"central":0,"entries":0,"cursor":"","file":null,"directory":0,"copied":0}';

		// What a crash of the machine leaves: the checkpoint, and an archive without its end.
		file_put_contents( $again . '.part', substr( (string) file_get_contents( $whole ), 0, -30 ) );
		file_put_contents( $again . '.entries', '' );
		file_put_contents( $again . '.state', $finished );
		self::assertFalse( $this->writer( $again )->advance( 0.0 ) );
		self::assertFileDoesNotExist( $again, 'A shorter file is not published.' );

		// A finished checkpoint with no archive at all.
		unlink( $again . '.part' );
		file_put_contents( $again . '.state', $finished );
		self::assertFalse( $this->writer( $again )->advance( 0.0 ) );
		self::assertFileExists( $again . '.part' );

		// A request killed after the rename has only the working files left to remove.
		copy( $whole, $again . '.part' );
		file_put_contents( $again . '.state', $finished );
		self::assertTrue( $this->writer( $again )->advance( 0.0 ) );
		file_put_contents( $again . '.state', $finished );
		self::assertFalse( $this->writer( $again )->is_complete() );
		self::assertTrue( $this->writer( $again )->advance( 0.0 ) );

		self::assertFileEquals( $whole, $again );
		self::assertSame( [ 'again.zip', 'whole.zip' ], array_values( array_diff( (array) scandir( $this->base . '/out' ), [ '.', '..' ] ) ) );
	}

	public function test_discarding_removes_the_checkpoint_before_the_files_it_names(): void {
		$this->fixture();
		$archive = $this->base . '/out/files.zip';
		self::assertFalse( $this->writer( $archive )->advance( 0.0 ) );
		// The removal stops at the working file, as a killed request would.
		unlink( $archive . '.part' );
		mkdir( $archive . '.part', 0700 );

		try {
			@$this->writer( $archive )->discard();
			self::fail( 'A working file that cannot be removed must be reported.' );
		} catch ( StorageFailed ) {
			self::assertFileDoesNotExist( $archive . '.state' );
		}
	}

	public function test_zip64_records_are_written_when_a_limit_is_reached_and_not_before(): void {
		$this->fixture();
		$plain = $this->base . '/out/plain.zip';
		$wide  = $this->base . '/out/wide.zip';

		self::assertTrue( $this->writer( $plain )->advance( microtime( true ) + 60 ) );
		// The limits of the format are lowered, so that a few kilobytes reach them.
		$writer = new ZipWriter( $this->tree, $wide, self::CHUNK, new ZipRecords( 1000, 5 ) );
		while ( ! $writer->advance( 0.0 ) ) {
			self::assertFileDoesNotExist( $wide );
		}

		$read = self::read_archive( $plain );
		self::assertFalse( $read['zip64'] );
		self::assertSame( [], array_filter( array_column( $read['entries'], 'zip64' ) ) );
		$read = self::read_archive( $wide );
		self::assertTrue( $read['zip64'] );
		$fields = array_column( $read['entries'], 'zip64', 'name' );
		self::assertFalse( $fields['a/'], 'The first entry is small and stands at the start.' );
		self::assertTrue( $fields['a/b/large.bin'], 'A file of the lowered limit or more has its sizes in a zip64 field.' );
		self::assertTrue( $fields['top.txt'], 'A small file far into the archive has its offset in a zip64 field.' );
		$this->assert_archive_holds_the_tree( $wide );
	}

	public function test_a_symbolic_link_in_the_tree_is_refused(): void {
		$this->fixture();
		file_put_contents( $this->base . '/secret.txt', 'outside the tree' );
		symlink( $this->base . '/secret.txt', $this->tree . '/a/link.txt' );
		$archive = $this->base . '/out/files.zip';

		try {
			$this->writer( $archive )->advance( microtime( true ) + 60 );
			self::fail( 'A link must end the work.' );
		} catch ( InvalidArgument $refused ) {
			self::assertSame( [ 'name' => 'a%2Flink.txt' ], $refused->context() );
		}
		self::assertFileDoesNotExist( $archive );
		self::assertStringNotContainsString( 'outside the tree', (string) file_get_contents( $archive . '.part' ) );
	}

	public function test_a_link_to_a_directory_and_a_second_name_of_a_file_are_refused(): void {
		mkdir( $this->base . '/elsewhere' );
		symlink( $this->base . '/elsewhere', $this->tree . '/directory' );
		$this->assert_refused( $this->tree );

		unlink( $this->tree . '/directory' );
		file_put_contents( $this->tree . '/one.txt', 'two names' );
		link( $this->tree . '/one.txt', $this->base . '/two.txt' );
		$this->assert_refused( $this->tree );
	}

	public function test_a_tree_that_is_a_link_and_an_archive_inside_the_tree_are_refused(): void {
		$this->fixture();
		symlink( $this->tree, $this->base . '/link' );
		$this->assert_refused( $this->base . '/link' );
		$this->assert_refused( $this->base . '/missing' );
		$this->assert_refused( $this->tree, $this->tree . '/a/inside.zip' );
		$this->assert_refused( $this->tree, $this->tree . '/inside.zip' );
	}

	public function test_a_name_that_is_not_utf8_or_holds_a_backslash_is_refused(): void {
		foreach ( [ "latin1-\xe9.txt", '..\\outside.txt' ] as $number => $name ) {
			$tree = $this->base . '/names-' . $number;
			mkdir( $tree );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some file systems refuse such a name themselves.
			if ( false === @file_put_contents( $tree . '/' . $name, 'content' ) ) {
				self::markTestSkipped( 'The file system does not store this name.' );
			}
			$this->assert_refused( $tree );
		}
	}

	public function test_a_file_that_changes_while_it_is_copied_is_reported(): void {
		file_put_contents( $this->tree . '/growing.bin', Vectors::hash_stream( 'growing', 3 * self::CHUNK ) );
		$archive = $this->base . '/out/files.zip';
		self::assertFalse( $this->writer( $archive )->advance( 0.0 ) );
		self::assertFalse( $this->writer( $archive )->advance( 0.0 ) );

		file_put_contents( $this->tree . '/growing.bin', 'more', FILE_APPEND );

		try {
			$this->writer( $archive )->advance( microtime( true ) + 60 );
			self::fail( 'The archive would hold a file that never existed.' );
		} catch ( StorageFailed $changed ) {
			self::assertSame( [ 'name' => 'growing.bin' ], $changed->context() );
		}
		self::assertFileDoesNotExist( $archive );
	}

	public function test_discarding_removes_the_unfinished_archive_and_keeps_a_complete_one(): void {
		$this->fixture();
		$archive = $this->base . '/out/files.zip';
		$writer  = $this->writer( $archive );
		self::assertFalse( $writer->advance( 0.0 ) );

		$writer->discard();
		self::assertSame( [], array_values( array_diff( (array) scandir( $this->base . '/out' ), [ '.', '..' ] ) ) );

		self::assertTrue( $writer->advance( microtime( true ) + 60 ) );
		$writer->discard();
		self::assertFileExists( $archive );
	}

	private function writer( string $archive ): ZipWriter {
		return new ZipWriter( $this->tree, $archive, self::CHUNK );
	}

	/**
	 * An empty directory, nested directories, an empty file, a file of several chunks, and
	 * names with spaces and with characters beyond ASCII.
	 */
	private function fixture(): void {
		mkdir( $this->tree . '/a/b/empty', 0700, true );
		mkdir( $this->tree . '/ümlaut ördner', 0700 );
		file_put_contents( $this->tree . '/a/zero.txt', '' );
		file_put_contents( $this->tree . '/a/b/large.bin', Vectors::hash_stream( 'large', 5 * self::CHUNK + 123 ) );
		file_put_contents( $this->tree . '/ümlaut ördner/日本語 file.txt', "unicode\n" );
		file_put_contents( $this->tree . '/name with spaces.txt', "spaces\n" );
		file_put_contents( $this->tree . '/top.txt', "top\n" );
		touch( $this->tree . '/top.txt', 1767323045 );
		touch( $this->tree . '/a/b/large.bin', 1500000001 );
	}

	private function assert_refused( string $tree, ?string $archive = null ): void {
		$archive ??= $this->base . '/out/refused.zip';
		try {
			( new ZipWriter( $tree, $archive, self::CHUNK ) )->advance( microtime( true ) + 60 );
			self::fail( 'The archive must be refused.' );
		} catch ( InvalidArgument $refused ) {
			self::assertFileDoesNotExist( $archive );
		}
	}

	/**
	 * Compares the archive with the tree through every independent reader this machine has.
	 */
	private function assert_archive_holds_the_tree( string $archive ): void {
		$expected = self::describe( $this->tree );

		$read  = self::read_archive( $archive );
		$found = [];
		foreach ( $read['entries'] as $entry ) {
			self::assertSame( 0x0800, $entry['flags'], 'Every name is declared as UTF-8.' );
			$found[ rtrim( $entry['name'], '/' ) ] = str_ends_with( $entry['name'], '/' ) ? 'directory' : sprintf( 'file:%d:%08x', $entry['size'], $entry['crc'] );
		}
		self::assertSame( array_map( static fn ( array $entry ): string => $entry['short'], $expected ), $found );

		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $archive, \ZipArchive::RDONLY ) );
			self::assertSame( count( $expected ), $zip->count() );
			foreach ( $expected as $name => $entry ) {
				if ( 'directory' === $entry['short'] ) {
					self::assertNotFalse( $zip->statName( $name . '/' ), $name );
				} else {
					self::assertSame( $entry['hash'], hash( 'sha256', (string) $zip->getFromName( $name ) ), $name );
				}
			}
			$zip->close();
		}

		$tested = self::unzip( [ '-t', $archive ] );
		if ( null === $tested ) {
			return;
		}
		self::assertSame( 0, $tested['status'], $tested['output'] );
		self::assertStringContainsString( 'No errors detected', $tested['output'] );
		$listed = self::unzip( [ '-l', $archive ] );
		self::assertNotNull( $listed );
		self::assertSame( 0, $listed['status'], $listed['output'] );
		self::assertMatchesRegularExpression( '/^\s*' . array_sum( array_column( $expected, 'size' ) ) . '\s+' . count( $expected ) . ' files$/m', $listed['output'] );
		$unpacked = TempDir::create( 'unzipped' );
		try {
			$extracted = self::unzip( [ '-q', $archive, '-d', $unpacked ] );
			self::assertNotNull( $extracted );
			self::assertSame( 0, $extracted['status'], $extracted['output'] );
			self::assertSame( $expected, self::describe( $unpacked ), 'Names, content and the times of files survive unpacking.' );
		} finally {
			TempDir::remove( $unpacked );
		}
	}

	/**
	 * @return array<string, array{short: string, hash: string, size: int, mtime: int}>
	 */
	private static function describe( string $root, string $below = '' ): array {
		$names = array_values( array_diff( (array) scandir( $root . $below ), [ '.', '..' ] ) );
		sort( $names, SORT_STRING );
		$found = [];
		foreach ( $names as $name ) {
			$relative = ltrim( $below . '/' . $name, '/' );
			$path     = $root . '/' . $relative;
			if ( is_dir( $path ) ) {
				$found[ $relative ] = [
					'short' => 'directory',
					'hash'  => '',
					'size'  => 0,
					'mtime' => 0,
				];
				$found             += self::describe( $root, '/' . $relative );
			} else {
				$found[ $relative ] = [
					'short' => sprintf( 'file:%d:%08x', filesize( $path ), crc32( (string) file_get_contents( $path ) ) ),
					'hash'  => (string) hash_file( 'sha256', $path ),
					'size'  => (int) filesize( $path ),
					'mtime' => (int) filemtime( $path ),
				];
			}
		}
		return $found;
	}

	/**
	 * Runs Info-ZIP's unzip, where it is installed.
	 *
	 * @param list<string> $arguments
	 * @return array{status: int, output: string}|null
	 */
	private static function unzip( array $arguments ): ?array {
		$program = null;
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $directory ) {
			if ( null === $program && '' !== $directory && is_executable( $directory . '/unzip' ) ) {
				$program = $directory . '/unzip';
			}
		}
		if ( null === $program ) {
			return null;
		}
		// unzip writes names in the character set of its locale.
		$process = proc_open(
			array_merge( [ $program ], $arguments ),
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes,
			null,
			[ 'LC_ALL' => 'C.UTF-8' ]
		);
		self::assertIsResource( $process );
		$output  = (string) stream_get_contents( $pipes[1] );
		$output .= (string) stream_get_contents( $pipes[2] );
		return [
			'status' => proc_close( $process ),
			'output' => $output,
		];
	}

	/**
	 * Reads an archive from its end, as the format asks: the end record, the zip64 end
	 * records if a locator stands before it, the central directory, and for every entry the
	 * local header and the content, whose CRC-32 is compared.
	 *
	 * @return array{zip64: bool, entries: list<array{name: string, size: int, crc: int, flags: int, zip64: bool}>}
	 */
	private static function read_archive( string $archive ): array {
		$bytes = (string) file_get_contents( $archive );
		$end   = strlen( $bytes ) - 22;
		self::assertSame( "PK\x05\x06", substr( $bytes, $end, 4 ), 'The archive ends with an end record without a comment.' );
		$record = self::fields( 'vdisk/vfirst/vhere/vcount/Vsize/Voffset/vcomment', $bytes, $end + 4 );
		$count  = $record['count'];
		$size   = $record['size'];
		$offset = $record['offset'];
		$zip64  = "PK\x06\x07" === substr( $bytes, max( 0, $end - 20 ), 4 );
		if ( $zip64 ) {
			$locator = self::fields( 'Vdisk/Poffset/Vdisks', $bytes, $end - 16 );
			self::assertSame( "PK\x06\x06", substr( $bytes, $locator['offset'], 4 ) );
			self::assertSame( $end - 20, $locator['offset'] + 56 );
			$wide   = self::fields( 'Plength/vmade/vneeded/Vdisk/Vfirst/Phere/Pcount/Psize/Poffset', $bytes, $locator['offset'] + 4 );
			$count  = $wide['count'];
			$size   = $wide['size'];
			$offset = $wide['offset'];
		} else {
			self::assertLessThan( 0xFFFF, $count );
			self::assertLessThan( 0xFFFFFFFF, max( $size, $offset ) );
		}
		self::assertSame( $zip64 ? $end - 76 : $end, $offset + $size, 'The central directory ends where the end records start.' );

		$entries = [];
		for ( $at = $offset; $at < $offset + $size; ) {
			self::assertSame( "PK\x01\x02", substr( $bytes, $at, 4 ) );
			$head  = self::fields( 'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', $bytes, $at + 4 );
			$name  = substr( $bytes, $at + 46, $head['name'] );
			$extra = substr( $bytes, $at + 46 + $head['name'], $head['extra'] );
			$at   += 46 + $head['name'] + $head['extra'] + $head['comment'];
			$long  = $head['size'];
			$start = $head['offset'];
			$wide  = false;
			for ( $field = 0; $field < $head['extra']; $field += 4 + $part['length'] ) {
				$part = self::fields( 'vid/vlength', $extra, $field );
				if ( 1 === $part['id'] ) {
					$wide   = true;
					$values = array_values( (array) unpack( 'P*', substr( $extra, $field + 4, $part['length'] ) ) );
					if ( 0xFFFFFFFF === $head['size'] ) {
						$long = (int) array_shift( $values );
						self::assertSame( $long, array_shift( $values ) );
					}
					if ( 0xFFFFFFFF === $head['offset'] ) {
						$start = (int) array_shift( $values );
					}
					self::assertSame( [], $values, 'A zip64 field holds only the values that did not fit.' );
				}
			}
			self::assertSame( 0, $head['method'], 'Entries are stored.' );
			self::assertSame( $wide ? 45 : 20, $head['needed'] );
			$local = self::fields( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra', $bytes, $start + 4 );
			self::assertSame( "PK\x03\x04", substr( $bytes, $start, 4 ) );
			self::assertSame( $name, substr( $bytes, $start + 30, $local['name'] ) );
			self::assertSame( $head['crc'], $local['crc'] );
			self::assertSame( $head['crc'], crc32( substr( $bytes, $start + 30 + $local['name'] + $local['extra'], $long ) ), $name );
			$entries[] = [
				'name'  => $name,
				'size'  => $long,
				'crc'   => $head['crc'],
				'flags' => $head['flags'],
				'zip64' => $wide,
			];
		}
		self::assertCount( $count, $entries );
		return [
			'zip64'   => $zip64,
			'entries' => $entries,
		];
	}

	/**
	 * @return array<string, int>
	 */
	private static function fields( string $format, string $bytes, int $offset ): array {
		$fields = unpack( $format, $bytes, $offset );
		self::assertIsArray( $fields );
		$result = [];
		foreach ( $fields as $name => $value ) {
			self::assertIsInt( $value );
			$result[ (string) $name ] = $value;
		}
		return $result;
	}
}
