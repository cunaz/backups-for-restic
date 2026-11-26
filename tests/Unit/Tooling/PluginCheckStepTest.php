<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The step of .github/actions/check-package that runs Plugin Check (docs/04 §5.8). Plugin
 * Check 2.2.0 prepares its runtime checks before WordPress loads the plugins only when WP-CLI's
 * first two arguments are "plugin check" (CLI_Runner::is_plugin_check()). The test runs the
 * step's script with stand-ins for php and python3 and reads the arguments WP-CLI would get.
 */
final class PluginCheckStepTest extends TestCase {

	public function test_wp_cli_receives_plugin_check_as_its_first_arguments(): void {
		$scratch = TempDir::create( 'plugin-check-step' );
		try {
			$action = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/actions/check-package/action.yml' );
			$steps  = explode( "      run: |\n", $action );
			self::assertSame( 1, preg_match( '/\A((?: {8}.*\n|\n)+)/', (string) end( $steps ), $match ), 'The last step has a run block.' );
			self::assertStringContainsString( 'plugin check backups-for-restic', $match[1], 'The last step runs Plugin Check.' );
			file_put_contents( $scratch . '/step.sh', (string) preg_replace( '/^ {8}/m', '', $match[1] ) );
			mkdir( $scratch . '/bin' );
			file_put_contents( $scratch . '/bin/php', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> \"\$CALLS\"\n" );
			file_put_contents( $scratch . '/bin/python3', "#!/usr/bin/env bash\nexit 0\n" );
			chmod( $scratch . '/bin/php', 0755 );
			chmod( $scratch . '/bin/python3', 0755 );
			$process = proc_open(
				[ 'bash', '--noprofile', '--norc', '-eo', 'pipefail', $scratch . '/step.sh' ],
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'redirect', 1 ],
				],
				$pipes,
				$scratch,
				[
					'PATH'               => $scratch . '/bin:' . getenv( 'PATH' ),
					'CALLS'              => $scratch . '/calls',
					'SITE'               => '/runner/temp/plugin-check/site',
					'RUNNER_TEMP'        => $scratch,
					'GITHUB_ACTION_PATH' => $scratch,
					'IGNORE_CODES'       => 'a,b',
					'EXCLUDE_FILES'      => 'src/x.php',
				]
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			self::assertSame( 0, proc_close( $process ), $output );
			$checks = array_values( array_filter( (array) file( $scratch . '/calls', FILE_IGNORE_NEW_LINES ), static fn ( $call ): bool => str_contains( (string) $call, 'plugin check' ) ) );
			self::assertCount( 1, $checks );
			self::assertStringStartsWith( '.tools/bin/wp plugin check backups-for-restic ', (string) $checks[0] );
			self::assertStringContainsString( ' --path=/runner/temp/plugin-check/site', (string) $checks[0] );
		} finally {
			TempDir::remove( $scratch );
		}
	}
}
