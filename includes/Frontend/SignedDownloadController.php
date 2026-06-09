<?php
/**
 * Public, tokenised download of a completed document's signed PDF.
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\DocumentRepository;
use ComSign\Support\Storage;

/**
 * Serves the final signed PDF over an unguessable, HMAC-signed link (no login),
 * so automation that receives a webhook can fetch the document directly. The
 * link only works for completed documents and is bound to that document's
 * verification hash, so it cannot be enumerated or pointed at another file.
 */
final class SignedDownloadController {

	public function register(): void {
		add_action( 'admin_post_nopriv_comsign_signed_download', array( $this, 'handle' ) );
		add_action( 'admin_post_comsign_signed_download', array( $this, 'handle' ) );
	}

	/**
	 * Build the public download URL for a completed document.
	 *
	 * @param int    $document_id Document id.
	 * @param string $signed_hash The document's SHA-256 verification hash.
	 */
	public static function url( int $document_id, string $signed_hash ): string {
		return add_query_arg(
			array(
				'action' => 'comsign_signed_download',
				'doc'    => $document_id,
				'sig'    => self::signature( $document_id, $signed_hash ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The HMAC that authorises a download link.
	 */
	private static function signature( int $document_id, string $signed_hash ): string {
		return hash_hmac( 'sha256', $document_id . '|' . $signed_hash, wp_salt( 'auth' ) );
	}

	/**
	 * Validate the link and stream the signed PDF.
	 */
	public function handle(): void {
		$document_id = isset( $_GET['doc'] ) ? absint( wp_unslash( $_GET['doc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sig         = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$document = $document_id ? ( new DocumentRepository() )->find( $document_id ) : null;
		if ( ! $document
			|| DocumentRepository::STATUS_COMPLETED !== $document->status
			|| empty( $document->signed_path )
			|| empty( $document->signed_hash ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Document not found.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		$expected = self::signature( (int) $document->id, (string) $document->signed_hash );
		if ( '' === $sig || ! hash_equals( $expected, $sig ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Invalid or expired link.', 'comsign' ), '', array( 'response' => 403 ) );
		}

		$path = (string) $document->signed_path;
		if ( ! Storage::is_within_base( $path ) || ! is_readable( $path ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'File not available.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( ( $document->title ? $document->title : 'document' ) . '.pdf' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
