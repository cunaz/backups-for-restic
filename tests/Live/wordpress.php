<?php
/**
 * Opt-in WordPress backup and independent restore against a dedicated normal BorgBase fixture.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

use BackupsForRestic\Connection\ServerProfile;
use BackupsForRestic\Tests\Live\WordPressOutputScan;
use BackupsForRestic\Tests\Support\MysqliConnection;
use BackupsForRestic\Tests\Support\ResticCli;
use BackupsForRestic\Tests\Support\RestServer;
use BackupsForRestic\Tests\Support\TempDir;
use BackupsForRestic\Tests\Support\TestSite;
use BackupsForRestic\Tests\Support\WordPressBackupFixture;
use PHPUnit\Framework\Assert;

require dirname( __DIR__ ) . '/bootstrap.php';

if ( ! in_array( array_slice( $argv, 1 ), [ [ '--preflight' ], [ '--backup' ] ], true ) ) {
	fwrite( STDERR, "Choose --preflight or --backup. See tests/Live/README.md.\n" );
	exit( 2 );
}

/**
 * Retain only the HTTP status on errors. The setup responses can contain a recovery kit.
 *
 * @return array<mixed>
 */
function hosted_wordpress_api( string $method, string $route, #[SensitiveParameter] ?array $body = null, int $expected = 200 ): array {
	[ $status, $answer ] = TestSite::api( $method, $route, $body );
	if ( $expected !== $status ) {
		fwrite( STDERR, sprintf( "%s %s returned HTTP %d.\n", $method, $route, $status ) );
	}
	Assert::assertSame( $expected, $status );
	return $answer;
}

$stage = '';
$begin = static function ( string $label ) use ( &$stage ): void {
	$stage = $label;
	fwrite( STDOUT, gmdate( 'H:i:s' ) . ' UTC · ' . $label . "\n" );
};

