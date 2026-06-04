<?php
/**
 * Documents list view.
 *
 * @package ComSign
 *
 * @var \ComSign\Admin\DocumentsListTable $table
 * @var string                            $new_url
 * @var array|null                        $notice
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'ComSign Documents', 'comsign' ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Document', 'comsign' ); ?></a>
	<hr class="wp-header-end">

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<?php if ( ! empty( $counts ) ) : ?>
		<div class="comsign-summary">
			<?php foreach ( $counts as $status => $total ) : ?>
				<span class="comsign-summary-item">
					<span class="comsign-badge comsign-badge--<?php echo esc_attr( $status ); ?>">
						<?php echo esc_html( \ComSign\Admin\DocumentsListTable::status_label( $status ) ); ?>
					</span>
					<strong><?php echo esc_html( (string) $total ); ?></strong>
				</span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="comsign">
		<?php $table->display(); ?>
	</form>
</div>
