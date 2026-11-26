<?php
/**
 * Exception: The memory that PHP's limit leaves does not suffice to decode a compressed object.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The memory that PHP's limit leaves does not suffice to decode a compressed object
 * (Format\Compression). A capacity limit of the host, not damage: a check reports the pack as
 * one it could not read in the memory left (pack_memory), and a restore as restore_blob_memory.
 * It is a FormatException with the code decompression_limit, as the decoder's refusals were
 * before, so that every other caller handles it as before.
 */
final class InsufficientDecodingMemory extends FormatException {
}
