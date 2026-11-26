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
use BackupsForRestic\Exception\IndexTooLarge;
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
	private const array CONTEXT_CODES = [ 'decompression_limit', 'no_snapshots', 'retention_external', 'job_unreadable', 'staging_unverified', 'file_plan_too_large', 'file_space', 'database_preview_missing', 'database_restore_prefix', 'database_restore_collation', 'database_restore_privilege', 'database_restore_format', 'database_restore_failed', 'database_restore_busy', 'database_admin_collision', 'database_admin_capabilities', 'database_admin_capabilities_unreadable', 'database_roles_invalid', 'database_roles_too_large', 'database_plugins_invalid', 'database_plugins_too_large', 'database_wordpress_version', 'database_review_required', 'database_exchange_changed', 'database_exchange_failed', 'database_exchange_cleanup', 'staging_space', 'staging_write', 'restore_path_missing', 'protected_path_invalid', 'database_exchange_sessions', 'performance_schema', 'database_exchange_foreign', 'database_binding_missing', 'database_binding_verified', 'database_exchange_rebuilt', 'database_restore_packet', 'file_writes_refused', 'maintenance_opcache', 'undo_nothing_to_revert', 'undo_review_required', 'database_dump_session', 'database_dump_unstable', 'database_dump_row_memory', 'database_dump_locked', 'file_changed_after_review', 'file_staging_gone', 'file_preview_expired', 'undo_unavailable', 'maintenance_busy', 'maintenance_failed', 'maintenance_stuck', 'key_missing', 'setup_incomplete', 'restore_snapshot_missing', 'undo_records_unreadable' ];

	/**
	 * The code of a failed file preview, apply or undo whose exception carries no code of its
	 * own. The codes that the exception classes give name backup and repository failures:
	 * "another backup job is still running" for a JobConflict, "this server cannot run the
	 * backup" for a StorageFailed. These jobs have neither; a file changed, or a private
	 * record of the job could not be read or written, and the job's log names which.
	 */
	public const array FILE_JOB_CODES = [
		'file_plan' => 'file_preview_failed',
		'apply'     => 'file_apply_failed',
		'undo'      => 'file_undo_failed',
	];

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
	 * Classifies an exception. A code that the exception carries in its context wins; for a
	 * file preview, apply or undo, a plugin exception without one gets the job's own code
	 * (FILE_JOB_CODES). Whether the runner tries again depends on the class or the context
	 * code only, as before: the job's own codes are never retried.
	 *
	 * @param \Throwable   $error Exception.
	 * @param JobType|null $type  Type of the job that the exception ended, or null outside a job.
	 * @return self Error.
	 */
	public static function from_throwable( \Throwable $error, ?JobType $type = null ): self {
		$code = match ( true ) {
			$error instanceof WrongPassword         => 'wrong_password',
			$error instanceof AuthenticationFailed,
			$error instanceof CryptoException       => 'authentication_failed',
			$error instanceof UnsupportedRepository => 'repository_unsupported_version',
			$error instanceof LockConflict          => 'repository_locked',
			$error instanceof RepositoryChanged     => 'repository_changed',
			$error instanceof AppendOnlyRefused     => 'repository_append_only',
			$error instanceof IndexTooLarge         => 'index_too_large',
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
		$precise = in_array( $context, self::CONTEXT_CODES, true );
		if ( $precise ) {
			$code = $context;
		}
		$retryable = in_array( $code, [ 'repository_unreachable', 'repository_locked', 'database_restore_busy', 'database_exchange_sessions', 'database_dump_locked' ], true );
		if ( ! $precise && $error instanceof PluginException && null !== $type && isset( self::FILE_JOB_CODES[ $type->value ] ) ) {
			$code = self::FILE_JOB_CODES[ $type->value ];
		}
		$message = $error instanceof PluginException ? $error->getMessage() : 'Unexpected ' . $error::class . ' at ' . basename( $error->getFile() ) . ':' . $error->getLine();
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
