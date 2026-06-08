<?php
/**
 * Compose-from-text: automatic signature-block field placement.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;

Test::add( 'compose: auto signature block places each signer\'s fields', static function (): void {
	$specs = array(
		array( 'signer_id' => 11, 'name' => 'Dana', 'fields' => array( 'signature', 'name', 'date' ) ),
		array( 'signer_id' => 22, 'name' => 'Ron', 'fields' => array( 'signature', 'name', 'date' ) ),
	);
	$fields = DocumentService::signature_block_fields( $specs, 3 );

	Test::equals( 6, count( $fields ), 'two signers × three fields' );

	foreach ( $fields as $f ) {
		Test::equals( 3, $f['page'], 'placed on the signature page' );
		Test::ok( $f['pos_x'] >= 0 && $f['pos_x'] <= 1, 'pos_x is a fraction' );
		Test::ok( $f['pos_y'] >= 0 && $f['pos_y'] <= 1, 'pos_y is a fraction' );
		Test::ok( $f['pos_x'] + $f['width'] <= 1.001, 'field stays within the page width' );
		Test::ok( $f['pos_y'] + $f['height'] <= 1.001, 'field stays within the page height' );
	}

	// The signature field is required; auto-filled name/date are not.
	$by_type = array();
	foreach ( $fields as $f ) {
		$by_type[ $f['type'] ][] = $f;
	}
	Test::equals( 2, count( $by_type['signature'] ?? array() ), 'one signature per signer' );
	Test::ok( $by_type['signature'][0]['required'], 'signature is required' );
	Test::ok( ! $by_type['name'][0]['required'], 'name is not required' );

	// Each signer's fields reference the right signer id.
	$signer_ids = array_unique( array_map( static fn( $f ) => $f['signer_id'], $fields ) );
	sort( $signer_ids );
	Test::equals( array( 11, 22 ), $signer_ids, 'fields belong to the two signers' );
} );

Test::add( 'compose: a signature-only signer gets exactly one field', static function (): void {
	$fields = DocumentService::signature_block_fields(
		array( array( 'signer_id' => 5, 'name' => 'Solo', 'fields' => array( 'signature' ) ) ),
		1
	);
	Test::equals( 1, count( $fields ), 'just the signature' );
	Test::equals( 'signature', $fields[0]['type'], 'it is the signature field' );

	// An empty field list falls back to a sensible default block.
	$defaults = DocumentService::signature_block_fields(
		array( array( 'signer_id' => 6, 'name' => 'NoPick', 'fields' => array() ) ),
		1
	);
	$types = array_map( static fn( $f ) => $f['type'], $defaults );
	sort( $types );
	Test::equals( array( 'date', 'name', 'signature' ), $types, 'defaults to signature + name + date' );
} );

Test::add( 'compose: end-to-end builds a document with auto-placed fields', static function (): void {
	reset_tables();
	$svc     = new DocumentService();
	$signers = new SignerRepository();
	$fields  = new FieldRepository();

	$doc_id = $svc->compose_with_signatures(
		'Service agreement',
		'<p>The parties agree to the following terms.</p>',
		array(
			array( 'name' => 'Dana Levi', 'email' => 'dana@example.com', 'fields' => array( 'signature', 'name', 'date' ) ),
			array( 'name' => 'Ron Cohen', 'email' => 'ron@example.com', 'fields' => array( 'signature' ) ),
		)
	);

	Test::ok( $doc_id > 0, 'a document was created' );
	Test::equals( 2, count( $signers->for_document( $doc_id ) ), 'both signers added' );

	$rows = $fields->for_document( $doc_id );
	Test::equals( 4, count( $rows ), 'three fields for signer 1 + one for signer 2' );

	$sig = array_filter( $rows, static fn( $f ) => 'signature' === $f->type );
	Test::equals( 2, count( $sig ), 'one signature field per signer' );
} );

Test::add( 'compose: signers with no id are skipped safely', static function (): void {
	$fields = DocumentService::signature_block_fields(
		array(
			array( 'signer_id' => 0, 'name' => 'Ghost', 'fields' => array( 'signature' ) ),
			array( 'signer_id' => 9, 'name' => 'Real', 'fields' => array( 'signature' ) ),
		),
		2
	);
	$signer_ids = array_unique( array_map( static fn( $f ) => $f['signer_id'], $fields ) );
	Test::equals( array( 9 ), array_values( $signer_ids ), 'only the real signer is placed' );
} );
