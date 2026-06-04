<?php
/**
 * Scheduled tasks.
 *
 * @package ComSign
 */

namespace ComSign\Setup;

defined( 'ABSPATH' ) || exit;

use ComSign\Services\DocumentService;
use ComSign\Support\Settings;

/**
 * Registers and runs the daily reminder cron event.
 */
final class Cron {

	public const HOOK = 'comsign_daily_reminders';

	/**
	 * Hook the cron callback.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule the daily event (called on activation).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled event (called on deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Daily callback: send reminders if the feature is enabled.
	 */
	public function run(): void {
		if ( ! Settings::get( 'reminders_enabled' ) ) {
			return;
		}

		( new DocumentService() )->run_reminders( (int) Settings::get( 'reminder_days' ) );
	}
}
