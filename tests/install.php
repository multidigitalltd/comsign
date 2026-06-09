<?php
/**
 * One-time installer for the test WordPress: install the site (if needed) and
 * activate ComSign. Run by tests/setup-wp.sh; safe to re-run.
 *
 * Uses WP_INSTALLING, which means active plugins are NOT loaded in this process
 * — that's fine here, we only need to install the site and flip the active-plugin
 * option. tests/bootstrap.php then loads WordPress normally so the plugin (and
 * its autoloader) are present for the actual tests.
 *
 * @package ComSign\Tests
 */

$wp_load = getenv( 'COMSIGN_WP_LOAD' ) ?: '/tmp/wp/WordPress-master/wp-load.php';

if ( ! defined( 'WP_INSTALLING' ) ) {
	define( 'WP_INSTALLING', true );
}

require $wp_load;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'ComSign Test', 'admin', 'admin@example.com', true, '', 'admin12345' );
	echo "WordPress installed.\n";
}

$activated = activate_plugin( 'comsign/comsign.php' );
if ( is_wp_error( $activated ) ) {
	fwrite( STDERR, 'Could not activate ComSign: ' . $activated->get_error_message() . "\n" );
	exit( 2 );
}

echo "ComSign activated.\n";
