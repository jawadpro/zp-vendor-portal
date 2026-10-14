<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic "conference details changed" emails.
 *
 * When staff change a published conference's dates, venue, city, hotel,
 * decorator or electricity information, floor plan, documents or booth staff
 * deadline, the change is noted on the conference. A single email listing
 * everything that changed goes to its registered vendors a set number of
 * minutes after the last edit (so a round of edits sends one email). Staff can
 * send it straight away or cancel it from the conference screen.
 */
final class Change_Notices {

	public const OPT     = 'zpvp_change_notices';
	public const HOOK    = 'zpvp_send_change_notice';
	public const PENDING = '_zpvp_pending_changes';

	/** Watched conference fields and how they're described to vendors. */
	private const FIELDS = array(
		'start_date'             => 'Dates',
		'end_date'               => 'Dates',
		'venue'                  => 'Venue',
		'city'                   => 'City',
		'hotel_info'             => 'Hotel information',
		'decorator_info'         => 'Decorator information',
		'electricity_info'       => 'Electricity information',
		'floor_plan'             => 'Floor plan',
		'resource_files'         => 'Documents',
		'attendee_edit_deadline' => 'Deadline for booth staff changes',
	);

	/** Conferences published during this request (their first save is not a change). */
	private static array $fresh = array();

