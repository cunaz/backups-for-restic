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
		self::assertTrue( $store->readable() );
	}

	public function test_changed_salts_make_secrets_undecryptable_instead_of_empty(): void {
		$options = new InMemoryOptions();
		$this->store( $options )->set( SecretName::MasterKey, new Secret( '{"key":1}' ) );

		$rotated = $this->store( $options, 'new salts' );
		self::assertFalse( $rotated->readable() );
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
		self::assertFalse( $this->store( $options )->readable() );
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
