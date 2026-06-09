<?php
/**
 * Portal: build-your-own document editor (manage signers + place fields).
 *
 * Reuses the same pdf.js field-placement editor as WP-Admin (admin-editor.js +
 * editor.css), wired to RBAC-checked portal endpoints.
 *
 * @package ComSign
 *
 * @var string   $page_title
 * @var array    $nav
 * @var object   $document
 * @var array    $signers
 * @var array    $fields_for_js
 * @var array    $signers_for_js
 * @var array    $readiness
 * @var array|null $switcher
 * @var string   $action      admin-post.php URL.
 * @var string   $pdf_url      Nonced source-PDF stream for pdf.js.
 * @var string   $worker_src
 * @var string   $pdfjs_src
 * @var string   $editor_src
 * @var string   $editor_css
 * @var array    $nonces
 */

defined( 'ABSPATH' ) || exit;

$doc_id   = (int) $document->id;
$has_signers = ! empty( $signers );

$editor_cfg = array(
	'workerSrc' => $worker_src,
	'pdfUrl'    => $pdf_url,
	'i18n'      => array(
		'signature'      => __( 'Signature', 'comsign' ),
		'initials'       => __( 'Initials', 'comsign' ),
		'date'           => __( 'Date', 'comsign' ),
		'name'           => __( 'Name', 'comsign' ),
		'email'          => __( 'Email', 'comsign' ),
		'text'           => __( 'Text', 'comsign' ),
		'number'         => __( 'Number', 'comsign' ),
		'checkbox'       => __( 'Checkbox', 'comsign' ),
		'choice'         => __( 'Choice', 'comsign' ),
		'attachment'     => __( 'File', 'comsign' ),
		'remove'         => __( 'Remove', 'comsign' ),
		'choicePrompt'   => __( 'Enter options separated by commas:', 'comsign' ),
		'labelPrompt'    => __( 'Field label shown to the signer (optional):', 'comsign' ),
		'helpPrompt'     => __( 'Short help text under the field (optional):', 'comsign' ),
		'toggleRequired' => __( 'Click to toggle required', 'comsign' ),
		'loading'        => __( 'Loading document…', 'comsign' ),
		'loadError'      => __( 'Could not load the document preview.', 'comsign' ),
	),
);

