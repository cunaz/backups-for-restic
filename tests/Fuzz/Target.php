<?php
/**
 * One parser under fuzzing.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Fuzz;

/**
 * A parser that reads data from the repository server, the inputs to start from, and whether
 * those inputs are JSON, which enables structure-aware mutations.
 */
final readonly class Target {

	/**
	 * Describes the target.
	 *
	 * @param string                 $name  Short name for the command line.
	 * @param list<string>           $seeds Valid inputs, preferably recorded from restic.
	 * @param bool                   $json  Whether the inputs are JSON documents.
	 * @param \Closure(string): void $run   Parses one input. It may reject the input only with a PluginException.
	 */
	public function __construct(
		public string $name,
		public array $seeds,
		public bool $json,
		public \Closure $run
	) {
	}
}
