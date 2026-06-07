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
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Your workspace', 'comsign' ); ?></h1>

		<div class="comsign-portal-cards">
			<?php foreach ( $counts as $status => $total ) : ?>
				<div class="comsign-portal-stat">
					<span class="comsign-portal-stat-num"><?php echo esc_html( (string) $total ); ?></span>
					<span class="comsign-portal-stat-label"><?php echo esc_html( ucfirst( (string) $status ) ); ?></span>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $counts ) ) : ?>
				<p class="comsign-empty"><?php esc_html_e( 'No documents yet.', 'comsign' ); ?></p>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( 'Recent documents', 'comsign' ); ?></h2>
		<?php
		$documents = $recent;
		require __DIR__ . '/partials/portal-doc-table.php';
		?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
