<?php
/**
 * Plugin Name:       ComSign
 * Plugin URI:        https://github.com/multidigitalltd/comsign
 * Description:       Secure digital document signing for WordPress - upload a PDF, place signature fields, send by email, sign online (draw / type / stamp), embed the signature in the PDF and keep a full audit trail. Full Hebrew/RTL support.
 * Version:           0.10.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Multi Digital LTD
 * Author URI:        https://github.com/multidigitalltd
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       comsign
 * Domain Path:       /languages
 *
 * @package ComSign
 */

defined( 'ABSPATH' ) || exit;

/*
 * --------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------------
 */
define( 'COMSIGN_VERSION', '0.10.0' );
define( 'COMSIGN_DB_VERSION', '8' );
define( 'COMSIGN_PLUGIN_FILE', __FILE__ );
define( 'COMSIGN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COMSIGN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'COMSIGN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * --------------------------------------------------------------------------
 * Autoloading
 * --------------------------------------------------------------------------
 *
 * We ship a committed Composer vendor/ directory so the plugin works on the
 * target site with no build step. If it is missing (e.g. a developer cloned
 * the repo without running `composer install`), fail loudly but safely.
 */
$comsign_autoload = COMSIGN_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $comsign_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'ComSign: dependencies are missing. Please run "composer install" inside the plugin directory.', 'comsign' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $comsign_autoload;

/*
 * --------------------------------------------------------------------------
 * Activation / Deactivation
 * --------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( \ComSign\Setup\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ComSign\Setup\Deactivator::class, 'deactivate' ) );

/*
 * --------------------------------------------------------------------------
 * Bootstrap
 * --------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		\ComSign\Plugin::instance()->run();
	}
);
