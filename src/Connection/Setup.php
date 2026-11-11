<?php
/**
 * The repository steps of the setup wizard.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\Job;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Secrets\SecretStore;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a tested server, creates a new repository with a generated password, or stores the
 * password of an existing repository whose key is then derived by an unlock job.
 *
 * None of the three happens while a job is active: the job would go on with another server,
 * another password or without its key in the middle of its work.
 */
final class Setup {

	/** Characters a repository password must have unless the plugin generates it. */
	public const int MIN_PASSWORD_LENGTH = 12;

	/**
	 * Creates the setup.
	 *
	 * @param SettingsStore                $settings Settings.
	 * @param SecretStore                  $secrets  Secrets.
	 * @param \Closure(): RepositoryAccess $access   Builds access from the current settings.
	 * @param RandomSource                 $random   Randomness.
	 * @param Identity                     $identity Recorded in the key file.
	 * @param RepositoryRecord             $record   Which repository the plugin works with.
	 * @param JobStore                     $jobs     Jobs.
	 */
	public function __construct(
		private readonly SettingsStore $settings,
		private readonly SecretStore $secrets,
		private readonly \Closure $access,
		private readonly RandomSource $random,
		private readonly Identity $identity,
		private readonly RepositoryRecord $record,
		private readonly JobStore $jobs
	) {
	}

	/**
	 * A new repository password: 32 characters from a CSPRNG, 192 bits.
	 *
	 * @param RandomSource $random Randomness.
	 * @return Secret Password.
	 */
	public static function generate_password( RandomSource $random ): Secret {
		return new Secret( Bytes::base64url_encode( $random->bytes( 24 ) ) );
	}

	/**
	 * The REST password that may be sent to a server named in a request: the one that came with
	 * the request, or the stored one if the request names the host, port and user name it is
	 * stored for. Without this rule a request that only names another host would get the stored
	 * password sent there.
	 *
	 * A password defined in wp-config.php cannot be replaced by a request. It stays in force
	 * for whatever server is saved, so for another server or user name the request has to
	 * carry exactly that password.
	 *
	 * @param string      $server   borgbase or rest-server.
	 * @param string      $url      Repository URL.
	 * @param string      $username REST username; empty for a server without authentication.
	 * @param Secret|null $given    Password from the request, if any.
	 * @return Secret|null The password to use; null without a user name, when none is sent.
	 * @throws InvalidArgument With the code password_required if the request has to carry the password, or with the code of a rejected URL.
	 */
	public function rest_password( string $server, string $url, string $username, ?Secret $given ): ?Secret {
		if ( '' === $username ) {
			// RestBackend sends no credentials without a user name.
			return $given;
		}
		$current = $this->settings->load();
		$same    = ServerProfile::same_server( ServerProfile::normalize( $server, $url ), $current->string( 'repository.url' ) ) && $username === $current->string( 'repository.rest_username' );
		try {
			$stored = $this->secrets->get( SecretName::RestPassword );
		} catch ( SecretsUndecryptable ) {
			$stored = null;
		}
		if ( null !== $stored && $this->secrets->is_overridden( SecretName::RestPassword ) ) {
			$allowed = ( $same || ( null !== $given && $stored->equals( $given ) ) ) ? $stored : null;
		} else {
			$allowed = $given ?? ( $same ? $stored : null );
		}
		if ( null === $allowed ) {
			InvalidArgument::raise( 'The request names a server or user name that the stored REST password is not for; it has to carry the password.', [ 'code' => 'password_required' ] );
		}
		return $allowed;
	}

	/**
	 * Saves a server after a successful connection test.
	 *
	 * @param string           $server   borgbase or rest-server.
	 * @param ConnectionReport $report   Test result with the normalized URL.
	 * @param string           $username REST username.
	 * @param Secret|null      $password REST password; null keeps the stored one, which must be
	 *                                   stored for this server and user name.
	 * @throws ValidationFailed If a setting is rejected.
	 * @throws InvalidArgument With the code password_required if the stored REST password would end up with another server or user name.
	 * @throws JobConflict If a job is active.
	 */
	public function save_server( string $server, ConnectionReport $report, string $username, ?Secret $password ): void {
		$this->jobs->while_idle(
			function () use ( $server, $report, $username, $password ): void {
				$this->store_server( $server, $report, $username, $password );
			}
		);
	}

