<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the portal pages once and remembers their IDs. Existing pages are
 * never overwritten; if a page with the slug already exists it is adopted.
 */
final class Pages {

	public static function install(): void {
		$pages = get_option( Settings::OPT_PAGES, array() );
		$pages = is_array( $pages ) ? $pages : array();

		$form_id   = Settings::registration_form_id();
		$shortcode = $form_id ? '[gravityform id="' . $form_id . '" title="false" description="true" ajax="true"]' : '';

		$definitions = array(
			'register'  => array( 'vendor-registration', 'Vendor Registration', $shortcode ),
			'login'     => array( 'vendor-login', 'Vendor Login', '[zpvp_login]' ),
			'dashboard' => array( 'my-conferences', 'My Conferences', '[zpvp_dashboard]' ),
		);

		foreach ( $definitions as $key => $def ) {
			[ $slug, $title, $content ] = $def;

			if ( ! empty( $pages[ $key ] ) && get_post( $pages[ $key ] ) && 'trash' !== get_post_status( $pages[ $key ] ) ) {
				continue;
			}

			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				$pages[ $key ] = $existing->ID;
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => $content,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$pages[ $key ] = $id;
			}
		}

		update_option( Settings::OPT_PAGES, $pages, false );

		// The registration page may exist from before Gravity Forms was active: add the form to it.
		if ( $shortcode && ! empty( $pages['register'] ) ) {
			$page = get_post( (int) $pages['register'] );
			if ( $page && false === strpos( (string) $page->post_content, '[gravityform' ) && false === strpos( (string) $page->post_content, 'wp:gravityforms' ) ) {
				wp_update_post( array( 'ID' => $page->ID, 'post_content' => trim( $page->post_content . "\n\n" . $shortcode ) ) );
			}
		}
	}
}
