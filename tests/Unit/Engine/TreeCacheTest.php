<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\BlobStore;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\IndexCache;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\TreeCache;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Storage\CacheDirectory;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

final class TreeCacheTest extends TestCase {

	private Repository $repository;
	private string $directory;

	protected function setUp(): void {
		$this->repository = Repository::initialize( new InMemoryBackend(), 'cache test', new Identity( 'example.com' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
		$this->directory  = TempDir::create( 'trees-cache' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	private function plain( string $name = 'private-name' ): string {
		return TreeCodec::encode( [ new Node( $name, NodeType::File, content: [] ) ] );
	}

	private function file( Id $id ): string {
		return $this->directory . '/trees/' . $id->hex() . '.bin';
	}

	public function test_only_private_ciphertext_is_kept(): void {
		$plain     = $this->plain();
		$id        = Id::of( $plain );
		$cache     = new TreeCache( $this->directory, 4096 );
		$old_umask = umask( 0 );
		try {
			$cache->write( $this->repository, $id, $plain );
		} finally {
			umask( $old_umask );
		}
		$sealed = (string) file_get_contents( $this->file( $id ) );
		self::assertSame( 0600, fileperms( $this->file( $id ) ) & 0777 );
		self::assertSame( 0700, fileperms( $this->directory . '/trees' ) & 0777 );
		self::assertStringNotContainsString( 'private-name', $sealed );
		self::assertSame( $plain, $this->repository->cipher()->open( $sealed ) );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
	}

	public function test_each_read_authenticates_the_cached_ciphertext(): void {
		$plain = $this->plain();
		$id    = Id::of( $plain );
		$cache = new TreeCache( $this->directory, 4096 );
		$cache->write( $this->repository, $id, $plain );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
		$sealed = (string) file_get_contents( $this->file( $id ) );

		$sealed[20] = chr( ord( $sealed[20] ) ^ 1 );
		file_put_contents( $this->file( $id ), $sealed );
		self::assertNull( $cache->read( $this->repository, $id ) );
	}

	public function test_cached_reads_after_the_first_do_not_require_enumerating_the_directory(): void {
		$plain = $this->plain();
		$id    = Id::of( $plain );
		( new TreeCache( $this->directory, 4096 ) )->write( $this->repository, $id, $plain );
		$cache = new TreeCache( $this->directory, 4096 );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
		$trees = $this->directory . '/trees';
		chmod( $trees, 0100 );
		try {
			if ( is_readable( $trees ) ) {
				self::markTestSkipped( 'This user bypasses directory read permissions.' );
			}
			self::assertSame( $plain, $cache->read( $this->repository, $id ), 'The known file remains accessible without listing its directory again.' );
		} finally {
			chmod( $trees, 0700 );
		}
	}

	public function test_a_valid_ciphertext_with_the_wrong_tree_id_is_a_cache_miss(): void {
		$id    = Id::of( $this->plain() );
		$cache = new TreeCache( $this->directory );
		$cache->write( $this->repository, $id, $this->plain( 'other-tree' ) );
		self::assertNull( $cache->read( $this->repository, $id ) );
	}

	public function test_a_later_read_still_refuses_a_symbolic_cache_directory(): void {
		$plain = $this->plain();
		$id    = Id::of( $plain );
		$cache = new TreeCache( $this->directory, 4096 );
		$cache->write( $this->repository, $id, $plain );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
		rename( $this->directory . '/trees', $this->directory . '/retained' );
		symlink( $this->directory . '/retained', $this->directory . '/trees' );
		$this->expectException( StorageFailed::class );
		$cache->read( $this->repository, $id );
	}

	public function test_trees_share_the_budget_with_the_published_index(): void {
		$plain = $this->plain();
		$other = $this->plain( 'another-name' );
		$id    = Id::of( $plain );
		$index = str_repeat( 'i', 200 );
		file_put_contents( $this->directory . '/index.bin', $index );
		$limit = 200 + max( strlen( $plain ), strlen( $other ) ) + Cipher::OVERHEAD;
		$cache = new TreeCache( $this->directory, $limit );
		$cache->write( $this->repository, $id, $plain );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
		$cache->write( $this->repository, Id::of( $other ), $other );

		self::assertLessThanOrEqual( $limit, ( new CacheDirectory( $this->directory ) )->size() );
		self::assertSame( $index, file_get_contents( $this->directory . '/index.bin' ) );
		self::assertSame( $other, $cache->read( $this->repository, Id::of( $other ) ) );
		self::assertNull( $cache->read( $this->repository, $id ) );
	}

	public function test_an_index_writer_evicts_trees_when_its_index_grows(): void {
		$plain = $this->plain( str_repeat( 'x', 4096 ) );
		$id    = Id::of( $plain );
		$limit = strlen( $plain ) + Cipher::OVERHEAD + 256;
		$index = new IndexCache( $this->directory, $limit );
		$index->load( $this->repository );
		$cache = new TreeCache( $this->directory, $limit );
		$cache->write( $this->repository, $id, $plain );
		self::assertSame( $plain, $cache->read( $this->repository, $id ) );

		$store = new BlobStore( $this->repository, new IndexSet() );
		for ( $i = 0; $i < 20; ++$i ) {
			$store->save( BlobType::Data, 'blob-' . $i );
		}
		$store->flush();
		$store->write_index();
		self::assertSame( 20, $index->load( $this->repository )->count() );
		self::assertFileExists( $this->directory . '/index.bin' );
		self::assertLessThanOrEqual( $limit, ( new CacheDirectory( $this->directory ) )->size() );
		self::assertFileDoesNotExist( $this->file( $id ), 'IndexCache itself must enforce the combined limit.' );
	}

	public function test_a_reduced_limit_evicts_old_tree_files_without_loading_them(): void {
		$plain = $this->plain();
		$id    = Id::of( $plain );
		( new TreeCache( $this->directory, 4096 ) )->write( $this->repository, $id, $plain );
		self::assertNull( ( new TreeCache( $this->directory, 1 ) )->read( $this->repository, $id ) );
		self::assertFileDoesNotExist( $this->file( $id ) );
	}

	public function test_a_tree_that_cannot_fit_does_not_evict_an_existing_useful_tree(): void {
		$plain = $this->plain();
		$id    = Id::of( $plain );
		$cache = new TreeCache( $this->directory, strlen( $plain ) + Cipher::OVERHEAD );
		$cache->write( $this->repository, $id, $plain );
		$large = $this->plain( str_repeat( 'x', 1000 ) );
		$cache->write( $this->repository, Id::of( $large ), $large );

		self::assertSame( $plain, $cache->read( $this->repository, $id ) );
		self::assertFileDoesNotExist( $this->file( Id::of( $large ) ) );
	}

	public function test_a_symbolic_cache_directory_is_never_followed(): void {
		$outside = TempDir::create( 'other-cache' );
		symlink( $outside, $this->directory . '/trees' );
		try {
			$this->expectException( StorageFailed::class );
			$plain = $this->plain();
			( new TreeCache( $this->directory ) )->write( $this->repository, Id::of( $plain ), $plain );
		} finally {
			self::assertSame( [ '.', '..' ], scandir( $outside ) );
			TempDir::remove( $outside );
		}
	}
}
