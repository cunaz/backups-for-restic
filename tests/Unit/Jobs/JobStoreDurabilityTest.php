<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/** Job directories and documents are on disk before anything that follows relies on them. */
final class JobStoreDurabilityTest extends TestCase {

	private string $directory;

	/**
	 * Observed steps, with paths relative to jobs/.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	protected function setUp(): void {
		$this->directory = TempDir::create( 'job-durability' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function store(): JobStore {
		return new JobStore(
			$this->directory,
			new Redactor(),
			new SystemRandom(),
			function ( string $operation, string $path ): void {
				$relative = ltrim( substr( $path, strlen( $this->directory ) ), '/' );
				// The temporary name of a document is random.
				$this->steps[] = $operation . ' ' . ( '' === $relative ? '.' : (string) preg_replace( '/\.[0-9a-f]{8}\.tmp\z/', '.tmp', $relative ) );
			}
		);
	}

	public function test_a_new_job_directory_is_on_disk_in_jobs_before_its_first_document(): void {
		$job = $this->store()->create( JobType::Backup, 'manual', 1767225600 );
		self::assertSame(
			[
				'mkdir ' . $job->id,
				'sync .',
				'sync ' . $job->id . '/job.json.tmp',
				'rename ' . $job->id . '/job.json',
				'sync ' . $job->id,
			],
			$this->steps
		);
	}

	public function test_every_save_puts_the_document_on_disk_before_and_after_its_rename(): void {
		$store       = $this->store();
		$job         = $store->create( JobType::Apply, 'manual', 1767225600 );
		$this->steps = [];
		$job->step   = 'applying';
		$store->save( $job );
		self::assertSame( [ 'sync ' . $job->id . '/job.json.tmp', 'rename ' . $job->id . '/job.json', 'sync ' . $job->id ], $this->steps );
		self::assertSame( 'applying', $store->get( $job->id )?->step );
		// A job directory that has to be created again is synchronised in jobs/ as well.
		TempDir::remove( $this->directory . '/' . $job->id );
		$this->steps = [];
		$store->save( $job );
		self::assertSame( [ 'mkdir ' . $job->id, 'sync .', 'sync ' . $job->id . '/job.json.tmp', 'rename ' . $job->id . '/job.json', 'sync ' . $job->id ], $this->steps );
	}
}
