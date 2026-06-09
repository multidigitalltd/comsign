<?php
/**
 * Subscription lifecycle: trials, status, plan resolution and usage limits.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Billing\Plans;
use ComSign\Database\ContactRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\SubscriptionRepository;

/**
 * One subscription per account. New accounts start a free trial automatically;
 * sending is gated by an active subscription and the plan's monthly document
 * quota. Cardcom billing (Phase 2) flips the status to "active".
 */
final class SubscriptionService {

	public const STATUS_TRIALING = 'trialing';
	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PAST_DUE = 'past_due';
	public const STATUS_CANCELED = 'canceled';
	public const STATUS_EXPIRED  = 'expired';
	// No subscription row at all: an unmanaged/legacy workspace, not billed and
	// not limited. Commercial signups always have a row (auto-trial), so limits
	// only ever apply to managed accounts.
	public const STATUS_NONE     = 'none';

	private SubscriptionRepository $subs;
	private DocumentRepository $documents;

	public function __construct( ?SubscriptionRepository $subs = null, ?DocumentRepository $documents = null ) {
		$this->subs      = $subs ?? new SubscriptionRepository();
		$this->documents = $documents ?? new DocumentRepository();
	}

	/**
	 * The raw subscription row for an account (or null).
	 */
	public function for_account( int $account_id ): ?object {
		return $this->subs->for_account( $account_id );
	}

	/**
	 * Start a free trial on a plan (idempotent: no-op if one already exists).
	 *
	 * @param int    $account_id Account id.
	 * @param string $plan       Plan id (defaults to the entry plan).
	 * @param string $cycle      'monthly' | 'annual'.
	 */
	public function start_trial( int $account_id, string $plan = '', string $cycle = 'monthly' ): void {
		if ( $account_id <= 0 || $this->subs->for_account( $account_id ) ) {
			return;
		}
		$plan  = Plans::exists( $plan ) ? $plan : Plans::default_id();
		$cycle = 'annual' === $cycle ? 'annual' : 'monthly';

		$this->subs->upsert(
			$account_id,
			array(
				'plan'          => $plan,
				'cycle'         => $cycle,
				'status'        => self::STATUS_TRIALING,
				'trial_ends_at' => gmdate( 'Y-m-d H:i:s', time() + Plans::TRIAL_DAYS * DAY_IN_SECONDS ),
			)
		);
	}

	/**
	 * Ensure an account has a subscription, starting a trial if it has none.
	 */
	public function ensure( int $account_id ): ?object {
		if ( ! $this->subs->for_account( $account_id ) ) {
			$this->start_trial( $account_id );
		}
		return $this->subs->for_account( $account_id );
	}

	/**
	 * The effective status of an account's subscription.
	 *
	 * A trial whose end date has passed is reported as EXPIRED even though the
	 * stored status is still "trialing", so callers don't have to re-check dates.
	 */
	public function status( int $account_id ): string {
		$sub = $this->subs->for_account( $account_id );
		if ( ! $sub ) {
			return self::STATUS_NONE;
		}
		if ( self::STATUS_TRIALING === $sub->status && $this->is_past( $sub->trial_ends_at ) ) {
			return self::STATUS_EXPIRED;
		}
		if ( self::STATUS_ACTIVE === $sub->status && $sub->current_period_end && $this->is_past( $sub->current_period_end ) ) {
			// A subscription scheduled to cancel simply ends when the paid period
			// runs out; one that should have renewed is payment-due.
			return ! empty( $sub->cancel_at_period_end ) ? self::STATUS_EXPIRED : self::STATUS_PAST_DUE;
		}
		return (string) $sub->status;
	}

	/**
	 * Whether the account may currently use paid functionality.
	 */
	public function is_active( int $account_id ): bool {
		return in_array( $this->status( $account_id ), array( self::STATUS_NONE, self::STATUS_TRIALING, self::STATUS_ACTIVE ), true );
	}

	/**
	 * Whether this account is managed by a subscription (billed/limited).
	 */
	public function is_managed( int $account_id ): bool {
		return self::STATUS_NONE !== $this->status( $account_id );
	}

	/**
	 * The plan id in effect for an account (defaults to the entry plan).
	 */
	public function plan_id( int $account_id ): string {
		$sub = $this->subs->for_account( $account_id );
		$plan = $sub ? (string) $sub->plan : '';
		return Plans::exists( $plan ) ? $plan : Plans::default_id();
	}

	/**
	 * Whether the account's plan grants a feature.
	 */
	public function has_feature( int $account_id, string $feature ): bool {
		return Plans::has_feature( $this->plan_id( $account_id ), $feature );
	}

	/**
	 * Documents created in the current calendar month for the account.
	 */
	public function documents_used( int $account_id ): int {
		return $this->documents->count_for_account_since( $account_id, gmdate( 'Y-m-01 00:00:00' ) );
	}

	/**
	 * Remaining documents this month, or null when the plan is unlimited.
	 */
	public function documents_remaining( int $account_id ): ?int {
		// Unmanaged (legacy) workspaces have no quota.
		if ( ! $this->is_managed( $account_id ) ) {
			return null;
		}
		$limit = Plans::limit( $this->plan_id( $account_id ), Plans::LIMIT_DOCS_PER_MONTH );
		if ( $limit <= 0 ) {
			return null; // unlimited
		}
		return max( 0, $limit - $this->documents_used( $account_id ) );
	}

	/**
	 * Contacts saved in the workspace's address book.
	 */
	public function contacts_used( int $account_id ): int {
		return ( new ContactRepository() )->count_for_account( $account_id );
	}

