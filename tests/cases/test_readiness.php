<?php
/**
 * SendReadiness — the "ready to send" checklist that gates sending a draft.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Services\DocumentService;
use ComSign\Services\SendReadiness;

Test::add( 'readiness: gates each requirement', static function (): void {
	reset_tables();
	$svc = new DocumentService();
	$rd  = new SendReadiness();

	// Composed text doc => has a source, but no signers yet.
	$doc = $svc->create_from_text( 'R', '<p>x</p>', array() );
	$r   = $rd->check( $doc );
	Test::ok( $r['items'][0]['ok'], 'source present after compose' );
	Test::ok( ! $r['items'][1]['ok'], 'no signers flagged' );
	Test::ok( ! $r['ready'], 'not ready without signers' );

	// Add a signer but no fields.
	$signer = $svc->add_signer( $doc, 'Dana', 'dana@example.com' );
	$r      = $rd->check( $doc );
	Test::ok( $r['items'][1]['ok'], 'signer requirement satisfied' );
	Test::ok( ! $r['items'][2]['ok'], 'missing-fields flagged' );
	Test::ok( ! $r['ready'], 'still not ready without fields' );

	// Add a non-signature field only.
	$svc->save_fields( $doc, array(
		array( 'signer_id' => $signer, 'type' => 'text', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .2, 'height' => .05 ),
	) );
	$r = $rd->check( $doc );
	Test::ok( $r['items'][2]['ok'], 'has a field now' );
	Test::ok( ! $r['items'][3]['ok'], 'signature requirement still unmet' );
	Test::ok( ! $r['ready'], 'not ready without a signature field' );

	// Add a signature field => ready.
	$svc->save_fields( $doc, array(
		array( 'signer_id' => $signer, 'type' => 'text', 'page' => 1, 'pos_x' => .1, 'pos_y' => .1, 'width' => .2, 'height' => .05 ),
		array( 'signer_id' => $signer, 'type' => 'signature', 'page' => 1, 'pos_x' => .4, 'pos_y' => .4, 'width' => .3, 'height' => .08 ),
	) );
	Test::ok( $rd->check( $doc )['ready'], 'ready once a signature field exists' );
} );
