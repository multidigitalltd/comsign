<?php
/**
 * Account / tenancy business logic.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AccountRepository;
use ComSign\Setup\Installer;

/**
 * Resolves which accounts a user can see (including descendant sub-accounts for
 * managers), the user's "current" account, and access decisions. This is the
 * single source of truth for tenant isolation.
 */
final class AccountService {

	private AccountRepository $accounts;

	private const META_CURRENT = 'comsign_current_account';

	public function __construct( ?AccountRepository $accounts = null ) {
		$this->accounts = $accounts ?? new AccountRepository();
	}

	/**
	 * The default (migration) account id.
	 */
	public function default_account_id(): int {
		return (int) get_option( Installer::OPTION_DEFAULT_ACCOUNT, 0 );
	}

	/**
	 * Every account id a user may see.
	 *
	 * A membership as owner/admin (a "manager") also grants visibility of every
	 * descendant account, so e.g. a company manager sees all their agents'
	 * sub-accounts. A plain member sees only that account.
	 *
	 * @return int[] Unique account ids (empty if the user belongs to none).
	 */
	public function visible_account_ids( int $user_id ): array {
		$ids = array();

		foreach ( $this->accounts->memberships_for_user( $user_id ) as $m ) {
			$account_id = (int) $m->account_id;
			if ( in_array( $m->role, AccountRepository::MANAGER_ROLES, true ) ) {
				foreach ( $this->accounts->descendant_ids( $account_id ) as $descendant ) {
					$ids[ $descendant ] = true;
				}
			} else {
				$ids[ $account_id ] = true;
			}
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * The accounts a user is directly a member of (for the switcher).
	 *
	 * @return array<int,object> Account rows.
	 */
	public function accounts_for_user( int $user_id ): array {
		$out = array();
		foreach ( $this->accounts->memberships_for_user( $user_id ) as $m ) {
			$account = $this->accounts->find( (int) $m->account_id );
			if ( $account ) {
				$account->role = $m->role;
				$out[]         = $account;
			}
		}
		return $out;
	}

	/**
	 * The user's current working account id.
	 *
	 * Falls back to the first membership (preferring a managed one) and is always
	 * validated against the user's memberships so a stale meta value cannot leak
	 * access to an account they were removed from.
	 */
	public function current_account_id( int $user_id ): int {
		$memberships = $this->accounts->memberships_for_user( $user_id );
		if ( ! $memberships ) {
			return 0;
		}

		$member_ids = array_map( static fn( $m ) => (int) $m->account_id, $memberships );
		$stored     = (int) get_user_meta( $user_id, self::META_CURRENT, true );
		if ( $stored && in_array( $stored, $member_ids, true ) ) {
			return $stored;
		}

		return (int) $member_ids[0];
	}

	/**
	 * Set the user's current account (only if they are a member of it).
	 */
	public function set_current_account( int $user_id, int $account_id ): bool {
		$member_ids = array_map( static fn( $m ) => (int) $m->account_id, $this->accounts->memberships_for_user( $user_id ) );
		if ( ! in_array( $account_id, $member_ids, true ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_CURRENT, $account_id );
		return true;
	}

	/**
	 * Whether a user may access a document (by its account scope).
	 *
	 * @param object|null $document Document row (must carry account_id).
	 */
	public function can_access_document( ?object $document, int $user_id ): bool {
		if ( ! $document ) {
			return false;
		}
		return in_array( (int) ( $document->account_id ?? 0 ), $this->visible_account_ids( $user_id ), true );
	}

	/**
	 * Create a (sub-)account and make the creator its owner.
	 *
	 * @return int New account id.
	 */
	public function create_account( string $name, int $owner_user_id, int $parent_id = 0, string $tier = 'free' ): int {
		$id = $this->accounts->create( $name, $parent_id, $tier );
		$this->accounts->add_member( $id, $owner_user_id, AccountRepository::ROLE_OWNER );
		return $id;
	}

	/**
	 * Expose the underlying repository for callers that need raw membership ops.
	 */
	public function repository(): AccountRepository {
		return $this->accounts;
	}
}
