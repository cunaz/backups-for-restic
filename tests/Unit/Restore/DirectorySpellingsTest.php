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
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function test_a_change_after_the_folder_was_looked_at_cuts_the_resumed_reading_short_at_its_boundary_too(): void {
		$this->photos();
		$folder = WatchedFolder::url( $this->folder );
		WatchedFolder::before_reading( static function (): void {} );
		try {
			try {
				( new DirectorySpellings( $this->workspace, 0 ) )->unique( $folder, 'photo-1' );
				self::fail( 'The bounded first slice scanned the entire directory.' );
			} catch ( FileWorkPending ) {
				// A checkpoint of the first 64 names.
				self::assertSame( 64, unpack( 'V', (string) file_get_contents( $this->checkpoint() ), strlen( "BFRNAMES1\0" ) + 48 )[1] ?? null );
			}
			// The next tick finds the folder as the checkpoint has it and waits for no change
			// second; then names are removed and one is added before the reading resumes, so that
			// the folder no longer reaches the last name of the checkpoint.
			WatchedFolder::before_reading(
				function (): void {
					for ( $i = 0; $i < 175; ++$i ) {
						unlink( $this->folder . '/photo-' . $i );
					}
					touch( $this->folder . '/Late' );
				}
			);
			try {
				( new DirectorySpellings( $this->workspace, INF ) )->unique( $folder, 'photo-178' );
				self::fail( 'A reading during which the folder changed was used.' );
			} catch ( FileWorkPending ) {
				// Before, the preview failed at once: "A directory enumeration changed between file
				// preview workers."
				self::assertTrue( WatchedFolder::changed() );
			}
			$resumed = new DirectorySpellings( $this->workspace, INF );
			self::assertFalse( $resumed->unique( $folder, 'late' ) );
			self::assertTrue( $resumed->unique( $folder, 'photo-178' ) );
			// The reading that the change cut short is counted, as one cut short within a tick.
			self::assertSame( 1, ord( substr( (string) file_get_contents( $this->checkpoint() ), -1 ) ) );
		} finally {
			WatchedFolder::stop();
		}
	}

	public function test_a_damaged_boundary_record_of_an_unchanged_folder_stays_a_damaged_index(): void {
		$this->photos();
		try {
			( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			$names   = substr( $this->checkpoint(), 0, -strlen( '.head' ) ) . '.names';
			$records = fopen( $names, 'r+b' );
			self::assertIsResource( $records );
			// The name digest of the last record that the checkpoint counts.
			fseek( $records, 63 * 68 + 32 );
			fwrite( $records, str_repeat( "\0", 32 ) );
			fclose( $records );
		}
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'A directory enumeration changed between file preview workers.' );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-1' );
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

	/** The checkpoint of the one folder that the tests index. */
	private function checkpoint(): string {
		$head = glob( $this->workspace . '/spell-*.head' );
		self::assertIsArray( $head );
		self::assertCount( 1, $head );
		return $head[0];
	}

	/**
	 * Ticks of workers without time, with an upload before each, until a lookup is refused.
	 *
	 * @return int The tick at which the lookup was refused.
	 */
	private function ticks_until_refused( int $first ): int {
		for ( $tick = $first; $tick < 20; ++$tick ) {
			try {
				( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
				self::fail( 'A reading of a folder that changes between every two ticks completed.' );
			} catch ( FileWorkPending ) {
				$this->upload( $tick );
			} catch ( StorageFailed $refused ) {
				self::assertSame( 'file_folder_changing', $refused->context()['code'] ?? null, $refused->getMessage() );
				return $tick;
			}
		}
		self::fail( 'The reading of a folder that changes between every two ticks never ended.' );
	}

	public function test_a_folder_that_settles_once_and_changes_again_gets_five_readings_again(): void {
		$this->photos();
		// Two readings that changes cut short, so that the count is not zero when one completes.
		for ( $tick = 0; $tick < 2; ++$tick ) {
			try {
				( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
				self::fail( 'A reading without time completed.' );
			} catch ( FileWorkPending ) {
				$this->upload( 200 + $tick );
			}
		}
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'photo-1' ) );
		self::assertSame( 2, ord( substr( (string) file_get_contents( $this->checkpoint() ), -1 ) ), 'The complete reading began after two that were cut short.' );
		// A complete reading clears the count: the five readings count from the next change, not
		// from the two before it.
		$this->upload( 100 );
		self::assertSame( 5, $this->ticks_until_refused( 0 ) );
	}

	public function test_a_reading_that_an_earlier_version_began_counts_as_the_first_of_the_five(): void {
		$this->photos();
		try {
			( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' );
			self::fail( 'The bounded first slice scanned the entire directory.' );
		} catch ( FileWorkPending ) {
			// As an earlier version writes it, without the count of readings cut short.
			$head = $this->checkpoint();
			file_put_contents( $head, substr( (string) file_get_contents( $head ), 0, -1 ) );
			$this->upload( 0 );
		}
		self::assertSame( 5, $this->ticks_until_refused( 1 ) );
	}

	public function test_a_folder_that_does_not_change_is_read_to_its_end_across_many_ticks(): void {
		for ( $i = 0; $i < 400; ++$i ) {
			touch( $this->folder . '/photo-' . $i );
		}
		$this->stable();
		for ( $tick = 0; $tick < 20; ++$tick ) {
			try {
				// 402 names, 64 of them in a tick without time: the seventh tick completes the reading.
				self::assertSame( [ true, 6 ], [ ( new DirectorySpellings( $this->workspace, 0 ) )->unique( $this->folder, 'photo-1' ), $tick ] );
				return;
			} catch ( FileWorkPending ) {
				continue;
			}
		}
		self::fail( 'The reading of a folder that does not change never ended.' );
	}

	/**
	 * Checkpoints that no version writes, and how each is made from a complete one.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function damaged_checkpoints(): array {
		return [
			'longer than any version writes it' => [ 'longer' ],
			'shorter than its bucket heads'     => [ 'shorter' ],
			'a flag that is neither of the two' => [ 'flag' ],
		];
	}

	#[DataProvider( 'damaged_checkpoints' )]
	public function test_a_checkpoint_that_no_version_writes_is_damaged( string $damage ): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' ) );
		$head = $this->checkpoint();
		$body = (string) file_get_contents( $head );
		// The magic, the identity, the salt and the cursor come before the flag.
		$flag = strlen( "BFRNAMES1\0" ) + 32 + 16 + 4;
		file_put_contents(
			$head,
			match ( $damage ) {
				'longer'  => $body . "\0",
				'shorter' => substr( $body, 0, $flag + 1 + 1024 ),
				default   => substr_replace( $body, "\2", $flag, 1 ),
			}
		);
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'The checkpoint of a directory spelling index is invalid.' );
		( new DirectorySpellings( $this->workspace, INF, true ) )->unique( $this->folder, 'original' );
	}

	public function test_a_checkpoint_that_cannot_be_read_is_damaged(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' ) );
		$head = $this->checkpoint();
		chmod( $head, 0000 );
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			if ( is_readable( $head ) ) {
				self::markTestSkipped( 'This user bypasses file permissions.' );
			}
			$this->expectException( StorageFailed::class );
			$this->expectExceptionMessage( 'The checkpoint of a directory spelling index is invalid.' );
			( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' );
		} finally {
			restore_error_handler();
			chmod( $head, 0600 );
		}
	}

	public function test_records_one_byte_short_of_the_cursor_fail_closed(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' ) );
		$names = substr( $this->checkpoint(), 0, -strlen( '.head' ) ) . '.names';
		file_put_contents( $names, substr( (string) file_get_contents( $names ), 0, -1 ) );
		$this->expectException( StorageFailed::class );
		$this->expectExceptionMessage( 'The directory spelling cursor exceeds its stored records.' );
		( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' );
	}

	public function test_a_cursor_at_the_most_names_that_a_folder_may_have_is_resumed(): void {
		touch( $this->folder . '/original' );
		$this->stable();
		self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' ) );
		$head   = $this->checkpoint();
		$names  = substr( $head, 0, -strlen( '.head' ) ) . '.names';
		$body   = (string) file_get_contents( $head );
		$cursor = strlen( "BFRNAMES1\0" ) + 32 + 16;
		// A million names and the two dot entries, with records for all of them (a sparse file),
		// and one name more.
		$limits = [
			1000002 => true,
			1000003 => false,
		];
		foreach ( $limits as $count => $resumed ) {
			file_put_contents( $head, substr_replace( $body, pack( 'V', $count ), $cursor, 4 ) );
			$records = fopen( $names, 'r+b' );
			self::assertIsResource( $records );
			ftruncate( $records, $count * 68 );
			fclose( $records );
			try {
				self::assertTrue( ( new DirectorySpellings( $this->workspace, INF ) )->unique( $this->folder, 'original' ) );
				self::assertTrue( $resumed, 'A cursor beyond the limit was resumed.' );
			} catch ( StorageFailed $refused ) {
				self::assertFalse( $resumed, $refused->getMessage() );
				self::assertSame( 'The directory spelling cursor exceeds its stored records.', $refused->getMessage() );
			}
		}
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
