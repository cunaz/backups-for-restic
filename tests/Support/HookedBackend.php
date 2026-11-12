<?php
/**
 * Backend decorator that calls a hook before every request.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;

/**
 * Passes every request on to another backend after a hook has seen it, so that a test can let
 * time pass or make a request fail at a chosen moment.
 */
final class HookedBackend implements Backend {

	/**
	 * Creates the decorator.
	 *
	 * @param Backend                                                    $inner Backend that answers.
	 * @param \Closure(string, ?ObjectType, string, int, ?int): void $hook  Gets the method, type, name and, for loads, the range; may throw.
	 */
	public function __construct( private readonly Backend $inner, private readonly \Closure $hook ) {
	}

	/**
	 * Creates the repository layout.
	 */
	public function create(): void {
		( $this->hook )( 'create', null, '', 0, null );
		$this->inner->create();
	}

	/**
	 * Size of an object.
	 *
	 * @param ObjectType $type Type.
	 * @param string     $name Name.
	 * @return int|null Size, or null if missing.
	 */
	public function size( ObjectType $type, string $name ): ?int {
		( $this->hook )( 'size', $type, $name, 0, null );
		return $this->inner->size( $type, $name );
	}

	/**
	 * Loads an object or a range of it.
	 *
	 * @param ObjectType $type   Type.
	 * @param string     $name   Name.
	 * @param int        $offset Offset.
	 * @param int|null   $length Length, or null for the rest.
	 * @return string Content.
	 */
	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		( $this->hook )( 'load', $type, $name, $offset, $length );
		return $this->inner->load( $type, $name, $offset, $length );
	}

	/**
	 * Saves an object.
	 *
	 * @param ObjectType $type Type.
	 * @param string     $name Name.
	 * @param string     $body Content.
	 */
	public function save( ObjectType $type, string $name, string $body ): void {
		( $this->hook )( 'save', $type, $name, 0, null );
		$this->inner->save( $type, $name, $body );
	}

	/**
	 * Deletes an object.
	 *
	 * @param ObjectType $type Type.
	 * @param string     $name Name.
	 */
	public function delete( ObjectType $type, string $name ): void {
		( $this->hook )( 'delete', $type, $name, 0, null );
		$this->inner->delete( $type, $name );
	}

	/**
	 * Lists the objects of a type.
	 *
	 * @param ObjectType $type Type.
	 * @return array<string, int> Sizes by name.
	 */
	public function list( ObjectType $type ): array {
		( $this->hook )( 'list', $type, '', 0, null );
		return $this->inner->list( $type );
	}

	/** Downloads the listing to a file. */
	public function list_to_file( ObjectType $type, string $file ): void {
		( $this->hook )( 'list', $type, '', 0, null );
		$this->inner->list_to_file( $type, $file );
	}
}