$work = null;
$exit = 0;
try {
	$begin( 'Read the private normal fixture' );
	$config_file = realpath( (string) getenv( 'BFR_LIVE_WORDPRESS_FIXTURE_FILE' ) );
	$site        = realpath( TestSite::root() );
	Assert::assertIsString( $site );
	Assert::assertIsString( $config_file );
	Assert::assertTrue( is_file( $config_file ) && 0 === ( fileperms( $config_file ) & 0077 ) );
	Assert::assertFalse( str_starts_with( $config_file, $site . DIRECTORY_SEPARATOR ) );
	$config  = json_decode( (string) file_get_contents( $config_file ), true, flags: JSON_THROW_ON_ERROR );
	$fixture = $config['borgbase']['normal'] ?? null;
	Assert::assertIsArray( $fixture );
	foreach ( [ 'url', 'username', 'rest_password', 'repository_password', 'repository_id' ] as $key ) {
		Assert::assertTrue( isset( $fixture[ $key ] ) && is_string( $fixture[ $key ] ) && '' !== $fixture[ $key ] );
	}
	Assert::assertFalse( isset( $fixture['ca_pem'] ) );
	Assert::assertSame( 1, preg_match( '/\A[0-9a-f]{64}\z/', $fixture['repository_id'] ) );
	$url = ServerProfile::normalize( ServerProfile::BORGBASE, $fixture['url'] );

	$begin( 'Verify the repository ID and check for existing locks and snapshots' );
	$work        = TempDir::create( 'hosted-wordpress' );
	$environment = [
		'RESTIC_REPOSITORY'    => 'rest:' . $url,
		'RESTIC_REST_USERNAME' => $fixture['username'],
		'RESTIC_REST_PASSWORD' => $fixture['rest_password'],
		'RESTIC_PASSWORD'      => $fixture['repository_password'],
	];
	$restic      = static function ( array $arguments ) use ( $work, $environment ): string {
		$binary  = RestServer::binary( 'restic-' . ResticCli::VERSIONS[0] );
		$command = array_merge( [ $binary, '--cache-dir', $work . '/cache' ], $arguments );
		// restic errors may include the private endpoint. Keep them out of terminal and CI output.
		$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes, null, $environment );
		Assert::assertIsResource( $process );
		$result = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$status = proc_close( $process );
		if ( 0 !== $status ) {
			fwrite( STDERR, sprintf( "The restic command exited with status %d.\n", $status ) );
		}
		Assert::assertSame( 0, $status );
		Assert::assertIsString( $result );
		return $result;
	};
	$config      = json_decode( $restic( [ '--no-lock', 'cat', 'config' ] ), true, flags: JSON_THROW_ON_ERROR );
	Assert::assertTrue( hash_equals( $fixture['repository_id'], $config['id'] ) );
	Assert::assertSame( '', trim( $restic( [ '--no-lock', 'list', 'locks' ] ) ) );
	Assert::assertSame( '', trim( $restic( [ '--no-lock', 'list', 'snapshots' ] ) ) );

	$begin( 'Verify the fresh disposable WordPress site' );
	$status = hosted_wordpress_api( 'GET', '/status' );
	Assert::assertFalse( $status['setup_completed'] );
	$checks = array_column( hosted_wordpress_api( 'GET', '/environment' )['checks'], 'values', 'id' );
	Assert::assertSame( 134217728, $checks['memory_limit']['bytes'] );
	Assert::assertSame( 30, $checks['time_limit']['seconds'] );
	fwrite( STDOUT, "WordPress reports a 128-MiB memory limit and a 30-second execution limit.\n" );
	$settings = hosted_wordpress_api( 'GET', '/settings' )['values'];
	Assert::assertSame( '', $settings['repository.url'] );
	Assert::assertSame( 'none', $settings['repository.ca_certificate'] );
	Assert::assertSame( [], hosted_wordpress_api( 'GET', '/jobs' )['jobs'] );
	$database = (string) getenv( 'BFR_DB_NAME' );
	Assert::assertNotSame( '', $database );
	Assert::assertSame( $database, TestSite::php( 'echo DB_NAME;' ) );
	$db = MysqliConnection::connect( $database );
	Assert::assertNotNull( $db );

	if ( '--preflight' === $argv[1] ) {
		fwrite( STDOUT, "Read-only WordPress and repository preflight passed.\n" );
	} else {
		$begin( 'Set manual schedules and connect through the BorgBase wizard' );
		$values = [
			'retention.mode'                    => 'external',
			'retention.run_forget_after_backup' => false,
			'schedule.backup.frequency'         => 'manual',
			'schedule.check.frequency'          => 'manual',
			'schedule.prune.frequency'          => 'manual',
		];
		hosted_wordpress_api( 'PUT', '/settings', [ 'values' => $values ] );
		$connection = [
			'server'   => ServerProfile::BORGBASE,
			'url'      => $url,
			'username' => $fixture['username'],
			'password' => $fixture['rest_password'],
		];
		$test       = hosted_wordpress_api( 'POST', '/repository/test', $connection )['report'];
		Assert::assertTrue( $test['exists'] );
		Assert::assertSame( 'deletions_allowed', $test['append_only'] );
		hosted_wordpress_api( 'POST', '/repository/save', $connection );
		$job = hosted_wordpress_api( 'POST', '/repository/connect', [ 'password' => $fixture['repository_password'] ], 201 )['job']['id'];
		Assert::assertSame( 'succeeded', TestSite::wait_for( $job, 600 )['state'] );
		$settings = hosted_wordpress_api( 'GET', '/settings' )['values'];
		Assert::assertTrue( hash_equals( $fixture['repository_id'], $settings['repository.id'] ) );
		Assert::assertSame( 'external', $settings['retention.mode'] );
		hosted_wordpress_api( 'POST', '/setup/complete', [ 'recovery_kit_acknowledged' => true ] );

		$begin( 'Prepare synthetic uploads and SQL data' );
		$uploads = TestSite::root() . '/wp-content/uploads/fixture';
		$reuse   = getenv( 'BFR_LIVE_PREPARED_UPLOADS' );
		$bytes   = WordPressBackupFixture::write_uploads( $uploads, 2048, false === $reuse ? null : $reuse );
		Assert::assertSame( 2147483648, $bytes );
		WordPressBackupFixture::write_database( $db, 300 );
		$size      = $db->rows( 'SELECT COUNT(*) AS entries, SUM(OCTET_LENGTH(meta_value)) AS payload FROM wp_postmeta WHERE meta_key = "_fixture"' )[0];
		$sql_bytes = (int) $size['payload'];
		$sql_rows  = (int) $size['entries'];
		Assert::assertSame( 300 * 1024, $sql_rows );
		Assert::assertSame( 300 * 1048576, $sql_bytes );
		fwrite( STDOUT, sprintf( "Prepared %d upload bytes and %d SQL value bytes in %d rows.\n", $bytes, $sql_bytes, $sql_rows ) );

		$begin( 'Run the backup through WordPress cron' );
		$id       = hosted_wordpress_api( 'POST', '/jobs', [], 201 )['job']['id'];
		$job      = TestSite::wait_for( $id, 4 * 3600 );
		$snapshot = $job['result']['snapshot'] ?? '';
		Assert::assertSame( 'succeeded', $job['state'] );
		Assert::assertSame( 1, preg_match( '/\A[0-9a-f]{64}\z/', $snapshot ) );
		Assert::assertSame( [], $job['warnings'] );
		Assert::assertSame( $snapshot, trim( $restic( [ '--no-lock', 'list', 'snapshots' ] ) ) );

		$begin( 'Read all repository data with the independent restic CLI' );
		$restic( [ 'check', '--read-data' ] );

		$begin( 'Restore the exact snapshot and compare every synthetic upload' );
		$target = $work . '/restored';
		$restic( [ 'restore', $snapshot, '--target', $target ] );
		Assert::assertSame( TempDir::describe( $uploads ), TempDir::describe( $target . $uploads ) );
		TempDir::remove( $target . $uploads );

		$begin( 'Import the recovered SQL and compare table checksums' );
		$dump      = $target . TestSite::private_dir() . '/spool/database';
		$checksums = WordPressBackupFixture::assert_restored_database( $db, $dump );
		foreach ( $checksums as $table => $checksum ) {
			fwrite( STDOUT, sprintf( "Source and restored CHECKSUM TABLE %s EXTENDED both yielded %s.\n", $table, $checksum ) );
		}
		$begin( 'Check the recovered SQL and WordPress diagnostics for exposed credentials' );
		$scan = WordPressOutputScan::inspect( $dump, $fixture );
		fwrite( STDOUT, sprintf( "Scanned %d files and %d bytes. Credential matches in %d files.\n", $scan['files'], $scan['bytes'], $scan['matches'] ) );
		Assert::assertSame( 0, $scan['matches'] );
		Assert::assertSame( '', trim( $restic( [ '--no-lock', 'list', 'locks' ] ) ) );
		fwrite( STDOUT, "Passed. The normal repository retains one verified snapshot and no locks.\n" );
	}
} catch ( Throwable ) {
	fwrite( STDERR, 'Hosted WordPress acceptance failed during ' . $stage . ".\n" );
	$exit = 1;
} finally {
	if ( null !== $work ) {
		TempDir::remove( $work );
	}
}
exit( $exit );
