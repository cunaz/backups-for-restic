<?php
/**
 * PHPUnit bootstrap of the database gate (tests/Mutation/database/phpunit.xml.dist): the one of
 * the mutation suites (tests/Mutation/bootstrap.php, tests/Support/MutationProcess.php), and then
 * the database server of the process's own Infection thread (tests/Support/DatabaseServers.php).
 * Without TEST_TOKEN, in Infection's initial test run and in PHPUnit alone, the database suite
 * connects as BFR_TEST_MYSQL_HOST says, as it does everywhere else.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

require dirname( __DIR__, 2 ) . '/bootstrap.php';

\BackupsForRestic\Tests\Support\DatabaseServers::choose( dirname( __DIR__, 3 ), \BackupsForRestic\Tests\Support\MutationProcess::prepare( dirname( __DIR__, 3 ) ) );
