<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * "Vendor Portal" menu in WP Admin, with a setup page for staff.
 */
final class Admin {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_zpvp_mark_paid', array( $this, 'mark_paid' ) );
		add_action( 'admin_post_zpvp_save_registration', array( $this, 'save_registration' ) );
		add_action( 'admin_post_zpvp_save_attendees', array( $this, 'save_attendees' ) );
		add_action( 'admin_post_zpvp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_zpvp_export_registrations', array( $this, 'export_registrations' ) );
		add_filter( 'admin_title', array( $this, 'hidden_page_title' ), 10, 2 );
		add_filter( 'parent_file', array( $this, 'series_parent' ) );
		add_filter( 'submenu_file', array( $this, 'series_submenu' ) );
	}

	/** Conference Series screens live under Vendor Portal → Settings in the menu. */
	public function series_parent( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && Conferences::SERIES === $screen->post_type ) ? 'zpvp' : $parent_file;
	}

	public function series_submenu( $submenu_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && Conferences::SERIES === $screen->post_type ) ? 'zpvp-settings' : $submenu_file;
	}

	/** Hidden admin pages (e.g. Edit Registration) have no menu entry, so give them a browser title. */
	public function hidden_page_title( $admin_title, $title ) {
		if ( 'zpvp-registration' === sanitize_key( $_GET['page'] ?? '' ) ) {
			return 'Edit Registration' . $admin_title;
		}
		return $admin_title;
	}

	public function menu(): void {
		$app = new Admin_App();
		add_menu_page( 'Vendor Portal', 'Vendor Portal', Roles::CAP_MANAGE, 'zpvp', array( $app, 'render' ), 'dashicons-groups', 26 );
		add_submenu_page( 'zpvp', 'Vendor Portal', 'Dashboard', Roles::CAP_MANAGE, 'zpvp', array( $app, 'render' ) );
		add_submenu_page( 'zpvp', 'Vendor Portal Settings', 'Settings', Roles::CAP_MANAGE, 'zpvp-settings', array( new Admin_Settings(), 'render' ) );
		// Classic screens stay reachable by link but are replaced by the dashboard in the menu.
		add_submenu_page( '', 'Registrations', 'Registrations', Roles::CAP_MANAGE, 'zpvp-registrations', array( $this, 'render_registrations' ) );
		add_submenu_page( '', 'Edit Registration', 'Edit Registration', Roles::CAP_MANAGE, 'zpvp-registration', array( $this, 'render_registration_edit' ) );
	}

	public function render_registration_edit(): void {
		$reg = Registrations::get( absint( $_GET['id'] ?? 0 ) );
		if ( ! $reg ) {
			echo '<div class="wrap"><h1>Registration not found</h1></div>';
			return;
		}
		$user   = $reg->user_id ? get_userdata( (int) $reg->user_id ) : null;
		$annual = ! empty( $reg->annual_id ) ? Annual_Registrations::get( (int) $reg->annual_id ) : null;
		?>
		<div class="wrap">
			<h1>Edit Registration</h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-registrations' ) ); ?>">&larr; All registrations</a></p>
			<?php if ( isset( $_GET['zpvp_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Registration saved.</p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px;margin-bottom:20px">
				<tbody>
					<tr><th style="width:200px">Company</th><td><?php echo esc_html( $reg->company_name ); ?></td></tr>
					<tr><th>Contact</th><td><?php echo esc_html( trim( $reg->contact_name . ' · ' . $reg->contact_email, ' ·' ) ); ?></td></tr>
					<tr><th>Vendor account</th><td>
						<?php if ( $user ) : ?>
							<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
						<?php else : ?>
							None linked
						<?php endif; ?>
					</td></tr>
					<tr><th>Conference</th><td><?php echo esc_html( Registrations::conference_label( $reg ) ); ?></td></tr>
					<tr><th>Type</th><td><?php echo esc_html( Registrations::type_label( (string) $reg->type ) ); ?></td></tr>
					<?php if ( $annual ) : ?>
						<tr><th>Annual pre-registration</th><td><?php echo esc_html( sprintf( '%s fee · %s · covers %d conference booking(s) from this form entry', '$' . number_format( (float) $annual->amount, 2 ), Registrations::payment_label( (string) $annual->payment_status ), Annual_Registrations::booking_count( (int) $annual->id ) ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( 'sponsorship' === $reg->type ) : ?><tr><th>Sponsorship</th><td><?php echo esc_html( Registrations::summary( $reg ) ); ?></td></tr><?php else : ?><tr><th>Booths</th><td><?php echo esc_html( (string) $reg->booths ); ?></td></tr><?php endif; ?>
					<tr><th>Amount</th><td><?php echo esc_html( '$' . number_format( (float) $reg->amount, 2 ) ); ?></td></tr>
					<tr><th>Payment method</th><td><?php echo esc_html( Registrations::method_label( $reg->payment_method ) . ( $reg->po_number ? ' · PO ' . $reg->po_number : '' ) ); ?></td></tr>
					<tr><th>Registered</th><td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $reg->created_at ) ); ?></td></tr>
					<tr><th>Document</th><td><a href="<?php echo esc_url( Documents::url( (int) $reg->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Documents::label( $reg ) . ' ' . Documents::number( $reg ) ); ?></a> <span class="description">Always generated from the current data.</span></td></tr>
					<tr><th>Form entry</th><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $reg->gf_form_id . '&lid=' . (int) $reg->gf_entry_id ) ); ?>">Form <?php echo (int) $reg->gf_form_id; ?>, entry <?php echo (int) $reg->gf_entry_id; ?></a></td></tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zpvp_save_registration">
				<input type="hidden" name="id" value="<?php echo (int) $reg->id; ?>">
				<?php wp_nonce_field( 'zpvp_save_registration_' . (int) $reg->id ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zpvp-booth-number">Booth number</label></th>
						<td><input type="text" id="zpvp-booth-number" name="booth_number" class="regular-text" value="<?php echo esc_attr( $reg->booth_number ); ?>">
						<p class="description">Shown to the vendor on My Conferences. Use commas for several booths, e.g. "14, 15".</p></td>
					</tr>
					<tr>
						<th scope="row">Badges</th>
						<td>
							<label>Included <input type="number" min="0" name="included_badges" value="<?php echo (int) $reg->included_badges; ?>" style="width:70px"></label>
							&nbsp; <label>Purchased extra <input type="number" min="0" name="paid_badges" value="<?php echo (int) $reg->paid_badges; ?>" style="width:70px"></label>
							<p class="description"><?php echo esc_html( Badges::summary( $reg ) ); ?> Leave "Included" at 0 to print every listed person without payment flags.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zpvp-payment">Payment status</label></th>
						<td><select id="zpvp-payment" name="payment_status">
							<?php foreach ( Registrations::PAYMENT_STATUSES as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $reg->payment_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<?php if ( $annual ) : ?>
					<tr>
						<th scope="row"><label for="zpvp-annual-payment">Annual fee payment</label></th>
						<td><select id="zpvp-annual-payment" name="annual_payment_status">
							<?php foreach ( Registrations::PAYMENT_STATUSES as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $annual->payment_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">The one-time <?php echo esc_html( '$' . number_format( (float) $annual->amount, 2 ) ); ?> pre-registration fee, shared by every conference booked on the same annual form. Its status is separate from this conference's booth payment.</p></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="zpvp-notes">Staff notes</label></th>
						<td><textarea id="zpvp-notes" name="notes" rows="5" class="large-text"><?php echo esc_textarea( (string) $reg->notes ); ?></textarea>
						<p class="description">Not visible to the vendor.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save Registration' ); ?>
			</form>

			<hr>
			<h2 id="attendees">Booth personnel</h2>
			<?php if ( isset( $_GET['zpvp_att_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Booth personnel saved.<?php echo isset( $_GET['zpvp_att_warn'] ) ? ' Some rows were missing a name or had an invalid email; please check them.' : ''; ?></p></div>
			<?php endif; ?>
			<?php
			$until = Attendees::editable_until( $reg );
			if ( $until ) {
				echo '<p class="description">' . esc_html( sprintf( 'Vendor can edit until %s%s. Staff can edit any time.', mysql2date( 'M j, Y g:i a', $until ), Attendees::vendor_can_edit( $reg ) ? '' : ' (closed)' ) ) . '</p>';
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
				<input type="hidden" name="action" value="zpvp_save_attendees">
				<input type="hidden" name="id" value="<?php echo (int) $reg->id; ?>">
				<?php wp_nonce_field( 'zpvp_save_attendees_' . (int) $reg->id ); ?>
				<?php Attendees::render_fields( Attendees::for_registration( (int) $reg->id ), true ); ?>
				<?php submit_button( 'Save Booth Personnel', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public function save_attendees(): void {
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id || ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_save_attendees_' . $id );
		$result = Attendees::save_from_request( $id, (array) ( $_POST['attendees'] ?? array() ), (array) ( $_POST['new'] ?? array() ), 'staff' );
		$args   = array( 'page' => 'zpvp-registration', 'id' => $id, 'zpvp_att_saved' => 1 );
		if ( $result['errors'] ) {
			$args['zpvp_att_warn'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#attendees' );
		exit;
	}

	public function save_registration(): void {
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id || ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_save_registration_' . $id );
		$reg = Registrations::get( $id );
		if ( ! $reg ) {
			wp_die( 'Registration not found.' );
		}

		$status = sanitize_key( $_POST['payment_status'] ?? $reg->payment_status );
		$status = isset( Registrations::PAYMENT_STATUSES[ $status ] ) ? $status : $reg->payment_status;
		$notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		if ( $status !== $reg->payment_status ) {
			$notes = trim( $notes . "\n" . sprintf( 'Payment status changed from %s to %s by %s on %s.', Registrations::payment_label( $reg->payment_status ), Registrations::payment_label( $status ), wp_get_current_user()->display_name, current_time( 'mysql' ) ) );
		}

		if ( ! empty( $reg->annual_id ) && isset( $_POST['annual_payment_status'] ) ) {
			$annual        = Annual_Registrations::get( (int) $reg->annual_id );
			$annual_status = sanitize_key( $_POST['annual_payment_status'] );
			if ( $annual && isset( Registrations::PAYMENT_STATUSES[ $annual_status ] ) && $annual_status !== $annual->payment_status ) {
				Annual_Registrations::update_status( (int) $annual->id, $annual_status );
				$notes = trim( $notes . "\n" . sprintf( 'Annual fee payment changed from %s to %s by %s on %s.', Registrations::payment_label( $annual->payment_status ), Registrations::payment_label( $annual_status ), wp_get_current_user()->display_name, current_time( 'mysql' ) ) );
			}
		}

		Registrations::update(
			$id,
			array(
				'booth_number'    => sanitize_text_field( wp_unslash( $_POST['booth_number'] ?? '' ) ),
				'included_badges' => absint( $_POST['included_badges'] ?? $reg->included_badges ),
				'paid_badges'     => absint( $_POST['paid_badges'] ?? $reg->paid_badges ),
				'payment_status'  => $status,
				'notes'           => $notes,
			)
		);
		Badges::sync_registration( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=zpvp-registration&id=' . $id . '&zpvp_saved=1' ) );
		exit;
	}

	public function export_registrations(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_export_registrations' );

		$rows = Registrations::export_rows( Registrations::request_filters() );
		Csv::download(
			'registrations-' . current_time( 'Y-m-d' ) . '.csv',
			array( 'Registration ID', 'Conference', 'Year', 'Company', 'Vendor categories', 'Contact', 'Email', 'Type', 'Booths', 'Booth #', 'Amount', 'Payment method', 'PO number', 'Payment status', 'Registered', 'Vendor account', 'Form ID', 'Entry ID' ),
			array_map(
				static fn( $r ) => array(
					$r->id,
					Registrations::conference_label( $r ),
					$r->year,
					$r->company_name,
					implode( '; ', Companies::category_names( (int) $r->company_id ) ),
					$r->contact_name,
					$r->contact_email,
					Registrations::type_label( (string) $r->type ),
					$r->booths,
					$r->booth_number,
					number_format( (float) $r->amount, 2, '.', '' ),
					Registrations::method_label( $r->payment_method ),
					$r->po_number,
					Registrations::payment_label( $r->payment_status ),
					$r->created_at,
					$r->user_id ? 'Yes' : 'No',
					$r->gf_form_id,
					$r->gf_entry_id,
				),
				$rows
			)
		);
	}



	public function render_registrations(): void {
		$table = new Registrations_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Registrations</h1>
			<?php if ( isset( $_GET['zpvp_paid'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Registration marked as paid.</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="zpvp-registrations">
				<?php $table->search_box( 'Search company or contact', 'zpvp-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function mark_paid(): void {
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! $id || ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_mark_paid_' . $id );
		$reg = Registrations::get( $id );
		if ( $reg ) {
			$user = wp_get_current_user();
			$note = trim( (string) $reg->notes . "\n" . sprintf( 'Marked paid by %s on %s.', $user->display_name, current_time( 'mysql' ) ) );
			Registrations::update( $id, array( 'payment_status' => 'paid', 'notes' => $note ) );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=zpvp-registrations' );
		wp_safe_redirect( add_query_arg( 'zpvp_paid', 1, $back ) );
		exit;
	}

	public function save_settings(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_save_settings' );
		update_option( Documents::OPT_ISSUER, sanitize_textarea_field( wp_unslash( $_POST['issuer'] ?? '' ) ), false );
		update_option( Documents::OPT_INSTRUCTIONS, sanitize_textarea_field( wp_unslash( $_POST['instructions'] ?? '' ) ), false );
		wp_safe_redirect( Admin_Settings::url( 'invoices', array( 'zpvp_settings_saved' => 1 ) ) );
		exit;
	}

}
