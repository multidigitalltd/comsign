<?php
/**
 * Portal: plan & billing.
 *
 * @package ComSign
 *
 * @var string       $page_title
 * @var array        $nav
 * @var bool|null    $activated     Checkout return result (true/false/null).
 * @var string       $status        Effective subscription status.
 * @var object|null  $subscription  Raw subscription row.
 * @var string       $plan_id       Current plan id.
 * @var array        $plans         Plan catalogue.
 * @var string       $currency
 * @var int          $used          Documents used this month.
 * @var int|null     $remaining     Documents remaining (null = unlimited).
 * @var bool         $configured    Whether Cardcom is configured.
 * @var string       $action        admin-post.php URL.
 * @var string       $nonce
 * @var array|null   $switcher
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Billing\Plans;
use ComSign\Services\SubscriptionService;

$status_labels = array(
	SubscriptionService::STATUS_TRIALING => __( 'Free trial', 'comsign' ),
	SubscriptionService::STATUS_ACTIVE   => __( 'Active', 'comsign' ),
	SubscriptionService::STATUS_PAST_DUE => __( 'Payment due', 'comsign' ),
	SubscriptionService::STATUS_CANCELED => __( 'Canceled', 'comsign' ),
	SubscriptionService::STATUS_EXPIRED  => __( 'Expired', 'comsign' ),
	SubscriptionService::STATUS_NONE     => __( 'No subscription', 'comsign' ),
);

$money = static function ( float $amount ) use ( $currency ): string {
	$symbols = array( 'ILS' => '₪', 'USD' => '$', 'EUR' => '€' );
	$sym     = $symbols[ $currency ] ?? ( $currency . ' ' );
	return $sym . number_format_i18n( $amount );
};

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';

// Cardcom redirects back here after checkout. $activated is the authoritative
// result of the server-to-server verification done in the controller:
// true = paid & plan activated, false = not completed, null = not a return.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paid = isset( $_GET['paid'] ) ? sanitize_key( wp_unslash( $_GET['paid'] ) ) : '';
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Plan & billing', 'comsign' ); ?></h1>

		<?php if ( true === $activated ) : ?>
			<div class="comsign-portal-flash is-success" role="status"><?php esc_html_e( 'Payment received — your plan is now active.', 'comsign' ); ?></div>
		<?php elseif ( false === $activated ) : ?>
			<div class="comsign-portal-flash is-error" role="status"><?php esc_html_e( 'We could not confirm your payment. If you were charged, it will activate shortly — otherwise you can try again below.', 'comsign' ); ?></div>
		<?php elseif ( '0' === $paid ) : ?>
			<div class="comsign-portal-flash is-error" role="status"><?php esc_html_e( 'Payment was not completed. You can try again below.', 'comsign' ); ?></div>
		<?php endif; ?>

		<section class="comsign-card" aria-labelledby="comsign-plan-status-h">
			<h2 id="comsign-plan-status-h"><?php esc_html_e( 'Your subscription', 'comsign' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Status:', 'comsign' ); ?></strong>
				<span class="comsign-pill comsign-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?></span>
				<?php
				$current = Plans::get( $plan_id );
				if ( $current ) :
					?>
					&nbsp;·&nbsp;<strong><?php esc_html_e( 'Plan:', 'comsign' ); ?></strong> <?php echo esc_html( (string) $current['name'] ); ?>
				<?php endif; ?>
			</p>

			<?php if ( $subscription && SubscriptionService::STATUS_TRIALING === $status && ! empty( $subscription->trial_ends_at ) ) : ?>
				<?php $days = max( 0, (int) ceil( ( strtotime( $subscription->trial_ends_at . ' UTC' ) - time() ) / DAY_IN_SECONDS ) ); ?>
				<p class="comsign-intro">
					<?php
					/* translators: %d: days left in trial. */
					echo esc_html( sprintf( __( 'Days left in your free trial: %d', 'comsign' ), $days ) );
					?>
				</p>
			<?php elseif ( $subscription && SubscriptionService::STATUS_ACTIVE === $status && ! empty( $subscription->current_period_end ) ) : ?>
				<p class="comsign-intro">
					<?php
					/* translators: %s: renewal date. */
					echo esc_html( sprintf( __( 'Renews on %s.', 'comsign' ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $subscription->current_period_end ) ) ) );
					?>
				</p>
			<?php endif; ?>

			<p>
				<strong><?php esc_html_e( 'This month:', 'comsign' ); ?></strong>
				<?php
				if ( null === $remaining ) {
					/* translators: %d: documents used. */
					echo esc_html( sprintf( __( '%d documents used (unlimited).', 'comsign' ), $used ) );
				} else {
					/* translators: 1: used, 2: remaining. */
					echo esc_html( sprintf( __( '%1$d used, %2$d remaining.', 'comsign' ), $used, $remaining ) );
				}
				?>
			</p>

			<?php if ( in_array( $status, array( SubscriptionService::STATUS_ACTIVE, SubscriptionService::STATUS_PAST_DUE ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( $action ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel your subscription? You will keep access until the end of the current period.', 'comsign' ) ); ?>');">
					<input type="hidden" name="action" value="comsign_portal_cancel_sub">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="comsign-btn comsign-btn--small"><?php esc_html_e( 'Cancel subscription', 'comsign' ); ?></button>
				</form>
			<?php endif; ?>
		</section>

		<?php if ( ! $configured ) : ?>
			<p class="comsign-empty"><?php esc_html_e( 'Online payment is not set up on this site yet. Please contact support to choose a paid plan.', 'comsign' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Choose a plan', 'comsign' ); ?></h2>
		<div class="comsign-plan-grid">
			<?php foreach ( $plans as $pid => $plan ) : ?>
				<?php $is_current = ( $pid === $plan_id ) && in_array( $status, array( SubscriptionService::STATUS_ACTIVE ), true ); ?>
				<section class="comsign-plan-card<?php echo $is_current ? ' is-current' : ''; ?>" aria-labelledby="plan-<?php echo esc_attr( $pid ); ?>-h">
					<h3 id="plan-<?php echo esc_attr( $pid ); ?>-h"><?php echo esc_html( (string) $plan['name'] ); ?></h3>
					<p class="comsign-plan-price">
						<span class="comsign-plan-amount"><?php echo esc_html( $money( (float) $plan['price']['monthly'] ) ); ?></span>
						<span class="comsign-plan-per"><?php esc_html_e( '/ month', 'comsign' ); ?></span>
					</p>
					<ul class="comsign-plan-limits">
						<?php
						$dpm = (int) ( $plan['limits'][ Plans::LIMIT_DOCS_PER_MONTH ] ?? 0 );
						$seats = (int) ( $plan['limits'][ Plans::LIMIT_SEATS ] ?? 0 );
						?>
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

					<?php if ( $is_current ) : ?>
						<p class="comsign-plan-current"><?php esc_html_e( 'Your current plan', 'comsign' ); ?></p>
					<?php elseif ( $configured ) : ?>
						<div class="comsign-plan-actions">
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<input type="hidden" name="action" value="comsign_portal_checkout">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<input type="hidden" name="plan" value="<?php echo esc_attr( $pid ); ?>">
								<input type="hidden" name="cycle" value="monthly">
								<button type="submit" class="comsign-btn comsign-btn--primary">
									<?php
									/* translators: %s: monthly price. */
									echo esc_html( sprintf( __( 'Monthly — %s', 'comsign' ), $money( (float) $plan['price']['monthly'] ) ) );
									?>
								</button>
							</form>
							<form method="post" action="<?php echo esc_url( $action ); ?>">
								<input type="hidden" name="action" value="comsign_portal_checkout">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<input type="hidden" name="plan" value="<?php echo esc_attr( $pid ); ?>">
								<input type="hidden" name="cycle" value="annual">
								<button type="submit" class="comsign-btn">
									<?php
									/* translators: %s: annual price. */
									echo esc_html( sprintf( __( 'Annual — %s', 'comsign' ), $money( (float) $plan['price']['annual'] ) ) );
									?>
								</button>
							</form>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
