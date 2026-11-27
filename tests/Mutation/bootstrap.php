<?php
/**
 * PHPUnit bootstrap of the mutation suites (tests/Mutation/phpunit.xml.dist). The database gate's
 * own, tests/Mutation/database/bootstrap.php, does the same and then gives each mutant process a
 * database server of its own.
 *
 * In a mutant process Infection serves the mutated class through a user-space file:// wrapper,
 * on which fsync() fails. MutationProcess loads the classes under mutation while that wrapper is
 * in place and then restores PHP's own, so that durable writes work in the tests as they do in
 * production. Without the wrapper (the initial test run, a plain PHPUnit run) it only checks
 * that fsync() works. See tests/Support/MutationProcess.php.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/bootstrap.php';

\BackupsForRestic\Tests\Support\MutationProcess::prepare( dirname( __DIR__, 2 ) );
