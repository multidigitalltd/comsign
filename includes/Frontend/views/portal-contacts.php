<?php
/**
 * Portal: address book management (list / search / add / remove).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var object[]   $contacts
 * @var string     $search
 * @var string     $action       admin-post.php URL.
 * @var string     $add_nonce
 * @var string     $delete_nonce
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Contacts', 'comsign' ); ?></h1>
		<p class="comsign-intro"><?php esc_html_e( 'People you sign with are saved here automatically and offered as suggestions when you add signers.', 'comsign' ); ?></p>

		<div class="comsign-editor-grid">
			<section class="comsign-editor-side" aria-labelledby="comsign-contact-add-h">
				<h2 id="comsign-contact-add-h"><?php esc_html_e( 'Add a contact', 'comsign' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-recipient">
					<input type="hidden" name="action" value="comsign_portal_contact_add">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $add_nonce ); ?>">
					<p>
						<label for="comsign-contact-name"><?php esc_html_e( 'Full name', 'comsign' ); ?></label>
						<input type="text" id="comsign-contact-name" name="name">
					</p>
					<p>
						<label for="comsign-contact-email"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
						<input type="email" id="comsign-contact-email" name="email" required>
					</p>
					<p>
						<label for="comsign-contact-phone"><?php esc_html_e( 'Phone (optional)', 'comsign' ); ?></label>
						<input type="text" id="comsign-contact-phone" name="phone">
					</p>
					<p><button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Save contact', 'comsign' ); ?></button></p>
				</form>
			</section>

			<section class="comsign-editor-main" aria-labelledby="comsign-contact-list-h">
				<h2 id="comsign-contact-list-h"><?php esc_html_e( 'Your contacts', 'comsign' ); ?></h2>

				<form method="get" class="comsign-portal-filter" role="search">
					<input type="hidden" name="comsign_app" value="1">
					<input type="hidden" name="view" value="contacts">
					<label class="comsign-sr-only" for="comsign-contact-search"><?php esc_html_e( 'Search contacts', 'comsign' ); ?></label>
					<input type="search" id="comsign-contact-search" name="cs" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name or email…', 'comsign' ); ?>">
					<button type="submit" class="comsign-btn"><?php esc_html_e( 'Search', 'comsign' ); ?></button>
					<?php if ( '' !== $search ) : ?>
						<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'contacts' ) ) ); ?>"><?php esc_html_e( 'Clear', 'comsign' ); ?></a>
					<?php endif; ?>
				</form>

				<?php if ( empty( $contacts ) ) : ?>
					<p class="comsign-empty"><?php esc_html_e( 'No contacts yet.', 'comsign' ); ?></p>
				<?php else : ?>
					<table class="comsign-portal-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'comsign' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'comsign' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Phone', 'comsign' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Action', 'comsign' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $contacts as $contact ) : ?>
								<tr>
									<td><?php echo esc_html( $contact->name ); ?></td>
									<td><?php echo esc_html( $contact->email ); ?></td>
									<td><?php echo esc_html( $contact->phone ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this contact?', 'comsign' ) ); ?>');">
											<input type="hidden" name="action" value="comsign_portal_contact_delete">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>">
											<input type="hidden" name="contact_id" value="<?php echo (int) $contact->id; ?>">
											<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Remove', 'comsign' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