	public static function settings(): array {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array( 'enabled' => 1, 'delay' => 30 ) );
	}

	public static function save_enabled( bool $on ): void {
		$s            = self::settings();
		$s['enabled'] = $on ? 1 : 0;
		update_option( self::OPT, $s, false );
	}

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'published' ), 10, 3 );
		add_action( 'update_post_meta', array( $this, 'updating' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'added' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'deleted' ), 10, 4 );
		add_action( self::HOOK, array( __CLASS__, 'send' ), 10, 1 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . Conferences::EDITION, array( $this, 'meta_box' ) );
			add_action( 'admin_post_zpvp_change_notice', array( $this, 'handle' ) );
		}
	}

	public function published( $new, $old, $post ): void {
		if ( 'publish' === $new && 'publish' !== $old && Conferences::EDITION === $post->post_type ) {
			self::$fresh[ (int) $post->ID ] = true;
		}
	}

	/** Field label for a meta key, or '' when the key isn't watched. */
	private static function label( string $key ): string {
		if ( isset( self::FIELDS[ $key ] ) ) {
			return self::FIELDS[ $key ];
		}
		return str_starts_with( $key, 'resource_files_' ) ? self::FIELDS['resource_files'] : '';
	}

	private static function watching( int $post_id, string $key ): bool {
		return '' !== self::label( $key )
			&& self::settings()['enabled']
			&& empty( self::$fresh[ $post_id ] )
			&& Conferences::EDITION === get_post_type( $post_id )
			&& 'publish' === get_post_status( $post_id )
			&& 'past' !== Conferences::status( $post_id );
	}

	public function updating( $meta_id, $post_id, $key, $value ): void {
		if ( self::watching( (int) $post_id, (string) $key ) ) {
			self::note( (int) $post_id, (string) $key, get_post_meta( (int) $post_id, (string) $key, true ), $value );
		}
	}

	public function added( $meta_id, $post_id, $key, $value ): void {
		if ( self::watching( (int) $post_id, (string) $key ) ) {
			self::note( (int) $post_id, (string) $key, '', $value );
		}
	}

	public function deleted( $meta_ids, $post_id, $key, $value ): void {
		if ( self::watching( (int) $post_id, (string) $key ) ) {
			self::note( (int) $post_id, (string) $key, $value, '' );
		}
	}

	private static function plain( $v ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ) ) );
	}

	/** Remember a change (first old value, latest new value) and push the send back. */
	private static function note( int $edition_id, string $key, $old, $new ): void {
		if ( self::plain( $old ) === self::plain( $new ) && ! str_starts_with( $key, 'resource_files' ) && 'floor_plan' !== $key ) {
			return; // Only formatting changed.
		}
		$pending = get_post_meta( $edition_id, self::PENDING, true );
		$pending = is_array( $pending ) ? $pending : array();
		$field   = str_starts_with( $key, 'resource_files' ) ? 'resource_files' : $key;
		if ( ! isset( $pending[ $field ] ) ) {
			$pending[ $field ] = array( 'old' => is_scalar( $old ) ? (string) $old : '' );
		}
		$pending[ $field ]['new'] = is_scalar( $new ) ? (string) $new : '';
		if ( in_array( $field, array( 'start_date', 'end_date' ), true ) && ! isset( $pending['_dates_before'] ) ) {
			$pending['_dates_before'] = Conferences::date_range( $edition_id );
		}
		update_post_meta( $edition_id, self::PENDING, $pending );

		wp_clear_scheduled_hook( self::HOOK, array( $edition_id ) );
		wp_schedule_single_event( time() + max( 1, (int) self::settings()['delay'] ) * MINUTE_IN_SECONDS, self::HOOK, array( $edition_id ) );
	}

	/** Plain-language lines describing what changed. */
	public static function describe( int $edition_id ): array {
		$pending = get_post_meta( $edition_id, self::PENDING, true );
		if ( ! is_array( $pending ) || ! $pending ) {
			return array();
		}
		$lines = array();
		if ( isset( $pending['start_date'] ) || isset( $pending['end_date'] ) ) {
			$now    = Conferences::date_range( $edition_id );
			$before = (string) ( $pending['_dates_before'] ?? '' );
			$lines[] = $before && $before !== $now
				? sprintf( __( 'Dates: %1$s (previously %2$s)', 'zp-vendor-portal' ), $now ? $now : __( 'to be announced', 'zp-vendor-portal' ), $before )
				: sprintf( __( 'Dates: %s', 'zp-vendor-portal' ), $now ? $now : __( 'to be announced', 'zp-vendor-portal' ) );
		}
		foreach ( array( 'venue', 'city' ) as $k ) {
			if ( isset( $pending[ $k ] ) ) {
				$new     = (string) get_post_meta( $edition_id, $k, true );
				$old     = (string) $pending[ $k ]['old'];
				$lines[] = self::FIELDS[ $k ] . ': ' . ( '' !== $new ? $new : __( 'to be announced', 'zp-vendor-portal' ) ) . ( '' !== $old && $old !== $new ? ' ' . sprintf( __( '(previously %s)', 'zp-vendor-portal' ), $old ) : '' );
			}
		}
		if ( isset( $pending['attendee_edit_deadline'] ) ) {
			$d       = (string) get_post_meta( $edition_id, 'attendee_edit_deadline', true );
			$lines[] = self::FIELDS['attendee_edit_deadline'] . ': ' . ( $d ? mysql2date( 'F j, Y g:i a', $d ) : __( 'the start of the conference', 'zp-vendor-portal' ) );
		}
		foreach ( array( 'hotel_info', 'decorator_info', 'electricity_info', 'floor_plan', 'resource_files' ) as $k ) {
			if ( isset( $pending[ $k ] ) ) {
				/* translators: %s: e.g. "Hotel information" */
				$lines[] = sprintf( __( '%s has been updated. See your vendor portal.', 'zp-vendor-portal' ), self::FIELDS[ $k ] );
			}
		}
		return $lines;
	}

	/** Email every active registration for the conference, then clear the pending list. */
	public static function send( $edition_id ): int {
		global $wpdb;
		$edition_id = (int) $edition_id;
		$lines      = self::describe( $edition_id );
		delete_post_meta( $edition_id, self::PENDING );
		wp_clear_scheduled_hook( self::HOOK, array( $edition_id ) );
		if ( ! $lines || 'publish' !== get_post_status( $edition_id ) || ! self::settings()['enabled'] ) {
			return 0;
		}
		$regs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE edition_id = %d AND status = 'active' AND payment_status NOT IN ('failed','refunded')", $edition_id ) );
		$list = '<ul>' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) ) . '</ul>';
		$sent = 0;
		foreach ( $regs as $reg ) {
			$body = '<p>' . Mailer::personalize( __( 'Hi {first_name},', 'zp-vendor-portal' ), $reg ) . '</p>'
				. '<p>' . Mailer::personalize( __( 'Some details for {conference} have changed:', 'zp-vendor-portal' ), $reg ) . '</p>'
				. $list
				. '<p>' . esc_html__( 'Your registration is not affected unless we contact you separately.', 'zp-vendor-portal' ) . '</p>'
				. '<p style="margin:24px 0"><a href="' . esc_url( Settings::page_url( 'dashboard' ) ) . '" style="background:#1d5c8c;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;display:inline-block">' . esc_html__( 'View conference information', 'zp-vendor-portal' ) . '</a></p>';
			if ( Mailer::send(
				Mailer::recipients( $reg ),
				wp_specialchars_decode( Mailer::personalize( __( 'Update: {conference}', 'zp-vendor-portal' ), $reg ) ),
				$body,
				array(),
				array( 'type' => 'change_notice', 'registration_id' => (int) $reg->id, 'entry_id' => (int) $reg->gf_entry_id, 'edition_id' => $edition_id )
			) ) {
				++$sent;
			}
		}
		update_post_meta( $edition_id, '_zpvp_last_change_notice', array( 'at' => current_time( 'mysql' ), 'sent' => $sent, 'lines' => $lines ) );
		return $sent;
	}

	/* ------------------------------------------------------------------ */
	/* Conference screen                                                   */
	/* ------------------------------------------------------------------ */

	public function meta_box(): void {
		add_meta_box( 'zpvp-change-notice', 'Change notice to vendors', array( $this, 'render_box' ), Conferences::EDITION, 'side' );
	}

	public function render_box( $post ): void {
		$id    = (int) $post->ID;
		$lines = self::describe( $id );
		$next  = wp_next_scheduled( self::HOOK, array( $id ) );
		$last  = get_post_meta( $id, '_zpvp_last_change_notice', true );
		if ( ! self::settings()['enabled'] ) {
			echo '<p>Automatic change notices are off (Settings → Emails). Use "Write a message" to tell vendors about changes.</p>';
			return;
		}
		if ( $lines ) {
			echo '<p><strong>Waiting to send' . ( $next ? ' at ' . esc_html( wp_date( 'g:i a', $next ) ) : '' ) . ':</strong></p><ul style="list-style:disc;padding-left:18px">';
			foreach ( $lines as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>';
			}
			echo '</ul>';
			foreach ( array( 'send' => array( 'Send now', 'button-primary' ), 'cancel' => array( "Don't send", '' ) ) as $do => $b ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=zpvp_change_notice&do=' . $do . '&edition=' . $id ), 'zpvp_change_notice_' . $id );
				echo '<a class="button ' . esc_attr( $b[1] ) . '" href="' . esc_url( $url ) . '">' . esc_html( $b[0] ) . '</a> ';
			}
		} else {
			echo '<p>When you change the dates, venue, hotel, decorator or electricity information, floor plan, documents or booth staff deadline of this published conference, its registered vendors are emailed ' . (int) self::settings()['delay'] . ' minutes after your last edit.</p>';
		}
		if ( is_array( $last ) && ! empty( $last['at'] ) ) {
			echo '<p class="description">Last notice: ' . esc_html( mysql2date( 'M j, Y g:i a', $last['at'] ) ) . ', ' . (int) $last['sent'] . ' registration(s).</p>';
		}
	}

	public function handle(): void {
		$id = absint( $_GET['edition'] ?? 0 );
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_change_notice_' . $id );
		if ( 'send' === sanitize_key( $_GET['do'] ?? '' ) ) {
			self::send( $id );
		} else {
			delete_post_meta( $id, self::PENDING );
			wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		}
		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $id ) );
		exit;
	}
}
