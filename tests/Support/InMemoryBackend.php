<?php
/**
 * Backend for unit tests.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\NotFound;

/**
 * Keeps objects in memory and behaves like rest-server, including append-only mode.
 */
final class InMemoryBackend implements Backend {

	/**
	 * @var array<string, array<string, string>>
	 */
	public array $objects = [];

	public int $requests = 0;

	/** @var (\Closure(ObjectType, string): void)|null Override for large file-backed fixtures. */
	public ?\Closure $listing_file = null;

	public function __construct( public bool $append_only = false ) {
	}

	public function create(): void {
		++$this->requests;
	}

	public function size( ObjectType $type, string $name ): ?int {
		++$this->requests;
		$data = $this->objects[ $type->value ][ $this->key( $type, $name ) ] ?? null;
		return null === $data ? null : strlen( $data );
	}

	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		++$this->requests;
		$data = $this->objects[ $type->value ][ $this->key( $type, $name ) ] ?? null;
		if ( null === $data ) {
			throw new NotFound( 'The object does not exist on the server.' );
		}
		return null === $length ? substr( $data, $offset ) : substr( $data, $offset, $length );
	}

	public function save( ObjectType $type, string $name, string $body ): void {
		++$this->requests;
		$key = $this->key( $type, $name );
		if ( isset( $this->objects[ $type->value ][ $key ] ) ) {
			if ( $this->objects[ $type->value ][ $key ] === $body && ObjectType::Config !== $type ) {
				return;
			}
			throw new AlreadyExists( 'An object with this name already exists.' );
		}
		if ( ObjectType::Config !== $type && hash( 'sha256', $body ) !== $name ) {
			throw new \LogicException( 'rest-server would reject an upload whose content does not match its name.' );
		}
		$this->objects[ $type->value ][ $key ] = $body;
	}

	public function delete( ObjectType $type, string $name ): void {
		++$this->requests;
		if ( $this->append_only && ObjectType::Locks !== $type ) {
			throw new AppendOnlyRefused( 'The server refused to delete an object; the repository is append-only.' );
		}
		unset( $this->objects[ $type->value ][ $this->key( $type, $name ) ] );
	}

	public function list( ObjectType $type ): array {
		++$this->requests;
		return array_map( 'strlen', $this->objects[ $type->value ] ?? [] );
	}

	public function list_to_file( ObjectType $type, string $file ): void {
		if ( null !== $this->listing_file ) {
			( $this->listing_file )( $type, $file );
			return;
		}
		$entries = [];
		foreach ( $this->list( $type ) as $name => $size ) {
			$entries[] = [
				'name' => (string) $name,
				'size' => $size,
			];
		}
		\BackupsForRestic\Storage\Files::write_atomic( $file, json_encode( $entries, JSON_THROW_ON_ERROR ) );
	}

	private function key( ObjectType $type, string $name ): string {
		return ObjectType::Config === $type ? 'config' : $name;
	}
}
