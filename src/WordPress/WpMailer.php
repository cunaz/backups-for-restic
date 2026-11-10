<?php
/**
 * Notification e-mails.
 *
 * @package BackupsForRestic
 */

declare( strict_types=1 );

namespace BackupsForRestic\WordPress;

use BackupsForRestic\Notifications\Mailer;
use BackupsForRestic\Notifications\Notice;
use BackupsForRestic\Notifications\NoticeKind;
use BackupsForRestic\Settings\Settings;
use BackupsForRestic\Support\Decoded;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the translated plain-text e-mail for a notice and hands it to wp_mail(). The texts
 * come from codes, times and counts only, never from a log, a path or a setting that could
 * hold a secret (docs/02-architecture.md §14).
 */
final class WpMailer implements Mailer {

	/**
	 * Creates the mailer.
	 *
	 * @param Settings $settings Settings, for the recipients.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Sends one notice.
	 *
	 * @param Notice $notice Notice.
	 * @return bool True if handed over or if there is nobody to send it to.
	 */
	public function send( Notice $notice ): bool {
		$recipients = $this->recipients( $notice );
		if ( [] === $recipients ) {
			return true;
		}
		[ $subject, $body ] = self::compose( $notice );
		try {
			return wp_mail( $recipients, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Who receives the notice: the configured addresses, or the site's administration e-mail.
	 *
	 * @param Notice $notice Notice.
	 * @return list<string> Valid addresses.
	 */
	private function recipients( Notice $notice ): array {
		$configured = $this->settings->list( 'notifications.email.recipients' );
		/**
		 * Filters who receives backup notifications.
		 *
		 * @param list<string> $recipients E-mail addresses from the settings, or the site's
		 *                                 administration e-mail address if none are set.
		 * @param string       $kind       failure, stale, recovery, check_findings or retention_external.
		 */
		$filtered = apply_filters( 'backups_for_restic_notification_recipients', [] === $configured ? [ get_bloginfo( 'admin_email' ) ] : $configured, $notice->kind->value );
		$valid    = [];
		foreach ( Decoded::strings( $filtered ) as $address ) {
			if ( false !== is_email( $address ) && ! in_array( $address, $valid, true ) ) {
				$valid[] = $address;
			}
		}
		return $valid;
	}

	/**
	 * Subject and body of a notice.
	 *
	 * @param Notice $notice Notice.
	 * @return array{0: string, 1: string} Subject and plain-text body.
	 */
	public static function compose( Notice $notice ): array {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$what = Messages::job_type( $notice->job_type );
		$url  = home_url();
		switch ( $notice->kind ) {
			case NoticeKind::Failure:
				$error = Messages::job_error( $notice->code );
				/* translators: 1: site title, 2: kind of job such as "Backup" or "Check". */
				$subject = sprintf( __( '[%1$s] %2$s failed', 'backups-for-restic' ), $site, $what );
				$lines   = [
					/* translators: 1: kind of job such as "Backup", 2: site address, 3: date and time. */
					sprintf( __( 'A job of the backup plugin failed: %1$s on %2$s, %3$s.', 'backups-for-restic' ), $what, $url, self::time( $notice->at ) ),
					'',
					/* translators: %s: what went wrong, one sentence. */
					sprintf( __( 'What happened: %s', 'backups-for-restic' ), $error['message'] ),
					/* translators: %s: what to do about it, one or two sentences. */
					sprintf( __( 'What to do: %s', 'backups-for-restic' ), $error['hint'] ),
					'',
					__( 'If it fails again, you will not get another e-mail about it; you will get one when it works again.', 'backups-for-restic' ),
				];
				break;
			case NoticeKind::Stale:
				/* translators: %s: site title. */
				$subject = sprintf( __( '[%s] No recent backup', 'backups-for-restic' ), $site );
				$lines   = [
					'' === $notice->job_id
						/* translators: 1: site address, 2: date and time. */
						? sprintf( __( 'No backup of %1$s has finished since the setup on %2$s.', 'backups-for-restic' ), $url, self::time( $notice->since ) )
						/* translators: 1: site address, 2: date and time, 3: time span such as "2 days". */
						: sprintf( __( 'The last backup of %1$s finished on %2$s, %3$s ago.', 'backups-for-restic' ), $url, self::time( $notice->since ), human_time_diff( $notice->since, $notice->at ) ),
					'',
					__( 'The schedule should have created a newer one. The dashboard shows the last job and its error; the Environment tab shows whether WordPress runs its scheduled tasks.', 'backups-for-restic' ),
					'',
					__( 'You will not get another e-mail about this until a backup succeeds again.', 'backups-for-restic' ),
				];
				break;
			case NoticeKind::Recovery:
				$subject = 'backup' === $notice->job_type
					/* translators: %s: site title. */
					? sprintf( __( '[%s] Backups work again', 'backups-for-restic' ), $site )
					/* translators: 1: site title, 2: kind of job such as "Check". */
					: sprintf( __( '[%1$s] %2$s works again', 'backups-for-restic' ), $site, $what );
				$lines = [
					/* translators: 1: kind of job such as "Backup", 2: site address, 3: date and time. */
					sprintf( __( '%1$s on %2$s succeeded on %3$s.', 'backups-for-restic' ), $what, $url, self::time( $notice->at ) ),
					/* translators: %s: date and time. */
					sprintf( __( 'The problem reported on %s is resolved.', 'backups-for-restic' ), self::time( $notice->since ) ),
				];
				break;
			case NoticeKind::CheckFindings:
				/* translators: %s: site title. */
				$subject = sprintf( __( '[%s] The backup check found damage', 'backups-for-restic' ), $site );
				$lines   = [
					sprintf(
						/* translators: 1: number of errors, 2: site address, 3: date and time. */
						_n( 'The check of the backup repository of %2$s found %1$d error on %3$s.', 'The check of the backup repository of %2$s found %1$d errors on %3$s.', $notice->count, 'backups-for-restic' ),
						$notice->count,
						$url,
						self::time( $notice->at )
					),
					__( 'Some snapshots may not restore completely.', 'backups-for-restic' ),
					'',
					__( 'Do not delete anything. The dashboard lists the findings. Run "restic check" from another computer; the disaster-recovery guide explains how to repair the repository.', 'backups-for-restic' ),
				];
				break;
			default:
				/* translators: %s: site title. */
				$subject = sprintf( __( '[%s] Old backups are no longer removed by this site', 'backups-for-restic' ), $site );
				$lines   = [
					__( 'The repository server refused to delete data: it is append-only. That protects your backups from anyone who takes over this site.', 'backups-for-restic' ),
					'',
					__( 'The plugin therefore switched its retention mode to "external": it no longer removes old snapshots. Remove them from another computer with "restic forget" and "restic prune"; the disaster-recovery guide explains how. Backups continue as before.', 'backups-for-restic' ),
				];
		}
		$lines[] = '';
		/* translators: %s: address of the backup dashboard. */
		$lines[] = sprintf( __( 'Backup dashboard: %s', 'backups-for-restic' ), admin_url( 'admin.php?page=' . AdminPage::SLUG ) );
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = __( 'Sent by the Backups for restic plugin. Choose who receives these e-mails, or turn them off, under Backups > Settings > Notifications.', 'backups-for-restic' );
		return [ $subject, implode( "\n", $lines ) ];
	}

	/**
	 * A time in the site's time zone and formats.
	 *
	 * @param int $time Unix time.
	 * @return string Date and time.
	 */
	private static function time( int $time ): string {
		return (string) wp_date( Decoded::string( get_option( 'date_format' ), 'Y-m-d' ) . ' ' . Decoded::string( get_option( 'time_format' ), 'H:i' ), $time );
	}
}
