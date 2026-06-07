<?php
/**
 * Document workflow happy path: compose -> add signers -> place fields -> send,
 * plus the guards that protect sending.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;

Test::add( 'documents: compose, sign-setup and send', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$docs    = new DocumentRepository();
	$signers = new SignerRepository();

	$doc = $svc->create_from_text( 'Agreement', '<p>Body</p>', array() );
	Test::ok( $doc > 0, 'document created' );
	Test::equals( DocumentRepository::STATUS_DRAFT, $docs->find( $doc )->status, 'starts as draft' );

	$s1 = $svc->add_signer( $doc, 'Alice', 'alice@example.com' );
	$s2 = $svc->add_signer( $doc, 'Bob', 'bob@example.com' );
	Test::equals( 2, count( $signers->for_document( $doc ) ), 'two signers added' );

	$svc->save_fields( $doc, array(
		array( 'signer_id' => $s1, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
		array( 'signer_id' => $s2, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .5, 'width' => .3, 'height' => .08 ),
	) );

	$result = $svc->send( $doc );
	Test::ok( is_array( $result ), 'send returns a summary' );
	Test::equals( DocumentRepository::STATUS_SENT, $docs->find( $doc )->status, 'status becomes sent' );
} );

Test::add( 'documents: cannot send without signers', static function (): void {
	reset_tables();
	$svc = new DocumentService();
	$doc = $svc->create_from_text( 'Empty', '<p>x</p>', array() );

	Test::throws( static function () use ( $svc, $doc ): void {
		$svc->send( $doc );
	}, 'send() rejects a document with no signers' );
} );

Test::add( 'documents: save_fields rejects foreign signers', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$signers = new SignerRepository();

	$doc    = $svc->create_from_text( 'Scoped', '<p>x</p>', array() );
	$signer = $svc->add_signer( $doc, 'Real', 'real@example.com' );

	// Attempt to attach a field to a signer id that doesn't belong to the doc.
	$svc->save_fields( $doc, array(
		array( 'signer_id' => $signer, 'type' => 'signature', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .3, 'height' => .08 ),
		array( 'signer_id' => 999999, 'type' => 'signature', 'page' => 1, 'pos_x' => .2, 'pos_y' => .2, 'width' => .3, 'height' => .08 ),
	) );

	global $wpdb;
	$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \ComSign\Setup\Installer::fields_table() ); // phpcs:ignore WordPress.DB
	Test::equals( 1, $count, 'only the legitimate field is stored' );
} );
