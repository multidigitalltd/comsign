<?php
/**
 * Portal: team management (members, roles, invites).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var array      $members   Decorated member rows.
 * @var object[]   $invites   Pending invite rows.
 * @var array      $roles     Assignable role => label.
 * @var string     $action    admin-post.php URL.
 * @var string     $nonce
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Team', 'comsign' ); ?></h1>

		<div class="comsign-editor-grid">
			<section class="comsign-editor-side" aria-labelledby="comsign-invite-h">
				<h2 id="comsign-invite-h"><?php esc_html_e( 'Invite a member', 'comsign' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-recipient">
					<input type="hidden" name="action" value="comsign_portal_invite">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<p>
						<label for="comsign-invite-email"><?php esc_html_e( 'Email', 'comsign' ); ?></label>
						<input type="email" id="comsign-invite-email" name="email" required>
					</p>
					<p>
						<label for="comsign-invite-role"><?php esc_html_e( 'Role', 'comsign' ); ?></label>
						<select id="comsign-invite-role" name="role">
							<?php foreach ( $roles as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'sender', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><button type="submit" class="comsign-btn comsign-btn--primary"><?php esc_html_e( 'Send invite', 'comsign' ); ?></button></p>
				</form>
			</section>

			<section class="comsign-editor-main" aria-labelledby="comsign-members-h">
				<h2 id="comsign-members-h"><?php esc_html_e( 'Workspace members', 'comsign' ); ?></h2>
				<table class="comsign-portal-table comsign-cards-on-mobile">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Email', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'comsign' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $members as $m ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Name', 'comsign' ); ?>"><?php echo esc_html( $m['name'] ? $m['name'] : '—' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Email', 'comsign' ); ?>"><?php echo esc_html( $m['email'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Role', 'comsign' ); ?>">
									<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form">
										<input type="hidden" name="action" value="comsign_portal_member_role">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
										<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
										<label class="comsign-sr-only" for="role-<?php echo (int) $m['user_id']; ?>"><?php esc_html_e( 'Role', 'comsign' ); ?></label>
										<select id="role-<?php echo (int) $m['user_id']; ?>" name="role">
											<?php foreach ( $roles as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m['role'], $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Update', 'comsign' ); ?></button>
									</form>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this member?', 'comsign' ) ); ?>');">
										<input type="hidden" name="action" value="comsign_portal_member_remove">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
										<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
										<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Remove', 'comsign' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $invites ) ) : ?>
					<h3><?php esc_html_e( 'Pending invitations', 'comsign' ); ?></h3>
					<table class="comsign-portal-table comsign-cards-on-mobile">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Email', 'comsign' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Role', 'comsign' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Action', 'comsign' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $invites as $invite ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Email', 'comsign' ); ?>"><?php echo esc_html( $invite->email ); ?></td>
									<td data-label="<?php esc_attr_e( 'Role', 'comsign' ); ?>"><?php echo esc_html( $roles[ $invite->role ] ?? $invite->role ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( $action ); ?>" class="comsign-inline-form">
											<input type="hidden" name="action" value="comsign_portal_member_remove">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
											<input type="hidden" name="invite_email" value="<?php echo esc_attr( $invite->email ); ?>">
											<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Cancel', 'comsign' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
