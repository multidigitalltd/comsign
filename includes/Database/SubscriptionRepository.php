<?php
/**
 * Subscription data access (one row per account).
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Reads/writes the per-account subscription row: plan, billing cycle, status,
 * trial/period dates and the (encrypted) recurring-billing token.
 */
final class SubscriptionRepository {

	/**
	 * Fetch the subscription for an account (or null).
	 */
	public function for_account( int $account_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::subscriptions_table() . ' WHERE account_id = %d', $account_id )
		);
		return $row ?: null;
	}

	/**
	 * Insert or update the subscription for an account.
	 *
	 * @param int   $account_id Account id.
	 * @param array $data       Columns to set (plan, cycle, status, trial_ends_at,
	 *                          current_period_end, cardcom_token).
	 *
	 * @return int Subscription id.
	 */
	public function upsert( int $account_id, array $data ): int {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = Installer::subscriptions_table();

		$fields = array(
			'plan'               => isset( $data['plan'] ) ? (string) $data['plan'] : null,
			'cycle'              => isset( $data['cycle'] ) ? (string) $data['cycle'] : null,
			'status'             => isset( $data['status'] ) ? (string) $data['status'] : null,
			'trial_ends_at'      => array_key_exists( 'trial_ends_at', $data ) ? $data['trial_ends_at'] : null,
			'current_period_end' => array_key_exists( 'current_period_end', $data ) ? $data['current_period_end'] : null,
			'cardcom_token'      => isset( $data['cardcom_token'] ) ? (string) $data['cardcom_token'] : null,
		);
		// Drop untouched keys so we never overwrite with null unintentionally.
		$fields = array_filter( $fields, static fn( $v ) => null !== $v );

		$existing = $this->for_account( $account_id );
		if ( $existing ) {
			if ( $fields ) {
				$fields['updated_at'] = $now;
				$wpdb->update( $table, $fields, array( 'id' => (int) $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return (int) $existing->id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array_merge(
				array(
					'account_id' => $account_id,
					'created_at' => $now,
					'updated_at' => $now,
				),
				$fields
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Set (or clear) the trial / current-period end datetimes.
	 *
	 * $wpdb->update() can't write SQL NULL reliably, so this uses a prepared
	 * statement for the NULL case.
	 *
	 * @param int         $account_id Account id.
	 * @param string      $column     'trial_ends_at' or 'current_period_end'.
	 * @param string|null $value      UTC 'Y-m-d H:i:s', or null to clear.
	 */
	public function set_datetime( int $account_id, string $column, ?string $value ): void {
		global $wpdb;
		$column = in_array( $column, array( 'trial_ends_at', 'current_period_end' ), true ) ? $column : 'trial_ends_at';
		$table  = Installer::subscriptions_table();

		if ( null === $value ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = NULL, updated_at = %s WHERE account_id = %d", current_time( 'mysql', true ), $account_id ) ); // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = %s, updated_at = %s WHERE account_id = %d", $value, current_time( 'mysql', true ), $account_id ) ); // phpcs:ignore WordPress.DB
	}
}
