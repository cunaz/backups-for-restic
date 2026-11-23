<?php
declare( strict_types=1 );

namespace BackupsForRestic\Tests\Unit\Tooling;

use BackupsForRestic\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The script of .github/actions/mutation-scope, which lets a pull request skip a mutation run
 * that its files cannot change (docs/04 §5.6). Each case runs that script as GitHub runs a step
 * of a composite action, in a repository whose last commit merges a branch.
 */
final class MutationScopeTest extends TestCase {

	/**
	 * Event, watched pattern, the output that results, and what the log says.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function decisions(): array {
		return [
			'a watched file changed'             => [ 'pull_request', '^(src/|tests/)', 'run=true', '' ],
			'no watched file changed'            => [ 'pull_request', '^(docs/|composer\.json$)', 'run=false', '::notice::Mutation testing skipped' ],
			'an invalid pattern runs in full'    => [ 'pull_request', '^(src/', 'run=true', '::warning::The files of the pull request could not be matched against the watched paths (grep exited with 2)' ],
			'a push runs whatever its files are' => [ 'push', '^(docs/)', 'run=true', '' ],
		];
	}

	#[DataProvider( 'decisions' )]
	public function test_only_a_list_that_matches_nothing_skips_the_run( string $event, string $watched, string $decision, string $logged ): void {
		$scratch = TempDir::create( 'mutation-scope' );
		try {
			self::repository( $scratch, true );
			[ $status, $output, $result ] = self::scope( $scratch, $event, $watched );
			self::assertSame( 0, $status, $output );
			self::assertSame( $decision, $result, $output );
			if ( '' !== $logged ) {
				self::assertStringContainsString( $logged, $output );
			}
		} finally {
			TempDir::remove( $scratch );
		}
	}

	public function test_a_pull_request_whose_files_cannot_be_listed_runs_in_full(): void {
		$scratch = TempDir::create( 'mutation-scope' );
		try {
			self::repository( $scratch, false );
			[ $status, $output, $result ] = self::scope( $scratch, 'pull_request', '^(docs/)' );
			self::assertSame( 0, $status, $output );
			self::assertSame( 'run=true', $result );
			self::assertStringContainsString( 'could not be listed: running in full', $output );
		} finally {
			TempDir::remove( $scratch );
		}
	}

	/**
	 * A repository whose head merges a branch that changed src/Restore/Changed.php, or, without
	 * the merge, has that change as a plain commit.
	 */
	private static function repository( string $scratch, bool $merged ): void {
		$git = static function ( string ...$arguments ) use ( $scratch ): void {
			$process = proc_open(
				array_merge( [ 'git', '-C', $scratch . '/repo' ], $arguments ),
				[
					1 => [ 'pipe', 'w' ],
					2 => [ 'redirect', 1 ],
				],
				$pipes,
				null,
				self::environment( $scratch )
			);
			self::assertIsResource( $process );
			$output = (string) stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			self::assertSame( 0, proc_close( $process ), implode( ' ', $arguments ) . ': ' . $output );
		};
		mkdir( $scratch . '/repo/src/Restore', 0700, true );
		file_put_contents( $scratch . '/repo/README', "base\n" );
		$git( 'init', '-q', '-b', 'main' );
		$git( 'add', 'README' );
		$git( 'commit', '-q', '-m', 'base' );
		if ( $merged ) {
			$git( 'checkout', '-q', '-b', 'feature' );
		}
		file_put_contents( $scratch . '/repo/src/Restore/Changed.php', "<?php\n" );
		$git( 'add', 'src/Restore/Changed.php' );
		$git( 'commit', '-q', '-m', 'change' );
		if ( $merged ) {
			$git( 'checkout', '-q', 'main' );
			$git( 'merge', '-q', '--no-ff', '-m', 'merge', 'feature' );
		}
	}

	/**
	 * Runs the action's script in the repository.
	 *
	 * @return array{int, string, string} Exit status, log and the line written to GITHUB_OUTPUT.
	 */
	private static function scope( string $scratch, string $event, string $watched ): array {
		$action = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/actions/mutation-scope/action.yml' );
		self::assertSame( 1, preg_match( '/^      run: \|\n((?: {8}.*\n|\n)+)/m', $action, $match ), 'The action has one run block.' );
		file_put_contents( $scratch . '/scope.sh', (string) preg_replace( '/^ {8}/m', '', $match[1] ) );
		file_put_contents( $scratch . '/output', '' );
		$process = proc_open(
			[ 'bash', '--noprofile', '--norc', '-eo', 'pipefail', $scratch . '/scope.sh' ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			],
			$pipes,
			$scratch . '/repo',
			[
				'EVENT_NAME'    => $event,
				'WATCHED'       => $watched,
				'LABEL'         => 'src/, tests/',
				'RUNNER_TEMP'   => $scratch,
				'GITHUB_OUTPUT' => $scratch . '/output',
			] + self::environment( $scratch )
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $process ), $output, trim( (string) file_get_contents( $scratch . '/output' ) ) ];
	}

	/**
	 * The environment of git and the script: no configuration of the user's own.
	 *
	 * @return array<string, string>
	 */
	private static function environment( string $scratch ): array {
		return [
			'PATH'                => (string) getenv( 'PATH' ),
			'HOME'                => $scratch,
			'GIT_CONFIG_NOSYSTEM' => '1',
			'GIT_CONFIG_GLOBAL'   => '/dev/null',
			'GIT_AUTHOR_NAME'     => 'Test',
			'GIT_AUTHOR_EMAIL'    => 'test@example.com',
			'GIT_COMMITTER_NAME'  => 'Test',
			'GIT_COMMITTER_EMAIL' => 'test@example.com',
		];
	}
}
