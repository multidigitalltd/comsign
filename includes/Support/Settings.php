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
			'webhook_url'       => '',
			'webhook_secret'    => '',
			'api_key'           => '',
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
		$current = self::all();

		// Keep existing secret/key unless explicitly (re)generated.
		$secret  = (string) $current['webhook_secret'];
		$api_key = (string) $current['api_key'];
		if ( ! empty( $data['regenerate_keys'] ) || '' === $secret ) {
			$secret = wp_generate_password( 40, false );
		}
		if ( ! empty( $data['regenerate_keys'] ) || '' === $api_key ) {
			$api_key = wp_generate_password( 40, false );
		}

		update_option(
			self::OPTION,
			array(
				'reminders_enabled' => ! empty( $data['reminders_enabled'] ),
				'reminder_days'     => max( 1, min( 60, (int) ( $data['reminder_days'] ?? 3 ) ) ),
				'webhook_url'       => isset( $data['webhook_url'] ) ? esc_url_raw( trim( (string) $data['webhook_url'] ) ) : '',
				'webhook_secret'    => $secret,
				'api_key'           => $api_key,
			)
		);
	}
}
