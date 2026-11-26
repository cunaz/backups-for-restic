<?php
/**
 * Backend decorator that holds a response as WordPress' HTTP API holds it.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;

/**
 * Passes every request on to another backend; a load holds its bytes three times at once
 * before it returns them, as the Requests library in WordPress does with cURL and with
 * fsockopen: the body it collected, the raw response with the headers in front of it, and the
 * body cut out of that (MemoryBudget::RESPONSE_COPIES). A memory test that reads through it
 * measures what a job inside WordPress takes.
 */
final class WordPressResponses implements Backend {

	/** A status line and an empty header block, as the raw response begins. */
	private const string HEAD = "HTTP/1.1 206 Partial Content\r\n\r\n";

	/**
	 * Creates the decorator.
	 *
	 * @param Backend $inner Backend that answers.
	 */
	public function __construct( private readonly Backend $inner ) {
	}

	/**
	 * Creates the repository layout.
	 */
	public function create(): void {
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
		return $this->inner->size( $type, $name );
	}

	/**
	 * Loads an object or a range of it, with the copies that WordPress makes on the way.
	 *
	 * @param ObjectType $type   Type.
	 * @param string     $name   Name.
	 * @param int        $offset Offset.
	 * @param int|null   $length Length, or null for the rest.
	 * @return string Content.
	 */
	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$collected = $this->inner->load( $type, $name, $offset, $length );
		$raw       = self::HEAD . $collected;
		$body      = substr( $raw, strlen( self::HEAD ) );
		unset( $collected, $raw );
		return $body;
	}

	/**
	 * Saves an object.
	 *
	 * @param ObjectType $type Type.
	 * @param string     $name Name.
	 * @param string     $body Content.
	 */
	public function save( ObjectType $type, string $name, string $body ): void {
		$this->inner->save( $type, $name, $body );
	}

	/**
	 * Deletes an object.
	 *
	 * @param ObjectType $type Type.
	 * @param string     $name Name.
	 */
	public function delete( ObjectType $type, string $name ): void {
		$this->inner->delete( $type, $name );
	}

	/**
	 * Lists the objects of a type.
	 *
	 * @param ObjectType $type Type.
	 * @return array<string, int> Sizes by name.
	 */
	public function list( ObjectType $type ): array {
		return $this->inner->list( $type );
	}

	/** Downloads the listing to a file. */
	public function list_to_file( ObjectType $type, string $file ): void {
		$this->inner->list_to_file( $type, $file );
	}
}
