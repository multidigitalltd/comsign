<?php
/**
 * Cardcom gateway credentials + configuration.
 *
 * @package ComSign
 */

namespace ComSign\Billing;

defined( 'ABSPATH' ) || exit;

use ComSign\Support\Crypto;

/**
 * Stores the Cardcom terminal number, API name and (encrypted) API password,
 * plus a test-mode flag. The password is never persisted in plaintext.
 */
final class CardcomSettings {

	private const OPTION = 'comsign_cardcom';

	/**
	 * Production API base. Cardcom's sandbox uses the same host with a test
	 * terminal, so there is a single base URL.
	 */
	public const API_BASE = 'https://secure.cardcom.solutions/api/v11';

	/**
	 * @return array{terminal:int,api_name:string,test_mode:bool}
	 */
	public static function all(): array {
		$opt = get_option( self::OPTION );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'terminal'  => (int) ( $opt['terminal'] ?? 0 ),
			'api_name'  => (string) ( $opt['api_name'] ?? '' ),
			'test_mode' => ! empty( $opt['test_mode'] ),
		);
	}

	public static function terminal(): int {
		return self::all()['terminal'];
	}

	public static function api_name(): string {
		return self::all()['api_name'];
	}

	public static function test_mode(): bool {
		return self::all()['test_mode'];
	}

	/**
	 * Decrypted API password (or '').
	 */
	public static function api_password(): string {
		$opt = get_option( self::OPTION );
		return is_array( $opt ) && ! empty( $opt['api_password'] ) ? Crypto::decrypt( (string) $opt['api_password'] ) : '';
	}

	/**
	 * Whether Cardcom is fully configured for charging.
	 */
	public static function is_configured(): bool {
		$all = self::all();
		return $all['terminal'] > 0 && '' !== $all['api_name'] && '' !== self::api_password();
	}

	/**
	 * Persist settings. A blank password keeps the stored one.
	 *
	 * @param array $data terminal, api_name, api_password, test_mode.
	 */
	public static function save( array $data ): void {
		$opt = get_option( self::OPTION );
		$opt = is_array( $opt ) ? $opt : array();

		$opt['terminal']  = isset( $data['terminal'] ) ? max( 0, (int) $data['terminal'] ) : (int) ( $opt['terminal'] ?? 0 );
		$opt['api_name']  = isset( $data['api_name'] ) ? sanitize_text_field( (string) $data['api_name'] ) : (string) ( $opt['api_name'] ?? '' );
		$opt['test_mode'] = ! empty( $data['test_mode'] );

		if ( isset( $data['api_password'] ) && '' !== (string) $data['api_password'] ) {
			$opt['api_password'] = Crypto::encrypt( (string) $data['api_password'] );
		}

		update_option( self::OPTION, $opt, false );
	}
}
