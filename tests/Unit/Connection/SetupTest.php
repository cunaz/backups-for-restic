<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Connection\ConnectionReport;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\Setup;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
use BackupsForRestic\Exception\ValidationFailed;
use BackupsForRestic\Jobs\JobState;
use BackupsForRestic\Jobs\JobStore;
use BackupsForRestic\Jobs\JobType;
use BackupsForRestic\Secrets\ConstantOverrideStore;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\SettingsStore;
use BackupsForRestic\Support\Redactor;
use BackupsForRestic\Tests\Support\InMemoryOptions;
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
	 * @param array<string, string> $constants Secrets and settings defined in wp-config.php, by constant name.
	 */
	private function wizard( array $constants = [] ): Setup {
		return new Setup(
			new SettingsStore( $this->options, $constants ),
			new ConstantOverrideStore( $this->stored, $constants ),
			static function (): RepositoryAccess {
				self::fail( 'Nothing here may reach the repository server.' );
			},
			new SystemRandom(),
			new Identity( 'example.com', 'www-data' )
		);
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
			new Identity( 'example.com', 'www-data' )
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

		$job = $this->wizard()->connect( new Secret( 'the right password' ), $this->jobs(), self::NOW );

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
			$this->wizard()->connect( new Secret( 'any other password' ), $this->jobs(), self::NOW + 60 );
			self::fail( 'A second job must be refused.' );
		} catch ( JobConflict ) {
			self::assertSame( 'password the running backup needs', $this->stored->get( SecretName::RepositoryPassword )?->reveal() );
			self::assertSame( '{"key":"the running backup works with"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
			self::assertSame( $running->id, $this->jobs()->active()?->id );
			self::assertCount( 1, $this->jobs()->latest( 10 ) );
		}
	}

	public function test_connecting_without_a_password_needs_the_one_from_wp_config(): void {
		try {
			$this->wizard()->connect( null, $this->jobs(), self::NOW );
			self::fail( 'There is no password to unlock with.' );
		} catch ( InvalidArgument $e ) {
			self::assertSame( 'no_password', $e->context()['code'] ?? null );
			self::assertNull( $this->jobs()->active() );
		}

		$this->stored->set( SecretName::MasterKey, new Secret( '{"key":"cached"}' ) );
		$job = $this->wizard( [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'from wp-config' ] )->connect( null, $this->jobs(), self::NOW );

		self::assertSame( JobType::Unlock, $job->type );
		self::assertNull( $this->stored->get( SecretName::RepositoryPassword ), 'A password from wp-config.php never reaches the database.' );
		self::assertSame( '{"key":"cached"}', $this->stored->get( SecretName::MasterKey )?->reveal() );
	}
}
