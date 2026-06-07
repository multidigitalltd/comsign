<?php
/**
 * Pending workspace invitations.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * CRUD for invitations: an email is invited to an account with a role, and
 * claims its membership when the matching user registers or logs in.
 */
final class InviteRepository {

	/**
	 * Create or update an invitation for an email + account.
	 */
	public function upsert( int $account_id, string $email, string $role ): void {
		global $wpdb;

		$email = strtolower( trim( $email ) );

		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT id FROM ' . Installer::invites_table() . ' WHERE account_id = %d AND email = %s', $account_id, $email )
		);

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Installer::invites_table(),
				array( 'role' => $role ),
				array( 'id' => $existing ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::invites_table(),
			array(
				'account_id' => $account_id,
				'email'      => $email,
				'role'       => $role,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Pending invitations for an email address.
	 *
	 * @return array<int,object>
	 */
	public function for_email( string $email ): array {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::invites_table() . ' WHERE email = %s', strtolower( trim( $email ) ) )
		);
	}

	/**
	 * Pending invitations for an account (for the members UI).
	 *
	 * @return array<int,object>
	 */
	public function for_account( int $account_id ): array {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::invites_table() . ' WHERE account_id = %d ORDER BY created_at DESC', $account_id )
		);
	}

	/**
	 * Delete an invitation.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::invites_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete an invitation by account + email (after it is claimed).
	 */
	public function delete_for( int $account_id, string $email ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::invites_table(),
			array(
				'account_id' => $account_id,
				'email'      => strtolower( trim( $email ) ),
			),
			array( '%d', '%s' )
		);
	}
}
