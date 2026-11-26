<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `bin/test-site.sh check-logs`, which fails the integration and browser jobs when the plugin's
 * code wrote to the PHP or WordPress log (docs/04 §5.3).
 */
final class TestSiteLogsTest extends TestCase {

	private string $tools;

	protected function setUp(): void {
		$this->tools = TempDir::create( 'test-site-logs' );
		mkdir( $this->tools . '/site/wp-content', 0700, true );
		file_put_contents( $this->tools . '/php-errors.log', "PHP Warning: elsewhere in /var/www/other.php on line 3\n" );
		file_put_contents( $this->tools . '/site/wp-content/debug.log', '' );
	}

	protected function tearDown(): void {
		chmod( $this->tools . '/php-errors.log', 0600 );
		TempDir::remove( $this->tools );
	}

	/**
	 * Runs the check against the temporary site.
	 *
	 * @return array{int, string} The exit status and what the script printed.
	 */
	private function check(): array {
		$root    = dirname( __DIR__, 3 );
		$process = proc_open(
			[ 'bash', $root . '/bin/test-site.sh', 'check-logs' ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			$root,
			[ 'BFR_SITE_TOOLS_DIR' => $this->tools ] + getenv()
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $process ), $output ];
	}

	public function test_logs_without_an_entry_of_the_plugin_pass(): void {
		[ $status, $output ] = $this->check();
		self::assertSame( 0, $status, $output );
	}

	public function test_an_entry_that_names_a_file_of_the_plugin_fails(): void {
		file_put_contents( $this->tools . '/php-errors.log', 'PHP Deprecated: x in ' . dirname( __DIR__, 3 ) . "/src/Plugin.php on line 7\n", FILE_APPEND );
		[ $status, $output ] = $this->check();
		self::assertSame( 1, $status, $output );
		self::assertStringContainsString( 'The plugin\'s code raised 1 notices, warnings, deprecations or errors', $output );
	}

	public function test_a_log_that_cannot_be_read_fails_instead_of_counting_as_empty(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'File permissions do not bind root.' );
		}
		chmod( $this->tools . '/php-errors.log', 0000 );
		[ $status, $output ] = $this->check();
		self::assertSame( 1, $status, $output );
		self::assertStringContainsString( 'php-errors.log could not be read (grep exited with 2), so it was not checked.', $output );
	}
}
