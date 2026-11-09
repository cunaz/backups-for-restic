<?php
/**
 * What the dump needs to know about a table.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Database\Dump;

defined( 'ABSPATH' ) || exit;

/**
 * Name, CREATE statement, columns with their kinds, and how to page through the rows.
 */
final readonly class Table {

	/**
	 * Describes a table.
	 *
	 * @param string                $name        Table name.
	 * @param string                $create      CREATE TABLE statement on one line.
	 * @param array<string, string> $columns     Column kinds by column name, in table order.
	 * @param string|null           $key         Single integer primary key column for keyset paging, if any.
	 * @param list<string>          $order       Columns to order by when paging by offset.
	 */
	public function __construct(
		public string $name,
		public string $create,
		public array $columns,
		public ?string $key,
		public array $order
	) {
	}

	/**
	 * File name inside spool/database/, safe for every filesystem.
	 *
	 * @return string File name such as 010-wp_posts.sql.
	 */
	public function file(): string {
		return '010-' . preg_replace_callback( '/[^A-Za-z0-9_$-]/', static fn ( array $character ): string => sprintf( '%%%02X', ord( $character[0] ) ), $this->name ) . '.sql';
	}
}
