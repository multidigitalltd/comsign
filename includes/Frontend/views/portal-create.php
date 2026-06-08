<?php
/**
 * Portal: multi-step "new document" wizard.
 *
 * Step 1 chooses how to create the document (upload a PDF, compose from text, or
 * start from a template). The chosen path then collects what it needs:
 *  - upload   → a PDF file, then the manual field editor.
 *  - text     → a title + body + recipients (with the fields each one signs),
 *               which auto-places a signature block (no dragging).
 *  - template → recipients for the template's roles.
 *
 * @package ComSign
 *
 * @var string      $page_title
 * @var array       $nav
 * @var string      $type          '', 'upload', 'text' or 'template'.
 * @var object[]    $templates     Templates available to this user.
 * @var object|null $selected      Chosen template, or null.
 * @var string[]    $roles         Role labels of the chosen template.
 * @var string      $action        admin-post.php URL.
 * @var string      $nonce         create (template) nonce.
 * @var string      $upload_nonce  upload nonce.
 * @var string      $compose_nonce compose-from-text nonce.
 * @var array|null  $switcher
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Frontend\PortalController;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';

$cs_url = static fn( array $args = array() ) => PortalController::url( array_merge( array( 'view' => 'create' ), $args ) );
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'New document', 'comsign' ); ?></h1>

		<?php if ( '' !== $type ) : ?>
			<p><a href="<?php echo esc_url( $cs_url() ); ?>">&larr; <?php esc_html_e( 'Choose a different way to start', 'comsign' ); ?></a></p>
		<?php endif; ?>

		<?php if ( '' === $type ) : ?>

			<p class="comsign-intro"><?php esc_html_e( 'How would you like to create this document?', 'comsign' ); ?></p>
			<div class="comsign-choice-grid">
				<a class="comsign-choice" href="<?php echo esc_url( $cs_url( array( 'type' => 'text' ) ) ); ?>">
					<span class="comsign-choice-icon" aria-hidden="true">✍️</span>
					<span class="comsign-choice-title"><?php esc_html_e( 'Write text', 'comsign' ); ?></span>
					<span class="comsign-choice-desc"><?php esc_html_e( 'Type the document and pick which fields each signer fills. We place the signature block for you — no dragging.', 'comsign' ); ?></span>
				</a>
				<a class="comsign-choice" href="<?php echo esc_url( $cs_url( array( 'type' => 'upload' ) ) ); ?>">
					<span class="comsign-choice-icon" aria-hidden="true">📄</span>
					<span class="comsign-choice-title"><?php esc_html_e( 'Upload a PDF', 'comsign' ); ?></span>
					<span class="comsign-choice-desc"><?php esc_html_e( 'Upload a scanned or existing PDF and place the fields exactly where you want them.', 'comsign' ); ?></span>
				</a>
				<a class="comsign-choice<?php echo empty( $templates ) ? ' is-disabled' : ''; ?>" href="<?php echo esc_url( empty( $templates ) ? '#' : $cs_url( array( 'type' => 'template' ) ) ); ?>">
					<span class="comsign-choice-icon" aria-hidden="true">🗂️</span>
					<span class="comsign-choice-title"><?php esc_html_e( 'Use a template', 'comsign' ); ?></span>
					<span class="comsign-choice-desc">
						<?php
						echo empty( $templates )
							? esc_html__( 'No templates in this workspace yet.', 'comsign' )
							: esc_html__( 'Start from a saved template and just add recipients.', 'comsign' );
						?>
					</span>
				</a>
			</div>

		<?php elseif ( 'upload' === $type ) : ?>

			<section class="comsign-create-option" aria-labelledby="comsign-upload-h">
				<h2 id="comsign-upload-h"><?php esc_html_e( 'Upload a PDF', 'comsign' ); ?></h2>
				<p class="comsign-template-meta"><?php esc_html_e( 'Upload a PDF, add signers, and place the fields yourself.', 'comsign' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data" class="comsign-portal-form">
					<input type="hidden" name="action" value="comsign_portal_upload">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $upload_nonce ); ?>">
					<p>
						<label for="comsign-upload-title"><?php esc_html_e( 'Document title (optional)', 'comsign' ); ?></label>
						<input type="text" id="comsign-upload-title" name="title">
					</p>
					<p>
						<label for="comsign-upload-file"><?php esc_html_e( 'PDF file', 'comsign' ); ?></label>
						<input type="file" id="comsign-upload-file" name="document" accept="application/pdf" required>
					</p>
					<p><button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Upload &amp; continue', 'comsign' ); ?></button></p>
				</form>
			</section>

		<?php elseif ( 'text' === $type ) : ?>

			<section class="comsign-create-option" aria-labelledby="comsign-text-h">
				<h2 id="comsign-text-h"><?php esc_html_e( 'Write the document', 'comsign' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-portal-form" id="comsign-compose-form">
					<input type="hidden" name="action" value="comsign_portal_compose">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $compose_nonce ); ?>">

					<p>
						<label for="comsign-compose-title"><?php esc_html_e( 'Document title', 'comsign' ); ?></label>
						<input type="text" id="comsign-compose-title" name="title" placeholder="<?php esc_attr_e( 'e.g. Service agreement', 'comsign' ); ?>" required>
					</p>
					<p>
						<label for="comsign-compose-content"><?php esc_html_e( 'Document content', 'comsign' ); ?></label>
						<textarea id="comsign-compose-content" name="content" rows="12" required></textarea>
						<span class="comsign-template-meta"><?php esc_html_e( 'Basic formatting is allowed. A “Signatures” page is added automatically.', 'comsign' ); ?></span>
					</p>

					<h3><?php esc_html_e( 'Who needs to sign?', 'comsign' ); ?></h3>
					<?php require __DIR__ . '/partials/contacts-datalist.php'; ?>

					<div id="comsign-compose-recipients">
						<?php for ( $i = 0; $i < 1; $i++ ) : ?>
							<fieldset class="comsign-recipient">
								<legend><?php echo esc_html( sprintf( /* translators: %d: signer number. */ __( 'Signer %d', 'comsign' ), $i + 1 ) ); ?></legend>
								<p>
									<label for="rcpt-name-<?php echo (int) $i; ?>"><?php esc_html_e( 'Full name', 'comsign' ); ?></label>
									<input type="text" id="rcpt-name-<?php echo (int) $i; ?>" name="recipient[<?php echo (int) $i; ?>][name]" list="comsign-contact-names" autocomplete="off" required>
								</p>
								<p>
									<label for="rcpt-email-<?php echo (int) $i; ?>"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
									<input type="email" id="rcpt-email-<?php echo (int) $i; ?>" name="recipient[<?php echo (int) $i; ?>][email]" list="comsign-contact-emails" autocomplete="off" required>
								</p>
								<fieldset class="comsign-field-picker">
									<legend><?php esc_html_e( 'Fields to add for this signer', 'comsign' ); ?></legend>
									<label><input type="checkbox" name="recipient[<?php echo (int) $i; ?>][fields][]" value="signature" checked> <?php esc_html_e( 'Signature', 'comsign' ); ?></label>
									<label><input type="checkbox" name="recipient[<?php echo (int) $i; ?>][fields][]" value="name" checked> <?php esc_html_e( 'Full name', 'comsign' ); ?></label>
									<label><input type="checkbox" name="recipient[<?php echo (int) $i; ?>][fields][]" value="date" checked> <?php esc_html_e( 'Date signed', 'comsign' ); ?></label>
									<label><input type="checkbox" name="recipient[<?php echo (int) $i; ?>][fields][]" value="initials"> <?php esc_html_e( 'Initials', 'comsign' ); ?></label>
								</fieldset>
							</fieldset>
						<?php endfor; ?>
					</div>

					<p class="comsign-form-actions">
						<button type="submit" name="send" value="1" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Create &amp; send', 'comsign' ); ?></button>
						<button type="submit" name="send" value="0" class="comsign-btn"><?php esc_html_e( 'Save as draft', 'comsign' ); ?></button>
					</p>
				</form>
			</section>

		<?php elseif ( ! $selected ) : ?>

			<ol class="comsign-steps" aria-label="<?php esc_attr_e( 'How it works', 'comsign' ); ?>">
				<li class="is-current"><span class="comsign-step-n">1</span><?php esc_html_e( 'Choose a template', 'comsign' ); ?></li>
				<li><span class="comsign-step-n">2</span><?php esc_html_e( 'Add recipients', 'comsign' ); ?></li>
				<li><span class="comsign-step-n">3</span><?php esc_html_e( 'Send', 'comsign' ); ?></li>
			</ol>

			<?php if ( empty( $templates ) ) : ?>
				<p class="comsign-empty"><?php esc_html_e( 'There are no templates in this workspace yet. An administrator can create one from any document.', 'comsign' ); ?></p>
			<?php else : ?>
				<ul class="comsign-template-list">
					<?php foreach ( $templates as $template ) : ?>
						<?php $count = count( \ComSign\Database\TemplateRepository::roles( $template ) ); ?>
						<li>
							<div class="comsign-template-info">
								<span class="comsign-template-name"><?php echo esc_html( $template->name ); ?></span>
								<span class="comsign-template-meta">
									<?php
									/* translators: %d: number of signing roles. */
									echo esc_html( sprintf( __( 'Signers: %d', 'comsign' ), $count ) );
									?>
								</span>
							</div>
							<a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( $cs_url( array( 'template' => (int) $template->id ) ) ); ?>">
								<?php esc_html_e( 'Use this template', 'comsign' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

		<?php else : ?>

			<ol class="comsign-steps" aria-label="<?php esc_attr_e( 'How it works', 'comsign' ); ?>">
				<li><span class="comsign-step-n">1</span><?php esc_html_e( 'Choose a template', 'comsign' ); ?></li>
				<li class="is-current"><span class="comsign-step-n">2</span><?php esc_html_e( 'Add recipients', 'comsign' ); ?></li>
				<li><span class="comsign-step-n">3</span><?php esc_html_e( 'Send', 'comsign' ); ?></li>
			</ol>

			<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-portal-form">
				<input type="hidden" name="action" value="comsign_portal_create">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="template_id" value="<?php echo (int) $selected->id; ?>">

				<h2><?php echo esc_html( $selected->name ); ?></h2>

				<?php require __DIR__ . '/partials/contacts-datalist.php'; ?>

				<?php foreach ( $roles as $index => $label ) : ?>
					<fieldset class="comsign-recipient">
						<legend><?php echo esc_html( $label ? $label : sprintf( /* translators: %d: role number. */ __( 'Signer %d', 'comsign' ), $index + 1 ) ); ?></legend>
						<p>
							<label for="rcpt-name-<?php echo (int) $index; ?>"><?php esc_html_e( 'Full name', 'comsign' ); ?></label>
							<input type="text" id="rcpt-name-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][name]" list="comsign-contact-names" autocomplete="off" required>
						</p>
						<p>
							<label for="rcpt-email-<?php echo (int) $index; ?>"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
							<input type="email" id="rcpt-email-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][email]" list="comsign-contact-emails" autocomplete="off" required>
						</p>
						<p>
							<label for="rcpt-phone-<?php echo (int) $index; ?>"><?php esc_html_e( 'Phone (optional)', 'comsign' ); ?></label>
							<input type="text" id="rcpt-phone-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][phone]">
						</p>
					</fieldset>
				<?php endforeach; ?>

				<p class="comsign-form-actions">
					<button type="submit" name="send" value="1" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Create &amp; send', 'comsign' ); ?></button>
					<button type="submit" name="send" value="0" class="comsign-btn"><?php esc_html_e( 'Save as draft', 'comsign' ); ?></button>
				</p>
			</form>

		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
