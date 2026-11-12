<?php
/**
 * An opened repository.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\Cipher;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Crypto\Scrypt;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AuthenticationFailed;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\CryptoException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\IndexTooLarge;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\UnsupportedRepository;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\BlobType;
use BackupsForRestic\Format\Config;
use BackupsForRestic\Format\Id;
use BackupsForRestic\Format\Index\IndexFile;
use BackupsForRestic\Format\Index\IndexSet;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Format\Pack\PackReader;
use BackupsForRestic\Format\Snapshot;
use BackupsForRestic\Format\Tree\Node;
use BackupsForRestic\Format\Tree\TreeCodec;
use BackupsForRestic\Format\Unpacked;
use BackupsForRestic\Support\Rfc3339;

defined( 'ABSPATH' ) || exit;

/**
 * A repository whose master key is known: loads and stores encrypted objects.
 */
final class Repository {

	/**
	 * Cipher with the master key.
	 *
	 * @var Cipher
	 */
	private Cipher $cipher;

	/**
	 * The memory the job that uses this repository may take, if PHP limits it.
	 *
	 * @var MemoryBudget|null
	 */
	private ?MemoryBudget $memory = null;

	/**
	 * Creates the handle.
	 *
	 * @param Backend      $backend    Storage.
	 * @param Config       $config     Repository configuration.
	 * @param MasterKey    $master_key Master key.
	 * @param RandomSource $random     Randomness for nonces and IDs.
	 */
	private function __construct(
		private readonly Backend $backend,
		private readonly Config $config,
		private readonly MasterKey $master_key,
		private readonly RandomSource $random
	) {
		$this->cipher = new Cipher( $master_key, $random );
	}

	/**
	 * Creates a new repository (format version 1, ADR 0019).
	 *
	 * @param Backend               $backend    Empty storage.
	 * @param string                $password   Repository password.
	 * @param Identity              $identity   Recorded in the key file.
	 * @param RandomSource          $random     Randomness.
	 * @param ScryptParameters|null $parameters Key derivation cost; restic's defaults if null.
	 * @return self Opened repository.
	 * @throws AlreadyExists If the server already holds a repository.
	 */
	public static function initialize( Backend $backend, #[\SensitiveParameter] string $password, Identity $identity, RandomSource $random, ?ScryptParameters $parameters = null ): self {
		if ( null !== $backend->size( ObjectType::Config, 'config' ) ) {
			AlreadyExists::raise( 'A repository already exists at this location.' );
		}

		$backend->create();
		$parameters ??= ScryptParameters::for_new_keys();
		$config       = Config::create( $random );
		$master       = MasterKey::generate( $random );
		$salt         = $random->bytes( KeyFile::SALT_LENGTH );
		$user_key     = MasterKey::from_kdf_output( Scrypt::derive( $password, $salt, $parameters ) );
		$key          = KeyFile::create( $master, $user_key, $salt, $parameters, $random, Rfc3339::format( time() ), $identity->username, $identity->hostname );
		$key_json     = $key->to_json();

		// Same order as restic: the key first, then the config that marks the repository as initialized.
		$backend->save( ObjectType::Keys, Id::of( $key_json )->hex(), $key_json );
		$repository = new self( $backend, $config, $master, $random );
		$backend->save( ObjectType::Config, 'config', $repository->cipher->seal( $config->to_json() ) );
		return $repository;
	}

	/**
	 * Opens a repository with a known master key, for example one cached after unlocking.
	 *
	 * @param Backend      $backend    Storage.
	 * @param MasterKey    $master_key Master key.
	 * @param RandomSource $random     Randomness.
	 * @return self Opened repository.
	 */
	public static function open( Backend $backend, MasterKey $master_key, RandomSource $random ): self {
		$cipher = new Cipher( $master_key, $random );
		$config = Config::from_json( $cipher->open( $backend->load( ObjectType::Config, 'config' ) ) );
		return new self( $backend, $config, $master_key, $random );
	}

