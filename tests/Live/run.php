<?php
/**
 * Run private TLS fixture or explicitly configured hosted backup/restore smoke tests.
 * Hosted credentials are accepted through the environment only.
 */
declare( strict_types=1 );

use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Connection\ServerProfile;
use BackupsForRestic\Crypto\ScryptParameters;
use BackupsForRestic\Crypto\SystemRandom;
use BackupsForRestic\Engine\Identity;
use BackupsForRestic\Engine\Repository;
use BackupsForRestic\Tests\Live\RepositorySmoke;
use BackupsForRestic\Tests\Live\SmokeBackend;
use BackupsForRestic\Tests\Support\RestServer;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
// phpcs:disable WordPress.PHP.IniSet -- Standalone CLI handles remote errors without leaking credentials into runner logs.
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
ini_set( 'zend.exception_ignore_args', '1' );
// phpcs:enable WordPress.PHP.IniSet
umask( 0077 );
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require ABSPATH . 'vendor/autoload.php';

/** Validate the whole operator configuration before contacting a fixture. */
function smoke_fixtures( #[\SensitiveParameter] string $json ): array {
	if ( '' === $json || strlen( $json ) > 262144 ) {
		throw new RuntimeException( 'Missing or oversized fixture configuration.' );
	}
	$providers = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $providers ) || [] === $providers || array_diff( array_keys( $providers ), [ ServerProfile::BORGBASE, ServerProfile::REST_SERVER ] ) ) {
		throw new RuntimeException( 'Unknown or missing fixture providers.' );
	}
	$seen      = [];
	$endpoints = [];
	foreach ( $providers as $provider => $modes ) {
		if ( ! is_array( $modes ) || 2 !== count( $modes ) || ! isset( $modes['normal'], $modes['append-only'] ) ) {
			throw new RuntimeException( 'Configure two dedicated repositories for each provider.' );
		}
		foreach ( $modes as $mode => $fixture ) {
			if ( ! is_array( $fixture ) || array_diff( array_keys( $fixture ), [ 'url', 'username', 'rest_password', 'repository_password', 'repository_id', 'ca_pem' ] ) ) {
				throw new RuntimeException( 'Unknown fixture configuration fields.' );
			}
			foreach ( [ 'url', 'username', 'rest_password', 'repository_password', 'repository_id' ] as $key ) {
				if ( ! is_string( $fixture[ $key ] ?? null ) || '' === $fixture[ $key ] || strlen( $fixture[ $key ] ) > 8192 ) {
					throw new RuntimeException( 'A required fixture value is missing.' );
				}
			}
			if ( ! str_starts_with( $fixture['url'], 'https://' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $fixture['url'] ) ) {
				throw new RuntimeException( 'Use a bare HTTPS endpoint and separate credentials.' );
			}
			$url = ServerProfile::normalize( $provider, $fixture['url'] );
			if ( isset( $endpoints[ $url ] ) || ( ServerProfile::BORGBASE === $provider && isset( $fixture['ca_pem'] ) ) ) {
				throw new RuntimeException( 'Use distinct endpoints and public trust for the hosted preset.' );
			}
			$endpoints[ $url ]                      = true;
			$providers[ $provider ][ $mode ]['url'] = $url;
			if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $fixture['repository_id'] ) || isset( $seen[ $fixture['repository_id'] ] ) ) {
				throw new RuntimeException( 'Every fixture needs a distinct dedicated repository ID.' );
			}
			if ( isset( $fixture['ca_pem'] ) && ( ! is_string( $fixture['ca_pem'] ) || ! str_contains( $fixture['ca_pem'], '-----BEGIN CERTIFICATE-----' ) || str_contains( $fixture['ca_pem'], 'PRIVATE KEY' ) ) ) {
				throw new RuntimeException( 'The optional CA bundle must contain only public certificates.' );
			}
			$seen[ $fixture['repository_id'] ] = true;
		}
	}
	return $providers;
}

