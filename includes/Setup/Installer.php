<?php
/**
 * Database schema installer.
 *
 * @package ComSign
 */

namespace ComSign\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 */
final class Installer {

	private const OPTION_DB_VERSION = 'comsign_db_version';

	/**
	 * Fully qualified table name for documents.
	 */
	public static function documents_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_documents';
	}

	/**
	 * Fully qualified table name for signers.
	 */
	public static function signers_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_signers';
	}

	/**
	 * Fully qualified table name for signature fields.
	 */
	public static function fields_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_fields';
	}

	/**
	 * Fully qualified table name for the audit trail.
	 */
	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_audit';
	}

	/**
	 * Fully qualified table name for reusable templates.
	 */
	public static function templates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_templates';
	}

	/**
	 * Run dbDelta to create/update the schema, then store the version.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$documents = self::documents_table();
		$signers   = self::signers_table();
		$fields    = self::fields_table();
		$audit     = self::audit_table();
		$templates = self::templates_table();

		$schema = array();

		// Documents: one row per uploaded document/envelope.
		$schema[] = "CREATE TABLE {$documents} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			sequential TINYINT(1) NOT NULL DEFAULT 0,
			message TEXT NULL,
			expires_at DATETIME DEFAULT NULL,
			source_path VARCHAR(255) NOT NULL DEFAULT '',
			signed_path VARCHAR(255) NOT NULL DEFAULT '',
			signed_hash CHAR(64) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_by (created_by)
		) {$charset_collate};";

		// Signers: recipients who must sign a document.
		$schema[] = "CREATE TABLE {$signers} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			document_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			token_hash CHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sign_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			auth_method VARCHAR(20) NOT NULL DEFAULT 'none',
			auth_code_hash VARCHAR(255) NOT NULL DEFAULT '',
			reminded_at DATETIME DEFAULT NULL,
			viewed_at DATETIME DEFAULT NULL,
			signed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY document_id (document_id),
			KEY email (email)
		) {$charset_collate};";

		// Fields: signature/date/text placements on the document, per signer.
		$schema[] = "CREATE TABLE {$fields} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			document_id BIGINT UNSIGNED NOT NULL,
			signer_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'signature',
			required TINYINT(1) NOT NULL DEFAULT 0,
			page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			pos_x DECIMAL(8,4) NOT NULL DEFAULT 0,
			pos_y DECIMAL(8,4) NOT NULL DEFAULT 0,
			width DECIMAL(8,4) NOT NULL DEFAULT 0,
			height DECIMAL(8,4) NOT NULL DEFAULT 0,
			options LONGTEXT NULL,
			value LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY document_id (document_id),
			KEY signer_id (signer_id)
		) {$charset_collate};";

		// Audit: append-only trail of events for legal traceability.
		$schema[] = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			document_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			signer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(40) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY document_id (document_id),
			KEY signer_id (signer_id),
			KEY event (event)
		) {$charset_collate};";

		// Templates: reusable document + field definitions (fields keyed by role).
		$schema[] = "CREATE TABLE {$templates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			source_path VARCHAR(255) NOT NULL DEFAULT '',
			roles LONGTEXT NULL,
			fields LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_by (created_by)
		) {$charset_collate};";

		// Drop the legacy UNIQUE index on token_hash before dbDelta re-adds it as
		// a plain index. The unique index made two not-yet-sent signers (both with
		// an empty token_hash) collide. Safe/no-op on fresh installs and SQLite.
		self::drop_legacy_token_unique_index();

		foreach ( $schema as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_DB_VERSION, COMSIGN_DB_VERSION );
	}

	/**
	 * Best-effort removal of the old UNIQUE(token_hash) index.
	 */
	private static function drop_legacy_token_unique_index(): void {
		global $wpdb;

		$signers  = self::signers_table();
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$index = $wpdb->get_results( "SHOW INDEX FROM {$signers} WHERE Key_name = 'token_hash' AND Non_unique = 0" );
		if ( ! empty( $index ) ) {
			$wpdb->query( "ALTER TABLE {$signers} DROP INDEX token_hash" );
		}
		// phpcs:enable

		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Run an upgrade when the stored DB version is behind the code.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION ) !== COMSIGN_DB_VERSION ) {
			self::install();
		}
	}
}
