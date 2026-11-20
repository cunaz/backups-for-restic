<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Live;

use BackupsForRestic\Tests\Support\TestSite;
use PHPUnit\Framework\Assert;

/** Counts files containing fixture credentials without emitting a matched value or line. */
final class WordPressOutputScan {

	/**
	 * @param array{username:string, rest_password:string, repository_password:string} $fixture Normal repository credentials.
	 * @return array{files:int,bytes:int,matches:int}
	 */
	public static function inspect( string $dump_directory, #[\SensitiveParameter] array $fixture ): array {
		$sql = glob( $dump_directory . '/*.sql' );
		Assert::assertNotEmpty( $sql );
		$logs = array_merge(
			glob( TestSite::private_dir() . '/jobs/*/job.json' ),
			glob( TestSite::private_dir() . '/jobs/*/events.log' ),
			glob( TestSite::private_dir() . '/logs/*.log' ),
			[ TestSite::tools_dir() . '/site-server.log', TestSite::tools_dir() . '/requests.log' ]
		);
		foreach ( [ TestSite::tools_dir() . '/php-errors.log', TestSite::root() . '/wp-content/debug.log' ] as $optional ) {
			if ( is_file( $optional ) ) {
				$logs[] = $optional;
			}
		}

		$patterns = [];
		foreach ( [ $fixture['rest_password'], $fixture['repository_password'], $fixture['username'] . ':' . $fixture['rest_password'] ] as $value ) {
			Assert::assertNotSame( '', $value );
			$base64 = base64_encode( $value );
			array_push( $patterns, $value, urlencode( $value ), rawurlencode( $value ), $base64, rtrim( $base64, '=' ), rtrim( strtr( $base64, '+/', '-_' ), '=' ) );
		}
		$patterns = array_unique( $patterns );
		$overlap  = max( array_map( 'strlen', $patterns ) ) - 1;
		$bytes    = 0;
		$matches  = 0;
		$files    = array_merge( $sql, $logs );
		foreach ( $files as $file ) {
			$handle = fopen( $file, 'rb' );
			Assert::assertIsResource( $handle );
			$matched = false;
			$tail    = '';
			try {
				while ( ! feof( $handle ) ) {
					$chunk = fread( $handle, 1048576 );
					Assert::assertIsString( $chunk );
					$bytes += strlen( $chunk );
					$window = $tail . $chunk;
					foreach ( $patterns as $pattern ) {
						if ( str_contains( $window, $pattern ) ) {
							$matched = true;
							break;
						}
					}
					$tail = $overlap > 0 ? substr( $window, -$overlap ) : '';
				}
			} finally {
				fclose( $handle );
			}
			if ( $matched ) {
				++$matches;
			}
		}
		return [
			'files'   => count( $files ),
			'bytes'   => $bytes,
			'matches' => $matches,
		];
	}
}
