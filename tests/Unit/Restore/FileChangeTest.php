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
		self::assertTrue( $this->change->undo( $this->path, 'replace', INF ) );
		self::assertTrue( $this->change->undo( $this->path, 'replace', INF ) );
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
					: $change->undo( $this->path, 'replace', $deadline );
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
		self::assertTrue( $this->change->undo( $this->path, 'replace', INF ) );
		$record['phase'] = 'restore';
		file_put_contents( $file, json_encode( $record ) );
		self::assertTrue( $this->change->undo( $this->path, 'replace', INF ) );
		self::assertSame( 'old', file_get_contents( $this->path ) );
	}

	public function test_undo_preserves_changes_made_after_apply(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF ) );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'create' ) );
		file_put_contents( $this->path, 'edited' );
		self::assertSame( 'refuse', $this->change->undo_action( $this->path, 'create' ) );
		try {
			$this->change->undo( $this->path, 'create', INF );
			self::fail( 'Undo must preserve a subsequent edit.' );
		} catch ( StorageFailed ) {
			self::assertSame( 'edited', file_get_contents( $this->path ) );
		}
	}

	public function test_create_and_undo_only_touch_the_selected_file(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		file_put_contents( $this->site . '/keep', 'unrelated' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 06755 ) );
		self::assertSame( 0755, fileperms( $this->path ) & 07777 );
		self::assertTrue( $this->change->undo( $this->path, 'create', INF ) );
		self::assertFileDoesNotExist( $this->path );
		self::assertSame( 'unrelated', file_get_contents( $this->site . '/keep' ) );
	}

	public function test_an_unpublished_partial_change_can_be_cancelled_while_the_tree_remains_intact(): void {
		file_put_contents( $this->staging . $this->path, str_repeat( 'x', 2000000 ) );
		$source = hash_file( 'sha256', $this->staging . $this->path );
		self::assertFalse( $this->change->apply( $this->path, 'create', $source, null, 0 ) );
		self::assertTrue( $this->change->undo( $this->path, 'create', INF ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function test_undo_preserves_later_permission_changes_even_when_the_bytes_match(): void {
		file_put_contents( $this->staging . $this->path, 'new' );
		self::assertTrue( $this->change->apply( $this->path, 'create', hash( 'sha256', 'new' ), null, INF, 0000 ) );
		self::assertSame( 0400, fileperms( $this->path ) & 0777 );
		chmod( $this->path, 0600 );
		self::assertSame( 'refuse', $this->change->undo_action( $this->path, 'create' ) );
	}

	public function test_undo_retains_a_directory_with_a_new_unrelated_entry(): void {
		mkdir( $this->staging . $this->path );
		self::assertTrue( $this->change->apply( $this->path, 'mkdir', null, null, INF ) );
		file_put_contents( $this->path . '/later', 'later' );
		try {
			$this->change->undo( $this->path, 'mkdir', INF );
			self::fail( 'Undo must preserve the new directory entry.' );
		} catch ( StorageFailed ) {
			self::assertSame( 'later', file_get_contents( $this->path . '/later' ) );
		}
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

	public function test_a_directory_is_created_and_undone_only_if_owned(): void {
		mkdir( $this->staging . $this->path );
		self::assertTrue( $this->change->apply( $this->path, 'mkdir', null, null, INF ) );
		self::assertDirectoryExists( $this->path );
		self::assertSame( 'remove', $this->change->undo_action( $this->path, 'mkdir' ) );
		self::assertTrue( $this->change->undo( $this->path, 'mkdir', INF ) );
		self::assertDirectoryDoesNotExist( $this->path );
	}
}
