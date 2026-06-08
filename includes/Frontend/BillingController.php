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
 * Receives Cardcom's server-to-server result notification. The webhook is not
 * trusted on its face: it only carries a transaction reference, which
 * {@see BillingService::complete_from_reference()} re-verifies directly with
 * Cardcom (using our credentials) and against our HMAC-signed return value
 * before any subscription is activated. A forged call therefore does nothing.
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

	/**
	 * Handle the Cardcom result notification.
	 */
	public function handle_webhook(): void {
		$reference = $this->reference_from_request();
		if ( '' !== $reference ) {
			( new BillingService() )->complete_from_reference( $reference );
		}

		// Always acknowledge so the gateway does not retry indefinitely; the
		// real success/failure is determined by the server-to-server verify.
		status_header( 200 );
		echo 'OK';
		exit;
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
