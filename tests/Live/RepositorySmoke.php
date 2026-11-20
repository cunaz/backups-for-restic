<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Live;

use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupPlan;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Locking;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Restore\RestoreRun;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\CurlHttpClient;
use BackupsForRestic\Tests\Support\TempDir;
use RuntimeException;

final class RepositorySmoke {

	private const string TAG     = 'backups-for-restic-live-smoke-v1';
	private const string PROGRAM = 'backups-for-restic/live-smoke';

	/** A fixed stage name is safe to display even when an exception contains credentials. */
	public string $stage = 'configuration';

	/**
	 * @param array{url:string,username:string,rest_password:string,repository_password:string,repository_id:string,ca_pem?:string} $fixture
	 */
	public function run( #[\SensitiveParameter] array $fixture, bool $append_only ): void {
		$work = TempDir::create( 'live-smoke' );
		try {
			$ca = null;
			if ( isset( $fixture['ca_pem'] ) ) {
				$ca = $work . '/ca.pem';
				file_put_contents( $ca, $fixture['ca_pem'] );
			}
			$remote      = new RestBackend( new CurlHttpClient( $ca, 30 ), $fixture['url'], $fixture['username'], $fixture['rest_password'], self::PROGRAM, 2 );
			$backend     = new SmokeBackend( $remote );
			$random      = new SystemRandom();
			$this->stage = 'unlock and verify repository ID';
			$repository  = Repository::open( $backend, Repository::unlock( $backend, $fixture['repository_password'], $random ), $random );
			$backend->bind( $repository, $fixture['repository_id'] );
			$this->stage = 'inspect dedicated fixture and v2 listing';
			$original    = $backend->inventory();
			self::verify_history( $repository, $original[ ObjectType::Snapshots->value ], $append_only );
			if ( [] !== $backend->list( ObjectType::Locks ) ) {
				throw new RuntimeException( 'The dedicated fixture already has a lock.' );
			}
			$tag         = 'run-' . bin2hex( random_bytes( 16 ) );
			$identity    = new Identity( 'bfr-smoke-' . substr( $tag, 4 ), 'fixture', 0, 0, self::PROGRAM );
			$clock       = new SystemClock();
			$this->stage = 'verify expected append-only policy';
			if ( $backend->probe() !== ( $append_only ? AppendOnlyStatus::AppendOnly : AppendOnlyStatus::DeletionsAllowed ) ) {
				throw new RuntimeException( 'The fixture mode is different from its configured mode.' );
			}
			$source      = $work . '/source';
			$backup_dir  = $work . '/backup';
			$restore_dir = $work . '/restore';
			$target      = $work . '/target';
			foreach ( [ $source, $backup_dir, $restore_dir, $target ] as $path ) {
				mkdir( $path, 0700 );
			}
			$payload = random_bytes( 32768 );
			file_put_contents( $source . '/canary.bin', $payload );
			$backup  = null;
			$restore = null;
			$success = false;
			try {
				$this->stage = 'backup under a repository lock';
				$plan        = new BackupPlan( [ $source ], [ self::TAG, 'fixture-' . $repository->config()->id, $tag ] );
				$backup      = BackupRun::start( $repository, $plan, $backup_dir, $identity, $clock );
				self::finish( $backup );
				$id = $backup->snapshot_id();
				if ( null === $id || [] !== $backup->warnings() || ! in_array( $id->hex(), $backend->written( ObjectType::Snapshots ), true ) || [] !== $backend->written( ObjectType::Locks ) ) {
					throw new RuntimeException( 'The smoke backup lacks its published snapshot or retained a lock.' );
				}
				$backup      = null;
				$this->stage = 'verify v2 size and ranged pack bytes';
				$packs       = $backend->written( ObjectType::Data );
				$sizes       = $backend->list( ObjectType::Data );
				$pack        = $packs[0] ?? '';
				$size        = $sizes[ $pack ] ?? 0;
				if ( $size < 16 || $size > 4194304 ) {
					throw new RuntimeException( 'The fresh pack has no bounded REST v2 size.' );
				}
				$bytes = $backend->load( ObjectType::Data, $pack );
				if ( strlen( $bytes ) !== $size || hash( 'sha256', $bytes ) !== $pack || $backend->load( ObjectType::Data, $pack, 7, 9 ) !== substr( $bytes, 7, 9 ) ) {
					throw new RuntimeException( 'The server returned incorrect ranged data.' );
				}
				$this->stage = 'restore and compare original bytes';
				$restore     = RestoreRun::start( $repository, $id, $target, $restore_dir, '/', $identity, $clock );
				self::finish( $restore );
				if ( file_get_contents( $target . $source . '/canary.bin' ) !== $payload || [] !== $backend->written( ObjectType::Locks ) ) {
					throw new RuntimeException( 'Restored bytes or lock cleanup differ.' );
				}
				$restore = null;
				if ( $append_only ) {
					$this->stage = 'verify refused forget of our own snapshot';
					try {
						Forget::delete( $repository, [ $id->hex() ] );
						throw new RuntimeException( 'The append-only fixture allowed removal.' );
					} catch ( AppendOnlyRefused ) {
						if ( null === $backend->size( ObjectType::Snapshots, $id->hex() ) ) {
							throw new RuntimeException( 'The refused snapshot removal still changed the repository.' );
						}
					}
				}
				$success = true;
			} finally {
				// These aborts release only lock names recorded by this process.
				$failed_at   = $this->stage;
				$this->stage = 'release owned locks and safely clean fixture objects';
				$restore?->abort();
				$backup?->abort();
				if ( ! $append_only ) {
					$lock = new Locking( $repository, $identity, $clock, random_int( 100000, 2147483647 ) );
					$held = $lock->acquire( true );
					try {
						$backend->require_original_objects( $original );
						Forget::delete( $repository, $backend->written( ObjectType::Snapshots ) );
						foreach ( [ ObjectType::Index, ObjectType::Data ] as $type ) {
							foreach ( $backend->written( $type ) as $name ) {
								$backend->delete( $type, $name );
							}
						}
						if ( $backend->inventory() !== $original ) {
							throw new RuntimeException( 'The normal fixture did not return to its original inventory.' );
						}
					} finally {
						$lock->release( $held );
					}
				}
				if ( [] !== $backend->list( ObjectType::Locks ) ) {
					throw new RuntimeException( 'A lock remains after the smoke run.' );
				}
				$this->stage = $success ? 'completed' : $failed_at;
			}
		} finally {
			TempDir::remove( $work );
		}
	}

