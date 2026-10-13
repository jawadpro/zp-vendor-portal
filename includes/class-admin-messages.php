<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor Portal → Messages: email the vendors registered for a conference
 * (for example when conference details change), automatic email settings
 * and the email log.
 */
final class Admin_Messages {

	public const ACTION = 'zpvp_send_message';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_zpvp_message', array( $this, 'handle' ) );
		add_action( 'admin_post_zpvp_email_settings', array( $this, 'save_settings' ) );
		add_action( 'add_meta_boxes_' . Conferences::EDITION, array( $this, 'meta_box' ) );
	}

	/** Background sending (Action Scheduler) runs outside admin, so it registers separately. */
	public static function register_worker(): void {
		add_action( self::ACTION, array( __CLASS__, 'send_one' ), 10, 2 );
	}

	public function menu(): void {
		add_submenu_page( '', 'Messages', 'Messages', Roles::CAP_MANAGE, 'zpvp-messages', array( $this, 'render' ) );
	}

	public function meta_box(): void {
		add_meta_box(
			'zpvp-message',
			'Email registered vendors',
			static function ( $post ) {
				$count = count( self::registrations( (int) $post->ID, 'all' ) );
				echo '<p>' . esc_html( sprintf( '%d registration(s) for this conference.', $count ) ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=zpvp&compose=1&edition=' . (int) $post->ID ) ) . '">Write a message</a></p>';
				echo '<p class="description">Use this when dates, venue, hotel or other details change.</p>';
			},
			Conferences::EDITION,
			'side'
		);
	}

	/** @return object[] */
	public static function registrations( int $edition_id, string $audience ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . Registrations::table() . " WHERE edition_id = %d AND status = 'active'";
		if ( 'paid' === $audience ) {
			$sql .= " AND payment_status IN ('paid','no_charge')";
		} elseif ( 'unpaid' === $audience ) {
			$sql .= " AND payment_status IN ('awaiting','pending','failed')";
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY company_name', $edition_id ) );
	}

	public function render(): void {
		global $wpdb;
		$s        = Mailer::settings();
		$edition  = absint( $_GET['edition'] ?? 0 );
		$editions = get_posts( array( 'post_type' => Conferences::EDITION, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$log      = $wpdb->get_results( 'SELECT * FROM ' . Mailer::table() . ' ORDER BY id DESC LIMIT 50' );
		$default  = $edition ? sprintf( "Hi {first_name},\n\nWe have an update about {conference} ({dates}):\n\n[Describe the change here.]\n\nYou can review your registration and booth personnel at {portal_link}\n\nThank you,\n%s", strtok( (string) get_option( Documents::OPT_ISSUER, get_bloginfo( 'name' ) ), "\n" ) ) : '';
		?>
		<div class="wrap">
			<h1>Messages</h1>
			<?php if ( isset( $_GET['zpvp_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['zpvp_msg'] ) ) ); ?></p></div>
			<?php endif; ?>

			<h2>Email vendors registered for a conference</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px">
				<input type="hidden" name="action" value="zpvp_message">
				<?php wp_nonce_field( 'zpvp_message' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="zpvp-m-edition">Conference</label></th><td>
						<select id="zpvp-m-edition" name="edition" required>
							<option value="">Choose a conference…</option>
							<?php foreach ( $editions as $e ) : ?>
								<option value="<?php echo (int) $e->ID; ?>" <?php selected( $edition, $e->ID ); ?>><?php echo esc_html( $e->post_title . ' (' . count( self::registrations( (int) $e->ID, 'all' ) ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th scope="row"><label for="zpvp-m-aud">Send to</label></th><td>
						<select id="zpvp-m-aud" name="audience">
							<option value="all">All registered vendors</option>
							<option value="paid">Paid only</option>
							<option value="unpaid">Payment outstanding only</option>
						</select></td></tr>
					<tr><th scope="row"><label for="zpvp-m-subject">Subject</label></th><td>
						<input id="zpvp-m-subject" name="subject" type="text" class="large-text" required value="<?php echo esc_attr( $edition ? 'Update: ' . get_the_title( $edition ) : '' ); ?>"></td></tr>
					<tr><th scope="row"><label for="zpvp-m-body">Message</label></th><td>
						<textarea id="zpvp-m-body" name="body" rows="12" class="large-text" required><?php echo esc_textarea( $default ); ?></textarea>
						<p class="description">Placeholders: {first_name} {contact_name} {company} {conference} {dates} {booths} {booth_number} {portal_link}. Each vendor gets their own copy; nobody sees other recipients.</p></td></tr>
				</table>
				<p>
					<button type="submit" name="mode" value="test" class="button">Send a test to me</button>
					<button type="submit" name="mode" value="send" class="button button-primary" onclick="return confirm('Send this message to every selected vendor?');">Send to vendors</button>
				</p>
			</form>

			<hr>
			<?php self::render_settings(); ?>
			<hr>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	public static function render_settings(): void {
		$s = Mailer::settings();
		?>
		<?php if ( isset( $_GET['zpvp_msg'] ) && 'zpvp-settings' === sanitize_key( $_GET['page'] ?? '' ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['zpvp_msg'] ) ) ); ?></p></div>
		<?php endif; ?>
			<h2>Automatic emails</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px">
				<input type="hidden" name="action" value="zpvp_email_settings">
				<?php wp_nonce_field( 'zpvp_email_settings' ); ?>
				<p><label><input type="checkbox" name="confirm_registration" value="1" <?php checked( $s['confirm_registration'] ); ?>> Registration confirmation with invoice attached</label><br>
				<span class="description">Leave off while the conference forms' own Gravity Forms notifications are still active, to avoid duplicate emails.</span></p>
				<p><label><input type="checkbox" name="confirm_payment" value="1" <?php checked( $s['confirm_payment'] ); ?>> Payment received, with receipt attached (card payments and staff "Mark paid")</label></p>
				<p><label><input type="checkbox" name="deadline_reminder" value="1" <?php checked( $s['deadline_reminder'] ); ?>> Booth personnel reminder</label>
				<input type="number" name="reminder_days" min="1" max="30" value="<?php echo (int) $s['reminder_days']; ?>" style="width:60px"> days before the attendee edit deadline</p>
				<p><label for="zpvp-reply">Reply-to address</label><br><input id="zpvp-reply" type="email" name="reply_to" class="regular-text" value="<?php echo esc_attr( $s['reply_to'] ); ?>" placeholder="e.g. conferences@zacpubs.com"></p>
				<?php submit_button( 'Save email settings', 'secondary', 'submit', false ); ?>
			</form>

		<?php
	}

	public static function render_log(): void {
		global $wpdb;
		$log = $wpdb->get_results( 'SELECT * FROM ' . Mailer::table() . ' ORDER BY id DESC LIMIT 50' );
		?>
			<h2>Recent emails</h2>
			<table class="widefat striped">
				<thead><tr><th>Sent</th><th>Type</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?><tr><td colspan="5">No emails sent yet.</td></tr><?php endif; ?>
				<?php foreach ( (array) $log as $l ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'M j, g:i a', $l->created_at ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $l->type ) ) ); ?></td>
						<td><?php echo esc_html( $l->recipient ); ?></td>
						<td><?php echo esc_html( $l->subject ); ?></td>
						<td><?php echo 'sent' === $l->status ? 'Sent' : '<strong style="color:#b42318">Failed</strong> ' . esc_html( (string) $l->error ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php
	}

	public function save_settings(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_email_settings' );
		update_option(
			Mailer::OPT_SETTINGS,
			array(
				'confirm_registration' => empty( $_POST['confirm_registration'] ) ? 0 : 1,
				'confirm_payment'      => empty( $_POST['confirm_payment'] ) ? 0 : 1,
				'deadline_reminder'    => empty( $_POST['deadline_reminder'] ) ? 0 : 1,
				'reminder_days'        => max( 1, min( 30, absint( $_POST['reminder_days'] ?? 3 ) ) ),
				'reply_to'             => sanitize_email( wp_unslash( $_POST['reply_to'] ?? '' ) ),
			),
			false
		);
		wp_safe_redirect( Admin_Settings::url( 'emails', array( 'zpvp_msg' => rawurlencode( 'Email settings saved.' ) ) ) );
		exit;
	}

	public function handle(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_message' );
		$edition  = absint( $_POST['edition'] ?? 0 );
		$audience = sanitize_key( $_POST['audience'] ?? 'all' );
		$subject  = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$body     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
		$regs     = self::registrations( $edition, $audience );
		$back     = admin_url( 'admin.php?page=zpvp-messages&edition=' . $edition );

		if ( ! $edition || '' === $subject || '' === $body ) {
			wp_safe_redirect( add_query_arg( 'zpvp_msg', rawurlencode( 'Please choose a conference and enter a subject and message.' ), $back ) );
			exit;
		}

		if ( 'test' === sanitize_key( $_POST['mode'] ?? '' ) ) {
			$sample = $regs ? $regs[0] : (object) array( 'id' => 0, 'user_id' => 0, 'contact_name' => 'Sample Contact', 'company_name' => 'Sample Company', 'edition_id' => $edition, 'series_id' => 0, 'year' => 0, 'booths' => 1, 'booth_number' => '', 'gf_entry_id' => 0 );
			Mailer::send( array( wp_get_current_user()->user_email ), '[TEST] ' . Mailer::personalize( $subject, $sample ), self::format( Mailer::personalize( $body, $sample ) ), array(), array( 'type' => 'message_test', 'edition_id' => $edition ) );
			wp_safe_redirect( add_query_arg( 'zpvp_msg', rawurlencode( 'Test sent to ' . wp_get_current_user()->user_email . '.' ), $back ) );
			exit;
		}

		$key = 'zpvp_msg_' . wp_generate_password( 10, false, false );
		update_option( $key, array( 'subject' => $subject, 'body' => $body, 'edition' => $edition ), false );

		$queued = 0;
		foreach ( $regs as $reg ) {
			if ( function_exists( 'as_enqueue_async_action' ) && count( $regs ) > 25 ) {
				as_enqueue_async_action( self::ACTION, array( $key, (int) $reg->id ), 'zpvp' );
			} else {
				self::send_one( $key, (int) $reg->id );
			}
			++$queued;
		}
		$note = count( $regs ) > 25 && function_exists( 'as_enqueue_async_action' ) ? ' They are being sent in the background over the next few minutes.' : '';
		wp_safe_redirect( add_query_arg( 'zpvp_msg', rawurlencode( sprintf( 'Message sent to %d registration(s).', $queued ) . $note ), $back ) );
		exit;
	}

	public static function send_one( $key, $registration_id ): void {
		$msg = get_option( (string) $key );
		$reg = Registrations::get( (int) $registration_id );
		if ( ! is_array( $msg ) || ! $reg ) {
			return;
		}
		Mailer::send(
			Mailer::recipients( $reg ),
			wp_specialchars_decode( Mailer::personalize( $msg['subject'], $reg ) ),
			self::format( Mailer::personalize( $msg['body'], $reg ) ),
			array(),
			array( 'type' => 'conference_message', 'registration_id' => (int) $reg->id, 'entry_id' => (int) $reg->gf_entry_id, 'edition_id' => (int) $msg['edition'] )
		);
	}

	/** Plain text (already escaped by personalize) to simple HTML with clickable links. */
	private static function format( string $text ): string {
		return make_clickable( wpautop( esc_html( wp_specialchars_decode( $text ) ) ) );
	}
}
