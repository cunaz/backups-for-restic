<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Database\Restore\Manifest;
use BackupsForRestic\Database\Dump\SqlFormatter;

/** Small complete dump with duplicate rows and no primary key, for import boundary tests. */
final class StagedDump {

	public static function table(): string {
		$sql = "-- backups-for-restic-dump/1 table `wp_rows`\n"
			. "SET NAMES utf8mb4;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET SESSION time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
			. "DROP TABLE IF EXISTS `wp_rows`;\n"
			. "CREATE TABLE `wp_rows` (`a` int, `b` longtext) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `wp_rows` (`a`,`b`) VALUES (1,'same'),(1,'same');\n"
			. 'INSERT INTO `wp_rows` (`a`,`b`) VALUES (NULL,NULL),(2,' . SqlFormatter::value( "quote' ; \n line", SqlFormatter::TEXT ) . ");\n";
		return $sql . '-- end table rows=4 bytes=' . strlen( $sql ) . "\n";
	}


	/** Rebuilds only the terminal byte offset after a fixture's SQL has been changed. */
	public static function with_updated_trailer( string $sql, int $rows = 4 ): string {
		$offset = strrpos( $sql, '-- end table rows=' );
		if ( false === $offset ) {
			throw new \LogicException( 'The table fixture has no trailer.' );
		}
		return substr( $sql, 0, $offset ) . '-- end table rows=' . $rows . ' bytes=' . $offset . "\n";
	}

	public static function write( string $directory, ?string $sql = null, int $rows = 4 ): Manifest {
		$sql ??= self::table();
		file_put_contents( $directory . '/010-wp_rows.sql', $sql );
		$json = json_encode(
			[
				'format'    => 'backups-for-restic-dump/1',
				'created'   => '2026-01-01T00:00:00Z',
				'server'    => 'fixture',
				'charset'   => 'utf8mb4',
				'time_zone' => '+00:00',
				'prefix'    => 'wp_',
				'tables'    => [
					[
						'name'  => 'wp_rows',
						'file'  => '010-wp_rows.sql',
						'rows'  => $rows,
						'bytes' => strlen( $sql ),
					],
				],
				'views'     => [],
			],
			JSON_THROW_ON_ERROR
		);
		file_put_contents( $directory . '/manifest.json', $json );
		return Manifest::parse( $json, 'wp_' );
	}
}
