<?php
/**
 * Plugin activation routine.
 *
 * @package ComSign
 */

namespace ComSign\Setup;

defined( 'ABSPATH' ) || exit;

use ComSign\Frontend\PortalController;
use ComSign\Frontend\SigningController;
use ComSign\Support\Capabilities;
use ComSign\Support\Storage;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Create tables, capabilities and the protected storage directory.
	 */
	public static function activate(): void {
		Installer::install();
		Capabilities::add();
		Storage::ensure_protected_dir();
		Cron::schedule();

		// Register our rewrite rules before flushing so they resolve.
		SigningController::register_verify_route();
		PortalController::register_route();
		flush_rewrite_rules();
	}
}
