<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Connection\ConnectionReport;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\RepositoryRecord;
use BackupsForRestic\Connection\Setup;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Secrets\ConstantOverrideStore;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Storage\SiteState;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\FrozenClock;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\InMemoryRestServer;
use BackupsForRestic\Tests\Support\TempDir;

/**
 * The repository steps of the wizard, without a server: what is stored, and when nothing is.
 */
final class SetupTest extends TestCase {

	private const int NOW = 1_800_000_000;

	private const string URL = 'https://backup.example.net:8000/site/';

	private InMemoryOptions $options;

	private EncryptedOptionStore $stored;

	private string $directory;

	protected function setUp(): void {
		$this->options   = new InMemoryOptions();
		$this->stored    = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		$this->directory = TempDir::create( 'setup' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->directory );
	}

	/**
	 * @param array<string, string>               $constants Secrets and settings defined in wp-config.php, by constant name.
	 * @param (\Closure(): RepositoryAccess)|null $access    Reaches the repository; by default the test fails if anything tries.
	 */
	private function wizard( array $constants = [], ?\Closure $access = null ): Setup {
		return new Setup(
			new SettingsStore( $this->options, $constants ),
			new ConstantOverrideStore( $this->stored, $constants ),
			$access ?? static function (): RepositoryAccess {
				self::fail( 'Nothing here may reach the repository server.' );
			},
			new SystemRandom(),
			new Identity( 'example.com', 'www-data' ),
			new RepositoryRecord( new SettingsStore( $this->options, $constants ), new SiteState( $this->options ), new FrozenClock( self::NOW ) ),
			$this->jobs()
		);
	}

	/**
	 * A wizard whose repository server is in memory, with the server saved in the settings.
	 *
	 * @param array<string, string> $constants Secrets defined in wp-config.php, by constant name.
	 */
	private function wizard_with( InMemoryRestServer $server, array $constants = [] ): Setup {
		( new SettingsStore( $this->options ) )->update(
			[
				'repository.server' => 'rest-server',
				'repository.url'    => self::URL,
			]
		);
		$secrets = new ConstantOverrideStore( $this->stored, $constants );
		return $this->wizard(
			$constants,
			fn (): RepositoryAccess => new RepositoryAccess( ( new SettingsStore( $this->options ) )->load(), $secrets, $server, new SystemRandom(), 'backups-for-restic-tests' )
		);
	}

	/**
	 * Whether Setup::create() gets as far as the repository server with a password.
	 *
	 * @param array<string, string> $constants Secrets defined in wp-config.php.
	 */
	private function reaches_the_server( ?Secret $password, array $constants = [] ): bool {
		$reached = new \LogicException( 'The password was accepted.' );
		try {
			$this->wizard(
				$constants,
				static function () use ( $reached ): RepositoryAccess {
					throw $reached;
				}
			)->create( $password );
		} catch ( \LogicException $caught ) {
			return $caught === $reached;
		}
		return false;
	}

