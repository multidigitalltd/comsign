<?php
/**
 * Subscriptions: trials, status, plan resolution, monthly quota, and the send
 * gate that enforces them.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Billing\Plans;
use ComSign\Database\DocumentRepository;
use ComSign\Database\SubscriptionRepository;
use ComSign\Services\AccountService;
use ComSign\Services\DocumentService;
use ComSign\Services\SubscriptionService;

Test::add( 'subscriptions: trial lifecycle + grandfathering', static function (): void {
	reset_tables();
	$subs = new SubscriptionService();
	$acct = 91001; // arbitrary id; subscription logic is keyed by account id

	// No row at all => unmanaged/grandfathered (never billed or limited).
	Test::equals( SubscriptionService::STATUS_NONE, $subs->status( $acct ), 'no subscription => none' );
	Test::ok( ! $subs->is_managed( $acct ), 'unmanaged' );
	Test::ok( $subs->is_active( $acct ), 'grandfathered counts as active' );
	Test::ok( $subs->can_send( $acct ), 'unmanaged can send' );

	$subs->start_trial( $acct, 'business', 'annual' );
	Test::equals( SubscriptionService::STATUS_TRIALING, $subs->status( $acct ), 'trialing after start' );
	Test::ok( $subs->is_managed( $acct ) && $subs->is_active( $acct ), 'managed + active' );
	Test::equals( 'business', $subs->plan_id( $acct ), 'plan stored' );
	Test::ok( $subs->has_feature( $acct, Plans::FEATURE_PKI ), 'business plan grants PKI' );

	// Idempotent: a second start_trial does not overwrite the plan.
	$subs->start_trial( $acct, 'solo' );
	Test::equals( 'business', $subs->plan_id( $acct ), 'second start_trial is a no-op' );
} );

Test::add( 'subscriptions: expired trial blocks sending', static function (): void {
	reset_tables();
	$subs = new SubscriptionService();
	$repo = new SubscriptionRepository();
	$acct = 91002;

	$subs->start_trial( $acct, 'solo' );
	$repo->set_datetime( $acct, 'trial_ends_at', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

	Test::equals( SubscriptionService::STATUS_EXPIRED, $subs->status( $acct ), 'past trial => expired' );
	Test::ok( ! $subs->is_active( $acct ), 'expired is not active' );
	Test::ok( ! $subs->can_send( $acct ), 'cannot send when expired' );
	Test::throws( static function () use ( $subs, $acct ): void {
		$subs->assert_can_send( $acct );
	}, 'assert_can_send throws when expired' );
} );

Test::add( 'subscriptions: monthly document quota', static function (): void {
	reset_tables();
	$accounts = new AccountService();
	$account  = $accounts->default_account_id();
	$subs     = new SubscriptionService();

	// Put the entry plan on a tiny quota for the test.
	$filter = static function ( $plans ) {
		$plans['solo']['limits']['docs_per_month'] = 2;
		return $plans;
	};
	add_filter( 'comsign_plans', $filter );

	$subs->start_trial( $account, 'solo' );
	Test::equals( 2, $subs->documents_remaining( $account ), 'starts with the full monthly quota' );

	$svc = new DocumentService();
	$svc->create_from_text( 'A', '<p>x</p>', array() ); // created in the default (current) account
	Test::equals( 1, $subs->documents_remaining( $account ), 'one document used' );
	$svc->create_from_text( 'B', '<p>x</p>', array() );
	Test::equals( 0, $subs->documents_remaining( $account ), 'quota exhausted' );
	Test::ok( ! $subs->can_send( $account ), 'cannot send over quota' );
	Test::throws( static function () use ( $subs, $account ): void {
		$subs->assert_can_send( $account );
	}, 'assert_can_send throws over quota' );

	remove_filter( 'comsign_plans', $filter );
} );

Test::add( 'subscriptions: new workspace auto-starts a trial', static function (): void {
	reset_tables();
	$accounts = new AccountService();
	$uid      = wp_insert_user( array(
		'user_login' => 'sub_owner_' . wp_generate_password( 6, false ),
		'user_pass'  => 'x',
		'user_email' => 'subo_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'subscriber',
	) );
	$new = $accounts->create_account( 'New Workspace', (int) $uid );

	$subs = new SubscriptionService();
	Test::equals( SubscriptionService::STATUS_TRIALING, $subs->status( $new ), 'a fresh workspace is trialing' );
	Test::equals( Plans::default_id(), $subs->plan_id( $new ), 'on the entry plan' );
} );

Test::add( 'subscriptions: send() is gated by the subscription', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$subs    = new SubscriptionService();
	$repo    = new SubscriptionRepository();

	$doc_id = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$s      = $svc->add_signer( $doc_id, 'A', 'a@example.com' );
	$svc->save_fields( $doc_id, array(
		array( 'signer_id' => $s, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
	) );

	// Move the document into a managed account whose trial has expired.
	$acct = 91003;
	$subs->start_trial( $acct, 'solo' );
	$repo->set_datetime( $acct, 'trial_ends_at', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
	$docs->update( $doc_id, array( 'account_id' => $acct ) );

	Test::throws( static function () use ( $svc, $doc_id ): void {
		$svc->send( $doc_id );
	}, 'send() is refused when the workspace subscription is inactive' );
} );

Test::add( 'subscriptions: cancellation keeps access until period end', static function (): void {
	reset_tables();
	$subs = new SubscriptionService();
	$repo = new SubscriptionRepository();
	$acct = 91010;

	// Active subscription with a paid period a week into the future.
	$subs->start_trial( $acct, 'business' );
	$future = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
	$subs->activate( $acct, 'business', 'monthly', $future );
	Test::ok( ! $subs->is_canceling( $acct ), 'not canceling right after activation' );

	// Cancelling schedules end-of-period: still active, still able to send.
	$subs->cancel( $acct );
	Test::equals( SubscriptionService::STATUS_ACTIVE, $subs->status( $acct ), 'stays active until period end' );
	Test::ok( $subs->is_active( $acct ), 'access continues after scheduling cancellation' );
	Test::ok( $subs->is_canceling( $acct ), 'flagged as canceling' );

	// A scheduled-cancel subscription is excluded from auto-renewal.
	$repo->set_datetime( $acct, 'current_period_end', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
	$due_ids = array_map( static fn( $r ) => (int) $r->account_id, $repo->due_for_renewal() );
	Test::ok( ! in_array( $acct, $due_ids, true ), 'not picked up for renewal once canceling' );

	// Once the period has passed, it reports expired (not payment-due).
	Test::equals( SubscriptionService::STATUS_EXPIRED, $subs->status( $acct ), 'expires after the period ends' );
	Test::ok( ! $subs->is_active( $acct ), 'no access after expiry' );

	// Resuming before expiry keeps it renewing.
	reset_tables();
	$subs->start_trial( $acct, 'business' );
	$subs->activate( $acct, 'business', 'monthly', $future );
	$subs->cancel( $acct );
	$subs->resume( $acct );
	Test::ok( ! $subs->is_canceling( $acct ), 'resume clears the schedule' );
	Test::equals( SubscriptionService::STATUS_ACTIVE, $subs->status( $acct ), 'still active after resume' );
} );

Test::add( 'subscriptions: cancelling a trial takes effect immediately', static function (): void {
	reset_tables();
	$subs = new SubscriptionService();
	$acct = 91011;

	// A trial has no paid period, so cancellation is immediate.
	$subs->start_trial( $acct, 'solo' );
	$subs->cancel( $acct );
	Test::equals( SubscriptionService::STATUS_CANCELED, $subs->status( $acct ), 'trial cancels immediately' );
	Test::ok( ! $subs->is_active( $acct ), 'no access after cancelling a trial' );
} );
