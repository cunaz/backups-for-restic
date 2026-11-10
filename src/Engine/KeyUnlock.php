<?php
/**
 * Resumable unlocking.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Engine;

use BackupsForRestic\Backend\Backend;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Crypto\MasterKey;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Crypto\Scrypt;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\WrongPassword;
use BackupsForRestic\Format\KeyFile;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Storage\Files;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the master key across ticks (ADR 0020). Tries the key files one after another; the
 * scrypt state of the current one, and its file-backed table, live in a directory under kdf/
 * with owner-only permissions. clear() removes them once the job ends either way.
 */
final class KeyUnlock {

	/**
	 * Prepares the unlock.
	 *
	 * @param Backend      $backend   Repository storage.
	 * @param Secret       $password  Repository password.
	 * @param string       $directory Private working directory for this unlock.
	 * @param RandomSource $random    Randomness for opening key files.
	 */
	public function __construct(
		private readonly Backend $backend,
		private readonly Secret $password,
		private readonly string $directory,
		private readonly RandomSource $random
	) {
	}

	/**
	 * Works until the master key is found or the deadline passes.
	 *
	 * @param float $deadline Unix time (microtime(true)) at which to stop.
	 * @return MasterKey|null The master key, or null if another tick is needed.
	 * @throws WrongPassword If no key file accepts the password.
	 */
	public function run( float $deadline ): ?MasterKey {
		$this->ensure_directory();
		$state = $this->load_state() ?? [
			'keys'       => array_keys( $this->backend->list( ObjectType::Keys ) ),
			'index'      => 0,
			'checkpoint' => [],
		];
		$keys  = count( $state['keys'] );
		while ( $state['index'] < $keys ) {
			$name   = $state['keys'][ $state['index'] ];
			$key    = KeyFile::from_json( $this->backend->load( ObjectType::Keys, $name ) );
			$scrypt = new Scrypt( $this->password->reveal(), $key->salt, $key->scrypt_parameters(), MasterKey::KDF_OUTPUT_LENGTH, $this->directory . '/table.bin', $state['checkpoint'] );
			if ( ! $scrypt->run( $deadline ) ) {
				$state['checkpoint'] = $scrypt->checkpoint();
				$state['progress']   = $scrypt->progress();
				$this->save_state( $state );
				return null;
			}
			try {
				$master = $key->open( MasterKey::from_kdf_output( $scrypt->result() ), $this->random );
				$this->clear();
				return $master;
			} catch ( WrongPassword ) {
				++$state['index'];
				$state['checkpoint'] = [];
				$state['progress']   = 0.0;
				$this->save_state( $state );
			}
		}
		$this->clear();
		WrongPassword::raise( 'No key in the repository accepts the password.' );
	}

	/**
	 * Share of the work done, across all key files that may have to be tried.
	 *
	 * @return float Between 0 and 1.
	 */
	public function progress(): float {
		$state = $this->load_state();
		if ( null === $state || [] === $state['keys'] ) {
			return 0.0;
		}
		return min( 1.0, ( $state['index'] + $state['progress'] ) / count( $state['keys'] ) );
	}

	/**
	 * Deletes the scrypt table and checkpoint.
	 */
	public function clear(): void {
		Files::remove_tree( $this->directory );
	}

	/**
	 * Loads the saved state.
	 *
	 * @return array{keys: list<string>, index: int, checkpoint: array<string, mixed>, progress: float}|null State.
	 */
	private function load_state(): ?array {
		$json   = is_file( $this->directory . '/state.json' ) ? file_get_contents( $this->directory . '/state.json' ) : false;
		$fields = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $fields ) ) {
			return null;
		}
		$checkpoint = [];
		foreach ( is_array( $fields['checkpoint'] ?? null ) ? $fields['checkpoint'] : [] as $field => $value ) {
			$checkpoint[ (string) $field ] = $value;
		}
		return [
			'keys'       => Decoded::strings( $fields['keys'] ?? [] ),
			'index'      => Decoded::int( $fields['index'] ?? 0 ),
			'checkpoint' => $checkpoint,
			'progress'   => Decoded::number( $fields['progress'] ?? null ),
		];
	}

	/**
	 * Saves the state with owner-only permissions.
	 *
	 * @param array<string, mixed> $state State.
	 * @throws StorageFailed If the directory cannot be written.
	 */
	private function save_state( array $state ): void {
		$this->ensure_directory();
		Files::write_atomic( $this->directory . '/state.json', (string) json_encode( $state ) );
	}

	/**
	 * Creates the working directory with owner-only permissions.
	 *
	 * @throws StorageFailed If it cannot be created.
	 */
	private function ensure_directory(): void {
		if ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
			StorageFailed::raise( 'The unlock directory could not be created.' );
		}
	}
}
