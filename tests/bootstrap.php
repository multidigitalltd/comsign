<?php
/**
 * Boot a real WordPress (SQLite) with ComSign active, for integration tests.
 *
 * The WordPress install is located via the COMSIGN_WP_LOAD environment variable
 * (path to wp-load.php). Run tests/install.php once first (tests/setup-wp.sh does
 * this) so the site exists and the plugin is active — this file then loads
 * WordPress normally, which loads active plugins and their autoloaders.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

$wp_load = getenv( 'COMSIGN_WP_LOAD' );
if ( ! $wp_load ) {
	$wp_load = '/tmp/wp/WordPress-master/wp-load.php';
}

if ( ! is_file( $wp_load ) ) {
	fwrite( STDERR, "Cannot find WordPress at: {$wp_load}\nSet COMSIGN_WP_LOAD or run tests/setup-wp.sh first.\n" );
	exit( 2 );
}

require $wp_load;

if ( ! function_exists( 'is_blog_installed' ) || ! is_blog_installed() ) {
	fwrite( STDERR, "WordPress is not installed. Run: php tests/install.php\n" );
	exit( 2 );
}

if ( ! class_exists( '\ComSign\Setup\Installer' ) ) {
	fwrite( STDERR, "ComSign is not active in the test site. Run: php tests/install.php\n" );
	exit( 2 );
}

// Run as the admin and never actually send email during tests.
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true', 10, 2 );

// Make sure the schema is current (covers upgrades between runs).
\ComSign\Setup\Installer::maybe_upgrade();

require __DIR__ . '/lib/assert.php';

/**
 * Reset ComSign tables between test cases so each starts from a clean slate.
 */
function reset_tables(): void {
	global $wpdb;
	foreach ( array(
		\ComSign\Setup\Installer::fields_table(),
		\ComSign\Setup\Installer::signers_table(),
		\ComSign\Setup\Installer::audit_table(),
		\ComSign\Setup\Installer::documents_table(),
		\ComSign\Setup\Installer::templates_table(),
	) as $table ) {
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
