<?php
/**
 * Signer identity-challenge page (access code / email OTP).
 *
 * @package ComSign
 *
 * @var object $signer
 * @var string $method
 * @var string $raw_token
 * @var string $post_url
 * @var string $nonce
 * @var string $error
 * @var string $email
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Frontend\SignerAuth;

$page_title = __( 'Verify your identity', 'comsign' );
require __DIR__ . '/partials/header.php';
?>
	<main class="comsign-card comsign-auth">
		<h1><?php esc_html_e( 'Verify your identity', 'comsign' ); ?></h1>

		<?php if ( SignerAuth::METHOD_OTP === $method ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: masked email address. */
					esc_html__( 'We sent a one-time code to %s. Enter it below to continue.', 'comsign' ),
					'<strong>' . esc_html( $email ) . '</strong>'
				);
				?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Please enter the access code provided by the sender to continue.', 'comsign' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $error ) : ?>
			<p class="comsign-auth__error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="comsign-auth__form">
			<input type="hidden" name="action" value="comsign_sign_auth">
			<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">

			<p>
				<label for="comsign-code"><?php esc_html_e( 'Code', 'comsign' ); ?></label><br>
				<input type="text" id="comsign-code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Continue', 'comsign' ); ?></button></p>
		</form>

		<?php if ( SignerAuth::METHOD_OTP === $method ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="comsign-auth__resend">
				<input type="hidden" name="action" value="comsign_sign_auth">
				<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="resend" value="1">
				<button type="submit" class="comsign-link-button"><?php esc_html_e( 'Resend code', 'comsign' ); ?></button>
			</form>
		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
