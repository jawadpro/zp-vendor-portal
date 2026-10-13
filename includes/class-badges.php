<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * One badge per active attendee, kept in sync automatically:
 * - Type: standard (within the included badges), additional (beyond them)
 *   or onsite (created or changed once the conference has started).
 * - Flagged "needs payment" when beyond included + purchased badges.
 * - Status: queued (ready to print), printed, void (attendee removed).
 *   A printed badge whose name, title or company changes goes back to the
 *   queue for a reprint.
 */
final class Badges {

	public const TYPES = array(
		'standard'   => 'Standard',
		'additional' => 'Additional',
		'onsite'     => 'On-site change',
	);

	public const STATUSES = array(
		'queued'  => 'Ready to print',
		'printed' => 'Printed',
		'void'    => 'Void',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_badges';
	}

	public function register(): void {
		add_action( 'zpvp_attendee_added', array( $this, 'on_attendee_change' ), 10, 2 );
		add_action( 'zpvp_attendee_updated', array( $this, 'on_attendee_change' ), 10, 2 );
		add_action( 'zpvp_attendee_removed', array( $this, 'on_attendee_change' ), 10, 2 );
	}

	public function on_attendee_change( $attendee_id, $registration_id ): void {
		self::sync_registration( (int) $registration_id );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attendee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			edition_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT 'standard',
			name varchar(190) NOT NULL DEFAULT '',
			title varchar(190) NOT NULL DEFAULT '',
			company varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'queued',
			needs_payment tinyint(1) NOT NULL DEFAULT 0,
			print_count smallint(5) unsigned NOT NULL DEFAULT 0,
			printed_at datetime NULL,
			printed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY attendee_id (attendee_id),
			KEY registration_id (registration_id),
			KEY edition_status (edition_id,status)
			) {$charset};"
		);

		global $wpdb;
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . Registrations::table() . " WHERE status = 'active'" ) as $rid ) {
			self::sync_registration( (int) $rid );
		}
	}

	/** Has the conference for this registration started? */
	private static function event_started( object $reg ): bool {
		if ( ! $reg->edition_id ) {
			return false;
		}
		$start = (string) get_post_meta( (int) $reg->edition_id, 'start_date', true );
		return $start && current_time( 'Ymd' ) >= $start;
	}

	public static function sync_registration( int $registration_id ): void {
		global $wpdb;
		$reg = Registrations::get( $registration_id );
		if ( ! $reg ) {
			return;
		}
		$now       = current_time( 'mysql' );
		$attendees = Attendees::for_registration( $registration_id );
		$active    = array_map( static fn( $a ) => (int) $a->id, $attendees );

		$existing = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE registration_id = %d AND status <> 'void'", $registration_id ) ) as $b ) {
			if ( ! in_array( (int) $b->attendee_id, $active, true ) || 'active' !== $reg->status ) {
				$wpdb->update( self::table(), array( 'status' => 'void', 'needs_payment' => 0, 'updated_at' => $now ), array( 'id' => $b->id ) );
				continue;
			}
			$existing[ (int) $b->attendee_id ] = $b;
		}
		if ( 'active' !== $reg->status ) {
			return;
		}

		$included = (int) $reg->included_badges;
		$paid     = (int) $reg->paid_badges;
		$onsite   = self::event_started( $reg );

		foreach ( array_values( $attendees ) as $i => $a ) {
			$type  = ( $included <= 0 || $i < $included ) ? 'standard' : 'additional';
			$needs = ( $included > 0 && $i >= $included + $paid ) ? 1 : 0;
			$snap  = array(
				'name'    => $a->name,
				'title'   => $a->title,
				'company' => $reg->company_name,
			);

			if ( ! isset( $existing[ (int) $a->id ] ) ) {
				$wpdb->insert(
					self::table(),
					$snap + array(
						'attendee_id'     => (int) $a->id,
						'registration_id' => $registration_id,
						'edition_id'      => (int) $reg->edition_id,
						'type'            => $onsite ? 'onsite' : $type,
						'status'          => 'queued',
						'needs_payment'   => $needs,
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				);
				continue;
			}

			$b       = $existing[ (int) $a->id ];
			$changed = $b->name !== $snap['name'] || $b->title !== $snap['title'] || $b->company !== $snap['company'];
			$data    = array(
				'edition_id'    => (int) $reg->edition_id,
				'needs_payment' => $needs,
				'type'          => ( 'onsite' === $b->type || ( $changed && $onsite ) ) ? 'onsite' : $type,
			);
			if ( $changed ) {
				$data = $snap + $data;
				if ( 'printed' === $b->status ) {
					$data['status'] = 'queued';
				}
			}
			$data['updated_at'] = $now;
			$wpdb->update( self::table(), $data, array( 'id' => $b->id ) );
		}
	}

	/** @return object[] */
	public static function for_registration( int $registration_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE registration_id = %d AND status <> 'void' ORDER BY id ASC", $registration_id )
		);
	}

	/** @return object[] */
	public static function query( array $filters ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['edition'] ) ) {
			$where[] = 'edition_id = %d';
			$args[]  = (int) $filters['edition'];
		}
		if ( ! empty( $filters['status'] ) && 'all' !== $filters['status'] ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		} elseif ( empty( $filters['status'] ) ) {
			$where[] = "status <> 'void'";
		}
		if ( ! empty( $filters['needs_payment'] ) ) {
			$where[] = 'needs_payment = 1';
		}
		if ( ! empty( $filters['ids'] ) ) {
			$ids     = array_map( 'intval', (array) $filters['ids'] );
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args    = array_merge( $args, $ids );
		}
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY company ASC, name ASC LIMIT 2000';
		return (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) );
	}

	public static function mark_printed( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', $ids );
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'printed', print_count = print_count + 1, printed_at = %s, printed_by = %d, updated_at = %s WHERE id IN ({$in}) AND status <> 'void'", // phpcs:ignore -- $in is a list of ints.
				current_time( 'mysql' ),
				get_current_user_id(),
				current_time( 'mysql' )
			)
		);
	}

	public static function set_status( array $ids, string $status ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids || ! isset( self::STATUSES[ $status ] ) ) {
			return 0;
		}
		$in = implode( ',', $ids );
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . self::table() . " SET status = %s, updated_at = %s WHERE id IN ({$in})", $status, current_time( 'mysql' ) ) // phpcs:ignore -- $in is a list of ints.
		);
	}

	/** Plain-language badge summary for a registration (vendor and staff screens). */
	public static function summary( object $reg ): string {
		$badges   = self::for_registration( (int) $reg->id );
		$count    = count( $badges );
		$included = (int) $reg->included_badges;
		$paid     = (int) $reg->paid_badges;
		$owed     = count( array_filter( $badges, static fn( $b ) => (int) $b->needs_payment ) );

		if ( $included <= 0 ) {
			/* translators: %d: number of badges */
			return sprintf( _n( '%d badge will be printed.', '%d badges will be printed.', $count, 'zp-vendor-portal' ), $count );
		}
		$text = sprintf(
			/* translators: 1: included badges, 2: purchased badges, 3: people listed */
			__( 'Your registration includes %1$d badges, plus %2$d additional purchased. %3$d people are listed.', 'zp-vendor-portal' ),
			$included,
			$paid,
			$count
		);
		if ( $owed ) {
			/* translators: %d: number of badges */
			$text .= ' ' . sprintf( _n( '%d additional badge will be invoiced by ZacPubs.', '%d additional badges will be invoiced by ZacPubs.', $owed, 'zp-vendor-portal' ), $owed );
		}
		return $text;
	}
}
