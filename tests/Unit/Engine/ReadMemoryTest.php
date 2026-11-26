<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use BackupsForRestic\Format\Compression;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A restore and a check read stored blobs beside the index, in what PHP's memory limit leaves.
 * Each case runs in a PHP process of its own with memory_limit = 128M and opcache off, as the
 * CI memory runs start a tick, and with every response held three times as WordPress holds it
 * (tests/Fixtures/memory/read.php). The memory is filled first to what WordPress and the index
 * of a repository leave of it, measured with the plugin on 2026-11-26: WordPress 7.1.5 had
 * reserved 34 MiB once it was loaded, and loaded from the cache an index of 500,000 blobs took
 * 30.5 MiB more and one of 1,000,000 blobs 60.5 MiB more. An index of 2,000,000 blobs does
 * not fit under 128 MiB at all and is refused with index_too_large (MemoryBudgetTest).
 *
 * Beside 94 MiB, the restore and check of 198645a ended with PHP's fatal error or refused a
 * group it had already downloaded (decompression_limit). Now a group is as large as the
 * memory left can take, and the peak stays more than 6 MiB below the limit.
 */
final class ReadMemoryTest extends TestCase {

	private const int MIB = 1048576;

	/** PHP's memory limit of the measured processes. */
	private const int LIMIT = 128 * self::MIB;

	/** How far below the limit a peak must stay at least: the budget keeps 2 MiB free. */
	private const int MARGIN = 2 * self::MIB;

	/** What WordPress and an index of 500,000 blobs had reserved. */
	private const float BESIDE_500000 = 64.5;

	/** What WordPress and an index of 1,000,000 blobs had reserved. */
	private const float BESIDE_1000000 = 94.0;

	/**
	 * The repositories, built once: their directories, key files and snapshots by file.
	 *
	 * @var array<string, array{directory: string, key: string, files: array<string, array{snapshot: string, sha256: string, size: int}>}>
	 */
	private static array $repositories = [];

	private static string $scratch = '';

	public static function setUpBeforeClass(): void {
		self::$scratch = TempDir::create( 'read-memory' );
	}

	public static function tearDownAfterClass(): void {
		TempDir::remove( self::$scratch );
		self::$repositories = [];
	}

