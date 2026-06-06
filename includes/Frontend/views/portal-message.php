<?php
/**
 * Portal informational message (sign-in required / no workspace / not found).
 *
 * @package ComSign
 *
 * @var string $page_title
 * @var string $heading
 * @var string $message
 * @var string $login_url Optional sign-in link.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/header.php';
?>
	<main class="comsign-card comsign-message">
		<h1><?php echo esc_html( $heading ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<?php if ( ! empty( $login_url ) ) : ?>
			<p><a class="comsign-btn comsign-btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Sign in', 'comsign' ); ?></a></p>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
