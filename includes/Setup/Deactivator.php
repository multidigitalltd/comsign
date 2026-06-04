<?php
/**
 * Plugin deactivation routine.
 *
 * @package ComSign
 */

namespace ComSign\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 *
 * Note: this intentionally does NOT drop tables or delete files — that only
 * happens on uninstall, so deactivating never destroys signed documents.
 */
final class Deactivator {

	/**
	 * Clean up transient, non-destructive state.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
