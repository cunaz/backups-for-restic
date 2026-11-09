<?php
/**
 * Removes secrets from text.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Support;

use BackupsForRestic\Secrets\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * Defence in depth for everything written to logs and job files: any occurrence of a known
 * secret, plainly or in the encodings that HTTP and logs use, is replaced. Code should not
 * pass secrets to logs in the first place; this catches mistakes.
 */
final class Redactor {

	/**
	 * Values to remove.
	 *
	 * @var list<Secret>
	 */
	private array $secrets = [];

	/**
	 * Adds a secret to remove. Very short values are ignored: replacing them would destroy
	 * ordinary text while protecting nothing.
	 *
	 * @param Secret $secret Secret.
	 */
	public function add( Secret $secret ): void {
		if ( strlen( $secret->reveal() ) >= 4 ) {
			$this->secrets[] = $secret;
		}
	}

	/**
	 * Redacts a text.
	 *
	 * @param string $text Text.
	 * @return string Text with secrets replaced by [redacted].
	 */
	public function redact( string $text ): string {
		foreach ( $this->secrets as $secret ) {
			$value = $secret->reveal();
			$text  = str_replace( [ $value, rawurlencode( $value ), Bytes::base64_encode( $value ) ], '[redacted]', $text );
		}
		return $text;
	}
}
