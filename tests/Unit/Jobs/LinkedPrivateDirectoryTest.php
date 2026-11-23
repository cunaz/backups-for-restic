<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Jobs\Apply\FileChanges;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Runner;
use BackupsForRestic\Jobs\RunnerLock;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\FileMaintenance;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\Staging;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * A private directory that the configuration names through a symbolic link, as
 * BACKUPS_FOR_RESTIC_PRIVATE_DIR=/data/acme/private does where /data is one: the services
 * are built from it as the composition root builds them, and the checks that refuse a path
 * whose real location differs from its spelling must still let the plugin work.
 */
final class LinkedPrivateDirectoryTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = (string) realpath( TempDir::create( 'linked-private' ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	public function test_a_large_file_preview_is_built_read_and_expired_through_the_link(): void {
		mkdir( $this->directory . '/volumes/data', 0700, true );
		if ( ! symlink( $this->directory . '/volumes/data', $this->directory . '/data' ) ) {
			self::markTestSkipped( 'Symbolic links cannot be created here.' );
		}
		$live = $this->directory . '/site';
		mkdir( $live );
		file_put_contents( $live . '/a-existing', 'original' );
		$private = PrivateDirectory::locate( $this->directory . '/data/acme/private', $live, $live );
		$private->ensure();
		$clock   = new FrozenClock();
		$random  = new SystemRandom();
		$jobs    = new JobStore( $private->subdirectory( 'jobs' ), new Redactor(), $random );
		$staging = new Staging( $private->subdirectory( 'restores' ), $jobs, $clock );

		$restore              = $jobs->create( JobType::Restore, 'manual', $clock->seconds - 60 );
		$restore->state       = JobState::Succeeded;
		$restore->finished_at = $clock->seconds;
		// More files than an inline preview takes: a job of its own prepares the preview.
		$restore->result = [ 'files' => 101 ];
		$jobs->save( $restore );
		$files = $private->subdirectory( 'restores' ) . '/' . $restore->id . '/files';
		mkdir( $files . $live . '/b-new', 0700, true );
		file_put_contents( $files . $live . '/a-existing', 'replacement' );
		file_put_contents( $files . $live . '/b-new/new.txt', 'created' );
		// The report of the staged entries, as the restore job leaves it beside the tree.
		$lines = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $files, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$lines[] = json_encode(
				[
					'path' => substr( $item->getPathname(), strlen( $files ) ),
					'type' => $item->isDir() ? 'dir' : 'file',
					'mode' => $item->isDir() ? 0755 : 0644,
				],
				JSON_THROW_ON_ERROR
			);
		}
		file_put_contents( dirname( $files ) . '/entries.jsonl', implode( "\n", $lines ) . "\n" );

		$changes = new FileChanges(
			$jobs,
			$staging,
			$clock,
			24,
			static fn ( bool $config, string $id ): ApplyPlan => new ApplyPlan( [ $live ], [ $live . '/.maintenance', $private->path ], [ $live . '/wp-config.php' ], $config ),
			new FileMaintenance( $live ),
			static fn (): bool => true,
			new FileSpace( $jobs->storage_directory(), static fn ( string $path ): ?int => PHP_INT_MAX )
		);
		$runner  = new Runner( $jobs, new RunnerLock( $private->path . '/runner.lock' ), $changes, $clock, $random );

		$queued = $changes->request_preview( $restore->id, false, false );
		self::assertInstanceOf( Job::class, $queued );
		self::assertSame( JobType::FilePlan, $queued->type );
		for ( $tick = 0; $tick < 50; ++$tick ) {
			$runner->tick( 2.0 );
			$plan = $jobs->get( $queued->id );
			if ( null === $plan || $plan->state->is_terminal() ) {
				break;
			}
		}
		$plan = $jobs->get( $queued->id );
		self::assertNotNull( $plan );
		self::assertSame( JobState::Succeeded, $plan->state, $plan->error->message ?? '' );

		$page = $changes->request_preview( $restore->id, false, false, $queued->id );
		self::assertIsArray( $page );
		$actions = array_column( $page['entries'], 'action', 'path' );
		self::assertSame( 'replace', $actions[ $live . '/a-existing' ] ?? null );
		self::assertSame( 'create', $actions[ $live . '/b-new/new.txt' ] ?? null );
		self::assertSame( 'original', file_get_contents( $live . '/a-existing' ), 'A preview changes nothing.' );

		// An hour after the preview finished, the heartbeat removes its private data.
		$plans = $private->subdirectory( 'restores' ) . '/' . $restore->id . '/plans/' . $queued->id;
		self::assertDirectoryExists( $plans );
		$clock->advance( 3601 );
		self::assertSame( 0, $staging->remove_due( 24, PHP_FLOAT_MAX )['failed'] );
		self::assertDirectoryDoesNotExist( $plans );
		self::assertArrayNotHasKey( 'plan_retained', $jobs->get( $queued->id )?->data ?? [ 'plan_retained' => true ] );
	}
}