	/**
	 * Remaining address-book contacts this plan allows, or null when unlimited.
	 */
	public function contacts_remaining( int $account_id ): ?int {
		if ( ! $this->is_managed( $account_id ) ) {
			return null; // unmanaged/legacy workspaces have no cap
		}
		$limit = Plans::limit( $this->plan_id( $account_id ), Plans::LIMIT_CONTACTS );
		if ( $limit <= 0 ) {
			return null; // unlimited
		}
		return max( 0, $limit - $this->contacts_used( $account_id ) );
	}

	/**
	 * Throw a user-facing error if the account is at its contact limit.
	 *
	 * @throws \RuntimeException When the address book is full for the plan.
	 */
	public function assert_can_add_contact( int $account_id ): void {
		$remaining = $this->contacts_remaining( $account_id );
		if ( null !== $remaining && $remaining <= 0 ) {
			throw new \RuntimeException( __( 'You have reached your plan’s contact limit. Upgrade your plan to add more contacts.', 'comsign' ) );
		}
	}

	/**
	 * Whether the account can send another document right now.
	 */
	public function can_send( int $account_id ): bool {
		if ( ! $this->is_active( $account_id ) ) {
			return false;
		}
		$remaining = $this->documents_remaining( $account_id );
		return null === $remaining || $remaining > 0;
	}

	/**
	 * Throw a user-facing error if the account cannot send.
	 *
	 * @throws \RuntimeException When the subscription is inactive or over quota.
	 */
	public function assert_can_send( int $account_id ): void {
		// Unmanaged/legacy workspaces are never blocked.
		if ( ! $this->is_managed( $account_id ) ) {
			return;
		}
		if ( ! $this->is_active( $account_id ) ) {
			throw new \RuntimeException( __( 'Your subscription is not active. Please choose a plan to keep sending documents.', 'comsign' ) );
		}
		$remaining = $this->documents_remaining( $account_id );
		if ( null !== $remaining && $remaining <= 0 ) {
			throw new \RuntimeException( __( 'You have reached your plan’s monthly document limit. Upgrade your plan to send more.', 'comsign' ) );
		}
	}

	/**
	 * Switch an account to a different plan/cycle (keeps current status).
	 */
	public function change_plan( int $account_id, string $plan, string $cycle ): void {
		if ( ! Plans::exists( $plan ) ) {
			return;
		}
		$this->subs->upsert(
			$account_id,
			array(
				'plan'  => $plan,
				'cycle' => 'annual' === $cycle ? 'annual' : 'monthly',
			)
		);
	}

	/**
	 * Mark a subscription active for a paid period (called after payment).
	 *
	 * @param int    $account_id  Account id.
	 * @param string $plan        Plan id.
	 * @param string $cycle       'monthly' | 'annual'.
	 * @param string $period_end  UTC 'Y-m-d H:i:s' end of the paid period.
	 * @param string $token       Recurring-billing token (already encrypted).
	 */
	public function activate( int $account_id, string $plan, string $cycle, string $period_end, string $token = '' ): void {
		$data = array(
			'plan'                 => Plans::exists( $plan ) ? $plan : Plans::default_id(),
			'cycle'                => 'annual' === $cycle ? 'annual' : 'monthly',
			'status'               => self::STATUS_ACTIVE,
			'current_period_end'   => $period_end,
			// A fresh payment / reactivation clears any pending cancellation.
			'cancel_at_period_end' => 0,
		);
		if ( '' !== $token ) {
			$data['cardcom_token'] = $token;
		}
		$this->subs->upsert( $account_id, $data );
	}

	/**
	 * Cancel a subscription.
	 *
	 * If the account still has paid time left, the cancellation is scheduled for
	 * the end of the current period: the status stays active and access
	 * continues until then, but the subscription will not auto-renew. With no
	 * paid period left (e.g. a trial), the cancellation takes effect immediately.
	 */
	public function cancel( int $account_id ): void {
		$sub = $this->subs->for_account( $account_id );
		if ( $sub && self::STATUS_ACTIVE === $sub->status && ! empty( $sub->current_period_end ) && ! $this->is_past( $sub->current_period_end ) ) {
			$this->subs->upsert( $account_id, array( 'cancel_at_period_end' => 1 ) );
			return;
		}
		$this->subs->upsert( $account_id, array( 'status' => self::STATUS_CANCELED, 'cancel_at_period_end' => 0 ) );
	}

	/**
	 * Undo a scheduled cancellation (keep the subscription renewing).
	 */
	public function resume( int $account_id ): void {
		$this->subs->upsert( $account_id, array( 'cancel_at_period_end' => 0 ) );
	}

	/**
	 * Whether the account is active but scheduled to cancel at period end.
	 */
	public function is_canceling( int $account_id ): bool {
		$sub = $this->subs->for_account( $account_id );
		return $sub && ! empty( $sub->cancel_at_period_end ) && self::STATUS_ACTIVE === (string) $sub->status;
	}

	/**
	 * Flag a subscription as payment-due (a renewal charge failed).
	 */
	public function mark_past_due( int $account_id ): void {
		$this->subs->upsert( $account_id, array( 'status' => self::STATUS_PAST_DUE ) );
	}

	/**
	 * Whether a stored UTC datetime is in the past.
	 *
	 * @param string|null $datetime UTC 'Y-m-d H:i:s' or null.
	 */
	private function is_past( ?string $datetime ): bool {
		return ! empty( $datetime ) && strtotime( $datetime . ' UTC' ) < time();
	}
}
