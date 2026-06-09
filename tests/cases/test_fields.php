<?php
/**
 * FieldRepository::sanitize_payload — the shared field-payload sanitiser used by
 * both the admin editor and the portal editor.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Database\FieldRepository;

Test::add( 'fields: sanitize_payload', static function (): void {
	$raw = array(
		array( 'signer_id' => '5', 'type' => 'TEXT', 'page' => '2', 'pos_x' => '0.3', 'width' => '0.2', 'label' => '  Hi <b>x</b> ', 'help_text' => 'help', 'required' => true ),
		array( 'signer_id' => 9, 'type' => 'evil', 'page' => 0, 'pos_x' => 2.5 ),
		array( 'type' => 'choice', 'options' => array( ' A ', '', 'B' ), 'signer_id' => 1 ),
		'not-an-array',
	);

	$clean = FieldRepository::sanitize_payload( $raw );

	Test::equals( 3, count( $clean ), 'drops non-array rows' );
	Test::equals( 'text', $clean[0]['type'], 'allowed type kept' );
	Test::equals( 5, $clean[0]['signer_id'], 'signer_id cast to int' );
	Test::equals( 'Hi x', $clean[0]['label'], 'label sanitised (tags stripped)' );
	Test::equals( null, $clean[0]['options'], 'non-choice options are null' );

	Test::equals( 'signature', $clean[1]['type'], 'unknown type falls back to signature' );
	Test::equals( 1, $clean[1]['page'], 'page floored to >= 1' );

	Test::equals( array( 'A', 'B' ), $clean[2]['options'], 'choice options trimmed + emptied' );
} );
