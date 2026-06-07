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
	public const EVENT_SEND_FAILED = 'send_failed';
	public const EVENT_REMINDED    = 'reminded';
	public const EVENT_EXPIRED     = 'expired';
	public const EVENT_EXPIRY_EXTENDED = 'expiry_extended';
	public const EVENT_DELEGATED   = 'delegated';

	private AuditRepository $repository;

	/**
	 * Human-readable label for an event, for the document timeline.
	 *
	 * @param string $event Event key.
	 */
	public static function label( string $event ): string {
		$labels = array(
			self::EVENT_CREATED         => __( 'Created', 'comsign' ),
			self::EVENT_SENT            => __( 'Sent for signing', 'comsign' ),
			self::EVENT_VIEWED          => __( 'Viewed', 'comsign' ),
			self::EVENT_CONSENTED       => __( 'Consented', 'comsign' ),
			self::EVENT_SIGNED          => __( 'Signed', 'comsign' ),
			self::EVENT_COMPLETED       => __( 'Completed', 'comsign' ),
			self::EVENT_DECLINED        => __( 'Declined', 'comsign' ),
			self::EVENT_DOWNLOADED      => __( 'Downloaded', 'comsign' ),
			self::EVENT_SEND_FAILED     => __( 'Send failed', 'comsign' ),
			self::EVENT_REMINDED        => __( 'Reminder sent', 'comsign' ),
			self::EVENT_EXPIRED         => __( 'Expired', 'comsign' ),
			self::EVENT_EXPIRY_EXTENDED => __( 'Expiry extended', 'comsign' ),
			self::EVENT_DELEGATED       => __( 'Delegated', 'comsign' ),
		);
		return $labels[ $event ] ?? ucfirst( str_replace( '_', ' ', $event ) );
	}

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

		/**
		 * Fires for every recorded audit event. Webhook delivery and other
		 * integrations subscribe to this — the webhook stream mirrors the audit
		 * trail exactly.
		 *
		 * @param string $event       Event slug.
		 * @param int    $document_id Document id.
		 * @param int    $signer_id   Signer id (0 if none).
		 * @param array  $meta        Extra structured context.
		 */
		do_action( 'comsign_event', $event, $document_id, $signer_id, $meta );
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
