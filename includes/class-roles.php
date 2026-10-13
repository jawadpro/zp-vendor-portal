<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Roles and capabilities used by the vendor portal.
 */
final class Roles {

	public const VENDOR = 'zp_vendor';

	/** Capability every vendor has; gates front-end portal pages. */
	public const CAP_PORTAL = 'zpvp_access_portal';

	/** Capability for staff screens in WP Admin. */
	public const CAP_MANAGE = 'zpvp_manage';

	public static function add(): void {
		$role = get_role( self::VENDOR );
		if ( ! $role ) {
			$role = add_role( self::VENDOR, __( 'Vendor', 'zp-vendor-portal' ), array( 'read' => true ) );
		}
		if ( $role ) {
			$role->add_cap( 'read' );
			$role->add_cap( self::CAP_PORTAL );
		}

		foreach ( array( 'administrator', 'editor' ) as $staff_role ) {
			$staff = get_role( $staff_role );
			if ( $staff ) {
				$staff->add_cap( self::CAP_MANAGE );
				$staff->add_cap( self::CAP_PORTAL );
			}
		}
	}

	public static function is_vendor( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return $user instanceof \WP_User && in_array( self::VENDOR, (array) $user->roles, true );
	}

	/**
	 * Staff "View as vendor": with ?zpvp_view_as={user ID} on a vendor page, staff
	 * see that vendor's portal read-only. Everyone else always sees their own.
	 */
	public static function viewer(): \WP_User {
		$preview = self::preview_user();
		return $preview ? $preview : wp_get_current_user();
	}

	public static function is_preview(): bool {
		return null !== self::preview_user();
	}

	public static function preview_user(): ?\WP_User {
		$id = absint( $_GET['zpvp_view_as'] ?? 0 );
		if ( ! $id || ! current_user_can( self::CAP_MANAGE ) ) {
			return null;
		}
		$user = get_userdata( $id );
		return ( $user && in_array( 'zp_vendor', (array) $user->roles, true ) ) ? $user : null;
	}

	/** Keep the preview on links inside the portal. */
	public static function preview_arg( string $url ): string {
		$user = self::preview_user();
		return $user ? add_query_arg( 'zpvp_view_as', $user->ID, $url ) : $url;
	}
}
