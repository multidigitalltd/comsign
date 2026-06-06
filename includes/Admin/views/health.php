<?php
/**
 * System status / health-check page.
 *
 * @package ComSign
 *
 * @var array      $checks     List of {label,status,detail} rows.
 * @var string     $action_url admin-post.php URL.
 * @var string     $nonce      Nonce for the test actions.
 * @var string     $admin_mail Current admin email.
 * @var array|null $notice     Notice to display.
 */

defined( 'ABSPATH' ) || exit;

$status_labels = array(
	'ok'   => __( 'OK', 'comsign' ),
	'warn' => __( 'Warning', 'comsign' ),
	'fail' => __( 'Problem', 'comsign' ),
);
$status_colors = array(
	'ok'   => '#15803d',
	'warn' => '#b45309',
	'fail' => '#b91c1c',
);
?>
<div class="wrap comsign-health">
	<h1><?php esc_html_e( 'System Status', 'comsign' ); ?></h1>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<p><?php esc_html_e( 'These checks help you confirm ComSign is correctly configured on this server.', 'comsign' ); ?></p>

	<table class="widefat striped" style="max-width:900px;">
		<thead>
			<tr>
				<th style="width:60px;"><?php esc_html_e( 'Status', 'comsign' ); ?></th>
				<th style="width:220px;"><?php esc_html_e( 'Check', 'comsign' ); ?></th>
				<th><?php esc_html_e( 'Details', 'comsign' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<?php $st = isset( $check['status'] ) ? $check['status'] : 'ok'; ?>
				<tr>
					<td>
						<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;background:<?php echo esc_attr( $status_colors[ $st ] ?? '#555' ); ?>;">
							<?php echo esc_html( $status_labels[ $st ] ?? $st ); ?>
						</span>
					</td>
					<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
					<td><?php echo esc_html( $check['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Connectivity tests', 'comsign' ); ?></h2>
	<p style="display:flex;gap:12px;flex-wrap:wrap;">
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="comsign_test_email">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<button type="submit" class="button">
				<?php
				/* translators: %s: admin email address. */
				echo esc_html( sprintf( __( 'Send test email to %s', 'comsign' ), $admin_mail ) );
				?>
			</button>
		</form>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="action" value="comsign_test_webhook">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Send test webhook', 'comsign' ); ?></button>
		</form>
	</p>
</div>
