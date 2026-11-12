<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\CheckpointLock;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

/** Cleanup identifies ownership without interpreting a checkpoint's work state. */
final class CheckpointLockTest extends TestCase {

	/** @return iterable<string, array{string}> */
	public static function unusable_checkpoints(): iterable {
		yield 'truncated JSON' => [ '{"pid":424242' ];
		yield 'non-object' => [ '[424242]' ];
		yield 'missing number' => [ '{"version":99999}' ];
		yield 'zero' => [ '{"pid":0}' ];
		yield 'negative number' => [ '{"pid":-1}' ];
		yield 'too large' => [ '{"pid":2147483648}' ];
		yield 'numeric string' => [ '{"pid":"424242"}' ];
		yield 'floating point' => [ '{"pid":424242.0}' ];
		yield 'boolean' => [ '{"pid":true}' ];
	}

	#[DataProvider( 'unusable_checkpoints' )]
	public function test_an_unusable_owner_record_releases_no_lock( string $json ): void {
		$work       = TempDir::create( 'checkpoint-lock' );
		$identity   = new Identity( 'example.com' );
		$clock      = new FrozenClock();
		$repository = Repository::initialize( new InMemoryBackend(), 'cleanup password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$id         = ( new Locking( $repository, $identity, $clock, 424242 ) )->acquire( false );
		file_put_contents( $work . '/state.json', $json );
		try {
			try {
				CheckpointLock::release( $repository, $work . '/state.json', $identity, $clock );
				self::fail( 'Cleanup must not guess which run owns a lock.' );
			} catch ( StorageFailed ) {
				self::assertSame( [ $id->hex() ], array_keys( $repository->backend()->list( ObjectType::Locks ) ) );
			}
		} finally {
			TempDir::remove( $work );
		}
	}

	public function test_the_same_run_number_on_another_host_is_left_alone(): void {
		$work       = TempDir::create( 'checkpoint-lock' );
		$identity   = new Identity( 'example.com' );
		$clock      = new FrozenClock();
		$repository = Repository::initialize( new InMemoryBackend(), 'cleanup password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		( new Locking( $repository, $identity, $clock, 424242 ) )->acquire( false );
		$other = ( new Locking( $repository, new Identity( 'another.example.com' ), $clock, 424242 ) )->acquire( false );
		file_put_contents( $work . '/state.json', '{"version":99999,"pid":424242,"lock":""}' );
		try {
			CheckpointLock::release( $repository, $work . '/state.json', $identity, $clock );
			self::assertSame( [ $other->hex() ], array_keys( $repository->backend()->list( ObjectType::Locks ) ) );
		} finally {
			TempDir::remove( $work );
		}
	}
}
