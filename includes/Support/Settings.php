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
			'brand_name'        => '',
			'brand_logo_url'    => '',
			'brand_color'       => '',
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

		// Accept a #rgb or #rrggbb colour only.
		$color = isset( $data['brand_color'] ) ? sanitize_hex_color( (string) $data['brand_color'] ) : '';

		update_option(
			self::OPTION,
			array(
				'reminders_enabled' => ! empty( $data['reminders_enabled'] ),
				'reminder_days'     => max( 1, min( 60, (int) ( $data['reminder_days'] ?? 3 ) ) ),
				'webhook_url'       => isset( $data['webhook_url'] ) ? esc_url_raw( trim( (string) $data['webhook_url'] ) ) : '',
				'webhook_secret'    => $secret,
				'api_key'           => $api_key,
				'brand_name'        => isset( $data['brand_name'] ) ? sanitize_text_field( (string) $data['brand_name'] ) : '',
				'brand_logo_url'    => isset( $data['brand_logo_url'] ) ? esc_url_raw( trim( (string) $data['brand_logo_url'] ) ) : '',
				'brand_color'       => (string) $color,
			)
		);
	}

	/**
	 * Brand name for signer-facing pages and emails (falls back to site name).
	 */
	public static function brand_name(): string {
		$name = (string) self::get( 'brand_name' );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
