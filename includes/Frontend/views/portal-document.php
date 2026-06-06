<?php
/**
 * Portal document detail (read-only).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var object     $document
 * @var array      $signers
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main class="comsign-portal">
		<p><a href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'documents' ) ) ); ?>">&larr; <?php esc_html_e( 'Back to documents', 'comsign' ); ?></a></p>

		<h1><?php echo esc_html( $document->title ? $document->title : __( '(untitled)', 'comsign' ) ); ?></h1>
		<p>
			<strong><?php esc_html_e( 'Status:', 'comsign' ); ?></strong>
			<span class="comsign-pill comsign-pill--<?php echo esc_attr( $document->status ); ?>"><?php echo esc_html( ucfirst( (string) $document->status ) ); ?></span>
			<?php if ( ! empty( $document->expires_at ) ) : ?>
				&nbsp;·&nbsp;<strong><?php esc_html_e( 'Expires:', 'comsign' ); ?></strong>
				<?php echo esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $document->expires_at ) ) ); ?>
			<?php endif; ?>
		</p>

		<h2><?php esc_html_e( 'Signers', 'comsign' ); ?></h2>
		<?php if ( empty( $signers ) ) : ?>
			<p class="comsign-empty"><?php esc_html_e( 'No signers added yet.', 'comsign' ); ?></p>
		<?php else : ?>
			<table class="comsign-portal-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'comsign' ); ?></th>
						<th><?php esc_html_e( 'Email', 'comsign' ); ?></th>
						<th><?php esc_html_e( 'Status', 'comsign' ); ?></th>
						<th><?php esc_html_e( 'Signed at', 'comsign' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $signers as $signer ) : ?>
						<tr>
							<td><?php echo esc_html( $signer->name ); ?></td>
							<td><?php echo esc_html( $signer->email ); ?></td>
							<td><span class="comsign-pill comsign-pill--<?php echo esc_attr( $signer->status ); ?>"><?php echo esc_html( ucfirst( (string) $signer->status ) ); ?></span></td>
							<td><?php echo esc_html( ! empty( $signer->signed_at ) ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $signer->signed_at ) ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
