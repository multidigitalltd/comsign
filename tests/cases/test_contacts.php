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
