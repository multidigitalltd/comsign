<?php
/**
 * Capability management.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises the custom capability used to gate the admin UI.
 */
final class Capabilities {

	/**
	 * Capability required to manage documents and signers.
	 */
	public const MANAGE = 'comsign_manage_documents';

	/**
	 * Grant the capability to administrators on activation.
	 */
	public static function add(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Remove the capability (used on uninstall).
	 */
	public static function remove(): void {
		$role = get_role( 'administrator' );
		if ( $role && $role->has_cap( self::MANAGE ) ) {
			$role->remove_cap( self::MANAGE );
		}
	}
}
