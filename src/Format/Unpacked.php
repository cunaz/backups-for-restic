<?php
/**
 * Encoding of files stored outside packs.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Format;

use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\UnsupportedRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Index, snapshot and lock files hold encrypted JSON. In repository version 2 restic may
 * prefix the plaintext with an encoding byte: 2 means zstd-compressed JSON, while plaintext that
 * starts with "[" or "{" is plain JSON. The plugin always writes plain JSON, which both versions
 * accept, and reads compressed files only when PHP has the zstd extension (ADR 0019).
 */
final class Unpacked {

	/**
	 * Decodes the plaintext of an unpacked file into JSON.
	 *
	 * The compressed bytes after the encoding byte are a copy. The plaintext is let go before
	 * they are decoded: when the caller passes it as a temporary value, such as the result of
	 * decrypting the file, the decoder then works beside one copy of the compressed bytes
	 * instead of two.
	 *
	 * @param string                     $plaintext Decrypted file content.
	 * @param int                        $version   Repository version.
	 * @param (\Closure(int): void)|null $reserve   Told what decoding takes before it starts, and may refuse (Compression::decompress()).
	 * @return string JSON.
	 * @throws UnsupportedRepository If the file is compressed and zstd is unavailable.
	 * @throws FormatException If the encoding byte is unknown or decompression fails.
	 */
	public static function decode( string $plaintext, int $version, ?\Closure $reserve = null ): string {
		if ( $version < 2 || '' === $plaintext || '[' === $plaintext[0] || '{' === $plaintext[0] ) {
			return $plaintext;
		}
		if ( "\x02" !== $plaintext[0] ) {
			throw new FormatException( 'Repository file uses an unknown encoding.' );
		}
		$compressed = substr( $plaintext, 1 );
		unset( $plaintext );
		return Compression::decompress( $compressed, null, $reserve );
	}
}
