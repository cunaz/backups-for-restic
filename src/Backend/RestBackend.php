<?php
/**
 * Client for the restic REST protocol.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\Backend;

use BackupsForRestic\Backend\Http\HttpClient;
use BackupsForRestic\Backend\Http\HttpResponse;
use BackupsForRestic\Exception\AlreadyExists;
use BackupsForRestic\Exception\AppendOnlyRefused;
use BackupsForRestic\Exception\BackendException;
use BackupsForRestic\Exception\FormatException;
use BackupsForRestic\Exception\InvalidArgument;
use BackupsForRestic\Exception\NotFound;
use BackupsForRestic\Exception\QuotaExceeded;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Exception\Unauthorized;
use BackupsForRestic\Exception\UploadRejected;
use BackupsForRestic\Secrets\Secret;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\GoJson;
use BackupsForRestic\Support\WorkFile;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to rest-server and BorgBase (ADR 0001, 0025).
 *
 * Credentials travel only in the Authorization header. Requests that cannot change the
 * repository in unintended ways are retried with exponential backoff on network errors and
 * 5xx responses; 4xx responses are final and mapped to typed exceptions.
 */
final class RestBackend implements Backend {

	private const string LIST_V2 = 'application/vnd.x.restic.rest.v2';

	/**
	 * The most that a whole repository file of each kind is read (threat T-19). The server is
	 * not trusted: without a limit it could answer a request for a lock with a gigabyte, and
	 * PHP would hold it. The values leave room: restic writes configs and keys of under a
	 * kilobyte, locks and snapshots of a few, and splits its index into files of a few
	 * megabytes; 128 MiB is what an old, unsplit index can reach and what Compression takes
	 * for a decompressed file. Packs are read in ranges, never whole, but the limit is there.
	 */
	private const array MAX_BYTES = [
		'config'    => 65536,
		'keys'      => 65536,
		'locks'     => 1048576,
		'snapshots' => 16777216,
		'index'     => 134217728,
		'data'      => 134217728,
	];

	/** The most that a listing is read: a million packs with their sizes. */
	private const int MAX_LISTING_BYTES = 134217728;

	/** The most that is read of an answer whose body nothing uses: a status, an error page. */
	private const int MAX_ANSWER_BYTES = 65536;

	/**
	 * Base URL ending with a slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Waits between retries; replaceable in tests.
	 *
	 * @var \Closure(int): void
	 */
	private \Closure $sleep;

	/**
	 * Basic auth password, kept out of dumps.
	 *
	 * @var Secret|null
	 */
	private readonly ?Secret $password;

