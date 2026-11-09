<?php
/**
 * Deterministic stand-in for a large database dump.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Support\ByteSource;

/**
 * Produces SQL-like INSERT statements of an exact total length without holding them in
 * memory, the way database streaming (ADR 0021) feeds the engine. The same seed and length
 * always give the same bytes, and no two rows repeat, so deduplication cannot shrink it.
 */
final class SqlDumpSource implements ByteSource {

	private const array WORDS = [
		'backup',
		'restic',
		'chunk',
		'snapshot',
		'tree',
		'blob',
		'index',
		'pack',
		'lock',
		'key',
		'wordpress',
		'post',
		'page',
		'comment',
		'option',
		'meta',
		'user',
		'term',
		'media',
		'theme',
		'plugin',
		'cron',
		'site',
		'draft',
		'publish',
		'revision',
		'attachment',
		'menu',
		'widget',
		'block',
		'pattern',
		'template',
	];

	private string $buffer = '';

	private int $returned = 0;

	private int $row = 0;

	/**
	 * Creates the source.
	 *
	 * @param string $seed   Varies the content.
	 * @param int    $length Total length in bytes.
	 */
	public function __construct( private readonly string $seed, private readonly int $length ) {
	}

	/**
	 * SHA-256 of the complete content, computed by streaming it once more.
	 *
	 * @param string $seed   Seed.
	 * @param int    $length Total length in bytes.
	 * @return string Hex digest.
	 */
	public static function digest( string $seed, int $length ): string {
		$source  = new self( $seed, $length );
		$context = hash_init( 'sha256' );
		for ( $block = $source->read( 1048576 ); '' !== $block; $block = $source->read( 1048576 ) ) {
			hash_update( $context, $block );
		}
		return hash_final( $context );
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Maximum number of bytes.
	 * @return string Bytes; empty at the end.
	 */
	public function read( int $length ): string {
		$wanted   = min( $length, $this->length - $this->returned );
		$buffered = strlen( $this->buffer );
		while ( $buffered < $wanted ) {
			$rows          = $this->rows( 65536 );
			$this->buffer .= $rows;
			$buffered     += strlen( $rows );
		}
		$block           = substr( $this->buffer, 0, $wanted );
		$this->buffer    = substr( $this->buffer, $wanted );
		$this->returned += $wanted;
		return $block;
	}

	/**
	 * Generates at least $bytes bytes of rows.
	 *
	 * @param int $bytes Minimum length.
	 * @return string Rows.
	 */
	private function rows( int $bytes ): string {
		$rows = '';
		$size = 0;
		while ( $size < $bytes ) {
			$hash  = hash( 'sha256', $this->seed . ':' . $this->row, true );
			$words = [];
			for ( $i = 0; $i < 32; $i++ ) {
				$words[] = self::WORDS[ ord( $hash[ $i ] ) & 31 ];
			}
			$row   = sprintf(
				"INSERT INTO `wp_posts` VALUES (%d,1,'2026-01-01 00:00:00','%s','%s','publish');\n",
				$this->row,
				bin2hex( substr( $hash, 0, 8 ) ),
				implode( ' ', $words )
			);
			$rows .= $row;
			$size += strlen( $row );
			++$this->row;
		}
		return $rows;
	}
}
