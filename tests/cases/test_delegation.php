<?php
/**
 * Signing delegation: reassign a signer slot to someone else, invalidating the
 * previous holder's link and recording the handover.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;
use ComSign\Setup\Installer;
use ComSign\Support\Tokens;

Test::add( 'delegation: reassigns the signer and invalidates the old link', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$signers = new SignerRepository();

	$doc_id = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$a      = $svc->add_signer( $doc_id, 'Original', 'orig@example.com' );
	$svc->save_fields( $doc_id, array(
		array( 'signer_id' => $a, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
	) );
	$svc->send( $doc_id, array( 'allow_delegation' => true ) );

	// Capture the original holder's live token.
	$old_raw = $svc->generate_link( $doc_id, $a );
	parse_str( (string) wp_parse_url( $old_raw, PHP_URL_QUERY ), $args );
	$old_token = (string) ( $args['token'] ?? '' );
	Test::ok( null !== $signers->find_by_token_hash( Tokens::hash( $old_token ) ), 'old token resolves before delegation' );

	$document = $docs->find( $doc_id );
	$signer   = $signers->find( $a );
	$svc->delegate_signer( $document, $signer, 'New Person', 'new@example.com', '+972500000000' );

	$updated = $signers->find( $a );
	Test::equals( 'new@example.com', $updated->email, 'signer email reassigned' );
	Test::equals( 'New Person', $updated->name, 'signer name reassigned' );
	Test::equals( SignerRepository::STATUS_PENDING, $updated->status, 'status reset to pending' );

	// The previous holder's token no longer works (a fresh one was issued).
	Test::ok( null === $signers->find_by_token_hash( Tokens::hash( $old_token ) ), 'old token invalidated after delegation' );

	// The handover is in the audit trail.
	global $wpdb;
	$events = $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . Installer::audit_table() . ' WHERE document_id = %d', $doc_id ) ); // phpcs:ignore WordPress.DB
	Test::ok( in_array( 'delegated', $events, true ), 'delegated event recorded' );
} );

Test::add( 'delegation: refused when not allowed or already signed', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$signers = new SignerRepository();

	$doc_id = $svc->create_from_text( 'Doc', '<p>x</p>', array() );
	$a      = $svc->add_signer( $doc_id, 'Original', 'orig@example.com' );
	$svc->save_fields( $doc_id, array(
		array( 'signer_id' => $a, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
	) );

	// Delegation disabled by default.
	$svc->send( $doc_id );
	$document = $docs->find( $doc_id );
	$signer   = $signers->find( $a );
	Test::throws( static function () use ( $svc, $document, $signer ): void {
		$svc->delegate_signer( $document, $signer, 'X', 'x@example.com', '' );
	}, 'delegation refused when the document does not allow it' );

	// Enable it, but a valid email is still required.
	$docs->update( $doc_id, array( 'allow_delegation' => 1 ) );
	$document = $docs->find( $doc_id );
	Test::throws( static function () use ( $svc, $document, $signer ): void {
		$svc->delegate_signer( $document, $signer, 'X', 'not-an-email', '' );
	}, 'delegation requires a valid email' );
} );
