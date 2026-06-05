<?php
/**
 * ComSign settings view.
 *
 * @package ComSign
 *
 * @var string     $action_url
 * @var string     $nonce
 * @var array      $settings
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'ComSign Settings', 'comsign' ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-card">
		<input type="hidden" name="action" value="comsign_save_settings">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

		<h2><?php esc_html_e( 'Automatic reminders', 'comsign' ); ?></h2>

		<p>
			<label>
				<input type="checkbox" name="reminders_enabled" value="1" <?php checked( ! empty( $settings['reminders_enabled'] ) ); ?>>
				<?php esc_html_e( 'Email a reminder to signers who have not signed yet.', 'comsign' ); ?>
			</label>
		</p>

		<p>
			<label for="comsign-reminder-days"><?php esc_html_e( 'Remind every', 'comsign' ); ?></label>
			<input type="number" id="comsign-reminder-days" name="reminder_days" min="1" max="60" value="<?php echo esc_attr( (string) ( $settings['reminder_days'] ?? 3 ) ); ?>" style="width:70px;">
			<?php esc_html_e( 'days', 'comsign' ); ?>
		</p>

		<p class="description"><?php esc_html_e( 'Reminders run once a day via WP-Cron. Each reminder issues a fresh signing link.', 'comsign' ); ?></p>

		<hr>
		<h2><?php esc_html_e( 'Integrations', 'comsign' ); ?></h2>

		<p>
			<label for="comsign-webhook-url"><strong><?php esc_html_e( 'Webhook URL', 'comsign' ); ?></strong></label><br>
			<input type="url" id="comsign-webhook-url" name="webhook_url" class="regular-text" value="<?php echo esc_attr( (string) ( $settings['webhook_url'] ?? '' ) ); ?>" placeholder="https://example.com/hook">
			<br><span class="description"><?php esc_html_e( 'Every event is POSTed here as JSON, signed with X-ComSign-Signature (HMAC-SHA256 of the body using the secret below).', 'comsign' ); ?></span>
		</p>

		<?php if ( ! empty( $settings['api_key'] ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'REST API key', 'comsign' ); ?></strong>
				(<code><?php esc_html_e( 'X-ComSign-Key', 'comsign' ); ?></code>):<br>
				<input type="text" readonly class="regular-text" value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>" onclick="this.select();">
			</p>
			<p>
				<strong><?php esc_html_e( 'Webhook secret', 'comsign' ); ?>:</strong><br>
				<input type="text" readonly class="regular-text" value="<?php echo esc_attr( (string) $settings['webhook_secret'] ); ?>" onclick="this.select();">
			</p>
		<?php endif; ?>

		<p>
			<label>
				<input type="checkbox" name="regenerate_keys" value="1">
				<?php esc_html_e( 'Regenerate API key & webhook secret on save', 'comsign' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'REST base: /wp-json/comsign/v1/documents', 'comsign' ); ?></p>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'comsign' ); ?></button></p>
	</form>

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Digital signature (PKI / PAdES)', 'comsign' ); ?></h2>

		<?php if ( empty( $pki_available ) ) : ?>
			<p class="description"><?php esc_html_e( 'OpenSSL is not available on this server, so cryptographic signing cannot be enabled.', 'comsign' ); ?></p>
		<?php elseif ( ! empty( $pki_subject ) ) : ?>
			<p>
				<?php esc_html_e( 'Active certificate:', 'comsign' ); ?>
				<strong><?php echo esc_html( $pki_subject ); ?></strong>
			</p>
			<p class="description"><?php esc_html_e( 'Completed documents are signed with a cryptographic PKCS#7/PAdES signature.', 'comsign' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove the certificate and revert to electronic signatures?', 'comsign' ) ); ?>');">
				<input type="hidden" name="action" value="comsign_remove_certificate">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $remove_nonce ); ?>">
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove certificate', 'comsign' ); ?></button>
			</form>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Upload a PKCS#12 certificate (.p12/.pfx) to sign completed documents cryptographically (eIDAS / ComSign compatible). Without it, documents use electronic signatures.', 'comsign' ); ?></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="comsign_upload_certificate">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $cert_nonce ); ?>">
				<p><input type="file" name="certificate" accept=".p12,.pfx" required></p>
				<p><input type="password" name="cert_password" class="regular-text" placeholder="<?php esc_attr_e( 'Certificate password', 'comsign' ); ?>"></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload certificate', 'comsign' ); ?></button></p>
			</form>
		<?php endif; ?>
	</div>
</div>
