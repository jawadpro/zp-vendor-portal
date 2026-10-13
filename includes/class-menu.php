<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the vendor link to the end of the site's header menu.
 *
 * - Logged out (or logged in without portal access): "Vendor Login".
 * - Vendors and staff with portal access: "Vendor Portal", with a
 *   "Log out" sub-item.
 *
 * Both labels and an on/off switch are set by staff in Vendor Portal →
 * Settings → Menu link (option `zpvp_menu`). Nothing is stored in the menu
 * itself, so the link appears on any install without setup and can't be
 * deleted by accident. The target menu location defaults to "primary" (the
 * Company Menu on Magazine Pro) and can be changed with the
 * `zpvp_menu_location` filter.
 */
final class Menu {

	public const OPT = 'zpvp_menu';

	/** High fake IDs so they never collide with real menu item IDs. */
	private const ID_PARENT = 990000001;
	private const ID_LOGOUT = 990000002;

	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'add_items' ), 20, 2 );
		add_action( 'admin_post_zpvp_menu_settings', array( $this, 'save' ) );
	}

	/** @return array{enabled:int, label_out:string, label_in:string} */
	public static function defaults(): array {
		return array(
			'enabled'   => 1,
			'label_out' => __( 'Vendor Login', 'zp-vendor-portal' ),
			'label_in'  => __( 'Vendor Portal', 'zp-vendor-portal' ),
		);
	}

	/** Saved settings, with defaults for anything missing or left blank. */
	public static function settings(): array {
		$saved = get_option( self::OPT, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::defaults();
		if ( isset( $saved['enabled'] ) ) {
			$out['enabled'] = $saved['enabled'] ? 1 : 0;
		}
		foreach ( array( 'label_out', 'label_in' ) as $key ) {
			if ( ! empty( $saved[ $key ] ) ) {
				$out[ $key ] = (string) $saved[ $key ];
			}
		}
		return $out;
	}

	/**
	 * @param array     $items Sorted menu item objects.
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 */
	public function add_items( $items, $args ) {
		$location = (string) apply_filters( 'zpvp_menu_location', 'primary' );
		if ( '' === $location || ! is_array( $items ) || empty( $args->theme_location ) || $location !== $args->theme_location ) {
			return $items;
		}

		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return $items;
		}

		$order = count( $items ) + 1;

		if ( is_user_logged_in() && current_user_can( Roles::CAP_PORTAL ) ) {
			$parent            = $this->item( self::ID_PARENT, 0, $settings['label_in'], Settings::page_url( 'dashboard' ), $order, Settings::is_page( 'dashboard' ) );
			$parent->classes[] = 'menu-item-has-children';
			$items[]           = $parent;
			$items[]           = $this->item( self::ID_LOGOUT, self::ID_PARENT, __( 'Log out', 'zp-vendor-portal' ), wp_logout_url( Settings::page_url( 'login' ) ), $order + 1, false );
		} else {
			$items[] = $this->item( self::ID_PARENT, 0, $settings['label_out'], Settings::page_url( 'login' ), $order, Settings::is_page( 'login' ) || Settings::is_page( 'register' ) );
		}

		return $items;
	}

	/** Saves the Settings → Menu link tab. */
	public function save(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_menu_settings' );
		update_option(
			self::OPT,
			array(
				'enabled'   => empty( $_POST['enabled'] ) ? 0 : 1,
				'label_out' => sanitize_text_field( wp_unslash( $_POST['label_out'] ?? '' ) ),
				'label_in'  => sanitize_text_field( wp_unslash( $_POST['label_in'] ?? '' ) ),
			),
			false
		);
		wp_safe_redirect( Admin_Settings::url( 'menu', array( 'zpvp_msg' => rawurlencode( 'Menu link saved.' ) ) ) );
		exit;
	}

	/** A menu item object shaped like the ones wp_nav_menu() passes to the walker. */
	private function item( int $id, int $parent, string $title, string $url, int $order, bool $current ): \stdClass {
		$classes = array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'zpvp-menu-item', 'menu-item-' . $id );
		if ( $current ) {
			$classes[] = 'current-menu-item';
		}

		return (object) array(
			'ID'                    => $id,
			'db_id'                 => $id,
			'menu_item_parent'      => (string) $parent,
			'object_id'             => (string) $id,
			'object'                => 'custom',
			'type'                  => 'custom',
			'type_label'            => 'Custom Link',
			'post_type'             => 'nav_menu_item',
			'post_status'           => 'publish',
			'post_parent'           => 0,
			'post_title'            => $title,
			'post_name'             => '',
			'post_excerpt'          => '',
			'post_content'          => '',
			'title'                 => $title,
			'url'                   => $url,
			'target'                => '',
			'attr_title'            => '',
			'description'           => '',
			'xfn'                   => '',
			'classes'               => $classes,
			'menu_order'            => $order,
			'current'               => $current,
			'current_item_parent'   => false,
			'current_item_ancestor' => false,
			'filter'                => 'raw',
		);
	}
}
