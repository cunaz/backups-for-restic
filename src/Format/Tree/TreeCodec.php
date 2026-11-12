<?php
/**
 * Tree blobs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format\Tree;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Support\GoJson;

defined( 'ABSPATH' ) || exit;

/**
 * A tree blob is the JSON listing of one directory: {"nodes":[…]} followed by a newline, with
 * nodes strictly ordered by name (byte order) as restic's TreeJSONBuilder requires.
 */
final class TreeCodec {

	/**
	 * Encodes nodes into a tree blob.
	 *
	 * @param list<Node> $nodes Nodes sorted by name.
	 * @return string Tree blob.
	 * @throws InvalidArgument If nodes are not strictly ordered by name.
	 */
	public static function encode( array $nodes ): string {
		$parts    = [];
		$previous = null;
		foreach ( $nodes as $node ) {
			if ( null !== $previous && strcmp( $node->name, $previous ) <= 0 ) {
				throw new InvalidArgument( 'Tree nodes must be sorted by name and unique.' );
			}
			$previous = $node->name;
			$parts[]  = $node->to_json();
		}
		return '{"nodes":[' . implode( ',', $parts ) . "]}\n";
	}

	/**
	 * Decodes a tree blob.
	 *
	 * @param string $blob Tree blob.
	 * @return list<Node> Nodes.
	 * @throws FormatException If the blob is not a tree or its names are not strictly ordered.
	 */
	public static function decode( string $blob ): array {
		$data  = GoJson::decode_object( $blob );
		$nodes = $data['nodes'] ?? [];
		if ( ! is_array( $nodes ) || ( [] !== $nodes && ! array_is_list( $nodes ) ) ) {
			throw new FormatException( 'Tree blob has no node list.' );
		}

		$result   = [];
		$previous = null;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				throw new FormatException( 'Tree blob contains an invalid node.' );
			}
			$decoded = Node::from_array( $node );
			if ( null !== $previous && strcmp( $decoded->name, $previous ) <= 0 ) {
				throw new FormatException( 'Tree nodes must be sorted by name and unique.' );
			}
			$previous = $decoded->name;
			$result[] = $decoded;
		}
		return $result;
	}
}
