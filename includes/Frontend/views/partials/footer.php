<?php
/**
 * Standalone public-page footer.
 *
 * @package ComSign
 */

defined( 'ABSPATH' ) || exit;
?>
	<footer class="comsign-public-footer">
		<?php
		printf(
			/* translators: %s: site name. */
			esc_html__( 'Securely processed by %s', 'comsign' ),
			esc_html( get_bloginfo( 'name' ) )
		);
		?>
	</footer>
</div>
</body>
</html>
