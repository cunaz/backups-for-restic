<?php
/**
 * Options table in memory.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Storage\Options;

/**
 * Test double for the options table that also remembers the autoload flag.
 */
final class InMemoryOptions implements Options {

	/** @var array<string, mixed> */
	public array $values = [];

	/** @var array<string, bool> */
	public array $autoload = [];

	/** Number of calls of set(). */
	public int $writes = 0;

	/**
	 * Whether get() answers like WordPress within one request: with what it read first, even
	 * though another request stored something else since.
	 */
	public bool $request_cache = false;

	/** @var array<string, mixed> */
	private array $read = [];

	public function get( string $name ): mixed {
		if ( ! $this->request_cache ) {
			return $this->values[ $name ] ?? null;
		}
		if ( ! array_key_exists( $name, $this->read ) ) {
			$this->read[ $name ] = $this->values[ $name ] ?? null;
		}
		return $this->read[ $name ];
	}

	/** Number of calls of fresh(). */
	public int $fresh = 0;

	/** Runs before fresh() answers: what another request wrote since this one read. */
	public ?\Closure $meanwhile = null;

	public function fresh( string $name ): mixed {
		++$this->fresh;
		if ( null !== $this->meanwhile ) {
			$meanwhile       = $this->meanwhile;
			$this->meanwhile = null;
			$meanwhile( $this );
		}
		$this->read[ $name ] = $this->values[ $name ] ?? null;
		return $this->read[ $name ];
	}

	/** What set() throws instead of writing, as a database that went away does. */
	public ?\Throwable $failure = null;

	/**
	 * Decides for each write whether the database refuses it, from the name of the option.
	 *
	 * @var (\Closure(string): bool)|null
	 */
	public ?\Closure $refuses = null;

	/**
	 * Whether a refused write is answered as WordPress answers it: with false and without an
	 * exception, and with the old value left in place.
	 */
	public bool $refuses_quietly = false;

	public function set( string $name, mixed $value, bool $autoload ): bool {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		if ( null !== $this->refuses && ( $this->refuses )( $name ) ) {
			if ( $this->refuses_quietly ) {
				return false;
			}
			throw new \RuntimeException( 'The database refused the write.' );
		}
		++$this->writes;
		$this->values[ $name ]   = $value;
		$this->read[ $name ]     = $value;
		$this->autoload[ $name ] = $autoload;
		return true;
	}

	public function initialize( string $name, mixed $value, bool $autoload ): bool {
		if ( array_key_exists( $name, $this->values ) ) {
			$this->read[ $name ] = $this->values[ $name ];
			return $value === $this->values[ $name ] || ( is_scalar( $value ) && is_scalar( $this->values[ $name ] ) && (string) $value === (string) $this->values[ $name ] );
		}
		return $this->set( $name, $value, $autoload );
	}

	public function delete( string $name ): void {
		unset( $this->values[ $name ], $this->autoload[ $name ], $this->read[ $name ] );
	}
}
