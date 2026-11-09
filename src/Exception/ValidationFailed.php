<?php
/**
 * Input was rejected.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Carries one error per rejected field. The codes are stable; the WordPress layer turns them
 * into translated messages.
 */
final class ValidationFailed extends PluginException {

	/**
	 * Error details (with "code") by field.
	 *
	 * @var array<string, array<string, scalar|null>>
	 */
	private array $errors = [];

	/**
	 * Throws the exception with its field errors.
	 *
	 * @param array<string, array<string, scalar|null>> $errors Error details (with "code") by field.
	 * @return never
	 * @throws self Always.
	 */
	public static function raise_for( array $errors ): never {
		$exception         = new self( 'Input is invalid.', [ 'fields' => implode( ', ', array_keys( $errors ) ) ] );
		$exception->errors = $errors;
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is constant; the field errors are data for the caller, never printed raw.
		throw $exception;
	}

	/**
	 * Error details by field.
	 *
	 * @return array<string, array<string, scalar|null>> Errors.
	 */
	public function errors(): array {
		return $this->errors;
	}
}
