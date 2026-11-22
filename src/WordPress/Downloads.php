<?php
/**
 * One-time downloads of staged files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Exception\StorageFailed;
use BackupsForRestic\Storage\DownloadTokens;
use BackupsForRestic\Support\Bytes;
use BackupsForRestic\Support\Decoded;
use BackupsForRestic\Support\GoQuote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot stream or close the verified file descriptor.

/**
 * A capability-checked REST call issues a form for a browser download. Submitting it
 * consumes the token and checks expiry again before opening the file. POST keeps both
 * the credential and the original file name out of the request URL.
 *
 * A restored file or archive is site content: it can be wp-config.php or the database dump,
 * which together decrypt the stored secrets. Its grant is issued only after the route has
 * checked the WordPress password, and only once it is recorded (docs/03 §5.5). The two
 * reports hold names, counts and table metadata, and need the capability alone.
 */
final readonly class Downloads {

	/** Entry of the state option that records the grants of restored content. */
	public const string AUDIT = 'downloads';

	/** Kinds that hold metadata only: the report of the staged entries and the database rehearsal's report. */
	public const array METADATA = [ 'report', 'database_report' ];

	private const string ACTION = 'backups_for_restic_download';

	/** Number of grants retained for the administrator's audit history. */
	private const int GRANTS = 20;

	/** Longest path the audit keeps, in bytes of its quoted form. */
	private const int PATH_BYTES = 1024;

	/**
	 * Creates the download handler.
	 *
	 * @param Container $container Plugin services.
	 */
	public function __construct( private Container $container ) {
	}

	/** Installs the token-authenticated handler for browser downloads. */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, $this->serve( ... ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, $this->serve( ... ) );
	}

	/**
	 * Whether a kind of download holds metadata only. Every other kind, an unknown one
	 * included, counts as content.
	 *
	 * @param string $kind Download kind.
	 * @return bool True for the restore report and the database rehearsal's report.
	 */
	public static function is_metadata( string $kind ): bool {
		return in_array( $kind, self::METADATA, true );
	}

	/**
	 * Issues a form for a file that exists in a completed restore. The caller has checked
	 * the WordPress password for every kind that is not metadata.
	 *
	 * @param string $id   Job ID.
	 * @param string $kind file, archive, report or database_report.
	 * @param string $path Absolute snapshot path for a file.
	 * @return array{url: string, fields: array<string, string>}|null Browser form or unavailable.
	 * @throws StorageFailed If the grant of content cannot be recorded; no token is issued then.
	 */
	public function issue( string $id, string $kind, string $path ): ?array {
		$handle = $this->open( $id, $kind, $path );
		if ( null === $handle ) {
			return null;
		}
		fclose( $handle );
		if ( ! self::is_metadata( $kind ) ) {
			$this->record( $id, $kind, $path );
		}
		// Encoding also preserves newlines and other name bytes through an HTML form.
		$subject = Bytes::base64url_encode( $id . "\0" . $kind . "\0" . $path );
		return [
			'url'    => admin_url( 'admin-post.php' ),
			'fields' => [
				'action'  => self::ACTION,
				'subject' => $subject,
				'token'   => $this->tokens()->issue( $subject ),
			],
		];
	}

	/** Streams the open file to the browser without passing its contents through JSON. */
	public function serve(): never {
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated by the single-use token; sanitization would change its subject.
		$subject = isset( $_POST['subject'] ) && is_string( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '';
		$token   = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		try {
			if ( '' === $subject || strlen( $subject ) > 5600 || ! $this->tokens()->redeem( $token, $subject ) ) {
				status_header( 403 );
				exit;
			}
			[ $id, $kind, $path ] = explode( "\0", Bytes::base64url_decode( $subject ), 3 );
			$handle               = $this->open( $id, $kind, $path );
		} catch ( PluginException ) {
			status_header( 503 );
			exit;
		}
		if ( null === $handle ) {
			status_header( 404 );
			exit;
		}
		$name = match ( $kind ) {
			'archive' => 'restore-' . $id . '.zip',
			'report'  => 'entries.jsonl',
			'database_report' => 'database-preview.json',
			default   => basename( $path ),
		};
		// A plugin or output_buffering can hold the complete file in memory or prefix it
		// with other output. Remove removable buffers before emitting the attachment.
		while ( ob_get_level() > 0 ) {
			if ( ! ob_end_clean() ) {
				fclose( $handle );
				status_header( 500 );
				exit;
			}
		}
		$stat = fstat( $handle );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		if ( false !== $stat ) {
			header( 'Content-Length: ' . $stat['size'] );
		}
		fpassthru( $handle );
		fclose( $handle );
		exit;
	}

	/**
	 * Records a grant of restored content before its token exists: the time, the user, the
	 * restore job, the kind and the path. The latest twenty stay in the state option, where
	 * the Settings tab shows them beside the deliveries of the recovery kit, and the action
	 * backups_for_restic_download_granted fires for audit plugins.
	 *
	 * @param string $id   Restore job ID, checked by open().
	 * @param string $kind file or archive.
	 * @param string $path Absolute snapshot path, empty for an archive.
	 * @throws StorageFailed If the entry cannot be stored.
	 */
	private function record( string $id, string $kind, string $path ): void {
		$user  = wp_get_current_user();
		$shown = self::shown( $path );
		$grant = [
			'at'    => time(),
			'user'  => $user->ID,
			'login' => $user->user_login,
			'job'   => $id,
			'kind'  => $kind,
			'path'  => $shown,
		];
		$this->container->state()->update( self::AUDIT, static fn ( array $entry ): array => [ 'grants' => array_slice( [ $grant, ...Decoded::arrays( $entry['grants'] ?? null ) ], 0, self::GRANTS ) ], true );
		/**
		 * Fires when a download of restored content has been granted to an authorized user.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param string $job     ID of the restore job.
		 * @param string $kind    file or archive.
		 * @param string $path    Snapshot path in Go's quoting, empty for an archive.
		 */
		do_action( 'backups_for_restic_download_granted', $user->ID, $id, $kind, $shown );
	}

	/**
	 * A snapshot path as the audit keeps it: in Go's quoting, the form of names in restic's
	 * tree JSON, so that control characters, line breaks and bytes that are not UTF-8 appear
	 * as escapes and cannot break a list or a log line. A longer one is cut, marked with
	 * three dots.
	 *
	 * @param string $path Snapshot path as the request named it.
	 * @return string Printable, valid UTF-8 text of at most 1027 bytes.
	 */
	public static function shown( string $path ): string {
		$quoted = GoQuote::quote( $path );
		return strlen( $quoted ) > self::PATH_BYTES ? mb_strcut( $quoted, 0, self::PATH_BYTES, 'UTF-8' ) . '...' : $quoted;
	}

	/**
	 * Opens a live staged download.
	 *
	 * @param string $id   Restore job ID.
	 * @param string $kind Download kind.
	 * @param string $path Snapshot path.
	 * @return resource|null Checked file, if available.
	 */
	private function open( string $id, string $kind, string $path ) {
		return $this->container->staging()->open_download( $id, $this->container->settings()->int( 'restore.staging_expiry_hours' ), $kind, $path );
	}

	/**
	 * Uses the private tmp directory so uninstall removes token state with other work files.
	 *
	 * @return DownloadTokens Token store.
	 */
	private function tokens(): DownloadTokens {
		return new DownloadTokens( $this->container->private_dir()->subdirectory( 'tmp' ), $this->container->random(), $this->container->clock() );
	}
}
