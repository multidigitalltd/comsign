<?php
/**
 * "Use template" — assign a recipient to each role.
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
	<h1><?php echo esc_html( sprintf( /* translators: %s: template name. */ __( 'Use template: %s', 'comsign' ), $template->name ) ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-card">
		<input type="hidden" name="action" value="comsign_use_template">
		<input type="hidden" name="template_id" value="<?php echo esc_attr( (int) $template->id ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

		<p class="description"><?php esc_html_e( 'Assign a recipient to each role. The document is created as a draft so you can review it before sending.', 'comsign' ); ?></p>

		<?php foreach ( $roles as $index => $label ) : ?>
			<fieldset style="margin-bottom:16px;border:1px solid #dcdcde;padding:12px;border-radius:6px;">
				<legend><strong><?php echo esc_html( $label ); ?></strong></legend>
				<p><input type="text" name="recipients[<?php echo esc_attr( (int) $index ); ?>][name]" class="regular-text" placeholder="<?php esc_attr_e( 'Full name', 'comsign' ); ?>"></p>
				<p><input type="email" name="recipients[<?php echo esc_attr( (int) $index ); ?>][email]" class="regular-text" placeholder="<?php esc_attr_e( 'email@example.com', 'comsign' ); ?>"></p>
				<p><input type="tel" name="recipients[<?php echo esc_attr( (int) $index ); ?>][phone]" class="regular-text" placeholder="<?php esc_attr_e( 'Phone for WhatsApp (optional)', 'comsign' ); ?>"></p>
			</fieldset>
		<?php endforeach; ?>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create document', 'comsign' ); ?></button></p>
	</form>
</div>
