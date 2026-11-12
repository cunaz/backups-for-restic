<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Engine\TreeCache;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Support\SystemClock;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\Vectors;

final class SnapshotReaderConformanceTest extends TestCase {

	public function test_browses_directories_written_by_restic(): void {
		$this->with_restic_snapshot(
			static function ( Reader $reader, Id $id, string $site ): void {
				$nodes = $reader->directory( $id, $site . '/nested' );
				self::assertCount( 1, $nodes );
				self::assertSame( 'quote " and ü.txt', $nodes[0]->name );
				self::assertSame( 5, $nodes[0]->size );
				self::assertSame( NodeType::Symlink, $reader->directory( $id, $site )[0]->type );
			}
		);
	}

	public function test_reads_a_legacy_manifest_written_by_restic(): void {
		$this->with_restic_snapshot(
			static function ( Reader $reader, Id $id, string $site, string $manifest ): void {
				$read = $reader->manifest( $id );
				self::assertSame( $site . '/spool/manifest.json', $read['path'] ?? null );
				self::assertSame( json_decode( $manifest, true, 64, JSON_THROW_ON_ERROR ), $read['data'] ?? null );
			}
		);
	}

	/** @param \Closure(Reader, Id, string, string): void $check Behaviour verified against the fixture. */
	private function with_restic_snapshot( \Closure $check ): void {
		if ( ! RestServer::available() ) {
			self::markTestSkipped( 'The restic and rest-server conformance tools are required.' );
		}
		$server = new RestServer();
		$site   = TempDir::create( 'reader-oracle-site' );
		$cache  = TempDir::create( 'reader-oracle-cache' );
		try {
			mkdir( $site . '/spool', 0700 );
			mkdir( $site . '/nested', 0700 );
			$manifest = (string) file_get_contents( Vectors::fixtures() . '/site-manifest.json' );
			file_put_contents( $site . '/spool/manifest.json', $manifest );
			file_put_contents( $site . '/nested/quote " and ü.txt', 'hello' );
			symlink( '/outside-the-snapshot', $site . '/link' );
			$cli = new ResticCli( $server, 'snapshot-reader', 'test password' );
			$cli->must( [ 'init', '--repository-version', '1' ] );
			$cli->must( [ 'backup', '--host', 'source.example.com', '--tag', 'backups-for-restic', '--tag', 'site:0123456789ab', $site ] );
			$repository = Repository::open( $server->backend( 'snapshot-reader' ), MasterKey::from_json( $cli->must( [ 'cat', 'masterkey' ] ) ), new SystemRandom() );
			$reader     = new Reader( $repository, new Identity( 'destination.example.com' ), new SystemClock(), new IndexCache( $cache, 1048576 ), new TreeCache( $cache, 1048576 ) );
			$snapshots  = $reader->snapshots( 'source.example.com', [ 'backups-for-restic', 'site:0123456789ab' ] );
			self::assertCount( 1, $snapshots );
			$name = array_key_first( $snapshots );
			self::assertIsString( $name );
			$check( $reader, Id::from_hex( $name ), $site, $manifest );
			self::assertSame( [], $repository->backend()->list( ObjectType::Locks ) );
			self::assertStringContainsString( 'no errors were found', $cli->must( [ 'check', '--read-data' ] ) );
		} finally {
			$server->stop();
			TempDir::remove( $site );
			TempDir::remove( $cache );
		}
	}
}
