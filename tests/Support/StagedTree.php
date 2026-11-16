<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\GoQuote;
use PHPUnit\Framework\Assert;

/**
 * Describes a staged restore the way TempDir::describe() describes its source: names, types,
 * modification times and content come from staging, modes from the report. Staging itself is
 * owner-only, and links exist only in the report.
 */
final class StagedTree {

	/** @return array<string, string> */
	public static function describe( string $target, string $snapshot_root, string $work ): array {
		$staged = TempDir::describe( $target . $snapshot_root );
		$report = file( $work . '/entries.jsonl', FILE_IGNORE_NEW_LINES );
		Assert::assertIsArray( $report );
		$result = [];
		$seen   = [];
		foreach ( $report as $line ) {
			$entry = GoJson::decode_object( $line );
			Assert::assertIsString( $entry['path'] );
			Assert::assertIsInt( $entry['mode'] );
			$path = GoQuote::unquote( $entry['path'] );
			Assert::assertArrayNotHasKey( $path, $seen, 'A report entry must be committed exactly once.' );
			$seen[ $path ] = true;
			$inside        = str_starts_with( $path, $snapshot_root . '/' );
			$relative      = $inside ? substr( $path, strlen( $snapshot_root ) + 1 ) : '';
			$original      = sprintf( '%o', FileMode::to_posix_permissions( $entry['mode'] ) & 0777 );
			if ( 'symlink' === $entry['type'] ) {
				Assert::assertFalse( file_exists( $target . $path ) || is_link( $target . $path ), 'Snapshot links must never be materialized in staging.' );
				if ( $inside ) {
					Assert::assertIsString( $entry['link_target'] );
					$result[ $relative ] = 'link:' . GoQuote::unquote( $entry['link_target'] );
				}
				continue;
			}
			if ( 'dir' !== $entry['type'] && 'file' !== $entry['type'] ) {
				Assert::assertFalse( file_exists( $target . $path ), 'Special files must never be created in staging.' );
				continue;
			}
			Assert::assertSame( 'dir' === $entry['type'] ? 0700 : 0600, fileperms( $target . $path ) & 07777, 'Staging is owner-only, whatever the snapshot recorded.' );
			if ( ! $inside ) {
				continue;
			}
			Assert::assertArrayHasKey( $relative, $staged, 'Every reported entry is staged.' );
			$result[ $relative ] = 'dir' === $entry['type'] ? 'dir:' . $original : (string) preg_replace( '/\Afile:600:/', 'file:' . $original . ':', $staged[ $relative ] );
		}
		Assert::assertSame( [], array_diff_key( $staged, $result ), 'Staging holds nothing that the report does not list.' );
		ksort( $result, SORT_STRING );
		return $result;
	}
}
