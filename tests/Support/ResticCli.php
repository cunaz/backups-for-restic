<?php
/**
 * The restic CLI as test oracle.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/**
 * Runs restic against a repository on the local rest-server (ADR 0023).
 */
final class ResticCli {

	public function __construct(
		private readonly RestServer $server,
		private readonly string $repository,
		private readonly string $password,
		private readonly string $version = '0.19.1'
	) {
	}

	/**
	 * Runs restic and returns exit code, stdout and stderr.
	 *
	 * @param list<string> $arguments Arguments after the global options.
	 * @return array{int, string, string}
	 */
	public function run( array $arguments ): array {
		$cache   = TempDir::create( 'restic-cache' );
		$command = array_merge( [ RestServer::binary( 'restic-' . $this->version ), '--cacert', $this->server->certificate, '--cache-dir', $cache ], $arguments );
		$url     = $this->server->url( $this->repository );
		if ( version_compare( $this->version, '0.16.1', '<' ) ) {
			// restic reads RESTIC_REST_USERNAME and RESTIC_REST_PASSWORD only since 0.16.1; older versions need them in the URL.
			$url = 'https://' . rawurlencode( RestServer::USER ) . ':' . rawurlencode( RestServer::PASSWORD ) . '@' . substr( $url, strlen( 'https://' ) );
		}
		$environment = [
			'RESTIC_REPOSITORY'    => 'rest:' . $url,
			'RESTIC_REST_USERNAME' => RestServer::USER,
			'RESTIC_REST_PASSWORD' => RestServer::PASSWORD,
			'RESTIC_PASSWORD'      => $this->password,
			'PATH'                 => '/usr/bin:/bin',
			'HOME'                 => $cache,
			'TZ'                   => 'UTC',
		];
		$process     = proc_open( $command, [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, null, $environment );
		if ( false === $process ) {
			throw new \RuntimeException( 'restic could not be started.' );
		}
		fclose( $pipes[0] );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		TempDir::remove( $cache );
		return [ $status, $stdout, $stderr ];
	}

	/**
	 * Runs restic and fails the test with restic's output unless it succeeds.
	 *
	 * @param list<string> $arguments Arguments.
	 */
	public function must( array $arguments ): string {
		[ $status, $stdout, $stderr ] = $this->run( $arguments );
		if ( 0 !== $status ) {
			throw new \RuntimeException( sprintf( "restic %s failed (%d):\n%s\n%s", implode( ' ', $arguments ), $status, $stdout, $stderr ) );
		}
		return $stdout;
	}
}
