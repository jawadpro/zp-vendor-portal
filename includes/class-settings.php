<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Stored settings and helpers for portal page URLs.
 */
final class Settings {

	public const OPT_PAGES    = 'zpvp_pages';
	public const OPT_REG_FORM = 'zpvp_registration_form_id';

	/** Page keys: register, login, dashboard. */
	public static function page_id( string $key ): int {
		$pages = get_option( self::OPT_PAGES, array() );
		return is_array( $pages ) ? (int) ( $pages[ $key ] ?? 0 ) : 0;
	}

	public static function page_url( string $key ): string {
		$id = self::page_id( $key );
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	public static function is_page( string $key ): bool {
		$id = self::page_id( $key );
		return $id && is_page( $id );
	}

	public static function registration_form_id(): int {
		return (int) get_option( self::OPT_REG_FORM, 0 );
	}
}
