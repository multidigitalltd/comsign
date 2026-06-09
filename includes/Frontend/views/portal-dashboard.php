<?php
/**
 * Portal dashboard.
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var array      $counts   status => count.
 * @var array      $recent   Recent document rows (decorated).
 * @var bool       $is_empty No documents yet.
 * @var bool       $can_create
 * @var bool       $can_manage_billing
 * @var int|null   $trial_days  Days left in trial (owners), or null.
 * @var string     $subscription_status
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';

$new_url     = \ComSign\Frontend\PortalController::url( array( 'view' => 'create' ) );
$billing_url = \ComSign\Frontend\PortalController::url( array( 'view' => 'billing' ) );
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Your workspace', 'comsign' ); ?></h1>

		<?php if ( null !== $trial_days ) : ?>
			<div class="comsign-trial-banner" role="status">
				<span>
					<?php
					/* translators: %d: days left. */
					echo esc_html( sprintf( __( 'You are on a free trial — %d days left.', 'comsign' ), (int) $trial_days ) );
					?>
				</span>
				<a class="comsign-btn comsign-btn--small comsign-btn--primary" href="<?php echo esc_url( $billing_url ); ?>"><?php esc_html_e( 'Choose a plan', 'comsign' ); ?></a>
			</div>
		<?php endif; ?>

		<?php if ( $is_empty ) : ?>
			<section class="comsign-card comsign-onboard" aria-labelledby="comsign-onboard-h">
				<h2 id="comsign-onboard-h"><?php esc_html_e( 'Welcome! Let’s send your first document.', 'comsign' ); ?></h2>
				<p class="comsign-intro"><?php esc_html_e( 'Upload a PDF or pick a template, add the people who need to sign, place the fields, and send. We’ll track it for you.', 'comsign' ); ?></p>
				<?php if ( $can_create ) : ?>
					<p><a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Create your first document', 'comsign' ); ?></a></p>
				<?php else : ?>
					<p class="comsign-empty"><?php esc_html_e( 'Ask a workspace owner to give you permission to create documents.', 'comsign' ); ?></p>
				<?php endif; ?>
			</section>
		<?php else : ?>
			<div class="comsign-portal-cards">
				<?php foreach ( $counts as $status => $total ) : ?>
					<div class="comsign-portal-stat">
						<span class="comsign-portal-stat-num"><?php echo esc_html( (string) $total ); ?></span>
						<span class="comsign-portal-stat-label"><?php echo esc_html( \ComSign\Admin\DocumentsListTable::status_label( (string) $status ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $can_create ) : ?>
				<p><a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'New document', 'comsign' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent documents', 'comsign' ); ?></h2>
			<?php
			$documents = $recent;
			require __DIR__ . '/partials/portal-doc-table.php';
			?>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
