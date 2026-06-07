<?php
/**
 * Portal document detail.
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var object     $document
 * @var array      $signers
 * @var array|null $switcher
 * @var bool       $can_send     Whether the viewer may send/resend.
 * @var bool       $is_draft     Whether the document is still a draft.
 * @var array|null $readiness    Readiness checklist (drafts the viewer can send).
 * @var bool       $has_signed   Whether a signed PDF is available.
 * @var string     $action       admin-post.php URL.
 * @var callable   $download_url fn(string $which): string.
 * @var string     $resend_nonce
 * @var string     $send_nonce
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
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

		<p class="comsign-portal-actions">
			<?php if ( ! empty( $is_draft ) && ! empty( $can_send ) ) : ?>
				<a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'edit', 'doc' => (int) $document->id ) ) ); ?>"><?php esc_html_e( 'Edit document', 'comsign' ); ?></a>
			<?php endif; ?>
			<a class="comsign-btn" href="<?php echo esc_url( $download_url( 'source' ) ); ?>"><?php esc_html_e( 'Download original', 'comsign' ); ?></a>
			<?php if ( ! empty( $has_signed ) ) : ?>
				<a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( $download_url( 'signed' ) ); ?>"><?php esc_html_e( 'Download signed PDF', 'comsign' ); ?></a>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $is_draft ) && ! empty( $can_send ) && ! empty( $readiness ) ) : ?>
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
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $send_nonce ); ?>">
						<input type="hidden" name="document_id" value="<?php echo (int) $document->id; ?>">
						<input type="hidden" name="send" value="1">
						<button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Send for signing', 'comsign' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>

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
						<?php if ( ! empty( $can_send ) && empty( $is_draft ) ) : ?>
							<th><?php esc_html_e( 'Action', 'comsign' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $signers as $signer ) : ?>
						<tr>
							<td><?php echo esc_html( $signer->name ); ?></td>
							<td><?php echo esc_html( $signer->email ); ?></td>
							<td><span class="comsign-pill comsign-pill--<?php echo esc_attr( $signer->status ); ?>"><?php echo esc_html( ucfirst( (string) $signer->status ) ); ?></span></td>
							<td><?php echo esc_html( ! empty( $signer->signed_at ) ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $signer->signed_at ) ) : '—' ); ?></td>
							<?php if ( ! empty( $can_send ) && empty( $is_draft ) ) : ?>
								<td>
									<?php if ( \ComSign\Database\SignerRepository::STATUS_SIGNED !== $signer->status ) : ?>
										<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form">
											<input type="hidden" name="action" value="comsign_portal_resend">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $resend_nonce ); ?>">
											<input type="hidden" name="document_id" value="<?php echo (int) $document->id; ?>">
											<input type="hidden" name="signer_id" value="<?php echo (int) $signer->id; ?>">
											<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Resend', 'comsign' ); ?></button>
										</form>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
