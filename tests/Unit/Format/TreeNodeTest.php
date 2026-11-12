<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Format\Tree\FileMode;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\NodeType;
use BackupsForRestic\Format\Tree\TreeCodec;

/**
 * Nodes follow restic's data.Node JSON (omitempty rules, unsigned 64-bit numbers) and file
 * modes follow Go's os.FileMode as restic stores it.
 */
final class TreeNodeTest extends TestCase {

	public function test_a_node_without_metadata_writes_only_the_fields_restic_never_omits(): void {
		self::assertSame(
			'{"name":"x","type":"file","mtime":"0001-01-01T00:00:00Z","atime":"0001-01-01T00:00:00Z","ctime":"0001-01-01T00:00:00Z","uid":0,"gid":0,"content":null}',
			( new Node( 'x', NodeType::File ) )->to_json()
		);
	}

	public function test_numbers_beyond_php_integers_survive_a_round_trip(): void {
		$blob = '{"nodes":[{"name":"sda","type":"dev","mode":67109296,"mtime":"2026-01-02T03:04:05Z","atime":"2026-01-02T03:04:05Z",'
			. '"ctime":"2026-01-02T03:04:05Z","uid":0,"gid":6,"inode":18446744073709551615,"device_id":9223372036854775808,'
			. '"device":18446744073709551614,"content":null}]}' . "\n";

		$node = TreeCodec::decode( $blob )[0];
		self::assertSame( '18446744073709551615', $node->inode );
		self::assertSame( '9223372036854775808', $node->device_id );
		self::assertSame( '18446744073709551614', $node->device );
		self::assertSame( $blob, TreeCodec::encode( [ $node ] ) );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, string}>
	 */
	public static function mistyped_fields(): iterable {
		yield 'mtime that is not a string' => [ [ 'mtime' => 5 ], 'mtime' ];
		yield 'negative uid' => [ [ 'uid' => -1 ], 'uid' ];
		yield 'size that is not a number' => [ [ 'size' => '5' ], 'size' ];
		yield 'inode with a leading letter' => [ [ 'inode' => 'x1' ], 'inode' ];
		yield 'inode with a trailing letter' => [ [ 'inode' => '1x' ], 'inode' ];
		yield 'inode with 21 digits' => [ [ 'inode' => '123456789012345678901' ], 'inode' ];
		yield 'negative device' => [ [ 'device' => -1 ], 'device' ];
	}

	/**
	 * @param array<string, mixed> $fields Fields added to a valid file node.
	 * @param string               $name   Field that must be named in the exception.
	 */
	#[DataProvider( 'mistyped_fields' )]
	public function test_mistyped_fields_are_rejected_with_their_name( array $fields, string $name ): void {
		$node = [
			'name' => 'f',
			'type' => 'file',
		];
		try {
			Node::from_array( $fields + $node );
			self::fail( 'The field ' . $name . ' was accepted.' );
		} catch ( FormatException $exception ) {
			self::assertSame( [ 'field' => $name ], $exception->context() );
		}
	}