require __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="<?php echo esc_url( $editor_css . '?ver=' . COMSIGN_VERSION ); ?>">
<?php
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<p><a href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'documents' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to documents', 'comsign' ); ?></a></p>

		<h1><?php echo esc_html( $document->title ? $document->title : __( '(untitled)', 'comsign' ) ); ?></h1>

		<ol class="comsign-steps" aria-label="<?php esc_attr_e( 'How it works', 'comsign' ); ?>">
			<li><span class="comsign-step-n">1</span><?php esc_html_e( 'Add the document', 'comsign' ); ?></li>
			<li class="is-current"><span class="comsign-step-n">2</span><?php esc_html_e( 'Add signers', 'comsign' ); ?></li>
			<li class="is-current"><span class="comsign-step-n">3</span><?php esc_html_e( 'Place fields', 'comsign' ); ?></li>
			<li><span class="comsign-step-n">4</span><?php esc_html_e( 'Review &amp; send', 'comsign' ); ?></li>
		</ol>

		<div class="comsign-editor-grid">

			<section class="comsign-editor-side" aria-labelledby="comsign-signers-h">
				<h2 id="comsign-signers-h"><?php esc_html_e( 'Signers', 'comsign' ); ?></h2>

				<?php if ( ! $has_signers ) : ?>
					<p class="comsign-empty"><?php esc_html_e( 'Add at least one signer to start placing fields.', 'comsign' ); ?></p>
				<?php else : ?>
					<ul class="comsign-editor-signers">
						<?php foreach ( $signers as $signer ) : ?>
							<li>
								<span>
									<strong><?php echo esc_html( $signer->name ? $signer->name : '—' ); ?></strong><br>
									<span class="comsign-template-meta"><?php echo esc_html( $signer->email ); ?></span>
								</span>
								<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this signer?', 'comsign' ) ); ?>');">
									<input type="hidden" name="action" value="comsign_portal_delete_signer">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['delete_signer'] ); ?>">
									<input type="hidden" name="document_id" value="<?php echo $doc_id; ?>">
									<input type="hidden" name="signer_id" value="<?php echo (int) $signer->id; ?>">
									<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Remove', 'comsign' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php require __DIR__ . '/partials/contacts-datalist.php'; ?>
				<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-recipient">
					<input type="hidden" name="action" value="comsign_portal_add_signer">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['add_signer'] ); ?>">
					<input type="hidden" name="document_id" value="<?php echo $doc_id; ?>">
					<p>
						<label for="comsign-new-name"><?php esc_html_e( 'Full name', 'comsign' ); ?></label>
						<input type="text" id="comsign-new-name" name="name" list="comsign-contact-names" autocomplete="off" required>
					</p>
					<p>
						<label for="comsign-new-email"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
						<input type="email" id="comsign-new-email" name="email" list="comsign-contact-emails" autocomplete="off" required>
					</p>
					<p>
						<label for="comsign-new-phone"><?php esc_html_e( 'Phone (optional)', 'comsign' ); ?></label>
						<input type="text" id="comsign-new-phone" name="phone">
					</p>
					<p><button type="submit" class="comsign-btn"><?php esc_html_e( 'Add signer', 'comsign' ); ?></button></p>
				</form>

				<div class="comsign-readiness <?php echo $readiness['ready'] ? 'is-ready' : 'is-blocked'; ?>">
					<p class="comsign-readiness-title">
						<?php echo $readiness['ready'] ? esc_html__( 'Ready to send', 'comsign' ) : esc_html__( 'Before you can send:', 'comsign' ); ?>
					</p>
					<ul>
						<?php foreach ( $readiness['items'] as $item ) : ?>
							<li class="<?php echo $item['ok'] ? 'ok' : 'todo'; ?>">
								<span class="comsign-readiness-mark" aria-hidden="true"><?php echo $item['ok'] ? '✓' : '○'; ?></span>
								<span class="comsign-sr-only"><?php echo $item['ok'] ? esc_html__( 'Done:', 'comsign' ) : esc_html__( 'To do:', 'comsign' ); ?></span>
								<?php echo esc_html( $item['label'] ); ?>
								<?php if ( ! $item['ok'] ) : ?>
									<span class="comsign-readiness-hint"><?php echo esc_html( $item['hint'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $readiness['ready'] ) : ?>
						<form method="post" action="<?php echo esc_url( $action ); ?>">
							<input type="hidden" name="action" value="comsign_portal_create">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['send'] ); ?>">
							<input type="hidden" name="document_id" value="<?php echo $doc_id; ?>">
							<input type="hidden" name="send" value="1">
							<button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Send for signing', 'comsign' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</section>

			<section class="comsign-editor-main" aria-labelledby="comsign-fields-h">
				<h2 id="comsign-fields-h"><?php esc_html_e( 'Place fields', 'comsign' ); ?></h2>

				<?php if ( ! $has_signers ) : ?>
					<p class="comsign-empty"><?php esc_html_e( 'Add a signer first, then drag fields onto the document.', 'comsign' ); ?></p>
				<?php else : ?>
					<div class="comsign-editor-toolbar">
						<label for="comsign-active-signer"><?php esc_html_e( 'For signer:', 'comsign' ); ?></label>
						<select id="comsign-active-signer">
							<?php foreach ( $signers as $signer ) : ?>
								<option value="<?php echo (int) $signer->id; ?>"><?php echo esc_html( $signer->name ? $signer->name : $signer->email ); ?></option>
							<?php endforeach; ?>
						</select>

						<label for="comsign-field-type"><?php esc_html_e( 'Field:', 'comsign' ); ?></label>
						<select id="comsign-field-type">
							<option value="signature"><?php esc_html_e( 'Signature', 'comsign' ); ?></option>
							<option value="initials"><?php esc_html_e( 'Initials', 'comsign' ); ?></option>
							<option value="date"><?php esc_html_e( 'Date (auto)', 'comsign' ); ?></option>
							<option value="name"><?php esc_html_e( 'Name (auto)', 'comsign' ); ?></option>
							<option value="email"><?php esc_html_e( 'Email (auto)', 'comsign' ); ?></option>
							<option value="text"><?php esc_html_e( 'Text', 'comsign' ); ?></option>
							<option value="number"><?php esc_html_e( 'Number', 'comsign' ); ?></option>
							<option value="checkbox"><?php esc_html_e( 'Checkbox', 'comsign' ); ?></option>
							<option value="choice"><?php esc_html_e( 'Choice (dropdown)', 'comsign' ); ?></option>
							<option value="attachment"><?php esc_html_e( 'File upload', 'comsign' ); ?></option>
						</select>
						<label class="comsign-required-toggle"><input type="checkbox" id="comsign-field-required"> <?php esc_html_e( 'Required', 'comsign' ); ?></label>
						<button type="button" class="comsign-btn comsign-btn--small" id="comsign-add-field"><?php esc_html_e( 'Add field', 'comsign' ); ?></button>
					</div>
					<p class="comsign-template-meta"><?php esc_html_e( 'Drag to reposition; double-click to remove; click a field label to toggle “required”.', 'comsign' ); ?></p>

					<div id="comsign-pdf" class="comsign-pdf" aria-live="polite"></div>

					<form method="post" action="<?php echo esc_url( $action ); ?>">
						<input type="hidden" name="action" value="comsign_portal_save_fields">
						<input type="hidden" name="document_id" value="<?php echo $doc_id; ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['save_fields'] ); ?>">
						<input type="hidden" name="fields" id="comsign-fields-input" value="">
						<p><button type="submit" class="comsign-btn comsign-btn--primary" id="comsign-save-fields"><?php esc_html_e( 'Save fields', 'comsign' ); ?></button></p>
					</form>

					<script type="application/json" id="comsign-existing-fields"><?php echo wp_json_encode( $fields_for_js ); ?></script>
					<script type="application/json" id="comsign-signers"><?php echo wp_json_encode( $signers_for_js ); ?></script>
				<?php endif; ?>
			</section>
		</div>
	</main>
<?php if ( $has_signers ) : ?>
	<script>window.ComSignEditor = <?php echo wp_json_encode( $editor_cfg ); ?>;</script>
	<script src="<?php echo esc_url( $pdfjs_src . '?ver=3.11.174' ); ?>"></script>
	<script src="<?php echo esc_url( $editor_src . '?ver=' . COMSIGN_VERSION ); ?>"></script>
<?php endif; ?>
<?php
require __DIR__ . '/partials/footer.php';
