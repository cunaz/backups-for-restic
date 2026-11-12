<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backup\PlanFactory;
use BackupsForRestic\Backup\SitePaths;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Backup\BackupRun;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Engine\Snapshot\Manifest;
use BackupsForRestic\Engine\Snapshot\Reader;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Storage\PrivateDirectory;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryBackend;
use BackupsForRestic\Tests\Support\TempDir;

final class SnapshotManifestTest extends TestCase {

	public function test_the_real_backup_plan_records_a_manifest_that_a_moved_site_can_read(): void {
		$root = TempDir::create( 'manifest-plan' );
		try {
			mkdir( $root . '/site/content/uploads', 0700, true );
			$paths      = new SitePaths( $root . '/site', $root . '/site/content', $root . '/site/content/uploads', 'https://old.example.com', 'old_', '6.7', '0.3.0' );
			$private    = PrivateDirectory::locate( $root . '/private', $paths->content_dir, $paths->uploads_dir );
			$clock      = new FrozenClock();
			$settings   = Settings::from_stored( [ 'repository.format_version' => 1 ] );
			$plan       = ( new PlanFactory( $paths, $settings, $private, [], [], null, 'test-job', $clock->seconds ) )->plan();
			$identity   = new Identity( $paths->hostname() );
			$repository = Repository::initialize( new InMemoryBackend(), 'test password', $identity, new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
			$run        = BackupRun::start( $repository, $plan, $root . '/work', $identity, $clock );
			self::assertTrue( $run->advance( INF ) );
			$id = $run->snapshot_id();
			self::assertNotNull( $id );
			$reader   = new Reader( $repository, new Identity( 'new.example.com' ), $clock );
			$snapshot = $reader->snapshot( $id );
			self::assertTrue( $snapshot->has_tags( [ PlanFactory::TAG, $paths->site_tag(), 'run:test-job' ] ) );
			self::assertSame( $private->path . '/spool/manifest.json', Manifest::path( $snapshot->tags ) );
			$manifest = $reader->manifest( $id );
			self::assertSame( $private->path . '/spool/manifest.json', $manifest['path'] ?? null );
			self::assertSame( 'https://old.example.com', $manifest['data']['home_url'] ?? null );
			self::assertSame( $root . '/site', $manifest['data']['abspath'] ?? null );
			self::assertSame( 'old_', $manifest['data']['prefix'] ?? null );
			self::assertFalse( $manifest['data']['database'] ?? null );
			self::assertSame( [ 'format' => 1 ], $manifest['data']['repository'] ?? null );
		} finally {
			TempDir::remove( $root );
		}
	}

	/** @return iterable<string, array{list<string>}> */
	public static function invalid_locators(): iterable {
		yield 'bad base64' => [ [ Manifest::TAG_PREFIX . '!' ] ];
		yield 'too long' => [ [ Manifest::TAG_PREFIX . str_repeat( 'A', 5465 ) ] ];
		yield 'relative' => [ [ Manifest::TAG_PREFIX . Bytes::base64_encode( 'relative' ) ] ];
		yield 'root' => [ [ Manifest::TAG_PREFIX . Bytes::base64_encode( '/' ) ] ];
		yield 'directory' => [ [ Manifest::TAG_PREFIX . Bytes::base64_encode( '/file/' ) ] ];
		yield 'parent' => [ [ Manifest::TAG_PREFIX . Bytes::base64_encode( '/dir/../file' ) ] ];
		yield 'nul' => [ [ Manifest::TAG_PREFIX . Bytes::base64_encode( "/file\0" ) ] ];
		yield 'duplicate' => [ [ Manifest::tag( '/one' ), Manifest::tag( '/two' ) ] ];
	}

	#[DataProvider( 'invalid_locators' )]
	public function test_invalid_or_ambiguous_locators_are_refused( array $tags ): void {
		$this->expectException( FormatException::class );
		Manifest::path( $tags );
	}

	/** @return iterable<string, array{string}> */
	public static function invalid_manifests(): iterable {
		$valid = [
			'format'     => Manifest::FORMAT,
			'created'    => '2026-01-01T00:00:00Z',
			'home_url'   => 'https://example.com',
			'abspath'    => '/site',
			'content'    => '/site/content',
			'uploads'    => '/site/uploads',
			'prefix'     => 'wp_',
			'roots'      => [ '/site' ],
			'excludes'   => [],
			'database'   => true,
			'versions'   => [
				'plugin'    => '0.3.0',
				'wordpress' => '6.7',
				'php'       => '8.3',
			],
			'repository' => [ 'format' => 1 ],
		];
		foreach ( [
			'format'     => 'unknown',
			'created'    => 'tomorrow',
			'home_url'   => 1,
			'prefix'     => "wp_\0",
			'roots'      => 'site',
			'excludes'   => [ 1 ],
			'database'   => 1,
			'versions'   => [],
			'repository' => [ 'format' => 9 ],
		] as $key => $value ) {
			yield $key => [ json_encode( array_replace( $valid, [ $key => $value ] ), JSON_THROW_ON_ERROR ) ];
		}
		yield 'too large' => [ str_repeat( ' ', Manifest::MAX_BYTES + 1 ) ];
		yield 'invalid JSON' => [ '{' ];
	}

	#[DataProvider( 'invalid_manifests' )]
	public function test_invalid_manifest_schemas_are_refused( string $json ): void {
		$this->expectException( FormatException::class );
		Manifest::decode( $json );
	}
}
