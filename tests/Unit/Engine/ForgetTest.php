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
}
