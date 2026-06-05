<?php
/**
 * Signer data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Read/write access to the signers table via $wpdb prepared statements.
 */
final class SignerRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_VIEWED   = 'viewed';
	public const STATUS_SIGNED   = 'signed';
	public const STATUS_DECLINED = 'declined';

	/**
	 * Insert a new signer and return its id.
	 *
	 * @param array $data name, email, document_id, token_hash, sign_order.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::signers_table(),
			array(
				'document_id' => (int) $data['document_id'],
				'name'        => (string) ( $data['name'] ?? '' ),
				'email'       => (string) ( $data['email'] ?? '' ),
				'phone'       => (string) ( $data['phone'] ?? '' ),
				'token_hash'  => (string) ( $data['token_hash'] ?? '' ),
				'status'      => self::STATUS_PENDING,
				'sign_order'  => (int) ( $data['sign_order'] ?? 0 ),
				'auth_method' => (string) ( $data['auth_method'] ?? 'none' ),
				'auth_code_hash' => (string) ( $data['auth_code_hash'] ?? '' ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single signer by id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::signers_table() . ' WHERE id = %d', $id )
		);

		return $row ?: null;
	}

	/**
	 * Look up a signer by the SHA-256 hash of their token.
	 */
	public function find_by_token_hash( string $token_hash ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::signers_table() . ' WHERE token_hash = %s', $token_hash )
		);

		return $row ?: null;
	}

	/**
	 * All signers for a document, in signing order.
	 */
	public function for_document( int $document_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::signers_table() . ' WHERE document_id = %d ORDER BY sign_order ASC, id ASC',
				$document_id
			)
		);
	}

	/**
	 * Update columns on a signer.
	 *
	 * @param int   $id   Signer id.
	 * @param array $data Column => value pairs.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::signers_table(),
			$data,
			array( 'id' => $id )
		);
	}

	/**
	 * Delete a single signer.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::signers_table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Delete all signers for a document.
	 */
	public function delete_for_document( int $document_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::signers_table(),
			array( 'document_id' => $document_id ),
			array( '%d' )
		);
	}

	/**
	 * The next signer (lowest order) who has not yet signed or declined.
	 *
	 * Used for sequential signing: only this signer should be active.
	 *
	 * @param int $document_id Document id.
	 */
	public function next_unsigned( int $document_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::signers_table()
					. ' WHERE document_id = %d AND status NOT IN ( %s, %s ) ORDER BY sign_order ASC, id ASC LIMIT 1',
				$document_id,
				self::STATUS_SIGNED,
				self::STATUS_DECLINED
			)
		);

		return $row ?: null;
	}

	/**
	 * Whether any earlier-order signer for the same document is still unsigned.
	 *
	 * @param object $signer Signer row.
	 */
	public function has_earlier_unsigned( object $signer ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Installer::signers_table()
					. ' WHERE document_id = %d AND sign_order < %d AND status NOT IN ( %s, %s )',
				(int) $signer->document_id,
				(int) $signer->sign_order,
				self::STATUS_SIGNED,
				self::STATUS_DECLINED
			)
		);

		return $count > 0;
	}

	/**
	 * Signers that are candidates for an automatic reminder.
	 *
	 * Pending/viewed signers who have an email and an active token, on
	 * documents that are still in progress.
	 *
	 * @return object[]
	 */
	public function reminder_candidates(): array {
		global $wpdb;

		$signers   = Installer::signers_table();
		$documents = Installer::documents_table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM {$signers} s
				 INNER JOIN {$documents} d ON d.id = s.document_id
				 WHERE s.status IN ( %s, %s )
				   AND s.email <> ''
				   AND s.token_hash <> ''
				   AND d.status IN ( %s, %s, %s )",
				self::STATUS_PENDING,
				self::STATUS_VIEWED,
				'sent',
				'viewed',
				'signed'
			)
		);
		// phpcs:enable
	}

	/**
	 * Whether every signer for a document has signed.
	 */
	public function all_signed( int $document_id ): bool {
		global $wpdb;

		$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Installer::signers_table() . ' WHERE document_id = %d AND status <> %s',
				$document_id,
				self::STATUS_SIGNED
			)
		);

		return 0 === $pending;
	}
}
