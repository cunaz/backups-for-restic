<?php
/**
 * Local rest-server for the conformance suite.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

use BackupsForRestic\Backend\RestBackend;

/**
 * Starts restic's rest-server with TLS (self-signed certificate) and basic auth, the setup
 * a self-hoster would use (ADR 0025).
 */
final class RestServer {

	public const string USER     = 'plugin';
	public const string PASSWORD = 'rest password';

	public string $directory;

	public string $certificate;

	public int $port;

	/** @var resource|null */
	private $process = null;

	/**
	 * Starts a server.
	 *
	 * @param bool $append_only Whether deletions are refused (--append-only).
	 * @param int  $max_size    Repository size limit in bytes (--max-size), 0 for none.
	 * @param bool $localhost_certificate Whether the positive socket-transport fixture needs a DNS SAN.
	 */
	public function __construct( public bool $append_only = false, public readonly int $max_size = 0, bool $localhost_certificate = false ) {
		$this->directory   = TempDir::create( 'rest-server' );
		$this->certificate = $this->directory . '/cert.pem';
		$key               = $this->directory . '/key.pem';
		$data              = $this->directory . '/data';
		mkdir( $data );

		$names = $localhost_certificate
			? ' -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1'
			: ' -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1';
		exec(
			'openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 2'
			. $names
			. ' -keyout ' . escapeshellarg( $key ) . ' -out ' . escapeshellarg( $this->certificate ) . ' 2>/dev/null',
			$output,
			$status
		);
		if ( 0 !== $status ) {
			throw new \RuntimeException( 'openssl failed to create a test certificate.' );
		}
		file_put_contents( $data . '/.htpasswd', self::USER . ':' . password_hash( self::PASSWORD, PASSWORD_BCRYPT ) . "\n" );

		// Ask the kernel for a free port.
		$socket     = stream_socket_server( 'tcp://127.0.0.1:0' );
		$name       = false === $socket ? false : stream_socket_get_name( $socket, false );
		$this->port = false === $name ? 0 : (int) substr( $name, (int) strrpos( $name, ':' ) + 1 );
		if ( false !== $socket ) {
			fclose( $socket );
		}
		$this->start();
	}

	/**
	 * Stops the server and starts it again on the same address with the same data, as an
	 * operator does who turns append-only mode on or off.
	 */
	public function restart( bool $append_only ): void {
		$this->halt();
		$this->append_only = $append_only;
		$this->start();
	}

	private function start(): void {
		$command = [ self::binary( 'rest-server' ), '--path', $this->directory . '/data', '--listen', '127.0.0.1:' . $this->port, '--tls', '--tls-cert', $this->certificate, '--tls-key', $this->directory . '/key.pem' ];
		if ( $this->append_only ) {
			$command[] = '--append-only';
		}
		if ( $this->max_size > 0 ) {
			array_push( $command, '--max-size', (string) $this->max_size );
		}
		$process = proc_open( $command, [ [ 'pipe', 'r' ], [ 'file', $this->directory . '/server.log', 'a' ], [ 'file', $this->directory . '/server.log', 'a' ] ], $pipes );
		if ( false === $process ) {
			throw new \RuntimeException( 'rest-server could not be started.' );
		}
		$this->process = $process;

		for ( $i = 0; $i < 100; $i++ ) {
			$probe = @fsockopen( '127.0.0.1', $this->port, $errno, $errstr, 0.1 );
			if ( false !== $probe ) {
				fclose( $probe );
				return;
			}
			usleep( 50000 );
		}
		throw new \RuntimeException( 'rest-server did not start.' );
	}

	private function halt(): void {
		if ( null !== $this->process ) {
			proc_terminate( $this->process );
			proc_close( $this->process );
			$this->process = null;
		}
	}

	public function stop(): void {
		$this->halt();
		TempDir::remove( $this->directory );
	}

	public function url( string $repository, string $hostname = '127.0.0.1' ): string {
		return 'https://' . $hostname . ':' . $this->port . '/' . $repository . '/';
	}

	public function backend( string $repository, string $password = self::PASSWORD ): RestBackend {
		return new RestBackend( new CurlHttpClient( $this->certificate ), $this->url( $repository ), self::USER, $password, 'backups-for-restic-tests', 3, static function ( int $ms ): void {} );
	}

	public static function binary( string $name ): string {
		return dirname( __DIR__, 2 ) . '/.tools/bin/' . $name;
	}

	public static function available(): bool {
		return is_executable( self::binary( 'restic' ) ) && is_executable( self::binary( 'rest-server' ) );
	}
}
