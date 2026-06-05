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
}
