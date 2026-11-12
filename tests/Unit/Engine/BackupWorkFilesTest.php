<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/** Local checkpoint failures must not silently remove files from a backup. */
final class BackupWorkFilesTest extends TestCase {

	private string $directory;
	private string $work;
	private BackupPlan $plan;
	private Repository $repository;
	private Identity $identity;
	private FrozenClock $clock;
	private BackupRun $run;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'backup-work-files' );
		mkdir( $this->directory . '/site', 0700 );
		file_put_contents( $this->directory . '/site/keep.txt', 'This file must not disappear from the backup.' );
		$this->work       = $this->directory . '/run';
		$this->plan       = new BackupPlan( [ $this->directory . '/site' ] );
		$this->identity   = new Identity( 'example.com', 'www-data' );
		$this->clock      = new FrozenClock();
		$this->repository = Repository::initialize( new InMemoryBackend(), 'work files password', $this->identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->run        = BackupRun::start( $this->repository, $this->plan, $this->work, $this->identity, $this->clock );
		self::assertFalse( $this->run->advance( 0 ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/** @return array<mixed> */
	private function state(): array {
		$state = json_decode( (string) file_get_contents( $this->work . '/state.json' ), true );
		self::assertIsArray( $state );
		return $state;
	}

	private function resume(): BackupRun {
		return BackupRun::resume( $this->repository, $this->plan, $this->work, $this->identity, $this->clock );
	}

	/** Pauses just before reading the site's file, or just before completing its directory. */
	private function site_nodes( bool $file_done ): string {
		for ( $tick = 0; $tick < 20; $tick++ ) {
			$state = $this->state();
			$frame = $state['stack'][ array_key_last( $state['stack'] ) ];
			if ( $this->directory . '/site' === $frame['path'] && ( $file_done ? 1 : 0 ) === $frame['next'] ) {
				return $this->work . '/nodes-' . $frame['sequence'] . '.jsonl';
			}
			self::assertFalse( $this->run->advance( 0 ) );
		}
		self::fail( 'The backup never reached the requested boundary.' );
	}

	/** Suppress native warnings while requiring the structured storage error. */
	private function storage_failure( \Closure $operation ): void {
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$operation();
			self::fail( 'A failed local file operation must stop the backup.' );
		} catch ( StorageFailed $error ) {
			self::assertSame( 'environment', JobError::from_throwable( $error )->code );
		} finally {
			restore_error_handler();
		}
	}

	private function require_permissions(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root ignores the permissions used to simulate a refused operation.' );
		}
	}

	public function test_a_frame_is_not_recorded_when_its_node_file_cannot_be_created(): void {
		$before  = file_get_contents( $this->work . '/state.json' );
		$blocked = $this->work . '/nodes-' . ( $this->state()['sequence'] + 1 ) . '.jsonl';
		mkdir( $blocked );
		try {
			$this->storage_failure( fn () => $this->run->advance( 0 ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
			self::assertSame( [], $this->repository->backend()->list( ObjectType::Snapshots ) );
		} finally {
			rmdir( $blocked );
			$this->run->abort();
		}
	}

	public function test_resume_refuses_when_uncommitted_node_bytes_cannot_be_removed(): void {
		$this->require_permissions();
		$node   = $this->work . '/nodes-1.jsonl';
		$before = file_get_contents( $this->work . '/state.json' );
		file_put_contents( $node, 'uncommitted tail', FILE_APPEND );
		chmod( $node, 0400 );
		try {
			$this->storage_failure( fn () => $this->resume() );
			self::assertSame( 'uncommitted tail', file_get_contents( $node ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
		} finally {
			chmod( $node, 0600 );
		}
		$resumed = $this->resume();
		clearstatcache( true, $node );
		self::assertSame( 0, filesize( $node ), 'Once writable, the uncommitted bytes are removed before resuming.' );
		$resumed->abort();
	}

	public function test_a_node_append_failure_keeps_the_previous_checkpoint(): void {
		$this->require_permissions();
		$node   = $this->site_nodes( false );
		$before = file_get_contents( $this->work . '/state.json' );
		chmod( $node, 0400 );
		try {
			$this->storage_failure( fn () => $this->resume()->advance( INF ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
			self::assertSame( [], $this->repository->backend()->list( ObjectType::Snapshots ) );
		} finally {
			chmod( $node, 0600 );
			$this->run->abort();
		}
	}

	public function test_an_unreadable_node_file_cannot_become_an_empty_directory_in_a_snapshot(): void {
		$this->require_permissions();
		$node   = $this->site_nodes( true );
		$before = file_get_contents( $this->work . '/state.json' );
		chmod( $node, 0000 );
		try {
			$this->storage_failure( fn () => $this->resume()->advance( INF ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
			self::assertSame( [], $this->repository->backend()->list( ObjectType::Snapshots ) );
		} finally {
			chmod( $node, 0600 );
			$this->run->abort();
		}
	}

	public function test_a_refused_checkpoint_write_preserves_the_previous_state(): void {
		$before  = file_get_contents( $this->work . '/state.json' );
		$blocked = $this->work . '/state.json.tmp';
		mkdir( $blocked );
		try {
			$this->storage_failure( fn () => $this->run->advance( 0 ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
		} finally {
			rmdir( $blocked );
			$this->run->abort();
		}
	}

	#[RunInSeparateProcess]
	public function test_a_partial_write_cannot_replace_the_previous_checkpoint(): void {
		if ( ! function_exists( 'posix_setrlimit' ) || ! function_exists( 'pcntl_signal_get_handler' ) || ! defined( 'POSIX_RLIMIT_FSIZE' ) ) {
			self::markTestSkipped( 'This failure injection needs POSIX file-size limits and signals.' );
		}
		$limits  = posix_getrlimit();
		$soft    = is_int( $limits['soft filesize'] ) ? $limits['soft filesize'] : POSIX_RLIMIT_INFINITY;
		$hard    = is_int( $limits['hard filesize'] ) ? $limits['hard filesize'] : POSIX_RLIMIT_INFINITY;
		$handler = pcntl_signal_get_handler( SIGXFSZ );
		$before  = file_get_contents( $this->work . '/state.json' );
		pcntl_signal( SIGXFSZ, SIG_IGN );
		self::assertTrue( posix_setrlimit( POSIX_RLIMIT_FSIZE, 128, $hard ) );
		try {
			$this->storage_failure( fn () => $this->run->advance( 0 ) );
			self::assertSame( $before, file_get_contents( $this->work . '/state.json' ) );
			self::assertSame( 128, filesize( $this->work . '/state.json.tmp' ), 'The kernel accepted only a prefix of the new checkpoint.' );
		} finally {
			posix_setrlimit( POSIX_RLIMIT_FSIZE, $soft, $hard );
			pcntl_signal( SIGXFSZ, $handler );
			$this->run->abort();
		}
	}

	public function test_failed_cleanup_is_reported_after_releasing_the_lock(): void {
		$this->require_permissions();
		chmod( $this->work, 0500 );
		try {
			$this->storage_failure( fn () => $this->run->abort() );
			self::assertSame( [], $this->repository->backend()->list( ObjectType::Locks ) );
			self::assertFileExists( $this->work . '/state.json' );
		} finally {
			chmod( $this->work, 0700 );
			$this->run->abort();
		}
	}

	public function test_checkpoint_and_node_files_are_owner_only(): void {
		$node = $this->site_nodes( true );
		foreach ( [ $node, $this->work . '/state.json' ] as $file ) {
			clearstatcache( true, $file );
			self::assertSame( 0600, fileperms( $file ) & 0777 );
		}
		$this->run->abort();
	}
}
