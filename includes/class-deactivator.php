<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation is intentionally non-destructive: the vendor role and all
 * data stay in place so vendors keep their accounts if the plugin is
 * temporarily switched off.
 */
final class Deactivator {

	public static function deactivate(): void {
		Notifications::unschedule();
	}
}
