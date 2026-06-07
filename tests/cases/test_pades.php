<?php
/**
 * PAdES / PKI cryptographic signing: certificate storage and a real embedded
 * PKCS#7 signature in the output PDF.
 *
 * Skips gracefully if the server has no OpenSSL (PAdES is then unavailable).
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Pdf\PdfComposer;
use ComSign\Pdf\PdfSigner;
use ComSign\Signature\Certificate;

/**
 * Generate a throwaway self-signed PKCS#12 for testing.
 *
 * @return string|null Raw PKCS#12 bytes, or null if OpenSSL can't do it.
 */
function make_test_p12( string $password ): ?string {
	if ( ! function_exists( 'openssl_pkey_new' ) ) {
		return null;
	}
	$pkey = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
	if ( ! $pkey ) {
		return null;
	}
	$dn   = array( 'commonName' => 'ComSign Test CA', 'organizationName' => 'ComSign' );
	$csr  = openssl_csr_new( $dn, $pkey, array( 'digest_alg' => 'sha256' ) );
	$x509 = openssl_csr_sign( $csr, null, $pkey, 365, array( 'digest_alg' => 'sha256' ) );
	$p12  = '';
	if ( ! openssl_pkcs12_export( $x509, $p12, $pkey, $password ) ) {
		return null;
	}
	return $p12;
}

Test::add( 'pades: certificate storage + cryptographic signature', static function (): void {
	if ( ! Certificate::openssl_available() ) {
		Test::ok( true, 'OpenSSL unavailable — PAdES test skipped' );
		return;
	}

	$password = 'secret123';
	$p12      = make_test_p12( $password );
	if ( null === $p12 ) {
		Test::ok( true, 'Could not generate a test certificate — skipped' );
		return;
	}

	$tmp = tempnam( get_temp_dir(), 'cs-cert' );
	file_put_contents( $tmp, $p12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	try {
		Certificate::store( $tmp, $password );
		Test::ok( Certificate::is_configured(), 'certificate is configured after store' );
		Test::ok( false !== strpos( Certificate::subject(), 'ComSign Test CA' ), 'subject extracted from cert' );

		// The stored password must never be plaintext.
		$opt = get_option( 'comsign_pki' );
		Test::ok( is_array( $opt ) && (string) ( $opt['password'] ?? '' ) !== $password, 'password stored encrypted, not plaintext' );

		// read() must decrypt and return a usable cert + key.
		$crypto = Certificate::read();
		Test::ok( is_array( $crypto ) && ! empty( $crypto['cert'] ) && ! empty( $crypto['pkey'] ), 'read() returns cert + private key' );

		// Sign a composed PDF and confirm a real signature dictionary is embedded.
		$src    = tempnam( get_temp_dir(), 'cs-src' ) . '.pdf';
		$signed = tempnam( get_temp_dir(), 'cs-signed' ) . '.pdf';
		( new PdfComposer() )->render( 'Doc', '<p>שלום עולם</p>', $src );
		( new PdfSigner() )->render(
			$src,
			$signed,
			array(),
			array( 'title' => 'Cert', 'lines' => array( 'Hash' => str_repeat( 'a', 64 ) ) ),
			array( 'cert' => $crypto['cert'], 'pkey' => $crypto['pkey'], 'pass' => '' )
		);

		$bytes = (string) file_get_contents( $signed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		Test::ok( strpos( $bytes, '/ByteRange' ) !== false, 'signed PDF has a /ByteRange' );
		Test::ok( strpos( $bytes, 'adbe.pkcs7.detached' ) !== false, 'signed PDF carries a PKCS#7 detached signature' );
		Test::ok( (bool) preg_match( '#/Type\s*/Sig#', $bytes ), 'signed PDF has a signature dictionary' );

		@unlink( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $signed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	} finally {
		Certificate::remove();
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	Test::ok( ! Certificate::is_configured(), 'certificate removed cleanly' );
} );
