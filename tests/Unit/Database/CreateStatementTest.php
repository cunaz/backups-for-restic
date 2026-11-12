<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Database\Dump\SqlFormatter;
use BackupsForRestic\Database\Restore\CreateStatement;
use BackupsForRestic\Exception\FormatException;

final class CreateStatementTest extends TestCase {

	public function test_common_wordpress_columns_indexes_and_generated_values_use_an_owned_shadow(): void {
		$sql  = "CREATE TABLE `wp_posts` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `body` longtext, `price` decimal(10,2) DEFAULT NULL, `flag` bit(1) NOT NULL DEFAULT b'0', `doubled` decimal(12,2) GENERATED ALWAYS AS (`price` * 2) VIRTUAL, `created` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (`id`), KEY `title` (`title`(191)), CONSTRAINT `price_positive` CHECK (`price` >= 0)) ENGINE=MyISAM AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='a ; harmless comment';";
		$read = CreateStatement::parse( $sql, 'wp_posts', 'bfr_restore_test_t0', 'owner:123' );
		self::assertSame( 'MYISAM', $read->engine );
		self::assertSame( "'a ; harmless comment'", $read->comment );
		self::assertSame( [ 'id', 'title', 'body', 'price', 'flag', 'created' ], $read->columns );
		self::assertStringStartsWith( 'CREATE TABLE `bfr_restore_test_t0` (', $read->sql );
		self::assertStringContainsString( 'ENGINE=InnoDB', $read->sql );
		self::assertStringContainsString( "COMMENT='owner:123'", $read->sql );
		self::assertStringContainsString( 'AUTO_INCREMENT=42', $read->sql );
		self::assertStringContainsString( "b'0'", $read->sql );
		self::assertStringContainsString( 'CURRENT_TIMESTAMP( 6 )', $read->sql );
		self::assertStringContainsString( 'CONSTRAINT `bfr_restore_test_t0_c9` CHECK(', $read->sql );
		self::assertStringNotContainsString( '`wp_posts`', $read->sql );
	}

	public function test_delimiters_inside_schema_literals_remain_data(): void {
		$value = SqlFormatter::value( "'; DROP TABLE `wp_users`; -- \\ and\nnewline", SqlFormatter::TEXT );
		$sql   = 'CREATE TABLE `wp_posts` (`text` varchar(255) COMMENT ' . $value . ', `enum` enum(\'a,b\',\'x)y\',\'z;\') DEFAULT \'a,b\') ENGINE=InnoDB COMMENT=' . $value . ';';
		$read  = CreateStatement::parse( $sql, 'wp_posts', 'shadow', 'owner' );
		self::assertSame( $value, $read->comment );
		self::assertStringContainsString( $value, $read->sql );
		self::assertSame( [ 'text', 'enum' ], $read->columns );
	}

