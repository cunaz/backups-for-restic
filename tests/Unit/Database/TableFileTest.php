<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Restore\TableFile;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Tests\Support\StagedDump;
use BackupsForRestic\Tests\Support\TempDir;

final class TableFileTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'import-file' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_batch_positions_resume_duplicate_rows_without_rereading_the_first_batch(): void {
		$manifest = StagedDump::write( $this->directory );
		$file     = new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
		self::assertSame( 2, $file->batch( $file->data_offset, 0 )?->rows );
		$offset   = $file->position();
		$identity = $file->identity;
		$file->close();
		$resumed = new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
		try {
			self::assertSame( $identity, $resumed->identity );
			$batch = $resumed->batch( $offset, 2 );
			self::assertSame( 2, $batch?->rows );
			self::assertStringStartsWith( 'INSERT INTO `shadow`', $batch?->sql ?? '' );
			self::assertNull( $resumed->batch( $resumed->position(), 4 ) );
			self::assertSame( strlen( StagedDump::table() ), $resumed->position() );
		} finally {
			$resumed->close();
		}
	}

	/** @return iterable<string, array{string,string}> */
	public static function changed_headers(): iterable {
		yield 'foreign DROP' => [ 'DROP TABLE IF EXISTS `wp_rows`;', 'DROP TABLE IF EXISTS `wp_users`;' ];
		yield 'combined commands' => [ 'SET NAMES utf8mb4;', 'SET NAMES utf8mb4; DROP TABLE `wp_users`;' ];
		yield 'escaped strings disabled' => [ "'NO_AUTO_VALUE_ON_ZERO'", "'NO_BACKSLASH_ESCAPES'" ];
		yield 'foreign timezone' => [ "'+00:00'", "'+02:00'" ];
		yield 'wrong version' => [ 'dump/1', 'dump/2' ];
		yield 'missing preamble' => [ "SET UNIQUE_CHECKS = 0;\n", '' ];
		yield 'CRLF conversion' => [ "\n", "\r\n" ];
	}

	#[DataProvider( 'changed_headers' )]
	public function test_only_the_exact_dump_preamble_is_accepted( string $from, string $to ): void {
		$manifest = StagedDump::write( $this->directory, str_replace( $from, $to, StagedDump::table() ) );
		$this->expectException( FormatException::class );
		new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
	}

	/** @return iterable<string, array{string,string}> */
	public static function changed_data(): iterable {
		yield 'wrong columns' => [ '(`a`,`b`) VALUES', '(`b`,`a`) VALUES' ];
		yield 'wrong INSERT destination' => [ 'INSERT INTO `wp_rows`', 'INSERT INTO `wp_users`' ];
		yield 'row count mismatch' => [ 'end table rows=4', 'end table rows=5' ];
		yield 'trailer bytes mismatch' => [ ' bytes=', ' bytes=9' ];
		yield 'data after trailer' => [ ' bytes=', ' bytes=0; DROP TABLE `wp_users`; --' ];
		yield 'missing trailer' => [ '-- end table rows=', 'INSERT INTO `wp_rows` VALUES ' ];
	}

	#[DataProvider( 'changed_data' )]
	public function test_invalid_rows_or_completion_never_pass( string $from, string $to ): void {
		$manifest = StagedDump::write( $this->directory, str_replace( $from, $to, StagedDump::table() ) );
		$file     = new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
		try {
			$this->expectException( FormatException::class );
			$rows = 0;
			for ( $i = 0; $i < 3; ++$i ) {
				$batch = $file->batch( $file->position(), $rows );
				$rows += $batch?->rows ?? 0;
			}
		} finally {
			$file->close();
		}
	}

	public function test_a_symlink_is_refused_even_when_its_target_is_the_right_dump(): void {
		$manifest = StagedDump::write( $this->directory );
		rename( $this->directory . '/010-wp_rows.sql', $this->directory . '/other.sql' );
		symlink( $this->directory . '/other.sql', $this->directory . '/010-wp_rows.sql' );
		$this->expectException( StorageFailed::class );
		new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
	}

	public function test_same_size_replacement_while_reading_is_refused(): void {
		$manifest = StagedDump::write( $this->directory );
		$file     = new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
		try {
			file_put_contents( $this->directory . '/replacement', StagedDump::table() );
			rename( $this->directory . '/replacement', $this->directory . '/010-wp_rows.sql' );
			$this->expectException( StorageFailed::class );
			$file->batch( $file->data_offset, 0 );
		} finally {
			$file->close();
		}
	}

	public function test_the_manifest_cannot_silently_omit_rows(): void {
		$manifest = StagedDump::write( $this->directory, null, 1 );
		$file     = new TableFile( $this->directory, $manifest, 0, 'shadow', 'owner' );
		try {
			$this->expectException( FormatException::class );
			$file->batch( $file->data_offset, 0 );
		} finally {
			$file->close();
		}
	}
}
