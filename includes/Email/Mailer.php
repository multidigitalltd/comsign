<?php
/**
 * Outgoing email for signing invitations.
 *
 * @package ComSign
 */

namespace ComSign\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the signing-invitation email to a signer.
 */
final class Mailer {

	/**
	 * Send the "please sign" invitation.
	 *
	 * @param object $document    Document row.
	 * @param object $signer      Signer row.
	 * @param string $sign_url    Tokenised signing URL.
	 * @param bool   $is_reminder Whether this is a reminder rather than a first send.
	 *
	 * @return bool Whether the mail was accepted for delivery.
	 */
	public function send_invitation( object $document, object $signer, string $sign_url, bool $is_reminder = false ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = $is_reminder
			/* translators: %s: site name. */
			? sprintf( __( 'Reminder: a document is awaiting your signature — %s', 'comsign' ), $site )
			/* translators: %s: site name. */
			: sprintf( __( 'A document is awaiting your signature — %s', 'comsign' ), $site );

		$greeting = $signer->name
			/* translators: %s: signer name. */
			? sprintf( __( 'Hello %s,', 'comsign' ), $signer->name )
			: __( 'Hello,', 'comsign' );

		$lines = array(
			$greeting,
			'',
			/* translators: %s: document title. */
			sprintf( __( 'You have been asked to review and sign the document: "%s".', 'comsign' ), $document->title ),
		);

		// Optional custom message from the sender.
		if ( ! empty( $document->message ) ) {
			$lines[] = '';
			$lines[] = (string) $document->message;
		}

		$lines = array_merge(
			$lines,
			array(
				'',
				__( 'Please open the secure link below to view and sign it:', 'comsign' ),
				$sign_url,
				'',
				__( 'This link is personal — please do not forward it.', 'comsign' ),
				'',
				/* translators: %s: site name. */
				sprintf( __( 'Sent by %s', 'comsign' ), $site ),
			)
		);

		$body = implode( "\r\n", $lines );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return wp_mail( $signer->email, $subject, $body, $headers );
	}
}
