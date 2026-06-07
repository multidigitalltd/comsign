<?php
/**
 * Dashboard analytics aggregation.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\DocumentRepository;
use ComSign\Setup\Installer;

/**
 * Read-only aggregate metrics over documents, signers and the audit trail for
 * the analytics screen. All queries are scoped to the plugin's own tables.
 */
final class Analytics {

	/**
	 * Account ids to scope to, or null for site-wide (back-compat).
	 *
	 * @var int[]|null
	 */
	private ?array $account_ids;

	/**
	 * @param int[]|null $account_ids Visible account ids, or null for no scoping.
	 */
	public function __construct( ?array $account_ids = null ) {
		$this->account_ids = is_array( $account_ids ) ? array_map( 'intval', $account_ids ) : null;
	}

	/**
	 * Whether results are scoped to a set of accounts.
	 */
	private function scoped(): bool {
		return null !== $this->account_ids;
	}

	/**
	 * SQL `IN (...)` integer list of the scoped account ids (0 when the scope is
	 * empty, which matches nothing). Values are ints, so inlining is safe.
	 */
	private function account_in(): string {
		return $this->account_ids ? implode( ',', $this->account_ids ) : '0';
	}

	/**
	 * Optional " AND account_id IN (...)" fragment for document queries.
	 */
	private function and_account( string $column = 'account_id' ): string {
		return $this->scoped() ? " AND {$column} IN (" . $this->account_in() . ')' : '';
	}

	/**
	 * Document counts grouped by status (zero-filled for known statuses).
	 *
	 * @return array<string,int>
	 */
	public function status_counts(): array {
		$repo   = new DocumentRepository();
		$counts = $this->scoped() ? $repo->status_counts_for_accounts( (array) $this->account_ids ) : $repo->status_counts();

		$known = array(
			DocumentRepository::STATUS_DRAFT,
			DocumentRepository::STATUS_SENT,
			DocumentRepository::STATUS_VIEWED,
			DocumentRepository::STATUS_SIGNED,
			DocumentRepository::STATUS_COMPLETED,
			DocumentRepository::STATUS_DECLINED,
		);
		foreach ( $known as $status ) {
			$counts[ $status ] = (int) ( $counts[ $status ] ?? 0 );
		}

		return $counts;
	}

	/**
	 * Total document count.
	 */
	public function total(): int {
		$repo = new DocumentRepository();
		return $this->scoped() ? $repo->count_for_accounts( (array) $this->account_ids ) : $repo->count();
	}

	/**
	 * Completion rate (completed / everything that was ever sent), as a percent.
	 */
	public function completion_rate(): float {
		$counts = $this->status_counts();
		$sent_or_more = $counts[ DocumentRepository::STATUS_SENT ]
			+ $counts[ DocumentRepository::STATUS_VIEWED ]
			+ $counts[ DocumentRepository::STATUS_SIGNED ]
			+ $counts[ DocumentRepository::STATUS_COMPLETED ]
			+ $counts[ DocumentRepository::STATUS_DECLINED ];

		if ( 0 === $sent_or_more ) {
			return 0.0;
		}

		return round( ( $counts[ DocumentRepository::STATUS_COMPLETED ] / $sent_or_more ) * 100, 1 );
	}

	/**
	 * Average time from creation to completion, in seconds (0 if none yet).
	 */
	public function avg_completion_seconds(): int {
		global $wpdb;
		$table = Installer::documents_table();

		// TIMESTAMPDIFF works on MySQL; the SQLite test shim maps it. Fall back to
		// a PHP computation when the function is unavailable.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT created_at, updated_at FROM ' . $table . ' WHERE status = %s' . $this->and_account(),
				DocumentRepository::STATUS_COMPLETED
			)
		);

		$total = 0;
		$n     = 0;
		foreach ( (array) $rows as $row ) {
			$start = strtotime( (string) $row->created_at );
			$end   = strtotime( (string) $row->updated_at );
			if ( $start && $end && $end >= $start ) {
				$total += ( $end - $start );
				++$n;
			}
		}

		return $n > 0 ? (int) round( $total / $n ) : 0;
	}

	/**
	 * Documents that are out for signature but not finished, older than $days.
	 *
	 * @param int $days Age threshold in days.
	 * @param int $limit Max rows.
	 *
	 * @return array Document rows.
	 */
	public function stuck( int $days = 7, int $limit = 20 ): array {
		global $wpdb;
		$table  = Installer::documents_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE status IN (%s, %s, %s) AND updated_at < %s' . $this->and_account() . ' ORDER BY updated_at ASC LIMIT %d',
				DocumentRepository::STATUS_SENT,
				DocumentRepository::STATUS_VIEWED,
				DocumentRepository::STATUS_SIGNED,
				$cutoff,
				$limit
			)
		);
	}

	/**
	 * Signer-level totals.
	 *
	 * @return array{total:int,signed:int,declined:int,pending:int}
	 */
	public function signer_stats(): array {
		global $wpdb;
		$table = Installer::signers_table();

		// Signers carry no account_id; scope via their parent document.
		$scope = $this->scoped()
			? ' WHERE document_id IN (SELECT id FROM ' . Installer::documents_table() . ' WHERE account_id IN (' . $this->account_in() . '))'
			: '';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'SELECT status, COUNT(*) AS total FROM ' . $table . $scope . ' GROUP BY status'
		);

		$by = array();
		foreach ( (array) $rows as $row ) {
			$by[ (string) $row->status ] = (int) $row->total;
		}

		$signed   = (int) ( $by['signed'] ?? 0 );
		$declined = (int) ( $by['declined'] ?? 0 );
		$total    = array_sum( $by );

		return array(
			'total'    => $total,
			'signed'   => $signed,
			'declined' => $declined,
			'pending'  => max( 0, $total - $signed - $declined ),
		);
	}

	/**
	 * Documents completed in each of the last $days days (for a simple sparkline).
	 *
	 * @param int $days Number of days.
	 *
	 * @return array<string,int> Y-m-d => count.
	 */
	public function completions_by_day( int $days = 14 ): array {
		global $wpdb;
		$table  = Installer::documents_table();
		$cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT updated_at FROM ' . $table . ' WHERE status = %s AND updated_at >= %s' . $this->and_account(),
				DocumentRepository::STATUS_COMPLETED,
				$cutoff
			)
		);

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) ) ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			$key = gmdate( 'Y-m-d', strtotime( (string) $row->updated_at ) );
			if ( isset( $series[ $key ] ) ) {
				++$series[ $key ];
			}
		}

		return $series;
	}

	/**
	 * Format a duration in seconds as a short human string.
	 *
	 * @param int $seconds Duration.
	 */
	public static function humanize( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '—';
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$m = max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) );
			/* translators: %d: minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $m, 'comsign' ), $m );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$h = (int) round( $seconds / HOUR_IN_SECONDS );
			/* translators: %d: hours. */
			return sprintf( _n( '%d hour', '%d hours', $h, 'comsign' ), $h );
		}
		$d = (int) round( $seconds / DAY_IN_SECONDS );
		/* translators: %d: days. */
		return sprintf( _n( '%d day', '%d days', $d, 'comsign' ), $d );
	}
}
