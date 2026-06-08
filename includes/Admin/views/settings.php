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
		<h2><?php esc_html_e( 'Branding', 'comsign' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Shown to signers on the signing page and in emails.', 'comsign' ); ?></p>

		<p>
			<label for="comsign-brand-name"><strong><?php esc_html_e( 'Brand name', 'comsign' ); ?></strong></label><br>
			<input type="text" id="comsign-brand-name" name="brand_name" class="regular-text" value="<?php echo esc_attr( (string) ( $settings['brand_name'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		</p>

		<p>
			<label for="comsign-brand-logo"><strong><?php esc_html_e( 'Logo URL', 'comsign' ); ?></strong></label><br>
			<input type="url" id="comsign-brand-logo" name="brand_logo_url" class="regular-text" value="<?php echo esc_attr( (string) ( $settings['brand_logo_url'] ?? '' ) ); ?>" placeholder="https://example.com/logo.png">
			<button type="button" class="button" id="comsign-pick-logo"><?php esc_html_e( 'Select image', 'comsign' ); ?></button>
		</p>
		<?php if ( ! empty( $settings['brand_logo_url'] ) ) : ?>
			<p><img src="<?php echo esc_url( (string) $settings['brand_logo_url'] ); ?>" alt="" style="max-height:48px;max-width:240px;"></p>
		<?php endif; ?>

		<p>
			<label for="comsign-brand-color"><strong><?php esc_html_e( 'Accent colour', 'comsign' ); ?></strong></label><br>
			<input type="color" id="comsign-brand-color" name="brand_color" value="<?php echo esc_attr( (string) ( $settings['brand_color'] ?? '' ) ?: '#0b3d91' ); ?>">
		</p>

		<script>
		( function () {
			var btn = document.getElementById( 'comsign-pick-logo' );
			if ( ! btn || ! window.wp || ! window.wp.media ) { return; }
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var frame = window.wp.media( { multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					document.getElementById( 'comsign-brand-logo' ).value = att.url;
				} );
				frame.open();
			} );
		} )();
		</script>

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

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="comsign_save_tsa">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $tsa_nonce ); ?>">
				<p>
					<label for="comsign-tsa-url"><strong><?php esc_html_e( 'Timestamp authority (TSA) URL', 'comsign' ); ?></strong></label><br>
					<input type="url" id="comsign-tsa-url" name="tsa_url" class="regular-text" value="<?php echo esc_attr( $pki_tsa ); ?>" placeholder="https://freetsa.org/tsr">
				</p>
				<p class="description"><?php esc_html_e( 'Optional. An RFC-3161 timestamp proves when each document was signed (PAdES-T), giving the signature long-term validity. Leave empty to disable.', 'comsign' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save timestamp settings', 'comsign' ); ?></button></p>
			</form>

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

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Billing (Cardcom)', 'comsign' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Connect your Cardcom terminal to charge subscriptions. The API password is stored encrypted.', 'comsign' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="comsign_save_cardcom">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $cardcom_nonce ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="comsign-cc-terminal"><?php esc_html_e( 'Terminal number', 'comsign' ); ?></label></th>
					<td><input type="number" id="comsign-cc-terminal" name="terminal" value="<?php echo esc_attr( (string) $cardcom['terminal'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="comsign-cc-apiname"><?php esc_html_e( 'API name', 'comsign' ); ?></label></th>
					<td><input type="text" id="comsign-cc-apiname" name="api_name" value="<?php echo esc_attr( $cardcom['api_name'] ); ?>" class="regular-text" autocomplete="off"></td>
				</tr>
				<tr>
					<th scope="row"><label for="comsign-cc-apipass"><?php esc_html_e( 'API password', 'comsign' ); ?></label></th>
					<td>
						<input type="password" id="comsign-cc-apipass" name="api_password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $cardcom_set ? esc_attr__( '•••••••• (unchanged)', 'comsign' ) : ''; ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current password.', 'comsign' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test mode', 'comsign' ); ?></th>
					<td><label><input type="checkbox" name="test_mode" value="1" <?php checked( $cardcom['test_mode'] ); ?>> <?php esc_html_e( 'Use a test terminal', 'comsign' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'comsign' ); ?></th>
					<td>
						<code><?php echo esc_html( $cardcom_webhook ); ?></code>
						<p class="description"><?php esc_html_e( 'Set this as the indicator/webhook URL in your Cardcom terminal.', 'comsign' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save billing settings', 'comsign' ); ?></button></p>
		</form>
	</div>

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Shortcodes &amp; links', 'comsign' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Paste these shortcodes into any page or post. The links are the public entry points for your customers.', 'comsign' ); ?></p>

		<?php
		$cs_shortcodes = array(
			array(
				'code' => '[comsign_pricing]',
				'desc' => __( 'Public pricing table — shows your plans (price, limits, features) with a “start free trial” button that signs the visitor up and gives them a workspace + trial. Put it on your pricing/landing page.', 'comsign' ),
			),
			array(
				'code' => '[comsign_verify]',
				'desc' => __( 'Document verification form — lets anyone confirm a signed document is authentic using its ID and verification code (SHA-256), and see who signed it.', 'comsign' ),
			),
		);
		$cs_links = array(
			array(
				'label' => __( 'Client portal', 'comsign' ),
				'url'   => home_url( '/comsign/app' ),
				'desc'  => __( 'Where signed-in customers manage, create, send and track their documents (no WP-Admin needed).', 'comsign' ),
			),
			array(
				'label' => __( 'Verification page', 'comsign' ),
				'url'   => home_url( '/comsign/verify' ),
				'desc'  => __( 'Standalone public page to verify a document (same as the [comsign_verify] shortcode).', 'comsign' ),
			),
		);
		?>

		<table class="widefat striped" style="margin-bottom:16px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it does', 'comsign' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cs_shortcodes as $sc ) : ?>
					<tr>
						<td><code><input type="text" readonly value="<?php echo esc_attr( $sc['code'] ); ?>" onclick="this.select()" style="width:170px;font-family:monospace;"></code></td>
						<td><?php echo esc_html( $sc['desc'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Link', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Address', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it does', 'comsign' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cs_links as $ln ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $ln['label'] ); ?></strong></td>
						<td><a href="<?php echo esc_url( $ln['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ln['url'] ); ?></a></td>
						<td><?php echo esc_html( $ln['desc'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td><strong><?php esc_html_e( 'Cardcom webhook', 'comsign' ); ?></strong></td>
					<td><code><?php echo esc_html( $cardcom_webhook ); ?></code></td>
					<td><?php esc_html_e( 'Set this as the webhook/indicator URL in your Cardcom terminal so payments activate subscriptions.', 'comsign' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
