<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use PHPUnit\Framework\Assert;

/** Compares all original fixture metadata while asserting links exist only in the report. */
final class StagedTree {

	/** @return array<string, string> */
	public static function describe( string $target, string $snapshot_root, string $work ): array {
		$result = TempDir::describe( $target . $snapshot_root );
		$report = file( $work . '/entries.jsonl', FILE_IGNORE_NEW_LINES );
		Assert::assertIsArray( $report );
		$seen = [];
		foreach ( $report as $line ) {
			$entry = GoJson::decode_object( $line );
			Assert::assertIsString( $entry['path'] );
			$path = GoQuote::unquote( $entry['path'] );
			Assert::assertArrayNotHasKey( $path, $seen, 'A report entry must be committed exactly once.' );
			$seen[ $path ] = true;
			if ( 'symlink' !== $entry['type'] ) {
				continue;
			}
			Assert::assertFalse( file_exists( $target . $path ) || is_link( $target . $path ), 'Snapshot links must never be materialized in staging.' );
			if ( str_starts_with( $path, $snapshot_root . '/' ) ) {
				Assert::assertIsString( $entry['link_target'] );
				$result[ substr( $path, strlen( $snapshot_root ) + 1 ) ] = 'link:' . GoQuote::unquote( $entry['link_target'] );
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}
}
