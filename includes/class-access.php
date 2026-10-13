<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps vendors on the front end and protects portal pages.
 */
final class Access {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( $this, 'admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( $this, 'woocommerce_login_redirect' ), 20, 2 );
		add_filter( 'logout_redirect', array( $this, 'logout_redirect' ), 20, 3 );
		add_action( 'template_redirect', array( $this, 'guard_pages' ), 5 );
	}

	/**
	 * A vendor with no other meaningful role (not staff, not a WooCommerce customer).
	 */
	private function vendor_only( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		if ( ! Roles::is_vendor( $user ) ) {
			return false;
		}
		if ( $user->has_cap( 'edit_posts' ) || $user->has_cap( 'manage_woocommerce' ) ) {
			return false;
		}
		return ! in_array( 'customer', (array) $user->roles, true );
	}

	/** Vendor without staff capabilities (may also be a customer). */
	private function vendor_no_staff( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return Roles::is_vendor( $user ) && ! $user->has_cap( 'edit_posts' ) && ! $user->has_cap( 'manage_woocommerce' );
	}

	public function block_admin(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( $this->vendor_no_staff() ) {
			wp_safe_redirect( Settings::page_url( 'dashboard' ) );
			exit;
		}
	}

	public function admin_bar( $show ) {
		return $this->vendor_no_staff() ? false : $show;
	}

	public function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User && $this->vendor_no_staff( $user ) ) {
			return Settings::page_url( 'dashboard' );
		}
		return $redirect_to;
	}

	public function woocommerce_login_redirect( $redirect, $user ) {
		if ( $user instanceof \WP_User && $this->vendor_only( $user ) ) {
			return Settings::page_url( 'dashboard' );
		}
		return $redirect;
	}

	public function logout_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User && Roles::is_vendor( $user ) ) {
			return Settings::page_url( 'login' );
		}
		return $redirect_to;
	}

	public function guard_pages(): void {
		// My Conferences: vendors and staff only.
		if ( Settings::is_page( 'dashboard' ) && ! current_user_can( Roles::CAP_PORTAL ) ) {
			$target = is_user_logged_in()
				? Settings::page_url( 'login' ) // Logged in without vendor access: offer to add it.
				: add_query_arg( 'redirect_to', rawurlencode( Settings::page_url( 'dashboard' ) ), Settings::page_url( 'login' ) );
			wp_safe_redirect( $target );
			exit;
		}

		// Registration page: nothing to do for logged-in users.
		if ( Settings::is_page( 'register' ) && is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
			wp_safe_redirect( current_user_can( Roles::CAP_PORTAL ) ? Settings::page_url( 'dashboard' ) : Settings::page_url( 'login' ) );
			exit;
		}

		// WooCommerce My Account is not relevant for vendor-only users.
		if ( function_exists( 'is_account_page' ) && is_account_page() && $this->vendor_only() ) {
			wp_safe_redirect( Settings::page_url( 'dashboard' ) );
			exit;
		}
	}
}
