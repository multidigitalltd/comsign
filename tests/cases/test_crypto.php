<?php
/**
 * Authenticated encryption: round-trip and tamper detection.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Support\Crypto;

Test::add( 'crypto: round-trips and authenticates secrets', static function (): void {
	$secret = 'cardcom-token-' . str_repeat( 'X9', 20 );

	$ct = Crypto::encrypt( $secret );
	Test::ok( '' !== $ct, 'encrypt returns a token' );
	Test::ok( false === strpos( $ct, $secret ), 'plaintext is not present in the ciphertext' );
	Test::equals( $secret, Crypto::decrypt( $ct ), 'decrypts back to the original' );

	// Two encryptions of the same value differ (random IV/nonce).
	Test::ok( Crypto::encrypt( $secret ) !== Crypto::encrypt( $secret ), 'nonce makes ciphertexts unique' );

	// Empty in, empty out.
	Test::equals( '', Crypto::encrypt( '' ), 'empty plaintext yields empty' );
	Test::equals( '', Crypto::decrypt( '' ), 'empty token yields empty' );
} );

Test::add( 'crypto: tampered ciphertext is rejected', static function (): void {
	$ct = Crypto::encrypt( 'sensitive-value' );

	// Flip a byte in the base64 body (after the "xx:" version prefix).
	$pos = strlen( $ct ) - 4;
	$ch  = $ct[ $pos ];
	$ct_tampered = substr( $ct, 0, $pos ) . ( 'A' === $ch ? 'B' : 'A' ) . substr( $ct, $pos + 1 );

	Test::ok( $ct_tampered !== $ct, 'tampered token differs' );
	Test::equals( '', Crypto::decrypt( $ct_tampered ), 'authentication rejects tampered data' );

	// Garbage is rejected too.
	Test::equals( '', Crypto::decrypt( 'g1:not-valid-base64!!' ), 'garbage rejected' );
} );
