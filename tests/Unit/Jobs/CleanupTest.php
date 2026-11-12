<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\Cleanup;

final class CleanupTest extends TestCase {

	public function test_later_failures_do_not_skip_actions_or_replace_the_first_error(): void {
		$first    = new StorageFailed( 'The first removal failed.' );
		$finished = false;
		try {
			Cleanup::run(
				static fn () => throw $first,
				static fn () => throw new \Error( 'Another cleanup action failed.' ),
				static function () use ( &$finished ): void {
					$finished = true;
				}
			);
			self::fail( 'Cleanup failures must reach the job runner.' );
		} catch ( StorageFailed $error ) {
			self::assertSame( $first, $error );
			self::assertTrue( $finished );
		}
	}
}
