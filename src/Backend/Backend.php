<?php
/**
 * Storage for repository objects.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\StorageFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Where repository files live. The plugin's only implementation speaks the restic REST
 * protocol; tests use an in-memory one.
 */
interface Backend {

	/**
	 * Creates the repository layout on the server.
	 *
	 * @throws BackendException If the server refuses.
	 */
	public function create(): void;

	/**
	 * Returns the size of an object, or null if it does not exist.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name (ignored for the config).
	 * @return int|null Size in bytes.
	 * @throws BackendException If the request fails.
	 */
	public function size( ObjectType $type, string $name ): ?int;

	/**
	 * Loads an object or a byte range of it.
	 *
	 * @param ObjectType $type   Object type.
	 * @param string     $name   Object name.
	 * @param int        $offset First byte.
	 * @param int|null   $length Number of bytes, or null for the rest.
	 * @return string Bytes.
	 * @throws NotFound If the object does not exist.
	 * @throws BackendException If the request fails.
	 */
	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string;

	/**
	 * Stores an object. Objects are write-once; storing an existing name fails.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 * @param string     $body Content.
	 * @throws AlreadyExists If an object with this name exists.
	 * @throws BackendException If the request fails.
	 */
	public function save( ObjectType $type, string $name, string $body ): void;

	/**
	 * Deletes an object. Deleting a missing object succeeds.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 * @throws AppendOnlyRefused If the repository is append-only.
	 * @throws BackendException If the request fails.
	 */
	public function delete( ObjectType $type, string $name ): void;

	/**
	 * Lists objects of a type. Like restic, implementations skip names that are not object
	 * ids (64 lowercase hex digits), so a stray file on the server cannot break a run.
	 *
	 * @param ObjectType $type Object type.
	 * @return array<string, int> Sizes by name; -1 when the server reports names only.
	 * @throws BackendException If the request fails.
	 */
	public function list( ObjectType $type ): array;

	/**
	 * Downloads a listing to a private file without holding the whole answer in memory.
	 * The file contains a REST v1/v2 JSON listing for ListingFile. It is replaced atomically;
	 * syntax validation is deliberately deferred to that bounded reader.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $file Destination in the private working directory.
	 * @throws BackendException If the request fails or the response exceeds the listing limit.
	 * @throws StorageFailed If the file cannot be written.
	 */
	public function list_to_file( ObjectType $type, string $file ): void;
}
