<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end login, lost password, reset password and "add vendor access"
 * for existing accounts. Rendered by the [zpvp_login] shortcode.
 *
 * Uses core auth functions (wp_signon, retrieve_password, reset_password),
 * so Kadence brute-force protection and WP Activity Log keep working.
 */
final class Auth {

	private array $errors = array();
	private string $notice = '';
	private string $force_view = '';

	public function register(): void {
		add_shortcode( 'zpvp_login', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_filter( 'retrieve_password_notification_email', array( $this, 'reset_email' ), 10, 4 );
		add_filter( 'gform_user_registration_validation', array( $this, 'existing_email_message' ), 20, 3 );
		add_action( 'gform_user_registered', array( $this, 'welcome_email' ), 10, 4 );
		add_action( 'wp', array( $this, 'activate_vendor' ), 5 );
	}

	/* ------------------------------------------------------------------ */
	/* Form handling                                                       */
	/* ------------------------------------------------------------------ */

	public function handle_post(): void {
		if ( ! Settings::is_page( 'login' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['zpvp_action'] ?? '' ) );
		if ( '' === $action ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['_zpvp_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'zpvp_' . $action ) ) {
			$this->errors[] = __( 'Your session expired. Please try again.', 'zp-vendor-portal' );
			return;
		}

		switch ( $action ) {
			case 'login':
				$this->do_login();
				break;
			case 'lostpassword':
				$this->do_lost_password();
				break;
			case 'resetpass':
				$this->do_reset_password();
				break;
			case 'addvendor':
				$this->do_add_vendor();
				break;
		}
	}

	private function do_login(): void {
		$user = wp_signon(
			array(
				'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
				'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->errors[] = __( 'The email or password is incorrect. If you registered recently, please activate your account using the link in your email first.', 'zp-vendor-portal' );
			return;
		}

		wp_safe_redirect( $this->redirect_after_login( $user ) );
		exit;
	}

	private function redirect_after_login( \WP_User $user ): string {
		$requested = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );

		if ( Roles::is_vendor( $user ) ) {
			return $requested ? wp_validate_redirect( $requested, Settings::page_url( 'dashboard' ) ) : Settings::page_url( 'dashboard' );
		}
		if ( $user->has_cap( 'edit_posts' ) ) {
			return $requested ? wp_validate_redirect( $requested, admin_url() ) : admin_url();
		}
		// Existing non-vendor account (e.g. magazine customer): offer vendor access.
		return Settings::page_url( 'login' );
	}

	private function do_lost_password(): void {
		$login = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );
		if ( '' === $login ) {
			$this->errors[]   = __( 'Please enter your email address.', 'zp-vendor-portal' );
			$this->force_view = 'lostpassword';
			return;
		}
		// Result intentionally ignored so the page never reveals whether an account exists.
		retrieve_password( $login );
		$this->notice     = __( 'If an account exists for that email, we have sent a link to reset your password.', 'zp-vendor-portal' );
		$this->force_view = 'login';
	}

	private function do_reset_password(): void {
		$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$login = sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) );
		$user  = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			$this->errors[]   = __( 'This password reset link is invalid or has expired. Please request a new one.', 'zp-vendor-portal' );
			$this->force_view = 'lostpassword';
			return;
		}

		$pass1 = (string) wp_unslash( $_POST['pass1'] ?? '' );
		$pass2 = (string) wp_unslash( $_POST['pass2'] ?? '' );

		if ( strlen( $pass1 ) < 10 ) {
			$this->errors[] = __( 'Your password must be at least 10 characters long.', 'zp-vendor-portal' );
			return;
		}
		if ( $pass1 !== $pass2 ) {
			$this->errors[] = __( 'The passwords do not match.', 'zp-vendor-portal' );
			return;
		}

		reset_password( $user, $pass1 );
		$this->notice     = __( 'Your password has been reset. You can now log in.', 'zp-vendor-portal' );
		$this->force_view = 'login';
	}

	private function do_add_vendor(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() || Roles::is_vendor( $user ) ) {
			return;
		}

		$company = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$title   = sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( '' === $company || '' === $phone ) {
			$this->errors[] = __( 'Please enter your company name and phone number.', 'zp-vendor-portal' );
			return;
		}

		$user->add_role( Roles::VENDOR );
		update_user_meta( $user->ID, 'zpvp_company_name', $company );
		update_user_meta( $user->ID, 'zpvp_job_title', $title );
		update_user_meta( $user->ID, 'zpvp_phone', $phone );

		wp_safe_redirect( Settings::page_url( 'dashboard' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Integrations                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Point vendor password-reset emails at the front-end reset page instead
	 * of the hidden WordPress login.
	 */
	public function reset_email( array $email, string $key, string $user_login, \WP_User $user ): array {
		if ( ! Roles::is_vendor( $user ) ) {
			return $email;
		}
		$url = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user_login ),
			),
			Settings::page_url( 'login' )
		);

		$email['subject'] = __( 'Reset your ZacPubs vendor account password', 'zp-vendor-portal' );
		$email['message'] = sprintf(
			/* translators: 1: first name, 2: email, 3: reset URL */
			__( "Hi %1\$s,\r\n\r\nWe received a request to reset the password for your ZacPubs vendor account (%2\$s).\r\n\r\nTo choose a new password, visit:\r\n%3\$s\r\n\r\nIf you didn't request this, you can ignore this email and your password will stay the same.\r\n", 'zp-vendor-portal' ),
			$user->first_name ? $user->first_name : $user_login,
			$user->user_email,
			$url
		);
		return $email;
	}

	/**
	 * Friendlier message when someone registers with an email that already has
	 * an account (typically a magazine subscriber).
	 */
	public function existing_email_message( $form, $feed, $pagenum ) {
		if ( (int) ( $form['id'] ?? 0 ) !== Settings::registration_form_id() ) {
			return $form;
		}
		$email_field = (string) ( $feed['meta']['email'] ?? '' );
		if ( '' === $email_field ) {
			return $form;
		}

		$email = sanitize_email( (string) rgpost( 'input_' . str_replace( '.', '_', $email_field ) ) );
		if ( ! $email || ! email_exists( $email ) ) {
			return $form;
		}

		foreach ( $form['fields'] as $field ) {
			if ( (string) $field->id === $email_field ) {
				$field->failed_validation  = true;
				$field->validation_message = sprintf(
					/* translators: %s: login page URL */
					__( 'An account with this email already exists, for example from a magazine subscription. <a href="%s">Log in</a> to add vendor access to that account.', 'zp-vendor-portal' ),
					esc_url( Settings::page_url( 'login' ) )
				);
			}
		}
		return $form;
	}

	/**
	 * Vendor activation links: activate the account here, then send the vendor to the
	 * vendor login page instead of Gravity Forms' activation page (which links to
	 * wp-login.php). Signups from other forms are left to Gravity Forms.
	 */
	public function activate_vendor(): void {
		$key = sanitize_text_field( wp_unslash( $_GET['gfur_activation'] ?? '' ) );
		if ( '' === $key || ! function_exists( 'gf_user_registration' ) || ! class_exists( '\GFAPI' ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->base_prefix}signups WHERE activation_key = %s", $key ) );
		if ( ! $row ) {
			return;
		}
		$meta  = maybe_unserialize( $row->meta );
		$entry = \GFAPI::get_entry( (int) ( is_array( $meta ) ? ( $meta['lead_id'] ?? 0 ) : 0 ) );
		if ( ! is_array( $entry ) || (int) $entry['form_id'] !== Settings::registration_form_id() ) {
			return;
		}

		$state = 'already';
		if ( ! (int) $row->active ) {
			require_once gf_user_registration()->get_base_path() . '/includes/signups.php';
			\GFUserSignups::prep_signups_functionality();
			$result = \GFUserSignups::activate_signup( $key );
			if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'user_already_exists', 'email_already_exists' ), true ) ) {
				return; // Let Gravity Forms show the error.
			}
			$state = is_wp_error( $result ) ? 'already' : 'yes';
		}

		wp_safe_redirect( add_query_arg( array( 'activated' => $state, 'email' => rawurlencode( (string) $row->user_email ) ), Settings::page_url( 'login' ) ) );
		exit;
	}

	/**
	 * Welcome email once a vendor activates their account.
	 */
	public function welcome_email( $user_id, $feed, $entry, $user_pass ): void {
		if ( (int) ( $entry['form_id'] ?? 0 ) !== Settings::registration_form_id() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		wp_mail(
			$user->user_email,
			__( 'Your ZacPubs vendor account is ready', 'zp-vendor-portal' ),
			sprintf(
				/* translators: 1: first name, 2: login URL */
				__( "Hi %1\$s,\r\n\r\nYour vendor account is now active. You can log in at any time to register for conferences and manage your booth, attendees and badges:\r\n%2\$s\r\n\r\nUse the email address and password you chose when you registered.\r\n", 'zp-vendor-portal' ),
				$user->first_name ? $user->first_name : $user->display_name,
				Settings::page_url( 'login' )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public function render(): string {
		ob_start();
		echo '<div class="zpvp zpvp-auth">';
		if ( '' === $this->notice && ! $this->errors && isset( $_GET['activated'] ) ) {
			$this->notice = 'yes' === sanitize_key( wp_unslash( $_GET['activated'] ) )
				? __( 'Your account is active. Log in with the email and password you chose.', 'zp-vendor-portal' )
				: __( 'Your account is already active. Please log in.', 'zp-vendor-portal' );
		}
		$this->messages();

		$view = $this->force_view ? $this->force_view : sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );

		if ( is_user_logged_in() && ! in_array( $view, array( 'rp' ), true ) ) {
			$user = wp_get_current_user();
			if ( current_user_can( Roles::CAP_PORTAL ) ) {
				$this->view_logged_in( $user );
			} else {
				$this->view_add_vendor( $user );
			}
		} elseif ( 'lostpassword' === $view ) {
			$this->view_lost_password();
		} elseif ( 'rp' === $view ) {
			$this->view_reset_password();
		} else {
			$this->view_login();
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	private function messages(): void {
		if ( $this->notice ) {
			echo '<div class="zpvp-notice zpvp-notice--success">' . esc_html( $this->notice ) . '</div>';
		}
		foreach ( $this->errors as $error ) {
			echo '<div class="zpvp-notice zpvp-notice--error">' . esc_html( $error ) . '</div>';
		}
	}

	private function nonce( string $action ): void {
		echo '<input type="hidden" name="zpvp_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_zpvp_nonce" value="' . esc_attr( wp_create_nonce( 'zpvp_' . $action ) ) . '">';
	}

	private function view_login(): void {
		$redirect = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ?? '' ) );
		$email    = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );
		?>
		<form method="post" class="zpvp-form">
			<?php $this->nonce( 'login' ); ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<p><label for="zpvp-log"><?php esc_html_e( 'Email', 'zp-vendor-portal' ); ?></label>
			<input type="email" id="zpvp-log" name="log" value="<?php echo esc_attr( $email ); ?>" autocomplete="username" required></p>
			<p><label for="zpvp-pwd"><?php esc_html_e( 'Password', 'zp-vendor-portal' ); ?></label>
			<input type="password" id="zpvp-pwd" name="pwd" autocomplete="current-password" required></p>
			<p class="zpvp-inline"><label><input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Remember me', 'zp-vendor-portal' ); ?></label></p>
			<p><button type="submit" class="zpvp-btn zpvp-btn--primary zpvp-btn--block"><?php esc_html_e( 'Log in', 'zp-vendor-portal' ); ?></button></p>
		</form>
		<p class="zpvp-links">
			<a href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword', Settings::page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'zp-vendor-portal' ); ?></a>
			&nbsp;|&nbsp;
			<a href="<?php echo esc_url( Settings::page_url( 'register' ) ); ?>"><?php esc_html_e( 'Create a vendor account', 'zp-vendor-portal' ); ?></a>
		</p>
		<?php
	}

	private function view_lost_password(): void {
		?>
		<p><?php esc_html_e( 'Enter your email address and we will send you a link to choose a new password.', 'zp-vendor-portal' ); ?></p>
		<form method="post" class="zpvp-form" action="<?php echo esc_url( Settings::page_url( 'login' ) ); ?>">
			<?php $this->nonce( 'lostpassword' ); ?>
			<p><label for="zpvp-user-login"><?php esc_html_e( 'Email', 'zp-vendor-portal' ); ?></label>
			<input type="email" id="zpvp-user-login" name="user_login" autocomplete="username" required></p>
			<p><button type="submit" class="zpvp-btn zpvp-btn--primary zpvp-btn--block"><?php esc_html_e( 'Send reset link', 'zp-vendor-portal' ); ?></button></p>
		</form>
		<p class="zpvp-links"><a href="<?php echo esc_url( Settings::page_url( 'login' ) ); ?>"><?php esc_html_e( 'Back to log in', 'zp-vendor-portal' ); ?></a></p>
		<?php
	}

	private function view_reset_password(): void {
		$key   = sanitize_text_field( wp_unslash( $_REQUEST['key'] ?? '' ) );
		$login = sanitize_text_field( wp_unslash( $_REQUEST['login'] ?? '' ) );
		$user  = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			echo '<div class="zpvp-notice zpvp-notice--error">' . esc_html__( 'This password reset link is invalid or has expired.', 'zp-vendor-portal' ) . '</div>';
			echo '<p><a href="' . esc_url( add_query_arg( 'action', 'lostpassword', Settings::page_url( 'login' ) ) ) . '">' . esc_html__( 'Request a new link', 'zp-vendor-portal' ) . '</a></p>';
			return;
		}
		?>
		<form method="post" class="zpvp-form" action="<?php echo esc_url( add_query_arg( 'action', 'rp', Settings::page_url( 'login' ) ) ); ?>">
			<?php $this->nonce( 'resetpass' ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
			<p><label for="zpvp-pass1"><?php esc_html_e( 'New password (at least 10 characters)', 'zp-vendor-portal' ); ?></label>
			<input type="password" id="zpvp-pass1" name="pass1" autocomplete="new-password" minlength="10" required></p>
			<p><label for="zpvp-pass2"><?php esc_html_e( 'Confirm new password', 'zp-vendor-portal' ); ?></label>
			<input type="password" id="zpvp-pass2" name="pass2" autocomplete="new-password" minlength="10" required></p>
			<p><button type="submit" class="zpvp-btn zpvp-btn--primary zpvp-btn--block"><?php esc_html_e( 'Save password', 'zp-vendor-portal' ); ?></button></p>
		</form>
		<?php
	}

	private function view_logged_in( \WP_User $user ): void {
		?>
		<p><?php echo esc_html( sprintf( __( 'You are logged in as %s.', 'zp-vendor-portal' ), $user->user_email ) ); ?></p>
		<p><a class="zpvp-btn zpvp-btn--primary" href="<?php echo esc_url( Settings::page_url( 'dashboard' ) ); ?>"><?php esc_html_e( 'Go to my conferences', 'zp-vendor-portal' ); ?></a>
		&nbsp;<a href="<?php echo esc_url( wp_logout_url( Settings::page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Log out', 'zp-vendor-portal' ); ?></a></p>
		<?php
	}

	private function view_add_vendor( \WP_User $user ): void {
		?>
		<p><?php echo esc_html( sprintf( __( 'You are logged in as %s. Add vendor access to this account to register for conferences.', 'zp-vendor-portal' ), $user->user_email ) ); ?></p>
		<form method="post" class="zpvp-form">
			<?php $this->nonce( 'addvendor' ); ?>
			<p><label for="zpvp-company"><?php esc_html_e( 'Company Name', 'zp-vendor-portal' ); ?></label>
			<input type="text" id="zpvp-company" name="company" autocomplete="organization" required></p>
			<p><label for="zpvp-title"><?php esc_html_e( 'Job Title', 'zp-vendor-portal' ); ?></label>
			<input type="text" id="zpvp-title" name="job_title" autocomplete="organization-title"></p>
			<p><label for="zpvp-phone"><?php esc_html_e( 'Phone', 'zp-vendor-portal' ); ?></label>
			<input type="tel" id="zpvp-phone" name="phone" autocomplete="tel" required></p>
			<p><button type="submit" class="zpvp-btn zpvp-btn--primary zpvp-btn--block"><?php esc_html_e( 'Add vendor access', 'zp-vendor-portal' ); ?></button></p>
		</form>
		<p class="zpvp-links"><a href="<?php echo esc_url( wp_logout_url( Settings::page_url( 'login' ) ) ); ?>"><?php esc_html_e( 'Log out', 'zp-vendor-portal' ); ?></a></p>
		<?php
	}
}