$label = 'configuration';
$smoke = new RepositorySmoke();
try {
	if ( [ '--local' ] === array_slice( $argv, 1 ) ) {
		$servers  = [];
		$fixtures = [];
		try {
			foreach ( [
				'normal'      => false,
				'append-only' => true,
			] as $mode => $append ) {
				$label     = 'private rest-server ' . $mode;
				$server    = new RestServer( append_only: $append, private_repos: true );
				$servers[] = $server;
				$name      = RestServer::USER . '/smoke';
				$password  = bin2hex( random_bytes( 24 ) );
				$remote    = $server->backend( $name );
				$random    = new SystemRandom();
				// Only this disposable server is initialized. Hosted runs require an existing pinned ID.
				$repository        = Repository::initialize( $remote, $password, new Identity( 'fixture-init' ), $random, new ScryptParameters( 1024, 8, 1 ) );
				$fixture           = [
					'url'                 => $server->url( $name ),
					'username'            => RestServer::USER,
					'rest_password'       => RestServer::PASSWORD,
					'repository_password' => $password,
					'repository_id'       => $repository->config()->id,
					'ca_pem'              => file_get_contents( $server->certificate ),
				];
				$fixtures[ $mode ] = $fixture;

				// A repository which authenticates successfully still needs to match its pin.
				$bad                  = $fixture;
				$bad['repository_id'] = str_repeat( '0', 64 );
				$refused              = false;
				try {
					$smoke->run( $bad, $append );
				} catch ( RuntimeException $error ) {
					$refused = 'Dedicated smoke repository ID mismatch.' === $error->getMessage();
				}
				if ( ! $refused ) {
					throw new RuntimeException( 'Local pin refusal check failed.' );
				}

				// A correctly pinned run still cannot delete pre-existing content, even on normal storage.
				$guard  = new SmokeBackend( $remote );
				$opened = Repository::open( $guard, Repository::unlock( $guard, $password, $random ), $random );
				$guard->bind( $opened, $fixture['repository_id'] );
				$key     = array_key_first( $guard->list( ObjectType::Keys ) );
				$refused = false;
				try {
					$guard->delete( ObjectType::Keys, $key );
				} catch ( RuntimeException $error ) {
					$refused = 'The smoke run refused to delete an object without its own write receipt.' === $error->getMessage();
				}
				if ( ! $refused || null === $guard->size( ObjectType::Keys, $key ) ) {
					throw new RuntimeException( 'Local ownership refusal check failed.' );
				}
			}
			// Exercise the same CLI, config schema, TLS, credentials and reporting as the hosted workflow.
			$label = 'private rest-server command';
			$env   = [ 'BFR_LIVE_REPOSITORIES' => json_encode( [ 'rest-server' => $fixtures ], JSON_THROW_ON_ERROR ) ];
			foreach ( [ 'PHP_INI_SCAN_DIR', 'PHPRC' ] as $setting ) {
				if ( false !== getenv( $setting ) ) {
					$env[ $setting ] = getenv( $setting );
				}
			}
			$child = proc_open(
				[ PHP_BINARY, __FILE__, '--hosted' ],
				[
					0 => [ 'pipe', 'r' ],
					1 => STDOUT,
					2 => STDERR,
				],
				$pipes,
				null,
				$env
			);
			if ( false === $child ) {
				throw new RuntimeException( 'The local CLI could not start.' );
			}
			fclose( $pipes[0] );
			if ( 0 !== proc_close( $child ) ) {
				throw new RuntimeException( 'The local CLI smoke run failed.' );
			}
			$smoke->run( $fixtures['append-only'], true );
			fwrite( STDOUT, "Repeat append-only run passed with the previous snapshot present.\n" );
		} finally {
			foreach ( $servers as $server ) {
				$server->stop();
			}
		}
	} elseif ( [ '--hosted' ] === array_slice( $argv, 1 ) ) {
		$providers = smoke_fixtures( (string) getenv( 'BFR_LIVE_REPOSITORIES' ) );
		foreach ( $providers as $provider => $modes ) {
			foreach ( $modes as $mode => $fixture ) {
				$label = $provider . ' ' . $mode;
				$smoke->run( $fixture, 'append-only' === $mode );
				fwrite( STDOUT, $label . " passed\n" );
			}
		}
	} else {
		throw new RuntimeException( 'Choose exactly one execution mode.' );
	}
} catch ( Throwable $error ) {
	// Server errors may quote a URL, a username or even credentials. Only fixed stages,
	// validated fixture labels and exception class names belong in public CI logs.
	fwrite( STDERR, sprintf( "Smoke failed for %s at %s (%s).\n", $label, $smoke->stage, $error::class ) );
	exit( 1 );
}