	/**
	 * A repository of the given format with one file of each kind
	 * (tests/Fixtures/memory/repository.php), built once by a process of its own.
	 *
	 * @return array{directory: string, key: string, files: array<string, array{snapshot: string, sha256: string, size: int}>}
	 */
	private static function repository( int $format ): array {
		if ( ! isset( self::$repositories[ (string) $format ] ) ) {
			$process = proc_open(
				[ PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', dirname( __DIR__, 2 ) . '/Fixtures/memory/repository.php', dirname( __DIR__, 3 ), self::$scratch . '/format-' . $format, (string) $format ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			$errors = (string) stream_get_contents( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $output . $errors );
			$repository = json_decode( $output, true );
			self::assertIsArray( $repository, $output . $errors );
			self::$repositories[ (string) $format ] = $repository;
		}
		return self::$repositories[ (string) $format ];
	}

	/**
	 * Runs one job in a process of its own.
	 *
	 * @param array<string, mixed> $request What tests/Fixtures/memory/read.php reads.
	 * @return array{outcome: string, reserved: int|null, peak: int|null, loads: int, sha256: string|null, size: int|null, findings?: list<string>}
	 */
	private static function measure( array $request ): array {
		$directory = TempDir::create( 'read-memory-run' );
		try {
			$file = $directory . '/request.json';
			file_put_contents(
				$file,
				json_encode(
					$request + [
						'root'   => dirname( __DIR__, 3 ),
						'target' => $directory . '/target',
						'work'   => $directory . '/work',
						'after'  => 0,
					],
					JSON_THROW_ON_ERROR
				)
			);
			$process = proc_open(
				[ PHP_BINARY, '-d', 'memory_limit=' . self::LIMIT, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', dirname( __DIR__, 2 ) . '/Fixtures/memory/read.php', $file ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				],
				$pipes
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			$errors = (string) stream_get_contents( $pipes[2] );
			proc_close( $process );
			$result = json_decode( $output, true );
			self::assertIsArray( $result, $output . $errors );
			return $result;
		} finally {
			TempDir::remove( $directory );
		}
	}

	/**
	 * Each file with the number of range requests that reading it took before this change, when
	 * memory was not short: one group per 16 MiB or 128 blobs.
	 *
	 * @return iterable<string, array{int, string, int}>
	 */
	public static function files(): iterable {
		yield 'format 1, full chunks' => [ 1, 'chunks', 2 ];
		yield 'format 1, two chunks of a group' => [ 1, 'pairs', 1 ];
		yield 'format 1, many small blobs' => [ 1, 'small', 2 ];
		yield 'format 2, full chunks that do not compress' => [ 2, 'chunks', 2 ];
		yield 'format 2, full chunks that compress' => [ 2, 'text', 1 ];
		yield 'format 2, many small blobs' => [ 2, 'small', 2 ];
	}

	#[DataProvider( 'files' )]
	public function test_a_restore_beside_wordpress_and_an_index_of_a_million_blobs_reads_what_fits_and_stays_below_128_mib( int $format, string $name, int $groups ): void {
		if ( 2 === $format && ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$repository = self::repository( $format );
		$file       = $repository['files'][ $name ];
		$result     = self::measure(
			[
				'job'        => 'restore',
				'repository' => $repository['directory'],
				'key'        => $repository['key'],
				'snapshot'   => $file['snapshot'],
				'before'     => self::BESIDE_1000000,
			]
		);
		self::assertSame( 'complete', $result['outcome'], json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertSame( $file['sha256'], $result['sha256'], 'Staging holds exactly the file of the snapshot.' );
		self::assertGreaterThanOrEqual( 94 * self::MIB, $result['reserved'], 'The memory was filled as far as intended.' );
		self::assertLessThanOrEqual( self::LIMIT - self::MARGIN, $result['peak'], sprintf( 'The peak of %.2f MiB stays 2 MiB below the limit.', $result['peak'] / self::MIB ) );
		self::assertGreaterThanOrEqual( $groups, $result['loads'] );
	}

	#[DataProvider( 'files' )]
	public function test_a_restore_that_has_memory_enough_sends_the_requests_it_sent_before( int $format, string $name, int $groups ): void {
		if ( 2 === $format && ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$repository = self::repository( $format );
		$file       = $repository['files'][ $name ];
		$result     = self::measure(
			[
				'job'        => 'restore',
				'repository' => $repository['directory'],
				'key'        => $repository['key'],
				'snapshot'   => $file['snapshot'],
				'before'     => self::BESIDE_500000,
			]
		);
		self::assertSame( 'complete', $result['outcome'], json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertSame( $file['sha256'], $result['sha256'] );
		self::assertSame( $groups, $result['loads'], 'Beside WordPress and 500,000 blobs every group is as large as before.' );
		self::assertLessThanOrEqual( self::LIMIT - self::MARGIN, $result['peak'] );
	}

	public function test_a_two_chunk_group_is_read_one_chunk_at_a_time_when_the_pair_does_not_fit(): void {
		$repository = self::repository( 1 );
		$file       = $repository['files']['pairs'];
		$result     = self::measure(
			[
				'job'        => 'restore',
				'repository' => $repository['directory'],
				'key'        => $repository['key'],
				'snapshot'   => $file['snapshot'],
				'before'     => self::BESIDE_1000000,
			]
		);
		// Reading the pair takes three times its 16 MiB while the response arrives.
		self::assertSame( 2, $result['loads'], json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertSame( $file['sha256'], $result['sha256'] );
	}

	/**
	 * The report of #52: 104.5 MiB reserved before a full chunk is read. One chunk takes three
	 * times its size while it arrives and while it is decrypted, 24 MiB, and 21.5 MiB are left
	 * beside the 2 MiB that the budget keeps free.
	 */
	public function test_a_chunk_that_does_not_fit_beside_the_memory_in_use_is_refused_before_it_is_downloaded(): void {
		$repository = self::repository( 1 );
		$file       = $repository['files']['chunks'];
		$result     = self::measure(
			[
				'job'        => 'restore',
				'repository' => $repository['directory'],
				'key'        => $repository['key'],
				'snapshot'   => $file['snapshot'],
				'before'     => self::BESIDE_1000000 - 2,
				'after'      => 104.5,
			]
		);
		self::assertSame( 'restore_blob_memory', $result['outcome'], json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertSame( 0, $result['loads'], 'Nothing was downloaded.' );
		self::assertSame( 0, $result['size'], 'Nothing was written to the file.' );
		self::assertLessThanOrEqual( self::LIMIT - self::MARGIN, $result['peak'] );
	}

	/**
	 * The check reads packs in pieces of 8 MiB that never split a blob. Beside 94 MiB the check
	 * of 198645a ended with PHP's fatal error on a pack of full chunks in format 2: it held the
	 * piece beside the decrypted frame and twice the chunk, and the piece before beside the next
	 * response.
	 */
	public function test_a_check_beside_wordpress_and_an_index_of_a_million_blobs_verifies_full_chunks_below_128_mib(): void {
		if ( ! Compression::available() ) {
			self::markTestSkipped( 'Needs the zstd extension.' );
		}
		$repository = self::repository( 2 );
		$result     = self::measure(
			[
				'job'        => 'check',
				'repository' => $repository['directory'],
				'key'        => $repository['key'],
				'before'     => self::BESIDE_1000000,
			]
		);
		self::assertSame( 'complete', $result['outcome'], json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertSame( [], $result['findings'] ?? null, 'Every blob was verified, and none is reported as damaged.' );
		self::assertLessThanOrEqual( self::LIMIT - self::MARGIN, $result['peak'], sprintf( 'The peak of %.2f MiB stays 2 MiB below the limit.', $result['peak'] / self::MIB ) );
	}
}
