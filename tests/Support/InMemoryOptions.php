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

	public function set( string $name, mixed $value, bool $autoload ): void {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		++$this->writes;
		$this->values[ $name ]   = $value;
		$this->read[ $name ]     = $value;
		$this->autoload[ $name ] = $autoload;
	}

	public function delete( string $name ): void {
		unset( $this->values[ $name ], $this->autoload[ $name ], $this->read[ $name ] );
	}
}
