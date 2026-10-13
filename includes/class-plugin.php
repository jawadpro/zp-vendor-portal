<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap. Modules register their hooks from here.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->maybe_upgrade();
		$this->load_modules();
	}

	/**
	 * Re-run install steps when the plugin version changes (e.g. after a deploy
	 * without deactivate/activate).
	 */
	private function maybe_upgrade(): void {
		if ( get_option( 'zpvp_version' ) !== ZPVP_VERSION ) {
			Activator::install();
		}
	}

	private function load_modules(): void {
		( new Auth() )->register();
		( new Access() )->register();
		( new Dashboard() )->register();
		( new Frontend() )->register();
		( new Account() )->register();
		( new Menu() )->register();
		( new Conferences() )->register();
		( new Registration_Sync() )->register();
		( new Annual_Form() )->register();
		( new Series() )->register();
		( new Vendor_Attendees() )->register();
		( new Badges() )->register();
		( new Documents() )->register();
		( new Notifications() )->register();
		Admin_Messages::register_worker();
		( new Rest() )->register();
		if ( is_admin() ) {
			( new Admin() )->register();
			( new Admin_Badges() )->register();
			( new Admin_Messages() )->register();
			( new Admin_Reports() )->register();
			( new Admin_App() )->register();
			( new Admin_Settings() )->register();
			( new Setup() )->register();
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Small, fully scoped stylesheet; also needed on conference form pages for the login prompt. */
	public function enqueue(): void {
		wp_enqueue_style( 'zpvp-portal', ZPVP_URL . 'assets/css/portal.css', array(), ZPVP_VERSION );
	}
}
