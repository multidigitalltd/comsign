<?php
/**
 * Cardcom payment webhook + checkout return handling.
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

use ComSign\Services\BillingService;

/**
 * Optional safety net for Cardcom's per-transaction notification (the
 * WebHookUrl passed with each Low Profile checkout — not a terminal-wide
 * indicator, so it never interferes with other systems sharing the same
 * Cardcom terminal).
 *
 * Activation normally happens on the customer's browser return
 * ({@see PortalController::maybe_complete_checkout()}); this endpoint only
 * matters when the browser never makes it back. Either way the reference is
 * re-verified directly with Cardcom (GetLpResult) and checked against our
 * HMAC-signed return value before anything is activated, so a forged call does
 * nothing and a missing webhook costs nothing.
 */
final class BillingController {

	public function register(): void {
		add_action( 'admin_post_nopriv_comsign_cardcom_webhook', array( $this, 'handle_webhook' ) );
		add_action( 'admin_post_comsign_cardcom_webhook', array( $this, 'handle_webhook' ) );
	}

	/**
	 * The URL Cardcom should POST results to.
	 */
	public static function webhook_url(): string {
		return admin_url( 'admin-post.php?action=comsign_cardcom_webhook' );
	}

	/** Max accepted Low Profile reference length (Cardcom ids are short). */
	private const REF_MAX_LEN = 64;
	/** Verification attempts allowed per client IP per window. */
	private const RATE_LIMIT = 30;
	/** Rate-limit window, seconds. */
	private const RATE_WINDOW = 600;

	/**
	 * Handle the Cardcom result notification.
	 *
	 * This endpoint is public and unauthenticated (Cardcom posts to it), so it is
	 * a noise/abuse vector even though a forged call can never activate anything
	 * (the reference is re-verified server-to-server and matched to our HMAC
	 * intent). We therefore: reject malformed references without any outbound
	 * call, rate-limit verification attempts per IP, and log repeated abuse.
	 */
	public function handle_webhook(): void {
		$reference = $this->reference_from_request();

		if ( '' !== $reference && $this->is_valid_reference( $reference ) ) {
			if ( $this->within_rate_limit() ) {
				( new BillingService() )->complete_from_reference( $reference );
			} else {
				$this->note_abuse( 'rate-limited' );
				status_header( 429 );
				echo 'Too Many Requests';
				exit;
			}
		} elseif ( '' !== $reference ) {
			// A non-empty but malformed reference is a strong abuse signal; never
			// make an outbound call for it.
			$this->note_abuse( 'invalid-reference' );
		}

		// Always acknowledge so the gateway does not retry indefinitely; the
		// real success/failure is determined by the server-to-server verify.
		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * Whether a reference looks like a legitimate Cardcom Low Profile id
	 * (GUID-ish: letters, digits and dashes, bounded length).
	 */
	private function is_valid_reference( string $reference ): bool {
		return strlen( $reference ) >= 8
			&& strlen( $reference ) <= self::REF_MAX_LEN
			&& 1 === preg_match( '/^[A-Za-z0-9\-]+$/', $reference );
	}

	/**
	 * Lightweight per-IP rate limit on verification attempts, backed by a
	 * transient counter. Returns false once the cap for the window is hit.
	 */
	private function within_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'comsign_cc_wh_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Record a webhook abuse signal (rate-limit hit / malformed reference) so a
	 * site owner can spot a pattern. Kept to error_log to avoid a noisy table.
	 */
	private function note_abuse( string $reason ): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'comsign_cc_wh_bad_' . md5( $ip );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, self::RATE_WINDOW );
		if ( $n <= 3 || 0 === $n % 25 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'ComSign: Cardcom webhook %s from %s (%d in window).', $reason, $ip, $n ) );
		}
	}

	/**
	 * Extract the Low Profile reference from a form-encoded or JSON body.
	 */
	private function reference_from_request(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'LowProfileId', 'lowprofilecode', 'low_profile_id' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( is_array( $json ) ) {
			foreach ( array( 'LowProfileId', 'lowprofilecode' ) as $key ) {
				if ( ! empty( $json[ $key ] ) ) {
					return sanitize_text_field( (string) $json[ $key ] );
				}
			}
		}
		return '';
	}
}
