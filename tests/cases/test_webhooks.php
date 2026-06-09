<?php
/**
 * Outgoing webhooks: SSRF guard, signed-download links, and per-workspace
 * delivery on signing events.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\AccountRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Frontend\SignedDownloadController;
use ComSign\Integrations\AccountWebhooks;
use ComSign\Integrations\Webhooks;
use ComSign\Services\AccountService;
use ComSign\Services\SubscriptionService;

Test::add( 'webhooks: SSRF guard blocks private / non-http targets', static function (): void {
	Test::ok( ! Webhooks::is_safe_url( 'ftp://example.com/x' ), 'non-http(s) rejected' );
	Test::ok( ! Webhooks::is_safe_url( 'javascript:alert(1)' ), 'javascript: rejected' );
	Test::ok( ! Webhooks::is_safe_url( 'http://127.0.0.1/hook' ), 'loopback rejected' );
	Test::ok( ! Webhooks::is_safe_url( 'http://10.0.0.5/hook' ), 'private 10/8 rejected' );
	Test::ok( ! Webhooks::is_safe_url( 'http://192.168.1.10/hook' ), 'private 192.168 rejected' );
	// A public IP literal (no DNS needed) is allowed.
	Test::ok( Webhooks::is_safe_url( 'https://93.184.216.34/hook' ), 'public IP allowed' );
} );

Test::add( 'webhooks: signed-download link is bound to the document hash', static function (): void {
	$hash = str_repeat( 'a', 64 );
	$url  = SignedDownloadController::url( 321, $hash );
	Test::ok( false !== strpos( $url, 'action=comsign_signed_download' ), 'targets the download action' );
	Test::ok( false !== strpos( $url, 'doc=321' ), 'carries the document id' );

	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$expected = hash_hmac( 'sha256', '321|' . $hash, wp_salt( 'auth' ) );
	Test::equals( $expected, $q['sig'] ?? '', 'signature is HMAC(doc|hash)' );

	// A different hash yields a different signature (can't be reused).
	parse_str( (string) wp_parse_url( SignedDownloadController::url( 321, str_repeat( 'b', 64 ) ), PHP_URL_QUERY ), $q2 );
	Test::ok( ( $q2['sig'] ?? '' ) !== ( $q['sig'] ?? '' ), 'signature changes with the hash' );
} );

Test::add( 'webhooks: per-workspace delivery fires on completion with a download link', static function (): void {
	reset_tables();
	$accounts = new AccountService();
	$repo     = new AccountRepository();
	$docs     = new DocumentRepository();
	$subs     = new SubscriptionService();

	$account = $accounts->create_account( 'WH Co', 0 );
	$repo->update_webhook( $account, 'https://93.184.216.34/hook', 'shh-secret' );
	// New workspaces auto-trial on the entry plan; move to enterprise (which
	// grants the webhooks feature).
	$subs->change_plan( $account, 'enterprise', 'monthly' );
	Test::ok( $subs->has_feature( $account, \ComSign\Billing\Plans::FEATURE_WEBHOOKS ), 'plan grants webhooks' );

	$doc_id = $docs->create( array( 'title' => 'Deal', 'account_id' => $account, 'created_by' => 0 ) );
	$docs->update( $doc_id, array( 'status' => DocumentRepository::STATUS_COMPLETED, 'signed_hash' => str_repeat( 'c', 64 ), 'signed_path' => '/x/doc.pdf' ) );

	// Intercept the outgoing HTTP request.
	$captured = array();
	$filter   = static function ( $pre, $args, $url ) use ( &$captured ) {
		$captured = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => '' );
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );

	( new AccountWebhooks() )->deliver( \ComSign\Audit\AuditLogger::EVENT_COMPLETED, $doc_id, 0, array() );

	remove_filter( 'pre_http_request', $filter, 10 );

	Test::equals( 'https://93.184.216.34/hook', $captured['url'] ?? '', 'posted to the workspace webhook' );
	$body = json_decode( (string) ( $captured['args']['body'] ?? '' ), true );
	Test::ok( is_array( $body ), 'a JSON body was sent' );
	Test::equals( 'completed', $body['event'] ?? '', 'event is completed' );
	Test::ok( ! empty( $body['download_url'] ) && false !== strpos( $body['download_url'], 'comsign_signed_download' ), 'includes a signed-download link' );

	// The signature header matches HMAC of the body with the workspace secret.
	$sig = $captured['args']['headers']['X-ComSign-Signature'] ?? '';
	Test::equals( hash_hmac( 'sha256', (string) $captured['args']['body'], 'shh-secret' ), $sig, 'body is HMAC-signed with the secret' );
} );

Test::add( 'webhooks: no delivery without the plan feature', static function (): void {
	reset_tables();
	$accounts = new AccountService();
	$repo     = new AccountRepository();
	$docs     = new DocumentRepository();
	$subs     = new SubscriptionService();

	$account = $accounts->create_account( 'Free Co', 0 );
	$repo->update_webhook( $account, 'https://93.184.216.34/hook', 'secret' );
	$subs->start_trial( $account, 'solo' ); // solo does NOT grant webhooks

	$doc_id = $docs->create( array( 'title' => 'X', 'account_id' => $account, 'created_by' => 0 ) );
	$docs->update( $doc_id, array( 'status' => DocumentRepository::STATUS_COMPLETED, 'signed_hash' => str_repeat( 'd', 64 ) ) );

	$called = false;
	$filter = static function ( $pre ) use ( &$called ) {
		$called = true;
		return array( 'response' => array( 'code' => 200 ), 'body' => '' );
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	( new AccountWebhooks() )->deliver( \ComSign\Audit\AuditLogger::EVENT_COMPLETED, $doc_id, 0, array() );
	remove_filter( 'pre_http_request', $filter, 10 );

	Test::ok( ! $called, 'no webhook is sent for a plan without the feature' );
} );
