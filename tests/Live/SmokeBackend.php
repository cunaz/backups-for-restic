<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Live;

use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\AppendOnlyStatus;
use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Repository;
use RuntimeException;

/** A live smoke run may mutate only content-addressed fixture objects it creates. */
final class SmokeBackend implements Backend {

	private ?string $config_hash = null;
	private bool $bound          = false;
	private bool $probing        = false;

	/** @var array<string,array<string,true>> Successfully written (or attempted after an absent HEAD). */
	private array $created = [];

	public function __construct( private readonly Backend $remote ) {}

	/** Repository::open() supplied and authenticated the config observed by load(). */
	public function bind( Repository $repository, string $expected ): void {
		if ( $this !== $repository->backend() || null === $this->config_hash || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $expected ) || ! hash_equals( $expected, $repository->config()->id ) ) {
			throw new RuntimeException( 'Dedicated smoke repository ID mismatch.' );
		}
		$this->bound = true;
	}

	public function create(): void {
		throw new RuntimeException( 'A hosted smoke run requires an existing, pinned fixture repository.' );
	}

	private function check_config(): void {
		if ( ! $this->bound ) {
			throw new RuntimeException( 'The smoke backend has no verified repository binding.' );
		}
		$this->load( ObjectType::Config, 'config' );
	}

	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$body = $this->remote->load( $type, $name, $offset, $length );
		if ( ObjectType::Config === $type && 0 === $offset && null === $length ) {
			$digest = hash( 'sha256', $body );
			if ( null !== $this->config_hash && ! hash_equals( $this->config_hash, $digest ) ) {
				throw new RuntimeException( 'The smoke repository config was replaced.' );
			}
			$this->config_hash = $digest;
		}
		return $body;
	}

	public function size( ObjectType $type, string $name ): ?int {
		return $this->remote->size( $type, $name );
	}

	public function list( ObjectType $type ): array {
		return $this->remote->list( $type );
	}

	public function list_to_file( ObjectType $type, string $file ): void {
		$this->remote->list_to_file( $type, $file );
	}

	public function save( ObjectType $type, string $name, string $body ): void {
		$this->check_config();
		if ( ! in_array( $type, [ ObjectType::Locks, ObjectType::Data, ObjectType::Index, ObjectType::Snapshots ], true ) || hash( 'sha256', $body ) !== $name ) {
			throw new RuntimeException( 'The smoke run refused an unexpected object write.' );
		}
		if ( null !== $this->remote->size( $type, $name ) ) {
			// Lost reply for our own immutable write is safe. Never claim an older object.
			if ( ! isset( $this->created[ $type->value ][ $name ] ) || hash( 'sha256', $this->remote->load( $type, $name ) ) !== $name ) {
				throw new RuntimeException( 'The smoke object name was already occupied.' );
			}
			return;
		}
		$this->created[ $type->value ][ $name ] = true;
		$this->remote->save( $type, $name, $body );
	}

	public function delete( ObjectType $type, string $name ): void {
		$this->check_config();
		if ( $this->probing && ObjectType::Snapshots === $type && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $name ) && null === $this->remote->size( $type, $name ) ) {
			// The plugin's negative probe needs the server's real refusal for an absent name.
			$this->remote->delete( $type, $name );
			return;
		}
		if ( ! isset( $this->created[ $type->value ][ $name ] ) ) {
			throw new RuntimeException( 'The smoke run refused to delete an object without its own write receipt.' );
		}
		if ( null !== $this->remote->size( $type, $name ) ) {
			if ( hash( 'sha256', $this->remote->load( $type, $name ) ) !== $name ) {
				throw new RuntimeException( 'A recorded smoke object was replaced.' );
			}
			$this->remote->delete( $type, $name );
		}
		unset( $this->created[ $type->value ][ $name ] );
	}

	public function probe(): AppendOnlyStatus {
		$this->probing = true;
		try {
			return AppendOnlyProbe::detect( $this, new SystemRandom() );
		} finally {
			$this->probing = false;
		}
	}

	/** @return list<string> */
	public function written( ObjectType $type ): array {
		return array_keys( $this->created[ $type->value ] ?? [] );
	}

	/** @return array<string,array<string,int>> Non-lock objects that an exclusive cleanup relies on. */
	public function inventory(): array {
		$objects = [];
		foreach ( [ ObjectType::Snapshots, ObjectType::Index, ObjectType::Data, ObjectType::Keys ] as $type ) {
			$names = $this->list( $type );
			if ( count( $names ) > 4096 || in_array( -1, $names, true ) ) {
				throw new RuntimeException( 'The dedicated fixture inventory is too large or lacks REST v2 sizes.' );
			}
			ksort( $names, SORT_STRING );
			$objects[ $type->value ] = $names;
		}
		return $objects;
	}

	/**
	 * Refuse cleanup of indexes and packs if another writer changed the repository.
	 * The caller holds an exclusive restic lock until the checked cleanup is finished.
	 *
	 * @param array<string,array<string,int>> $original Inventory before our first write.
	 */
	public function require_original_objects( array $original ): void {
		foreach ( $this->inventory() as $kind => $objects ) {
			if ( array_diff_key( $objects, $this->created[ $kind ] ?? [] ) !== $original[ $kind ] ) {
				throw new RuntimeException( 'Unrelated objects changed during the smoke run. Keeping fixture data for review.' );
			}
		}
	}
}
