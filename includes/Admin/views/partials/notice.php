<?php
/**
 * Flash-notice partial.
 *
 * @package ComSign
 *
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $notice ) || empty( $notice['message'] ) ) {
	return;
}

$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
?>
<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
	<p><?php echo esc_html( $notice['message'] ); ?></p>
</div>
