<?php
/**
 * Role -> permission matrix and per-account permission checks.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\AccountRepository;
use ComSign\Services\AccountService;
use ComSign\Support\Roles;

Test::add( 'rbac: role matrix', static function (): void {
	// Owner can do everything; viewer can only view.
	Test::ok( Roles::can( AccountRepository::ROLE_OWNER, Roles::MANAGE_SETTINGS ), 'owner manages settings' );
	Test::ok( Roles::can( AccountRepository::ROLE_OWNER, Roles::DELETE_DOCUMENTS ), 'owner deletes documents' );

	Test::ok( Roles::can( AccountRepository::ROLE_VIEWER, Roles::VIEW_DOCUMENTS ), 'viewer views' );
	Test::ok( ! Roles::can( AccountRepository::ROLE_VIEWER, Roles::CREATE_DOCUMENTS ), 'viewer cannot create' );
	Test::ok( ! Roles::can( AccountRepository::ROLE_VIEWER, Roles::SEND_DOCUMENTS ), 'viewer cannot send' );

	// Sender can create/send but not manage members/settings.
	Test::ok( Roles::can( AccountRepository::ROLE_SENDER, Roles::CREATE_DOCUMENTS ), 'sender creates' );
	Test::ok( Roles::can( AccountRepository::ROLE_SENDER, Roles::SEND_DOCUMENTS ), 'sender sends' );
	Test::ok( ! Roles::can( AccountRepository::ROLE_SENDER, Roles::MANAGE_MEMBERS ), 'sender cannot manage members' );

	// Least privilege: a sender uses templates but does NOT author them.
	Test::ok( ! Roles::can( AccountRepository::ROLE_SENDER, Roles::MANAGE_TEMPLATES ), 'sender cannot manage templates' );
	Test::ok( ! Roles::can( AccountRepository::ROLE_MEMBER, Roles::MANAGE_TEMPLATES ), 'legacy member cannot manage templates' );
	Test::ok( Roles::can( AccountRepository::ROLE_ADMIN, Roles::MANAGE_TEMPLATES ), 'admin manages templates' );
	Test::ok( Roles::can( AccountRepository::ROLE_OWNER, Roles::MANAGE_TEMPLATES ), 'owner manages templates' );

	// Admin can manage members but not settings.
	Test::ok( Roles::can( AccountRepository::ROLE_ADMIN, Roles::MANAGE_MEMBERS ), 'admin manages members' );
	Test::ok( ! Roles::can( AccountRepository::ROLE_ADMIN, Roles::MANAGE_SETTINGS ), 'admin cannot manage settings' );
} );

Test::add( 'rbac: can_in_account', static function (): void {
	$accounts = new AccountService();
	$account  = $accounts->default_account_id();
	$repo     = new AccountRepository();

	$uid = wp_insert_user( array(
		'user_login' => 'rbac_user_' . wp_generate_password( 6, false ),
		'user_pass'  => 'x',
		'user_email' => 'rbac_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'subscriber',
	) );
	Test::ok( ! is_wp_error( $uid ), 'created a test user' );

	$repo->add_member( $account, (int) $uid, AccountRepository::ROLE_VIEWER );
	Test::ok( ! $accounts->can_in_account( (int) $uid, $account, Roles::CREATE_DOCUMENTS ), 'viewer cannot create in account' );

	$repo->add_member( $account, (int) $uid, AccountRepository::ROLE_SENDER );
	Test::ok( $accounts->can_in_account( (int) $uid, $account, Roles::CREATE_DOCUMENTS ), 'sender can create in account' );
	Test::ok( $accounts->can_in_account( (int) $uid, $account, Roles::SEND_DOCUMENTS ), 'sender can send in account' );
} );
