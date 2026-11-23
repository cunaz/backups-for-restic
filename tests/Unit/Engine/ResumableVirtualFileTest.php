<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Backup\VirtualFile;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Support\ResumableByteSource;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\FixedRandom;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * A resumable virtual file across slices that each end after the first chunk: it continues
 * after its cursor, or starts over when its source says it cannot continue there.
 */
final class ResumableVirtualFileTest extends TestCase {

	private string $site;

	/** @var list<string> */
	private array $cleanup = [];

	/** @var list<string> Units of the first version of the content, each larger than a chunk. */
	private array $first = [];

	/** @var list<string> Units of the second version. */
	private array $second = [];

	/** @var list<?string> Cursors the sources were opened with. */
	private array $opened = [];

	protected function setUp(): void {
		$this->site      = TempDir::create( 'site' );
		$this->cleanup[] = $this->site;
		mkdir( $this->site . '/wp-content' );
		// The same content and, with the repository's fixed randomness, the same chunker
		// polynomial in every run: the chunks end at the same places, and the first slice
		// ends inside the file. Random content could hold no chunk boundary for 8 MiB.
		$first  = new FixedRandom( 'first version' );
		$second = new FixedRandom( 'second version' );
		for ( $unit = 0; $unit < 3; ++$unit ) {
			$this->first[]  = $first->bytes( 3145728 );
			$this->second[] = $second->bytes( 3145728 );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			TempDir::remove( $path );
		}
	}

	public function test_a_source_that_continues_after_its_cursor_keeps_the_earlier_chunks(): void {
		[ $content, $warnings ] = $this->back_up_and_restore( false );

		self::assertSame( self::digest( $this->first ), self::digest( [ $content ] ) );
		self::assertNotSame( [], array_filter( $this->opened, static fn ( ?string $cursor ): bool => null !== $cursor ), 'The file was resumed at least once.' );
		self::assertSame( [ 'table complete' ], $warnings );
	}

	public function test_a_source_that_restarts_replaces_the_chunks_of_its_earlier_slices(): void {
		[ $content, $warnings ] = $this->back_up_and_restore( true );

		self::assertNotSame( [], array_filter( $this->opened, static fn ( ?string $cursor ): bool => str_starts_with( (string) $cursor, 'first:' ) ), 'The first version was resumed before it was given up.' );
		self::assertSame( self::digest( $this->second ), self::digest( [ $content ] ), 'Nothing of the abandoned first version is left in the file.' );
		self::assertSame( [ 'table complete' ], $warnings, 'Only the complete source reports, and only once.' );
	}

	/**
	 * Length and hash of content, so that a failure does not print megabytes.
	 *
	 * @param list<string> $parts Content in pieces.
	 */
	private static function digest( array $parts ): string {
		$content = implode( '', $parts );
		return strlen( $content ) . ' bytes, SHA-256 ' . hash( 'sha256', $content );
	}

	/**
	 * Backs up the virtual file with a deadline in the past, so that every slice stops after
	 * its first chunk, then restores it.
	 *
	 * @return array{string, list<string>} Restored content and the run's warnings.
	 */
	private function back_up_and_restore( bool $restart ): array {
		$plan       = new BackupPlan(
			[ $this->site ],
			[ 'backups-for-restic' ],
			null,
			fn ( string $directory ): array => $directory === $this->site . '/wp-content'
				? [ new VirtualFile( 'table.sql', fn ( ?string $cursor ): ResumableByteSource => $this->source( $cursor, $restart ), 1767323045 ) ]
				: []
		);
		$repository = Repository::initialize( new InMemoryBackend(), 'test password', new Identity( 'example.com', 'www-data' ), new FixedRandom( 'repository' ), new ScryptParameters( 1024, 8, 1 ) );
		$work       = TempDir::create( 'work' );
		$target     = TempDir::create( 'target' );
		array_push( $this->cleanup, $work, $target );

		$run    = BackupRun::start( $repository, $plan, $work, new Identity( 'example.com', 'www-data' ), new SystemClock(), null, 1048576 );
		$slices = 0;
		while ( ! $run->advance( 0.0 ) ) {
			self::assertLessThan( 1000, ++$slices );
			$run = BackupRun::resume( $repository, $plan, $work, new Identity( 'example.com', 'www-data' ), new SystemClock(), 1048576 );
		}
		$snapshot = $run->snapshot_id();
		self::assertInstanceOf( Id::class, $snapshot );

		$restore_work    = TempDir::create( 'restore-work' );
		$this->cleanup[] = $restore_work;
		$restore         = RestoreRun::start( $repository, $snapshot, $target, $restore_work, '/' );
		while ( ! $restore->advance( INF ) ) {
			continue;
		}
		return [ (string) file_get_contents( $target . $this->site . '/wp-content/table.sql' ), $run->warnings() ];
	}

	/**
	 * Opens the content at a cursor. With $restart, a source opened at a cursor of the first
	 * version gives it up and produces the second version from its beginning.
	 */
	private function source( ?string $cursor, bool $restart ): ResumableByteSource {
		$this->opened[]  = $cursor;
		$gives_up        = $restart && null !== $cursor && str_starts_with( $cursor, 'first:' );
		[ $name, $next ] = explode( ':', $gives_up ? 'second:0' : ( $cursor ?? 'first:0' ) );
		return new class( 'first' === $name ? $this->first : $this->second, (int) $next, $name, $gives_up ) implements ResumableByteSource {

			private string $buffer = '';

			private bool $stopping = false;

			private bool $stopped = false;

			/** @param list<string> $units */
			public function __construct( private readonly array $units, private int $next, private readonly string $name, private readonly bool $restarted ) {
			}

			public function read( int $length ): string {
				$units = count( $this->units );
				while ( ! $this->stopped && $this->next < $units ) {
					if ( strlen( $this->buffer ) >= $length ) {
						break;
					}
					if ( $this->stopping ) {
						$this->stopped = true;
						break;
					}
					$this->buffer .= $this->units[ $this->next++ ];
				}
				$block        = substr( $this->buffer, 0, $length );
				$this->buffer = substr( $this->buffer, strlen( $block ) );
				return $block;
			}

			public function stop_at_boundary(): void {
				$this->stopping = true;
			}

			public function cursor(): ?string {
				return $this->stopped ? $this->name . ':' . $this->next : null;
			}

			public function restarted(): bool {
				return $this->restarted;
			}

			public function warnings(): array {
				return $this->stopped ? [] : [ 'table complete' ];
			}
		};
	}
}
