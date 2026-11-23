<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Jobs;

use BackupsForRestic\Jobs\Apply\PlanBuild;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\DiskFilePlan;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The check of a background undo review that a created folder holds only reviewed removals.
 */
final class PlanBuildTest extends TestCase {

	private string $root;
	private DiskFilePlan $plan;

	protected function setUp(): void {
		$this->root = TempDir::create( 'plan-build' );
		mkdir( $this->root . '/plan' );
		mkdir( $this->root . '/live' );
		$this->plan = new DiskFilePlan( $this->root . '/plan' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	public function test_a_folder_that_is_gone_when_its_names_are_read_is_kept_as_changed(): void {
		// Its own decision found it a moment before; then someone removed it.
		$cursor = [];
		self::assertSame( 'changed_since_apply', PlanBuild::children_removable( $this->plan, $this->root . '/live/created', INF, $cursor ) );
		self::assertSame( [], $cursor );
	}

	public function test_a_folder_whose_names_are_all_reviewed_removals_is_removable_and_one_with_another_name_is_kept(): void {
		$folder = $this->root . '/live/created';
		mkdir( $folder );
		touch( $folder . '/reviewed' );
		$this->plan->claim( 'remove', $folder . '/reviewed', 0 );
		$cursor = [];
		self::assertTrue( PlanBuild::children_removable( $this->plan, $folder, INF, $cursor ) );
		touch( $folder . '/uploaded' );
		self::assertSame( 'new_children', PlanBuild::children_removable( $this->plan, $folder, INF, $cursor ) );
	}

	public function test_a_resumed_reading_whose_boundary_is_past_the_folders_end_keeps_the_folder(): void {
		$folder = $this->root . '/live/created';
		mkdir( $folder );
		touch( $folder . '/reviewed' );
		$this->plan->claim( 'remove', $folder . '/reviewed', 0 );
		// The last tick stopped after five names; names left since, and the folder kept its
		// identity, as where a file system keeps coarse times.
		$cursor = [
			'offset'   => 5,
			'identity' => ApplyManifest::identity( $folder ),
			'stable'   => true,
			'last'     => hash( 'sha256', 'gone' ),
		];
		self::assertSame( 'new_children', PlanBuild::children_removable( $this->plan, $folder, INF, $cursor ) );
	}
}
