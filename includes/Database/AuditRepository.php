<?php
/**
 * Audit-trail data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Append-only writer/reader for the audit trail.
 */
final class AuditRepository {

	/**
	 * Append an audit record.
	 *
	 * @param array $data document_id, signer_id, event, ip, user_agent, meta.
	 */
	public function log( array $data ): int {
		global $wpdb;

		$meta = $data['meta'] ?? null;
		if ( null !== $meta && ! is_string( $meta ) ) {
			$meta = wp_json_encode( $meta );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::audit_table(),
			array(
				'document_id' => (int) ( $data['document_id'] ?? 0 ),
				'signer_id'   => (int) ( $data['signer_id'] ?? 0 ),
				'event'       => (string) ( $data['event'] ?? '' ),
				'ip'          => (string) ( $data['ip'] ?? '' ),
				'user_agent'  => (string) ( $data['user_agent'] ?? '' ),
				'meta'        => $meta,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Full trail for a document, oldest first.
	 */
	public function for_document( int $document_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::audit_table() . ' WHERE document_id = %d ORDER BY id ASC',
				$document_id
			)
		);
	}

	/**
	 * Delete the trail for a document.
	 */
	public function delete_for_document( int $document_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::audit_table(),
			array( 'document_id' => $document_id ),
			array( '%d' )
		);
	}
}
