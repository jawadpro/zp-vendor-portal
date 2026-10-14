<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end presentation: full-width portal pages, the portal shell
 * (header bar + navigation), the login/registration layout and small
 * shared UI helpers.
 */
final class Frontend {

	public function register(): void {
		add_filter( 'genesis_site_layout', array( $this, 'full_width' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'the_content', array( $this, 'access_layout' ), 20 );
	}

	public static function is_portal_page(): bool {
		return Settings::is_page( 'login' ) || Settings::is_page( 'register' ) || Settings::is_page( 'dashboard' );
	}

	public function full_width( $layout ) {
		return self::is_portal_page() ? 'full-width-content' : $layout;
	}

	public function body_class( array $classes ): array {
		if ( self::is_portal_page() ) {
			$classes[] = 'zpvp-portal';
		}
		return $classes;
	}

	/** Two-column layout for the login and registration pages. */
	public function access_layout( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( Settings::is_page( 'login' ) ) {
			$heading = is_user_logged_in() ? __( 'Your vendor account', 'zp-vendor-portal' ) : __( 'Log in to your vendor account', 'zp-vendor-portal' );
		} elseif ( Settings::is_page( 'register' ) ) {
			$heading = __( 'Create your vendor account', 'zp-vendor-portal' );
		} else {
			return $content;
		}

		$intro = '<aside class="zpvp-access__intro">'
			. '<h2>' . esc_html__( 'One account for every ZacPubs conference', 'zp-vendor-portal' ) . '</h2>'
			. '<p>' . esc_html__( 'Exhibitors use their vendor account to:', 'zp-vendor-portal' ) . '</p>'
			. '<ul>'
			. '<li>' . esc_html__( 'Register for conferences with your details filled in', 'zp-vendor-portal' ) . '</li>'
			. '<li>' . esc_html__( 'See your booth numbers and payment status', 'zp-vendor-portal' ) . '</li>'
			. '<li>' . esc_html__( 'Keep your booth staff list up to date for name badges', 'zp-vendor-portal' ) . '</li>'
			. '<li>' . esc_html__( 'Download invoices and receipts', 'zp-vendor-portal' ) . '</li>'
			. '</ul>'
			. '</aside>';

		return '<div class="zpvp zpvp-access">' . $intro
			. '<section class="zpvp-access__panel"><h1 class="zpvp-access__title">' . esc_html( $heading ) . '</h1>' . $content . '</section>'
			. '</div>';
	}

	/** Wrap vendor content in the portal shell. */
	public static function shell( string $active, string $inner ): string {
		$user    = wp_get_current_user();
		$company = Companies::company_name_for_user( (int) $user->ID );
		$items   = array(
			'dashboard' => array( __( 'My conferences', 'zp-vendor-portal' ), Settings::page_url( 'dashboard' ) ),
			'account'   => array( __( 'Account', 'zp-vendor-portal' ), add_query_arg( 'view', 'account', Settings::page_url( 'dashboard' ) ) ),
		);
		$nav = '';
		foreach ( $items as $key => $item ) {
			$current = $key === $active;
			$nav    .= '<a href="' . esc_url( $item[1] ) . '"' . ( $current ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . '</a>';
		}
		$nav .= '<a class="zpvp-shell__logout" href="' . esc_url( wp_logout_url( Settings::page_url( 'login' ) ) ) . '">' . esc_html__( 'Log out', 'zp-vendor-portal' ) . '</a>';

		return '<div class="zpvp zpvp-shell">'
			. '<header class="zpvp-shell__bar">'
			. '<div class="zpvp-shell__who"><span class="zpvp-shell__title">' . esc_html__( 'Vendor portal', 'zp-vendor-portal' ) . '</span>'
			. ( $company ? '<span class="zpvp-shell__company">' . esc_html( $company ) . '</span>' : '' ) . '</div>'
			. '<nav class="zpvp-shell__nav" aria-label="' . esc_attr__( 'Vendor portal', 'zp-vendor-portal' ) . '">' . $nav . '</nav>'
			. '</header>'
			. '<div class="zpvp-shell__main">' . $inner . '</div>'
			. '</div>';
	}

	/** Coloured status label for a payment status. */
	public static function pill( string $status ): string {
		return '<span class="zpvp-pill zpvp-pill--' . esc_attr( $status ) . '">' . esc_html( Registrations::payment_label( $status ) ) . '</span>';
	}

	/** Month and day range for the calendar block, from Ymd meta. */
	public static function date_block( int $edition_id ): array {
		$start = \DateTime::createFromFormat( 'Ymd', (string) get_post_meta( $edition_id, 'start_date', true ) );
		$end   = \DateTime::createFromFormat( 'Ymd', (string) get_post_meta( $edition_id, 'end_date', true ) );
		if ( ! $start ) {
			return array( '', '' );
		}
		$days = $start->format( 'j' );
		if ( $end && $end->format( 'Ymd' ) !== $start->format( 'Ymd' ) ) {
			$days .= '–' . ( $end->format( 'm' ) === $start->format( 'm' ) ? $end->format( 'j' ) : $end->format( 'M j' ) );
		}
		return array( $start->format( 'M' ), $days );
	}
}
