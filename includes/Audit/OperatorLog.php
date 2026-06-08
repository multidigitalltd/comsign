<?php
/**
 * Operator (cross-tenant admin) action log.
 *
 * @package ComSign
 */

namespace ComSign\Audit;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Records high-impact operator overrides (manual plan changes, suspensions,
 * reactivations) on customer workspaces so cross-tenant admin actions are
 * traceable: who did what, to which workspace, from/to which plan & status,
 * when, and optionally why.
 */
final class OperatorLog {

	public const ACTION_SET_PLAN   = 'set_plan';
	public const ACTION_SUSPEND    = 'suspend';
	public const ACTION_REACTIVATE = 'reactivate';

	/**
	 * Record an operator action.
	 *
	 * @param int    $account_id Target workspace.
	 * @param string $action     One of the ACTION_* constants.
	 * @param array  $before     ['plan' => string, 'status' => string].
	 * @param array  $after      ['plan' => string, 'status' => string].
	 * @param string $reason     Optional free-text reason.
	 */
	public static function record( int $account_id, string $action, array $before, array $after, string $reason = '' ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::operator_log_table(),
			array(
				'actor_id'   => get_current_user_id(),
				'account_id' => $account_id,
				'action'     => substr( $action, 0, 40 ),
				'old_plan'   => substr( (string) ( $before['plan'] ?? '' ), 0, 40 ),
				'old_status' => substr( (string) ( $before['status'] ?? '' ), 0, 20 ),
				'new_plan'   => substr( (string) ( $after['plan'] ?? '' ), 0, 40 ),
				'new_status' => substr( (string) ( $after['status'] ?? '' ), 0, 20 ),
				'reason'     => substr( $reason, 0, 255 ),
				'ip'         => self::client_ip(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Most recent operator actions (newest first).
	 *
	 * @param int $limit Max rows.
	 *
	 * @return object[]
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::operator_log_table() . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Best-effort client IP for the current request.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}
}
