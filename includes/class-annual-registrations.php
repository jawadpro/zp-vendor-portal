<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Annual pre-registration fees. One row per annual form entry holds the
 * one-time fee (e.g. $500) and its own payment status. The conference
 * bookings from the same entry are registrations linked by annual_id.
 */
final class Annual_Registrations {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_annual_registrations';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			year smallint(4) unsigned NOT NULL DEFAULT 0,
			gf_form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			gf_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(10,2) NOT NULL DEFAULT 0,
			payment_status varchar(20) NOT NULL DEFAULT 'awaiting',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gf_entry (gf_form_id,gf_entry_id),
			KEY company_year (company_id,year)
			) {$charset};"
		);
	}

	/** Insert the fee record for an annual form entry; returns its ID (the existing one if already recorded). */
	public static function record( array $data ): int {
		global $wpdb;
		$data = wp_parse_args(
			array_intersect_key( $data, array_flip( array( 'company_id', 'year', 'gf_form_id', 'gf_entry_id', 'amount', 'payment_status' ) ) ),
			array(
				'company_id'     => 0,
				'year'           => 0,
				'gf_form_id'     => 0,
				'gf_entry_id'    => 0,
				'amount'         => 0,
				'payment_status' => 'awaiting',
			)
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE gf_form_id = %d AND gf_entry_id = %d', $data['gf_form_id'], $data['gf_entry_id'] )
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ? $row : null;
	}

	public static function update_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'payment_status' => $status ), array( 'id' => $id ) );
	}

	/** Card payment events update the fee along with the bookings from the same entry. */
	public static function set_status_for_entry( int $entry_id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'payment_status' => $status ), array( 'gf_entry_id' => $entry_id ) );
	}

	/** Number of active conference bookings linked to this fee. */
	public static function booking_count( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Registrations::table() . " WHERE annual_id = %d AND status = 'active'", $id )
		);
	}
}