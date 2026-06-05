<?php
/**
 * "Bulk send" from a single-role template.
 *
 * @package ComSign
 *
 * @var object     $template
 * @var array      $roles
 * @var string     $action_url
 * @var string     $nonce
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1><?php echo esc_html( sprintf( /* translators: %s: template name. */ __( 'Bulk send: %s', 'comsign' ), $template->name ) ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-card">
		<input type="hidden" name="action" value="comsign_bulk_template">
		<input type="hidden" name="template_id" value="<?php echo esc_attr( (int) $template->id ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

		<p class="description"><?php esc_html_e( 'Each line creates and sends a separate document. Format: name, email, phone (phone optional).', 'comsign' ); ?></p>
		<p>
			<textarea name="recipients_text" rows="8" class="large-text code" placeholder="<?php esc_attr_e( "Dana Cohen, dana@example.com, +972500000000\nAvi Levi, avi@example.com", 'comsign' ); ?>"></textarea>
		</p>

		<p>
			<label for="comsign-bulk-message"><strong><?php esc_html_e( 'Message to signers (optional)', 'comsign' ); ?></strong></label><br>
			<textarea id="comsign-bulk-message" name="message" rows="2" class="large-text"></textarea>
		</p>
		<p>
			<label for="comsign-bulk-expiry"><?php esc_html_e( 'Link expires after', 'comsign' ); ?></label>
			<input type="number" id="comsign-bulk-expiry" name="expiry_days" min="0" max="365" value="0" style="width:70px;">
			<?php esc_html_e( 'days (0 = never)', 'comsign' ); ?>
		</p>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create & send all', 'comsign' ); ?></button></p>
	</form>
</div>
