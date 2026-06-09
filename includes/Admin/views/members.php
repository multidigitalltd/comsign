<?php
/**
 * Workspace members & invitations.
 *
 * @package ComSign
 *
 * @var object|null $account
 * @var array       $members     [{user_id,name,email,role}]
 * @var array       $invites     Pending invitation rows.
 * @var array       $roles       role => label.
 * @var bool        $can_manage
 * @var int         $current_uid
 * @var string      $action_url
 * @var string      $nonce
 * @var array|null  $notice
 */

defined( 'ABSPATH' ) || exit;

$account_id = $account ? (int) $account->id : 0;
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Workspace members', 'comsign' ); ?></h1>
	<?php if ( $account ) : ?>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s: workspace name. */ __( 'Workspace: %s', 'comsign' ), $account->name ) ); ?></p>
	<?php endif; ?>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<?php if ( ! $can_manage ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'You can view members but only an owner or admin can change them.', 'comsign' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:760px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Member', 'comsign' ); ?></th>
				<th><?php esc_html_e( 'Email', 'comsign' ); ?></th>
				<th><?php esc_html_e( 'Role', 'comsign' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $members as $m ) : ?>
				<tr>
					<td><?php echo esc_html( $m['name'] ); ?></td>
					<td><?php echo esc_html( $m['email'] ); ?></td>
					<td>
						<?php if ( $can_manage ) : ?>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:flex;gap:6px;">
								<input type="hidden" name="action" value="comsign_update_member_role">
								<input type="hidden" name="account_id" value="<?php echo (int) $account_id; ?>">
								<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<select name="role">
									<?php foreach ( $roles as $rk => $rl ) : ?>
										<option value="<?php echo esc_attr( $rk ); ?>"<?php selected( $rk, $m['role'] ); ?>><?php echo esc_html( $rl ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'comsign' ); ?></button>
							</form>
						<?php else : ?>
							<?php echo esc_html( \ComSign\Support\Roles::label( $m['role'] ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $can_manage && (int) $m['user_id'] !== (int) $current_uid ) : ?>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this member?', 'comsign' ) ); ?>');">
								<input type="hidden" name="action" value="comsign_remove_member">
								<input type="hidden" name="account_id" value="<?php echo (int) $account_id; ?>">
								<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<button type="submit" class="button-link delete"><?php esc_html_e( 'Remove', 'comsign' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( ! empty( $invites ) ) : ?>
		<h2><?php esc_html_e( 'Pending invitations', 'comsign' ); ?></h2>
		<table class="widefat striped" style="max-width:760px;">
			<thead><tr><th><?php esc_html_e( 'Email', 'comsign' ); ?></th><th><?php esc_html_e( 'Role', 'comsign' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $invites as $inv ) : ?>
					<tr>
						<td><?php echo esc_html( $inv->email ); ?></td>
						<td><?php echo esc_html( \ComSign\Support\Roles::label( (string) $inv->role ) ); ?></td>
						<td>
							<?php if ( $can_manage ) : ?>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>">
									<input type="hidden" name="action" value="comsign_cancel_invite">
									<input type="hidden" name="account_id" value="<?php echo (int) $account_id; ?>">
									<input type="hidden" name="invite_id" value="<?php echo (int) $inv->id; ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
									<button type="submit" class="button-link delete"><?php esc_html_e( 'Cancel', 'comsign' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $can_manage ) : ?>
		<h2><?php esc_html_e( 'Invite a member', 'comsign' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;max-width:760px;">
			<input type="hidden" name="action" value="comsign_invite_member">
			<input type="hidden" name="account_id" value="<?php echo (int) $account_id; ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label><?php esc_html_e( 'Email', 'comsign' ); ?><br>
				<input type="email" name="email" required class="regular-text">
			</label>
			<label><?php esc_html_e( 'Role', 'comsign' ); ?><br>
				<select name="role">
					<?php foreach ( $roles as $rk => $rl ) : ?>
						<option value="<?php echo esc_attr( $rk ); ?>"<?php selected( 'viewer', $rk ); ?>><?php echo esc_html( $rl ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Send invite', 'comsign' ); ?></button>
		</form>

		<h2><?php esc_html_e( 'Create a sub-workspace', 'comsign' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A sub-workspace belongs under this one; managers here can see its documents too.', 'comsign' ); ?></p>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:flex;gap:8px;align-items:flex-end;max-width:760px;">
			<input type="hidden" name="action" value="comsign_create_subaccount">
			<input type="hidden" name="account_id" value="<?php echo (int) $account_id; ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label><?php esc_html_e( 'Sub-workspace name', 'comsign' ); ?><br>
				<input type="text" name="name" required class="regular-text">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Create', 'comsign' ); ?></button>
		</form>
	<?php endif; ?>
</div>
