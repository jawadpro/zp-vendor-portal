<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Sends portal emails through wp_mail (so Gravity SMTP / SendGrid delivers
 * them), wraps them in a simple branded template and logs every send.
 */
final class Mailer {

	public const OPT_SETTINGS = 'zpvp_email_settings';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_email_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL DEFAULT '',
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			edition_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'sent',
			error text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_reg (type,registration_id),
			KEY type_entry (type,entry_id),
			KEY edition_id (edition_id)
			) {$charset};"
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'confirm_registration' => 0, // Gravity Forms notifications usually already confirm registrations.
				'confirm_payment'      => 1,
				'deadline_reminder'    => 1,
				'reminder_days'        => 3,
				'reply_to'             => '',
			)
		);
	}

	/**
	 * Unique, valid addresses for a registration: form contact plus the vendor
	 * account, and every contact at the vendor's company (unless switched off).
	 */
	public static function recipients( object $reg ): array {
		$emails = array( (string) ( $reg->contact_email ?? '' ) );
		if ( ! empty( $reg->user_id ) ) {
			$user = get_userdata( (int) $reg->user_id );
			if ( $user ) {
				$emails[] = $user->user_email;
			}
		}
		if ( ! empty( $reg->company_id ) && Companies::email_all_contacts() ) {
			$emails = array_merge( $emails, Companies::contact_emails( (int) $reg->company_id ) );
		}
		$emails = array_map( 'strtolower', array_filter( array_map( 'trim', $emails ), 'is_email' ) );
		return array_values( array_unique( $emails ) );
	}

	public static function already_sent( string $type, array $where ): bool {
		global $wpdb;
		$sql  = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE type = %s AND status = 'sent'";
		$args = array( $type );
		foreach ( array( 'registration_id', 'entry_id', 'edition_id' ) as $col ) {
			if ( isset( $where[ $col ] ) ) {
				$sql   .= " AND {$col} = %d";
				$args[] = (int) $where[ $col ];
			}
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) > 0;
	}

	/**
	 * @param string[]                                   $to
	 * @param array<int, array{name:string, data:string}> $attachments In-memory files.
	 */
	public static function send( array $to, string $subject, string $body_html, array $attachments = array(), array $context = array() ): bool {
		global $wpdb;
		$to = array_values( array_filter( $to, 'is_email' ) );
		if ( ! $to ) {
			return false;
		}

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply_to = (string) self::settings()['reply_to'];
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$files = array();
		foreach ( $attachments as $a ) {
			$dir  = trailingslashit( get_temp_dir() ) . 'zpvp-' . wp_generate_password( 12, false );
			wp_mkdir_p( $dir );
			$path = $dir . '/' . sanitize_file_name( $a['name'] );
			file_put_contents( $path, $a['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- temp file for attachment.
			$files[] = $path;
		}

		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error instanceof \WP_Error ? $wp_error->get_error_message() : 'Unknown mail error';
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to, $subject, self::template( $subject, $body_html ), $headers, $files );
		remove_action( 'wp_mail_failed', $catch );

		foreach ( $files as $path ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		foreach ( $to as $recipient ) {
			$wpdb->insert(
				self::table(),
				array(
					'type'            => (string) ( $context['type'] ?? 'other' ),
					'registration_id' => (int) ( $context['registration_id'] ?? 0 ),
					'entry_id'        => (int) ( $context['entry_id'] ?? 0 ),
					'edition_id'      => (int) ( $context['edition_id'] ?? 0 ),
					'recipient'       => $recipient,
					'subject'         => mb_substr( $subject, 0, 255 ),
					'status'          => $ok ? 'sent' : 'failed',
					'error'           => $ok ? null : $error,
					'created_at'      => current_time( 'mysql' ),
				)
			);
		}
		return (bool) $ok;
	}

	public static function template( string $title, string $body_html ): string {
		$issuer = trim( (string) get_option( Documents::OPT_ISSUER, get_bloginfo( 'name' ) ) );
		$lines  = preg_split( '/\R/', $issuer );
		$brand  = esc_html( (string) array_shift( $lines ) );
		$footer = esc_html( implode( ' · ', array_filter( $lines ) ) );
		return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2a37">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f7;padding:24px 0"><tr><td align="center">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px">'
			. '<tr><td style="padding:20px 28px;border-bottom:1px solid #e5e7eb;font-size:16px;font-weight:bold">' . $brand . '</td></tr>'
			. '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55">' . $body_html . '</td></tr>'
			. '<tr><td style="padding:16px 28px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280">' . $footer . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Replace {placeholders} for one registration. Values are escaped. */
	public static function personalize( string $text, object $reg ): string {
		$user = $reg->user_id ? get_userdata( (int) $reg->user_id ) : null;
		$name = $user && $user->first_name ? $user->first_name : ( $reg->contact_name ? strtok( $reg->contact_name, ' ' ) : '' );
		$map  = array(
			'{first_name}'   => $name,
			'{contact_name}' => $reg->contact_name,
			'{company}'      => $reg->company_name,
			'{conference}'   => Registrations::conference_label( $reg ),
			'{dates}'        => $reg->edition_id ? Conferences::date_range( (int) $reg->edition_id ) : '',
			'{booths}'       => (string) $reg->booths,
			'{booth_number}' => $reg->booth_number ? $reg->booth_number : __( 'not assigned yet', 'zp-vendor-portal' ),
			'{portal_link}'  => Settings::page_url( 'dashboard' ),
		);
		return strtr( $text, array_map( 'esc_html', $map ) );
	}
}
