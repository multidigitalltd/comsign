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
	<main id="comsign-main" tabindex="-1" class="comsign-sign">
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

			<div class="comsign-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Signature method', 'comsign' ); ?>">
				<button type="button" id="comsign-tab-draw" class="comsign-tab is-active" data-tab="draw" role="tab" aria-selected="true" aria-controls="comsign-panel-draw"><?php esc_html_e( 'Draw', 'comsign' ); ?></button>
				<button type="button" id="comsign-tab-type" class="comsign-tab" data-tab="type" role="tab" aria-selected="false" aria-controls="comsign-panel-type" tabindex="-1"><?php esc_html_e( 'Type', 'comsign' ); ?></button>
			</div>

			<div class="comsign-tab-panel" data-panel="draw" id="comsign-panel-draw" role="tabpanel" aria-labelledby="comsign-tab-draw" tabindex="0">
				<canvas id="comsign-canvas" class="comsign-canvas" width="600" height="200" role="img" aria-label="<?php esc_attr_e( 'Drawing area for your signature', 'comsign' ); ?>"></canvas>
				<p><button type="button" class="comsign-link" id="comsign-clear"><?php esc_html_e( 'Clear', 'comsign' ); ?></button></p>
			</div>

			<div class="comsign-tab-panel is-hidden" data-panel="type" id="comsign-panel-type" role="tabpanel" aria-labelledby="comsign-tab-type" tabindex="0">
				<label class="comsign-sr-only" for="comsign-type-input"><?php esc_html_e( 'Type your full name', 'comsign' ); ?></label>
				<input type="text" id="comsign-type-input" class="comsign-input" placeholder="<?php esc_attr_e( 'Type your full name', 'comsign' ); ?>" autocomplete="off">
				<canvas id="comsign-type-canvas" class="comsign-canvas" width="600" height="200" role="img" aria-label="<?php esc_attr_e( 'Preview of your typed signature', 'comsign' ); ?>"></canvas>
			</div>
		</section>
		<?php endif; ?>

		<?php
		$has_uploads = false;
		foreach ( $input_fields as $f ) {
			if ( \ComSign\Database\FieldRepository::TYPE_ATTACHMENT === $f->type ) {
				$has_uploads = true;
				break;
			}
		}
		?>
		<form id="comsign-sign-form" method="post" action="<?php echo esc_url( $post_url ); ?>" class="comsign-card" data-needs-signature="<?php echo $needs_signature ? '1' : '0'; ?>"<?php echo $has_uploads ? ' enctype="multipart/form-data"' : ''; ?>>
			<input type="hidden" name="action" value="comsign_sign_submit">
			<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="signature" id="comsign-signature-data" value="">

			<?php if ( ! empty( $input_fields ) ) : ?>
				<div class="comsign-fill-fields">
					<h2><?php esc_html_e( 'Fill in the details below', 'comsign' ); ?></h2>
					<p class="comsign-required-legend"><?php esc_html_e( '* Required', 'comsign' ); ?></p>
					<?php
					$fr = '\ComSign\Database\FieldRepository';
					foreach ( $input_fields as $field ) :
						$fid     = 'comsign-field-' . (int) $field->id;
						$name    = 'fields[' . (int) $field->id . ']';
						$req     = ! empty( $field->required );
						$caption = $fr::display_label( $field );
						$help    = isset( $field->help_text ) ? trim( (string) $field->help_text ) : '';
						$help_id = '' !== $help ? $fid . '-help' : '';
						$describe = '' !== $help_id ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
						?>
						<div class="comsign-field-row<?php echo $req ? ' comsign-required' : ''; ?>" data-required="<?php echo $req ? '1' : '0'; ?>">
						<?php if ( $fr::TYPE_CHECKBOX === $field->type ) : ?>
							<label class="comsign-check" for="<?php echo esc_attr( $fid ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $describe; ?><?php echo $req ? ' required' : ''; ?>>
								<span><?php echo esc_html( $caption ); ?><?php echo $req ? ' <span class="comsign-req">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							</label>
						<?php else : ?>
							<label class="comsign-field-caption" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $caption ); ?><?php echo $req ? ' <span class="comsign-req">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
							<?php if ( $fr::TYPE_CHOICE === $field->type ) : ?>
								<select id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" class="comsign-input"<?php echo $describe; ?><?php echo $req ? ' required' : ''; ?>>
									<option value=""><?php esc_html_e( 'Choose…', 'comsign' ); ?></option>
									<?php foreach ( $fr::decode_options( $field ) as $opt ) : ?>
										<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( $fr::TYPE_ATTACHMENT === $field->type ) : ?>
								<input type="file" id="<?php echo esc_attr( $fid ); ?>" name="attachments[<?php echo (int) $field->id; ?>]" class="comsign-file" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.heic,.doc,.docx,.txt"<?php echo $describe; ?><?php echo $req ? ' required' : ''; ?>>
							<?php elseif ( $fr::TYPE_NUMBER === $field->type ) : ?>
								<input type="number" inputmode="decimal" step="any" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" class="comsign-input"<?php echo $describe; ?><?php echo $req ? ' required' : ''; ?>>
							<?php else : ?>
								<input type="text" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>" class="comsign-input"<?php echo $describe; ?><?php echo $req ? ' required' : ''; ?>>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( '' !== $help ) : ?>
							<p class="comsign-field-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
						<?php endif; ?>
						<p class="comsign-field-error is-hidden"><?php esc_html_e( 'This field is required.', 'comsign' ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<label class="comsign-consent" for="comsign-consent">
				<input type="checkbox" name="consent" value="1" id="comsign-consent" required>
				<span><?php esc_html_e( 'I have read the document and I agree to sign it electronically.', 'comsign' ); ?></span>
			</label>
			<details class="comsign-legal">
				<summary><?php esc_html_e( 'More information', 'comsign' ); ?></summary>
				<p><?php esc_html_e( 'My electronic signature is legally binding and has the same effect as a handwritten signature. The time, my IP address and browser are recorded as proof of signing.', 'comsign' ); ?></p>
			</details>

			<p class="comsign-error is-hidden" id="comsign-error"><?php esc_html_e( 'Please complete the highlighted items: add your signature, fill the required fields and accept the agreement.', 'comsign' ); ?></p>

			<div class="comsign-actions">
				<button type="submit" class="comsign-btn comsign-btn--primary" id="comsign-submit"><?php esc_html_e( 'Sign & finish', 'comsign' ); ?></button>
				<button type="button" class="comsign-btn comsign-btn--ghost" id="comsign-decline-toggle"><?php esc_html_e( 'Decline to sign', 'comsign' ); ?></button>
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