	public function test_a_chosen_repository_password_needs_twelve_characters(): void {
		try {
			$this->wizard()->create( new Secret( 'elevenchars' ) );
			self::fail( 'Eleven characters are too few.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'password_too_short', $e->context()['code'] ?? null );
			self::assertSame( 12, $e->context()['min'] ?? null );
		}
		self::assertNull( $this->stored->get( SecretName::RepositoryPassword ), 'Nothing was created and nothing stored.' );

		self::assertTrue( $this->reaches_the_server( new Secret( 'twelve chars' ) ) );
		self::assertTrue( $this->reaches_the_server( new Secret( 'zwölf Zeiche' ) ), 'Characters count, not bytes.' );
		self::assertTrue( $this->reaches_the_server( null ), 'A generated password is always long enough.' );
		self::assertSame( 32, strlen( Setup::generate_password( new SystemRandom() )->reveal() ) );
	}

	public function test_a_short_repository_password_from_wp_config_is_refused_too(): void {
		try {
			$this->wizard( [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'short' ] )->create( new Secret( 'long enough, but not the one in force' ) );
			self::fail( 'The password from wp-config.php is the one the repository would get.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'password_too_short', $e->context()['code'] ?? null );
		}
		self::assertTrue( $this->reaches_the_server( null, [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'long enough for a repository' ] ) );
	}

	private function jobs(): JobStore {
		return new JobStore( $this->directory . '/jobs', new Redactor(), new SystemRandom() );
	}

	/**
	 * A site that is connected to a rest-server with a user name and a stored REST password.
	 */
	private function connected(): void {
		( new SettingsStore( $this->options ) )->update(
			[
				'repository.server'        => 'rest-server',
				'repository.url'           => self::URL,
				'repository.rest_username' => 'backup',
			]
		);
		$this->stored->set( SecretName::RestPassword, new Secret( 'stored REST password' ) );
	}

	private static function report( string $url ): ConnectionReport {
		return new ConnectionReport( $url, true, 1, AppendOnlyStatus::DeletionsAllowed );
	}

	/**
	 * Asserts that a call is refused because the request has to carry the REST password.
	 *
	 * @param \Closure(): mixed $call Call.
	 */
	private static function assert_password_required( \Closure $call ): void {
		try {
			$call();
			self::fail( 'The stored REST password must not be used.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'password_required', $e->context()['code'] ?? null );
		}
	}

	/**
	 * @return iterable<string, array{string, string, bool}>
	 */
	public static function servers(): iterable {
		yield 'the stored address' => [ self::URL, 'backup', true ];
		yield 'another path on the server' => [ 'https://backup.example.net:8000/another-site', 'backup', true ];
		yield 'as restic writes it, with capitals' => [ 'rest:https://Backup.Example.NET:8000/site/', 'backup', true ];
		yield 'another host' => [ 'https://collector.example.org:8000/site/', 'backup', false ];
		yield 'a host that only starts the same' => [ 'https://backup.example.net.collector.example.org:8000/site/', 'backup', false ];
		yield 'another port' => [ 'https://backup.example.net:8443/site/', 'backup', false ];
		yield 'the default port' => [ 'https://backup.example.net/site/', 'backup', false ];
		yield 'another user name' => [ self::URL, 'someone-else', false ];
		yield 'the user name in other letters' => [ self::URL, 'Backup', false ];
	}

	#[DataProvider( 'servers' )]
	public function test_the_stored_rest_password_only_goes_to_the_server_and_user_name_it_is_stored_for( string $url, string $username, bool $allowed ): void {
		$this->connected();
		if ( $allowed ) {
			self::assertSame( 'stored REST password', $this->wizard()->rest_password( 'rest-server', $url, $username, null )?->reveal() );
			return;
		}
		self::assert_password_required( fn () => $this->wizard()->rest_password( 'rest-server', $url, $username, null ) );
	}

	public function test_a_rest_password_from_the_request_is_used_for_any_server(): void {
		$this->connected();
		$given = new Secret( 'typed for the new server' );
		self::assertSame( $given, $this->wizard()->rest_password( 'rest-server', 'https://collector.example.org/', 'someone-else', $given ) );
		self::assertSame( $given, $this->wizard()->rest_password( 'rest-server', self::URL, 'backup', $given ), 'A new password for the same server replaces the stored one.' );
	}

	public function test_a_url_without_a_port_means_port_443(): void {
		( new SettingsStore( $this->options ) )->update(
			[
				'repository.server'        => 'borgbase',
				'repository.url'           => 'https://abc123.repo.borgbase.com/',
				'repository.rest_username' => 'abc123',
			]
		);
		$this->stored->set( SecretName::RestPassword, new Secret( 'stored REST password' ) );
		self::assertNotNull( $this->wizard()->rest_password( 'borgbase', 'https://abc123.repo.borgbase.com:443', 'abc123', null ) );
	}

	public function test_without_a_stored_rest_password_the_request_has_to_carry_one(): void {
		self::assert_password_required( fn () => $this->wizard()->rest_password( 'rest-server', self::URL, 'backup', null ) );
		$this->connected();
		$rotated = new Setup(
			new SettingsStore( $this->options ),
			new EncryptedOptionStore( $this->options, new Secret( 'other salts' ), new SystemRandom() ),
			static fn () => self::fail( 'Nothing here may reach the repository server.' ),
			new SystemRandom(),
			new Identity( 'example.com', 'www-data' ),
			new RepositoryRecord( new SettingsStore( $this->options ), new SiteState( $this->options ), new FrozenClock( self::NOW ) ),
			$this->jobs()
		);
		self::assert_password_required( static fn () => $rotated->rest_password( 'rest-server', self::URL, 'backup', null ) );
	}

	public function test_a_server_without_a_user_name_gets_no_password(): void {
		$this->connected();
		self::assertNull( $this->wizard()->rest_password( 'rest-server', 'https://collector.example.org/', '', null ) );
	}

	public function test_a_rejected_url_is_reported_as_such(): void {
		$this->connected();
		try {
			$this->wizard()->rest_password( 'rest-server', 'http://backup.example.net:8000/site/', 'backup', null );
			self::fail( 'Plain HTTP must be refused.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'not_https', $e->context()['code'] ?? null );
		}
	}

	public function test_a_rest_password_from_wp_config_only_follows_a_request_that_knows_it(): void {
		$this->connected();
		$wizard = $this->wizard( [ 'BACKUPS_FOR_RESTIC_REST_PASSWORD' => 'from wp-config' ] );

		self::assertSame( 'from wp-config', $wizard->rest_password( 'rest-server', self::URL, 'backup', null )?->reveal() );
		self::assertSame( 'from wp-config', $wizard->rest_password( 'rest-server', self::URL, 'backup', new Secret( 'typed' ) )?->reveal(), 'The password in force is tested, not one that cannot replace it.' );
		self::assertSame( 'from wp-config', $wizard->rest_password( 'rest-server', 'https://new.example.net/', 'backup', new Secret( 'from wp-config' ) )?->reveal() );
		self::assert_password_required( static fn () => $wizard->rest_password( 'rest-server', 'https://collector.example.org/', 'backup', null ) );
		self::assert_password_required( static fn () => $wizard->rest_password( 'rest-server', 'https://collector.example.org/', 'backup', new Secret( 'accepted by the collector' ) ) );
	}

	public function test_saving_another_path_on_the_server_keeps_the_stored_rest_password(): void {
		$this->connected();
		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"of the old repository"}' ) );

		$this->wizard()->save_server( 'rest-server', self::report( 'https://backup.example.net:8000/another-site/' ), 'backup', null );

		self::assertSame( 'https://backup.example.net:8000/another-site/', ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ) );
		self::assertSame( 'stored REST password', $this->stored->get( SecretName::RestPassword )?->reveal() );
		self::assertNull( $this->stored->get( SecretName::MasterKey ), 'Another repository needs its own key.' );
	}

	public function test_saving_another_server_without_its_password_changes_nothing(): void {
		$this->connected();
		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"cached"}' ) );

		self::assert_password_required( fn () => $this->wizard()->save_server( 'rest-server', self::report( 'https://collector.example.org/' ), 'backup', null ) );

		self::assertSame( self::URL, ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ), 'The stored password never becomes the password of another server.' );
		self::assertSame( 'stored REST password', $this->stored->get( SecretName::RestPassword )?->reveal() );
		self::assertSame( '{"key":"cached"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
	}

	public function test_saving_another_server_with_its_password_replaces_the_stored_one(): void {
		$this->connected();

		$this->wizard()->save_server( 'rest-server', self::report( 'https://new.example.net/' ), 'someone-else', new Secret( 'password of the new server' ) );

		$saved = ( new SettingsStore( $this->options ) )->load();
		self::assertSame( [ 'https://new.example.net/', 'someone-else' ], [ $saved->string( 'repository.url' ), $saved->string( 'repository.rest_username' ) ] );
		self::assertSame( 'password of the new server', $this->stored->get( SecretName::RestPassword )?->reveal() );
	}

	public function test_a_rejected_setting_leaves_the_stored_rest_password_alone(): void {
		$this->connected();
		try {
			$this->wizard()->save_server( 'rest-server', self::report( 'https://new.example.net/' ), "two\nlines", new Secret( 'password of the new server' ) );
			self::fail( 'A user name is a single line.' );
		} catch ( ValidationFailed $e ) {
			self::assertArrayHasKey( 'repository.rest_username', $e->errors() );
			self::assertSame( 'stored REST password', $this->stored->get( SecretName::RestPassword )?->reveal(), 'Nothing is stored for a server that is not saved.' );
			self::assertSame( self::URL, ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ) );
		}
	}

