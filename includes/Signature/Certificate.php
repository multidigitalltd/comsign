<?php
/**
 * PKI certificate management for PAdES signing.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

use ComSign\Support\Storage;

/**
 * Stores and reads the PKCS#12 certificate used for cryptographic (PAdES)
 * signing. The certificate lives in the protected uploads directory; its
 * password is kept in a dedicated option.
 */
final class Certificate {

	private const OPTION = 'comsign_pki';

	/**
	 * Absolute path to the stored PKCS#12 file.
	 */
	public static function path(): string {
		return trailingslashit( Storage::base_dir() ) . 'pki-cert.p12';
	}

	/**
	 * Whether OpenSSL is available (required for PAdES).
	 */
	public static function openssl_available(): bool {
		return function_exists( 'openssl_pkcs12_read' );
	}

	/**
	 * Whether a usable certificate is configured.
	 */
	public static function is_configured(): bool {
		$opt = get_option( self::OPTION );
		return self::openssl_available() && is_array( $opt ) && ! empty( $opt['password'] ) && is_file( self::path() );
	}

	/**
	 * Validate and store an uploaded PKCS#12 certificate.
	 *
	 * @param string $tmp_file Path to the uploaded temp file.
	 * @param string $password Certificate password.
	 *
	 * @throws \RuntimeException If OpenSSL is missing or the cert is invalid.
	 */
	public static function store( string $tmp_file, string $password ): void {
		if ( ! self::openssl_available() ) {
			throw new \RuntimeException( __( 'OpenSSL is not available on this server, so PKI signing cannot be enabled.', 'comsign' ) );
		}

		$data = is_readable( $tmp_file ) ? file_get_contents( $tmp_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$certs = array();
		if ( '' === $data || ! openssl_pkcs12_read( $data, $certs, $password ) ) {
			throw new \RuntimeException( __( 'The certificate could not be read. Check the file and password.', 'comsign' ) );
		}

		Storage::ensure_protected_dir();
		if ( ! move_uploaded_file( $tmp_file, self::path() ) ) {
			// Fallback for non-HTTP-upload contexts (e.g. tests).
			file_put_contents( self::path(), $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		update_option(
			self::OPTION,
			array(
				'password' => (string) $password,
				'subject'  => self::subject_from( $certs ),
			)
		);
	}

	/**
	 * Read the certificate + private key as PEM strings for signing.
	 *
	 * @return array{cert:string,pkey:string}|null
	 */
	public static function read(): ?array {
		if ( ! self::is_configured() ) {
			return null;
		}

		$opt  = get_option( self::OPTION );
		$data = is_readable( self::path() ) ? file_get_contents( self::path() ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$certs = array();
		if ( '' === $data || ! openssl_pkcs12_read( $data, $certs, (string) $opt['password'] ) ) {
			return null;
		}

		return array(
			'cert' => (string) ( $certs['cert'] ?? '' ),
			'pkey' => (string) ( $certs['pkey'] ?? '' ),
		);
	}

	/**
	 * Human-readable subject of the stored certificate (for the settings UI).
	 */
	public static function subject(): string {
		$opt = get_option( self::OPTION );
		return is_array( $opt ) ? (string) ( $opt['subject'] ?? '' ) : '';
	}

	/**
	 * Remove the stored certificate and its option.
	 */
	public static function remove(): void {
		if ( is_file( self::path() ) ) {
			wp_delete_file( self::path() );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Extract a readable subject string from a parsed PKCS#12.
	 *
	 * @param array $certs Result of openssl_pkcs12_read().
	 */
	private static function subject_from( array $certs ): string {
		if ( empty( $certs['cert'] ) ) {
			return '';
		}
		$parsed = openssl_x509_parse( $certs['cert'] );
		if ( ! is_array( $parsed ) ) {
			return '';
		}
		$cn = $parsed['subject']['CN'] ?? '';
		$o  = $parsed['subject']['O'] ?? '';
		return trim( $cn . ( $o ? ' (' . $o . ')' : '' ) );
	}
}
