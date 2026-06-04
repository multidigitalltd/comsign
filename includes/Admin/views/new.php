<?php
/**
 * Add-new-document view (upload a PDF, or compose one from text).
 *
 * @package ComSign
 *
 * @var string     $action_url
 * @var string     $nonce
 * @var string     $compose_nonce
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Add Document', 'comsign' ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<div class="comsign-tabs comsign-admin-tabs" role="tablist">
		<button type="button" class="comsign-tab is-active" data-tab="upload" role="tab"><?php esc_html_e( 'Upload a PDF', 'comsign' ); ?></button>
		<button type="button" class="comsign-tab" data-tab="compose" role="tab"><?php esc_html_e( 'Compose from text', 'comsign' ); ?></button>
	</div>

	<div class="comsign-tab-panel" data-panel="upload">
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
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & continue', 'comsign' ); ?></button></p>
		</form>
	</div>

	<div class="comsign-tab-panel is-hidden" data-panel="compose">
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-card">
			<input type="hidden" name="action" value="comsign_create_text">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $compose_nonce ); ?>">

			<p>
				<label for="comsign-compose-title"><strong><?php esc_html_e( 'Document title', 'comsign' ); ?></strong></label><br>
				<input type="text" id="comsign-compose-title" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Service agreement', 'comsign' ); ?>">
			</p>

			<p><strong><?php esc_html_e( 'Document content', 'comsign' ); ?></strong></p>
			<p class="description"><?php esc_html_e( 'Write the fixed text of the document. After creating it you can place fillable and signature fields on the generated PDF.', 'comsign' ); ?></p>
			<?php
			wp_editor(
				'',
				'comsign_content',
				array(
					'textarea_name' => 'content',
					'textarea_rows' => 14,
					'media_buttons' => false,
					'teeny'         => true,
				)
			);
			?>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create document', 'comsign' ); ?></button></p>
		</form>
	</div>
</div>
