<?php
/**
 * Mutation fuzzer without dependencies.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Fuzz;

use Random\Randomizer;
use BackupsForRestic\Exception\PluginException;

/**
 * Feeds mutated inputs to one target and records findings.
 *
 * A finding is any outcome other than a parsed input or a PluginException: a TypeError, a
 * ValueError, a PHP warning, or an input that takes longer than a second. The fuzzer is not
 * coverage-guided. As a substitute it keeps every input that causes a behaviour it has not
 * seen before (acceptance, or a rejection with a new exception message) and mutates those
 * further, which walks it deeper into the validation code step by step.
 */
final class Fuzzer {

	private const int CORPUS_LIMIT = 512;

	private const float SLOW_SECONDS = 1.0;

	/** @var list<string> */
	private array $corpus;

	/** @var array<string, true> */
	private array $behaviours = [];

	/** @var array<string, array{string, \Throwable}> Findings by fingerprint: input and error. */
	private array $findings = [];

	private int $executions = 0;

	private readonly Mutator $mutator;

	/**
	 * Creates a fuzzer.
	 *
	 * @param Target     $target Target.
	 * @param Randomizer $random Seeded source of randomness.
	 */
	public function __construct( private readonly Target $target, private readonly Randomizer $random ) {
		$this->corpus  = $target->seeds;
		$this->mutator = new Mutator( $random );
	}

	/**
	 * Runs the seeds, then mutated inputs until the time or the execution budget is spent.
	 *
	 * @param float $seconds        Time budget.
	 * @param int   $max_executions Execution budget.
	 */
	public function run( float $seconds, int $max_executions = PHP_INT_MAX ): void {
		$deadline = microtime( true ) + $seconds;
		foreach ( $this->target->seeds as $seed ) {
			$this->execute( $seed );
		}
		while ( $this->executions < $max_executions && microtime( true ) < $deadline ) {
			$input = $this->corpus[ $this->random->getInt( 0, count( $this->corpus ) - 1 ) ];
			$this->execute( $this->mutator->mutate( $input, $this->target->json, $this->corpus ) );
		}
	}

	/**
	 * Runs one input.
	 *
	 * @param string $input Input.
	 * @return string Behaviour: "accepted", the rejection, or "finding".
	 */
	public function execute( string $input ): string {
		++$this->executions;
		$start = hrtime( true );
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			( $this->target->run )( $input );
			$behaviour = 'accepted';
		} catch ( PluginException $rejection ) {
			$behaviour = $rejection::class . ': ' . $rejection->getMessage();
		} catch ( \Throwable $error ) {
			$this->record( $input, $error );
			return 'finding';
		} finally {
			restore_error_handler();
		}
		$seconds = ( hrtime( true ) - $start ) / 1e9;
		if ( $seconds > self::SLOW_SECONDS ) {
			$this->record( $input, new \RuntimeException( sprintf( 'Input took %.1f s.', $seconds ) ) );
		}
		if ( ! isset( $this->behaviours[ $behaviour ] ) || ( 'accepted' === $behaviour && 0 === $this->random->getInt( 0, 7 ) ) ) {
			$this->behaviours[ $behaviour ] = true;
			if ( count( $this->corpus ) < self::CORPUS_LIMIT ) {
				$this->corpus[] = $input;
			}
		}
		return $behaviour;
	}

	/**
	 * Findings, one per distinct error location and type.
	 *
	 * @return array<string, array{string, \Throwable}> Input and error by fingerprint.
	 */
	public function findings(): array {
		return $this->findings;
	}

	/**
	 * Number of inputs run so far.
	 *
	 * @return int Executions.
	 */
	public function executions(): int {
		return $this->executions;
	}

	/**
	 * Number of distinct behaviours seen.
	 *
	 * @return int Behaviours.
	 */
	public function behaviours(): int {
		return count( $this->behaviours );
	}

	/**
	 * Records a finding once per error type and location.
	 *
	 * @param string     $input Input.
	 * @param \Throwable $error Error.
	 */
	private function record( string $input, \Throwable $error ): void {
		$fingerprint = $error::class . '@' . basename( $error->getFile() ) . ':' . $error->getLine();
		if ( ! isset( $this->findings[ $fingerprint ] ) || strlen( $input ) < strlen( $this->findings[ $fingerprint ][0] ) ) {
			$this->findings[ $fingerprint ] = [ $input, $error ];
		}
	}
}