	/**
	 * Creates a client.
	 *
	 * @param HttpClient    $http         Transport.
	 * @param string        $url          Repository URL (https://host[:port]/path/).
	 * @param string|null   $username     Basic auth user name.
	 * @param string|null   $password     Basic auth password.
	 * @param string        $user_agent   User-Agent header.
	 * @param int           $max_attempts Attempts for retryable requests.
	 * @param \Closure|null $sleep      Sleep function taking milliseconds.
	 * @throws InvalidArgument If the URL is not an https URL.
	 */
	public function __construct(
		private readonly HttpClient $http,
		string $url,
		private readonly ?string $username = null,
		#[\SensitiveParameter] ?string $password = null,
		private readonly string $user_agent = 'backups-for-restic',
		private readonly int $max_attempts = 5,
		?\Closure $sleep = null
	) {
		$this->password = null === $password ? null : new Secret( $password );
		$parts          = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || '' === ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			throw new InvalidArgument( 'Repository URL must be an https URL without credentials, query or fragment.' );
		}
		$this->base_url = rtrim( $url, '/' ) . '/';
		$this->sleep    = $sleep ?? static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
	}

	/**
	 * Creates the repository layout on the server.
	 */
	public function create(): void {
		$response = $this->send( 'POST', $this->base_url . '?create=true', [], '', true );
		$this->expect_success( $response, 'create' );
	}

	/**
	 * Returns the size of an object, or null if it does not exist.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 * @return int|null Size.
	 */
	public function size( ObjectType $type, string $name ): ?int {
		$response = $this->send( 'HEAD', $this->url( $type, $name ), [], '', true );
		if ( 404 === $response->status ) {
			return null;
		}
		$this->expect_success( $response, 'stat' );
		$length = $response->header( 'content-length' );
		return '' !== $length && strspn( $length, '0123456789' ) === strlen( $length ) ? (int) $length : null;
	}

	/**
	 * Loads an object or a range of it.
	 *
	 * @param ObjectType $type   Object type.
	 * @param string     $name   Object name.
	 * @param int        $offset First byte.
	 * @param int|null   $length Number of bytes.
	 * @return string Bytes.
	 */
	public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string {
		$headers = [];
		if ( $offset > 0 || null !== $length ) {
			$headers['Range'] = 'bytes=' . $offset . '-' . ( null === $length ? '' : (string) ( $offset + $length - 1 ) );
		}

		// A range is as long as it was asked for; a whole file as long as its kind may be.
		$limit    = $length ?? self::MAX_BYTES[ $type->value ];
		$response = $this->send( 'GET', $this->url( $type, $name ), $headers, '', true, $limit );
		$this->expect_success( $response, 'load' );
		if ( null === $length && strlen( $response->body ) > $limit ) {
			FormatException::raise(
				'The server sent more than a repository file of this kind can hold.',
				[
					'type'  => $type->value,
					'limit' => $limit,
				]
			);
		}

		if ( null !== $length && strlen( $response->body ) !== $length ) {
			BackendException::raise(
				'The server did not return the number of bytes that were requested.',
				[
					'expected' => $length,
					'received' => strlen( $response->body ),
				]
			);
		}
		return $response->body;
	}

	/**
	 * Stores an object.
	 *
	 * Uploads are retried. Names are content hashes, so an object that already exists with the
	 * expected size is the object we sent, for example after a lost response.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 * @param string     $body Content.
	 */
	public function save( ObjectType $type, string $name, string $body ): void {
		$attempts = ObjectType::Config === $type ? 1 : $this->max_attempts;
		for ( $attempt = 1; ; $attempt++ ) {
			$response = $this->send( 'POST', $this->url( $type, $name ), [ 'Content-Type' => 'binary/octet-stream' ], $body, false );
			if ( $response->status >= 200 && $response->status < 300 ) {
				return;
			}
			if ( 403 === $response->status ) {
				// Names are content hashes: an existing object of the same size is this object.
				if ( ObjectType::Config !== $type && $this->size( $type, $name ) === strlen( $body ) ) {
					return;
				}
				AlreadyExists::raise( 'An object with this name already exists.', [ 'type' => $type->value ] );
			}
			if ( ! $this->retryable( $response ) || $attempt >= $attempts ) {
				$this->expect_success( $response, 'save' );
			}
			( $this->sleep )( $this->backoff( $attempt ) );
		}
	}

	/**
	 * Deletes an object.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 */
	public function delete( ObjectType $type, string $name ): void {
		$response = $this->send( 'DELETE', $this->url( $type, $name ), [], '', true );
		if ( 403 === $response->status || 405 === $response->status ) {
			AppendOnlyRefused::raise( 'The server refused to delete an object; the repository is append-only.', [ 'type' => $type->value ] );
		}
		if ( 404 === $response->status ) {
			return;
		}
		$this->expect_success( $response, 'delete' );
	}

	/**
	 * Lists objects of a type, using the v2 listing with sizes when the server supports it.
	 * Names that are not object ids are skipped, as restic does: PHP would turn a name such
	 * as "12" into an integer array key, and no caller could use it anyway.
	 *
	 * @param ObjectType $type Object type.
	 * @return array<string, int> Sizes by name (-1 if the server only reports names).
	 * @throws BackendException If the request fails or the listing is malformed.
	 */
	public function list( ObjectType $type ): array {
		$response = $this->send( 'GET', $this->base_url . $type->value . '/', [ 'Accept' => self::LIST_V2 ], '', true, self::MAX_LISTING_BYTES );
		$this->expect_success( $response, 'list' );
		if ( strlen( $response->body ) > self::MAX_LISTING_BYTES ) {
			BackendException::raise( 'The listing is larger than the plugin can read.', [ 'type' => $type->value ] );
		}

		$entries = GoJson::decode( $response->body );
		if ( null === $entries ) {
			return [];
		}
		if ( ! is_array( $entries ) ) {
			BackendException::raise( 'Server returned an invalid listing.' );
		}

		$result = [];
		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$name = $entry;
				$size = -1;
			} elseif ( is_array( $entry ) && is_string( $entry['name'] ?? null ) && is_int( $entry['size'] ?? null ) && $entry['size'] >= 0 ) {
				$name = $entry['name'];
				$size = $entry['size'];
			} else {
				BackendException::raise( 'Server returned an invalid listing entry.' );
			}
			if ( 1 === preg_match( '/\A[0-9a-f]{64}\z/', $name ) ) {
				$result[ $name ] = $size;
			}
		}
		return $result;
	}

	/**
	 * Downloads a listing directly to a private file, publishing it only after HTTP success.
	 * A retry truncates the temporary file; a failed request preserves the previous listing.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $file Destination.
	 * @throws BackendException If the response is too large or the request fails.
	 * @throws StorageFailed If local file operations fail.
	 */
	public function list_to_file( ObjectType $type, string $file ): void {
		$temporary = $file . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		try {
			$destination = new WorkFile( $temporary, true );
			$destination->close();
			$response = $this->send( 'GET', $this->base_url . $type->value . '/', [ 'Accept' => self::LIST_V2 ], '', true, self::MAX_LISTING_BYTES, $temporary );
			$this->expect_success( $response, 'list' );
			$download = new WorkFile( $temporary );
			$size     = $download->size();
			$download->close();
			if ( $size > self::MAX_LISTING_BYTES ) {
				BackendException::raise( 'The listing is larger than the plugin can read.', [ 'type' => $type->value ] );
			}
			if ( ! rename( $temporary, $file ) ) {
				StorageFailed::raise( 'The downloaded listing could not be replaced.' );
			}
		} finally {
			if ( is_file( $temporary ) && ! unlink( $temporary ) ) {
				StorageFailed::raise( 'An incomplete listing download could not be removed.' );
			}
		}
	}

	/**
	 * Builds the URL of an object.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $name Object name.
	 * @return string URL.
	 * @throws InvalidArgument If the name is not a hex identifier.
	 */
	private function url( ObjectType $type, string $name ): string {
		if ( ObjectType::Config === $type ) {
			return $this->base_url . 'config';
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $name ) ) {
			throw new InvalidArgument( 'Object names must be 64 lowercase hex digits.' );
		}
		return $this->base_url . $type->value . '/' . $name;
	}

	/**
	 * Sends a request, retrying idempotent ones on transient failures.
	 *
	 * @param string                $method     HTTP method.
	 * @param string                $url        URL.
	 * @param array<string, string> $headers    Extra headers.
	 * @param string                $body       Body.
	 * @param bool                  $idempotent Whether retries are safe.
	 * @param int                   $limit      Most bytes of response body to read.
	 * @param string|null           $file       Private destination for a streamed response.
	 * @return HttpResponse Response.
	 * @throws BackendException When all attempts fail without a response.
	 */
	private function send( string $method, string $url, array $headers, string $body, bool $idempotent, int $limit = self::MAX_ANSWER_BYTES, ?string $file = null ): HttpResponse {
		$headers['User-Agent'] = $this->user_agent;
		if ( null !== $this->username && '' !== $this->username ) {
			$headers['Authorization'] = 'Basic ' . Bytes::base64_encode( $this->username . ':' . ( $this->password?->reveal() ?? '' ) );
		}

		$attempts = $idempotent ? $this->max_attempts : 1;
		for ( $attempt = 1; ; $attempt++ ) {
			try {
				$response = $this->http->request( $method, $url, $headers, $body, $limit, $file );
				if ( 401 === $response->status ) {
					Unauthorized::raise( 'The server rejected the repository credentials.' );
				}
				if ( $attempt < $attempts && $this->retryable( $response ) ) {
					( $this->sleep )( $this->backoff( $attempt ) );
					continue;
				}
				return $response;
			} catch ( Unauthorized $exception ) {
				throw $exception;
			} catch ( BackendException $exception ) {
				if ( $attempt >= $attempts ) {
					throw $exception;
				}
				( $this->sleep )( $this->backoff( $attempt ) );
			}
		}
	}

	/**
	 * Whether a response may succeed when retried.
	 *
	 * @param HttpResponse $response Response.
	 * @return bool True for 5xx except 507 and for 429.
	 */
	private function retryable( HttpResponse $response ): bool {
		return 429 === $response->status || ( $response->status >= 500 && 507 !== $response->status );
	}

	/**
	 * Exponential backoff: 0.5 s, 1 s, 2 s, 4 s, …
	 *
	 * @param int $attempt Failed attempt number.
	 * @return int Milliseconds.
	 */
	private function backoff( int $attempt ): int {
		return min( 8000, 500 * ( 2 ** ( $attempt - 1 ) ) );
	}

	/**
	 * Maps an unsuccessful response to a typed exception.
	 *
	 * @param HttpResponse $response  Response.
	 * @param string       $operation Operation name for diagnostics.
	 * @throws NotFound|UploadRejected|QuotaExceeded|Unauthorized|BackendException Unless the status is 2xx.
	 */
	private function expect_success( HttpResponse $response, string $operation ): void {
		$status  = $response->status;
		$context = [
			'operation' => $operation,
			'status'    => $status,
		];
		if ( $status >= 200 && $status < 300 ) {
			return;
		}
		match ( true ) {
			404 === $status => NotFound::raise( 'The object does not exist on the server.', $context ),
			400 === $status => UploadRejected::raise( 'The server rejected the upload because its content did not match its name.', $context ),
			403 === $status => Unauthorized::raise( 'The server denied access to the repository.', $context ),
			507 === $status => QuotaExceeded::raise( 'The repository has no space left on the server.', $context ),
			default => BackendException::raise( 'The server answered with an unexpected status.', $context ),
		};
	}
}
