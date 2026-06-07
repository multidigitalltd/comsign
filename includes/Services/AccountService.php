<?php
/**
 * Account / tenancy business logic.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AccountRepository;
use ComSign\Database\InviteRepository;
use ComSign\Setup\Installer;
use ComSign\Support\Roles;

/**
 * Resolves which accounts a user can see (including descendant sub-accounts for
 * managers), the user's "current" account, and access decisions. This is the
 * single source of truth for tenant isolation.
 */
final class AccountService {

	private AccountRepository $accounts;
	private InviteRepository $invites;

	private const META_CURRENT = 'comsign_current_account';

	public function __construct( ?AccountRepository $accounts = null, ?InviteRepository $invites = null ) {
		$this->accounts = $accounts ?? new AccountRepository();
		$this->invites  = $invites ?? new InviteRepository();
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

	/* ---------------------------------------------------------------------
	 * RBAC
	 * ------------------------------------------------------------------- */

	/**
	 * A user's effective role in an account.
	 *
	 * Direct membership wins; otherwise, if the user is a manager (owner/admin)
	 * of an ancestor account, that managing role applies to this sub-account.
	 * Returns '' when the user has no access.
	 */
	public function effective_role_for_account( int $user_id, int $account_id ): string {
		$direct = $this->accounts->role_of( $account_id, $user_id );
		if ( '' !== $direct ) {
			return $direct;
		}

		foreach ( $this->accounts->ancestor_ids( $account_id ) as $ancestor ) {
			if ( $ancestor === $account_id ) {
				continue;
			}
			$role = $this->accounts->role_of( $ancestor, $user_id );
			if ( in_array( $role, AccountRepository::MANAGER_ROLES, true ) ) {
				return $role;
			}
		}

		return '';
	}

	/**
	 * Whether a user has a permission in an account (RBAC).
	 */
	public function can_in_account( int $user_id, int $account_id, string $permission ): bool {
		$role = $this->effective_role_for_account( $user_id, $account_id );
		return '' !== $role && Roles::can( $role, $permission );
	}

	/**
	 * Whether a user has a permission on a specific document.
	 */
	public function can_for_document( int $user_id, ?object $document, string $permission ): bool {
		if ( ! $document ) {
			return false;
		}
		return $this->can_in_account( $user_id, (int) ( $document->account_id ?? 0 ), $permission );
	}

	/**
	 * The current user's role in their current account.
	 */
	public function current_role( int $user_id ): string {
		return $this->effective_role_for_account( $user_id, $this->current_account_id( $user_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Invitations + onboarding
	 * ------------------------------------------------------------------- */

	/**
	 * Invite an email to an account with a role.
	 *
	 * If a WP user already exists for the email they are added immediately;
	 * otherwise a pending invitation is stored and claimed on registration/login.
	 *
	 * @return string 'added' if joined now, 'invited' if pending.
	 */
	public function invite( int $account_id, string $email, string $role ): string {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! array_key_exists( $role, Roles::assignable() ) ) {
			throw new \RuntimeException( __( 'Please provide a valid email and role.', 'comsign' ) );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$this->accounts->add_member( $account_id, (int) $user->ID, $role );
			return 'added';
		}

		$this->invites->upsert( $account_id, $email, $role );
		return 'invited';
	}

	/**
	 * Claim any pending invitations for a user (by their email).
	 *
	 * @return int Number of memberships granted.
	 */
	public function claim_invites( int $user_id ): int {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return 0;
		}

		$claimed = 0;
		foreach ( $this->invites->for_email( $user->user_email ) as $invite ) {
			$this->accounts->add_member( (int) $invite->account_id, $user_id, (string) $invite->role );
			$this->invites->delete_for( (int) $invite->account_id, $user->user_email );
			++$claimed;
		}

		return $claimed;
	}

	/**
	 * Ensure a user belongs to at least one workspace.
	 *
	 * Claims pending invites first; if the user still has no membership, creates
	 * a personal workspace and makes them its owner. This lets a brand-new user
	 * (e.g. signing in with Google for the first time) work immediately.
	 */
	public function ensure_onboarded( int $user_id ): void {
		$this->claim_invites( $user_id );

		if ( $this->accounts->memberships_for_user( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$name = $user->display_name ? $user->display_name : $user->user_login;
		/* translators: %s: user display name. */
		$this->create_account( sprintf( __( '%s\'s workspace', 'comsign' ), $name ), $user_id );
	}
}
