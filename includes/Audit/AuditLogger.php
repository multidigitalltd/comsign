<?php
/**
 * High-level audit logging with request context capture.
 *
 * @package ComSign
 */

namespace ComSign\Audit;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AuditRepository;

/**
 * Records audit events, automatically attaching the requester's IP and
 * User-Agent. This is the audit-trail backbone of the signer authentication
 * model in the current (electronic-signature) phase.
 */
final class AuditLogger {

	public const EVENT_CREATED   = 'created';
	public const EVENT_SENT      = 'sent';
	public const EVENT_VIEWED    = 'viewed';
	public const EVENT_CONSENTED = 'consented';
	public const EVENT_SIGNED    = 'signed';
	public const EVENT_COMPLETED = 'completed';
	public const EVENT_DECLINED  = 'declined';
	public const EVENT_DOWNLOADED = 'downloaded';

	private AuditRepository $repository;

	public function __construct( ?AuditRepository $repository = null ) {
		$this->repository = $repository ?? new AuditRepository();
	}

	/**
	 * Record an event, capturing IP + User-Agent from the current request.
	 *
	 * @param string $event       One of the EVENT_* constants.
	 * @param int    $document_id Related document id.
	 * @param int    $signer_id   Related signer id (0 if none).
	 * @param array  $meta        Extra structured context (stored as JSON).
	 */
	public function record( string $event, int $document_id, int $signer_id = 0, array $meta = array() ): void {
		$this->repository->log(
			array(
				'document_id' => $document_id,
				'signer_id'   => $signer_id,
				'event'       => $event,
				'ip'          => self::client_ip(),
				'user_agent'  => self::user_agent(),
				'meta'        => $meta ? $meta : null,
			)
		);
	}

	/**
	 * Best-effort client IP, validated and capped to a safe length.
	 *
	 * Only REMOTE_ADDR is trusted by default; proxy headers are spoofable and
	 * are deliberately not honoured here.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		return substr( (string) $ip, 0, 45 );
	}

	/**
	 * Sanitised, length-capped User-Agent string.
	 */
	public static function user_agent(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return substr( sanitize_text_field( $ua ), 0, 255 );
	}
}
