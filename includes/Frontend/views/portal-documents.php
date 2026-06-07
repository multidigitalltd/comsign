<?php
/**
 * Portal documents list (paginated).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var array      $documents Decorated rows.
 * @var int        $page
 * @var int        $pages
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Documents', 'comsign' ); ?></h1>

		<?php require __DIR__ . '/partials/portal-doc-table.php'; ?>

		<?php if ( $pages > 1 ) : ?>
			<nav class="comsign-portal-pagination">
				<?php if ( $page > 1 ) : ?>
					<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'documents', 'paged' => $page - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'comsign' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					/* translators: 1: current page, 2: total pages. */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'comsign' ), $page, $pages ) );
					?>
				</span>
				<?php if ( $page < $pages ) : ?>
					<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'documents', 'paged' => $page + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'comsign' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
