<?php
/**
 * Standalone public document-verification page (/comsign/verify).
 *
 * @package ComSign
 *
 * @var string $content Pre-rendered (and escaped) verification markup.
 */

defined( 'ABSPATH' ) || exit;

$page_title = __( 'Verify a document', 'comsign' );
require __DIR__ . '/partials/header.php';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-verify comsign-card">
		<h1><?php esc_html_e( 'Verify a document', 'comsign' ); ?></h1>
		<p class="comsign-intro"><?php esc_html_e( 'Enter the document ID and its verification code to confirm it is authentic and see who signed it.', 'comsign' ); ?></p>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped pieces in verify_markup(). ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
