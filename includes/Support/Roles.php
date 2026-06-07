<?php
/**
 * Account role -> permission matrix (RBAC).
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AccountRepository;

/**
 * Defines what each account role may do. Permissions are checked at the
 * service layer (not just in the UI) so a user cannot exceed their role.
 */
final class Roles {

	// Permission keys.
	public const VIEW_DOCUMENTS   = 'view_documents';
	public const CREATE_DOCUMENTS = 'create_documents';
	public const SEND_DOCUMENTS   = 'send_documents';
	public const DELETE_DOCUMENTS = 'delete_documents';
	public const MANAGE_TEMPLATES = 'manage_templates';
	public const MANAGE_MEMBERS   = 'manage_members';
	public const MANAGE_SETTINGS  = 'manage_settings';

	/**
	 * Role -> granted permissions.
	 *
	 * @return array<string,string[]>
	 */
	private static function matrix(): array {
		$all = array(
			self::VIEW_DOCUMENTS,
			self::CREATE_DOCUMENTS,
			self::SEND_DOCUMENTS,
			self::DELETE_DOCUMENTS,
			self::MANAGE_TEMPLATES,
			self::MANAGE_MEMBERS,
			self::MANAGE_SETTINGS,
		);

		return array(
			AccountRepository::ROLE_OWNER => $all,
			AccountRepository::ROLE_ADMIN => array(
				self::VIEW_DOCUMENTS,
				self::CREATE_DOCUMENTS,
				self::SEND_DOCUMENTS,
				self::DELETE_DOCUMENTS,
				self::MANAGE_TEMPLATES,
				self::MANAGE_MEMBERS,
			),
			AccountRepository::ROLE_SENDER => array(
				self::VIEW_DOCUMENTS,
				self::CREATE_DOCUMENTS,
				self::SEND_DOCUMENTS,
				self::MANAGE_TEMPLATES,
			),
			AccountRepository::ROLE_VIEWER => array(
				self::VIEW_DOCUMENTS,
			),
			// Legacy 'member' behaves like a sender (its historical capability).
			AccountRepository::ROLE_MEMBER => array(
				self::VIEW_DOCUMENTS,
				self::CREATE_DOCUMENTS,
				self::SEND_DOCUMENTS,
				self::MANAGE_TEMPLATES,
			),
		);
	}

	/**
	 * Whether a role grants a permission.
	 */
	public static function can( string $role, string $permission ): bool {
		$matrix = self::matrix();
		return isset( $matrix[ $role ] ) && in_array( $permission, $matrix[ $role ], true );
	}

	/**
	 * All assignable roles with human labels (for the members UI).
	 *
	 * @return array<string,string>
	 */
	public static function assignable(): array {
		return array(
			AccountRepository::ROLE_OWNER  => __( 'Owner', 'comsign' ),
			AccountRepository::ROLE_ADMIN  => __( 'Admin', 'comsign' ),
			AccountRepository::ROLE_SENDER => __( 'Sender', 'comsign' ),
			AccountRepository::ROLE_VIEWER => __( 'Viewer', 'comsign' ),
		);
	}

	/**
	 * Human label for a role.
	 */
	public static function label( string $role ): string {
		$labels = self::assignable();
		return $labels[ $role ] ?? ucfirst( $role );
	}
}
