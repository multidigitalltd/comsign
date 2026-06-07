<?php
/**
 * Standalone status/result message page.
 *
 * @package ComSign
 *
 * @var string $title
 * @var string $message
 * @var string $type
 */

defined( 'ABSPATH' ) || exit;

$page_title = $title;
require __DIR__ . '/partials/header.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-card comsign-message comsign-message--<?php echo esc_attr( $type ); ?>">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
