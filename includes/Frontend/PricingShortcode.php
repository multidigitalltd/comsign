<?php
/**
 * Public pricing table shortcode: [comsign_pricing].
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

use ComSign\Billing\Plans;

/**
 * Renders a public plan-comparison table with a "start free trial" call to
 * action, for placement on any marketing page via [comsign_pricing].
 */
final class PricingShortcode {

	public function register(): void {
		add_shortcode( 'comsign_pricing', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the pricing styles only on pages that use the shortcode.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'comsign_pricing' ) ) {
			wp_enqueue_style( 'comsign-tokens', COMSIGN_PLUGIN_URL . 'assets/css/tokens.css', array(), COMSIGN_VERSION );
			wp_enqueue_style( 'comsign-pricing', COMSIGN_PLUGIN_URL . 'assets/css/pricing.css', array( 'comsign-tokens' ), COMSIGN_VERSION );
		}
	}

	/**
	 * The call-to-action URL: logged-in users go to the portal (which creates a
	 * workspace + trial on first visit); guests go to register/login.
	 */
	private function cta_url(): string {
		if ( is_user_logged_in() ) {
			return PortalController::url();
		}
		return get_option( 'users_can_register' ) ? wp_registration_url() : wp_login_url( PortalController::url() );
	}

	/**
	 * Render the pricing table.
	 *
	 * @param array $atts Shortcode attributes (unused).
	 */
	public function render( $atts = array() ): string {
		$cta      = $this->cta_url();
		$currency = Plans::currency();
		$symbols  = array( 'ILS' => '₪', 'USD' => '$', 'EUR' => '€' );
		$sym      = $symbols[ $currency ] ?? ( $currency . ' ' );

		ob_start();
		?>
		<div class="comsign-pricing" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>">
			<div class="comsign-pricing-grid">
				<?php foreach ( Plans::all() as $pid => $plan ) : ?>
					<?php
					$dpm   = (int) ( $plan['limits'][ Plans::LIMIT_DOCS_PER_MONTH ] ?? 0 );
					$seats = (int) ( $plan['limits'][ Plans::LIMIT_SEATS ] ?? 0 );
					?>
					<section class="comsign-pricing-card" aria-labelledby="cs-price-<?php echo esc_attr( $pid ); ?>">
						<h3 id="cs-price-<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( (string) $plan['name'] ); ?></h3>
						<p class="comsign-pricing-amount">
							<?php echo esc_html( $sym . number_format_i18n( (float) $plan['price']['monthly'] ) ); ?>
							<span class="comsign-pricing-per"><?php esc_html_e( '/ month', 'comsign' ); ?></span>
						</p>
						<p class="comsign-pricing-annual">
							<?php
							/* translators: %s: annual price. */
							echo esc_html( sprintf( __( 'or %s / year', 'comsign' ), $sym . number_format_i18n( (float) $plan['price']['annual'] ) ) );
							?>
						</p>
						<ul class="comsign-pricing-features">
							<li><?php echo esc_html( 0 === $dpm ? __( 'Unlimited documents', 'comsign' ) : sprintf( /* translators: %d: docs. */ __( '%d documents / month', 'comsign' ), $dpm ) ); ?></li>
							<li><?php echo esc_html( sprintf( /* translators: %d: seats. */ __( 'Team seats: %d', 'comsign' ), $seats ) ); ?></li>
							<?php if ( Plans::has_feature( $pid, Plans::FEATURE_PKI ) ) : ?>
								<li><?php esc_html_e( 'Digital (PKI) signatures', 'comsign' ); ?></li>
							<?php endif; ?>
							<?php if ( Plans::has_feature( $pid, Plans::FEATURE_BRANDING ) ) : ?>
								<li><?php esc_html_e( 'Custom branding', 'comsign' ); ?></li>
							<?php endif; ?>
							<?php if ( Plans::has_feature( $pid, Plans::FEATURE_API ) ) : ?>
								<li><?php esc_html_e( 'API & webhooks', 'comsign' ); ?></li>
							<?php endif; ?>
						</ul>
						<a class="comsign-pricing-cta" href="<?php echo esc_url( $cta ); ?>">
							<?php
							/* translators: %d: trial days. */
							echo esc_html( sprintf( __( 'Start %d-day free trial', 'comsign' ), Plans::TRIAL_DAYS ) );
							?>
						</a>
					</section>
				<?php endforeach; ?>
			</div>
			<p class="comsign-pricing-note"><?php esc_html_e( 'No credit card required to start. Cancel anytime.', 'comsign' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
