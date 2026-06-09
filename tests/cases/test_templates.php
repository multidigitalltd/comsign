<?php
/**
 * Template account-scoping: save_as_template records the account, and
 * for_accounts() only returns that account's templates.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\TemplateRepository;
use ComSign\Services\AccountService;
use ComSign\Services\DocumentService;
use ComSign\Services\SendReadiness;

Test::add( 'templates: scoping + create-from-template', static function (): void {
	reset_tables();
	$svc      = new DocumentService();
	$tpl      = new TemplateRepository();
	$accounts = new AccountService();
	$account  = $accounts->default_account_id();

	// Build a signable doc, then save it as a template.
	$doc    = $svc->create_from_text( 'Lease', '<p>Sign here</p>', array() );
	$signer = $svc->add_signer( $doc, 'Role A', 'a@example.com' );
	$svc->save_fields( $doc, array(
		array( 'signer_id' => $signer, 'type' => 'signature', 'page' => 1, 'pos_x' => .4, 'pos_y' => .4, 'width' => .3, 'height' => .08 ),
	) );
	$tid = $svc->save_as_template( $doc, 'Lease Template' );

	$row = $tpl->find( $tid );
	Test::equals( $account, (int) $row->account_id, 'template stored in the source account' );

	$mine = $tpl->for_accounts( array( $account ) );
	Test::equals( 1, count( array_filter( $mine, static fn( $t ) => (int) $t->id === $tid ) ), 'for_accounts returns the template' );
	Test::equals( 0, count( $tpl->for_accounts( array( 999999 ) ) ), 'other account sees nothing' );
	Test::equals( array(), $tpl->for_accounts( array() ), 'empty input returns empty' );

	// Creating from the template yields a ready-to-send draft.
	$new = $svc->create_from_template( $tid, array( 0 => array( 'name' => 'Dana', 'email' => 'dana@example.com', 'phone' => '' ) ) );
	Test::ok( ( new SendReadiness() )->check( $new )['ready'], 'document from template is ready to send' );
} );
