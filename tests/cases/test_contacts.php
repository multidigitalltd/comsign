<?php
/**
 * Address book: account-scoped upsert/dedupe, search, isolation, and the
 * passive capture of signers as contacts.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\ContactRepository;
use ComSign\Services\AccountService;
use ComSign\Services\DocumentService;

Test::add( 'contacts: upsert dedupes by account + email', static function (): void {
	reset_tables();
	$repo    = new ContactRepository();
	$account = ( new AccountService() )->default_account_id();

	$id1 = $repo->upsert( $account, 'Dana Cohen', 'dana@example.com', '' );
	Test::ok( $id1 > 0, 'first upsert creates a contact' );

	// Same email + account => updates the same row (no duplicate).
	$id2 = $repo->upsert( $account, 'Dana C.', 'dana@example.com', '+972500000000' );
	Test::equals( $id1, $id2, 'second upsert returns the same id (dedupe)' );

	$all = $repo->for_accounts( array( $account ) );
	Test::equals( 1, count( $all ), 'still a single contact' );
	Test::equals( '+972500000000', $all[0]->phone, 'phone updated on upsert' );

	// No usable email => nothing stored.
	Test::equals( 0, $repo->upsert( $account, 'No Email', '', '' ), 'blank email is not stored' );
	Test::equals( 0, $repo->upsert( $account, 'Bad', 'not-an-email', '' ), 'invalid email is not stored' );
} );

Test::add( 'contacts: search + account isolation', static function (): void {
	reset_tables();
	$repo     = new ContactRepository();
	$accounts = new AccountService();
	$account  = $accounts->default_account_id();

	$repo->upsert( $account, 'Alice Adams', 'alice@example.com', '' );
	$repo->upsert( $account, 'Bob Brown', 'bob@example.com', '' );

	Test::equals( 1, count( $repo->for_accounts( array( $account ), 'alice' ) ), 'search by name' );
	Test::equals( 1, count( $repo->for_accounts( array( $account ), 'bob@' ) ), 'search by email' );
	Test::equals( 2, count( $repo->for_accounts( array( $account ) ) ), 'no filter returns both' );
	Test::equals( 0, count( $repo->for_accounts( array( 999999 ) ) ), 'foreign account sees no contacts' );
	Test::equals( 0, count( $repo->for_accounts( array() ) ), 'empty scope returns nothing' );
} );

Test::add( 'contacts: signers are captured into the address book', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$repo    = new ContactRepository();
	$account = ( new AccountService() )->default_account_id();

	$doc = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$svc->add_signer( $doc, 'Carol King', 'carol@example.com', '+972511111111' );
	$svc->add_signer( $doc, 'No Mail', '' ); // no email => not captured

	$contacts = $repo->for_accounts( array( $account ) );
	Test::equals( 1, count( $contacts ), 'only the emailed signer was captured' );
	Test::equals( 'carol@example.com', $contacts[0]->email, 'captured the right contact' );
	Test::equals( '+972511111111', $contacts[0]->phone, 'captured the phone too' );

	$id = (int) $contacts[0]->id;
	$repo->delete( $id );
	Test::equals( 0, count( $repo->for_accounts( array( $account ) ) ), 'delete removes the contact' );
} );

Test::add( 'contacts: delete is account-scoped (IDOR guard)', static function (): void {
	reset_tables();
	$repo     = new ContactRepository();
	$accounts = new AccountService();
	$account  = $accounts->default_account_id();

	$id      = $repo->upsert( $account, 'Scoped', 'scoped@example.com', '' );
	$contact = $repo->find( $id );
	Test::ok( $contact && (int) $contact->account_id === (int) $account, 'contact stored in the default account' );

	// An outsider's visible accounts do not include the contact's account, so the
	// delete handler's guard (account_id IN visible) would block them.
	$outsider = wp_insert_user( array(
		'user_login' => 'c_out_' . wp_generate_password( 6, false ),
		'user_pass'  => 'x',
		'user_email' => 'cout_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'subscriber',
	) );
	$outsider_visible = array_map( 'intval', $accounts->visible_account_ids( (int) $outsider ) );
	Test::ok( ! in_array( (int) $contact->account_id, $outsider_visible, true ), 'outsider cannot reach the contact (delete blocked)' );

	// The admin (a member of the default account) can.
	$admin_visible = array_map( 'intval', $accounts->visible_account_ids( 1 ) );
	Test::ok( in_array( (int) $contact->account_id, $admin_visible, true ), 'account member may delete it' );
} );

Test::add( 'contacts: count + per-plan limit', static function (): void {
	reset_tables();
	$repo    = new ContactRepository();
	$subs    = new \ComSign\Services\SubscriptionService();
	$account = ( new AccountService() )->default_account_id();

	Test::equals( 0, $repo->count_for_account( $account ), 'empty book counts zero' );
	$repo->upsert( $account, 'A', 'a@example.com', '' );
	$repo->upsert( $account, 'B', 'b@example.com', '' );
	Test::equals( 2, $repo->count_for_account( $account ), 'counts two contacts' );

	// Cap the plan at 2 contacts via the plans filter and start a managed trial.
	add_filter( 'comsign_plans', $cap = static function ( $plans ) {
		if ( isset( $plans['solo'] ) ) {
			$plans['solo']['limits'][ \ComSign\Billing\Plans::LIMIT_CONTACTS ] = 2;
		}
		return $plans;
	} );
	$subs->start_trial( $account, 'solo' );

	Test::equals( 0, $subs->contacts_remaining( $account ), 'no room left at the cap' );
	Test::throws( static function () use ( $subs, $account ): void {
		$subs->assert_can_add_contact( $account );
	}, 'adding past the cap is refused' );

	remove_filter( 'comsign_plans', $cap );
} );

Test::add( 'contacts: signing history by email', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$signers = new \ComSign\Database\SignerRepository();
	$account = ( new AccountService() )->default_account_id();

	// A document signed by dana@.
	$doc_id = $svc->create_from_text( 'Lease', '<p>x</p>', array() );
	$sid    = $svc->add_signer( $doc_id, 'Dana', 'dana@example.com' );
	$signers->update( $sid, array( 'status' => \ComSign\Database\SignerRepository::STATUS_SIGNED, 'signed_at' => gmdate( 'Y-m-d H:i:s' ) ) );

	// A second document only sent (not signed) to the same email.
	$doc2 = $svc->create_from_text( 'Draft', '<p>y</p>', array() );
	$svc->add_signer( $doc2, 'Dana', 'dana@example.com' );

	$found = $signers->documents_signed_by_email( 'dana@example.com', array( $account ) );
	Test::equals( 1, count( $found ), 'only the signed document is returned' );
	Test::equals( $doc_id, (int) $found[0]->id, 'it is the signed lease' );

	Test::equals( 0, count( $signers->documents_signed_by_email( 'nobody@example.com', array( $account ) ) ), 'unknown email has no history' );
} );
