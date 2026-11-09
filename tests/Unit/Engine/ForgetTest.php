<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Engine\Forget\Duration;
use BackupsForRestic\Engine\Forget\Forget;
use BackupsForRestic\Engine\Forget\RetentionPolicy;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Tests\Support\Vectors;

/**
 * Retention must decide exactly like restic. Vectors: 150 snapshots in four time zones,
 * evaluated by restic forget --dry-run (bin/record-forget-vectors.sh).
 */
final class ForgetTest extends TestCase {

	/**
	 * @return array<string, Snapshot>
	 */
	private static function snapshots(): array {
		$snapshots = [];
		foreach ( Vectors::json( 'vectors/forget-restic.json' )['snapshots'] as $entry ) {
			$snapshots[ $entry['id'] ] = new Snapshot( $entry['time'], Id::of( $entry['id'] ), [ '/' ], 'vectors', tags: $entry['tags'] );
		}
		return $snapshots;
	}

	/**
	 * @param list<string> $flags restic flags.
	 */
	private static function policy( array $flags ): RetentionPolicy {
		$args  = [];
		$tags  = [];
		$count = count( $flags );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$name  = str_replace( '-', '_', substr( $flags[ $i ], strlen( '--keep-' ) ) );
			$value = $flags[ $i + 1 ];
			if ( 'tag' === $name ) {
				$tags[] = explode( ',', $value );
			} elseif ( str_starts_with( $name, 'within' ) ) {
				$args[ $name ] = Duration::parse( $value );
			} else {
				$args[ $name ] = 'unlimited' === $value ? -1 : (int) $value;
			}
		}
		return new RetentionPolicy( ...$args, keep_tags: $tags );
	}

	/**
	 * @return iterable<string, array{list<string>, list<string>, list<string>}>
	 */
	public static function restic_cases(): iterable {
		foreach ( Vectors::json( 'vectors/forget-restic.json' )['cases'] as $case ) {
			yield implode( ' ', $case['flags'] ) => [ $case['flags'], $case['keep'], $case['remove'] ];
		}
	}

	/**
	 * @param list<string> $flags  restic flags.
	 * @param list<string> $keep   IDs restic keeps.
	 * @param list<string> $remove IDs restic removes.
	 */
	#[DataProvider( 'restic_cases' )]
	public function test_matches_restic( array $flags, array $keep, array $remove ): void {
		$result        = Forget::apply( self::snapshots(), self::policy( $flags ) );
		$actual_keep   = $result['keep'];
		$actual_remove = $result['remove'];
		sort( $actual_keep );
		sort( $actual_remove );
		self::assertSame( $keep, $actual_keep );
		self::assertSame( $remove, $actual_remove );
	}

	public function test_refuses_an_empty_policy(): void {
		$this->expectException( InvalidArgument::class );
		Forget::apply( self::snapshots(), new RetentionPolicy() );
	}

	public function test_reasons_explain_each_kept_snapshot(): void {
		$result = Forget::apply( self::snapshots(), new RetentionPolicy( last: 1, within: Duration::parse( '1d' ) ) );
		$first  = $result['keep'][0];
		self::assertContains( 'last snapshot', $result['reasons'][ $first ] );
		self::assertContains( 'within 1d', $result['reasons'][ $first ] );
	}

	public function test_durations_parse_like_restic(): void {
		self::assertSame( '1y2m3d4h', Duration::parse( '4h3d2m1y' )->format() );
		self::assertTrue( Duration::parse( '0d' )->is_zero() );
		$this->expectException( InvalidArgument::class );
		Duration::parse( '2 days' );
	}

	public function test_grouping_by_host_and_tags(): void {
		$snapshots = [
			'a' => new Snapshot( '2026-01-01T00:00:00Z', Id::of( 'a' ), [ '/x' ], 'one', tags: [ 'b', 'a' ] ),
			'b' => new Snapshot( '2026-01-02T00:00:00Z', Id::of( 'b' ), [ '/y' ], 'one', tags: [ 'a', 'b' ] ),
			'c' => new Snapshot( '2026-01-03T00:00:00Z', Id::of( 'c' ), [ '/x' ], 'two', tags: [ 'a', 'b' ] ),
		];
		$groups    = Forget::group( $snapshots );
		self::assertCount( 2, $groups );
		self::assertCount( 3, Forget::group( $snapshots, true, true, true ) );
	}

	public function test_grouping_tells_tags_apart_by_default_and_ignores_the_order_of_paths(): void {
		$snapshots = [
			'a' => new Snapshot( '2026-01-01T00:00:00Z', Id::of( 'a' ), [ '/x', '/y' ], 'one', tags: [ 'daily' ] ),
			'b' => new Snapshot( '2026-01-02T00:00:00Z', Id::of( 'b' ), [ '/y', '/x' ], 'one', tags: [ 'weekly' ] ),
			'c' => new Snapshot( '2026-01-03T00:00:00Z', Id::of( 'c' ), [ '/y', '/x' ], 'one', tags: [ 'daily' ] ),
		];

		self::assertSame( [ [ 'a', 'c' ], [ 'b' ] ], array_map( 'array_keys', array_values( Forget::group( $snapshots ) ) ) );
		self::assertCount( 1, Forget::group( $snapshots, true, true, false ) );
	}

	/**
	 * Snapshots of one host with only the given times, keyed by a readable name.
	 *
	 * @param array<string, string> $times Times by name.
	 * @return array<string, Snapshot>
	 */
	private static function at( array $times ): array {
		$snapshots = [];
		foreach ( $times as $name => $time ) {
			$snapshots[ $name ] = new Snapshot( $time, Id::of( $name ), [ '/' ], 'host' );
		}
		return $snapshots;
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function offsets(): iterable {
		yield 'UTC' => [ 'Z' ];
		yield 'Berlin in winter' => [ '+01:00' ];
		yield 'New York in winter' => [ '-05:00' ];
		yield 'Kolkata' => [ '+05:30' ];
	}

	/**
	 * The decisions below are restic 0.19.1's (forget --dry-run --json --keep-daily unlimited),
	 * for snapshots made with TZ set to each zone. A day begins at midnight, to the second, in
	 * the snapshot's own offset.
	 */
	#[DataProvider( 'offsets' )]
	public function test_days_begin_at_midnight_in_the_snapshots_own_offset( string $offset ): void {
		$snapshots = self::at(
			[
				'midnight'    => '2024-03-02T00:00:00' . $offset,
				'last second' => '2024-03-01T23:59:59' . $offset,
				'noon'        => '2024-03-01T12:00:00' . $offset,
			]
		);

		self::assertSame(
			[
				'midnight'    => [ 'daily snapshot' ],
				'last second' => [ 'daily snapshot' ],
				'noon'        => [ 'oldest daily snapshot' ],
			],
			Forget::apply( $snapshots, new RetentionPolicy( daily: -1 ) )['reasons']
		);
	}

	/**
	 * restic 0.19.1's decisions for --keep-within 1m: the month is subtracted from the latest
	 * snapshot's local date (Go's AddDate in its offset), which differs from doing it in UTC
	 * close to midnight.
	 *
	 * @return iterable<string, array{array<string, bool>}>
	 */
	public static function month_boundaries(): iterable {
		yield 'shortly after midnight in Berlin' => [
			[
				'2024-03-01T00:30:00+01:00' => true,
				'2024-02-01T00:30:01+01:00' => true,
				'2024-02-01T00:30:00+01:00' => false,
				'2024-01-30T13:00:00+01:00' => false,
			],
		];
		yield 'shortly before midnight in Berlin' => [
			[
				'2024-02-29T23:30:00+01:00' => true,
				'2024-01-30T00:00:00+01:00' => true,
				'2024-01-29T23:30:00+01:00' => false,
			],
		];
		yield 'shortly after midnight in Kolkata' => [
			[
				'2024-03-01T00:00:30+05:30' => true,
				'2024-02-01T00:00:31+05:30' => true,
				'2024-01-31T00:00:00+05:30' => false,
			],
		];
	}

	/**
	 * @param array<string, bool> $kept Whether restic keeps each snapshot, by time, newest first.
	 */
	#[DataProvider( 'month_boundaries' )]
	public function test_within_subtracts_months_in_the_latest_snapshots_offset( array $kept ): void {
		$times  = array_keys( $kept );
		$result = Forget::apply( self::at( array_combine( $times, $times ) ), new RetentionPolicy( within: Duration::parse( '1m' ) ) );

		self::assertSame( array_keys( array_filter( $kept ) ), $result['keep'] );
	}

	public function test_within_counts_hours_and_nanoseconds_back_from_the_latest_snapshot(): void {
		// restic 0.19.1 keeps 10:00:01 and removes 10:00:00 for --keep-within 2h; Go's
		// Time.After also compares nanoseconds, so one nanosecond past the threshold is inside.
		$snapshots = self::at(
			[
				'latest'                => '2024-03-10T12:00:00+01:00',
				'one second inside'     => '2024-03-10T10:00:01+01:00',
				'one nanosecond inside' => '2024-03-10T10:00:00.000000001+01:00',
				'on the threshold'      => '2024-03-10T10:00:00+01:00',
			]
		);
		$result    = Forget::apply( $snapshots, new RetentionPolicy( within: Duration::parse( '2h' ) ) );

		self::assertSame( [ 'latest', 'one second inside', 'one nanosecond inside' ], $result['keep'] );
		self::assertSame( [ 'on the threshold' ], $result['remove'] );
	}

	public function test_snapshots_within_one_second_are_ordered_by_nanoseconds(): void {
		$oldest_first = self::at(
			[
				'earlier' => '2024-03-10T12:00:00.1Z',
				'later'   => '2024-03-10T12:00:00.2Z',
			]
		);
		$newest_first = array_reverse( $oldest_first, true );

		self::assertSame( [ 'later' ], Forget::apply( $oldest_first, new RetentionPolicy( last: 1 ) )['keep'] );
		self::assertSame( [ 'later' ], Forget::apply( $newest_first, new RetentionPolicy( last: 1 ) )['keep'] );
	}

	public function test_the_oldest_snapshot_is_kept_while_a_rule_has_counts_left(): void {
		// restic 0.19.1: --keep-daily 5 and --keep-within-daily 10d.
		$four  = self::at(
			[
				'march 3'         => '2024-03-03T12:00:00Z',
				'march 2'         => '2024-03-02T12:00:00Z',
				'march 1 evening' => '2024-03-01T18:00:00Z',
				'march 1 morning' => '2024-03-01T06:00:00Z',
			]
		);
		$three = array_slice( $four, 0, 3 );

		self::assertSame(
			[
				'march 3'         => [ 'daily snapshot' ],
				'march 2'         => [ 'daily snapshot' ],
				'march 1 evening' => [ 'daily snapshot' ],
				'march 1 morning' => [ 'oldest daily snapshot' ],
			],
			Forget::apply( $four, new RetentionPolicy( daily: 5 ) )['reasons']
		);
		self::assertSame(
			[
				'march 3'         => [ 'daily within 10d' ],
				'march 2'         => [ 'daily within 10d' ],
				'march 1 evening' => [ 'daily within 10d' ],
				'march 1 morning' => [ 'oldest daily within 10d' ],
			],
			Forget::apply( $four, new RetentionPolicy( within_daily: Duration::parse( '10d' ) ) )['reasons']
		);
		self::assertSame(
			[
				'march 3'         => [ 'daily snapshot' ],
				'march 2'         => [ 'daily snapshot' ],
				'march 1 evening' => [ 'daily snapshot' ],
			],
			Forget::apply( $three, new RetentionPolicy( daily: 5 ) )['reasons'],
			'An oldest snapshot that starts a new day is an ordinary daily snapshot.'
		);
		self::assertSame(
			[
				'march 3'         => [ 'daily within 10d' ],
				'march 2'         => [ 'daily within 10d' ],
				'march 1 evening' => [ 'daily within 10d' ],
			],
			Forget::apply( $three, new RetentionPolicy( within_daily: Duration::parse( '10d' ) ) )['reasons']
		);
	}

	public function test_hourly_snapshots_within_a_duration(): void {
		// restic 0.19.1: --keep-within-hourly 2h.
		$snapshots = self::at(
			[
				'12:30' => '2024-03-10T12:30:00Z',
				'11:45' => '2024-03-10T11:45:00Z',
				'11:15' => '2024-03-10T11:15:00Z',
				'09:59' => '2024-03-10T09:59:00Z',
			]
		);
		$result    = Forget::apply( $snapshots, new RetentionPolicy( within_hourly: Duration::parse( '2h' ) ) );

		self::assertSame(
			[
				'12:30' => [ 'hourly within 2h' ],
				'11:45' => [ 'hourly within 2h' ],
			],
			$result['reasons']
		);
		self::assertSame( [ '11:15', '09:59' ], $result['remove'] );
	}

	public function test_tag_rules_keep_snapshots_with_all_tags_and_name_them(): void {
		// restic 0.19.1: --keep-tag a,b.
		$snapshots = [
			'both' => new Snapshot( '2024-03-10T12:00:00Z', Id::of( 'both' ), [ '/' ], 'host', tags: [ 'a', 'b' ] ),
			'one'  => new Snapshot( '2024-03-10T11:00:00Z', Id::of( 'one' ), [ '/' ], 'host', tags: [ 'a' ] ),
			'more' => new Snapshot( '2024-03-10T10:00:00Z', Id::of( 'more' ), [ '/' ], 'host', tags: [ 'b', 'a', 'c' ] ),
		];
		$result    = Forget::apply( $snapshots, new RetentionPolicy( keep_tags: [ [ 'a', 'b' ] ] ) );

		self::assertSame(
			[
				'both' => [ 'has tags [a, b]' ],
				'more' => [ 'has tags [a, b]' ],
			],
			$result['reasons']
		);
		self::assertSame( [ 'one' ], $result['remove'] );
	}

	public function test_a_duration_without_parts_is_zero(): void {
		$duration = new Duration();
		self::assertTrue( $duration->is_zero() );
		self::assertSame( '', $duration->format() );
	}

	public function test_durations_with_a_single_unit(): void {
		self::assertSame( '1y', Duration::parse( '1y' )->format() );
		self::assertEquals( new Duration( hours: 36 ), Duration::parse( '36h' ) );
	}

	/**
	 * Strings restic's ParseDuration rejects as well.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function malformed_durations(): iterable {
		yield 'number without unit at the end' => [ '1d2' ];
		yield 'unknown unit at the end' => [ '2dx' ];
		yield 'text in front' => [ 'x2d' ];
	}

	#[DataProvider( 'malformed_durations' )]
	public function test_malformed_durations_are_rejected( string $text ): void {
		$this->expectException( InvalidArgument::class );
		Duration::parse( $text );
	}
}
