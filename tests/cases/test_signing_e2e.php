<?php
/**
 * End-to-end signing: a document goes draft -> sent -> (each signer signs) ->
 * completed, with a real signed PDF and a hash recorded.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\AuditRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;
use ComSign\Setup\Installer;

/**
 * A small opaque PNG as raw base64 (no data: prefix), like the captured
 * signature that record_signature() expects (transparent 1x1s trip TCPDF's
 * alpha handling, so we use an opaque stand-in here).
 */
function tiny_png_b64(): string {
	// A small opaque PNG (no alpha) — a stand-in for a captured signature.
	return 'iVBORw0KGgoAAAANSUhEUgAAACgAAAAQCAIAAADrtar6AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAWUlEQVQ4je3VoQ4AIAiEYXU23v9RyRjYGEE3nZwU/3Tti1dFpGTUUtRMuL/EiEgHM8Nhw9SzDYFXmC8M3sHC4FPsCr7BjuEobAtGYEsYjU1gJdGYr/6TeNUASB0kLRdwuqkAAAAASUVORK5CYII=';
}

Test::add( 'signing: full draft -> sent -> completed flow', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$signers = new SignerRepository();
	$audit   = new AuditRepository();
	$png     = tiny_png_b64();

	// Build a two-signer document with a signature field each.
	$doc_id = $svc->create_from_text( 'הסכם', '<p>נא לחתום למטה.</p>', array() );
	$a      = $svc->add_signer( $doc_id, 'Alice', 'alice@example.com' );
	$b      = $svc->add_signer( $doc_id, 'Bob', 'bob@example.com' );
	$svc->save_fields( $doc_id, array(
		array( 'signer_id' => $a, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
		array( 'signer_id' => $b, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .5, 'width' => .3, 'height' => .08 ),
	) );
	$svc->send( $doc_id );
	Test::equals( DocumentRepository::STATUS_SENT, $docs->find( $doc_id )->status, 'document is sent' );

	// Map signer rows by name so we can sign each in turn.
	$rows = array();
	foreach ( $signers->for_document( $doc_id ) as $s ) {
		$rows[ $s->name ] = $s;
	}

	// First signer signs -> document moves to "signed", not yet completed.
	$svc->record_signature( $docs->find( $doc_id ), $rows['Alice'], $png );
	$after_first = $docs->find( $doc_id );
	Test::equals( DocumentRepository::STATUS_SIGNED, $after_first->status, 'after first signer: status signed' );
	Test::ok( empty( $after_first->signed_path ), 'no signed PDF until everyone signs' );

	// Second signer signs -> document finalises.
	$svc->record_signature( $docs->find( $doc_id ), $rows['Bob'], $png );
	$done = $docs->find( $doc_id );
	Test::equals( DocumentRepository::STATUS_COMPLETED, $done->status, 'after last signer: completed' );

	// A real signed PDF exists, with a recorded hash.
	$ok_file = $done->signed_path && is_file( $done->signed_path )
		&& '%PDF' === substr( (string) file_get_contents( $done->signed_path, false, null, 0, 4 ), 0, 4 );
	Test::ok( $ok_file, 'signed PDF generated and is a valid PDF' );
	Test::equals( 64, strlen( (string) $done->signed_hash ), 'sha256 hash recorded' );

	// Both signers are marked signed.
	$signed_count = 0;
	foreach ( $signers->for_document( $doc_id ) as $s ) {
		if ( SignerRepository::STATUS_SIGNED === $s->status ) {
			$signed_count++;
		}
	}
	Test::equals( 2, $signed_count, 'both signers marked signed' );

	// Audit trail captured the signed + completed events.
	global $wpdb;
	$events = $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . Installer::audit_table() . ' WHERE document_id = %d', $doc_id ) ); // phpcs:ignore WordPress.DB
	Test::equals( 2, count( array_filter( $events, static fn( $e ) => 'signed' === $e ) ), 'two signed events logged' );
	Test::ok( in_array( 'completed', $events, true ), 'completed event logged' );
} );

Test::add( 'signing: missing signature is rejected', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$signers = new SignerRepository();

	$doc_id = $svc->create_from_text( 'X', '<p>x</p>', array() );
	$s      = $svc->add_signer( $doc_id, 'Carol', 'carol@example.com' );
	$svc->save_fields( $doc_id, array(
		array( 'signer_id' => $s, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
	) );
	$svc->send( $doc_id );

	$rows = $signers->for_document( $doc_id );
	Test::throws( static function () use ( $svc, $docs, $doc_id, $rows ): void {
		$svc->record_signature( $docs->find( $doc_id ), $rows[0], '' ); // no signature image
	}, 'signing without a signature image is rejected' );
} );
