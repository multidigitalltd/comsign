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

	<form method="get">
		<input type="hidden" name="page" value="comsign">
		<?php $table->display(); ?>
	</form>
</div>
