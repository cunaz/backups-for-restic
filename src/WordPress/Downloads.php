<?php
/**
 * One-time downloads of staged files.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Exception\PluginException;
use BackupsForRestic\Storage\DownloadTokens;
use BackupsForRestic\Support\Bytes;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot stream or close the verified file descriptor.

/**
 * A capability-checked REST call issues a form for a browser download. Submitting it
 * consumes the token and checks expiry again before opening the file. POST keeps both
 * the credential and the original file name out of the request URL.
 */
final readonly class Downloads {

	private const string ACTION = 'backups_for_restic_download';

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
	 * Issues a form for a file that exists in a completed restore.
	 *
	 * @param string $id   Job ID.
	 * @param string $kind file, archive or report.
	 * @param string $path Absolute snapshot path for a file.
	 * @return array{url: string, fields: array<string, string>}|null Browser form or unavailable.
	 */
	public function issue( string $id, string $kind, string $path ): ?array {
		$handle = $this->open( $id, $kind, $path );
		if ( null === $handle ) {
			return null;
		}
		fclose( $handle );
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
