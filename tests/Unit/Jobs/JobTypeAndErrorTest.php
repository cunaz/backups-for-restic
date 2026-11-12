<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Settings\Settings;

/**
 * Step lists of every job type and the error codes jobs report.
 */
final class JobTypeAndErrorTest extends TestCase {

	/**
	 * @return iterable<string, array{JobType}>
	 */
	public static function types(): iterable {
		foreach ( JobType::cases() as $type ) {
			yield $type->value => [ $type ];
		}
	}

	#[DataProvider( 'types' )]
	public function test_every_type_ends_with_cleanup_and_walks_its_steps_in_order( JobType $type ): void {
		$all = array_merge( $type->steps(), $type->cleanup_steps() );
		self::assertSame( 'cleaning_up', end( $all ) );
		self::assertCount( count( $all ), array_unique( $all ), 'No step appears twice.' );
		$walk = [ $all[0] ];
		$next = $type->next_step( $all[0] );
		while ( null !== $next ) {
			$walk[] = $next;
			$next   = $type->next_step( $next );
		}
		self::assertSame( $all, $walk );
	}

	public function test_maintenance_types_detect_append_only_before_deleting(): void {
		self::assertSame( [ 'preflight', 'unlocking', 'detecting', 'forgetting', 'recording' ], JobType::Forget->steps() );
		self::assertSame( [ 'preflight', 'unlocking', 'detecting', 'pruning', 'recording' ], JobType::Prune->steps() );
		self::assertSame( [ 'preflight', 'unlocking', 'checking', 'recording' ], JobType::Check->steps() );
		self::assertTrue( JobType::Check->is_maintenance() );
		self::assertFalse( JobType::Backup->is_maintenance() );
		self::assertContains( 'releasing', JobType::Prune->cleanup_steps(), 'An aborted prune releases its exclusive lock.' );
	}

	public function test_backups_and_maintenance_wait_for_the_completed_setup(): void {
		$repository = [ 'repository.url' => 'https://backup.example.net/' ];
		$during     = Settings::from_stored( $repository );
		foreach ( [ JobType::Backup, JobType::Forget, JobType::Prune, JobType::Check ] as $type ) {
			self::assertSame( 'setup_incomplete', $type->refusal( $during ), $type->value . ': no job before the recovery kit is acknowledged.' );
		}
		self::assertNull( JobType::Unlock->refusal( $during ), 'The wizard unlocks an existing repository before the setup is complete.' );

		$after = Settings::from_stored( $repository + [ 'setup.completed' => true ] );
		foreach ( JobType::cases() as $type ) {
			self::assertNull( $type->refusal( $after ), $type->value );
		}
	}

	public function test_a_completed_setup_still_needs_a_repository_and_respects_external_retention(): void {
		self::assertSame( 'configuration', JobType::Backup->refusal( Settings::from_stored( [ 'setup.completed' => true ] ) ) );

		$external = Settings::from_stored(
			[
				'repository.url'  => 'https://backup.example.net/',
				'setup.completed' => true,
				'retention.mode'  => 'external',
			]
		);
		self::assertSame( 'retention_external', JobType::Forget->refusal( $external ) );
		self::assertSame( 'retention_external', JobType::Prune->refusal( $external ) );
		self::assertNull( JobType::Check->refusal( $external ), 'The check never deletes.' );
		self::assertNull( JobType::Backup->refusal( $external ) );
	}

	/**
	 * @return iterable<string, array{\Throwable, string}>
	 */
	public static function errors(): iterable {
		yield 'append-only' => [ new AppendOnlyRefused( 'refused' ), 'repository_append_only' ];
		yield 'an index beyond the memory limit' => [ new IndexTooLarge( 'too large' ), 'index_too_large' ];
		yield 'damage' => [ new FormatException( 'damaged', [ 'code' => 'repository_damaged' ] ), 'repository_damaged' ];
		yield 'no snapshots' => [ new InvalidArgument( 'empty', [ 'code' => 'no_snapshots' ] ), 'no_snapshots' ];
		yield 'other context codes do not override' => [ new InvalidArgument( 'bad', [ 'code' => 'something_else' ] ), 'configuration' ];
	}

	#[DataProvider( 'errors' )]
	public function test_error_codes( \Throwable $error, string $code ): void {
		self::assertSame( $code, JobError::from_throwable( $error )->code );
	}
}
