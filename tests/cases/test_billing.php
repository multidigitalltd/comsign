<?php
/**
 * Billing: signed checkout intent (return value), and completing a verified
 * gateway result to activate a subscription — exercised with a fake gateway.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Billing\GatewayInterface;
use ComSign\Database\SubscriptionRepository;
use ComSign\Services\BillingService;
use ComSign\Services\SubscriptionService;
use ComSign\Support\Crypto;

/**
 * A scripted gateway: captures the return value from checkout and echoes it back
 * on verification, like a real provider round-tripping our ReturnValue.
 */
final class FakeGateway implements GatewayInterface {

	public string $captured_return = '';
	public bool $paid              = true;
	public string $token           = 'TOK-123';
	public bool $renew_ok          = true;
	public int $charges            = 0;

	public function create_checkout( array $args ): array {
		$this->captured_return = (string) ( $args['return_value'] ?? '' );
		return array( 'ok' => true, 'url' => 'https://pay.example/checkout/abc', 'reference' => 'LP-ABC', 'error' => '' );
	}

	public function verify_transaction( string $reference ): array {
		return array(
			'ok'           => true,
			'paid'         => $this->paid,
			'return_value' => $this->captured_return,
			'token'        => $this->token,
			'amount'       => 149.0,
			'error'        => '',
		);
	}

	public function charge_token( array $args ): array {
		$this->charges++;
		return array( 'ok' => true, 'paid' => $this->renew_ok, 'error' => '' );
	}
}

Test::add( 'billing: return value is HMAC-signed', static function (): void {
	$rv = BillingService::encode_return_value( 42, 'business', 'annual' );
	$decoded = BillingService::decode_return_value( $rv );
	Test::ok( is_array( $decoded ), 'valid return value decodes' );
	Test::equals( 42, $decoded['account_id'], 'account preserved' );
	Test::equals( 'business', $decoded['plan'], 'plan preserved' );
	Test::equals( 'annual', $decoded['cycle'], 'cycle preserved' );

	// Tampering breaks the signature.
	Test::ok( null === BillingService::decode_return_value( $rv . 'x' ), 'tampered signature rejected' );
	$swapped = str_replace( 'business', 'enterprise', $rv );
	Test::ok( null === BillingService::decode_return_value( $swapped ), 'changed plan rejected' );
	Test::ok( null === BillingService::decode_return_value( 'garbage' ), 'garbage rejected' );
} );

Test::add( 'billing: checkout + verified completion activates the subscription', static function (): void {
	reset_tables();
	$fake = new FakeGateway();
	$svc  = new BillingService( $fake );
	$subs = new SubscriptionService();
	$repo = new SubscriptionRepository();
	$acct = 50501;

	// Start on a trial, then "buy" the Business annual plan.
	$subs->start_trial( $acct, 'solo' );
	$checkout = $svc->start_checkout( $acct, 'business', 'annual', array(
		'success_url' => 'https://site/ok',
		'failed_url'  => 'https://site/no',
		'webhook_url' => 'https://site/hook',
	) );
	Test::ok( ! empty( $checkout['ok'] ) && '' !== $checkout['url'], 'checkout returns a redirect URL' );
	Test::ok( '' !== $fake->captured_return, 'a signed return value was sent to the gateway' );

	// Cardcom calls back; we re-verify server-to-server and activate.
	$activated = $svc->complete_from_reference( 'LP-ABC' );
	Test::ok( $activated, 'a paid, verified result activates the subscription' );

	Test::equals( SubscriptionService::STATUS_ACTIVE, $subs->status( $acct ), 'status is active' );
	Test::equals( 'business', $subs->plan_id( $acct ), 'plan switched to business' );

	// The recurring token is stored encrypted and decrypts back.
	$row = $repo->for_account( $acct );
	Test::ok( $row && '' !== (string) $row->cardcom_token && 'TOK-123' !== (string) $row->cardcom_token, 'token stored, not plaintext' );
	Test::equals( 'TOK-123', Crypto::decrypt( (string) $row->cardcom_token ), 'token decrypts back' );
} );

Test::add( 'billing: unpaid or forged results never activate', static function (): void {
	reset_tables();
	$subs = new SubscriptionService();
	$acct = 50502;
	$subs->start_trial( $acct, 'solo' );

	// Unpaid result.
	$fake = new FakeGateway();
	$fake->paid = false;
	$svc = new BillingService( $fake );
	$svc->start_checkout( $acct, 'business', 'monthly', array() );
	Test::ok( ! $svc->complete_from_reference( 'LP-ABC' ), 'unpaid result does not activate' );
	Test::equals( SubscriptionService::STATUS_TRIALING, $subs->status( $acct ), 'still trialing after unpaid' );

	// Paid but forged return value (not produced by us).
	$fake2 = new FakeGateway();
	$fake2->captured_return = '50502|business|monthly|deadbeef'; // bad signature
	$svc2 = new BillingService( $fake2 );
	Test::ok( ! $svc2->complete_from_reference( 'LP-ABC' ), 'forged return value rejected' );
} );

Test::add( 'billing: due renewals are charged and the period is extended', static function (): void {
	reset_tables();
	$fake = new FakeGateway();
	$svc  = new BillingService( $fake );
	$subs = new SubscriptionService();
	$repo = new SubscriptionRepository();
	$acct = 50601;

	// An active subscription with a stored token whose period has just ended.
	$subs->start_trial( $acct, 'business' );
	$subs->activate( $acct, 'business', 'monthly', gmdate( 'Y-m-d H:i:s', time() - 3600 ), Crypto::encrypt( 'TOK-RENEW' ) );

	$result = $svc->run_renewals();
	Test::equals( 1, $result['charged'], 'one renewal charged' );
	Test::equals( 1, $fake->charges, 'the gateway was charged once' );

	$row = $repo->for_account( $acct );
	Test::equals( SubscriptionService::STATUS_ACTIVE, $subs->status( $acct ), 'still active after renewal' );
	Test::ok( strtotime( $row->current_period_end . ' UTC' ) > time(), 'period extended into the future' );
} );

Test::add( 'billing: a failed renewal marks the account past_due', static function (): void {
	reset_tables();
	$fake = new FakeGateway();
	$fake->renew_ok = false;
	$svc  = new BillingService( $fake );
	$subs = new SubscriptionService();
	$acct = 50602;

	$subs->start_trial( $acct, 'business' );
	$subs->activate( $acct, 'business', 'monthly', gmdate( 'Y-m-d H:i:s', time() - 3600 ), Crypto::encrypt( 'TOK-X' ) );

	$result = $svc->run_renewals();
	Test::equals( 1, $result['failed'], 'the renewal is recorded as failed' );
	Test::equals( SubscriptionService::STATUS_PAST_DUE, $subs->status( $acct ), 'account is now past due' );
} );
