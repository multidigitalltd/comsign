<?php
/**
 * Template data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Read/write access to the reusable templates table.
 *
 * A template stores the source PDF, a list of role labels, and the field
 * definitions keyed by role index (rather than a concrete signer), so the same
 * layout can be reused for any set of recipients.
 */
final class TemplateRepository {

	/**
	 * Insert a template and return its id.
	 *
	 * @param array $data name, source_path, roles (array), fields (array).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::templates_table(),
			array(
				'name'        => (string) ( $data['name'] ?? '' ),
				'source_path' => (string) ( $data['source_path'] ?? '' ),
				'roles'       => wp_json_encode( array_values( (array) ( $data['roles'] ?? array() ) ) ),
				'fields'      => wp_json_encode( array_values( (array) ( $data['fields'] ?? array() ) ) ),
				'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a template by id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Installer::templates_table() . ' WHERE id = %d', $id )
		);

		return $row ?: null;
	}

	/**
	 * All templates, newest first.
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . Installer::templates_table() . ' ORDER BY id DESC' );
	}

	/**
	 * Delete a template row.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::templates_table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Decode the role labels for a template.
	 *
	 * @param object $template Template row.
	 *
	 * @return string[]
	 */
	public static function roles( object $template ): array {
		$decoded = json_decode( (string) $template->roles, true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : array();
	}

	/**
	 * Decode the field definitions for a template.
	 *
	 * @param object $template Template row.
	 *
	 * @return array[]
	 */
	public static function fields( object $template ): array {
		$decoded = json_decode( (string) $template->fields, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
