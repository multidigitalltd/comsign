<?php
/**
 * Per-workspace outgoing webhooks.
 *
 * @package ComSign
 */

namespace ComSign\Integrations;

defined( 'ABSPATH' ) || exit;

use ComSign\Audit\AuditLogger;
use ComSign\Billing\Plans;
use ComSign\Database\AccountRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Frontend\SignedDownloadController;
use ComSign\Services\SubscriptionService;

/**
 * Delivers signature events to each workspace's own webhook URL (configured in
 * the portal), so a customer can wire their own automation. On completion the
 * payload includes a direct, tokenised download link to the signed PDF.
 *
 * Only fires for workspaces whose plan grants the webhooks feature, and reuses
 * the same SSRF guard as the site-wide webhook.
 */
final class AccountWebhooks {

	/** Events worth notifying a workspace about. */
	private const EVENTS = array( AuditLogger::EVENT_SIGNED, AuditLogger::EVENT_COMPLETED, AuditLogger::EVENT_DECLINED );

	public function register(): void {
		add_action( 'comsign_event', array( $this, 'deliver' ), 20, 4 );
	}

	/**
	 * @param string $event       Event slug.
	 * @param int    $document_id Document id.
	 * @param int    $signer_id   Signer id.
	 * @param array  $meta        Extra context.
	 */
	public function deliver( string $event, int $document_id, int $signer_id, array $meta ): void {
		if ( ! in_array( $event, self::EVENTS, true ) || $document_id <= 0 ) {
			return;
		}

		$document = ( new DocumentRepository() )->find( $document_id );
		if ( ! $document ) {
			return;
		}
		$account_id = (int) $document->account_id;
		$account    = $account_id ? ( new AccountRepository() )->find( $account_id ) : null;
		if ( ! $account || empty( $account->webhook_url ) ) {
			return;
		}

		// Gate on the plan feature, and on the SSRF guard.
		if ( ! ( new SubscriptionService() )->has_feature( $account_id, Plans::FEATURE_WEBHOOKS ) ) {
			return;
		}
		$url = (string) $account->webhook_url;
		if ( ! Webhooks::is_safe_url( $url ) ) {
			return;
		}

		$body = array(
			'event'       => $event,
			'document_id' => $document_id,
			'signer_id'   => $signer_id,
			'title'       => (string) $document->title,
			'status'      => (string) $document->status,
			'meta'        => $meta,
			'site'        => home_url(),
			'timestamp'   => time(),
		);
		// A direct download link to the signed PDF once the document is complete.
		if ( AuditLogger::EVENT_COMPLETED === $event && ! empty( $document->signed_hash ) ) {
			$body['download_url'] = SignedDownloadController::url( $document_id, (string) $document->signed_hash );
		}

		$payload   = (string) wp_json_encode( $body );
		$secret    = (string) $account->webhook_secret;
		$signature = '' !== $secret ? hash_hmac( 'sha256', $payload, $secret ) : '';

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
