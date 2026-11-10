<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Connection\Setup;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\JobConflict;
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
	 * @param array<string, string> $constants Secrets defined in wp-config.php, by constant name.
	 */
	private function wizard( array $constants = [] ): Setup {
		return new Setup(
			new SettingsStore( $this->options ),
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
