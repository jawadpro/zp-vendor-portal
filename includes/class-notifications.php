<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic emails: registration confirmation, payment received and the
 * attendee edit deadline reminder. Each can be switched off in
 * Vendor Portal → Messages.
 */
final class Notifications {

	public const CRON = 'zpvp_daily';

	public function register(): void {
		add_action( 'zpvp_entry_processed', array( $this, 'registration_confirmation' ), 10, 1 );
		add_action( 'zpvp_payment_status_changed', array( $this, 'payment_received' ), 10, 3 );
		add_action( self::CRON, array( $this, 'deadline_reminders' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** @return object[] */
	private static function entry_regs( int $entry_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE gf_entry_id = %d AND status = 'active' ORDER BY id", $entry_id ) );
	}

	private static function conference_list( array $regs ): string {
		$items = array_map(
			static function ( $r ) {
				$dates = $r->edition_id ? Conferences::date_range( (int) $r->edition_id ) : '';
				return '<li>' . esc_html( Registrations::conference_label( $r ) . ( $dates ? ' (' . $dates . ')' : '' ) . ' – ' . Registrations::summary( $r ) ) . '</li>';
			},
			$regs
		);
		return '<ul>' . implode( '', $items ) . '</ul>';
	}

	private static function button( string $url, string $label ): string {
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="background:#1d5c8c;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;display:inline-block">' . esc_html( $label ) . '</a></p>';
	}

	private static function document_attachment( object $reg ): array {
		$kind = Documents::kind( $reg );
		return array(
			array(
				'name' => $kind . '-' . Documents::number( $reg ) . '.pdf',
				'data' => Documents::build( $reg ),
			),
		);
	}

	public function registration_confirmation( $entry_id ): void {
		if ( ! Mailer::settings()['confirm_registration'] ) {
			return;
		}
		$regs = self::entry_regs( (int) $entry_id );
		if ( ! $regs || Mailer::already_sent( 'registration_confirmation', array( 'entry_id' => (int) $entry_id ) ) ) {
			return;
		}
		$reg  = $regs[0];
		$kind = Documents::kind( $reg );
		$body = '<p>' . Mailer::personalize( __( 'Hi {first_name},', 'zp-vendor-portal' ), $reg ) . '</p>'
			. '<p>' . esc_html__( 'Thank you for registering. We have recorded the following:', 'zp-vendor-portal' ) . '</p>'
			. self::conference_list( $regs )
			. '<p>' . esc_html(
				'receipt' === $kind
					? __( 'Your payment has been received. Your receipt is attached.', 'zp-vendor-portal' )
					: __( 'Your invoice is attached. You can also download it at any time from your vendor account.', 'zp-vendor-portal' )
			) . '</p>'
			. '<p>' . esc_html__( 'Please log in to add or update your booth personnel.', 'zp-vendor-portal' ) . '</p>'
			. self::button( Settings::page_url( 'dashboard' ), __( 'Go to My Conferences', 'zp-vendor-portal' ) );

		Mailer::send(
			Mailer::recipients( $reg ),
			__( 'Your conference registration', 'zp-vendor-portal' ),
			$body,
			self::document_attachment( $reg ),
			array( 'type' => 'registration_confirmation', 'registration_id' => (int) $reg->id, 'entry_id' => (int) $entry_id, 'edition_id' => (int) $reg->edition_id )
		);
	}

	public function payment_received( $registration_id, $old, $new ): void {
		if ( 'paid' !== $new || ! Mailer::settings()['confirm_payment'] ) {
			return;
		}
		$reg = Registrations::get( (int) $registration_id );
		if ( ! $reg || 'receipt' !== Documents::kind( $reg ) ) {
			return; // Wait until everything on the entry is paid.
		}
		if ( Mailer::already_sent( 'payment_received', array( 'entry_id' => (int) $reg->gf_entry_id ) ) ) {
			return;
		}
		$regs = self::entry_regs( (int) $reg->gf_entry_id );
		$body = '<p>' . Mailer::personalize( __( 'Hi {first_name},', 'zp-vendor-portal' ), $reg ) . '</p>'
			. '<p>' . esc_html__( 'We have received your payment. Thank you! Your receipt is attached.', 'zp-vendor-portal' ) . '</p>'
			. self::conference_list( $regs )
			. self::button( Settings::page_url( 'dashboard' ), __( 'Go to My Conferences', 'zp-vendor-portal' ) );

		Mailer::send(
			Mailer::recipients( $reg ),
			__( 'Payment received – thank you', 'zp-vendor-portal' ),
			$body,
			self::document_attachment( $reg ),
			array( 'type' => 'payment_received', 'registration_id' => (int) $reg->id, 'entry_id' => (int) $reg->gf_entry_id, 'edition_id' => (int) $reg->edition_id )
		);
	}

	/** Daily: remind vendors a few days before the attendee edit deadline. */
	public function deadline_reminders(): int {
		global $wpdb;
		$settings = Mailer::settings();
		if ( ! $settings['deadline_reminder'] ) {
			return 0;
		}
		$now  = current_time( 'mysql' );
		$soon = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp', true ) + (int) $settings['reminder_days'] * DAY_IN_SECONDS );

		$editions = get_posts(
			array(
				'post_type'      => Conferences::EDITION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'attendee_edit_deadline',
						'value'   => array( $now, $soon ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$sent = 0;
		foreach ( $editions as $edition_id ) {
			$deadline = (string) get_post_meta( (int) $edition_id, 'attendee_edit_deadline', true );
			$regs     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE edition_id = %d AND status = 'active' AND payment_status <> 'failed'", (int) $edition_id ) );
			foreach ( (array) $regs as $reg ) {
				if ( Mailer::already_sent( 'deadline_reminder', array( 'registration_id' => (int) $reg->id ) ) ) {
					continue;
				}
				$people = Attendees::for_registration( (int) $reg->id );
				$list   = $people
					? '<ul>' . implode( '', array_map( static fn( $a ) => '<li>' . esc_html( $a->name . ( $a->title ? ', ' . $a->title : '' ) ) . '</li>', $people ) ) . '</ul>'
					: '<p><em>' . esc_html__( 'No booth personnel listed yet.', 'zp-vendor-portal' ) . '</em></p>';
				$body   = '<p>' . Mailer::personalize( __( 'Hi {first_name},', 'zp-vendor-portal' ), $reg ) . '</p>'
					. '<p>' . Mailer::personalize( __( 'This is a reminder that booth personnel for {conference} can be changed until', 'zp-vendor-portal' ), $reg ) . ' <strong>' . esc_html( mysql2date( 'F j, Y g:i a', $deadline ) ) . '</strong>. ' . esc_html__( 'Badges are printed from this list.', 'zp-vendor-portal' ) . '</p>'
					. '<p>' . esc_html__( 'Currently listed:', 'zp-vendor-portal' ) . '</p>' . $list
					. self::button( Vendor_Attendees::url( (int) $reg->id ), __( 'Review booth personnel', 'zp-vendor-portal' ) );

				if ( Mailer::send(
					Mailer::recipients( $reg ),
					Mailer::personalize( __( 'Reminder: confirm your booth personnel for {conference}', 'zp-vendor-portal' ), $reg ),
					$body,
					array(),
					array( 'type' => 'deadline_reminder', 'registration_id' => (int) $reg->id, 'entry_id' => (int) $reg->gf_entry_id, 'edition_id' => (int) $edition_id )
				) ) {
					++$sent;
				}
			}
		}
		return $sent;
	}
}
