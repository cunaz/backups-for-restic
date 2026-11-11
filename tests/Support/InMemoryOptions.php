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

	public function get( string $name ): mixed {
		return $this->values[ $name ] ?? null;
	}

	/** What set() throws instead of writing, as a database that went away does. */
	public ?\Throwable $failure = null;

	public function set( string $name, mixed $value, bool $autoload ): void {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		++$this->writes;
		$this->values[ $name ]   = $value;
		$this->autoload[ $name ] = $autoload;
	}

	public function delete( string $name ): void {
		unset( $this->values[ $name ], $this->autoload[ $name ] );
	}
}
