<?php
/**
 * Tests a repository server before it is saved.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Connection;

use BackupsForRestic\Backend\AppendOnlyProbe;
use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\ObjectType;
use BackupsForRestic\Backend\RestBackend;
use BackupsForRestic\Crypto\RandomSource;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Secrets\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * The wizard's connection test (ADR 0025): URL rules, authentication, whether a repository
 * exists, ranged reads, and append-only detection (ADR 0010). Nothing is written except the
 * probe, which deletes a name that does not exist.
 */
final class ConnectionTester {

	/**
	 * Creates the tester.
	 *
	 * @param HttpClient   $http       HTTP client, with the uploaded CA certificate if any.
	 * @param RandomSource $random     Randomness for the probe.
	 * @param string       $user_agent User-Agent.
	 */
	public function __construct(
		private readonly HttpClient $http,
		private readonly RandomSource $random,
		private readonly string $user_agent
	) {
	}

	/**
	 * Tests a server.
	 *
	 * @param string      $server   borgbase or rest-server.
	 * @param string      $url      URL as entered.
	 * @param string      $username REST username; empty for none.
	 * @param Secret|null $password REST password.
	 * @return ConnectionReport Report.
	 * @throws InvalidArgument If the URL breaks the rules, or the server does not support ranged reads.
	 * @throws Unauthorized If the credentials are rejected.
	 * @throws BackendException If the server cannot be reached.
	 */
	public function test( string $server, string $url, string $username, ?Secret $password ): ConnectionReport {
		$url     = ServerProfile::normalize( $server, $url );
		$backend = new RestBackend( $this->http, $url, '' === $username ? null : $username, $password?->reveal(), $this->user_agent, 2 );
		$exists  = null !== $backend->size( ObjectType::Config, 'config' );
		try {
			$keys = array_keys( $backend->list( ObjectType::Keys ) );
		} catch ( NotFound ) {
			$keys = [];
		}
		// restic reads packs in ranges; a key file is the smallest object to try it on. The
		// config itself is always served whole, even by rest-server.
		if ( [] !== $keys ) {
			try {
				$backend->load( ObjectType::Keys, $keys[0], 0, 1 );
			} catch ( BackendException $e ) {
				InvalidArgument::raise( 'The server does not support ranged reads.', [ 'code' => 'server_incompatible' ], $e );
			}
		}
		return new ConnectionReport( $url, $exists, count( $keys ), AppendOnlyProbe::detect( $backend, $this->random ) );
	}
}