	/**
	 * A normal fixture starts without snapshots. Append-only fixtures can only accumulate
	 * the authenticated snapshots produced by previous runs for their dedicated config ID.
	 *
	 * @param array<string,int> $snapshots REST v2 snapshot inventory.
	 */
	private static function verify_history( Repository $repository, array $snapshots, bool $append_only ): void {
		if ( ! $append_only && [] !== $snapshots ) {
			throw new RuntimeException( 'The normal repository is not an empty smoke fixture.' );
		}
		foreach ( $snapshots as $name => $size ) {
			if ( $size <= 0 || $size > 32768 ) {
				throw new RuntimeException( 'Unexpected existing snapshot in the dedicated smoke repository.' );
			}
			$snapshot = Snapshot::from_json( $repository->load_unpacked( ObjectType::Snapshots, Id::from_hex( $name ) ) );
			if ( self::PROGRAM !== $snapshot->program_version || ! $snapshot->has_tags( [ self::TAG, 'fixture-' . $repository->config()->id ] ) || 1 !== preg_match( '/\Abfr-smoke-[0-9a-f]{32}\z/', $snapshot->hostname ) ) {
				throw new RuntimeException( 'The repository contains an unrelated snapshot.' );
			}
		}
	}

	private static function finish( BackupRun|RestoreRun $run ): void {
		$deadline = microtime( true ) + 300;
		do {
			if ( $run->advance( min( $deadline, microtime( true ) + 10 ) ) ) {
				return;
			}
		} while ( microtime( true ) < $deadline );
		throw new RuntimeException( 'The fixture job exceeded its execution window.' );
	}
}