	public function test_engine_specific_row_formats_are_not_copied_to_transactional_shadows(): void {
		$fixed = CreateStatement::parse( 'CREATE TABLE `wp_posts` (`id` int) ENGINE=MyISAM ROW_FORMAT=FIXED DEFAULT CHARSET=utf8mb4;', 'wp_posts', 'shadow', 'owner' );
		self::assertStringNotContainsString( 'ROW_FORMAT', $fixed->sql );
		self::assertStringContainsString( 'CHARSET=utf8mb4', $fixed->sql );
		self::assertSame( 'MYISAM', $fixed->engine );
		$dynamic = CreateStatement::parse( 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;', 'wp_posts', 'shadow', 'owner' );
		self::assertStringContainsString( 'ROW_FORMAT=DYNAMIC', $dynamic->sql );
	}

	public function test_a_table_of_generated_columns_has_no_inserted_values(): void {
		$read = CreateStatement::parse( 'CREATE TABLE `wp_posts` (`g` int GENERATED ALWAYS AS (42) STORED) ENGINE=InnoDB;', 'wp_posts', 'shadow', 'owner' );
		self::assertSame( [], $read->columns );
		self::assertStringContainsString( 'AS( 42 ) STORED', $read->sql );
	}

	public function test_nested_expressions_beyond_the_limit_are_refused(): void {
		$this->expectException( FormatException::class );
		CreateStatement::parse( 'CREATE TABLE `wp_posts` (`id` int, CHECK (' . str_repeat( '(', 64 ) . '`id`' . str_repeat( ')', 64 ) . ' > 0)) ENGINE=InnoDB;', 'wp_posts', 'shadow', 'owner' );
	}

	public function test_mysql_invisible_marker_is_rendered_as_a_keyword_without_executing_a_comment(): void {
		$read = CreateStatement::parse( "CREATE TABLE `wp_posts` (`id` int, `hidden` int /*!80023 INVISIBLE */ COMMENT '/*!80023 INVISIBLE */') ENGINE=InnoDB;", 'wp_posts', 'shadow', 'owner' );
		self::assertStringContainsString( "`hidden` int INVISIBLE COMMENT'/*!80023 INVISIBLE */'", $read->sql );
		self::assertSame( [ 'id', 'hidden' ], $read->columns );
	}

	/** @return iterable<string, array{string}> */
	public static function rejected(): iterable {
		foreach ( [
			'wrong table'               => 'CREATE TABLE `wp_other` (`id` int) ENGINE=InnoDB;',
			'qualified table'           => 'CREATE TABLE `elsewhere`.`wp_posts` (`id` int) ENGINE=InnoDB;',
			'create select'             => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB AS SELECT * FROM wp_users;',
			'create like'               => 'CREATE TABLE `wp_posts` LIKE `wp_users`;',
			'temporary'                 => 'CREATE TEMPORARY TABLE `wp_posts` (`id` int) ENGINE=InnoDB;',
			'if exists'                 => 'CREATE TABLE IF NOT EXISTS `wp_posts` (`id` int) ENGINE=InnoDB;',
			'following command'         => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB; DROP TABLE `wp_users`;',
			'comment tail'              => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB; -- tail',
			'changed invisible marker'  => 'CREATE TABLE `wp_posts` (`id` int /*!80023 INVISIBLE, `extra` int */) ENGINE=InnoDB;',
			'versioned engine'          => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB /*!50100 DATA DIRECTORY=\'/tmp\' */;',
			'data directory'            => "CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB DATA DIRECTORY='/tmp';",
			'index directory'           => "CREATE TABLE `wp_posts` (`id` int) ENGINE=MyISAM INDEX DIRECTORY='/tmp';",
			'tablespace'                => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB TABLESPACE hidden;',
			'federated'                 => "CREATE TABLE `wp_posts` (`id` int) ENGINE=FEDERATED CONNECTION='mysql://other';",
			'merge'                     => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=MRG_MYISAM UNION=(wp_users);',
			'unknown engine'            => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=unknown;',
			'duplicate engine'          => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB ENGINE=MyISAM;',
			'plugin option'             => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB ENGINE_ATTRIBUTE=\'{}\';',
			'partition'                 => 'CREATE TABLE `wp_posts` (`id` int) ENGINE=InnoDB PARTITION BY HASH(id) PARTITIONS 2;',
			'default stored function'   => 'CREATE TABLE `wp_posts` (`id` int DEFAULT (evil())) ENGINE=InnoDB;',
			'generated udf'             => 'CREATE TABLE `wp_posts` (`id` int, `g` int AS (evil(`id`))) ENGINE=InnoDB;',
			'quoted udf'                => 'CREATE TABLE `wp_posts` (`id` int, `g` int AS (`evil`(`id`))) ENGINE=InnoDB;',
			'qualified function'        => 'CREATE TABLE `wp_posts` (`id` int DEFAULT (other.ABS(1))) ENGINE=InnoDB;',
			'check udf'                 => 'CREATE TABLE `wp_posts` (`id` int, CHECK (evil(`id`))) ENGINE=InnoDB;',
			'index udf'                 => 'CREATE TABLE `wp_posts` (`id` int, KEY `k` ((`evil`(1)))) ENGINE=InnoDB;',
			'function with file access' => 'CREATE TABLE `wp_posts` (`id` int, `g` longtext AS (LOAD_FILE(\'/etc/passwd\'))) ENGINE=InnoDB;',
			'foreign table'             => 'CREATE TABLE `wp_posts` (`id` int, FOREIGN KEY (`id`) REFERENCES `wp_users` (`ID`)) ENGINE=InnoDB;',
			'duplicate columns'         => 'CREATE TABLE `wp_posts` (`ID` int, `id` int) ENGINE=InnoDB;',
			'unclosed definition'       => 'CREATE TABLE `wp_posts` (`id` int ENGINE=InnoDB;',
			'missing engine'            => 'CREATE TABLE `wp_posts` (`id` int);',
		] as $name => $sql ) {
			yield $name => [ $sql ];
		}
	}

	#[DataProvider( 'rejected' )]
	public function test_only_table_local_supported_definitions_reach_the_shadow( string $sql ): void {
		$this->expectException( FormatException::class );
		CreateStatement::parse( $sql, 'wp_posts', 'shadow', 'owner' );
	}
}
