<?php
/**
 * Subscription lifecycle emails (welcome, trial-ending reminder).
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AccountRepository;
use ComSign\Database\SubscriptionRepository;
use ComSign\Support\Settings;

/**
 * Sends transactional emails around the subscription lifecycle. Trial reminders
 * are de-duplicated with a per-account transient so each trial is only nudged
 * once near its end.
 */
final class BillingNotifications {

	private AccountRepository $accounts;
	private SubscriptionRepository $subs;

	public function __construct( ?AccountRepository $accounts = null, ?SubscriptionRepository $subs = null ) {
		$this->accounts = $accounts ?? new AccountRepository();
		$this->subs     = $subs ?? new SubscriptionRepository();
	}

	/**
	 * Email a workspace's owners that their free trial is ending soon.
	 *
	 * @param int $within_days Trials ending within this many days get a nudge.
	 *
	 * @return int Number of accounts reminded.
	 */
	public function send_trial_reminders( int $within_days = 3 ): int {
		$before  = gmdate( 'Y-m-d H:i:s', time() + max( 1, $within_days ) * DAY_IN_SECONDS );
		$reminded = 0;

		foreach ( $this->subs->trials_ending_before( $before ) as $sub ) {
			$account_id = (int) $sub->account_id;
			$key        = 'comsign_trial_nudged_' . $account_id;
			if ( get_transient( $key ) ) {
				continue; // already nudged
			}

			$days = max( 0, (int) ceil( ( strtotime( $sub->trial_ends_at . ' UTC' ) - time() ) / DAY_IN_SECONDS ) );
			$sent = false;
			foreach ( $this->owner_emails( $account_id ) as $email ) {
				$subject = sprintf(
					/* translators: %s: brand name. */
					__( 'Your %s free trial is ending soon', 'comsign' ),
					Settings::brand_name()
				);
				$body  = sprintf( /* translators: %d: days left. */ __( 'Your free trial ends in %d days.', 'comsign' ), $days ) . "\n\n";
				$body .= __( 'To keep sending documents, choose a plan here:', 'comsign' ) . "\n";
				$body .= \ComSign\Frontend\PortalController::url( array( 'view' => 'billing' ) );
				$sent = wp_mail( $email, $subject, $body ) || $sent;
			}

			if ( $sent ) {
				// Don't nudge again for this trial window.
				set_transient( $key, 1, 7 * DAY_IN_SECONDS );
				$reminded++;
			}
		}

		return $reminded;
	}

	/**
	 * Email a workspace's owners that an automatic renewal charge failed.
	 *
	 * @param int $account_id Account id.
	 */
	public function send_payment_failed( int $account_id ): void {
		foreach ( $this->owner_emails( $account_id ) as $email ) {
			$subject = sprintf(
				/* translators: %s: brand name. */
				__( 'Action needed: %s payment failed', 'comsign' ),
				Settings::brand_name()
			);
			$body  = __( 'We could not charge your subscription. Your workspace will keep working for now, but please update your payment to avoid interruption.', 'comsign' ) . "\n\n";
			$body .= __( 'Update your billing here:', 'comsign' ) . "\n";
			$body .= \ComSign\Frontend\PortalController::url( array( 'view' => 'billing' ) );
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * Email a new workspace's owner a short welcome.
	 *
	 * @param int $account_id Account id.
	 */
	public function send_welcome( int $account_id ): void {
		foreach ( $this->owner_emails( $account_id ) as $email ) {
			$subject = sprintf(
				/* translators: %s: brand name. */
				__( 'Welcome to %s', 'comsign' ),
				Settings::brand_name()
			);
			$body  = sprintf( /* translators: %d: trial days. */ __( 'Welcome! Your workspace is ready with a %d-day free trial.', 'comsign' ), \ComSign\Billing\Plans::TRIAL_DAYS ) . "\n\n";
			$body .= __( 'Send your first document for signing here:', 'comsign' ) . "\n";
			$body .= \ComSign\Frontend\PortalController::url();
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * Owner email addresses for an account.
	 *
	 * @return string[]
	 */
	private function owner_emails( int $account_id ): array {
		$emails = array();
		foreach ( $this->accounts->members_of( $account_id ) as $m ) {
			if ( AccountRepository::ROLE_OWNER !== $m->role ) {
				continue;
			}
			$user = get_userdata( (int) $m->user_id );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_unique( $emails );
	}
}
