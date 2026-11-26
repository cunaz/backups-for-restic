<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Jobs\JobError;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Restore\DirectorySpellings;
use BackupsForRestic\Restore\FileWorkPending;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\WatchedFolder;
use PHPUnit\Framework\TestCase;

final class DirectorySpellingsTest extends TestCase {

	private string $root;
	private string $folder;
	private string $workspace;

	protected function setUp(): void {
		$this->root      = TempDir::create( 'directory-spellings' );
		$this->folder    = $this->root . '/names';
		$this->workspace = $this->root . '/private';
		mkdir( $this->folder, 0700 );
		mkdir( $this->workspace, 0700 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	private function stable(): void {
		clearstatcache( true, $this->folder );
		while ( time() <= filectime( $this->folder ) ) {
			usleep( 10000 );
		}
	}

	public function test_a_partially_indexed_directory_resumes_with_exact_and_folded_collisions(): void {
		for ( $i = 0; $i < 180; ++$i ) {
			touch( $this->folder . '/photo-' . $i );
		}
		touch( $this->folder . '/Öl' );
		touch( $this->folder . '/Logo' );
		touch( $this->folder . '/logo' );
		$this->stable();
		$start = new DirectorySpellings( $this->workspace, 0 );
		self::assertFalse( $start->known( $this->folder ) );
		try {
			$start->unique( $this->folder, 'photo-120' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			self::assertTrue( $start->known( $this->folder ) );
		}
		$resumed = new DirectorySpellings( $this->workspace, INF );
		self::assertTrue( $resumed->unique( $this->folder, 'photo-120' ) );
		self::assertTrue( $resumed->unique( $this->folder, 'missing' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'PHOTO-120' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'öl' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'Logo' ) );
		self::assertFalse( $resumed->unique( $this->folder, 'logo' ) );
		touch( $this->folder . '/PHOTO-120' );
		$this->stable();
		self::assertFalse( $resumed->unique( $this->folder, 'photo-120' ) );
	}

	/** A folder of 180 names whose index an undo review has begun. */
	private function begun_index(): void {
		for ( $i = 0; $i < 180; ++$i ) {
			touch( $this->folder . '/photo-' . $i );
		}
		$this->stable();
		try {
			( new DirectorySpellings( $this->workspace, 0, true ) )->unique( $this->folder, 'photo-1' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->known( $this->folder ) );
		}
	}

	public function test_an_undo_review_answers_a_folder_that_cannot_be_read_where_a_preview_fails(): void {
		$this->begun_index();
		// The folder can no longer be read once its index has begun: an undo review answers that
		// another spelling may be there, so that the entry is kept, and a preview fails.
		chmod( $this->folder, 0311 );
		try {
			if ( is_readable( $this->folder ) ) {
				self::markTestSkipped( 'This user bypasses directory read permissions.' );
			}
			$this->stable();
			self::assertFalse( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'photo-1' ) );
			$this->expectException( StorageFailed::class );
			$this->expectExceptionMessage( 'A directory could not be read while its names were being reviewed.' );
			( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-1' );
		} finally {
			chmod( $this->folder, 0700 );
		}
	}

	public function test_an_undo_review_answers_a_folder_that_is_gone_where_a_preview_fails(): void {
		$this->begun_index();
		TempDir::remove( $this->folder );
		self::assertFalse( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'photo-1' ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'A directory disappeared while its names were being reviewed.' );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-1' );
	}

	public function test_a_damaged_index_fails_also_in_an_undo_review(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' ) );
		$files = glob( $this->workspace . '/spell-*.names' );
		self::assertCount( 1, $files );
		file_put_contents( $files[0], '' );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'The directory spelling cursor exceeds its stored records.' );
		( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' );
	}

	/** A folder of 180 names whose last change lies a second back. */
	private function photos(): void {
		for ( $i = 0; $i < 180; ++$i ) {
			touch( $this->folder . '/photo-' . $i );
		}
		$this->stable();
	}

	/**
	 * Changes the folder as an upload does, and gives it a modification time of its own, as a
	 * change in a later second does, so that its identity changes on every file system.
	 */
	private function upload( int $number ): void {
		touch( $this->folder . '/upload-' . $number );
		touch( $this->folder, 1700000000 + $number );
	}

	public function test_a_folder_that_changes_between_every_two_ticks_ends_its_reading_with_a_refusal_of_its_own(): void {
		$this->photos();
		for ( $tick = 0; $tick < 20; ++$tick ) {
			try {
				( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
				self::fail( 'A reading of a folder that changes between every two ticks completed.' );
			} catch ( FileWorkPending ) {
				$this->upload( $tick );
			} catch ( StorageFailed $refused ) {
				// Five readings began, and the folder changed before each of them was complete.
				self::assertSame( 5, $tick );
				self::assertSame( 'A folder kept changing while its names were being reviewed.', $refused->getMessage() );
				self::assertSame(
					[
						'directory' => 'unsettled',
						'code'      => 'file_folder_changing',
						'folder'    => $this->folder,
					],
					$refused->context()
				);
				self::assertSame( 'file_folder_changing', JobError::from_throwable( $refused, JobType::FilePlan )->code );
				return;
			}
		}
		// Before, every tick began the reading again, and the review never ended.
		self::fail( 'The reading of a folder that changes between every two ticks never ended.' );
	}

	public function test_an_undo_review_keeps_the_entries_of_a_folder_that_keeps_changing_and_goes_on(): void {
		$this->photos();
		$answer = null;
		$ticks  = 0;
		while ( null === $answer && $ticks < 20 ) {
			try {
				$answer = ( new DirectorySpellings( $this->workspace, 0, true ) )->unique( $this->folder, 'photo-1' );
			} catch ( FileWorkPending ) {
				$this->upload( $ticks++ );
			}
		}
		// Another spelling may be there: the entry is kept, and the review goes on.
		self::assertSame( [ false, 5 ], [ $answer, $ticks ] );
		// For the rest of the review the folder is answered at once, also once it has settled,
		// and no other reading of it begins; a preview is refused.
		$this->stable();
		$head = glob( $this->workspace . '/spell-*.head' );
		self::assertIsArray( $head );
		self::assertCount( 1, $head );
		$checkpoint = file_get_contents( $head[0] );
		self::assertFalse( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'photo-2' ) );
		self::assertSame( $checkpoint, file_get_contents( $head[0] ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'A folder kept changing while its names were being reviewed.' );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-2' );
	}

	public function test_a_folder_that_changes_once_between_two_ticks_is_read_again_and_completes(): void {
		$this->photos();
		try {
			( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			// An upload arrives after the first tick read a part of the names.
			touch( $this->folder . '/Upload' );
		}
		$resumed = new DirectorySpellings( $this->workspace, INF );
		self::assertFalse( $resumed->unique( $this->folder, 'upload' ) );
		self::assertTrue( $resumed->unique( $this->folder, 'photo-1' ) );
	}

	public function test_a_folder_that_changes_while_a_tick_reads_its_names_is_read_again_instead_of_failing(): void {
		$this->photos();
		$folder = WatchedFolder::url( $this->folder );
		// An upload arrives when the first reading has read a hundred names.
		WatchedFolder::watch( 100, fn (): bool => touch( $this->folder . '/Upload' ) );
		try {
			try {
				( new DirectorySpellings( $this->workspace, INF ) )->unique( $folder, 'photo-1' );
				self::fail( 'A reading during which the folder changed was used.' );
			} catch ( FileWorkPending ) {
				// Before, the preview failed: the folder changed while its names were read.
				self::assertTrue( WatchedFolder::changed() );
			}
			$resumed = new DirectorySpellings( $this->workspace, INF );
			self::assertFalse( $resumed->unique( $folder, 'upload' ) );
			self::assertTrue( $resumed->unique( $folder, 'photo-1' ) );
		} finally {
			WatchedFolder::stop();
		}
	}

	public function test_a_checkpoint_that_an_earlier_version_wrote_is_resumed(): void {
		$this->photos();
		try {
			( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			$head = glob( $this->workspace . '/spell-*.head' );
			self::assertIsArray( $head );
			// An earlier version ends the checkpoint with the bucket heads, without the count of
			// readings that changes cut short.
			file_put_contents( $head[0], substr( (string) file_get_contents( $head[0] ), 0, -1 ) );
		}
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-1' ) );
		self::assertFalse( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'PHOTO-1' ) );
	}

	public function test_a_checkpoint_that_counts_more_readings_cut_short_than_any_index_records_is_damaged(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' ) );
		$head = glob( $this->workspace . '/spell-*.head' );
		self::assertIsArray( $head );
		$checkpoint = (string) file_get_contents( $head[0] );
		// Four readings cut short is the most that a checkpoint records; with a fifth there is none.
		file_put_contents( $head[0], substr( $checkpoint, 0, -1 ) . chr( 4 ) );
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' ) );
		file_put_contents( $head[0], substr( $checkpoint, 0, -1 ) . chr( 5 ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'The checkpoint of a directory spelling index is invalid.' );
		( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' );
	}

	public function test_an_incomplete_persisted_record_fails_closed(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		$first = new DirectorySpellings( $this->workspace, INF );
		self::assertTrue( $first->unique( $this->folder, 'original' ) );
		$files = glob( $this->workspace . '/spell-*.names' );
		self::assertCount( 1, $files );
		file_put_contents( $files[0], '' );
		$this->expectException( StorageFailed::class );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' );
	}
}
