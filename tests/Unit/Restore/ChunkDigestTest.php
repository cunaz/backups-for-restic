<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Restore\ChunkDigest;
use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ChunkDigestTest extends TestCase {

	public function test_restarts_produce_the_same_size_and_order_bound_digest(): void {
		$dir = TempDir::create( 'chunk-digest' );
		try {
			$path = $dir . '/original';
			file_put_contents( $path, str_repeat( 'a', ChunkDigest::BLOCK ) . str_repeat( 'b', ChunkDigest::BLOCK ) . 'end' );
			$this->wait_for_stable_inode( $path );
			$cursor = [];
			$once   = ChunkDigest::advance( $path, PHP_FLOAT_MAX, $cursor );
			$cursor = [];
			self::assertNull( ChunkDigest::advance( $path, 0.0, $cursor ) );
			self::assertSame( ChunkDigest::BLOCK, $cursor['offset'] );
			$cursor = json_decode( json_encode( $cursor, JSON_THROW_ON_ERROR ), true, flags: JSON_THROW_ON_ERROR );
			self::assertNull( ChunkDigest::advance( $path, 0.0, $cursor ) );
			self::assertSame( $once, ChunkDigest::advance( $path, 0.0, $cursor ) );
			$cursor = [];
			file_put_contents( $path, str_repeat( 'b', ChunkDigest::BLOCK ) . str_repeat( 'a', ChunkDigest::BLOCK ) . 'end' );
			self::assertNotSame( $once, ChunkDigest::advance( $path, PHP_FLOAT_MAX, $cursor ) );
			$cursor = [];
			ChunkDigest::advance( $path, 0.0, $cursor );
			file_put_contents( $path, 'truncated' );
			$this->expectException( StorageFailed::class );
			ChunkDigest::advance( $path, 0.0, $cursor );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/** Wait until a later inode write must change PHP's whole-second ctime. */
	private function wait_for_stable_inode( string $path ): void {
		clearstatcache( true, $path );
		while ( time() <= filectime( $path ) ) {
			usleep( 10000 );
		}
	}

	public function test_a_prefix_read_in_the_change_second_is_never_trusted_on_resume(): void {
		$dir = TempDir::create( 'chunk-current' );
		try {
			$path = $dir . '/current';
			file_put_contents( $path, str_repeat( 'a', ChunkDigest::BLOCK * 2 ) );
			$cursor = [];
			self::assertNull( ChunkDigest::advance( $path, 0.0, $cursor ) );
			// Exercise recovery of a tentative prefix even when scheduling crosses
			// a wall-clock second during the first read.
			$cursor['stable'] = false;
			$cursor['chain']  = str_repeat( 'f', 64 );
			$this->wait_for_stable_inode( $path );
			$restart = [];
			self::assertSame( ChunkDigest::advance( $path, PHP_FLOAT_MAX, $restart ), ChunkDigest::advance( $path, PHP_FLOAT_MAX, $cursor ) );
		} finally {
			TempDir::remove( $dir );
		}
	}
}
