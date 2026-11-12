<?php
/**
 * Why a job failed.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Jobs;

use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\DatabaseFailed;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\LockConflict;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\QuotaExceeded;
use BackupsForRestic\Exception\RepositoryChanged;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\UploadRejected;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Exception\WrongPassword;

defined( 'ABSPATH' ) || exit;

/**
 * A stable error code with a retryable flag (docs/02-architecture.md §14). The code is what
 * the UI translates into a message and a hint; the technical message stays in the log.
 */
final readonly class JobError {

	/** Context codes that replace the code derived from the exception class. */
	private const array CONTEXT_CODES = [ 'no_snapshots', 'retention_external', 'job_unreadable' ];

	/**
	 * Creates the error.
	 *
	 * @param string $code      Stable code, for example repository_unreachable.
	 * @param string $message   Technical message in English, without secrets.
	 * @param bool   $retryable Whether the next tick may simply try again.
	 */
	public function __construct( public string $code, public string $message, public bool $retryable ) {
	}

	/**
	 * Classifies an exception.
	 *
	 * @param \Throwable $error Exception.
	 * @return self Error.
	 */
	public static function from_throwable( \Throwable $error ): self {
		$code = match ( true ) {
			$error instanceof WrongPassword         => 'wrong_password',
			$error instanceof AuthenticationFailed,
			$error instanceof CryptoException       => 'authentication_failed',
			$error instanceof UnsupportedRepository => 'repository_unsupported_version',
			$error instanceof LockConflict          => 'repository_locked',
			$error instanceof RepositoryChanged     => 'repository_changed',
			$error instanceof AppendOnlyRefused     => 'repository_append_only',
			$error instanceof QuotaExceeded         => 'quota_exceeded',
			$error instanceof UploadRejected        => 'upload_rejected',
			$error instanceof Unauthorized          => 'unauthorized',
			$error instanceof NotFound              => 'repository_missing',
			$error instanceof AlreadyExists         => 'repository_exists',
			$error instanceof BackendException      => 'repository_unreachable',
			$error instanceof SecretsUndecryptable  => 'secrets_undecryptable',
			$error instanceof StorageFailed         => 'environment',
			$error instanceof DatabaseFailed        => 'database_dump_failed',
			$error instanceof JobConflict           => 'job_conflict',
			$error instanceof ValidationFailed      => 'configuration',
			$error instanceof FormatException       => 'repository_damaged',
			$error instanceof InvalidArgument       => 'configuration',
			$error instanceof PluginException       => 'internal',
			default                                 => 'internal',
		};
		// A few refusals carry a more precise code in their context.
		$context = $error instanceof PluginException ? $error->context()['code'] ?? null : null;
		if ( in_array( $context, self::CONTEXT_CODES, true ) ) {
			$code = $context;
		}
		$retryable = in_array( $code, [ 'repository_unreachable', 'repository_locked' ], true );
		$message   = $error instanceof PluginException ? $error->getMessage() : 'Unexpected ' . $error::class . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine();
		return new self( $code, $message, $retryable );
	}

	/**
	 * For the job document.
	 *
	 * @return array{code: string, message: string, retryable: bool} Fields.
	 */
	public function to_array(): array {
		return [
			'code'      => $this->code,
			'message'   => $this->message,
			'retryable' => $this->retryable,
		];
	}
}
