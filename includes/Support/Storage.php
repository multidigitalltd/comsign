<?php
/**
 * Protected file storage helpers.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the private directory where source and signed PDFs live.
 *
 * Files are stored outside the web-reachable flow: the directory is protected
 * by .htaccess / a blank index, and documents are only ever streamed through
 * an authenticated/tokenised PHP endpoint — never linked directly.
 */
final class Storage {

	private const DIR_NAME = 'comsign';

	/**
	 * Absolute path to the plugin's private upload directory.
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Create the storage directory and harden it against direct access.
	 */
	public static function ensure_protected_dir(): string {
		$dir = self::base_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Block directory listing / direct access on Apache.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Prevent index listing on misconfigured servers.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Build an absolute path inside the storage dir for a document.
	 *
	 * @param int    $document_id Document id.
	 * @param string $suffix      File suffix (e.g. 'source' or 'signed').
	 */
	public static function document_path( int $document_id, string $suffix ): string {
		$dir = self::ensure_protected_dir();
		// Add a random component so paths are not guessable from the id alone.
		$nonce = substr( hash( 'sha256', $document_id . wp_salt( 'auth' ) ), 0, 16 );
		return sprintf( '%s/doc-%d-%s-%s.pdf', $dir, $document_id, sanitize_file_name( $suffix ), $nonce );
	}

	/**
	 * Delete every stored file belonging to a document.
	 *
	 * @param object $document Document row with source_path/signed_path.
	 */
	public static function delete_document_files( object $document ): void {
		foreach ( array( $document->source_path, $document->signed_path ) as $path ) {
			if ( $path && is_file( $path ) && self::is_within_base( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Guard against path traversal — only operate inside our base dir.
	 *
	 * @param string $path Candidate absolute path.
	 */
	public static function is_within_base( string $path ): bool {
		$base = trailingslashit( wp_normalize_path( self::base_dir() ) );
		$real = wp_normalize_path( $path );
		// strpos (not str_starts_with) for PHP 7.4 compatibility.
		return 0 === strpos( $real, $base );
	}
}
