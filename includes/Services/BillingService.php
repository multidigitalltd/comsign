<?php
/**
 * Billing orchestration: start a hosted checkout and complete it from a
 * verified gateway result, activating the account's subscription.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Billing\CardcomGateway;
use ComSign\Billing\GatewayInterface;
use ComSign\Billing\Plans;

/**
 * Sits between the portal/checkout flow and the payment gateway. The gateway is
 * injectable so the whole flow is unit-testable with a fake. Checkout intent is
 * carried in an HMAC-signed "return value" so the result that comes back can be
 * trusted to map to a real request we made; the transaction itself is always
 * re-verified server-to-server before a subscription is activated.
 */
final class BillingService {

	private GatewayInterface $gateway;
	private SubscriptionService $subscriptions;

	public function __construct( ?GatewayInterface $gateway = null, ?SubscriptionService $subscriptions = null ) {
		$this->gateway       = $gateway ?? new CardcomGateway();
		$this->subscriptions = $subscriptions ?? new SubscriptionService();
	}

	/**
	 * Start a hosted checkout for a plan/cycle and return the redirect URL.
	 *
	 * @param int    $account_id  Account to subscribe.
	 * @param string $plan        Plan id.
	 * @param string $cycle       'monthly' | 'annual'.
	 * @param array  $urls        success_url, failed_url, webhook_url.
	 * @param string $language    Two-letter UI language.
	 *
	 * @return array{ok:bool,url:string,error:string}
	 */
	public function start_checkout( int $account_id, string $plan, string $cycle, array $urls, string $language = 'he' ): array {
		if ( $account_id <= 0 || ! Plans::exists( $plan ) ) {
			return array( 'ok' => false, 'url' => '', 'error' => __( 'Invalid plan.', 'comsign' ) );
		}
		$cycle  = 'annual' === $cycle ? 'annual' : 'monthly';
		$amount = Plans::price( $plan, $cycle );
		$plan_def = Plans::get( $plan );

		return $this->gateway->create_checkout(
			array(
				'amount'       => $amount,
				'currency'     => Plans::currency(),
				'product_name' => sprintf(
					/* translators: 1: plan name, 2: billing cycle. */
					__( 'ComSign %1$s (%2$s)', 'comsign' ),
					(string) ( $plan_def['name'] ?? $plan ),
					'annual' === $cycle ? __( 'annual', 'comsign' ) : __( 'monthly', 'comsign' )
				),
				'return_value' => self::encode_return_value( $account_id, $plan, $cycle ),
				'success_url'  => (string) ( $urls['success_url'] ?? '' ),
				'failed_url'   => (string) ( $urls['failed_url'] ?? '' ),
				'webhook_url'  => (string) ( $urls['webhook_url'] ?? '' ),
				'language'     => $language,
				'create_token' => true,
			)
		);
	}

	/**
	 * Complete a checkout from a gateway reference (LowProfileId), verifying the
	 * transaction server-to-server and activating the subscription if paid.
	 *
	 * @param string $reference Gateway transaction reference.
	 *
	 * @return bool Whether a subscription was activated.
	 */
	public function complete_from_reference( string $reference ): bool {
		$result = $this->gateway->verify_transaction( $reference );
		if ( empty( $result['ok'] ) || empty( $result['paid'] ) ) {
			return false;
		}

		$intent = self::decode_return_value( (string) ( $result['return_value'] ?? '' ) );
		if ( null === $intent ) {
			return false; // forged or stale return value
		}

		$cycle = $intent['cycle'];

		// The HMAC proves account|plan|cycle and the gateway says "paid", but we
		// must also confirm the customer actually paid the price we asked for, in
		// the expected currency — a mismatched or partial payment must not unlock
		// a plan. (Currency is only checked when the gateway reports one.)
		$expected_amount = Plans::price( $intent['plan'], $cycle );
		$paid_amount     = (float) ( $result['amount'] ?? 0 );
		if ( $expected_amount > 0 && abs( $paid_amount - $expected_amount ) > 0.01 ) {
			return false;
		}
		$paid_currency = (string) ( $result['currency'] ?? '' );
		if ( '' !== $paid_currency && 0 !== strcasecmp( $paid_currency, Plans::currency() ) ) {
			return false;
		}

		$period_end = gmdate(
			'Y-m-d H:i:s',
			strtotime( 'annual' === $cycle ? '+1 year' : '+1 month' )
		);

		$token = (string) ( $result['token'] ?? '' );
		$this->subscriptions->activate(
			$intent['account_id'],
			$intent['plan'],
			$cycle,
			$period_end,
			'' !== $token ? \ComSign\Support\Crypto::encrypt( $token ) : ''
		);
		return true;
	}