	public function test_a_directory_needs_a_subtree(): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Directory node lacks a subtree.' );
		Node::from_array(
			[
				'name' => 'dir',
				'type' => 'dir',
			]
		);
	}

	public function test_an_empty_directory_is_an_empty_node_list(): void {
		self::assertSame( "{\"nodes\":[]}\n", TreeCodec::encode( [] ) );
		self::assertSame( [], TreeCodec::decode( "{\"nodes\":[]}\n" ) );
	}

	public function test_the_node_list_must_be_a_json_array(): void {
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Tree blob has no node list.' );
		TreeCodec::decode( '{"nodes":{"a":{"name":"a","type":"file"}}}' );
	}

	public function test_node_names_must_be_unique(): void {
		$this->expectException( InvalidArgument::class );
		TreeCodec::encode( [ new Node( 'a', NodeType::File, content: [] ), new Node( 'a', NodeType::File, content: [] ) ] );
	}

	/**
	 * @return iterable<string, array{list<string>}>
	 */
	public static function unordered_names(): iterable {
		yield 'reverse order' => [ [ 'b', 'a' ] ];
		yield 'disorder after a valid prefix' => [ [ 'a', 'c', 'b' ] ];
		yield 'duplicate' => [ [ 'a', 'a' ] ];
		yield 'duplicate after a valid prefix' => [ [ 'a', 'b', 'b' ] ];
		yield 'numeric names use byte order' => [ [ '2', '10' ] ];
		yield 'case sensitive' => [ [ 'a', 'Z' ] ];
		yield 'raw bytes, not their escapes' => [ [ "\xff", 'a' ] ];
	}

	/**
	 * @param list<string> $names Names as raw bytes.
	 */
	#[DataProvider( 'unordered_names' )]
	public function test_reading_refuses_unordered_or_duplicate_node_names( array $names ): void {
		$nodes = array_map( static fn ( string $name ): string => ( new Node( $name, NodeType::File, content: [] ) )->to_json(), $names );
		$this->expectException( FormatException::class );
		$this->expectExceptionMessage( 'Tree nodes must be sorted by name and unique.' );
		TreeCodec::decode( '{"nodes":[' . implode( ',', $nodes ) . ']}' );
	}

	public function test_order_is_checked_after_decoding_the_name(): void {
		$this->expectException( FormatException::class );
		TreeCodec::decode( '{"nodes":[{"name":"a","type":"file"},{"name":"\\\\x61","type":"file"}]}' );
	}

	public function test_reading_accepts_byte_order_including_escaped_and_non_utf8_names(): void {
		$names = [ "\x01", '10', '2', 'Z', '\\', 'a', "\x7f", "\x80", "\xff" ];
		$nodes = array_map( static fn ( string $name ): string => ( new Node( $name, NodeType::File, content: [] ) )->to_json(), $names );
		$read  = TreeCodec::decode( '{"nodes":[' . implode( ',', $nodes ) . ']}' );
		self::assertSame( $names, array_map( static fn ( Node $node ): string => $node->name, $read ) );
	}

	/**
	 * Go's os.FileMode from a stat() mode as os.Lstat() builds it on Linux, the node type restic
	 * derives from it, and the permissions to restore. A file type that stat() does not define
	 * gets no type bits, as in Go; the plugin records such an entry as irregular.
	 *
	 * @return iterable<string, array{int, int, NodeType, int}>
	 */
	public static function posix_modes(): iterable {
		yield 'regular file' => [ 0100644, 0644, NodeType::File, 0644 ];
		yield 'directory' => [ 040755, FileMode::DIR | 0755, NodeType::Dir, 0755 ];
		yield 'setgid directory' => [ 042775, FileMode::DIR | FileMode::SETGID | 0775, NodeType::Dir, 02775 ];
		yield 'setuid and sticky file' => [ 0105700, FileMode::SETUID | FileMode::STICKY | 0700, NodeType::File, 05700 ];
		yield 'symlink' => [ 0120777, FileMode::SYMLINK | 0777, NodeType::Symlink, 0777 ];
		yield 'block device' => [ 060660, FileMode::DEVICE | 0660, NodeType::Dev, 0660 ];
		yield 'character device' => [ 020620, FileMode::DEVICE | FileMode::CHAR_DEVICE | 0620, NodeType::CharDev, 0620 ];
		yield 'named pipe' => [ 010644, FileMode::NAMED_PIPE | 0644, NodeType::Fifo, 0644 ];
		yield 'socket' => [ 0140755, FileMode::SOCKET | 0755, NodeType::Socket, 0755 ];
		yield 'unknown type' => [ 0170644, 0644, NodeType::Irregular, 0644 ];
	}

	#[DataProvider( 'posix_modes' )]
	public function test_file_modes_follow_go( int $st_mode, int $go_mode, NodeType $type, int $permissions ): void {
		self::assertSame( $go_mode, FileMode::from_posix( $st_mode ) );
		self::assertSame( $type, FileMode::type_from_posix( $st_mode ) );
		self::assertSame( $permissions, FileMode::to_posix_permissions( $go_mode ) );
	}
}
