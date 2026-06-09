<?php
/**
 * Contacts (address book) data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Reusable signer details, scoped to an account. Populated passively as signers
 * are added, and offered back as autocomplete so frequent recipients don't have
 * to be retyped. A tenant only ever sees its own account's contacts.
 */
final class ContactRepository {

	/**
	 * Insert or update a contact by (account_id, email).
	 *
	 * A contact needs a usable email to be stored (it is the dedupe key); rows
	 * without one are ignored so we don't accumulate blank entries.
	 *
	 * @param int    $account_id Account scope.
	 * @param string $name       Display name.
	 * @param string $email      Email (dedupe key).
	 * @param string $phone      Optional phone.
	 *
	 * @return int Contact id (0 when nothing was stored).
	 */
	public function upsert( int $account_id, string $name, string $email, string $phone = '' ): int {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( $account_id <= 0 || '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		$name  = sanitize_text_field( $name );
		$phone = sanitize_text_field( $phone );
		$now   = current_time( 'mysql', true );
		$table = Installer::contacts_table();

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} WHERE account_id = %d AND email = %s", $account_id, $email )
		);

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					// Keep the most recent non-empty name/phone we've seen.
					'name'       => '' !== $name ? $name : null,
					'phone'      => '' !== $phone ? $phone : null,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'account_id' => $account_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Contacts visible to a set of accounts, optionally filtered by a term.
	 *
	 * @param int[]  $account_ids Account scope.
	 * @param string $search      Substring matched against name/email (optional).
	 * @param int    $limit       Max rows.
	 *
	 * @return object[]
	 */
	public function for_accounts( array $account_ids, string $search = '', int $limit = 100 ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $account_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$where        = "WHERE account_id IN ($placeholders)";
		$args         = $ids;

		$search = trim( $search );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND ( name LIKE %s OR email LIKE %s )';
			$args[]  = $like;
			$args[]  = $like;
		}

		$args[] = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . Installer::contacts_table() . " {$where} ORDER BY name ASC, email ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$args
			)
		);
	}

	/**
	 * Number of contacts in a single workspace (for plan-limit checks).
	 */
	public function count_for_account( int $account_id ): int {
		global $wpdb;
		if ( $account_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::contacts_table() . ' WHERE account_id = %d', $account_id )
		);
	}

	/**
	 * Fetch a contact by id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::contacts_table() . ' WHERE id = %d', $id )
		);
		return $row ?: null;
	}

	/**
	 * Delete a contact.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::contacts_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