	/**
	 * Finds the master key for a password by trying every key file (synchronous; the plugin's
	 * jobs use the resumable KeyUnlock instead).
	 *
	 * @param Backend      $backend  Storage.
	 * @param string       $password Password.
	 * @param RandomSource $random   Randomness.
	 * @return MasterKey Master key.
	 * @throws WrongPassword If no key file accepts the password.
	 */
	public static function unlock( Backend $backend, #[\SensitiveParameter] string $password, RandomSource $random ): MasterKey {
		foreach ( array_keys( $backend->list( ObjectType::Keys ) ) as $name ) {
			$key = KeyFile::from_json( $backend->load( ObjectType::Keys, $name ) );
			try {
				$user_key = MasterKey::from_kdf_output( Scrypt::derive( $password, $key->salt, $key->scrypt_parameters() ) );
				return $key->open( $user_key, $random );
			} catch ( WrongPassword ) {
				continue;
			}
		}
		WrongPassword::raise( 'No key in the repository accepts the password.' );
	}

	/**
	 * Returns the configuration.
	 *
	 * @return Config Configuration.
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Returns the storage.
	 *
	 * @return Backend Backend.
	 */
	public function backend(): Backend {
		return $this->backend;
	}

	/**
	 * Returns the cipher with the master key.
	 *
	 * @return Cipher Cipher.
	 */
	public function cipher(): Cipher {
		return $this->cipher;
	}

	/**
	 * Returns the randomness source.
	 *
	 * @return RandomSource Randomness.
	 */
	public function random(): RandomSource {
		return $this->random;
	}

	/**
	 * Tells the repository how much memory the job may take. The index then stops loading
	 * with an error instead of running into PHP's limit, and backups choose a pack size that
	 * fits (ADR 0031).
	 *
	 * @param MemoryBudget|null $memory Budget, or null if memory is not limited.
	 */
	public function limit_memory( ?MemoryBudget $memory ): void {
		$this->memory = $memory;
	}

	/**
	 * Returns the memory budget, if one was set.
	 *
	 * @return MemoryBudget|null Budget.
	 */
	public function memory(): ?MemoryBudget {
		return $this->memory;
	}

	/**
	 * Returns the master key, for caching it encrypted (ADR 0020).
	 *
	 * @return MasterKey Master key.
	 */
	public function master_key(): MasterKey {
		return $this->master_key;
	}

	/**
	 * Encrypts and stores a JSON document; its name is the hash of the stored bytes.
	 *
	 * @param ObjectType $type Index, snapshot or lock.
	 * @param string     $json JSON.
	 * @return Id Name of the stored file.
	 */
	public function save_unpacked( ObjectType $type, string $json ): Id {
		$sealed = $this->cipher->seal( $json );
		$id     = Id::of( $sealed );
		$this->backend->save( $type, $id->hex(), $sealed );
		return $id;
	}

	/**
	 * Loads, verifies and decrypts a JSON document.
	 *
	 * @param ObjectType $type Index, snapshot or lock.
	 * @param Id         $id   Name.
	 * @return string JSON.
	 * @throws NotFound If the file does not exist.
	 * @throws BackendException If the request fails.
	 * @throws FormatException If the content does not match its name.
	 * @throws UnsupportedRepository If the file is compressed and PHP lacks the zstd extension.
	 */
	public function load_unpacked( ObjectType $type, Id $id ): string {
		$sealed = $this->backend->load( $type, $id->hex() );
		if ( ! Id::of( $sealed )->equals( $id ) ) {
			FormatException::raise( 'Repository file content does not match its name.', [ 'type' => $type->value ] );
		}
		return Unpacked::decode( $this->cipher->open( $sealed ), $this->config->version );
	}

