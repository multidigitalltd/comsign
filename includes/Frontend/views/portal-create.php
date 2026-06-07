<?php
/**
 * Portal: create a document from a template and send it.
 *
 * Step 1 lists the account's templates. Once one is chosen (?template=ID) the
 * recipient form for that template's roles is shown.
 *
 * @package ComSign
 *
 * @var string      $page_title
 * @var array       $nav
 * @var object[]    $templates Templates available to this user.
 * @var object|null $selected  Chosen template, or null.
 * @var string[]    $roles     Role labels of the chosen template.
 * @var string      $action    admin-post.php URL.
 * @var string      $nonce
 * @var array|null  $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'New document', 'comsign' ); ?></h1>

		<?php if ( ! $selected ) : ?>

			<ol class="comsign-steps" aria-label="<?php esc_attr_e( 'How it works', 'comsign' ); ?>">
				<li class="is-current"><span class="comsign-step-n">1</span><?php esc_html_e( 'Choose a template', 'comsign' ); ?></li>
				<li><span class="comsign-step-n">2</span><?php esc_html_e( 'Add recipients', 'comsign' ); ?></li>
				<li><span class="comsign-step-n">3</span><?php esc_html_e( 'Send', 'comsign' ); ?></li>
			</ol>

			<?php if ( empty( $templates ) ) : ?>
				<p class="comsign-empty">
					<?php esc_html_e( 'There are no templates in this workspace yet. An administrator can create one from any document.', 'comsign' ); ?>
				</p>
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
							<a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'create', 'template' => (int) $template->id ) ) ); ?>">
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

			<p>
				<a href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'create' ) ) ); ?>">
					&larr; <?php esc_html_e( 'Choose a different template', 'comsign' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-portal-form">
				<input type="hidden" name="action" value="comsign_portal_create">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="template_id" value="<?php echo (int) $selected->id; ?>">

				<h2><?php echo esc_html( $selected->name ); ?></h2>

				<?php foreach ( $roles as $index => $label ) : ?>
					<fieldset class="comsign-recipient">
						<legend><?php echo esc_html( $label ? $label : sprintf( /* translators: %d: role number. */ __( 'Signer %d', 'comsign' ), $index + 1 ) ); ?></legend>
						<p>
							<label for="rcpt-name-<?php echo (int) $index; ?>"><?php esc_html_e( 'Full name', 'comsign' ); ?></label>
							<input type="text" id="rcpt-name-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][name]" required>
						</p>
						<p>
							<label for="rcpt-email-<?php echo (int) $index; ?>"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
							<input type="email" id="rcpt-email-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][email]" required>
						</p>
						<p>
							<label for="rcpt-phone-<?php echo (int) $index; ?>"><?php esc_html_e( 'Phone (optional)', 'comsign' ); ?></label>
							<input type="text" id="rcpt-phone-<?php echo (int) $index; ?>" name="recipient[<?php echo (int) $index; ?>][phone]">
						</p>
					</fieldset>
				<?php endforeach; ?>

				<p class="comsign-form-actions">
					<button type="submit" name="send" value="1" class="comsign-btn comsign-btn--primary">
						<?php esc_html_e( 'Create &amp; send', 'comsign' ); ?>
					</button>
					<button type="submit" name="send" value="0" class="comsign-btn">
						<?php esc_html_e( 'Save as draft', 'comsign' ); ?>
					</button>
				</p>
			</form>

		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
