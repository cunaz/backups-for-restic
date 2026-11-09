<?php
/**
 * Base class of all exceptions thrown by the plugin.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Exceptions carry a constant message and structured context.
 *
 * Messages never contain data from files, the server or the user. Variable details live in
 * the context array, which logging redacts and the admin UI escapes before display. This
 * keeps secrets and untrusted strings out of PHP error logs and HTML by construction.
 */
abstract class PluginException extends \RuntimeException {

	/**
	 * Structured details for logs and diagnostics.
	 *
	 * @var array<string, scalar|null>
	 */
	private array $context;

	/**
	 * Creates the exception.
	 *
	 * @param string                     $message  Constant, human-readable message.
	 * @param array<string, scalar|null> $context  Variable details.
	 * @param \Throwable|null            $previous Underlying cause.
	 */
	final public function __construct( string $message, array $context = [], ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->context = $context;
	}

	/**
	 * Throws an exception of the called class with structured context.
	 *
	 * Use this instead of "throw new" whenever context is attached, so that the message stays a
	 * constant and variable details stay out of anything that may be displayed.
	 *
	 * @param string                     $message  Constant, human-readable message.
	 * @param array<string, scalar|null> $context  Variable details.
	 * @param \Throwable|null            $previous Underlying cause.
	 * @return never
	 * @throws static Always.
	 */
	public static function raise( string $message, array $context = [], ?\Throwable $previous = null ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a constant; context is logged with redaction and escaped on display, never output raw.
		throw new static( $message, $context, $previous );
	}

	/**
	 * Returns the structured details.
	 *
	 * @return array<string, scalar|null>
	 */
	public function context(): array {
		return $this->context;
	}
}