	/**
	 * Charge due renewals against their stored tokens (called by cron).
	 *
	 * On a successful charge the paid period is extended; on failure the
	 * subscription is marked past_due so access is gated until it is fixed.
	 *
	 * @return array{charged:int,failed:int}
	 */
	public function run_renewals(): array {
		$repo    = new \ComSign\Database\SubscriptionRepository();
		$charged = 0;
		$failed  = 0;

		foreach ( $repo->due_for_renewal() as $sub ) {
			$plan  = (string) $sub->plan;
			$cycle = 'annual' === $sub->cycle ? 'annual' : 'monthly';
			$token = \ComSign\Support\Crypto::decrypt( (string) $sub->cardcom_token );
			if ( '' === $token || ! Plans::exists( $plan ) ) {
				$failed++;
				continue;
			}

			$result = $this->gateway->charge_token(
				array(
					'token'        => $token,
					'amount'       => Plans::price( $plan, $cycle ),
					'currency'     => Plans::currency(),
					'product_name' => sprintf(
						/* translators: 1: plan name, 2: cycle. */
						__( 'ComSign %1$s (%2$s) renewal', 'comsign' ),
						(string) ( Plans::get( $plan )['name'] ?? $plan ),
						'annual' === $cycle ? __( 'annual', 'comsign' ) : __( 'monthly', 'comsign' )
					),
				)
			);

			if ( ! empty( $result['ok'] ) && ! empty( $result['paid'] ) ) {
				$repo->set_datetime(
					(int) $sub->account_id,
					'current_period_end',
					gmdate( 'Y-m-d H:i:s', strtotime( 'annual' === $cycle ? '+1 year' : '+1 month' ) )
				);
				$charged++;
			} else {
				$this->subscriptions->mark_past_due( (int) $sub->account_id );
				( new BillingNotifications() )->send_payment_failed( (int) $sub->account_id );
				$failed++;
			}
		}

		return array( 'charged' => $charged, 'failed' => $failed );
	}

	/* ---------------------------------------------------------------------
	 * Signed return value (checkout intent)
	 * ------------------------------------------------------------------- */

	/**
	 * Encode "account.plan.cycle" with an HMAC so the gateway result can be tied
	 * back to a request we actually made.
	 */
	public static function encode_return_value( int $account_id, string $plan, string $cycle ): string {
		$payload = $account_id . '|' . $plan . '|' . $cycle;
		return $payload . '|' . hash_hmac( 'sha256', $payload, self::secret() );
	}

	/**
	 * Verify and decode a signed return value.
	 *
	 * @return array{account_id:int,plan:string,cycle:string}|null
	 */
	public static function decode_return_value( string $value ): ?array {
		$parts = explode( '|', $value );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		list( $account_id, $plan, $cycle, $sig ) = $parts;
		$expected = hash_hmac( 'sha256', $account_id . '|' . $plan . '|' . $cycle, self::secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}
		if ( ! Plans::exists( $plan ) ) {
			return null;
		}
		return array(
			'account_id' => (int) $account_id,
			'plan'       => $plan,
			'cycle'      => 'annual' === $cycle ? 'annual' : 'monthly',
		);
	}

	/**
	 * HMAC secret (site auth salt; not stored in the database).
	 */
	private static function secret(): string {
		return wp_salt( 'auth' );
	}
}
