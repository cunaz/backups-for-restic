<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ApplyManifest;
use BackupsForRestic\Restore\ApplyPlan;
use BackupsForRestic\Restore\ChunkDigest;
use BackupsForRestic\Restore\FileChange;
use BackupsForRestic\Restore\FileSpace;
use BackupsForRestic\Restore\LiveSpellings;
use BackupsForRestic\Tests\Support\TempDir;

final class FileChangeTest extends TestCase {

	private string $root;
	private string $site;
	private string $staging;
	private string $journal;
	private string $path;
	private FileChange $change;

	protected function setUp(): void {
		$this->root    = TempDir::create( 'file-change' );
		$this->site    = $this->root . '/site';
		$this->path    = $this->site . '/selected.php';
		$this->staging = $this->root . '/files';
		$this->journal = $this->root . '/journal';
		mkdir( $this->site, 0700 );
		mkdir( $this->staging . $this->site, 0700, true );
		$this->change = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
	}

	/**
	 * The outcome of a worker's undo of one entry.
	 *
	 * @return array{outcome: string, reason: string}
	 */
	private static function outcome( string $outcome, string $reason ): array {
		return [
			'outcome' => $outcome,
			'reason'  => $reason,
		];
	}

	public function test_a_reviewed_file_is_replaced_and_undone_once_across_slices(): void {
		$new = str_repeat( 'n', 3000000 );
		$old = str_repeat( 'o', 2500000 );
		file_put_contents( $this->staging . $this->path, $new );
		file_put_contents( $this->path, $old );
		chmod( $this->path, 0640 );
		touch( $this->path, 1710000000 );
		$new_hash = hash( 'sha256', $new );
		$old_hash = hash( 'sha256', $old );

		$space   = new FileSpace( $this->root );
		$payload = $space->allocation( strlen( $new ) ) + $space->allocation( strlen( $old ) );
		self::assertSame( $payload, $this->change->remaining( $this->path, 'replace', $payload, $space ) );
		self::assertFalse( $this->change->apply( $this->path, 'replace', $new_hash, $old_hash, 0 ) );
		self::assertSame( $payload - $space->allocation( 1048576 ), $this->change->remaining( $this->path, 'replace', $payload, $space ) );
		self::assertSame( $old, file_get_contents( $this->path ) );
		self::assertSame( 'keep', $this->change->undo_action( $this->path, 'replace' ) );
		for ( $tick = 0; $tick < 10; ++$tick ) {
			if ( $this->change->apply( $this->path, 'replace', $new_hash, $old_hash, 0 ) ) {
				break;
			}
		}
		self::assertLessThan( 10, $tick );
		self::assertTrue( $this->change->apply( $this->path, 'replace', $new_hash, $old_hash, INF ) );
		self::assertSame( 0, $this->change->remaining( $this->path, 'replace', $payload, $space ) );
		self::assertSame( $new, file_get_contents( $this->path ) );
		self::assertSame( 0640, fileperms( $this->path ) & 0777 );
		self::assertSame( 'restore', $this->change->undo_action( $this->path, 'replace' ) );
		self::assertSame( self::outcome( 'restored', 'undo_journal' ), $this->change->undo( $this->path, 'replace', INF ) );
		// A later undo finds nothing left to revert.
		$later = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'later-undo' );
		self::assertSame( self::outcome( 'unchanged', 'already_undone' ), $later->undo( $this->path, 'replace', INF ) );
		self::assertSame( $old, file_get_contents( $this->path ) );
		self::assertSame( 0640, fileperms( $this->path ) & 0777 );
		self::assertSame( 1710000000, filemtime( $this->path ) );
	}

	public function test_large_chunk_digests_apply_and_undo_after_repeated_worker_restarts(): void {
		$staged = $this->staging . $this->path;
		file_put_contents( $staged, str_repeat( 'n', 9 * ChunkDigest::BLOCK + 1 ) );
		file_put_contents( $this->path, str_repeat( 'o', 8 * ChunkDigest::BLOCK + 1 ) );
		$cursor   = [];
		$source   = ChunkDigest::advance( $staged, INF, $cursor );
		$cursor   = [];
		$original = ChunkDigest::advance( $this->path, INF, $cursor );
		$old_sha  = hash_file( 'sha256', $this->path );
		$new_sha  = hash_file( 'sha256', $staged );
		foreach ( [ 'apply', 'undo' ] as $operation ) {
			$time_limit = microtime( true ) + 18;
			$ticks      = 0;
			do {
				$change   = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, $operation );
				$deadline = microtime( true ) + 0.001;
				$finished = 'apply' === $operation
					? $change->apply( $this->path, 'replace', $source, $original, $deadline )
					: self::outcome( 'restored', 'undo_journal' ) === $change->undo( $this->path, 'replace', $deadline, 'restore' );
				++$ticks;
				if ( ! $finished ) {
					usleep( 15000 );
				}
			} while ( ! $finished && microtime( true ) < $time_limit );
			self::assertTrue( $finished, $operation . ' stalled after worker restart' );
			self::assertGreaterThan( 2, $ticks );
			self::assertSame( 'apply' === $operation ? $new_sha : $old_sha, hash_file( 'sha256', $this->path ) );
		}
	}

	public function test_a_different_preimage_inode_cannot_authorize_the_replacement_even_with_identical_bytes(): void {
		$new = str_repeat( 'n', ChunkDigest::BLOCK + 1 );
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, $new );
		$source   = hash( 'sha256', $new );
		$original = hash( 'sha256', 'old' );
		self::assertFalse( $this->change->apply( $this->path, 'replace', $source, $original, 0.0 ) );
		$backup = $this->journal . '/original';
		self::assertSame( 'old', file_get_contents( $backup ) );
		$inode = fileinode( $backup );
		file_put_contents( $this->journal . '/another', 'old' );
		rename( $this->journal . '/another', $backup );
		clearstatcache( true, $backup );
		self::assertNotSame( $inode, fileinode( $backup ) );
		try {
			$this->change->apply( $this->path, 'replace', $source, $original, INF );
			self::fail( 'The reviewed undo inode must exist when the live original is replaced.' );
		} catch ( StorageFailed $refused ) {
			self::assertStringContainsString( 'retained undo copy', $refused->getMessage() );
		}
		self::assertSame( 'old', file_get_contents( $this->path ) );
	}

	public function test_restarted_workers_recheck_a_damaged_large_preimage_in_bounded_blocks_before_publication(): void {
		$old = str_repeat( 'a', 3 * ChunkDigest::BLOCK + 1 );
		$new = str_repeat( 'b', 2 * ChunkDigest::BLOCK + 1 );
		file_put_contents( $this->path, $old );
		file_put_contents( $this->staging . $this->path, $new );
		$cursor     = [];
		$original   = ChunkDigest::advance( $this->path, INF, $cursor );
		$cursor     = [];
		$source     = ChunkDigest::advance( $this->staging . $this->path, INF, $cursor );
		$backup     = $this->journal . '/original';
		$checkpoint = $this->journal . '/verify-' . hash( 'sha256', 'apply' . "\0" . $backup . "\0" . $original . "\0" . '1' ) . '.json';
		$changed    = null;
		$offsets    = [];
		$refused    = false;
		$limit      = microtime( true ) + 20;
		do {
			$change = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'apply' );
			try {
				self::assertFalse( $change->apply( $this->path, 'replace', $source, $original, 0.0 ) );
			} catch ( StorageFailed $error ) {
				self::assertStringContainsString( 'retained undo copy', $error->getMessage() );
				$refused = true;
				break;
			}
			$intent = json_decode( file_get_contents( $this->journal . '/entry.json' ), true, 512, JSON_THROW_ON_ERROR );
			if ( null === $changed && null !== $intent['backup'] ) {
				$handle = fopen( $backup, 'r+b' );
				fwrite( $handle, 'x' );
				fclose( $handle );
				touch( $backup, time() - 60 );
				$changed = ApplyManifest::identity( $backup );
			}
			if ( null !== $changed && is_file( $checkpoint ) ) {
				$proof = json_decode( file_get_contents( $checkpoint ), true, 512, JSON_THROW_ON_ERROR );
				if ( $changed === $proof['identity'] && true === $proof['stable'] ) {
					$offsets[] = $proof['offset'];
				}
			}
			usleep( 20000 );
		} while ( microtime( true ) < $limit );
		self::assertTrue( $refused, 'The damaged preimage must stop the live rename.' );
		self::assertContains( ChunkDigest::BLOCK, $offsets );
		self::assertContains( 2 * ChunkDigest::BLOCK, $offsets );
		self::assertContains( 3 * ChunkDigest::BLOCK, $offsets );
		self::assertSame( $old, file_get_contents( $this->path ) );
	}

	public function test_recovery_recognizes_a_completed_rename_on_both_sides(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		$apply  = fn (): bool => $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF );
		self::assertTrue( $apply() );
		$file   = $this->journal . '/entry.json';
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		// The rename finished, but the last intent visible after a kill still precedes it.
		$record['phase'] = 'ready';
		file_put_contents( $file, json_encode( $record ) );
		self::assertTrue( $apply() );
		self::assertSame( self::outcome( 'restored', 'undo_journal' ), $this->change->undo( $this->path, 'replace', INF, 'restore' ) );
		// The original is back, but the last record still precedes the rename: the retry of
		// the same undo completes it and counts its own restore, also without the confirmed
		// action, instead of reporting a change; another undo counts nothing.
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( 'legacy', $record['undo'] );
		$record['phase'] = 'restore';
		foreach ( [ [ 'later-undo', 'unchanged' ], [ 'legacy', 'restored' ] ] as [ $scope, $outcome ] ) {
			file_put_contents( $file, json_encode( $record ) );
			$retry = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, $scope );
			self::assertSame( self::outcome( $outcome, 'already_undone' ), $retry->undo( $this->path, 'replace', INF ), $scope );
			self::assertSame( 'undone', json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR )['phase'] );
		}
		self::assertSame( 'old', file_get_contents( $this->path ) );
	}

	public function test_undo_preserves_changes_made_after_apply(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF ) );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		file_put_contents( $this->path, 'edited' );
		self::assertSame(
			[
				'action' => 'keep',
				'reason' => 'changed_since_apply',
			],
			$this->change->undo_review( $this->path, 'create' )
		);
		// The confirmed removal finds the edit and keeps the file, and its journal, instead.
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'remove' ) );
		self::assertSame( 'edited', file_get_contents( $this->path ) );
		self::assertSame( 'done', json_decode( (string) file_get_contents( $this->journal . '/entry.json' ), true, flags: JSON_THROW_ON_ERROR )['phase'] );
	}

	public function test_create_and_undo_only_touch_the_selected_file(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		file_put_contents( $this->site . '/keep', 'unrelated' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 06755 ) );
		self::assertSame( 0755, fileperms( $this->path ) & 07777 );
		// A created file is only ever removed: a confirmed restore of it is a change, and kept.
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'restore' ) );
		self::assertSame( 'new', file_get_contents( $this->path ) );
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $this->change->undo( $this->path, 'create', INF ) );
		self::assertFileDoesNotExist( $this->path );
		self::assertSame( 'unrelated', file_get_contents( $this->site . '/keep' ) );
	}

	public function test_an_unpublished_partial_change_can_be_cancelled_while_the_tree_remains_intact(): void {
		file_put_contents( $this->staging . $this->path, str_repeat( 'x', 2000000 ) );
		$source = hash_file( 'sha256', $this->staging . $this->path );
		self::assertFalse( $this->change->apply( $this->path, 'create', $source, null, 0 ) );
		self::assertSame( self::outcome( 'unchanged', 'not_applied' ), $this->change->undo( $this->path, 'create', INF ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function test_undo_preserves_later_permission_changes_even_when_the_bytes_match(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 0000 ) );
		self::assertSame( 0400, fileperms( $this->path ) & 0777 );
		chmod( $this->path, 0600 );
		self::assertSame( 'keep', $this->change->undo_action( $this->path, 'create' ) );
		self::assertSame( 'changed_since_apply', $this->change->undo_review( $this->path, 'create' )['reason'] );
	}

	public function test_undo_retains_a_directory_with_a_new_unrelated_entry(): void {
		mkdir( $this->staging . $this->path );
		self::assertTrue( $this->change->apply( $this->path, 'mkdir', null, null, INF ) );
		file_put_contents( $this->path . '/later', 'later' );
		self::assertSame( self::outcome( 'kept', 'new_children' ), $this->change->undo( $this->path, 'mkdir', INF, 'remove' ) );
		self::assertSame( 'later', file_get_contents( $this->path . '/later' ) );
		// Without the new entry, a later undo removes the directory after all.
		unlink( $this->path . '/later' );
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $this->change->undo( $this->path, 'mkdir', INF, 'remove' ) );
		self::assertDirectoryDoesNotExist( $this->path );
	}

	public function test_a_changed_source_cannot_replace_a_live_file(): void {
		file_put_contents( $this->staging . $this->path, 'different' );
		file_put_contents( $this->path, 'old' );
		try {
			$this->change->apply( $this->path, 'replace', hash( 'sha256', 'reviewed' ), hash( 'sha256', 'old' ), INF );
			self::fail( 'The new content must match the manifest.' );
		} catch ( StorageFailed ) {
			self::assertSame( 'old', file_get_contents( $this->path ) );
		}
	}

	public function test_a_folder_that_gains_a_name_after_its_last_check_is_kept_and_the_undo_goes_on(): void {
		$folder = $this->site . '/uploads';
		mkdir( $this->staging . $folder, 0700 );
		self::assertTrue( $this->change->apply( $folder, 'mkdir', null, null, INF ) );
		$plan   = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$worker = fn ( \Closure $gap ): FileChange => new FileChange(
			$plan,
			$this->staging,
			$this->journal,
			null,
			'undo',
			static function ( string $done, string $on ) use ( $gap, $folder ): void {
				if ( 'check' === $done && $folder === $on ) {
					$gap();
				}
			}
		);
		$phase  = fn (): string => json_decode( (string) file_get_contents( $this->journal . '/entry.json' ), true, flags: JSON_THROW_ON_ERROR )['phase'];
		// A file arrives in the folder after its last check and before its removal: the folder
		// is kept with its reason, as at the check, and the undo goes on with the next entry.
		$upload = static function () use ( $folder ): void {
			file_put_contents( $folder . '/photo.jpg', 'photo' );
		};
		self::assertSame( self::outcome( 'kept', 'new_children' ), $worker( $upload )->undo( $folder, 'mkdir', INF, 'remove' ) );
		self::assertSame( 'photo', file_get_contents( $folder . '/photo.jpg' ) );
		self::assertSame( 'done', $phase() );
		unlink( $folder . '/photo.jpg' );
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			return;
		}
		// A removal refused for another reason, with no name in the folder, still stops the undo,
		// and so does one with a name that cannot be read.
		$refusals = [
			'site not writable'   => function (): void {
				chmod( $this->site, 0500 );
			},
			'folder not readable' => static function () use ( $folder ): void {
				file_put_contents( $folder . '/hidden', 'hidden' );
				chmod( $folder, 0300 );
			},
		];
		foreach ( $refusals as $case => $gap ) {
			try {
				$worker( $gap )->undo( $folder, 'mkdir', INF, 'remove' );
				self::fail( 'The removal must stop the undo: ' . $case );
			} catch ( StorageFailed $refused ) {
				self::assertStringContainsString( 'cannot be removed', $refused->getMessage(), $case );
			} finally {
				chmod( $this->site, 0700 );
				chmod( $folder, 0700 );
			}
			self::assertDirectoryExists( $folder );
			self::assertSame( 'done', $phase() );
		}
	}

	public function test_an_entry_that_cannot_be_looked_up_is_not_taken_for_a_missing_one(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Root searches every folder.' );
		}
		$folder = $this->site . '/folder';
		$file   = $folder . '/created.txt';
		mkdir( $folder );
		mkdir( $this->staging . $folder, 0700 );
		file_put_contents( $this->staging . $file, 'created' );
		self::assertTrue( $this->change->apply( $file, 'create', hash( 'sha256', 'created' ), null, INF ) );
		$kept         = [
			'action' => 'keep',
			'reason' => 'changed_since_apply',
		];
		$unsearchable = function ( string $directory, \Closure $check ): void {
			// file_exists() and lstat() answer false below it, also for a file that is there.
			chmod( $directory, 0600 );
			try {
				$check();
			} finally {
				chmod( $directory, 0700 );
			}
		};
		$unsearchable(
			$folder,
			function () use ( $file, $kept ): void {
				self::assertSame( $kept, $this->change->undo_review( $file, 'create' ) );
				self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $file, 'create', INF, 'remove' ) );
			}
		);
		self::assertSame( 'created', file_get_contents( $file ) );
		self::assertSame( 'remove', $this->change->undo_action( $file, 'create' ) );
		// Missing from a folder that can be searched, or below one that is itself missing, the file is gone.
		unlink( $file );
		self::assertSame( 'removed_since_apply', $this->change->undo_review( $file, 'create' )['reason'] );
		rmdir( $folder );
		self::assertSame( 'removed_since_apply', $this->change->undo_review( $file, 'create' )['reason'] );
		// A journal record that cannot be looked up is not a missing one either.
		$unsearchable(
			$this->journal,
			function () use ( $file ): void {
				try {
					$this->change->undo_review( $file, 'create' );
					self::fail( 'A journal that cannot be read must not count as missing.' );
				} catch ( StorageFailed $refused ) {
					self::assertStringContainsString( 'cannot be read', $refused->getMessage() );
				}
			}
		);
		// A create stopped after its link, before it released its private name: published, though
		// the private name remains. Below a folder that cannot be searched, that is not told apart
		// from an entry that was never published, and the entry is kept.
		mkdir( $folder );
		$journal = $this->root . '/journal-linked';
		$plan    = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$stop    = static function ( string $done, string $on ) use ( $file ): void {
			if ( 'link' === $done && $file === $on ) {
				throw new \DomainException( 'The worker was killed.' );
			}
		};
		try {
			( new FileChange( $plan, $this->staging, $journal, null, 'apply', $stop ) )->apply( $file, 'create', hash( 'sha256', 'created' ), null, INF );
			self::fail( 'The worker must stop after its link.' );
		} catch ( \DomainException ) {
			self::assertFileExists( $journal . '/candidate' );
		}
		$linked = new FileChange( $plan, $this->staging, $journal );
		$unsearchable(
			$folder,
			function () use ( $linked, $file, $kept ): void {
				self::assertSame( $kept, $linked->undo_review( $file, 'create' ) );
			}
		);
		self::assertSame( 'remove', $linked->undo_action( $file, 'create' ) );
	}

	public function test_a_created_folder_that_gained_many_names_is_kept_without_listing_them(): void {
		$folder = $this->site . '/uploads';
		mkdir( $this->staging . $folder, 0700 );
		self::assertTrue( $this->change->apply( $folder, 'mkdir', null, null, INF ) );
		self::assertSame( 'remove', $this->change->undo_action( $folder, 'mkdir' ) );
		// After the review, the folder gains many long names; one of them tells that it is not empty.
		for ( $index = 0; $index < 8000; ++$index ) {
			touch( $folder . '/' . str_pad( (string) $index, 240, '-' ) );
		}
		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$outcome = $this->change->undo( $folder, 'mkdir', INF, 'remove' );
		$peak    = memory_get_peak_usage() - $before;
		self::assertSame( self::outcome( 'kept', 'new_children' ), $outcome );
		// Their listing alone would take more than two megabytes.
		self::assertLessThan( 1048576, $peak );
		self::assertCount( 8002, scandir( $folder ) );
	}

	public function test_a_directory_is_created_and_undone_only_if_owned(): void {
		mkdir( $this->staging . $this->path );
		self::assertTrue( $this->change->apply( $this->path, 'mkdir', null, null, INF ) );
		self::assertDirectoryExists( $this->path );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'mkdir' ) );
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $this->change->undo( $this->path, 'mkdir', INF ) );
		self::assertDirectoryDoesNotExist( $this->path );
	}

	public function test_records_and_copies_reach_the_disk_before_the_live_changes_they_authorise(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		$steps  = [];
		$record = static function ( string $operation, string $subject ) use ( &$steps ): void {
			$steps[] = $operation . ' ' . $subject;
		};
		$change = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'apply', $record );
		self::assertTrue( $change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$copy      = $this->journal . '/original';
		$candidate = $this->journal . '/candidate';
		self::assertSame(
			[
				'journal copy',
				'sync ' . $copy,
				'journal copy',
				'sync ' . $candidate,
				'journal ready',
				'rename ' . $this->path,
				'sync ' . $this->site,
				'journal done',
			],
			$steps
		);
		$steps = [];
		self::assertSame( self::outcome( 'restored', 'undo_journal' ), $change->undo( $this->path, 'replace', INF ) );
		self::assertSame( [ 'sync ' . $copy, 'journal restore', 'check ' . $this->path, 'rename ' . $this->path, 'sync ' . $this->site, 'journal undone' ], $steps );
		self::assertSame( 'old', file_get_contents( $this->path ) );
		// An entry that is undone already is neither touched nor recorded again, by a later
		// undo or by a retry of this one.
		$steps = [];
		$later = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'later-undo', $record );
		self::assertSame( self::outcome( 'unchanged', 'already_undone' ), $later->undo( $this->path, 'replace', INF ) );
		self::assertSame( self::outcome( 'restored', 'already_undone' ), $change->undo( $this->path, 'replace', INF ) );
		self::assertSame( [], $steps );

		$created = $this->site . '/created.php';
		$journal = $this->root . '/journal-created';
		file_put_contents( $this->staging . $created, 'created' );
		$steps  = [];
		$change = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $journal, null, 'apply', $record );
		self::assertTrue( $change->apply( $created, 'create', hash( 'sha256', 'created' ), null, INF ) );
		self::assertSame(
			[
				'journal copy',
				'sync ' . $journal . '/candidate',
				'journal ready',
				'link ' . $created,
				'unlink ' . $journal . '/candidate',
				'sync ' . $this->site,
				'journal done',
			],
			$steps
		);
		$steps = [];
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $change->undo( $created, 'create', INF ) );
		self::assertSame( [ 'check ' . $created, 'unlink ' . $created, 'sync ' . $this->site, 'journal undone' ], $steps );
	}

	public function test_a_retry_counts_a_revert_only_from_the_record_that_proves_it(): void {
		$plan    = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$number  = 0;
		$applied = function ( string $action ) use ( $plan, &$number ): array {
			++$number;
			$path    = $this->site . '/entry-' . $number;
			$journal = $this->root . '/journal-' . $number;
			if ( 'mkdir' === $action ) {
				mkdir( $this->staging . $path, 0700 );
			} else {
				file_put_contents( $this->staging . $path, 'created' );
			}
			self::assertTrue( ( new FileChange( $plan, $this->staging, $journal ) )->apply( $path, $action, 'mkdir' === $action ? null : hash( 'sha256', 'created' ), null, INF ) );
			$worker = fn ( string $scope, ?\Closure $observer = null ): FileChange => new FileChange( $plan, $this->staging, $journal, null, $scope, $observer );
			// The phase of the entry's record and the undo it names.
			$record = static function () use ( $journal ): array {
				$data = json_decode( (string) file_get_contents( $journal . '/entry.json' ), true, flags: JSON_THROW_ON_ERROR );
				return [ $data['phase'], $data['undo'] ];
			};
			return [ $path, $worker, $record ];
		};
		$kill    = static function ( string $operation, string $subject ): \Closure {
			return static function ( string $done, string $on ) use ( $operation, $subject ): void {
				if ( $done === $operation && $on === $subject ) {
					throw new \DomainException( 'The worker was killed.' );
				}
			};
		};
		$removal = [
			'create' => 'unlink',
			'mkdir'  => 'rmdir',
		];
		foreach ( $removal as $action => $removed ) {
			// Stopped after the record that completes the entry, before the job counted it: that
			// record names this undo, so its retry counts the removal as its own, another undo not.
			[ $path, $worker, $record ] = $applied( $action );
			try {
				$worker( 'undo', $kill( 'journal', 'undone' ) )->undo( $path, $action, INF, 'remove' );
				self::fail( 'The worker must stop after its record.' );
			} catch ( \DomainException ) {
				self::assertFalse( file_exists( $path ) );
			}
			self::assertSame( [ 'undone', 'undo' ], $record() );
			self::assertSame( self::outcome( 'removed', 'already_undone' ), $worker( 'undo' )->undo( $path, $action, INF, 'remove' ), $action );
			self::assertSame( self::outcome( 'unchanged', 'already_undone' ), $worker( 'later-undo' )->undo( $path, $action, INF, 'remove' ), $action );
			// Stopped after the unlink or rmdir, before that record: the last record is the
			// apply's, as when someone else removes the entry, and the retry cannot tell the two
			// apart. It counts nothing, and its record names no undo.
			[ $path, $worker, $record ] = $applied( $action );
			try {
				$worker( 'undo', $kill( $removed, $path ) )->undo( $path, $action, INF, 'remove' );
				self::fail( 'The worker must stop after ' . $removed . '.' );
			} catch ( \DomainException ) {
				self::assertFalse( file_exists( $path ) );
			}
			self::assertSame( [ 'done', null ], $record() );
			self::assertSame( self::outcome( 'unchanged', 'removed_since_apply' ), $worker( 'undo' )->undo( $path, $action, INF, 'remove' ), $action );
			self::assertSame( [ 'undone', null ], $record() );
			self::assertSame( self::outcome( 'unchanged', 'already_undone' ), $worker( 'undo' )->undo( $path, $action, INF, 'remove' ), $action );
			// Stopped before the removal, and someone else removes the entry: the same record,
			// and the same count. No record of this undo precedes a removal.
			[ $path, $worker, $record ] = $applied( $action );
			'mkdir' === $action ? rmdir( $path ) : unlink( $path );
			self::assertSame( [ 'done', null ], $record() );
			self::assertSame( self::outcome( 'unchanged', 'removed_since_apply' ), $worker( 'undo' )->undo( $path, $action, INF, 'remove' ), $action );
		}
		// A restore names this undo in the record before its rename, and only that rename puts
		// the retained inode in place. Stopped after the record, and someone else deletes the
		// file: nothing was restored, and the entry is kept with its saved original.
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$restorer = fn ( ?\Closure $observer = null ): FileChange => new FileChange( $plan, $this->staging, $this->journal, null, 'undo', $observer );
		try {
			$restorer( $kill( 'journal', 'restore' ) )->undo( $this->path, 'replace', INF, 'restore' );
			self::fail( 'The worker must stop after its record.' );
		} catch ( \DomainException ) {
			self::assertSame( 'new', file_get_contents( $this->path ) );
		}
		unlink( $this->path );
		self::assertSame( self::outcome( 'kept', 'deleted_since_apply' ), $restorer()->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'old', file_get_contents( $this->journal . '/original' ) );
	}

	public function test_a_damaged_name_of_an_undo_is_refused(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF ) );
		$file   = $this->journal . '/entry.json';
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		self::assertNull( $record['undo'] );
		foreach ( [ '', 'two words', str_repeat( 'u', 129 ), 7, [ 'undo' ] ] as $number => $undo ) {
			$record['undo'] = $undo;
			file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
			try {
				$this->change->undo_review( $this->path, 'create' );
				self::fail( 'Damaged name ' . $number . ' must not be read.' );
			} catch ( StorageFailed $refused ) {
				self::assertStringContainsString( 'invalid undo', $refused->getMessage() );
			}
		}
		// A record written before an undo named itself names none.
		unset( $record['undo'] );
		file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		self::assertFileExists( $this->path );
	}

	public function test_undo_removes_a_created_file_whose_private_name_survived_a_power_loss(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF ) );
		// The completed record survived, the earlier unlink of the private name did not.
		link( $this->path, $this->journal . '/candidate' );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $this->change->undo( $this->path, 'create', INF ) );
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->journal . '/candidate' );
	}

	public function test_a_private_name_left_by_a_create_does_not_hide_a_later_replacement(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF ) );
		// The private name survived a power loss, and someone has replaced the file since.
		link( $this->path, $this->journal . '/candidate' );
		file_put_contents( $this->path . '.next', 'theirs' );
		rename( $this->path . '.next', $this->path );
		self::assertSame( 'changed_since_apply', $this->change->undo_review( $this->path, 'create' )['reason'] );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'remove' ) );
		self::assertSame( 'theirs', file_get_contents( $this->path ) );
	}

	public function test_a_kill_at_any_step_of_a_directory_creation_resumes_and_undo_removes_only_that_directory(): void {
		$plugin = $this->site . '/plugin';
		$code   = $plugin . '/main.php';
		mkdir( $this->staging . $plugin, 0700 );
		file_put_contents( $this->staging . $code, 'code' );
		mkdir( $this->site . '/kept' );
		$plan    = fn (): ApplyPlan => new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$journal = $this->root . '/journal-uninterrupted';
		$steps   = [];
		$record  = static function ( string $operation, string $subject ) use ( &$steps ): void {
			$steps[] = $operation . ' ' . $subject;
		};
		$change  = new FileChange( $plan(), $this->staging, $journal, null, 'apply', $record );
		self::assertTrue( $change->apply( $plugin, 'mkdir', null, null, INF ) );
		self::assertSame(
			[
				'journal copy',
				'mkdir ' . $journal . '/directory',
				'sync ' . $journal . '/directory',
				'journal ready',
				'rename ' . $plugin,
				'sync ' . $this->site,
				'journal done',
			],
			$steps
		);
		$crash_points = count( $steps );
		self::assertSame( self::outcome( 'removed', 'undo_journal' ), $change->undo( $plugin, 'mkdir', INF ) );
		self::assertDirectoryDoesNotExist( $plugin );

		for ( $stop = 0; $stop < $crash_points; ++$stop ) {
			$journal = $this->root . '/journal-' . $stop;
			$seen    = 0;
			$kill    = static function () use ( &$seen, $stop ): void {
				if ( $seen++ === $stop ) {
					throw new \DomainException( 'The worker was killed.' );
				}
			};
			try {
				( new FileChange( $plan(), $this->staging, $journal, null, 'apply', $kill ) )->apply( $plugin, 'mkdir', null, null, INF );
				self::fail( 'The worker must stop after step ' . $stop . '.' );
			} catch ( \DomainException $killed ) {
				self::assertSame( 'The worker was killed.', $killed->getMessage() );
			}
			// The next request completes the entry, owns the directory and publishes a child.
			$next      = $plan();
			$directory = new FileChange( $next, $this->staging, $journal );
			self::assertTrue( $directory->apply( $plugin, 'mkdir', null, null, INF ), 'Step ' . $stop );
			self::assertSame( 'remove', $directory->undo_action( $plugin, 'mkdir' ), 'Step ' . $stop );
			$child = new FileChange( $next, $this->staging, $journal . '-child' );
			self::assertTrue( $child->apply( $code, 'create', hash( 'sha256', 'code' ), null, INF ) );
			self::assertSame( self::outcome( 'removed', 'undo_journal' ), $child->undo( $code, 'create', INF ) );
			self::assertSame( self::outcome( 'removed', 'undo_journal' ), $directory->undo( $plugin, 'mkdir', INF ) );
			self::assertSame( [ '.', '..', 'kept' ], scandir( $this->site ), 'Step ' . $stop );
		}
	}

	public function test_a_created_directory_keeps_the_permissions_of_one_made_in_place(): void {
		$shared = $this->site . '/shared';
		$album  = $shared . '/album';
		mkdir( $shared );
		chmod( $shared, 02755 );
		mkdir( $this->staging . $album, 0700, true );
		$umask = umask( 027 );
		try {
			self::assertTrue( $this->change->apply( $album, 'mkdir', null, null, INF, 0755 ) );
		} finally {
			umask( $umask );
		}
		clearstatcache();
		self::assertSame( 02750, fileperms( $album ) & 07777 );
		self::assertSame( filegroup( $shared ), filegroup( $album ) );
		self::assertSame( 'remove', $this->change->undo_action( $album, 'mkdir' ) );
	}

	/** Waits until the folders set up so far have settled, as a kept listing needs. */
	private static function settle(): void {
		$ready = time() + 1 + LiveSpellings::CLOCK_MARGIN;
		while ( microtime( true ) < $ready ) {
			usleep( 10000 );
		}
	}

	public function test_many_changes_in_one_wide_folder_list_each_folder_about_once_per_second(): void {
		$uploads = $this->site . '/uploads';
		mkdir( $uploads );
		mkdir( $this->staging . $uploads, 0700 );
		for ( $i = 0; $i < 2500; ++$i ) {
			touch( $uploads . '/existing-' . $i );
		}
		for ( $i = 0; $i < 120; ++$i ) {
			file_put_contents( $this->staging . $uploads . '/new-' . $i, 'new ' . $i );
		}
		mkdir( $this->journal );
		// The staging root and one staged folder per path component, then the live root and
		// the written folder. Before, each of the five rechecks of every change listed them all.
		$folders = substr_count( $uploads, '/' ) + 3;
		self::settle();
		$plan    = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$started = time();
		for ( $i = 0; $i < 120; ++$i ) {
			$change = new FileChange( $plan, $this->staging, $this->journal . '/item-' . $i );
			self::assertTrue( $change->apply( $uploads . '/new-' . $i, 'create', hash( 'sha256', 'new ' . $i ), null, INF ) );
		}
		// The written folder is listed again about once for each second in which it changed.
		self::assertLessThanOrEqual( $folders + 1 + time() - $started, $plan->listings() );
		self::assertSame( 'new 119', file_get_contents( $uploads . '/new-119' ) );

		// The undo needs no wait: the other folders have settled, and the wide one keeps a
		// listing made in the second of its last change until that second has passed.
		$plan    = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$started = time();
		for ( $i = 119; $i >= 0; --$i ) {
			$change = new FileChange( $plan, $this->staging, $this->journal . '/item-' . $i );
			self::assertSame( self::outcome( 'removed', 'undo_journal' ), $change->undo( $uploads . '/new-' . $i, 'create', INF ) );
		}
		self::assertLessThanOrEqual( $folders + 1 + time() - $started, $plan->listings() );
		self::assertCount( 2502, (array) scandir( $uploads ) );
	}

	public function test_an_alias_that_appears_during_the_request_is_refused_before_the_write(): void {
		$wide = $this->site . '/wide';
		mkdir( $wide );
		mkdir( $this->staging . $wide, 0700 );
		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			file_put_contents( $this->staging . $wide . '/' . $name, $name );
		}
		mkdir( $this->journal );
		self::settle();
		$plan  = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$apply = fn ( string $name ): bool => ( new FileChange( $plan, $this->staging, $this->journal . '/' . $name ) )->apply( $wide . '/' . $name, 'create', hash( 'sha256', $name ), null, INF );
		self::assertTrue( $apply( 'a' ) );
		// Another process adds a second spelling of a folder on the chain, in the same second.
		mkdir( $this->site . '/WIDE' );
		try {
			$apply( 'b' );
			self::fail( 'A second spelling of a folder on the chain must refuse the write.' );
		} catch ( StorageFailed $refused ) {
			self::assertStringContainsString( 'plan changed', $refused->getMessage() );
		}
		rmdir( $this->site . '/WIDE' );
		// And at once a second spelling of the next name, in the folder that the writer changed.
		touch( $wide . '/C' );
		try {
			$apply( 'c' );
			self::fail( 'A second spelling of the name must refuse the write.' );
		} catch ( StorageFailed $refused ) {
			self::assertStringContainsString( 'plan changed', $refused->getMessage() );
		}
		self::assertFileDoesNotExist( $wide . '/b' );
		self::assertFileDoesNotExist( $wide . '/c' );
		self::assertSame( 'a', file_get_contents( $wide . '/a' ) );
	}

	public function test_undo_review_keeps_entries_created_below_a_removed_directory(): void {
		$plugin = $this->site . '/plugin';
		$code   = $plugin . '/main.php';
		mkdir( $this->staging . $plugin, 0700 );
		file_put_contents( $this->staging . $code, 'plugin code' );
		file_put_contents( $this->staging . $this->path, 'new' );
		file_put_contents( $this->path, 'old' );
		$plan      = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$directory = new FileChange( $plan, $this->staging, $this->root . '/journal-directory' );
		$created   = new FileChange( $plan, $this->staging, $this->root . '/journal-created' );
		$replaced  = new FileChange( $plan, $this->staging, $this->root . '/journal-replaced' );
		self::assertTrue( $directory->apply( $plugin, 'mkdir', null, null, INF ) );
		self::assertTrue( $created->apply( $code, 'create', hash( 'sha256', 'plugin code' ), null, INF ) );
		self::assertTrue( $replaced->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		// The administrator deletes the restored plugin within the undo window.
		TempDir::remove( $plugin );
		self::assertSame( 'keep', $created->undo_action( $code, 'create' ) );
		self::assertSame( 'keep', $directory->undo_action( $plugin, 'mkdir' ) );
		self::assertSame( 'restore', $replaced->undo_action( $this->path, 'replace' ) );
		self::assertSame( self::outcome( 'unchanged', 'removed_since_apply' ), $created->undo( $code, 'create', INF ) );
		self::assertSame( self::outcome( 'unchanged', 'removed_since_apply' ), $directory->undo( $plugin, 'mkdir', INF ) );
		// Nothing was left to revert, and the journal says so for a later undo.
		self::assertSame( 'undone', json_decode( (string) file_get_contents( $this->root . '/journal-created/entry.json' ), true, flags: JSON_THROW_ON_ERROR )['phase'] );
		self::assertSame( 'already_undone', $created->undo_review( $code, 'create' )['reason'] );
		self::assertSame( self::outcome( 'restored', 'undo_journal' ), $replaced->undo( $this->path, 'replace', INF ) );
		self::assertSame( 'old', file_get_contents( $this->path ) );
		self::assertDirectoryDoesNotExist( $plugin );
	}

	/**
	 * Replaces the live file by a copy of itself under another inode, with the same bytes,
	 * permissions (or the ones given) and modification time: the state in which the undo of a
	 * later apply over the same file leaves it.
	 */
	private function rewrite_as_copy( string $path, ?int $mode = null ): void {
		clearstatcache();
		$mtime = filemtime( $path );
		$copy  = $path . '.copy';
		copy( $path, $copy );
		chmod( $copy, $mode ?? fileperms( $path ) & 07777 );
		touch( $copy, (int) $mtime );
		rename( $copy, $path );
		clearstatcache();
	}

	public function test_another_inode_with_the_installed_content_and_attributes_is_reverted(): void {
		file_put_contents( $this->path, 'old' );
		chmod( $this->path, 0640 );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$installed = fileinode( $this->path );
		// As the undo of a later apply leaves it: the bytes and attributes the apply installed,
		// its modification time among them, under a new inode.
		$this->rewrite_as_copy( $this->path );
		self::assertNotSame( $installed, fileinode( $this->path ) );
		self::assertSame(
			[
				'action' => 'restore',
				'reason' => 'installed_content',
			],
			$this->change->undo_review( $this->path, 'replace' )
		);
		self::assertSame( self::outcome( 'restored', 'installed_content' ), $this->change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'old', file_get_contents( $this->path ) );
		self::assertSame( 0640, fileperms( $this->path ) & 0777 );
	}

	public function test_another_inode_with_other_bytes_size_or_permissions_is_kept(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 0644 ) );
		$mtime  = (int) filemtime( $this->path );
		$reason = fn (): string => $this->change->undo_review( $this->path, 'create' )['reason'];
		$this->rewrite_as_copy( $this->path, 0600 );
		self::assertSame( 'changed_since_apply', $reason() );
		// A file system that reuses inode numbers can give this copy the recorded one, which
		// was freed by the copy before: only the reason depends on that.
		$this->rewrite_as_copy( $this->path, 0644 );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		// Each with the installed permissions and modification time: only the bytes differ.
		foreach ( [ 'NEW', 'newer' ] as $bytes ) {
			file_put_contents( $this->path . '.copy', $bytes );
			chmod( $this->path . '.copy', 0644 );
			touch( $this->path . '.copy', $mtime );
			rename( $this->path . '.copy', $this->path );
			self::assertSame( 'changed_since_apply', $reason(), $bytes );
		}
		// A directory in its place is a change as well.
		unlink( $this->path );
		mkdir( $this->path );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'remove' ) );
		self::assertDirectoryExists( $this->path );
	}

	public function test_a_file_with_the_installed_bytes_and_a_later_modification_time_is_kept(): void {
		$kept = [
			'action' => 'keep',
			'reason' => 'changed_since_apply',
		];
		// The staged copy has the time of the snapshot, as a restore leaves it; the apply
		// installs a copy of its own, written at the time of the apply.
		file_put_contents( $this->staging . $this->path, 'photo' );
		touch( $this->staging . $this->path, 1600000000 );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'photo' ), null, INF, 0644 ) );
		clearstatcache();
		$mtime = (int) filemtime( $this->path );
		self::assertNotSame( 1600000000, $mtime );
		// Deleted and uploaded again with the same bytes and permissions: a new file, which a
		// file system that reuses inode numbers can give the recorded one, with the time of
		// the upload, or with the time of the snapshot as a copy that keeps times has it.
		foreach ( [ $mtime + 1, 1600000000 ] as $time ) {
			unlink( $this->path );
			file_put_contents( $this->path, 'photo' );
			chmod( $this->path, 0644 );
			touch( $this->path, $time );
			self::assertSame( $kept, $this->change->undo_review( $this->path, 'create' ), (string) $time );
			self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'remove' ) );
			self::assertSame( 'photo', file_get_contents( $this->path ) );
		}
		// The same file with the installed time is what the apply left there.
		touch( $this->path, $mtime );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );

		// A replaced file whose copy, or whose own inode, gets a later time.
		$replaced = $this->site . '/settings.php';
		$journal  = $this->root . '/journal-replaced';
		$change   = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal, $journal ] ), $this->staging, $journal );
		file_put_contents( $replaced, 'old' );
		file_put_contents( $this->staging . $replaced, 'new' );
		self::assertTrue( $change->apply( $replaced, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		clearstatcache();
		$mtime     = (int) filemtime( $replaced );
		$installed = fileinode( $replaced );
		// The installed inode first: the copy replaces it.
		foreach ( [ 'the installed inode', 'another inode' ] as $case ) {
			if ( 'another inode' === $case ) {
				$this->rewrite_as_copy( $replaced );
			}
			touch( $replaced, $mtime + 1 );
			clearstatcache();
			self::assertSame( 'the installed inode' === $case, fileinode( $replaced ) === $installed, $case );
			self::assertSame( $kept, $change->undo_review( $replaced, 'replace' ), $case );
			self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $change->undo( $replaced, 'replace', INF, 'restore' ), $case );
			self::assertSame( 'new', file_get_contents( $replaced ) );
			self::assertSame( 'old', file_get_contents( $journal . '/original' ) );
		}
	}

	public function test_an_interrupted_undo_of_a_later_apply_leaves_the_first_undoable(): void {
		file_put_contents( $this->path, 'original' );
		file_put_contents( $this->staging . $this->path, 'first' );
		$staged = $this->root . '/files-second';
		mkdir( $staged . $this->site, 0700, true );
		file_put_contents( $staged . $this->path, 'second' );
		// The worker of the later apply's undo stops before its rename, or after it and before
		// the record that completes the entry.
		foreach ( [ [ 'journal', 'restore' ], [ 'rename', $this->path ] ] as $number => [ $operation, $subject ] ) {
			$journals = [ $this->root . '/first-' . $number, $this->root . '/second-' . $number ];
			$plan     = new ApplyPlan( [ $this->site ], $journals );
			$first    = new FileChange( $plan, $this->staging, $journals[0] );
			self::assertTrue( $first->apply( $this->path, 'replace', hash( 'sha256', 'first' ), hash( 'sha256', 'original' ), INF ) );
			clearstatcache();
			$installed = [ fileinode( $this->path ), (int) filemtime( $this->path ) ];
			self::assertTrue( ( new FileChange( $plan, $staged, $journals[1] ) )->apply( $this->path, 'replace', hash( 'sha256', 'second' ), hash( 'sha256', 'first' ), INF ) );
			// The later apply ran an hour after the first: its copy of the first one's file is from then.
			touch( $journals[1] . '/original', $installed[1] + 3600 );
			$kill = static function ( string $done, string $on ) use ( $operation, $subject ): void {
				if ( $done === $operation && $on === $subject ) {
					throw new \DomainException( 'The worker was killed.' );
				}
			};
			try {
				( new FileChange( $plan, $staged, $journals[1], null, 'later-undo', $kill ) )->undo( $this->path, 'replace', INF, 'restore' );
				self::fail( 'The undo of the later apply must stop at ' . $operation . '.' );
			} catch ( \DomainException ) {
				$this->addToAssertionCount( 1 );
			}
			// Before the rename the later apply's file is live, after it the later apply's copy of
			// the first apply's file, with the time that the first apply installed.
			self::assertSame( 0 === $number ? 'changed_since_apply' : 'installed_content', $first->undo_review( $this->path, 'replace' )['reason'], $operation );
			$second = new FileChange( $plan, $staged, $journals[1], null, 'later-undo' );
			self::assertSame( 'restored', $second->undo( $this->path, 'replace', INF, 'restore' )['outcome'], $operation );
			clearstatcache();
			self::assertSame( 'first', file_get_contents( $this->path ) );
			self::assertNotSame( $installed[0], fileinode( $this->path ) );
			self::assertSame( $installed[1], filemtime( $this->path ) );
			self::assertSame( self::outcome( 'restored', 'installed_content' ), $first->undo( $this->path, 'replace', INF, 'restore' ), $operation );
			self::assertSame( 'original', file_get_contents( $this->path ) );
		}
	}

	public function test_a_second_link_is_a_change_of_the_installed_file_and_of_any_other(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 0644 ) );
		$second = $this->site . '/second-link';
		$review = fn (): array => $this->change->undo_review( $this->path, 'create' );
		$kept   = [
			'action' => 'keep',
			'reason' => 'changed_since_apply',
		];
		// The installed inode itself: the apply left it with one link. Its own state decides,
		// not the site policy, which refuses every file with a second link as a type conflict.
		link( $this->path, $second );
		self::assertSame( $kept, $review() );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'create', INF, 'remove' ) );
		self::assertSame( 'new', file_get_contents( $second ) );
		unlink( $second );
		self::assertSame( 'remove', $review()['action'] );
		// Another inode with the installed bytes and attributes, made while the installed file
		// still exists so that it cannot get the recorded number, and linked twice.
		file_put_contents( $this->path . '.next', 'new' );
		chmod( $this->path . '.next', 0644 );
		clearstatcache();
		touch( $this->path . '.next', (int) filemtime( $this->path ) );
		link( $this->path . '.next', $second );
		rename( $this->path . '.next', $this->path );
		self::assertSame( $kept, $review() );
		unlink( $second );
		self::assertSame( 'installed_content', $review()['reason'] );
	}

	public function test_a_second_link_made_before_the_rename_keeps_the_file(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$observer = function ( string $operation, string $subject ): void {
			if ( 'journal' === $operation && 'restore' === $subject ) {
				link( $this->path, $this->site . '/second-link' );
			}
		};
		$change   = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'second-link', $observer );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'new', file_get_contents( $this->path ) );
		self::assertSame( 'new', file_get_contents( $this->site . '/second-link' ) );
	}

	public function test_an_entry_recorded_without_its_installed_size_counts_only_its_own_inode(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$file   = $this->journal . '/entry.json';
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( filesize( $this->path ), $record['size'] );
		// The record an earlier version wrote.
		unset( $record['size'] );
		file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'undo_journal', $this->change->undo_review( $this->path, 'replace' )['reason'] );
		$this->rewrite_as_copy( $this->path );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'new', file_get_contents( $this->path ) );
		$record['size'] = 'three';
		file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
		$this->expectException( StorageFailed::class );
		$this->change->undo_review( $this->path, 'replace' );
	}

	public function test_a_large_copy_is_hashed_in_bounded_ticks_before_it_is_reverted(): void {
		$new = str_repeat( 'n', 4 * ChunkDigest::BLOCK + 1 );
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, $new );
		$cursor = [];
		$source = ChunkDigest::advance( $this->staging . $this->path, INF, $cursor );
		self::assertNotNull( $source );
		self::assertTrue( $this->change->apply( $this->path, 'replace', $source, hash( 'sha256', 'old' ), INF ) );
		$this->rewrite_as_copy( $this->path );
		// Let the copy's change second pass, so that the prefix read in one tick counts in the next.
		sleep( 1 );
		$ticks = 0;
		do {
			$change  = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'undo' );
			$outcome = $change->undo( $this->path, 'replace', microtime( true ) + 0.0001, 'restore' );
			++$ticks;
		} while ( null === $outcome && $ticks < 100 );
		self::assertSame( self::outcome( 'restored', 'installed_content' ), $outcome );
		self::assertGreaterThan( 2, $ticks );
		self::assertSame( 'old', file_get_contents( $this->path ) );
	}

	public function test_the_verified_inode_is_the_one_that_the_undo_replaces(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$mtime = (int) filemtime( $this->path );
		$edits = [
			// Another process writes into the file while the undo prepares its rename.
			fn () => file_put_contents( $this->path, 'edit' ),
			// Or puts another copy of the same bytes there: not the inode that was verified.
			fn () => $this->rewrite_as_copy( $this->path ),
		];
		foreach ( $edits as $number => $edit ) {
			$observer = static function ( string $operation, string $subject ) use ( $edit ): void {
				if ( 'journal' === $operation && 'restore' === $subject ) {
					$edit();
				}
			};
			$change   = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'undo-' . $number, $observer );
			self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $change->undo( $this->path, 'replace', INF, 'restore' ), 'Edit ' . $number );
			self::assertSame( 0 === $number ? 'edit' : 'new', file_get_contents( $this->path ) );
			self::assertSame( 'old', file_get_contents( $this->journal . '/original' ) );
			file_put_contents( $this->path, 'new' );
			touch( $this->path, $mtime );
			// Restore the installed content for the next case; only the inode is new.
			$this->rewrite_as_copy( $this->path, 0600 );
			chmod( $this->path, fileperms( $this->journal . '/original' ) & 0777 );
		}
		self::assertSame( self::outcome( 'restored', 'installed_content' ), $this->change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'old', file_get_contents( $this->path ) );
	}

	public function test_a_later_apply_over_the_same_file_and_its_undo_leave_the_first_undoable(): void {
		file_put_contents( $this->path, 'original' );
		file_put_contents( $this->staging . $this->path, 'first' );
		$plan  = new ApplyPlan( [ $this->site ], [ $this->journal, $this->root . '/second' ] );
		$first = new FileChange( $plan, $this->staging, $this->journal );
		self::assertTrue( $first->apply( $this->path, 'replace', hash( 'sha256', 'first' ), hash( 'sha256', 'original' ), INF ) );
		$staged = $this->root . '/files-second';
		mkdir( $staged . $this->site, 0700, true );
		file_put_contents( $staged . $this->path, 'second' );
		$second = new FileChange( $plan, $staged, $this->root . '/second' );
		self::assertTrue( $second->apply( $this->path, 'replace', hash( 'sha256', 'second' ), hash( 'sha256', 'first' ), INF ) );
		self::assertSame( 'changed_since_apply', $first->undo_review( $this->path, 'replace' )['reason'] );
		self::assertSame( self::outcome( 'restored', 'undo_journal' ), $second->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'first', file_get_contents( $this->path ) );
		// The second undo published its own copy of the first apply's file, a new inode.
		self::assertSame( self::outcome( 'restored', 'installed_content' ), $first->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'original', file_get_contents( $this->path ) );
	}

	public function test_an_edit_in_the_second_of_the_check_is_found_before_the_rename(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		// The same size, and usually the same second: the bytes tell what the times may not.
		$observer = function ( string $operation, string $subject ): void {
			if ( 'journal' === $operation && 'restore' === $subject ) {
				$handle = fopen( $this->path, 'r+b' );
				fwrite( $handle, 'NEW' );
				fclose( $handle );
			}
		};
		$change   = new FileChange( new ApplyPlan( [ $this->site ], [ $this->journal ] ), $this->staging, $this->journal, null, 'same-second', $observer );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'NEW', file_get_contents( $this->path ) );
		self::assertSame( 'old', file_get_contents( $this->journal . '/original' ) );
	}

	public function test_an_entry_stopped_before_its_publication_has_nothing_to_undo(): void {
		$plan    = new ApplyPlan( [ $this->site ], [ $this->journal ] );
		$kill    = static function ( string $operation, string $subject ): void {
			if ( 'journal' === $operation && 'ready' === $subject ) {
				throw new \DomainException( 'The worker was killed.' );
			}
		};
		$replace = $this->site . '/existing.php';
		$folder  = $this->site . '/folder';
		file_put_contents( $this->staging . $this->path, 'new' );
		file_put_contents( $replace, 'old' );
		file_put_contents( $this->staging . $replace, 'newer' );
		mkdir( $this->staging . $folder );
		$entries = [
			'create'  => [ $this->path, hash( 'sha256', 'new' ), null, 'remove' ],
			'replace' => [ $replace, hash( 'sha256', 'newer' ), hash( 'sha256', 'old' ), 'restore' ],
			'mkdir'   => [ $folder, null, null, 'remove' ],
		];
		foreach ( $entries as $action => [ $path, $source, $original, $reviewed ] ) {
			$journal = $this->root . '/journal-' . $action;
			try {
				( new FileChange( $plan, $this->staging, $journal, null, 'apply', $kill ) )->apply( $path, $action, $source, $original, INF );
				self::fail( 'The worker must stop after its ready record.' );
			} catch ( \DomainException ) {
				// The prepared entry is still private.
				$this->addToAssertionCount( 1 );
			}
			$change = new FileChange( $plan, $this->staging, $journal );
			self::assertSame(
				[
					'action' => 'keep',
					'reason' => 'not_applied',
				],
				$change->undo_review( $path, $action ),
				$action
			);
			self::assertSame( self::outcome( 'unchanged', 'not_applied' ), $change->undo( $path, $action, INF, $reviewed ), $action );
		}
		self::assertFileDoesNotExist( $this->path );
		self::assertSame( 'old', file_get_contents( $replace ) );
		self::assertDirectoryDoesNotExist( $folder );
	}

	public function test_only_the_retained_inode_counts_as_put_back_by_an_interrupted_undo(): void {
		file_put_contents( $this->path, 'old' );
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		$file   = $this->journal . '/entry.json';
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		// An undo stopped after its restore record, and someone else put the old bytes back.
		$record['phase'] = 'restore';
		file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
		file_put_contents( $this->path . '.copy', 'old' );
		chmod( $this->path . '.copy', fileperms( $this->path ) & 0777 );
		rename( $this->path . '.copy', $this->path );
		self::assertSame( 'changed_since_apply', $this->change->undo_review( $this->path, 'replace' )['reason'] );
		// Nor do the old bytes count before an undo began.
		$record['phase'] = 'done';
		file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'changed_since_apply', $this->change->undo_review( $this->path, 'replace' )['reason'] );
		self::assertSame( self::outcome( 'kept', 'changed_since_apply' ), $this->change->undo( $this->path, 'replace', INF, 'restore' ) );
		self::assertSame( 'done', json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR )['phase'] );
	}

	public function test_a_damaged_record_of_the_installed_size_is_refused(): void {
		// An empty file: a size of zero is a valid one.
		file_put_contents( $this->staging . $this->path, '' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', '' ), null, INF ) );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		$file   = $this->journal . '/entry.json';
		$record = json_decode( (string) file_get_contents( $file ), true, flags: JSON_THROW_ON_ERROR );
		self::assertSame( 0, $record['size'] );
		$damaged = [ 'text', -1, '0', 1.5, [ 'size' => 0 ], true ];
		foreach ( $damaged as $number => $size ) {
			$record['size'] = $size;
			file_put_contents( $file, json_encode( $record, JSON_THROW_ON_ERROR ) );
			try {
				$this->change->undo_review( $this->path, 'create' );
				self::fail( 'Damaged size ' . $number . ' must not be read.' );
			} catch ( StorageFailed $refused ) {
				self::assertStringContainsString( 'invalid installed size', $refused->getMessage() );
			}
		}
		self::assertFileExists( $this->path );
	}

	public function test_undo_review_keeps_a_replacement_whose_directory_was_removed_or_linked(): void {
		$folder = $this->site . '/folder';
		$file   = $folder . '/settings.json';
		mkdir( $folder );
		mkdir( $this->staging . $folder, 0700 );
		file_put_contents( $file, 'old' );
		file_put_contents( $this->staging . $file, 'new' );
		self::assertTrue( $this->change->apply( $file, 'replace', hash( 'sha256', 'new' ), hash( 'sha256', 'old' ), INF ) );
		TempDir::remove( $folder );
		self::assertSame( 'deleted_since_apply', $this->change->undo_review( $file, 'replace' )['reason'] );
		// Undo cannot restore a replaced file into a removed directory: the entry is kept.
		self::assertSame( self::outcome( 'kept', 'deleted_since_apply' ), $this->change->undo( $file, 'replace', INF, 'restore' ) );
		self::assertDirectoryDoesNotExist( $folder );
		// A link in place of the removed directory is not the recorded parent either.
		mkdir( $this->root . '/elsewhere' );
		file_put_contents( $this->root . '/elsewhere/settings.json', 'new' );
		symlink( $this->root . '/elsewhere', $folder );
		self::assertSame(
			[
				'action' => 'keep',
				'reason' => 'parent_changed',
			],
			$this->change->undo_review( $file, 'replace' )
		);
		self::assertSame( self::outcome( 'kept', 'parent_changed' ), $this->change->undo( $file, 'replace', INF, 'restore' ) );
		self::assertSame( 'new', file_get_contents( $this->root . '/elsewhere/settings.json' ) );
	}
}
