<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		self::install();
	}

	/** Idempotent install steps; safe to run on every version change. */
	public static function install(): void {
		Roles::add();
		Setup::install_forms();
		Pages::install();
		Registrations::install();
		Annual_Registrations::install();
		Companies::install();
		Attendees::install();
		Badges::install();
		Badge_Orders::install();
		Mailer::install();
		Notifications::schedule();
		update_option( 'zpvp_version', ZPVP_VERSION, false );
	}
}
