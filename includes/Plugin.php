<?php
/**
 * Main plugin orchestrator.
 *
 * @package ComSign
 */

namespace ComSign;

defined( 'ABSPATH' ) || exit;

use ComSign\Admin\Admin;
use ComSign\Frontend\SigningController;

/**
 * Wires up the plugin's services and hooks.
 *
 * Kept deliberately thin: it only loads text domain and delegates to the
 * admin and frontend controllers.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Retrieve the singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use {@see Plugin::instance()}.
	 */
	private function __construct() {}

	/**
	 * Register hooks and boot the controllers.
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Database upgrades for sites updated without re-activating. run() is
		// already executing on plugins_loaded, so call this directly rather than
		// hooking plugins_loaded again (which would be too late to fire).
		Setup\Installer::maybe_upgrade();

		// Ensure the reminder cron stays scheduled even after a silent update.
		add_action( 'init', array( Setup\Cron::class, 'schedule' ) );
		( new Setup\Cron() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		( new SigningController() )->register();

		// Front-end client portal (/comsign/app).
		( new \ComSign\Frontend\PortalController() )->register();

		// Claim pending workspace invitations when a user logs in or registers
		// (covers email/password and social logins like Google).
		$claim = static function ( $user_id ): void {
			( new \ComSign\Services\AccountService() )->claim_invites( (int) $user_id );
		};
		add_action( 'user_register', $claim );
		add_action( 'wp_login', static function ( $login, $user ) use ( $claim ): void {
			$claim( $user->ID );
		}, 10, 2 );

		// Integrations: outgoing webhooks + REST API.
		( new \ComSign\Integrations\Webhooks() )->register();
		( new \ComSign\Integrations\RestApi() )->register();
	}

	/**
	 * Load the plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'comsign',
			false,
			dirname( COMSIGN_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
