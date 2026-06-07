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
 * @var string     $search   Current search term.
 * @var string     $status   Current status filter ('' = any).
 * @var string[]   $statuses Selectable statuses.
 * @var int        $total    Total matching documents.
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

// Keep the active search/status on pagination links.
$page_args = array( 'view' => 'documents' );
if ( '' !== $search ) {
	$page_args['s'] = $search;
}
if ( '' !== $status ) {
	$page_args['status'] = $status;
}

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Documents', 'comsign' ); ?></h1>

		<form method="get" class="comsign-portal-filter" role="search">
			<input type="hidden" name="comsign_app" value="1">
			<input type="hidden" name="view" value="documents">
			<label class="comsign-sr-only" for="comsign-doc-search"><?php esc_html_e( 'Search documents', 'comsign' ); ?></label>
			<input type="search" id="comsign-doc-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', 'comsign' ); ?>">
			<label class="comsign-sr-only" for="comsign-doc-status"><?php esc_html_e( 'Filter by status', 'comsign' ); ?></label>
			<select id="comsign-doc-status" name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'comsign' ); ?></option>
				<?php foreach ( $statuses as $st ) : ?>
					<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="comsign-btn"><?php esc_html_e( 'Filter', 'comsign' ); ?></button>
			<?php if ( '' !== $search || '' !== $status ) : ?>
				<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'documents' ) ) ); ?>"><?php esc_html_e( 'Clear', 'comsign' ); ?></a>
			<?php endif; ?>
		</form>

		<?php require __DIR__ . '/partials/portal-doc-table.php'; ?>

		<?php if ( $pages > 1 ) : ?>
			<nav class="comsign-portal-pagination">
				<?php if ( $page > 1 ) : ?>
					<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array_merge( $page_args, array( 'paged' => $page - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'comsign' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					/* translators: 1: current page, 2: total pages. */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'comsign' ), $page, $pages ) );
					?>
				</span>
				<?php if ( $page < $pages ) : ?>
					<a class="comsign-btn" href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array_merge( $page_args, array( 'paged' => $page + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'comsign' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
