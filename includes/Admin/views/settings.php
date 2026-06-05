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
</div>