	/**
	 * What save_server() does once no job can be in the way.
	 *
	 * @param string           $server   Server address as entered.
	 * @param ConnectionReport $report   Report of the connection test.
	 * @param string           $username REST user name.
	 * @param Secret|null      $password REST password, or null for the stored one.
	 * @throws ValidationFailed If a setting is rejected.
	 * @throws InvalidArgument With the code password_required.
	 */
	private function store_server( string $server, ConnectionReport $report, string $username, ?Secret $password ): void {
		$append_only = match ( $report->append_only ) {
			AppendOnlyStatus::AppendOnly       => 'yes',
			AppendOnlyStatus::DeletionsAllowed => 'no',
			AppendOnlyStatus::Unknown          => 'unknown',
		};
		$changes = [
			'repository.server'        => $server,
			'repository.url'           => $report->url,
			'repository.rest_username' => $username,
			'repository.append_only'   => $append_only,
		];
		if ( 'yes' === $append_only ) {
			$changes['retention.mode'] = 'external';
		}
		$current = $this->settings->load();
		foreach ( array_keys( $changes ) as $path ) {
			if ( $current->is_overridden( $path ) ) {
				unset( $changes[ $path ] );
			}
		}
		// What the jobs will use after the save; a value from wp-config.php stays as it is.
		$next     = $current->with( $changes );
		$url      = $next->string( 'repository.url' );
		$username = $next->string( 'repository.rest_username' );
		$this->rest_password( ServerProfile::detect( $url ), $url, $username, $password );
		// The password first: should storing it fail, the settings still name the server that
		// the stored password is for.
		if ( ! $this->secrets->is_overridden( SecretName::RestPassword ) ) {
			if ( null !== $password ) {
				$this->secrets->set( SecretName::RestPassword, $password );
			} elseif ( '' === $username ) {
				// The server takes no credentials: a password kept from before belongs to none.
				$this->secrets->delete( SecretName::RestPassword );
			}
		}
		$this->settings->update( $changes );
		// The same address is the same repository: a new REST password or user name for it
		// leaves the cached key alone, and deriving it again can take minutes.
		if ( $url !== $current->string( 'repository.url' ) ) {
			$this->secrets->delete( SecretName::MasterKey );
			$this->record->moved();
		}
	}

	/**
	 * Creates a new repository on the saved server.
	 *
	 * The password is stored before the repository exists: a repository whose password was
	 * lost on the way into the database could be opened by nobody. If the repository then does
	 * not come to exist, the password stored before is put back.
	 *
	 * @param Secret|null $password Password to use; a new one is generated if null.
	 * @return Secret The repository password, for the recovery kit.
	 * @throws InvalidArgument With the code password_too_short if a password that somebody chose has fewer than MIN_PASSWORD_LENGTH characters.
	 * @throws AlreadyExists If the server already holds a repository.
	 * @throws JobConflict If a job is active.
	 * @throws PluginException If the password cannot be stored, or the repository cannot be created.
	 * @throws \Throwable Whatever else stopped the creation, after the stored password was put back.
	 */
	public function create( ?Secret $password ): Secret {
		return $this->jobs->while_idle( fn (): Secret => $this->create_repository( $password ) );
	}

