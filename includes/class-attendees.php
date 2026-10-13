<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Booth personnel for a registration. Imported from the form's "Booth
 * Personnel" list field, then maintained by the vendor (until the
 * conference's attendee edit deadline) and by staff (any time).
 */
final class Attendees {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_attendees';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			title varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'form',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id)
			) {$charset};"
		);

		self::backfill();
	}

	/** @return object[] */
	public static function for_registration( int $registration_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE registration_id = %d AND status = 'active' ORDER BY id ASC", $registration_id )
		);
	}

	public static function count( int $registration_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE registration_id = %d AND status = 'active'", $registration_id )
		);
	}

	public static function add( int $registration_id, array $data, string $source ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::table(),
			array(
				'registration_id' => $registration_id,
				'name'            => (string) ( $data['name'] ?? '' ),
				'title'           => (string) ( $data['title'] ?? '' ),
				'email'           => (string) ( $data['email'] ?? '' ),
				'status'          => 'active',
				'source'          => $source,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		do_action( 'zpvp_attendee_added', $id, $registration_id, $source );
		return $id;
	}

	/**
	 * Create attendees from the form's list field (first column = name,
	 * second = title). Only runs when the registration has none yet.
	 */
	public static function import_from_entry( int $registration_id, array $entry, string $field_id ): int {
		if ( '' === $field_id || self::count( $registration_id ) > 0 ) {
			return 0;
		}
		$rows = maybe_unserialize( rgar( $entry, $field_id ) );
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		$added = 0;
		foreach ( $rows as $row ) {
			$values = is_array( $row ) ? array_values( $row ) : array( $row );
			$name   = sanitize_text_field( (string) ( $values[0] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			self::add(
				$registration_id,
				array(
					'name'  => $name,
					'title' => sanitize_text_field( (string) ( $values[1] ?? '' ) ),
				),
				'form'
			);
			++$added;
		}
		return $added;
	}

	/** Import personnel for registrations recorded before attendees existed. */
	public static function backfill(): void {
		global $wpdb;
		if ( ! class_exists( '\GFAPI' ) ) {
			return;
		}
		$regs = $wpdb->get_results(
			'SELECT r.id, r.gf_form_id, r.gf_entry_id FROM ' . Registrations::table() . ' r
			LEFT JOIN ' . self::table() . " a ON a.registration_id = r.id
			WHERE a.id IS NULL AND r.status = 'active' AND r.type = 'individual' LIMIT 500"
		);
		foreach ( (array) $regs as $reg ) {
			$entry = \GFAPI::get_entry( (int) $reg->gf_entry_id );
			if ( is_wp_error( $entry ) ) {
				continue;
			}
			$map = Form_Map::for_form( (int) $reg->gf_form_id );
			self::import_from_entry( (int) $reg->id, $entry, (string) ( $map['personnel'] ?? '' ) );
		}
	}

	/**
	 * When the vendor loses the ability to edit: the conference's attendee
	 * edit deadline, or the start of the conference if none is set.
	 */
	public static function editable_until( object $reg ): ?string {
		if ( ! $reg->edition_id ) {
			return null;
		}
		$deadline = (string) get_post_meta( (int) $reg->edition_id, 'attendee_edit_deadline', true );
		if ( $deadline ) {
			return $deadline;
		}
		$start = (string) get_post_meta( (int) $reg->edition_id, 'start_date', true );
		if ( $start ) {
			$d = \DateTime::createFromFormat( 'Ymd', $start );
			return $d ? $d->format( 'Y-m-d 00:00:00' ) : null;
		}
		return null;
	}

	public static function vendor_can_edit( object $reg ): bool {
		$until = self::editable_until( $reg );
		return 'active' === $reg->status && $until && current_time( 'mysql' ) <= $until;
	}

	/**
	 * Apply an attendee form: updates and removals for existing rows, plus
	 * new rows. Only rows belonging to this registration are touched.
	 *
	 * @return array{added:int, updated:int, removed:int, errors:string[]}
	 */
	public static function save_from_request( int $registration_id, array $existing, array $new, string $source ): array {
		global $wpdb;
		$result = array( 'added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => array() );
		$own    = array_map( 'intval', wp_list_pluck( self::for_registration( $registration_id ), 'id' ) );
		$now    = current_time( 'mysql' );

		foreach ( $existing as $id => $row ) {
			$id = (int) $id;
			if ( ! in_array( $id, $own, true ) || ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['remove'] ) ) {
				$wpdb->update( self::table(), array( 'status' => 'removed', 'updated_at' => $now ), array( 'id' => $id ) );
				do_action( 'zpvp_attendee_removed', $id, $registration_id, $source );
				++$result['removed'];
				continue;
			}
			$clean = self::clean_row( $row, $result['errors'] );
			if ( null === $clean ) {
				continue;
			}
			$wpdb->update( self::table(), $clean + array( 'updated_at' => $now ), array( 'id' => $id ) );
			do_action( 'zpvp_attendee_updated', $id, $registration_id, $source );
			++$result['updated'];
		}

		foreach ( $new as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) wp_unslash( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$clean = self::clean_row( $row, $result['errors'] );
			if ( null !== $clean ) {
				self::add( $registration_id, $clean, $source );
				++$result['added'];
			}
		}
		return $result;
	}

	private static function clean_row( array $row, array &$errors ): ?array {
		$name      = sanitize_text_field( wp_unslash( (string) ( $row['name'] ?? '' ) ) );
		$title     = sanitize_text_field( wp_unslash( (string) ( $row['title'] ?? '' ) ) );
		$raw_email = trim( wp_unslash( (string) ( $row['email'] ?? '' ) ) );
		if ( '' === $name ) {
			$errors[] = __( 'Each attendee needs a name. Rows without a name were not saved.', 'zp-vendor-portal' );
			return null;
		}
		$email = '';
		if ( '' !== $raw_email ) {
			if ( is_email( $raw_email ) ) {
				$email = sanitize_email( $raw_email );
			} else {
				/* translators: %s: attendee name */
				$errors[] = sprintf( __( 'The email for %s doesn\'t look right, so it was left blank.', 'zp-vendor-portal' ), $name );
			}
		}
		return array( 'name' => $name, 'title' => $title, 'email' => $email );
	}

	/**
	 * Shared editable table for vendor and staff screens.
	 *
	 * @param object[] $attendees
	 */
	public static function render_fields( array $attendees, bool $editable, int $blank_rows = 3 ): void {
		?>
		<div class="zpvp-table-wrap">
		<table class="zpvp-table zpvp-attendees<?php echo is_admin() ? ' widefat' : ''; ?>">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'zp-vendor-portal' ); ?></th>
				<th><?php esc_html_e( 'Title', 'zp-vendor-portal' ); ?></th>
				<th><?php esc_html_e( 'Email', 'zp-vendor-portal' ); ?></th>
				<?php if ( $editable ) : ?><th><?php esc_html_e( 'Remove', 'zp-vendor-portal' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $attendees as $a ) : ?>
				<tr>
					<?php if ( $editable ) : ?>
						<td><input type="text" name="attendees[<?php echo (int) $a->id; ?>][name]" value="<?php echo esc_attr( $a->name ); ?>" required></td>
						<td><input type="text" name="attendees[<?php echo (int) $a->id; ?>][title]" value="<?php echo esc_attr( $a->title ); ?>"></td>
						<td><input type="email" name="attendees[<?php echo (int) $a->id; ?>][email]" value="<?php echo esc_attr( $a->email ); ?>"></td>
						<td><input type="checkbox" name="attendees[<?php echo (int) $a->id; ?>][remove]" value="1" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'zp-vendor-portal' ), $a->name ) ); ?>"></td>
					<?php else : ?>
						<td><?php echo esc_html( $a->name ); ?></td>
						<td><?php echo esc_html( $a->title ); ?></td>
						<td><?php echo esc_html( $a->email ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			<?php if ( $editable ) : ?>
				<?php for ( $i = 0; $i < $blank_rows; $i++ ) : ?>
					<tr class="zpvp-attendee-new">
						<td><input type="text" name="new[<?php echo (int) $i; ?>][name]" placeholder="<?php esc_attr_e( 'Add a person', 'zp-vendor-portal' ); ?>"></td>
						<td><input type="text" name="new[<?php echo (int) $i; ?>][title]"></td>
						<td><input type="email" name="new[<?php echo (int) $i; ?>][email]"></td>
						<td></td>
					</tr>
				<?php endfor; ?>
			<?php elseif ( ! $attendees ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No booth staff listed yet.', 'zp-vendor-portal' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
