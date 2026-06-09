<?php
/**
 * Account (tenant/workspace) data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Read/write access to accounts and their memberships.
 */
final class AccountRepository {

	public const ROLE_OWNER  = 'owner';
	public const ROLE_ADMIN  = 'admin';
	public const ROLE_SENDER = 'sender';
	public const ROLE_VIEWER = 'viewer';
	public const ROLE_MEMBER = 'member';

	/**
	 * Roles whose holders also see every descendant (sub-)account.
	 */
	public const MANAGER_ROLES = array( self::ROLE_OWNER, self::ROLE_ADMIN );

	/**
	 * Create an account and return its id.
	 *
	 * @param string $name      Display name.
	 * @param int    $parent_id Parent account (0 = top level).
	 * @param string $tier      Subscription tier.
	 */
	public function create( string $name, int $parent_id = 0, string $tier = 'free' ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::accounts_table(),
			array(
				'name'       => $name,
				'parent_id'  => max( 0, $parent_id ),
				'tier'       => $tier,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one account.
	 */
	public function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::accounts_table() . ' WHERE id = %d', $id )
		);
		return $row ?: null;
	}

	/**
	 * Set (or clear) a workspace's outgoing-webhook URL and secret.
	 *
	 * @param int    $account_id Account id.
	 * @param string $url        Webhook URL ('' to disable).
	 * @param string $secret     HMAC secret for signing deliveries.
	 */
	public function update_webhook( int $account_id, string $url, string $secret ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::accounts_table(),
			array(
				'webhook_url'    => substr( $url, 0, 255 ),
				'webhook_secret' => substr( $secret, 0, 64 ),
			),
			array( 'id' => $account_id )
		);
	}

	/**
	 * All accounts (for the admin switcher).
	 */
	public function all(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Installer::accounts_table() . ' ORDER BY name ASC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Direct children of an account.
	 *
	 * @return int[] Child account ids.
	 */
	public function child_ids( int $parent_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT id FROM ' . Installer::accounts_table() . ' WHERE parent_id = %d', $parent_id )
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * An account plus every descendant id (recursive), the set a manager sees.
	 *
	 * @return int[]
	 */
	public function descendant_ids( int $account_id ): array {
		$all   = array( $account_id );
		$queue = array( $account_id );

		// Breadth-first walk; guarded against cycles by tracking seen ids.
		while ( $queue ) {
			$current  = (int) array_shift( $queue );
			$children = $this->child_ids( $current );
			foreach ( $children as $child ) {
				if ( ! in_array( $child, $all, true ) ) {
					$all[]   = $child;
					$queue[] = $child;
				}
			}
		}

		return $all;
	}

	/**
	 * An account plus every ancestor id (walking parent_id upwards).
	 *
	 * @return int[] Including the account itself.
	 */
	public function ancestor_ids( int $account_id ): array {
		$ids     = array( $account_id );
		$current = $this->find( $account_id );
		$guard   = 0;

		while ( $current && (int) $current->parent_id > 0 && $guard < 50 ) {
			$parent = (int) $current->parent_id;
			if ( in_array( $parent, $ids, true ) ) {
				break; // cycle guard
			}
			$ids[]   = $parent;
			$current = $this->find( $parent );
			++$guard;
		}

		return $ids;
	}

	/**
	 * Add (or update the role of) a user in an account.
	 */
	public function add_member( int $account_id, int $user_id, string $role = self::ROLE_MEMBER ): void {
		global $wpdb;

		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT id FROM ' . Installer::account_users_table() . ' WHERE account_id = %d AND user_id = %d', $account_id, $user_id )
		);

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Installer::account_users_table(),
				array( 'role' => $role ),
				array( 'id' => $existing ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::account_users_table(),
			array(
				'account_id' => $account_id,
				'user_id'    => $user_id,
				'role'       => $role,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Remove a user from an account.
	 */
	public function remove_member( int $account_id, int $user_id ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::account_users_table(),
			array(
				'account_id' => $account_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Memberships for a user.
	 *
	 * @return array<int,object> Rows with account_id + role.
	 */
	public function memberships_for_user( int $user_id ): array {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT account_id, role FROM ' . Installer::account_users_table() . ' WHERE user_id = %d', $user_id )
		);
	}

	/**
	 * Members of an account.
	 *
	 * @return array<int,object> Rows with user_id + role.
	 */
	public function members_of( int $account_id ): array {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT user_id, role FROM ' . Installer::account_users_table() . ' WHERE account_id = %d', $account_id )
		);
	}

	/**
	 * A user's direct role in an account ('' if none).
	 */
	public function role_of( int $account_id, int $user_id ): string {
		global $wpdb;
		$role = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT role FROM ' . Installer::account_users_table() . ' WHERE account_id = %d AND user_id = %d', $account_id, $user_id )
		);
		return (string) ( $role ?? '' );
	}
}
