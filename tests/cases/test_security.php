<?php
/**
 * Security regression tests: token handling, signer-token isolation,
 * cross-tenant document access (IDOR), and webhook SSRF protection.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\AccountRepository;
use ComSign\Database\SignerRepository;
use ComSign\Integrations\Webhooks;
use ComSign\Services\AccountService;
use ComSign\Services\DocumentService;
use ComSign\Support\Settings;
use ComSign\Support\Tokens;

Test::add( 'security: signer tokens are random, hashed and constant-time', static function (): void {
	$a = Tokens::generate();
	$b = Tokens::generate();

	Test::equals( 64, strlen( $a ), 'token is 64 hex chars (256-bit)' );
	Test::ok( ctype_xdigit( $a ), 'token is hex' );
	Test::ok( $a !== $b, 'tokens are unique' );

	$hash = Tokens::hash( $a );
	Test::ok( $hash !== $a, 'stored hash differs from the raw token' );
	Test::equals( 64, strlen( $hash ), 'hash is sha256 (64 hex)' );

	Test::ok( Tokens::verify( $a, $hash ), 'correct token verifies' );
	Test::ok( ! Tokens::verify( $b, $hash ), 'wrong token does not verify' );
	Test::ok( ! Tokens::verify( '', $hash ), 'empty token does not verify' );
} );

Test::add( 'security: a token resolves only its own signer', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$signers = new SignerRepository();

	$doc = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$svc->add_signer( $doc, 'A', 'a@example.com' );
	$svc->add_signer( $doc, 'B', 'b@example.com' );

	// Mint a fresh link for signer A and capture the raw token.
	$rows = $signers->for_document( $doc );
	$raw  = $svc->generate_link( $doc, (int) $rows[0]->id );
	// generate_link returns a full URL; pull the token query arg out of it.
	$token = '';
	$qs    = (string) wp_parse_url( $raw, PHP_URL_QUERY );
	parse_str( $qs, $args );
	$token = (string) ( $args['comsign_token'] ?? $args['token'] ?? '' );
	Test::ok( '' !== $token, 'a raw token was issued' );

	$resolved = $signers->find_by_token_hash( Tokens::hash( $token ) );
	Test::ok( $resolved && (int) $resolved->id === (int) $rows[0]->id, 'token resolves to signer A' );

	// A wrong/forged token resolves to nobody.
	Test::ok( null === $signers->find_by_token_hash( Tokens::hash( 'forged-token' ) ), 'forged token resolves to no signer' );
} );

Test::add( 'security: cross-tenant document access is denied (IDOR)', static function (): void {
	reset_tables();
	$accounts = new AccountService();
	$repo     = new AccountRepository();
	$svc      = new DocumentService();

	$account = $accounts->default_account_id();
	$doc_id  = $svc->create_from_text( 'Tenant A doc', '<p>x</p>', array() );
	$doc     = ( new \ComSign\Database\DocumentRepository() )->find( $doc_id );

	// An outsider with no membership cannot access the document.
	$outsider = wp_insert_user( array(
		'user_login' => 'outsider_' . wp_generate_password( 6, false ),
		'user_pass'  => 'x',
		'user_email' => 'out_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'subscriber',
	) );
	Test::ok( ! $accounts->can_access_document( $doc, (int) $outsider ), 'outsider cannot access the document' );
	Test::ok( ! $accounts->can_access_document( null, (int) $outsider ), 'null document is never accessible' );

	// A member of the document's account can access it.
	$member = wp_insert_user( array(
		'user_login' => 'member_' . wp_generate_password( 6, false ),
		'user_pass'  => 'x',
		'user_email' => 'mem_' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'subscriber',
	) );
	$repo->add_member( $account, (int) $member, AccountRepository::ROLE_VIEWER );
	Test::ok( $accounts->can_access_document( $doc, (int) $member ), 'account member can access the document' );
} );

Test::add( 'security: webhook SSRF targets are blocked', static function (): void {
	$blocked = array(
		'http://127.0.0.1/hook',        // loopback
		'http://localhost/hook',        // loopback by name
		'http://10.0.0.5/hook',         // private
		'http://192.168.1.10/hook',     // private
		'http://169.254.169.254/latest',// cloud metadata (link-local)
		'ftp://example.com/hook',       // non-http scheme
	);
	$webhooks = new Webhooks();
	foreach ( $blocked as $url ) {
		Settings::update( array( 'webhook_url' => $url ) );
		$result = $webhooks->send_test();
		Test::ok( empty( $result['ok'] ), 'blocked SSRF target: ' . $url );
	}
	// Clean up so we never keep a junk URL configured.
	Settings::update( array( 'webhook_url' => '' ) );
} );

Test::add( 'security: identity challenge locks out after repeated failures', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$signers = new SignerRepository();

	$doc = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$svc->add_signer( $doc, 'A', 'a@example.com' );
	$signer = $signers->for_document( $doc )[0];

	Test::ok( ! \ComSign\Frontend\SignerAuth::is_locked_out( $signer ), 'not locked out initially' );

	for ( $i = 0; $i < \ComSign\Frontend\SignerAuth::MAX_ATTEMPTS; $i++ ) {
		Test::ok( ! \ComSign\Frontend\SignerAuth::is_locked_out( $signer ), 'still open during attempt ' . ( $i + 1 ) );
		\ComSign\Frontend\SignerAuth::register_failed_attempt( $signer );
	}

	Test::ok( \ComSign\Frontend\SignerAuth::is_locked_out( $signer ), 'locked out after MAX_ATTEMPTS failures' );

	\ComSign\Frontend\SignerAuth::clear_attempts( $signer );
	Test::ok( ! \ComSign\Frontend\SignerAuth::is_locked_out( $signer ), 'cleared on success' );
} );
