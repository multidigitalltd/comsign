<?php
/**
 * Document data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Read/write access to the documents table via $wpdb prepared statements.
 */
final class DocumentRepository {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_SENT      = 'sent';
	public const STATUS_VIEWED    = 'viewed';
	public const STATUS_SIGNED    = 'signed';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_DECLINED  = 'declined';

	/**
	 * Insert a new document and return its id.
	 *
	 * @param array $data Associative data (title, source_path, created_by).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::documents_table(),
			array(
				'title'       => (string) ( $data['title'] ?? '' ),
				'status'      => self::STATUS_DRAFT,
				'source_path' => (string) ( $data['source_path'] ?? '' ),
				'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single document by id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::documents_table() . ' WHERE id = %d', $id )
		);

		return $row ?: null;
	}

	/**
	 * Return a page of documents for the admin list, newest first.
	 *
	 * @param int $per_page Items per page.
	 * @param int $offset   Offset.
	 */
	public function paginate( int $per_page, int $offset ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::documents_table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Total document count (for pagination).
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::documents_table() );
	}

	/**
	 * Document counts grouped by status (for the dashboard summary).
	 *
	 * @return array<string,int> status => count.
	 */
	public function status_counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT status, COUNT(*) AS total FROM ' . Installer::documents_table() . ' GROUP BY status'
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Update arbitrary columns on a document.
	 *
	 * @param int   $id   Document id.
	 * @param array $data Column => value pairs.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::documents_table(),
			$data,
			array( 'id' => $id )
		);
	}

	/**
	 * Convenience: set the document status.
	 */
	public function set_status( int $id, string $status ): void {
		$this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * Delete a document row.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::documents_table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}
}
