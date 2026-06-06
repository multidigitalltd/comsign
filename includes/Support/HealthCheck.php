<?php
/**
 * System health checks for the admin status screen.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Cron;
use ComSign\Signature\Certificate;

/**
 * Runs lightweight environment/configuration checks and reports their status,
 * so an administrator can spot deployment problems before they bite a signer.
 */
final class HealthCheck {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/**
	 * Run every check.
	 *
	 * @return array<int,array{label:string,status:string,detail:string}>
	 */
	public static function run(): array {
		return array(
			self::php_version(),
			self::gd(),
			self::openssl(),
			self::cron(),
			self::storage(),
			self::mail(),
			self::rest_key(),
			self::webhook(),
		);
	}

	/**
	 * Shorthand to build a result row.
	 */
	private static function row( string $label, string $status, string $detail ): array {
		return array(
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
	}

	private static function php_version(): array {
		$ok = version_compare( PHP_VERSION, '7.4', '>=' );
		return self::row(
			__( 'PHP version', 'comsign' ),
			$ok ? self::OK : self::FAIL,
			// translators: %s: PHP version.
			sprintf( __( 'Running PHP %s (7.4+ required).', 'comsign' ), PHP_VERSION )
		);
	}

	private static function gd(): array {
		$ok = function_exists( 'imagecreatefrompng' );
		return self::row(
			__( 'Image support (GD)', 'comsign' ),
			$ok ? self::OK : self::WARN,
			$ok
				? __( 'GD is available for stamping signature images.', 'comsign' )
				: __( 'GD is missing; drawn/typed signature images cannot be processed.', 'comsign' )
		);
	}

	private static function openssl(): array {
		$ok = Certificate::openssl_available();
		return self::row(
			__( 'OpenSSL (PKI signing)', 'comsign' ),
			$ok ? self::OK : self::WARN,
			$ok
				? __( 'OpenSSL is available for cryptographic (PAdES) signing.', 'comsign' )
				: __( 'OpenSSL is unavailable; documents use electronic signatures only.', 'comsign' )
		);
	}

	private static function cron(): array {
		$scheduled = (bool) wp_next_scheduled( Cron::HOOK );
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! $scheduled ) {
			return self::row( __( 'Scheduled tasks (WP-Cron)', 'comsign' ), self::FAIL, __( 'The reminder task is not scheduled. Reactivate the plugin.', 'comsign' ) );
		}
		if ( $disabled ) {
			return self::row( __( 'Scheduled tasks (WP-Cron)', 'comsign' ), self::WARN, __( 'WP-Cron is disabled; make sure a real system cron triggers wp-cron.php, or reminders will not be sent.', 'comsign' ) );
		}
		return self::row( __( 'Scheduled tasks (WP-Cron)', 'comsign' ), self::OK, __( 'The daily reminder task is scheduled.', 'comsign' ) );
	}

	private static function storage(): array {
		$dir = Storage::ensure_protected_dir();

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return self::row( __( 'Protected file storage', 'comsign' ), self::FAIL, __( 'The protected uploads directory is missing or not writable.', 'comsign' ) );
		}

		$has_htaccess = is_file( trailingslashit( $dir ) . '.htaccess' );
		if ( ! $has_htaccess ) {
			return self::row( __( 'Protected file storage', 'comsign' ), self::WARN, __( 'Storage is writable but the .htaccess guard is missing. On nginx, add a deny rule for the comsign uploads directory.', 'comsign' ) );
		}

		return self::row( __( 'Protected file storage', 'comsign' ), self::OK, __( 'The storage directory exists, is writable and carries deny rules (verify nginx separately).', 'comsign' ) );
	}

	private static function mail(): array {
		return self::row(
			__( 'Email delivery', 'comsign' ),
			self::OK,
			__( 'Invitations are sent with wp_mail(). Use “Send test email” below to confirm delivery on this server.', 'comsign' )
		);
	}

	private static function rest_key(): array {
		$ok = '' !== (string) Settings::get( 'api_key' );
		return self::row(
			__( 'REST API key', 'comsign' ),
			$ok ? self::OK : self::WARN,
			$ok
				? __( 'A REST API key is configured.', 'comsign' )
				: __( 'No REST API key is set. Save Settings to generate one if you use the API.', 'comsign' )
		);
	}

	private static function webhook(): array {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url ) {
			return self::row( __( 'Outgoing webhook', 'comsign' ), self::OK, __( 'No webhook URL configured (optional).', 'comsign' ) );
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_http_validate_url( $url ) ) {
			return self::row( __( 'Outgoing webhook', 'comsign' ), self::WARN, __( 'The webhook URL is set but looks invalid or points to a blocked (private/loopback) target.', 'comsign' ) );
		}
		return self::row( __( 'Outgoing webhook', 'comsign' ), self::OK, __( 'A webhook URL is configured. Use “Send test webhook” below to confirm it is reachable.', 'comsign' ) );
	}
}
