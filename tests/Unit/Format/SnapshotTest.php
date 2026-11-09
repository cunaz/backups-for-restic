<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\SnapshotSummary;

/**
 * Snapshot files follow restic's Snapshot struct: fields in struct order, empty optional fields
 * omitted (omitempty), and lists of strings validated on the way in.
 */
final class SnapshotTest extends TestCase {

	public function test_a_minimal_snapshot_has_only_time_tree_and_paths(): void {
		$tree     = Id::of( 'tree' );
		$snapshot = new Snapshot( '2026-11-09T12:00:00Z', $tree, [ '/var/www' ] );

		self::assertSame( '{"time":"2026-11-09T12:00:00Z","tree":"' . $tree->hex() . '","paths":["/var/www"]}', $snapshot->to_json() );
	}

	public function test_every_field_is_written_in_restic_order_and_read_back(): void {
		$tree     = Id::of( 'tree' );
		$parent   = Id::of( 'parent' );
		$original = Id::of( 'original' );
		$summary  = new SnapshotSummary( '2026-11-09T12:00:00Z', '2026-11-09T12:00:05Z', 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 );
		$snapshot = new Snapshot( '2026-11-09T12:00:05.5Z', $tree, [ '/var/www', '/srv' ], 'example.com', 'www-data', 33, 34, [ '*.tmp', '*.log' ], [ 'daily', 'database' ], $parent, $original, 'backups-for-restic 0.1.0', $summary );

		$json = $snapshot->to_json();
		self::assertSame(
			'{"time":"2026-11-09T12:00:05.5Z","parent":"' . $parent->hex() . '","tree":"' . $tree->hex() . '","paths":["/var/www","/srv"],'
			. '"hostname":"example.com","username":"www-data","uid":33,"gid":34,"excludes":["*.tmp","*.log"],"tags":["daily","database"],'
			. '"original":"' . $original->hex() . '","program_version":"backups-for-restic 0.1.0","summary":{"backup_start":"2026-11-09T12:00:00Z",'
			. '"backup_end":"2026-11-09T12:00:05Z","files_new":1,"files_changed":2,"files_unmodified":3,"dirs_new":4,"dirs_changed":5,'
			. '"dirs_unmodified":6,"data_blobs":7,"tree_blobs":8,"data_added":9,"data_added_packed":10,"total_files_processed":11,'
			. '"total_bytes_processed":12}}',
			$json
		);
		self::assertEquals( $snapshot, Snapshot::from_json( $json ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_snapshots(): iterable {
		$tree = Id::of( 'tree' )->hex();
		yield 'time not RFC 3339' => [ '{"time":"2026-11-09 12:00:00","tree":"' . $tree . '","paths":["/"]}' ];
		yield 'path that is not a string' => [ '{"time":"2026-11-09T12:00:00Z","tree":"' . $tree . '","paths":["/",7]}' ];
		yield 'tags that are not a list' => [ '{"time":"2026-11-09T12:00:00Z","tree":"' . $tree . '","paths":["/"],"tags":{"a":"b"}}' ];
	}

	#[DataProvider( 'invalid_snapshots' )]
	public function test_invalid_snapshots_are_rejected( string $json ): void {
		$this->expectException( FormatException::class );
		Snapshot::from_json( $json );
	}

	public function test_summary_counters_default_to_zero(): void {
		$summary = new SnapshotSummary( '2026-11-09T12:00:00Z', '2026-11-09T12:00:05Z' );

		self::assertSame(
			[
				'backup_start'          => '2026-11-09T12:00:00Z',
				'backup_end'            => '2026-11-09T12:00:05Z',
				'files_new'             => 0,
				'files_changed'         => 0,
				'files_unmodified'      => 0,
				'dirs_new'              => 0,
				'dirs_changed'          => 0,
				'dirs_unmodified'       => 0,
				'data_blobs'            => 0,
				'tree_blobs'            => 0,
				'data_added'            => 0,
				'data_added_packed'     => 0,
				'total_files_processed' => 0,
				'total_bytes_processed' => 0,
			],
			$summary->to_array()
		);
	}

	public function test_summary_reads_missing_or_mistyped_counters_as_zero(): void {
		$summary = SnapshotSummary::from_array(
			[
				'backup_start' => '2026-11-09T12:00:00Z',
				'backup_end'   => '2026-11-09T12:00:05Z',
				'files_new'    => '5',
				'data_added'   => 1.5,
				'tree_blobs'   => 3,
			]
		);

		self::assertEquals( new SnapshotSummary( '2026-11-09T12:00:00Z', '2026-11-09T12:00:05Z', tree_blobs: 3 ), $summary );
	}
}
