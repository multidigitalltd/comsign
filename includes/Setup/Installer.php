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
	 * Fully qualified table name for accounts (tenants/workspaces).
	 */
	public static function accounts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_accounts';
	}

	/**
	 * Fully qualified table name for account memberships.
	 */
	public static function account_users_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_account_users';
	}

	/**
	 * Fully qualified table name for pending workspace invitations.
	 */
	public static function invites_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_invites';
	}

	/**
	 * Contacts (address book) table name.
	 */
	public static function contacts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'comsign_contacts';
	}

	/**
	 * Option holding the id of the default (migration) account.
	 */
	public const OPTION_DEFAULT_ACCOUNT = 'comsign_default_account';

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
		$accounts      = self::accounts_table();
		$account_users = self::account_users_table();
		$invites       = self::invites_table();

		$schema = array();

		// Documents: one row per uploaded document/envelope.
		$schema[] = "CREATE TABLE {$documents} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			sequential TINYINT(1) NOT NULL DEFAULT 0,
			allow_delegation TINYINT(1) NOT NULL DEFAULT 0,
			message TEXT NULL,
			cc_emails TEXT NULL,
			expires_at DATETIME DEFAULT NULL,
			source_path VARCHAR(255) NOT NULL DEFAULT '',
			signed_path VARCHAR(255) NOT NULL DEFAULT '',
			signed_hash CHAR(64) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY account_id (account_id),
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
			label VARCHAR(150) NOT NULL DEFAULT '',
			help_text VARCHAR(255) NOT NULL DEFAULT '',
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
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY account_id (account_id),
			KEY created_by (created_by)
		) {$charset_collate};";

		// Accounts: tenants / workspaces. parent_id links a sub-account to its
		// parent so a manager account can see all descendants' documents.
		$schema[] = "CREATE TABLE {$accounts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tier VARCHAR(40) NOT NULL DEFAULT 'free',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY parent_id (parent_id)
		) {$charset_collate};";

		// Account memberships: which WP user belongs to which account, and as
		// what role (owner / admin / member).
		$schema[] = "CREATE TABLE {$account_users} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY account_user (account_id, user_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		// Invitations: a pending membership for an email that has no WP user yet
		// (or that has not joined). Claimed on registration/login.
		$schema[] = "CREATE TABLE {$invites} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL DEFAULT '',
			role VARCHAR(20) NOT NULL DEFAULT 'viewer',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY account_email (account_id, email),
			KEY email (email)
		) {$charset_collate};";

		// Contacts (address book): reusable signer details, scoped to an account.
		$contacts = self::contacts_table();
		$schema[] = "CREATE TABLE {$contacts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY account_email (account_id, email),
			KEY account_id (account_id)
		) {$charset_collate};";

		// Drop the legacy UNIQUE index on token_hash before dbDelta re-adds it as
		// a plain index. The unique index made two not-yet-sent signers (both with
		// an empty token_hash) collide. Safe/no-op on fresh installs and SQLite.
		self::drop_legacy_token_unique_index();

		foreach ( $schema as $statement ) {
			dbDelta( $statement );
		}

		self::migrate_accounts();

		update_option( self::OPTION_DB_VERSION, COMSIGN_DB_VERSION );
	}

	/**
	 * Ensure a default account exists, every existing document/template belongs
	 * to it, and every current manager is an owner of it. Idempotent, so it is
	 * safe to run on every upgrade.
	 */
	private static function migrate_accounts(): void {
		global $wpdb;

		$accounts = self::accounts_table();
		$now      = current_time( 'mysql', true );

		// 1) Ensure the default account exists.
		$default_id = (int) get_option( self::OPTION_DEFAULT_ACCOUNT, 0 );
		$exists     = $default_id
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$accounts} WHERE id = %d", $default_id ) ) // phpcs:ignore WordPress.DB
			: 0;
		if ( ! $exists ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$accounts,
				array(
					'name'       => __( 'Default workspace', 'comsign' ),
					'parent_id'  => 0,
					'tier'       => 'free',
					'created_at' => $now,
				),
				array( '%s', '%d', '%s', '%s' )
			);
			$default_id = (int) $wpdb->insert_id;
			update_option( self::OPTION_DEFAULT_ACCOUNT, $default_id );
		}

		// 2) Backfill account_id on pre-existing rows (account_id = 0).
		$docs = self::documents_table();
		$tpl  = self::templates_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET account_id = %d WHERE account_id = 0", $default_id ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "UPDATE {$tpl} SET account_id = %d WHERE account_id = 0", $default_id ) );  // phpcs:ignore WordPress.DB

		// 3) Make every current manager (and every past document creator) an
		//    owner of the default account, so existing access is preserved.
		$au       = self::account_users_table();
		$user_ids = array();
		foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $u ) {
			$uid = (int) $u->ID;
			// Core administrators (manage_options) always qualify, so the
			// activating admin becomes an owner even before the custom cap is
			// granted; plus anyone already holding the ComSign manage cap.
			if ( user_can( $uid, 'manage_options' ) || user_can( $uid, \ComSign\Support\Capabilities::MANAGE ) ) {
				$user_ids[] = $uid;
			}
		}
		$creators = $wpdb->get_col( "SELECT DISTINCT created_by FROM {$docs} WHERE created_by > 0" ); // phpcs:ignore WordPress.DB
		$user_ids = array_unique( array_merge( $user_ids, array_map( 'intval', (array) $creators ) ) );

		foreach ( $user_ids as $uid ) {
			$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$au} WHERE account_id = %d AND user_id = %d", $default_id, $uid ) ); // phpcs:ignore WordPress.DB
			if ( ! $has ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$au,
					array(
						'account_id' => $default_id,
						'user_id'    => $uid,
						'role'       => 'owner',
						'created_at' => $now,
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}
		}
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
