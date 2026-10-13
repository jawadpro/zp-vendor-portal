<?php
/**
 * Plugin Name:       ZP Vendor Portal
 * Description:       Vendor accounts, conference registrations, attendees, badges and staff tools for ZacPubs conferences.
 * Version:           0.20.6
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Firelight Creative Development
 * Text Domain:       zp-vendor-portal
 */

defined( 'ABSPATH' ) || exit;

define( 'ZPVP_VERSION', '0.20.6' );
define( 'ZPVP_FILE', __FILE__ );
define( 'ZPVP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZPVP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader: ZPVP\Foo_Bar => includes/class-foo-bar.php
 * Sub-namespaces map to sub-folders: ZPVP\Admin\Foo => includes/admin/class-foo.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'ZPVP\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, 5 ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = ZPVP_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ZPVP\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZPVP\\Deactivator', 'deactivate' ) );

add_action( 'init', array( 'ZPVP\\Plugin', 'instance' ), 5 );
