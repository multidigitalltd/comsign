<?php
/**
 * Portal: per-workspace integrations (outgoing webhook).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var string     $webhook_url
 * @var string     $secret
 * @var string     $action      admin-post.php URL.
 * @var string     $nonce
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Integrations', 'comsign' ); ?></h1>
		<p class="comsign-intro"><?php esc_html_e( 'Send signing events to your own systems. ComSign POSTs a JSON payload to your URL each time a document is signed, completed or declined — and on completion it includes a direct link to download the signed PDF.', 'comsign' ); ?></p>

		<section class="comsign-card" aria-labelledby="comsign-webhook-h">
			<h2 id="comsign-webhook-h"><?php esc_html_e( 'Outgoing webhook', 'comsign' ); ?></h2>

			<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-portal-form">
				<input type="hidden" name="action" value="comsign_portal_webhook_save">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<p>
					<label for="comsign-webhook-url"><?php esc_html_e( 'Webhook URL', 'comsign' ); ?></label>
					<input type="url" id="comsign-webhook-url" name="webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" placeholder="https://example.com/hooks/comsign" style="width:100%;max-width:520px;">
					<span class="comsign-template-meta"><?php esc_html_e( 'Must be a public https URL. Leave blank to disable.', 'comsign' ); ?></span>
				</p>

				<?php if ( '' !== $secret ) : ?>
					<p>
						<label for="comsign-webhook-secret"><?php esc_html_e( 'Signing secret', 'comsign' ); ?></label>
						<input type="text" id="comsign-webhook-secret" readonly value="<?php echo esc_attr( $secret ); ?>" onclick="this.select();" style="width:100%;max-width:520px;font-family:monospace;">
						<span class="comsign-template-meta"><?php esc_html_e( 'Each request is signed with HMAC-SHA256 of the body in the X-ComSign-Signature header. Verify it with this secret.', 'comsign' ); ?></span>
					</p>
				<?php endif; ?>

				<p><button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Save webhook', 'comsign' ); ?></button></p>
			</form>

			<details class="comsign-legal">
				<summary><?php esc_html_e( 'Example payload', 'comsign' ); ?></summary>
				<pre style="white-space:pre-wrap;overflow:auto;"><code>{
  "event": "completed",
  "document_id": 123,
  "signer_id": 456,
  "title": "Service agreement",
  "status": "completed",
  "download_url": "https://your-site/wp-admin/admin-post.php?action=comsign_signed_download&amp;doc=123&amp;sig=…",
  "site": "https://your-site",
  "timestamp": 1733740800
}</code></pre>
			</details>
		</section>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
