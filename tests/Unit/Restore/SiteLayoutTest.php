<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Restore;

use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Jobs\Restore\RestoreRequest;
use BackupsForRestic\Restore\SiteLayout;
use PHPUnit\Framework\TestCase;

final class SiteLayoutTest extends TestCase {

	private function job( string $uploads = '/old/content/uploads' ): Job {
		$snapshot                 = str_repeat( 'a', 64 );
		$request                  = RestoreRequest::from_input( $snapshot, null, false, true, true );
		$job                      = new Job( '20270115T030000Z-abcdef', JobType::Restore, 'manual', 1800000000 );
		$job->data                = $request->to_data();
		$job->data['site_layout'] = SiteLayout::bind(
			$snapshot,
			[
				'database' => true,
				'content'  => '/old/content/',
				'uploads'  => $uploads,
			],
			'/old/content/private/spool/manifest.json'
		);
		return $job;
	}

	public function test_stages_original_content_and_external_uploads_and_maps_to_the_receiving_configuration(): void {
		$job    = $this->job( '/library' );
		$layout = SiteLayout::from_job( $job, '/new/wp-content', '/new/wp-content/uploads' );
		self::assertNotNull( $layout );
		self::assertTrue( RestoreRequest::from_job( $job )->fresh_install );
		self::assertSame( [ '/library', '/old/content' ], SiteLayout::selection( $job->data['site_layout'] ) );
		self::assertTrue( $layout->is_ancestor( '/old' ) );
		self::assertFalse( $layout->is_ancestor( '/old/content/private' ) );
		self::assertSame( '/new/wp-content/themes/t/style.css', $layout->destination( '/old/content/themes/t/style.css' ) );
		self::assertSame( '/new/wp-content/uploads/2026/media.jpg', $layout->destination( '/library/2026/media.jpg' ) );
		foreach ( [ '/old/content/private/spool/database/manifest.json', '/old/content/wp-config.php', '/new/wp-content/unrelated' ] as $path ) {
			self::assertNull( $layout->destination( $path ) );
		}
		$this->expectException( InvalidArgument::class );
		$layout->destination( '/old/content/uploads/2026/media.jpg' );
	}

	public function test_custom_target_uploads_and_private_storage_outside_content(): void {
		$job                      = $this->job();
		$job->data['site_layout'] = SiteLayout::bind(
			$job->data['snapshot'],
			[
				'database' => true,
				'content'  => '/old/content',
				'uploads'  => '/old/content/uploads',
			],
			'/private/spool/manifest.json'
		);
		self::assertSame( [ '/old/content', '/private/spool' ], SiteLayout::selection( $job->data['site_layout'] ) );
		$layout = SiteLayout::from_job( $job, '/new/app', '/media' );
		self::assertNotNull( $layout );
		self::assertSame( '/media/photo.jpg', $layout->destination( '/old/content/uploads/photo.jpg' ) );
		self::assertNull( $layout->destination( '/private/spool/database/manifest.json' ) );
		self::assertNull( $layout->destination( '/old/contentious/a.php' ) );
	}

	public function test_an_ordinary_restore_never_reinterprets_its_paths(): void {
		$job = $this->job();
		unset( $job->data['fresh_install'] );
		self::assertNull( SiteLayout::from_job( $job, '/new/app', '/new/media' ) );
	}

	public function test_a_fresh_restore_refuses_a_substituted_manifest_binding(): void {
		$job                   = $this->job();
		$job->data['snapshot'] = str_repeat( 'b', 64 );
		$this->expectException( InvalidArgument::class );
		SiteLayout::from_job( $job, '/new/app', '/new/media' );
	}

	public function test_fresh_install_rejects_caller_supplied_paths(): void {
		$this->expectException( InvalidArgument::class );
		RestoreRequest::from_input( str_repeat( 'a', 64 ), [ '/' ], false, true, true );
	}

	public function test_fresh_install_rejects_unsafe_snapshot_roots(): void {
		$this->expectException( InvalidArgument::class );
		SiteLayout::bind(
			str_repeat( 'a', 64 ),
			[
				'database' => true,
				'content'  => '/old/content',
				'uploads'  => '/old/../files',
			],
			'/private/spool/manifest.json'
		);
	}

