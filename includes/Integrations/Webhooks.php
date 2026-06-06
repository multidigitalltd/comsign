<?php
/**
 * Outgoing webhook delivery.
 *
 * @package ComSign
 */

namespace ComSign\Integrations;

defined( 'ABSPATH' ) || exit;

use ComSign\Support\Settings;

/**
 * Posts a JSON payload to a configured URL for every ComSign event, signed with
 * an HMAC-SHA256 header so the receiver can verify authenticity.
 */
final class Webhooks {

	/**
	 * Subscribe to the event stream.
	 */
	public function register(): void {
		add_action( 'comsign_event', array( $this, 'deliver' ), 10, 4 );
	}

	/**
	 * Deliver one event to the configured webhook URL.
	 *
	 * @param string $event       Event slug.
	 * @param int    $document_id Document id.
	 * @param int    $signer_id   Signer id.
	 * @param array  $meta        Extra context.
	 */
	public function deliver( string $event, int $document_id, int $signer_id, array $meta ): void {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url ) {
			return;
		}

		// SSRF guard: only http(s).
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return;
		}

		// Reject private / loopback / link-local / reserved targets explicitly.
		// (wp_http_validate_url() exempts the site's own host, which would let a
		// site hosted on a private IP reach internal services — so we check the
		// resolved IP ourselves as well.)
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( filter_var( $ip, FILTER_VALIDATE_IP )
			&& ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return;
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'event'       => $event,
				'document_id' => $document_id,
				'signer_id'   => $signer_id,
				'meta'        => $meta,
				'site'        => home_url(),
				'timestamp'   => time(),
			)
		);

		$secret    = (string) Settings::get( 'webhook_secret' );
		$signature = $secret ? hash_hmac( 'sha256', (string) $payload, $secret ) : '';

		// Fire-and-forget; never block the signing flow on a slow endpoint.
		wp_remote_post(
			$url,
			array(
				'timeout'   => 5,
				'blocking'  => false,
				'headers'   => array(
					'Content-Type'        => 'application/json',
					'X-ComSign-Event'     => $event,
					'X-ComSign-Signature' => $signature,
				),
				'body'      => $payload,
				'sslverify' => true,
			)
		);
	}

	/**
	 * Send a blocking test delivery and report the outcome (for the health page).
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function send_test(): array {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url ) {
			return array(
				'ok'      => false,
				'message' => __( 'No webhook URL is configured.', 'comsign' ),
			);
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ip     = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		$blocked = filter_var( $ip, FILTER_VALIDATE_IP )
			&& ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || $blocked || ! wp_http_validate_url( $url ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The webhook URL is invalid or points to a blocked (private/loopback) target.', 'comsign' ),
			);
		}

		$payload   = (string) wp_json_encode(
			array(
				'event'     => 'test',
				'site'      => home_url(),
				'timestamp' => time(),
			)
		);
		$secret    = (string) Settings::get( 'webhook_secret' );
		$signature = $secret ? hash_hmac( 'sha256', $payload, $secret ) : '';

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 10,
				'blocking'  => true,
				'headers'   => array(
					'Content-Type'        => 'application/json',
					'X-ComSign-Event'     => 'test',
					'X-ComSign-Signature' => $signature,
				),
				'body'      => $payload,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				// translators: %s: error message.
				'message' => sprintf( __( 'Delivery failed: %s', 'comsign' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => $code >= 200 && $code < 400,
			// translators: %d: HTTP status code.
			'message' => sprintf( __( 'Endpoint responded with HTTP %d.', 'comsign' ), $code ),
		);
	}
}
