<?php
// The one WordPress function that Messages calls, for a unit test in a process of its own.
declare( strict_types=1 );

/**
 * Returns the text untranslated.
 *
 * @param string $text Text.
 * @param string $domain Text domain.
 */
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
