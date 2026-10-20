<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Registration records: one row per company per conference, linked to the
 * Gravity Forms entry it came from. Stored in a custom table for fast
 * filtering, counting and exports.
 */
final class Registrations {

	public const PAYMENT_STATUSES = array(
		'paid'      => 'Paid',
		'pending'   => 'Card pending',
		'awaiting'  => 'Awaiting payment',
		'failed'    => 'Payment failed',
		'no_charge' => 'No charge',
		'refunded'  => 'Refunded',
	);

	public const PAYMENT_METHODS = array(
		'card'  => 'Credit card',
		'check' => 'Check',
		'po'    => 'Invoice / PO',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_registrations';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			edition_id bigint(20) unsigned NOT NULL DEFAULT 0,
			series_id bigint(20) unsigned NOT NULL DEFAULT 0,
			year smallint(4) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_name varchar(255) NOT NULL DEFAULT '',
			contact_name varchar(255) NOT NULL DEFAULT '',
			contact_email varchar(190) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'individual',
			booths smallint(5) unsigned NOT NULL DEFAULT 1,
			included_badges smallint(5) unsigned NOT NULL DEFAULT 0,
			paid_badges smallint(5) unsigned NOT NULL DEFAULT 0,
			booth_number varchar(50) NOT NULL DEFAULT '',
			item varchar(190) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0,
			payment_method varchar(20) NOT NULL DEFAULT '',
			po_number varchar(100) NOT NULL DEFAULT '',
			payment_status varchar(20) NOT NULL DEFAULT 'pending',
			status varchar(20) NOT NULL DEFAULT 'active',
			gf_form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			gf_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			annual_id bigint(20) unsigned NOT NULL DEFAULT 0,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_series (gf_entry_id,series_id),
			KEY edition_id (edition_id),
			KEY user_id (user_id),
			KEY company_id (company_id),
			KEY series_year (series_id,year),
			KEY payment_status (payment_status)
			) {$charset};"
		);

		// Legacy unique key (form + entry) from an early schema blocked annual-form entries
		// from saving one row per conference. entry_series (entry + series) is the real key.
		if ( $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'gf_entry'" ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX gf_entry" ); // phpcs:ignore WordPress.DB
		}
	}

	public const TYPES = array(
		'individual'  => 'Individual',
		'annual'      => 'Annual',
		'sponsorship' => 'Sponsorship',
	);

	public static function type_label( string $type ): string {
		return self::TYPES[ $type ] ?? ucfirst( $type );
	}

	/** "2 booths", or the sponsorship level, e.g. "Gold Sponsorship". */
	public static function summary( object $reg ): string {
		if ( 'sponsorship' === ( $reg->type ?? '' ) ) {
			return ! empty( $reg->item ) ? (string) $reg->item : __( 'Sponsorship', 'zp-vendor-portal' );
		}
		/* translators: %d: number of booths */
		return sprintf( _n( '%d booth', '%d booths', (int) $reg->booths, 'zp-vendor-portal' ), (int) $reg->booths );
	}

	/** Insert a registration; returns its ID (existing ID if the entry was already recorded). */
	public static function create( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = wp_parse_args(
			$data,
			array(
				'edition_id'     => 0,
				'series_id'      => 0,
				'year'           => 0,
				'user_id'        => 0,
				'company_name'   => '',
				'contact_name'   => '',
				'contact_email'  => '',
				'type'           => 'individual',
				'booths'         => 1,
				'included_badges' => 0,
				'paid_badges'    => 0,
				'amount'         => 0,
				'payment_method' => '',
				'po_number'      => '',
				'payment_status' => 'pending',
				'gf_form_id'     => 0,
				'gf_entry_id'    => 0,
			)
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE gf_entry_id = %d AND series_id = %d', $data['gf_entry_id'], $data['series_id'] )
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		do_action( 'zpvp_registration_created', $id, $data );
		return $id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$old = isset( $data['payment_status'] ) ? self::get( $id ) : null;
		$data['updated_at'] = current_time( 'mysql' );
		if ( false === $wpdb->update( self::table(), $data, array( 'id' => $id ) ) ) {
			return false;
		}
		if ( $old && $old->payment_status !== $data['payment_status'] ) {
			do_action( 'zpvp_payment_status_changed', $id, $old->payment_status, $data['payment_status'] );
		}
		return true;
	}

	public static function set_payment_status_for_entry( int $entry_id, string $status ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE gf_entry_id = %d', $entry_id ) );
		foreach ( (array) $ids as $id ) {
			self::update( (int) $id, array( 'payment_status' => $status ) );
		}
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ? $row : null;
	}

	/** @return object[] The vendor's registrations and their company's. */
	public static function for_user( int $user_id ): array {
		return Companies::registrations_for_user( $user_id );
	}

	public static function booths_taken( int $edition_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(booths),0) FROM ' . self::table() . " WHERE edition_id = %d AND status = 'active' AND payment_status NOT IN ('failed','refunded')", $edition_id )
		);
	}

	/**
	 * The conference edition a registration belongs to. With a year, the
	 * series' edition for that year; otherwise the open edition, or the next
	 * upcoming one.
	 */
	public static function edition_for( int $series_id, int $year = 0 ): int {
		$meta = array( array( 'key' => 'series', 'value' => $series_id ) );
		if ( $year ) {
			$meta[] = array( 'key' => 'year', 'value' => $year );
		}
		$ids = get_posts(
			array(
				'post_type'      => Conferences::EDITION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta,
			)
		);
		if ( $year ) {
			return (int) ( $ids[0] ?? 0 );
		}
		foreach ( $ids as $id ) {
			if ( 'open' === Conferences::status( (int) $id ) ) {
				return (int) $id;
			}
		}
		$best  = 0;
		$start = '';
		$today = current_time( 'Ymd' );
		foreach ( $ids as $id ) {
			$s = (string) get_post_meta( (int) $id, 'start_date', true );
			if ( $s && $s >= $today && ( ! $best || $s < $start ) ) {
				$best  = (int) $id;
				$start = $s;
			}
		}
		return $best;
	}

	/**
	 * WHERE clause for list, report and export filters: edition, payment,
	 * search, year and registration date range. $alias prefixes columns
	 * (e.g. "r.") when the registrations table is joined.
	 *
	 * @return array{0:string,1:array}
	 */
	public static function filter_sql( array $filters, string $alias = '' ): array {
		global $wpdb;
		$c     = $alias;
		$where = array( "{$c}status = 'active'" );
		$args  = array();

		if ( ! empty( $filters['edition'] ) ) {
			$where[] = "{$c}edition_id = %d";
			$args[]  = (int) $filters['edition'];
		}
		if ( ! empty( $filters['year'] ) ) {
			$where[] = "{$c}year = %d";
			$args[]  = (int) $filters['year'];
		}
		if ( ! empty( $filters['payment'] ) ) {
			if ( 'outstanding' === $filters['payment'] ) {
				$where[] = "{$c}payment_status IN ('awaiting','pending')";
			} elseif ( isset( self::PAYMENT_STATUSES[ $filters['payment'] ] ) ) {
				$where[] = "{$c}payment_status = %s";
				$args[]  = $filters['payment'];
			}
		}
		if ( ! empty( $filters['category'] ) ) {
			$companies = Companies::in_categories( (array) $filters['category'] );
			$where[]   = $companies ? "{$c}company_id IN (" . implode( ',', array_map( 'intval', $companies ) ) . ')' : '1=0';
		}
		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = "({$c}company_name LIKE %s OR {$c}contact_email LIKE %s OR {$c}contact_name LIKE %s)";
			array_push( $args, $like, $like, $like );
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = "{$c}created_at >= %s";
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = "{$c}created_at <= %s";
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		return array( 'WHERE ' . implode( ' AND ', $where ), $args );
	}

	/** Filters from the current request (list, reports and exports share them). */
	public static function request_filters(): array {
		$date = static function ( $key ) {
			$v = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ?? '' ) );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		return array(
			'edition' => absint( $_REQUEST['edition'] ?? 0 ),
			'year'    => absint( $_REQUEST['year'] ?? 0 ),
			'payment' => sanitize_key( $_REQUEST['payment'] ?? '' ),
			'category' => absint( $_REQUEST['category'] ?? 0 ),
			'search'  => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ),
			'from'    => $date( 'from' ),
			'to'      => $date( 'to' ),
		);
	}

	/** @return object[] */
	public static function export_rows( array $filters ): array {
		global $wpdb;
		[ $where, $args ] = self::filter_sql( $filters );
		$sql = 'SELECT * FROM ' . self::table() . " {$where} ORDER BY created_at DESC";
		return (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) );
	}

	public static function payment_label( string $status ): string {
		return self::PAYMENT_STATUSES[ $status ] ?? ucfirst( $status );
	}

	public static function method_label( string $method ): string {
		return self::PAYMENT_METHODS[ $method ] ?? '—';
	}

	/** Conference name for a registration, even when no edition is linked yet. */
	public static function conference_label( object $reg ): string {
		if ( $reg->edition_id && get_post( (int) $reg->edition_id ) ) {
			return get_the_title( (int) $reg->edition_id );
		}
		$series = $reg->series_id ? get_the_title( (int) $reg->series_id ) : 'Unknown conference';
		return trim( $series . ' ' . ( $reg->year ? $reg->year : '' ) );
	}
}
