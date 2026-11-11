<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Connection\RepositoryAccess;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Secrets\ConstantOverrideStore;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Tests\Support\InMemoryOptions;
use BackupsForRestic\Tests\Support\InMemoryRestServer;

/**
 * The cache of the master key: a cache, never a reason to stop.
 */
final class RepositoryAccessTest extends TestCase {

	private const string URL = 'https://backup.example.net:8000/site/';

	private InMemoryOptions $options;

	private InMemoryRestServer $server;

	protected function setUp(): void {
		$this->options = new InMemoryOptions();
		$this->server  = new InMemoryRestServer();
	}

	/**
	 * @param array<string, string> $constants Secrets defined in wp-config.php, by constant name.
	 */
	private function access( string $salts = 'salts', array $constants = [] ): RepositoryAccess {
		$stored = new EncryptedOptionStore( $this->options, new Secret( $salts ), new SystemRandom() );
		return new RepositoryAccess(
			Settings::from_stored( [ 'repository.url' => self::URL ] ),
			new ConstantOverrideStore( $stored, $constants ),
			$this->server,
			new SystemRandom(),
			'backups-for-restic-tests'
		);
	}

	private function repository(): Repository {
		return Repository::initialize( $this->access()->backend(), 'repository password', new Identity( 'example.com', 'www-data' ), new SystemRandom(), new ScryptParameters( 1024, 8, 1 ) );
	}

	public function test_the_cached_key_opens_the_repository_it_was_cached_for(): void {
		$repository = $this->repository();
		self::assertNull( $this->access()->open_cached(), 'Nothing is cached yet.' );
		self::assertSame( '', $this->access()->cached_repository_id() );

		$this->access()->cache_master_key( $repository->config(), $repository->master_key() );

		self::assertSame( $repository->config()->id, $this->access()->open_cached()?->config()->id );
		self::assertSame( $repository->config()->id, $this->access()->cached_repository_id() );
	}

	public function test_the_doctor_asks_for_the_passwords_that_the_jobs_would_use(): void {
		$stored = new EncryptedOptionStore( $this->options, new Secret( 'salts' ), new SystemRandom() );
		self::assertTrue( $this->access()->passwords_readable(), 'Nothing stored is nothing undecryptable.' );
		$stored->set( SecretName::RepositoryPassword, new Secret( 'repository password' ) );
		$stored->set( SecretName::RestPassword, new Secret( 'rest password' ) );
		self::assertTrue( $this->access()->passwords_readable() );

		self::assertFalse( $this->access( 'other salts' )->passwords_readable() );
		self::assertFalse( $this->access( 'other salts', [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'from wp-config' ] )->passwords_readable(), 'The REST password is still a stored one.' );
		self::assertTrue(
			$this->access(
				'other salts',
				[
					'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'from wp-config',
					'BACKUPS_FOR_RESTIC_REST_PASSWORD' => 'from wp-config',
				]
			)->passwords_readable(),
			'Stored values that constants override are not used.'
		);
	}

	public function test_a_cached_key_for_another_repository_is_forgotten(): void {
		$first = $this->repository();
		$this->access()->cache_master_key( $first->config(), $first->master_key() );
		$this->server->objects = [];
		$this->repository();

		self::assertNull( $this->access()->open_cached() );
		self::assertSame( '', $this->access()->cached_repository_id(), 'The key of the first repository opens nothing here.' );
	}

	public function test_a_cached_key_that_no_longer_decrypts_is_forgotten_instead_of_stopping_the_jobs(): void {
		$repository = $this->repository();
		$this->access()->cache_master_key( $repository->config(), $repository->master_key() );

		// The salts in wp-config.php changed; the passwords are defined there and survived.
		$constants = [ 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'repository password' ];
		$rotated   = $this->access( 'other salts', $constants );

		self::assertTrue( $rotated->passwords_readable(), 'The Doctor does not stop at a cache.' );
		self::assertNull( $rotated->open_cached(), 'The key has to be derived again.' );
		self::assertSame( 'repository password', $rotated->password()->reveal() );
		self::assertNull( ( new EncryptedOptionStore( $this->options, new Secret( 'other salts' ), new SystemRandom() ) )->get( SecretName::MasterKey ), 'The key that no longer decrypts is gone.' );

		$rotated->cache_master_key( $repository->config(), $repository->master_key() );
		self::assertSame( $repository->config()->id, $this->access( 'other salts', $constants )->open_cached()?->config()->id );
	}
}
