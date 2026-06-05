<?php
/**
 * ComSign uninstall routine.
 *
 * Runs only when the user deletes the plugin from WordPress. Removes all of
 * the plugin's data: custom tables, stored PDFs, options and capabilities.
 *
 * @package ComSign
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'comsign_documents',
	$wpdb->prefix . 'comsign_signers',
	$wpdb->prefix . 'comsign_fields',
	$wpdb->prefix . 'comsign_audit',
	$wpdb->prefix . 'comsign_templates',
);

foreach ( $tables as $table ) {
	// Table name cannot be parameterised; it is built from a trusted prefix.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

// Remove options.
delete_option( 'comsign_db_version' );
delete_option( 'comsign_settings' );

// Clear the scheduled reminder event.
wp_clear_scheduled_hook( 'comsign_daily_reminders' );

// Remove the custom capability from all roles.
if ( function_exists( 'wp_roles' ) ) {
	foreach ( wp_roles()->roles as $role_name => $role_info ) {
		$role = get_role( $role_name );
		if ( $role && $role->has_cap( 'comsign_manage_documents' ) ) {
			$role->remove_cap( 'comsign_manage_documents' );
		}
	}
}

// Delete the private storage directory (source + signed PDFs).
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'comsign';

if ( is_dir( $dir ) ) {
	// GLOB_BRACE + a dotfile pattern so .htaccess / index.php are caught too,
	// otherwise rmdir() would fail and leave the directory behind.
	$items = array_merge(
		(array) glob( $dir . '/*' ),
		(array) glob( $dir . '/.*' )
	);
	foreach ( $items as $item ) {
		if ( is_file( $item ) ) {
			wp_delete_file( $item );
		}
	}
	// Remove now-empty directory.
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
