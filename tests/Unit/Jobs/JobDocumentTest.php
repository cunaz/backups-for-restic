<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The layout number of job.json (docs/02-architecture.md §18).
 */
final class JobDocumentTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = TempDir::create( 'job-document' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function store(): JobStore {
		return new JobStore( $this->directory, new Redactor(), new SystemRandom() );
	}

	/**
	 * Rewrites a job's document on disk.
	 *
	 * @param \Closure(array<mixed>): array<mixed> $change
	 */
	private function rewrite( Job $job, \Closure $change ): void {
		$file     = $this->directory . '/' . $job->id . '/job.json';
		$document = json_decode( (string) file_get_contents( $file ), true );
		self::assertIsArray( $document );
		file_put_contents( $file, json_encode( $change( $document ) ) );
	}

	public function test_a_saved_document_carries_the_layout_number_first(): void {
		$job      = $this->store()->create( JobType::Backup, 'manual', 1767225600 );
		$document = json_decode( (string) file_get_contents( $this->directory . '/' . $job->id . '/job.json' ), true );
		self::assertIsArray( $document );
		self::assertSame( 'schema', array_key_first( $document ) );
		self::assertSame( Job::SCHEMA, $document['schema'] );
		self::assertSame( Job::SCHEMA, $job->to_array()['schema'] );
	}

	public function test_a_document_from_before_the_number_existed_has_the_first_layout(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Check, 'cli', 1767225600, [ 'read_data_subset_percent' => 7 ] );
		$this->rewrite(
			$job,
			static function ( array $document ): array {
				unset( $document['schema'] );
				return $document;
			}
		);

		$loaded = $store->get( $job->id );

		self::assertNotNull( $loaded );
		self::assertSame( [ 'read_data_subset_percent' => 7 ], $loaded->data );
		self::assertSame( $job->id, $store->active()?->id );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function unreadable_numbers(): iterable {
		yield 'the next layout' => [ Job::SCHEMA + 1 ];
		yield 'zero' => [ 0 ];
		yield 'a negative number' => [ -1 ];
		yield 'a numeral' => [ (string) Job::SCHEMA ];
		yield 'a fraction' => [ 1.5 ];
		yield 'null' => [ null ];
		yield 'a list' => [ [ Job::SCHEMA ] ];
	}

	#[DataProvider( 'unreadable_numbers' )]
	public function test_a_document_with_another_layout_number_is_not_loaded( mixed $schema ): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', 1767225600 );
		$this->rewrite( $job, static fn ( array $document ): array => [ 'schema' => $schema ] + $document );

		self::assertNull( $store->get( $job->id ), 'A layout this version does not know is never interpreted.' );
		self::assertNull( $store->active() );
		self::assertSame( [], $store->latest( 10 ) );
		$this->assert_blocks_changes( $store, $job );
	}

	private function assert_blocks_changes( JobStore $store, Job $unreadable ): void {
		try {
			$store->create( JobType::Backup, 'manual', $unreadable->created_at + 1 );
			self::fail( 'An unreadable job must not allow a second one.' );
		} catch ( JobConflict $conflict ) {
			self::assertSame( $unreadable->id, $conflict->context()['job'] ?? null );
			self::assertSame( 'job_unreadable', JobError::from_throwable( $conflict )->code );
		}
		$changed = false;
		try {
			$store->while_idle(
				static function () use ( &$changed ): void {
					$changed = true;
				}
			);
			self::fail( 'An unreadable job cannot prove that the store is idle.' );
		} catch ( JobConflict $conflict ) {
			self::assertSame( $unreadable->id, $conflict->context()['job'] ?? null );
		}
		self::assertFalse( $changed );
	}

	public function test_a_newer_document_is_kept_even_if_its_raw_state_looks_terminal(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', 1767225600 );
		$this->rewrite(
			$job,
			static fn ( array $document ): array => [
				'schema' => Job::SCHEMA + 1,
				'state'  => 'succeeded',
			] + $document
		);
		$this->assert_blocks_changes( $store, $job );
		$before       = file_get_contents( $this->directory . '/' . $job->id . '/job.json' );
		$newer        = new Job( '20260102T000000Z-abcdef', JobType::Backup, 'manual', 1767312000 );
		$newer->state = JobState::Succeeded;
		$store->save( $newer );

		self::assertSame( 0, $store->prune( 1 ) );
		self::assertSame( $before, file_get_contents( $this->directory . '/' . $job->id . '/job.json' ) );
		$this->assert_blocks_changes( $store, $job );
	}

	public function test_a_damaged_document_also_cannot_prove_that_the_store_is_idle(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', 1767225600 );
		file_put_contents( $this->directory . '/' . $job->id . '/job.json', '{"state":' );
		$this->assert_blocks_changes( $store, $job );
	}

	public function test_a_directory_without_a_document_does_not_block_a_new_job(): void {
		$store = $this->store();
		$job   = $store->create( JobType::Backup, 'manual', 1767225600 );
		unlink( $this->directory . '/' . $job->id . '/job.json' );
		$next = $store->while_idle( fn (): Job => $store->create( JobType::Check, 'manual', 1767225601 ) );
		self::assertSame( $next->id, $store->active()?->id );
	}

	public function test_the_refusal_names_the_layout(): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'layout' );
		Job::from_array(
			[
				'schema' => Job::SCHEMA + 1,
				'id'     => '20260101T000000Z-abcdef',
				'type'   => 'backup',
				'state'  => 'queued',
			]
		);
	}
}
