<?php
/**
 * Operator-only cross-tenant workspaces overview.
 *
 * @package ComSign
 *
 * @var array      $rows   Per-workspace summary rows.
 * @var array      $plans  Plan catalogue.
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Services\SubscriptionService;

$status_labels = array(
	SubscriptionService::STATUS_TRIALING => __( 'Free trial', 'comsign' ),
	SubscriptionService::STATUS_ACTIVE   => __( 'Active', 'comsign' ),
	SubscriptionService::STATUS_PAST_DUE => __( 'Payment due', 'comsign' ),
	SubscriptionService::STATUS_CANCELED => __( 'Canceled', 'comsign' ),
	SubscriptionService::STATUS_EXPIRED  => __( 'Expired', 'comsign' ),
	SubscriptionService::STATUS_NONE     => __( 'No subscription', 'comsign' ),
);
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Workspaces', 'comsign' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every customer workspace on this site, with its plan, members and document count. Only you (the site operator) can see this cross-tenant view.', 'comsign' ); ?></p>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No workspaces yet.', 'comsign' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Workspace', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Owner(s)', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Plan', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Members', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Documents', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'comsign' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['name'] ); ?></strong> <span class="description">#<?php echo (int) $row['id']; ?></span></td>
						<td><?php echo esc_html( $row['owners'] ? implode( ', ', $row['owners'] ) : '—' ); ?></td>
						<td><?php echo esc_html( (string) ( $plans[ $row['plan'] ]['name'] ?? $row['plan'] ) ); ?></td>
						<td><span class="comsign-badge comsign-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $status_labels[ $row['status'] ] ?? $row['status'] ); ?></span></td>
						<td><?php echo (int) $row['members']; ?></td>
						<td><?php echo (int) $row['documents']; ?></td>
						<td><?php echo esc_html( '' !== $row['created_at'] && '0000-00-00 00:00:00' !== $row['created_at'] ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( $row['created_at'] ) ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
