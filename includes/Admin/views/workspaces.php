<?php
/**
 * Operator-only cross-tenant workspaces overview.
 *
 * @package ComSign
 *
 * @var array      $rows       Per-workspace summary rows.
 * @var array      $plans      Plan catalogue.
 * @var string     $search     Active name search.
 * @var string     $status     Active status filter.
 * @var string     $action_url   admin-post.php URL.
 * @var string     $nonce
 * @var array|null $notice
 * @var array      $operator_log Recent operator actions (newest first).
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

	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="comsign-workspaces">
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name…', 'comsign' ); ?>">
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'comsign' ); ?></option>
			<?php foreach ( $status_labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'comsign' ); ?></button>
		<?php if ( '' !== $search || '' !== $status ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=comsign-workspaces' ) ); ?>"><?php esc_html_e( 'Clear', 'comsign' ); ?></a>
		<?php endif; ?>
	</form>

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
					<th scope="col"><?php esc_html_e( 'Manage', 'comsign' ); ?></th>
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
						<td>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form" style="margin-bottom:6px;">
								<input type="hidden" name="action" value="comsign_workspace_action">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<input type="hidden" name="account_id" value="<?php echo (int) $row['id']; ?>">
								<input type="hidden" name="op" value="set_plan">
								<label class="screen-reader-text" for="ws-plan-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Plan', 'comsign' ); ?></label>
								<select id="ws-plan-<?php echo (int) $row['id']; ?>" name="plan">
									<?php foreach ( $plans as $pid => $plan ) : ?>
										<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $row['plan'], $pid ); ?>><?php echo esc_html( (string) $plan['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="cycle">
									<option value="monthly"><?php esc_html_e( 'monthly', 'comsign' ); ?></option>
									<option value="annual"><?php esc_html_e( 'annual', 'comsign' ); ?></option>
								</select>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Set plan', 'comsign' ); ?></button>
							</form>
							<?php if ( in_array( $row['status'], array( SubscriptionService::STATUS_CANCELED, SubscriptionService::STATUS_EXPIRED ), true ) ) : ?>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form">
									<input type="hidden" name="action" value="comsign_workspace_action">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
									<input type="hidden" name="account_id" value="<?php echo (int) $row['id']; ?>">
									<input type="hidden" name="op" value="reactivate">
									<label class="screen-reader-text" for="ws-reason-r-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Reason (optional)', 'comsign' ); ?></label>
									<input type="text" id="ws-reason-r-<?php echo (int) $row['id']; ?>" name="reason" maxlength="255" placeholder="<?php esc_attr_e( 'Reason (optional)', 'comsign' ); ?>" style="width:140px;">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Reactivate', 'comsign' ); ?></button>
								</form>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Suspend this workspace?', 'comsign' ) ); ?>');">
									<input type="hidden" name="action" value="comsign_workspace_action">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
									<input type="hidden" name="account_id" value="<?php echo (int) $row['id']; ?>">
									<input type="hidden" name="op" value="suspend">
									<label class="screen-reader-text" for="ws-reason-s-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Reason (optional)', 'comsign' ); ?></label>
									<input type="text" id="ws-reason-s-<?php echo (int) $row['id']; ?>" name="reason" maxlength="255" placeholder="<?php esc_attr_e( 'Reason (optional)', 'comsign' ); ?>" style="width:140px;">
									<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Suspend', 'comsign' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $operator_log ) ) : ?>
		<h2 style="margin-top:32px;"><?php esc_html_e( 'Recent operator actions', 'comsign' ); ?></h2>
		<p class="description"><?php esc_html_e( 'An audit trail of manual plan changes, suspensions and reactivations.', 'comsign' ); ?></p>
		<?php
		$action_labels = array(
			'set_plan'   => __( 'Set plan', 'comsign' ),
			'suspend'    => __( 'Suspend', 'comsign' ),
			'reactivate' => __( 'Reactivate', 'comsign' ),
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Operator', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Workspace', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'comsign' ); ?></th>
					<th scope="col"><?php esc_html_e( 'IP', 'comsign' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $operator_log as $entry ) : ?>
					<?php $actor = get_userdata( (int) $entry->actor_id ); ?>
					<tr>
						<td><?php echo esc_html( '' !== (string) $entry->created_at && '0000-00-00 00:00:00' !== (string) $entry->created_at ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( (string) $entry->created_at ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $actor ? $actor->user_login : '#' . (int) $entry->actor_id ); ?></td>
						<td>#<?php echo (int) $entry->account_id; ?></td>
						<td><?php echo esc_html( $action_labels[ $entry->action ] ?? (string) $entry->action ); ?></td>
						<td><?php echo esc_html( (string) $entry->old_plan . '/' . (string) $entry->old_status . ' → ' . (string) $entry->new_plan . '/' . (string) $entry->new_status ); ?></td>
						<td><?php echo esc_html( (string) $entry->reason ); ?></td>
						<td><?php echo esc_html( (string) $entry->ip ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
