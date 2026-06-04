<?php
/**
 * Standalone signing page.
 *
 * @package ComSign
 *
 * @var object $document
 * @var object $signer
 * @var string $raw_token
 * @var string $nonce
 * @var string $view_url
 * @var string $post_url
 */

defined( 'ABSPATH' ) || exit;

$page_title = sprintf(
	/* translators: %s: document title. */
	__( 'Sign: %s', 'comsign' ),
	$document->title
);
require __DIR__ . '/partials/header.php';
?>
	<main class="comsign-sign">
		<h1><?php echo esc_html( $document->title ?: __( 'Document', 'comsign' ) ); ?></h1>
		<p class="comsign-intro">
			<?php
			$who = $signer->name ? $signer->name : $signer->email;
			printf(
				/* translators: %s: signer name or email. */
				esc_html__( 'Hello %s, please review the document below and add your signature.', 'comsign' ),
				esc_html( $who )
			);
			?>
		</p>

		<div class="comsign-doc-viewer">
			<iframe src="<?php echo esc_url( $view_url ); ?>" title="<?php esc_attr_e( 'Document preview', 'comsign' ); ?>"></iframe>
		</div>

		<?php $needs_signature = ! empty( $needs_signature ); ?>

		<?php if ( $needs_signature ) : ?>
		<section class="comsign-card comsign-signature-pad" data-needs-signature="1">
			<h2><?php esc_html_e( 'Your signature', 'comsign' ); ?></h2>

			<div class="comsign-tabs" role="tablist">
				<button type="button" class="comsign-tab is-active" data-tab="draw" role="tab"><?php esc_html_e( 'Draw', 'comsign' ); ?></button>
				<button type="button" class="comsign-tab" data-tab="type" role="tab"><?php esc_html_e( 'Type', 'comsign' ); ?></button>
			</div>

			<div class="comsign-tab-panel" data-panel="draw">
				<canvas id="comsign-canvas" class="comsign-canvas" width="600" height="200"></canvas>
				<p><button type="button" class="comsign-link" id="comsign-clear"><?php esc_html_e( 'Clear', 'comsign' ); ?></button></p>
			</div>

			<div class="comsign-tab-panel is-hidden" data-panel="type">
				<input type="text" id="comsign-type-input" class="comsign-input" placeholder="<?php esc_attr_e( 'Type your full name', 'comsign' ); ?>" autocomplete="off">
				<canvas id="comsign-type-canvas" class="comsign-canvas" width="600" height="200"></canvas>
			</div>
		</section>
		<?php endif; ?>

		<form id="comsign-sign-form" method="post" action="<?php echo esc_url( $post_url ); ?>" class="comsign-card" data-needs-signature="<?php echo $needs_signature ? '1' : '0'; ?>">
			<input type="hidden" name="action" value="comsign_sign_submit">
			<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="signature" id="comsign-signature-data" value="">

			<?php if ( ! empty( $text_fields ) ) : ?>
				<div class="comsign-fill-fields">
					<h2><?php esc_html_e( 'Please fill in', 'comsign' ); ?></h2>
					<?php foreach ( $text_fields as $field ) : ?>
						<p>
							<input type="text" name="fields[<?php echo esc_attr( (int) $field->id ); ?>]" class="comsign-input" placeholder="<?php esc_attr_e( 'Your answer', 'comsign' ); ?>">
						</p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<label class="comsign-consent">
				<input type="checkbox" name="consent" value="1" id="comsign-consent" required>
				<span><?php esc_html_e( 'I agree to sign this document electronically, and I confirm that my electronic signature is legally binding.', 'comsign' ); ?></span>
			</label>

			<p class="comsign-error is-hidden" id="comsign-error"><?php esc_html_e( 'Please add your signature and accept the agreement.', 'comsign' ); ?></p>

			<div class="comsign-actions">
				<button type="submit" class="comsign-btn comsign-btn--primary" id="comsign-submit"><?php esc_html_e( 'Sign document', 'comsign' ); ?></button>
				<button type="button" class="comsign-btn comsign-btn--ghost" id="comsign-decline-toggle"><?php esc_html_e( 'Decline', 'comsign' ); ?></button>
			</div>
		</form>

		<form id="comsign-decline-form" method="post" action="<?php echo esc_url( $post_url ); ?>" class="comsign-card is-hidden">
			<input type="hidden" name="action" value="comsign_sign_decline">
			<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<p><label for="comsign-reason"><?php esc_html_e( 'Reason for declining (optional)', 'comsign' ); ?></label></p>
			<p><textarea name="reason" id="comsign-reason" class="comsign-input" rows="3"></textarea></p>
			<button type="submit" class="comsign-btn comsign-btn--danger"><?php esc_html_e( 'Confirm decline', 'comsign' ); ?></button>
		</form>
	</main>

	<script src="<?php echo esc_url( COMSIGN_PLUGIN_URL . 'assets/vendor/signature_pad/signature_pad.umd.min.js?ver=4.1.7' ); ?>"></script>
	<script src="<?php echo esc_url( COMSIGN_PLUGIN_URL . 'assets/js/signing.js?ver=' . COMSIGN_VERSION ); ?>"></script>
<?php
require __DIR__ . '/partials/footer.php';
