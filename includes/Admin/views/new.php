<?php
/**
 * Upload-new-document view.
 *
 * @package ComSign
 *
 * @var string     $action_url
 * @var string     $nonce
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Add Document', 'comsign' ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="comsign-card">
		<input type="hidden" name="action" value="comsign_create_document">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

		<p>
			<label for="comsign-title"><strong><?php esc_html_e( 'Document title', 'comsign' ); ?></strong></label><br>
			<input type="text" id="comsign-title" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Service agreement', 'comsign' ); ?>">
		</p>

		<p>
			<label for="comsign-file"><strong><?php esc_html_e( 'PDF file', 'comsign' ); ?></strong></label><br>
			<input type="file" id="comsign-file" name="document" accept="application/pdf" required>
			<span class="description"><?php esc_html_e( 'PDF only, up to 25 MB.', 'comsign' ); ?></span>
		</p>

		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & continue', 'comsign' ); ?></button>
		</p>
	</form>
</div>
