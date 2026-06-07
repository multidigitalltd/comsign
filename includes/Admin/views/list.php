<?php
/**
 * Documents list view.
 *
 * @package ComSign
 *
 * @var \ComSign\Admin\DocumentsListTable $table
 * @var string                            $new_url
 * @var array|null                        $notice
 * @var array                             $accounts    Accounts the user belongs to.
 * @var int                               $current_account
 * @var string                            $switch_url
 * @var string                            $switch_nonce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap comsign-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'ComSign Documents', 'comsign' ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add Document', 'comsign' ); ?></a>
	<hr class="wp-header-end">

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<?php if ( count( $accounts ) > 1 ) : ?>
		<form method="post" action="<?php echo esc_url( $switch_url ); ?>" class="comsign-account-switcher" style="margin:8px 0;">
			<input type="hidden" name="action" value="comsign_switch_account">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $switch_nonce ); ?>">
			<label for="comsign-account"><?php esc_html_e( 'Workspace:', 'comsign' ); ?></label>
			<select name="account_id" id="comsign-account">
				<?php foreach ( $accounts as $acct ) : ?>
					<option value="<?php echo (int) $acct->id; ?>"<?php selected( (int) $acct->id, $current_account ); ?>>
						<?php echo esc_html( $acct->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Switch', 'comsign' ); ?></button>
		</form>
	<?php endif; ?>

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
