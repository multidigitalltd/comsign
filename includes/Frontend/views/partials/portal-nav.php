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
<nav class="comsign-portal-nav">
	<ul class="comsign-portal-links">
		<?php foreach ( $nav as $link ) : ?>
			<li>
				<a href="<?php echo esc_url( $link['url'] ); ?>" class="<?php echo $link['active'] ? 'is-active' : ''; ?>">
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
			<select name="account_id" id="comsign-portal-account" onchange="this.form.submit()">
				<?php foreach ( $switcher['accounts'] as $acct ) : ?>
					<option value="<?php echo (int) $acct->id; ?>"<?php selected( (int) $acct->id, (int) $switcher['current'] ); ?>>
						<?php echo esc_html( $acct->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="comsign-btn"><?php esc_html_e( 'Switch', 'comsign' ); ?></button></noscript>
		</form>
	<?php endif; ?>
</nav>
