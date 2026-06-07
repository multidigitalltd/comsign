<?php
/**
 * Portal top navigation + account switcher.
 *
 * @package ComSign
 *
 * @var array      $nav      Nav links.
 * @var array|null $switcher Switcher data or null.
 */

defined( 'ABSPATH' ) || exit;
?>
<nav class="comsign-portal-nav" aria-label="<?php esc_attr_e( 'Workspace', 'comsign' ); ?>">
	<ul class="comsign-portal-links">
		<?php foreach ( $nav as $link ) : ?>
			<li>
				<a href="<?php echo esc_url( $link['url'] ); ?>" class="<?php echo $link['active'] ? 'is-active' : ''; ?>"<?php echo $link['active'] ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $link['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $switcher ) ) : ?>
		<form method="post" action="<?php echo esc_url( $switcher['action'] ); ?>" class="comsign-portal-switcher">
			<input type="hidden" name="action" value="<?php echo esc_attr( $switcher['handler'] ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $switcher['nonce'] ); ?>">
			<label for="comsign-portal-account"><?php esc_html_e( 'Workspace:', 'comsign' ); ?></label>
			<select name="account_id" id="comsign-portal-account">
				<?php foreach ( $switcher['accounts'] as $acct ) : ?>
					<option value="<?php echo (int) $acct->id; ?>"<?php selected( (int) $acct->id, (int) $switcher['current'] ); ?>>
						<?php echo esc_html( $acct->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Switch', 'comsign' ); ?></button>
		</form>
	<?php endif; ?>
</nav>

<?php
// Flash notice carried across the post/redirect/get cycle.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$cs_notice = isset( $_GET['cs_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['cs_notice'] ) ) : '';
$cs_type   = ( isset( $_GET['cs_type'] ) && 'success' === $_GET['cs_type'] ) ? 'success' : 'error';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
if ( '' !== $cs_notice ) :
	?>
	<div class="comsign-portal-flash is-<?php echo esc_attr( $cs_type ); ?>" role="status">
		<?php echo esc_html( $cs_notice ); ?>
	</div>
	<?php
endif;
?>
