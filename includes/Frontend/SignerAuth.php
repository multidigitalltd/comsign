<?php
/**
 * Per-signer authentication (access code / email OTP).
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an optional identity challenge before a signer can view/sign a document.
 *
 * Two methods, neither requiring a third-party service:
 *  - "code"      : a shared access code the sender communicates out of band.
 *  - "email_otp" : a one-time code emailed to the signer on demand.
 *
 * Once verified, a short-lived signed cookie lets the signer continue without
 * re-challenging on every page load.
 */
final class SignerAuth {

	public const METHOD_NONE  = 'none';
	public const METHOD_CODE  = 'code';
	public const METHOD_OTP   = 'email_otp';

	private const COOKIE_PREFIX = 'comsign_auth_';
	private const SESSION_TTL    = 2 * HOUR_IN_SECONDS;
	private const OTP_TTL        = 10 * MINUTE_IN_SECONDS;

	// Brute-force throttling for the identity challenge.
	public const MAX_ATTEMPTS   = 5;
	private const LOCKOUT_TTL    = 15 * MINUTE_IN_SECONDS;

	/**
	 * Available methods => human label (for the admin UI).
	 */
	public static function methods(): array {
		return array(
			self::METHOD_NONE => __( 'No extra verification', 'comsign' ),
			self::METHOD_CODE => __( 'Access code (shared by you)', 'comsign' ),
			self::METHOD_OTP  => __( 'Email one-time code', 'comsign' ),
		);
	}

	/**
	 * Whether a signer must pass a challenge.
	 *
	 * @param object $signer Signer row.
	 */
	public static function requires( object $signer ): bool {
		$method = (string) ( $signer->auth_method ?? self::METHOD_NONE );
		if ( self::METHOD_CODE === $method ) {
			return '' !== (string) ( $signer->auth_code_hash ?? '' );
		}
		if ( self::METHOD_OTP === $method ) {
			return '' !== (string) ( $signer->email ?? '' );
		}
		return false;
	}

	/**
	 * Hash an access code for storage.
	 *
	 * @param string $code Plain code.
	 */
	public static function hash_code( string $code ): string {
		return wp_hash_password( $code );
	}

	/**
	 * Whether the signer has a valid verification cookie for this session.
	 *
	 * @param object $signer Signer row.
	 */
	public static function verified( object $signer ): bool {
		$name = self::COOKIE_PREFIX . (int) $signer->id;
		if ( empty( $_COOKIE[ $name ] ) ) {
			return false;
		}
		$provided = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		return hash_equals( self::cookie_value( $signer ), $provided );
	}

	/**
	 * Mark the signer verified by setting the session cookie.
	 *
	 * @param object $signer Signer row.
	 */
	public static function mark_verified( object $signer ): void {
		setcookie(
			self::COOKIE_PREFIX . (int) $signer->id,
			self::cookie_value( $signer ),
			array(
				'expires'  => time() + self::SESSION_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Verify a submitted access code.
	 *
	 * @param object $signer Signer row.
	 * @param string $code   Submitted code.
	 */
	public static function verify_code( object $signer, string $code ): bool {
		$hash = (string) ( $signer->auth_code_hash ?? '' );
		return '' !== $hash && '' !== $code && wp_check_password( $code, $hash );
	}

	/**
	 * Generate, store and email a one-time code to the signer.
	 *
	 * @param object $signer Signer row.
	 *
	 * @return bool Whether the email was dispatched.
	 */
	public static function send_otp( object $signer ): bool {
		$email = (string) ( $signer->email ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		set_transient( self::otp_key( $signer ), wp_hash_password( $code ), self::OTP_TTL );

		$subject = __( 'Your ComSign verification code', 'comsign' );
		$body    = sprintf(
			/* translators: 1: 6-digit code, 2: minutes until expiry. */
			__( "Your verification code is: %1\$s\n\nIt expires in %2\$d minutes.", 'comsign' ),
			$code,
			(int) ( self::OTP_TTL / MINUTE_IN_SECONDS )
		);

		return (bool) wp_mail( $email, $subject, $body );
	}

	/**
	 * Verify a submitted one-time code.
	 *
	 * @param object $signer Signer row.
	 * @param string $code   Submitted code.
	 */
	public static function verify_otp( object $signer, string $code ): bool {
		$hash = get_transient( self::otp_key( $signer ) );
		if ( ! is_string( $hash ) || '' === $code ) {
			return false;
		}
		if ( wp_check_password( $code, $hash ) ) {
			delete_transient( self::otp_key( $signer ) );
			return true;
		}
		return false;
	}

	/**
	 * Whether the signer is currently locked out after too many failed attempts.
	 *
	 * @param object $signer Signer row.
	 */
	public static function is_locked_out( object $signer ): bool {
		return (int) get_transient( self::attempts_key( $signer ) ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Record one failed verification attempt (sliding lockout window).
	 *
	 * @param object $signer Signer row.
	 */
	public static function register_failed_attempt( object $signer ): void {
		$key   = self::attempts_key( $signer );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::LOCKOUT_TTL );
	}

	/**
	 * Clear the failed-attempt counter (e.g. after a successful verification).
	 *
	 * @param object $signer Signer row.
	 */
	public static function clear_attempts( object $signer ): void {
		delete_transient( self::attempts_key( $signer ) );
	}

	/**
	 * Transient key for a signer's failed-attempt counter.
	 *
	 * @param object $signer Signer row.
	 */
	private static function attempts_key( object $signer ): string {
		return 'comsign_authfail_' . (int) $signer->id;
	}

	/**
	 * Deterministic, secret cookie value bound to the signer + their token.
	 *
	 * @param object $signer Signer row.
	 */
	private static function cookie_value( object $signer ): string {
		return hash_hmac( 'sha256', (int) $signer->id . '|' . (string) $signer->token_hash, wp_salt( 'auth' ) );
	}

	/**
	 * Transient key for a signer's current OTP.
	 *
	 * @param object $signer Signer row.
	 */
	private static function otp_key( object $signer ): string {
		return 'comsign_otp_' . (int) $signer->id;
	}
}
