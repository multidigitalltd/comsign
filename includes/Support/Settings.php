<?php
/**
 * Plugin settings access.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over a single options row holding plugin settings.
 */
final class Settings {

	private const OPTION = 'comsign_settings';

	/**
	 * Default settings.
	 *
	 * @return array{reminders_enabled:bool,reminder_days:int}
	 */
	public static function defaults(): array {
		return array(
			'reminders_enabled' => false,
			'reminder_days'     => 3,
		);
	}

	/**
	 * Retrieve all settings merged with defaults.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key Setting key.
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Persist settings (sanitised).
	 *
	 * @param array $data Raw settings.
	 */
	public static function update( array $data ): void {
		update_option(
			self::OPTION,
			array(
				'reminders_enabled' => ! empty( $data['reminders_enabled'] ),
				'reminder_days'     => max( 1, min( 60, (int) ( $data['reminder_days'] ?? 3 ) ) ),
			)
		);
	}
}
