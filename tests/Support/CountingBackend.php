<?php
/**
 * Counts requests to a backend.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;

/**
 * Wraps a backend and counts loads per object type.
 */
final class CountingBackend implements Backend {

	/** @var array<string, int> */
	public array $loads = [];

	public function __construct( public readonly Backend $inner = new InMemoryBackend() ) {
	}

	public function create(): void {
		$this->inner->create();
	}

	public function size( ObjectType $type, string $name ): ?int {
		return $this->inner->size( $type, $name );
	}

	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$this->loads[ $type->value ] = ( $this->loads[ $type->value ] ?? 0 ) + 1;
		return $this->inner->load( $type, $name, $offset, $length );
	}

	public function save( ObjectType $type, string $name, string $body ): void {
		$this->inner->save( $type, $name, $body );
	}

	public function delete( ObjectType $type, string $name ): void {
		$this->inner->delete( $type, $name );
	}

	public function list( ObjectType $type ): array {
		return $this->inner->list( $type );
	}
}
