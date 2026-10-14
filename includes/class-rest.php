<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints (namespace zpvp/v1) behind the staff workspace. Every route
 * requires the zpvp_manage capability and the standard wp_rest nonce.
 */
final class Rest {

	public const NS = 'zpvp/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public static function can(): bool {
		return current_user_can( Roles::CAP_MANAGE );
	}

	public function routes(): void {
		$perm = array( __CLASS__, 'can' );
		$id   = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );

		register_rest_route( self::NS, '/summary', array( 'methods' => 'GET', 'callback' => array( $this, 'summary' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/registrations', array( 'methods' => 'GET', 'callback' => array( $this, 'registrations' ), 'permission_callback' => $perm ) );
		register_rest_route(
			self::NS,
			'/registrations/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( $this, 'registration' ), 'permission_callback' => $perm, 'args' => $id ),
				array( 'methods' => 'POST', 'callback' => array( $this, 'update_registration' ), 'permission_callback' => $perm, 'args' => $id ),
			)
		);
		register_rest_route( self::NS, '/registrations/(?P<id>\d+)/paid', array( 'methods' => 'POST', 'callback' => array( $this, 'mark_paid' ), 'permission_callback' => $perm, 'args' => $id ) );
		register_rest_route( self::NS, '/registrations/(?P<id>\d+)/booth', array( 'methods' => 'POST', 'callback' => array( $this, 'set_booth' ), 'permission_callback' => $perm, 'args' => $id ) );
		register_rest_route( self::NS, '/registrations/(?P<id>\d+)/attendees', array( 'methods' => 'POST', 'callback' => array( $this, 'save_attendees' ), 'permission_callback' => $perm, 'args' => $id ) );
		register_rest_route( self::NS, '/badges', array( 'methods' => 'GET', 'callback' => array( $this, 'badges' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/badges/bulk', array( 'methods' => 'POST', 'callback' => array( $this, 'badges_bulk' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/messages', array( 'methods' => 'POST', 'callback' => array( $this, 'message' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/messages/audience', array( 'methods' => 'GET', 'callback' => array( $this, 'audience' ), 'permission_callback' => $perm ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function filters( \WP_REST_Request $r ): array {
		return array(
			'edition' => absint( $r->get_param( 'edition' ) ),
			'year'    => 0,
			'payment' => sanitize_key( (string) $r->get_param( 'payment' ) ),
			'category' => absint( $r->get_param( 'category' ) ),
			'search'  => sanitize_text_field( (string) $r->get_param( 'search' ) ),
			'from'    => '',
			'to'      => '',
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'zpvp_not_found', 'Registration not found.', array( 'status' => 404 ) );
	}

	public static function row( object $r ): array {
		return array(
			'id'             => (int) $r->id,
			'company'        => (string) $r->company_name,
			'contact'        => (string) $r->contact_name,
			'email'          => (string) $r->contact_email,
			'conference'     => Registrations::conference_label( $r ),
			'edition_id'     => (int) $r->edition_id,
			'type'           => Registrations::type_label( (string) $r->type ),
			'item'           => (string) ( $r->item ?? '' ),
			'booths'         => (int) $r->booths,
			'booth_number'   => (string) $r->booth_number,
			'people'         => Attendees::count( (int) $r->id ),
			'amount'         => (float) $r->amount,
			'payment_status' => (string) $r->payment_status,
			'payment_label'  => Registrations::payment_label( $r->payment_status ),
			'method'         => Registrations::method_label( $r->payment_method ),
			'po'             => (string) $r->po_number,
			'created'        => mysql2date( 'M j, Y', $r->created_at ),
			'has_account'    => (bool) $r->user_id,
			'company_id'     => (int) ( $r->company_id ?? 0 ),
			'categories'     => Companies::category_names( (int) ( $r->company_id ?? 0 ) ),
		);
	}

	private static function detail( object $r ): array {
		$until = Attendees::editable_until( $r );
		return self::row( $r ) + array(
			'included_badges' => (int) $r->included_badges,
			'paid_badges'     => (int) $r->paid_badges,
			'notes'           => (string) $r->notes,
			'badge_summary'   => Badges::summary( $r ),
			'deadline'        => $until ? mysql2date( 'M j, Y g:i a', $until ) . ( Attendees::vendor_can_edit( $r ) ? '' : ' (closed)' ) : '',
			'attendees'       => array_map(
				static fn( $a ) => array( 'id' => (int) $a->id, 'name' => $a->name, 'title' => $a->title, 'email' => $a->email ),
				Attendees::for_registration( (int) $r->id )
			),
			'document_url'    => Documents::url( (int) $r->id ),
			'document_label'  => 'receipt' === Documents::kind( $r ) ? 'Receipt PDF' : 'Invoice PDF',
			'document_number' => Documents::number( $r ),
			'entry_url'       => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $r->gf_form_id . '&lid=' . (int) $r->gf_entry_id ),
			'vendor_url'      => $r->user_id ? get_edit_user_link( (int) $r->user_id ) : '',
			'company_url'     => ! empty( $r->company_id ) ? get_edit_post_link( (int) $r->company_id, 'raw' ) : '',
			'badge_orders'    => count( Badge_Orders::for_registration( (int) $r->id ) ),
			'badges_owed'     => Documents::owed_badges( $r ),
			'badge_invoice_url' => Documents::badge_invoice_url( (int) $r->id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Endpoints                                                           */
	/* ------------------------------------------------------------------ */

	public function summary( \WP_REST_Request $req ) {
		global $wpdb;
		$f    = self::filters( $req );
		$rows = Admin_Reports::summary( array_merge( $f, array( 'payment' => '', 'search' => '' ) ) );
		$t    = array( 'registrations' => 0, 'booths' => 0, 'paid' => 0.0, 'outstanding' => 0.0 );
		foreach ( $rows as $r ) {
			$t['registrations'] += (int) $r->registrations;
			$t['booths']        += (int) $r->booths;
			$t['paid']          += (float) $r->paid;
			$t['outstanding']   += (float) $r->outstanding;
		}
		$bt    = Badges::table();
		$where = $f['edition'] ? $wpdb->prepare( ' AND edition_id = %d', $f['edition'] ) : '';
		return array(
			'registrations' => $t['registrations'],
			'booths'        => $t['booths'],
			'capacity'      => $f['edition'] ? (int) get_post_meta( $f['edition'], 'booth_capacity', true ) : 0,
			'status'        => $f['edition'] ? Conferences::status_label( Conferences::status( $f['edition'] ) ) : '',
			'paid'          => $t['paid'],
			'outstanding'   => $t['outstanding'],
			'badges_queued' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} WHERE status = 'queued'{$where}" ), // phpcs:ignore -- $where prepared above.
			'badges_owed'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} WHERE status <> 'void' AND needs_payment = 1{$where}" ), // phpcs:ignore
		);
	}

	public function registrations( \WP_REST_Request $req ) {
		global $wpdb;
		$f        = self::filters( $req );
		$page     = max( 1, absint( $req->get_param( 'page' ) ) );
		$per_page = 50;
		[ $where, $args ] = Registrations::filter_sql( $f );
		$table = Registrations::table();
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );

		$counts = array();
		foreach ( array( '', 'outstanding', 'paid', 'failed' ) as $p ) {
			[ $w, $a ] = Registrations::filter_sql( array_merge( $f, array( 'payment' => $p, 'search' => '' ) ) );
			$counts[ '' === $p ? 'all' : $p ] = (int) ( $a ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$w}", $a ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$w}" ) );
		}

		return array(
			'rows'   => array_map( array( __CLASS__, 'row' ), $rows ),
			'total'  => $total,
			'pages'  => (int) ceil( $total / $per_page ),
			'page'   => $page,
			'counts' => $counts,
		);
	}

	public function registration( \WP_REST_Request $req ) {
		$r = Registrations::get( (int) $req['id'] );
		return $r ? self::detail( $r ) : self::not_found();
	}

	public function update_registration( \WP_REST_Request $req ) {
		$r = Registrations::get( (int) $req['id'] );
		if ( ! $r ) {
			return self::not_found();
		}
		$status = sanitize_key( (string) $req->get_param( 'payment_status' ) );
		$status = isset( Registrations::PAYMENT_STATUSES[ $status ] ) ? $status : $r->payment_status;
		$notes  = sanitize_textarea_field( (string) $req->get_param( 'notes' ) );
		if ( $status !== $r->payment_status ) {
			$notes = trim( $notes . "\n" . sprintf( 'Payment status changed from %s to %s by %s on %s.', Registrations::payment_label( $r->payment_status ), Registrations::payment_label( $status ), wp_get_current_user()->display_name, current_time( 'mysql' ) ) );
		}
		Registrations::update(
			(int) $r->id,
			array(
				'booth_number'    => sanitize_text_field( (string) $req->get_param( 'booth_number' ) ),
				'included_badges' => absint( $req->get_param( 'included_badges' ) ),
				'paid_badges'     => absint( $req->get_param( 'paid_badges' ) ),
				'payment_status'  => $status,
				'notes'           => $notes,
			)
		);
		Badges::sync_registration( (int) $r->id );
		return self::detail( Registrations::get( (int) $r->id ) );
	}

	public function mark_paid( \WP_REST_Request $req ) {
		$r = Registrations::get( (int) $req['id'] );
		if ( ! $r ) {
			return self::not_found();
		}
		if ( 'paid' !== $r->payment_status ) {
			$note = trim( (string) $r->notes . "\n" . sprintf( 'Marked paid by %s on %s.', wp_get_current_user()->display_name, current_time( 'mysql' ) ) );
			Registrations::update( (int) $r->id, array( 'payment_status' => 'paid', 'notes' => $note ) );
		}
		return self::row( Registrations::get( (int) $r->id ) );
	}

	public function set_booth( \WP_REST_Request $req ) {
		$r = Registrations::get( (int) $req['id'] );
		if ( ! $r ) {
			return self::not_found();
		}
		Registrations::update( (int) $r->id, array( 'booth_number' => sanitize_text_field( (string) $req->get_param( 'booth_number' ) ) ) );
		return self::row( Registrations::get( (int) $r->id ) );
	}

	/**
	 * Replace the booth staff list: rows with an id are updated, rows without
	 * an id are added, and existing people missing from the list are removed.
	 */
	public function save_attendees( \WP_REST_Request $req ) {
		$r = Registrations::get( (int) $req['id'] );
		if ( ! $r ) {
			return self::not_found();
		}
		$incoming = (array) $req->get_param( 'attendees' );
		$existing = array();
		$new      = array();
		$seen     = array();
		foreach ( $incoming as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'title' => (string) ( $row['title'] ?? '' ),
				'email' => (string) ( $row['email'] ?? '' ),
			);
			if ( ! empty( $row['id'] ) ) {
				$existing[ (int) $row['id'] ] = $clean;
				$seen[]                       = (int) $row['id'];
			} elseif ( '' !== trim( $clean['name'] ) ) {
				$new[] = $clean;
			}
		}
		foreach ( Attendees::for_registration( (int) $r->id ) as $a ) {
			if ( ! in_array( (int) $a->id, $seen, true ) ) {
				$existing[ (int) $a->id ] = array( 'remove' => 1 );
			}
		}
		$result = Attendees::save_from_request( (int) $r->id, $existing, $new, 'staff' );
		return array(
			'errors'       => array_values( array_unique( $result['errors'] ) ),
			'registration' => self::detail( Registrations::get( (int) $r->id ) ),
		);
	}

	public function badges( \WP_REST_Request $req ) {
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$filter = array(
			'edition'       => absint( $req->get_param( 'edition' ) ),
			'status'        => in_array( $status, array( 'queued', 'printed', 'void', 'all' ), true ) ? $status : '',
			'needs_payment' => 'owed' === $status,
		);
		$rows = array_map(
			static fn( $b ) => array(
				'id'              => (int) $b->id,
				'registration_id' => (int) $b->registration_id,
				'name'            => $b->name,
				'title'           => $b->title,
				'company'         => $b->company,
				'conference'      => $b->edition_id ? get_the_title( (int) $b->edition_id ) : '',
				'type'            => Badges::TYPES[ $b->type ] ?? $b->type,
				'status'          => $b->status,
				'status_label'    => Badges::STATUSES[ $b->status ] ?? $b->status,
				'reprint'         => 'queued' === $b->status && (int) $b->print_count > 0,
				'needs_payment'   => (int) $b->needs_payment && 'void' !== $b->status,
				'printed'         => $b->printed_at ? mysql2date( 'M j, g:i a', $b->printed_at ) . ' (' . (int) $b->print_count . '×)' : '',
			),
			Badges::query( $filter )
		);
		return array( 'rows' => $rows );
	}

	public function badges_bulk( \WP_REST_Request $req ) {
		$ids    = array_map( 'intval', (array) $req->get_param( 'ids' ) );
		$action = sanitize_key( (string) $req->get_param( 'action' ) );
		if ( 'mark_printed' === $action ) {
			$done = Badges::mark_printed( $ids );
		} elseif ( in_array( $action, array( 'queued', 'void' ), true ) ) {
			$done = Badges::set_status( $ids, $action );
		} else {
			return new \WP_Error( 'zpvp_bad_action', 'Unknown action.', array( 'status' => 400 ) );
		}
		return array( 'updated' => $done );
	}

	public function audience( \WP_REST_Request $req ) {
		$edition = absint( $req->get_param( 'edition' ) );
		$targets = Admin_Messages::targets( $edition, sanitize_key( (string) $req->get_param( 'audience' ) ), absint( $req->get_param( 'category' ) ) );
		return array( 'count' => count( $targets ), 'label' => Admin_Messages::audience_label( $targets, $edition ) );
	}

	public function message( \WP_REST_Request $req ) {
		$edition  = absint( $req->get_param( 'edition' ) );
		$audience = sanitize_key( (string) $req->get_param( 'audience' ) );
		$subject  = sanitize_text_field( (string) $req->get_param( 'subject' ) );
		$body     = sanitize_textarea_field( (string) $req->get_param( 'body' ) );
		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'zpvp_incomplete', 'Enter a subject and message.', array( 'status' => 400 ) );
		}
		$regs = Admin_Messages::targets( $edition, $audience, absint( $req->get_param( 'category' ) ) );
		if ( ! $regs ) {
			return new \WP_Error( 'zpvp_nobody', 'Nobody matches these choices, so nothing was sent.', array( 'status' => 400 ) );
		}

		if ( 'test' === sanitize_key( (string) $req->get_param( 'mode' ) ) ) {
			$sample = Admin_Messages::target_object( $regs[0], $edition ) ?? (object) array( 'id' => 0, 'user_id' => 0, 'contact_name' => 'Sample Contact', 'company_name' => 'Sample Company', 'edition_id' => $edition, 'series_id' => 0, 'year' => 0, 'booths' => 1, 'booth_number' => '', 'gf_entry_id' => 0 );
			$to     = wp_get_current_user()->user_email;
			Mailer::send( array( $to ), '[TEST] ' . wp_specialchars_decode( Mailer::personalize( $subject, $sample ) ), make_clickable( wpautop( esc_html( wp_specialchars_decode( Mailer::personalize( $body, $sample ) ) ) ) ), array(), array( 'type' => 'message_test', 'edition_id' => $edition ) );
			return array( 'message' => 'Test sent to ' . $to . '.' );
		}

		$key = 'zpvp_msg_' . wp_generate_password( 10, false, false );
		update_option( $key, array( 'subject' => $subject, 'body' => $body, 'edition' => $edition ), false );
		$background = function_exists( 'as_enqueue_async_action' ) && count( $regs ) > 25;
		foreach ( $regs as $target ) {
			if ( $background ) {
				as_enqueue_async_action( Admin_Messages::ACTION, array( $key, $target ), 'zpvp' );
			} else {
				Admin_Messages::send_one( $key, $target );
			}
		}
		$noun = str_starts_with( $regs[0], 'c' ) ? 'compan' . ( 1 === count( $regs ) ? 'y' : 'ies' ) : 'registration(s)';
		return array( 'message' => sprintf( 'Message sent to %d %s.', count( $regs ), $noun ) . ( $background ? ' Sending continues in the background.' : '' ) );
	}
}
