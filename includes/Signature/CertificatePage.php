<?php
/**
 * Builds the signature-certificate page content.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

use ComSign\Audit\AuditLogger;
use ComSign\Setup\Installer;

/**
 * Produces the title + label/value lines for the appended certificate page,
 * shared by every signature provider.
 */
final class CertificatePage {

	/**
	 * Build the certificate content from signer records + audit trail.
	 *
	 * @param object $document Document row.
	 * @param array  $signers  Signer rows.
	 *
	 * @return array{title:string,lines:array}
	 */
	public static function build( object $document, array $signers ): array {
		$lines = array(
			__( 'Document', 'comsign' )    => (string) $document->title,
			__( 'Document ID', 'comsign' ) => (string) $document->id,
		);

		foreach ( $signers as $index => $signer ) {
			/* translators: %d: signer number. */
			$prefix = sprintf( __( 'Signer %d', 'comsign' ), $index + 1 );

			$lines[ $prefix . ' - ' . __( 'Name', 'comsign' ) ]      = (string) $signer->name;
			$lines[ $prefix . ' - ' . __( 'Email', 'comsign' ) ]     = (string) $signer->email;
			$lines[ $prefix . ' - ' . __( 'Signed at', 'comsign' ) ] = (string) ( $signer->signed_at ?? '' );

			$event = self::find_signed_event( (int) $signer->id );
			if ( $event ) {
				$lines[ $prefix . ' - ' . __( 'IP address', 'comsign' ) ] = (string) $event->ip;
				$lines[ $prefix . ' - ' . __( 'Browser', 'comsign' ) ]    = (string) $event->user_agent;
			}
		}

		// List signer-uploaded attachments, if any.
		$n = 0;
		foreach ( self::attachments( (int) $document->id ) as $att ) {
			++$n;
			/* translators: %d: attachment number. */
			$lines[ sprintf( __( 'Attachment %d', 'comsign' ), $n ) ] = (string) $att;
		}

		return array(
			'title' => __( 'Signature Certificate', 'comsign' ),
			'lines' => $lines,
		);
	}

	/**
	 * Filenames of attachments uploaded for a document.
	 *
	 * @param int $document_id Document id.
	 *
	 * @return string[]
	 */
	private static function attachments( int $document_id ): array {
		global $wpdb;
		$table = \ComSign\Setup\Installer::fields_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT value FROM ' . $table . ' WHERE document_id = %d AND type = %s',
				$document_id,
				\ComSign\Database\FieldRepository::TYPE_ATTACHMENT
			)
		);

		$names = array();
		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( (string) $row->value, true );
			if ( is_array( $decoded ) && 'file' === ( $decoded['kind'] ?? '' ) && ! empty( $decoded['name'] ) ) {
				$names[] = (string) $decoded['name'];
			}
		}
		return $names;
	}

	/**
	 * Locate the "signed" audit event for a signer, if any.
	 */
	private static function find_signed_event( int $signer_id ): ?object {
		global $wpdb;
		$table = Installer::audit_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE signer_id = %d AND event = %s ORDER BY id DESC LIMIT 1',
				$signer_id,
				AuditLogger::EVENT_SIGNED
			)
		);

		return $row ?: null;
	}
}