	public function test_a_user_name_from_wp_config_counts_when_the_request_names_none(): void {
		$this->connected();
		$wizard = $this->wizard( [ 'BACKUPS_FOR_RESTIC_REST_USERNAME' => 'backup' ] );

		self::assert_password_required( static fn () => $wizard->save_server( 'rest-server', self::report( 'https://collector.example.org/' ), '', null ) );

		self::assertSame( self::URL, ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ), 'The jobs would have sent the stored password with the user name from wp-config.php.' );
	}

	public function test_a_password_from_wp_config_is_not_taken_to_another_server_by_a_request_with_any_password(): void {
		$this->connected();
		$wizard = $this->wizard( [ 'BACKUPS_FOR_RESTIC_REST_PASSWORD' => 'from wp-config' ] );

		self::assert_password_required( static fn () => $wizard->save_server( 'rest-server', self::report( 'https://collector.example.org/' ), 'backup', new Secret( 'accepted by the collector' ) ) );

		self::assertSame( self::URL, ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ) );
	}

	public function test_saving_a_server_without_a_user_name_forgets_the_stored_rest_password(): void {
		$this->connected();

		$this->wizard()->save_server( 'rest-server', self::report( 'https://open.example.net/' ), '', null );

		self::assertSame( '', ( new SettingsStore( $this->options ) )->load()->string( 'repository.rest_username' ) );
		self::assertNull( $this->stored->get( SecretName::RestPassword ), 'It was the password of another server.' );
	}

	public function test_connecting_stores_the_password_forgets_the_cached_key_and_queues_the_unlock_job(): void {
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'password of another repository' ) );
		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"of another repository"}' ) );

		$job = $this->wizard()->connect( new Secret( 'the right password' ), self::NOW );

		self::assertSame( JobType::Unlock, $job->type );
		self::assertSame( 'setup', $job->trigger );
		self::assertSame( JobState::Queued, $this->jobs()->active()?->state );
		self::assertSame( 'the right password', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertNull( $this->stored->get( SecretName::MasterKey ), 'The unlock job has to derive the key from the new password.' );
	}

	public function test_connecting_while_a_job_is_active_stores_nothing(): void {
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'password the running backup needs' ) );
		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"the running backup works with"}' ) );
		$running = $this->jobs()->create( JobType::Backup, 'schedule', self::NOW );

		try {
			$this->wizard()->connect( new Secret( 'any other password' ), self::NOW + 60 );
			self::fail( 'A second job must be refused.' );
		} catch ( JobConflict ) {
			self::assertSame( 'password the running backup needs', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
			self::assertSame( '{"key":"the running backup works with"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
			self::assertSame( $running->id, $this->jobs()->active()?->id );
			self::assertCount( 1, $this->jobs()->latest( 10 ) );
		}
	}

	public function test_connecting_without_a_password_needs_one_from_wp_config_or_a_stored_one(): void {
		try {
			$this->wizard()->connect( null, self::NOW );
			self::fail( 'There is no password to unlock with.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'no_password', $e->context()['code'] ?? null );
			self::assertNull( $this->jobs()->active() );
		}

		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"cached"}' ) );
		$job = $this->wizard( [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'from wp-config' ] )->connect( null, self::NOW );

		self::assertSame( JobType::Unlock, $job->type );
		self::assertNull( $this->stored->get( SecretName::RepositoryPassword ), 'A password from wp-config.php never reaches the database.' );
		self::assertSame( '{"key":"cached"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
	}

	public function test_connecting_without_a_password_unlocks_with_the_stored_one(): void {
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'stored when the repository was created' ) );

		$job = $this->wizard()->connect( null, self::NOW );

		self::assertSame( JobType::Unlock, $job->type );
		self::assertSame( 'stored when the repository was created', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
	}

	public function test_a_server_is_not_saved_while_a_job_is_active(): void {
		$this->connected();
		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"the running backup works with"}' ) );
		$this->jobs()->create( JobType::Backup, 'schedule', self::NOW );

		try {
			$this->wizard()->save_server( 'rest-server', self::report( 'https://new.example.net/' ), 'someone-else', new Secret( 'password of the new server' ) );
			self::fail( 'The running backup would go on with another server.' );
		} catch ( JobConflict ) {
			self::assertSame( self::URL, ( new SettingsStore( $this->options ) )->load()->string( 'repository.url' ) );
			self::assertSame( 'stored REST password', $this->stored->get( SecretName::RestPassword )?->reveal() );
			self::assertSame( '{"key":"the running backup works with"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
		}
	}

	public function test_saving_another_address_drops_what_was_remembered_about_the_old_repository(): void {
		$this->connected();
		$state = new SiteState( $this->options );
		( new SettingsStore( $this->options ) )->update( [ 'repository.id' => str_repeat( 'ab', 32 ) ] );
		$state->bind( str_repeat( 'ab', 32 ), self::NOW - 3600 );
		$state->set( 'check_alert', [ 'errors' => 2 ] );
		$state->set( 'last_backup', [ 'job' => 'in the old repository' ] );

		$this->wizard()->save_server( 'rest-server', self::report( self::URL ), 'backup', new Secret( 'a new REST password for the same address' ) );
		self::assertSame( [ 'errors' => 2 ], $state->get( 'check_alert' ), 'The same address is the same repository.' );
		self::assertSame( str_repeat( 'ab', 32 ), ( new SettingsStore( $this->options ) )->load()->string( 'repository.id' ) );

		$this->wizard()->save_server( 'rest-server', self::report( 'https://backup.example.net:8000/another-site/' ), 'backup', null );

		self::assertSame( [], $state->get( 'check_alert' ) );
		self::assertSame( [], $state->get( 'last_backup' ) );
		self::assertSame( '', ( new SettingsStore( $this->options ) )->load()->string( 'repository.id' ), 'Which repository is there shows when a job opens it.' );
	}

	public function test_a_repository_is_not_created_while_a_job_is_active(): void {
		$server = new InMemoryRestServer();
		$this->jobs()->create( JobType::Backup, 'schedule', self::NOW );

		try {
			$this->wizard_with( $server )->create( null );
			self::fail( 'The running job would lose its password and its key.' );
		} catch ( JobConflict ) {
			self::assertSame( [], $server->requests );
			self::assertNull( $this->stored->get( SecretName::RepositoryPassword ) );
		}
	}

	public function test_creating_stores_the_password_before_the_repository_exists_and_records_the_repository(): void {
		$server = new InMemoryRestServer();
		$stored = null;
		// The config is what makes a repository exist.
		$server->before = function ( string $method, string $path ) use ( &$stored ): ?HttpResponse {
			if ( 'POST' === $method && '/site/config' === $path ) {
				$stored = $this->stored->get( SecretName::RepositoryPassword )?->reveal();
			}
			return null;
		};

		$password = $this->wizard_with( $server )->create( null );

		self::assertSame( $password->reveal(), $stored, 'A repository whose password did not reach the database could be opened by nobody.' );
		self::assertSame( $password->reveal(), $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertArrayHasKey( '/site/config', $server->objects );

		$settings = ( new SettingsStore( $this->options ) )->load();
		$cached   = json_decode( (string) $this->stored->get( SecretName::MasterKey )?->reveal(), true );
		self::assertIsArray( $cached );
		self::assertSame( 1, $settings->int( 'repository.format_version' ) );
		self::assertSame( 64, strlen( $settings->string( 'repository.id' ) ) );
		self::assertSame( $settings->string( 'repository.id' ), $cached['repository'] ?? null );
		self::assertSame( $settings->string( 'repository.id' ), ( new SiteState( $this->options ) )->get( 'repository' )['id'] ?? null );
	}

	public function test_a_repository_that_exists_leaves_the_stored_password_alone(): void {
		$server                          = new InMemoryRestServer();
		$server->objects['/site/config'] = 'the config of a repository that is in use';
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'the only copy of its password' ) );

		try {
			$this->wizard_with( $server )->create( null );
			self::fail( 'There is a repository at this address.' );
		} catch ( AlreadyExists ) {
			self::assertSame( 'the only copy of its password', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
			self::assertSame( [ 'HEAD /site/config' ], $server->requests );
		}
	}

	public function test_a_repository_that_does_not_come_to_exist_gives_the_stored_password_back(): void {
		$server         = new InMemoryRestServer();
		$server->before = static fn ( string $method, string $path ): ?HttpResponse => 'POST' === $method ? new HttpResponse( 403, [], '' ) : null;
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'password of the repository at the other address' ) );

		try {
			$this->wizard_with( $server )->create( null );
			self::fail( 'The server refuses every upload.' );
		} catch ( Unauthorized ) {
			self::assertSame( 'password of the repository at the other address', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
		}

		$this->stored->delete( SecretName::RepositoryPassword );
		try {
			$this->wizard_with( $server )->create( null );
			self::fail( 'The server refuses every upload.' );
		} catch ( Unauthorized ) {
			self::assertNull( $this->stored->get( SecretName::RepositoryPassword ), 'A password for no repository is not left behind.' );
		}
	}

	public function test_the_new_password_stays_when_the_repository_may_exist_after_a_failure(): void {
		$server        = new InMemoryRestServer();
		$server->after = static function ( string $method, string $path ): ?HttpResponse {
			if ( 'POST' === $method && '/site/config' === $path ) {
				throw new BackendException( 'The answer got lost.' );
			}
			return null;
		};
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'password of the repository at the other address' ) );

		try {
			$this->wizard_with( $server )->create( new Secret( 'chosen for the new repository' ) );
			self::fail( 'The request failed.' );
		} catch ( BackendException ) {
			self::assertArrayHasKey( '/site/config', $server->objects, 'The server did store the config.' );
			self::assertSame( 'chosen for the new repository', $this->stored->get( SecretName::RepositoryPassword )?->reveal(), 'It is the only password that opens the repository.' );
		}

		$server->after = null;
		self::assertSame( JobType::Unlock, $this->wizard_with( $server )->connect( null, self::NOW )->type, 'The repository is then unlocked with the stored password.' );
	}

	public function test_a_password_from_wp_config_is_never_stored_when_a_repository_is_created(): void {
		$server         = new InMemoryRestServer();
		$server->before = static fn ( string $method, string $path ): ?HttpResponse => 'POST' === $method ? new HttpResponse( 403, [], '' ) : null;
		$this->stored->set( SecretName::RepositoryPassword, new Secret( 'stored before the constant was defined' ) );

		try {
			$this->wizard_with( $server, [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'long enough for a repository' ] )->create( null );
			self::fail( 'The server refuses every upload.' );
		} catch ( Unauthorized ) {
			self::assertSame( 'stored before the constant was defined', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
		}
	}
}