	public function test_manifest_binding_requires_the_exact_spool_locator_database_and_private_boundary(): void {
		$snapshot = str_repeat( 'a', 64 );
		$base     = [
			'database' => true,
			'content'  => '/content',
			'uploads'  => '/media',
		];
		foreach ( [
			[ $snapshot, [ 'database' => false ] + $base, '/private/spool/manifest.json' ],
			[ $snapshot, $base, '/private/other/manifest.json' ],
			[ 'invalid', $base, '/private/spool/manifest.json' ],
			[ $snapshot, [ 'content' => '/private/content' ] + $base, '/private/spool/manifest.json' ],
			[ $snapshot, [ 'uploads' => '/private' ] + $base, '/private/spool/manifest.json' ],
			[ $snapshot, [ 'content' => 1 ] + $base, '/private/spool/manifest.json' ],
			[ $snapshot, [ 'uploads' => '/' ] + $base, '/private/spool/manifest.json' ],
		] as [ $id, $manifest, $path ] ) {
			try {
				SiteLayout::bind( $id, $manifest, $path );
				self::fail( 'An ambiguous source binding was accepted.' );
			} catch ( InvalidArgument $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_stored_bindings_require_a_restore_job_with_the_matching_snapshot_and_valid_roots(): void {
		foreach ( [ 'flag', 'type', 'missing', 'snapshot', 'root' ] as $input ) {
			$job = $this->job();
			switch ( $input ) {
				case 'flag':
					$job->data['fresh_install'] = 1;
					break;
				case 'type':
					$backup       = new Job( $job->id, JobType::Backup, 'manual', $job->created_at );
					$backup->data = $job->data;
					$job          = $backup;
					break;
				case 'missing':
					unset( $job->data['site_layout'] );
					break;
				case 'snapshot':
					$job->data['snapshot']                = 1;
					$job->data['site_layout']['snapshot'] = 1;
					break;
				case 'root':
					$job->data['site_layout']['private'] = '/old';
					break;
			}
			try {
				SiteLayout::from_job( $job, '/site/content', '/site/media' );
				self::fail( 'Invalid fresh-install job accepted: ' . $input );
			} catch ( InvalidArgument $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_equal_and_nested_roots_keep_unique_round_trip_mappings(): void {
		$flat   = $this->job( '/old/content' );
		$layout = SiteLayout::from_job( $flat, '/new', '/new' );
		self::assertNotNull( $layout );
		self::assertSame( '/new', $layout->destination( '/old/content' ) );
		self::assertSame( '/new/file', $layout->destination( '/old/content/file' ) );
		self::assertNull( $layout->destination( '/old/content/WP-CoNfIG.PHP' ) );
		$nested = $this->job( '/old' );
		$layout = SiteLayout::from_job( $nested, '/new/content', '/new' );
		self::assertNotNull( $layout );
		self::assertSame( '/new/content', $layout->destination( '/old/content' ) );
		self::assertSame( '/new/media', $layout->destination( '/old/media' ) );
		foreach ( [ [ $flat, '/new/site', '/new/media' ], [ $nested, '/new', '/new' ] ] as [ $job, $content, $uploads ] ) {
			try {
				SiteLayout::from_job( $job, $content, $uploads );
				self::fail( 'A mapping changed the number of distinct roots.' );
			} catch ( InvalidArgument $error ) {
				self::assertSame( 'These content and upload directories cannot be uniquely mapped between installations.', $error->getMessage() );
			}
		}
	}

	public function test_public_path_checks_require_a_canonical_name_and_a_full_ancestor_component(): void {
		$layout = SiteLayout::from_job( $this->job(), '/new/content', '/new/uploads' );
		self::assertNotNull( $layout );
		self::assertTrue( $layout->is_ancestor( '/old/' ) );
		self::assertFalse( $layout->is_ancestor( '/ol' ) );
		foreach ( [ 'destination', 'is_ancestor' ] as $method ) {
			try {
				$layout->$method( '/old/../content' );
				self::fail( 'The public path check accepted a noncanonical name.' );
			} catch ( InvalidArgument $error ) {
				self::assertSame( 'The snapshot path contains an empty, dot or parent component.', $error->getMessage() );
			}
		}
	}
}
