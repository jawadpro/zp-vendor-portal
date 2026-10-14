<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor account page (/my-conferences/?view=account): contact details and
 * password change.
 */
final class Account {

	private static array $errors = array();

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 20 );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'view' => 'account' ), $args ), Settings::page_url( 'dashboard' ) );
	}

	public function handle(): void {
		if ( ! Settings::is_page( 'dashboard' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$action = sanitize_key( $_POST['zpvp_action'] ?? '' );
		if ( ! in_array( $action, array( 'account_details', 'account_password' ), true ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zpvp_nonce'] ?? '' ) ), 'zpvp_' . $action ) ) {
			self::$errors[] = __( 'Your session expired. Please try again.', 'zp-vendor-portal' );
			return;
		}
		$user = wp_get_current_user();

		if ( 'account_details' === $action ) {
			$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$company = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
			if ( '' === $first || '' === $company ) {
				self::$errors[] = __( 'First name and company name are required.', 'zp-vendor-portal' );
				return;
			}
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ),
				)
			);
			update_user_meta( $user->ID, 'zpvp_company_name', $company );
			update_user_meta( $user->ID, 'zpvp_job_title', sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) ) );
			update_user_meta( $user->ID, 'zpvp_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
			wp_safe_redirect( self::url( array( 'saved' => 'details' ) ) );
			exit;
		}

		$current = (string) wp_unslash( $_POST['current_password'] ?? '' );
		$new     = (string) wp_unslash( $_POST['new_password'] ?? '' );
		$confirm = (string) wp_unslash( $_POST['confirm_password'] ?? '' );
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			self::$errors[] = __( 'Your current password is not correct.', 'zp-vendor-portal' );
		} elseif ( strlen( $new ) < 10 ) {
			self::$errors[] = __( 'The new password must be at least 10 characters long.', 'zp-vendor-portal' );
		} elseif ( $new !== $confirm ) {
			self::$errors[] = __( 'The new passwords do not match.', 'zp-vendor-portal' );
		}
		if ( self::$errors ) {
			return;
		}
		wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $new ) ); // Keeps the vendor logged in.
		wp_safe_redirect( self::url( array( 'saved' => 'password' ) ) );
		exit;
	}

	private static function nonce( string $action ): string {
		return '<input type="hidden" name="zpvp_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_zpvp_nonce" value="' . esc_attr( wp_create_nonce( 'zpvp_' . $action ) ) . '">';
	}

	/** The vendor's company: name, categories and the colleagues who share its registrations. */
	private static function company_panel( int $user_id ): string {
		$cid = Companies::company_for_user( $user_id );
		if ( ! $cid ) {
			return '';
		}
		$primary  = Companies::primary_contact( $cid );
		$contacts = Companies::contacts( $cid );
		$cats     = Companies::category_names( $cid );
		$out      = '<section class="zpvp-panel zpvp-company"><h2>' . esc_html__( 'Your company', 'zp-vendor-portal' ) . '</h2>';
		$out     .= '<p><strong>' . esc_html( get_post_field( 'post_title', $cid ) ) . '</strong>' . ( $cats ? '<br>' . esc_html( implode( ', ', $cats ) ) : '' ) . '</p>';
		$out     .= '<p>' . esc_html__( 'Everyone listed here can see and manage your company\'s conference registrations, booth staff, invoices and badges, and receives conference emails.', 'zp-vendor-portal' ) . '</p><ul class="zpvp-company__people">';
		foreach ( $contacts as $u ) {
			$out .= '<li>' . esc_html( $u->display_name . ' (' . $u->user_email . ')' . ( (int) $u->ID === $primary ? ' – ' . __( 'primary contact', 'zp-vendor-portal' ) : '' ) ) . '</li>';
		}
		$out .= '</ul><p class="zpvp-help">' . esc_html__( 'To add or remove a colleague, contact ZacPubs. Colleagues need their own vendor account first.', 'zp-vendor-portal' ) . '</p></section>';
		return $out;
	}

	public static function render(): string {
		$user  = wp_get_current_user();
		$saved = sanitize_key( $_GET['saved'] ?? '' );
		$v     = static fn( $key ) => esc_attr( (string) get_user_meta( $user->ID, $key, true ) );
		ob_start();
		?>
		<div class="zpvp-page-head">
			<h1><?php esc_html_e( 'Account', 'zp-vendor-portal' ); ?></h1>
			<p><?php esc_html_e( 'These details fill in your conference registration forms automatically.', 'zp-vendor-portal' ); ?></p>
		</div>
		<?php if ( 'details' === $saved && ! self::$errors ) : ?>
			<div class="zpvp-notice zpvp-notice--success" role="status"><?php esc_html_e( 'Details saved.', 'zp-vendor-portal' ); ?></div>
		<?php elseif ( 'password' === $saved && ! self::$errors ) : ?>
			<div class="zpvp-notice zpvp-notice--success" role="status"><?php esc_html_e( 'Password changed.', 'zp-vendor-portal' ); ?></div>
		<?php endif; ?>
		<?php foreach ( self::$errors as $e ) : ?>
			<div class="zpvp-notice zpvp-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
		<?php endforeach; ?>

		<div class="zpvp-account">
			<form method="post" class="zpvp-panel zpvp-form">
				<h2><?php esc_html_e( 'Your details', 'zp-vendor-portal' ); ?></h2>
				<?php echo self::nonce( 'account_details' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="zpvp-grid-2">
					<p><label for="zpvp-first"><?php esc_html_e( 'First name', 'zp-vendor-portal' ); ?></label><input id="zpvp-first" name="first_name" type="text" autocomplete="given-name" required value="<?php echo esc_attr( $user->first_name ); ?>"></p>
					<p><label for="zpvp-last"><?php esc_html_e( 'Last name', 'zp-vendor-portal' ); ?></label><input id="zpvp-last" name="last_name" type="text" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>"></p>
				</div>
				<p><label for="zpvp-company-a"><?php esc_html_e( 'Company name', 'zp-vendor-portal' ); ?></label><input id="zpvp-company-a" name="company" type="text" autocomplete="organization" required value="<?php echo $v( 'zpvp_company_name' ); // phpcs:ignore ?>"></p>
				<div class="zpvp-grid-2">
					<p><label for="zpvp-title-a"><?php esc_html_e( 'Job title', 'zp-vendor-portal' ); ?></label><input id="zpvp-title-a" name="job_title" type="text" autocomplete="organization-title" value="<?php echo $v( 'zpvp_job_title' ); // phpcs:ignore ?>"></p>
					<p><label for="zpvp-phone-a"><?php esc_html_e( 'Phone', 'zp-vendor-portal' ); ?></label><input id="zpvp-phone-a" name="phone" type="tel" autocomplete="tel" value="<?php echo $v( 'zpvp_phone' ); // phpcs:ignore ?>"></p>
				</div>
				<p><label for="zpvp-email-a"><?php esc_html_e( 'Email', 'zp-vendor-portal' ); ?></label><input id="zpvp-email-a" type="email" value="<?php echo esc_attr( $user->user_email ); ?>" readonly aria-describedby="zpvp-email-help">
				<span id="zpvp-email-help" class="zpvp-help"><?php esc_html_e( 'This is your login. To change it, contact ZacPubs.', 'zp-vendor-portal' ); ?></span></p>
				<p><button type="submit" class="zpvp-btn zpvp-btn--primary"><?php esc_html_e( 'Save details', 'zp-vendor-portal' ); ?></button></p>
			</form>

			<form method="post" class="zpvp-panel zpvp-form">
				<h2><?php esc_html_e( 'Change password', 'zp-vendor-portal' ); ?></h2>
				<?php echo self::nonce( 'account_password' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p><label for="zpvp-cur"><?php esc_html_e( 'Current password', 'zp-vendor-portal' ); ?></label><input id="zpvp-cur" name="current_password" type="password" autocomplete="current-password" required></p>
				<p><label for="zpvp-new"><?php esc_html_e( 'New password', 'zp-vendor-portal' ); ?></label><input id="zpvp-new" name="new_password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="zpvp-new-help">
				<span id="zpvp-new-help" class="zpvp-help"><?php esc_html_e( 'At least 10 characters.', 'zp-vendor-portal' ); ?></span></p>
				<p><label for="zpvp-conf"><?php esc_html_e( 'Confirm new password', 'zp-vendor-portal' ); ?></label><input id="zpvp-conf" name="confirm_password" type="password" autocomplete="new-password" minlength="10" required></p>
				<p><button type="submit" class="zpvp-btn"><?php esc_html_e( 'Change password', 'zp-vendor-portal' ); ?></button></p>
			</form>

			<?php echo self::company_panel( (int) $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
