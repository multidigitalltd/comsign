<?php
/**
 * Portal: a single contact's signing history.
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var object     $contact   The contact row.
 * @var object[]   $documents Documents in this workspace the contact has signed.
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Frontend\PortalController;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<p><a href="<?php echo esc_url( PortalController::url( array( 'view' => 'contacts' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to contacts', 'comsign' ); ?></a></p>

		<h1><?php echo esc_html( $contact->name ? $contact->name : $contact->email ); ?></h1>
		<p class="comsign-intro">
			<?php echo esc_html( $contact->email ); ?><?php echo $contact->phone ? ' · ' . esc_html( $contact->phone ) : ''; ?>
		</p>

		<section class="comsign-card" aria-labelledby="comsign-contact-docs-h">
			<h2 id="comsign-contact-docs-h"><?php esc_html_e( 'Documents signed', 'comsign' ); ?></h2>

			<?php if ( empty( $documents ) ) : ?>
				<p class="comsign-empty"><?php esc_html_e( 'This contact hasn’t signed any documents in this workspace yet.', 'comsign' ); ?></p>
			<?php else : ?>
				<table class="comsign-portal-table comsign-cards-on-mobile">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Document', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Signed on', 'comsign' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $documents as $doc ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Document', 'comsign' ); ?>">
									<a href="<?php echo esc_url( PortalController::url( array( 'view' => 'document', 'doc' => (int) $doc->id ) ) ); ?>">
										<?php echo esc_html( $doc->title ? $doc->title : __( '(untitled)', 'comsign' ) ); ?>
									</a>
								</td>
								<td data-label="<?php esc_attr_e( 'Status', 'comsign' ); ?>">
									<span class="comsign-pill comsign-pill--<?php echo esc_attr( $doc->status ); ?>"><?php echo esc_html( \ComSign\Admin\DocumentsListTable::status_label( (string) $doc->status ) ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Signed on', 'comsign' ); ?>">
									<?php echo esc_html( ! empty( $doc->signed_at ) && '0000-00-00 00:00:00' !== $doc->signed_at ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $doc->signed_at ) ) : '—' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
