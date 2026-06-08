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
				'account_id'  => (int) ( $data['account_id'] ?? 0 ),
				'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
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
	 * A page of documents restricted to a set of account ids, newest first.
	 *
	 * @param int[] $account_ids Visible account ids.
	 * @param int   $per_page    Items per page.
	 * @param int   $offset      Offset.
	 */
	public function paginate_for_accounts( array $account_ids, int $per_page, int $offset, string $search = '', string $status = '' ): array {
		global $wpdb;

		$scope = $this->account_scope( $account_ids, $search, $status );
		if ( null === $scope ) {
			return array();
		}

		$args = array_merge( $scope['args'], array( $per_page, $offset ) );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::documents_table() . ' ' . $scope['where'] . ' ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$args
			)
		);
	}

	/**
	 * Document count restricted to a set of account ids (with optional filters).
	 *
	 * @param int[]  $account_ids Visible account ids.
	 * @param string $search      Title substring to match (optional).
	 * @param string $status      Exact status to match (optional).
	 */
	public function count_for_accounts( array $account_ids, string $search = '', string $status = '' ): int {
		global $wpdb;

		$scope = $this->account_scope( $account_ids, $search, $status );
		if ( null === $scope ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Installer::documents_table() . ' ' . $scope['where'], // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$scope['args']
			)
		);
	}

	/**
	 * Count documents created for a single account since a UTC datetime.
	 *
	 * Used for monthly usage quotas. Drafts count too, since creating a document
	 * already consumes the workspace's allowance.
	 *
	 * @param int    $account_id Account id.
	 * @param string $since_gmt  UTC 'Y-m-d H:i:s' lower bound (inclusive).
	 */
	public function count_for_account_since( int $account_id, string $since_gmt ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Installer::documents_table() . ' WHERE account_id = %d AND created_at >= %s',
				$account_id,
				$since_gmt
			)
		);
	}

	/**
	 * Build the shared "account scope + optional filters" WHERE clause.
	 *
	 * @param int[]  $account_ids Visible account ids.
	 * @param string $search      Title substring (matched with LIKE).
	 * @param string $status      Exact status.
	 *
	 * @return array{where:string,args:array}|null Null when there is no account scope.
	 */
	private function account_scope( array $account_ids, string $search, string $status ): ?array {
		global $wpdb;

		$account_ids = array_values( array_unique( array_map( 'intval', $account_ids ) ) );
		if ( ! $account_ids ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $account_ids ), '%d' ) );
		$where        = "WHERE account_id IN ($placeholders)";
		$args         = $account_ids;

		$status = trim( $status );
		if ( '' !== $status ) {
			$where  .= ' AND status = %s';
			$args[]  = $status;
		}

		$search = trim( $search );
		if ( '' !== $search ) {
			$where  .= ' AND title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( 'where' => $where, 'args' => $args );
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
	 * Document counts grouped by status, restricted to a set of account ids.
	 *
	 * @param int[] $account_ids Visible account ids.
	 *
	 * @return array<string,int>
	 */
	public function status_counts_for_accounts( array $account_ids ): array {
		global $wpdb;

		$account_ids = array_values( array_unique( array_map( 'intval', $account_ids ) ) );
		if ( ! $account_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $account_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS total FROM ' . Installer::documents_table() . " WHERE account_id IN ($placeholders) GROUP BY status",
				...$account_ids
			)
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
	 * Set (or clear) the expiry datetime.
	 *
	 * $wpdb->update() cannot reliably write SQL NULL, so this uses a prepared
	 * statement that sets NULL when no expiry is given.
	 *
	 * @param int         $id      Document id.
	 * @param string|null $expires UTC 'Y-m-d H:i:s', or null to clear.
	 */
	public function set_expiry( int $id, ?string $expires ): void {
		global $wpdb;

		$table = Installer::documents_table();

		if ( null === $expires ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = NULL WHERE id = %d", $id ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %s WHERE id = %d", $expires, $id ) );
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