	/**
	 * Lists the files of a type.
	 *
	 * @param ObjectType $type Type of the files.
	 * @return list<string> Hex names in order.
	 */
	public function names( ObjectType $type ): array {
		$names = array_map( 'strval', array_keys( $this->backend->list( $type ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Tells which of some files the server no longer lists.
	 *
	 * @param ObjectType   $type  Type of the files.
	 * @param list<string> $names Hex names.
	 * @return list<string> The names that are gone; nothing is requested for an empty list.
	 */
	public function missing( ObjectType $type, array $names ): array {
		if ( [] === $names ) {
			return [];
		}
		return array_values( array_diff( $names, $this->names( $type ) ) );
	}

	/**
	 * Loads index files.
	 *
	 * @param list<string>|null $names The index files to read, for a caller that has to know
	 *                                 which they were; all that the server lists otherwise, in
	 *                                 the order of its listing.
	 * @return IndexSet Merged index.
	 * @throws BackendException If an index file disappears or a server request fails.
	 * @throws AuthenticationFailed If an index file does not authenticate.
	 * @throws CryptoException If an index file cannot be decrypted.
	 * @throws FormatException If an index file is malformed or does not match its name.
	 * @throws UnsupportedRepository If an index file needs unavailable decompression.
	 * @throws IndexTooLarge If the index does not fit into the memory budget.
	 */
	public function load_index( ?array $names = null ): IndexSet {
		$index = new IndexSet();
		foreach ( $names ?? array_map( 'strval', array_keys( $this->backend->list( ObjectType::Index ) ) ) as $name ) {
			foreach ( IndexFile::packs( $this->load_unpacked( ObjectType::Index, Id::from_hex( $name ) ) ) as $pack => $entries ) {
				$index->add_pack( Id::from_hex( $pack ), $entries );
				$this->memory?->check_index();
			}
		}
		$this->memory?->check_loaded_index();
		return $index;
	}

	/**
	 * Loads matching snapshots, newest first, with a callback between repository reads.
	 *
	 * @param (\Closure(): void)|null         $keep_lock Check and refresh the caller's lock.
	 * @param (\Closure(Snapshot): bool)|null $filter    Retain only matching metadata, or all.
	 * @return array<string, Snapshot> Snapshots by hex ID.
	 */
	public function snapshots( ?\Closure $keep_lock = null, ?\Closure $filter = null ): array {
		$snapshots = [];
		$keep_lock?->__invoke();
		foreach ( array_keys( $this->backend->list( ObjectType::Snapshots ) ) as $name ) {
			$keep_lock?->__invoke();
			$snapshot = Snapshot::from_json( $this->load_unpacked( ObjectType::Snapshots, Id::from_hex( $name ) ) );
			$keep_lock?->__invoke();
			if ( null === $filter || $filter( $snapshot ) ) {
				$snapshots[ $name ] = $snapshot;
			}
		}
		uasort( $snapshots, static fn ( Snapshot $a, Snapshot $b ): int => Rfc3339::parse( $b->time ) <=> Rfc3339::parse( $a->time ) );
		$keep_lock?->__invoke();
		return $snapshots;
	}

	/**
	 * Loads and verifies a blob.
	 *
	 * @param IndexSet $index Index.
	 * @param BlobType $type  Blob type.
	 * @param Id       $id    Blob ID.
	 * @return string Plaintext.
	 * @throws FormatException If the blob is not indexed.
	 */
	public function load_blob( IndexSet $index, BlobType $type, Id $id ): string {
		$location = $index->find( $id, $type );
		if ( null === $location ) {
			FormatException::raise( 'A referenced blob is missing from the index.', [ 'blob' => $id->hex() ] );
		}
		$sealed = $this->backend->load( ObjectType::Data, $location->pack->hex(), $location->entry->offset, $location->entry->length );
		return PackReader::open_blob( $this->cipher, $location->entry, $sealed );
	}

	/**
	 * Loads a tree.
	 *
	 * @param IndexSet $index Index.
	 * @param Id       $id    Tree blob ID.
	 * @return list<Node> Nodes.
	 */
	public function load_tree( IndexSet $index, Id $id ): array {
		return TreeCodec::decode( $this->load_blob( $index, BlobType::Tree, $id ) );
	}
}
