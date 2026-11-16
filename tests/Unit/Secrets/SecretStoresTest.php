<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Secrets;

use PHPUnit\Framework\TestCase;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\SecretsUndecryptable;
use BackupsForRestic\Secrets\ConstantOverrideStore;
use BackupsForRestic\Secrets\EncryptedOptionStore;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Secrets\SecretName;
use BackupsForRestic\Storage\Options;
use BackupsForRestic\Tests\Support\InMemoryOptions;

final class SecretStoresTest extends TestCase {

	private const string SALTS = 'auth-key|secure-auth-key|logged-in-key|nonce-key|auth-salt|secure-auth-salt|logged-in-salt|nonce-salt';

	private function store( InMemoryOptions $options, string $salts = self::SALTS ): EncryptedOptionStore {
		return new EncryptedOptionStore( $options, new Secret( $salts ), new SystemRandom() );
	}

	public function test_round_trip_keeps_only_ciphertext_in_a_non_autoloaded_option(): void {
		$options = new InMemoryOptions();
		$store   = $this->store( $options );
		self::assertNull( $store->get( SecretName::RepositoryPassword ) );

		$store->set( SecretName::RepositoryPassword, new Secret( 'repository password' ) );
		$store->set( SecretName::RestPassword, new Secret( 'rest password' ) );

		self::assertSame( 'repository password', $store->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertSame( 'rest password', $this->store( $options )->get( SecretName::RestPassword )?->reveal(), 'A new instance with the same salts decrypts.' );
		self::assertFalse( $options->autoload[ EncryptedOptionStore::OPTION ] );
		$raw = print_r( $options->values, true );
		self::assertStringNotContainsString( 'repository password', $raw );
		self::assertStringNotContainsString( 'rest password', $raw );
		self::assertMatchesRegularExpression( '/^v1\.[A-Za-z0-9_-]{32}\.[A-Za-z0-9_-]+$/', $options->values[ EncryptedOptionStore::OPTION ]['secrets']['repository_password'] );
	}

	public function test_storing_a_secret_keeps_one_that_another_request_stored_since_this_one_read(): void {
		$options                = new InMemoryOptions();
		$options->request_cache = true;
		$tick                   = new EncryptedOptionStore( $options, new Secret( 'salts' ), new SystemRandom() );
		$tick->set( SecretName::RepositoryPassword, new Secret( 'repository password' ) );
		// While the tick derives the key, the administrator saves a new REST password.
		$admin         = new InMemoryOptions();
		$admin->values = $options->values;
		( new EncryptedOptionStore( $admin, new Secret( 'salts' ), new SystemRandom() ) )->set( SecretName::RestPassword, new Secret( 'rest password' ) );
		$options->values = $admin->values;

		$tick->set( SecretName::MasterKey, new Secret( '{"key":"derived"}' ) );

		$after         = new EncryptedOptionStore( $admin, new Secret( 'salts' ), new SystemRandom() );
		$admin->values = $options->values;
		self::assertSame( 'rest password', $after->get( SecretName::RestPassword )?->reveal() );
		self::assertSame( '{"key":"derived"}', $after->get( SecretName::MasterKey )?->reveal() );
	}

	/**
	 * An options table that stops keeping what it is given, or changes it on the way.
	 */
	private function unreliable_options(): Options {
		return new class() implements Options {
			/** Whether writes are dropped, as when the database refuses an update. */
			public bool $read_only = false;

			/** Whether stored texts lose their last characters, as with a column that is too short. */
			public bool $truncating = false;

			private mixed $value = null;

			public function get( string $name ): mixed {
				return $this->value;
			}

			public function fresh( string $name ): mixed {
				return $this->value;
			}

			public function initialize( string $name, mixed $value, bool $autoload ): bool {
				throw new \LogicException( 'The secret store never initializes layout numbers.' );
			}

			public function set( string $name, mixed $value, bool $autoload ): bool {
				if ( $this->read_only ) {
					return false;
				}
				if ( $this->truncating && is_array( $value ) && is_array( $value['secrets'] ?? null ) ) {
					$value['secrets'] = array_map( static fn ( string $box ): string => substr( $box, 0, -4 ), $value['secrets'] );
				}
				$this->value = $value;
				return true;
			}

			public function delete( string $name ): void {
				$this->value = null;
			}
		};
	}

	public function test_a_secret_that_was_not_kept_does_not_pass_for_saved(): void {
		$options            = $this->unreliable_options();
		$store              = new EncryptedOptionStore( $options, new Secret( self::SALTS ), new SystemRandom() );
		$options->read_only = true;
		try {
			$store->set( SecretName::RepositoryPassword, new Secret( 'never stored' ) );
			self::fail( 'Nothing was stored.' );
		} catch ( SecretsUndecryptable $e ) {
			self::assertSame( 'repository_password', $e->context()['secret'] ?? null );
		}

		$options->read_only = false;
		$store->set( SecretName::RepositoryPassword, new Secret( 'the old password' ) );
		$options->read_only = true;
		try {
			$store->set( SecretName::RepositoryPassword, new Secret( 'the new password' ) );
			self::fail( 'The old password is still the stored one.' );
		} catch ( SecretsUndecryptable ) {
			self::assertSame( 'the old password', $store->get( SecretName::RepositoryPassword )?->reveal() );
		}
	}

	public function test_a_secret_that_comes_back_changed_does_not_pass_for_saved(): void {
		$options             = $this->unreliable_options();
		$options->truncating = true;
		$this->expectException( SecretsUndecryptable::class );
		( new EncryptedOptionStore( $options, new Secret( self::SALTS ), new SystemRandom() ) )->set( SecretName::RestPassword, new Secret( 'rest password' ) );
	}

	public function test_changed_salts_make_secrets_undecryptable_instead_of_empty(): void {
		$options = new InMemoryOptions();
		$this->store( $options )->set( SecretName::MasterKey, new Secret( '{"key":1}' ) );

		$rotated = $this->store( $options, 'new salts' );
		$this->expectException( SecretsUndecryptable::class );
		$rotated->get( SecretName::MasterKey );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$options = new InMemoryOptions();
		$store   = $this->store( $options );
		$store->set( SecretName::RestPassword, new Secret( 'rest password' ) );
		$box = $options->values[ EncryptedOptionStore::OPTION ]['secrets']['rest_password'];
		$options->values[ EncryptedOptionStore::OPTION ]['secrets']['rest_password'] = substr( $box, 0, -2 ) . ( str_ends_with( $box, 'A' ) ? 'B' : 'A' );

		$this->expectException( SecretsUndecryptable::class );
		$store->get( SecretName::RestPassword );
	}

	public function test_salt_is_created_once_and_secrets_can_be_deleted(): void {
		$options = new InMemoryOptions();
		$store   = $this->store( $options );
		$store->set( SecretName::RestPassword, new Secret( 'a' ) );
		$salt = $options->values[ EncryptedOptionStore::OPTION ]['salt'];
		$store->set( SecretName::RepositoryPassword, new Secret( 'b' ) );
		self::assertSame( $salt, $options->values[ EncryptedOptionStore::OPTION ]['salt'] );

		$store->delete( SecretName::RestPassword );
		self::assertNull( $store->get( SecretName::RestPassword ) );
		self::assertSame( 'b', $store->get( SecretName::RepositoryPassword )?->reveal() );
	}

	public function test_missing_salt_constants_still_work_and_differ_from_real_ones(): void {
		$options = new InMemoryOptions();
		$this->store( $options, '' )->set( SecretName::RestPassword, new Secret( 'x' ) );
		self::assertSame( 'x', $this->store( $options, '' )->get( SecretName::RestPassword )?->reveal() );
		$this->expectException( SecretsUndecryptable::class );
		$this->store( $options )->get( SecretName::RestPassword );
	}

	public function test_constants_win_and_are_read_only(): void {
		$options = new InMemoryOptions();
		$inner   = $this->store( $options );
		$inner->set( SecretName::RepositoryPassword, new Secret( 'stored' ) );
		$store = new ConstantOverrideStore(
			$inner,
			[
				'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD' => 'from wp-config',
				'BACKUPS_FOR_RESTIC_REST_PASSWORD'       => '',
			]
		);

		self::assertSame( 'from wp-config', $store->get( SecretName::RepositoryPassword )?->reveal() );
		self::assertTrue( $store->is_overridden( SecretName::RepositoryPassword ) );
		self::assertFalse( $store->is_overridden( SecretName::RestPassword ), 'An empty constant does not count.' );
		self::assertFalse( $store->is_overridden( SecretName::MasterKey ) );

		$store->set( SecretName::MasterKey, new Secret( 'cached key' ) );
		self::assertSame( 'cached key', $store->get( SecretName::MasterKey )?->reveal() );

		$this->expectException( InvalidArgument::class );
		$store->set( SecretName::RepositoryPassword, new Secret( 'other' ) );
	}

	public function test_constants_do_not_show_in_dumps(): void {
		$store = new ConstantOverrideStore( $this->store( new InMemoryOptions() ), [ 'BACKUPS_FOR_RESTIC_REST_PASSWORD' => 'very secret' ] );
		self::assertStringNotContainsString( 'very secret', print_r( $store, true ) . var_export( $store, true ) );
	}
}