	/**
	 * What create() does once no job can be in the way.
	 *
	 * @param Secret|null $password Password to use; a new one is generated if null.
	 * @return Secret The repository password.
	 * @throws PluginException If the password cannot be stored, or the repository cannot be created.
	 * @throws \Throwable Whatever else stopped the creation.
	 */
	private function create_repository( ?Secret $password ): Secret {
		$overridden = $this->secrets->is_overridden( SecretName::RepositoryPassword );
		if ( $overridden ) {
			$password = $this->secrets->get( SecretName::RepositoryPassword );
		}
		// The password is all that protects a new repository; a generated one is long enough.
		if ( null !== $password && mb_strlen( $password->reveal(), 'UTF-8' ) < self::MIN_PASSWORD_LENGTH ) {
			InvalidArgument::raise(
				'The repository password is too short.',
				[
					'code' => 'password_too_short',
					'min'  => self::MIN_PASSWORD_LENGTH,
				]
			);
		}
		$password ??= self::generate_password( $this->random );
		$access     = ( $this->access )();
		$backend    = $access->backend();
		if ( null !== $backend->size( ObjectType::Config, 'config' ) ) {
			// Before anything is stored: the stored password may be the one of that repository.
			AlreadyExists::raise( 'A repository already exists at this location.' );
		}
		$previous = $overridden ? null : $this->stored_password();
		if ( ! $overridden ) {
			$this->secrets->set( SecretName::RepositoryPassword, $password );
		}
		try {
			$repository = Repository::initialize( $backend, $password->reveal(), $this->identity, $this->random );
		} catch ( \Throwable $failure ) {
			// A repository that somebody else created in the meantime is not opened by this password.
			if ( ! $overridden && ( $failure instanceof AlreadyExists || self::certainly_absent( $backend ) ) ) {
				$this->restore_password( $previous );
			}
			throw $failure;
		}
		$access->cache_master_key( $repository->config(), $repository->master_key() );
		$this->record->opened( $repository->config() );
		return $password;
	}

	/**
	 * Whether the server holds no repository after a failed attempt to create one. An attempt
	 * can fail after the server stored the config, for example when its answer got lost; then,
	 * and whenever the server does not say, the new password has to stay. connect() without a
	 * password then unlocks the repository with it.
	 *
	 * @param Backend $backend Storage.
	 * @return bool True only if the server answered that there is no config.
	 */
	private static function certainly_absent( Backend $backend ): bool {
		try {
			return null === $backend->size( ObjectType::Config, 'config' );
		} catch ( PluginException ) {
			return false;
		}
	}

	/**
	 * The repository password from wp-config.php or the database, if there is one that can be
	 * read.
	 *
	 * @return Secret|null Password.
	 */
	private function stored_password(): ?Secret {
		try {
			return $this->secrets->get( SecretName::RepositoryPassword );
		} catch ( SecretsUndecryptable ) {
			return null;
		}
	}

	/**
	 * Puts back the repository password that was stored before a failed attempt to create a
	 * repository. Failing at this must not hide why the repository could not be created.
	 *
	 * @param Secret|null $previous Password stored before, if any.
	 */
	private function restore_password( ?Secret $previous ): void {
		try {
			if ( null === $previous ) {
				$this->secrets->delete( SecretName::RepositoryPassword );
			} else {
				$this->secrets->set( SecretName::RepositoryPassword, $previous );
			}
		} catch ( PluginException ) {
			return;
		}
	}

	/**
	 * Stores the password of an existing repository and queues the unlock job that derives its
	 * key, because the derivation can take longer than one request.
	 *
	 * Nothing is stored while another job is active: that job would lose the cached key in the
	 * middle of its work, and no unlock job could tell whether the new password is right.
	 *
	 * @param Secret|null $password Repository password; null for the one defined in wp-config.php, or else the stored one.
	 * @param int         $now      Unix time.
	 * @return Job The unlock job.
	 * @throws InvalidArgument If there is no password: none given, none in wp-config.php and none stored.
	 * @throws JobConflict If another job is active.
	 */
	public function connect( ?Secret $password, int $now ): Job {
		$overridden = $this->secrets->is_overridden( SecretName::RepositoryPassword );
		if ( null === $password && null === $this->stored_password() ) {
			InvalidArgument::raise( 'No repository password was given.', [ 'code' => 'no_password' ] );
		}
		return $this->jobs->while_idle(
			function () use ( $password, $overridden, $now ): Job {
				if ( null !== $password ) {
					if ( ! $overridden ) {
						$this->secrets->set( SecretName::RepositoryPassword, $password );
					}
					$this->secrets->delete( SecretName::MasterKey );
				}
				return $this->jobs->create( JobType::Unlock, 'setup', $now );
			}
		);
	}
}
