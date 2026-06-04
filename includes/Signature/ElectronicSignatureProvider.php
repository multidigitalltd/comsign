<?php
/**
 * Electronic (DocuSign-style) signature provider.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

use ComSign\Pdf\PdfSigner;
use ComSign\Support\Storage;
use ComSign\Database\AuditRepository;

/**
 * Finalises a document by stamping the captured signature images/text onto the
 * source PDF and appending a signature-certificate page built from the audit
 * trail. This is the baseline provider; a PKI/PAdES provider can be added later
 * behind the same {@see SignatureProviderInterface}.
 */
final class ElectronicSignatureProvider implements SignatureProviderInterface {

	private PdfSigner $pdf_signer;
	private AuditRepository $audit;

	public function __construct( ?PdfSigner $pdf_signer = null, ?AuditRepository $audit = null ) {
		$this->pdf_signer = $pdf_signer ?? new PdfSigner();
		$this->audit      = $audit ?? new AuditRepository();
	}

	public function id(): string {
		return 'electronic';
	}

	public function label(): string {
		return __( 'Electronic signature', 'comsign' );
	}

	/**
	 * @inheritDoc
	 */
	public function finalize( object $document, array $fields, array $signers ): string {
		$output = Storage::document_path( (int) $document->id, 'signed' );

		$this->pdf_signer->render(
			$document->source_path,
			$output,
			$fields,
			$this->build_certificate( $document, $signers )
		);

		return $output;
	}

	/**
	 * Build the certificate page content from signer records + audit trail.
	 *
	 * @param object $document Document row.
	 * @param array  $signers  Signer rows.
	 *
	 * @return array{title:string,lines:array}
	 */
	private function build_certificate( object $document, array $signers ): array {
		$lines = array(
			__( 'Document', 'comsign' )       => (string) $document->title,
			__( 'Document ID', 'comsign' )    => (string) $document->id,
		);

		foreach ( $signers as $index => $signer ) {
			$n = $index + 1;
			/* translators: %d: signer number. */
			$prefix = sprintf( __( 'Signer %d', 'comsign' ), $n );

			$lines[ $prefix . ' — ' . __( 'Name', 'comsign' ) ]   = (string) $signer->name;
			$lines[ $prefix . ' — ' . __( 'Email', 'comsign' ) ]  = (string) $signer->email;
			$lines[ $prefix . ' — ' . __( 'Signed at', 'comsign' ) ] = (string) ( $signer->signed_at ?? '' );

			$sign_event = $this->find_signed_event( (int) $signer->id );
			if ( $sign_event ) {
				$lines[ $prefix . ' — ' . __( 'IP address', 'comsign' ) ] = (string) $sign_event->ip;
				$lines[ $prefix . ' — ' . __( 'Browser', 'comsign' ) ]    = (string) $sign_event->user_agent;
			}
		}

		return array(
			'title' => __( 'Signature Certificate', 'comsign' ),
			'lines' => $lines,
		);
	}

	/**
	 * Locate the "signed" audit event for a signer, if any.
	 */
	private function find_signed_event( int $signer_id ): ?object {
		global $wpdb;
		$table = \ComSign\Setup\Installer::audit_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE signer_id = %d AND event = %s ORDER BY id DESC LIMIT 1',
				$signer_id,
				\ComSign\Audit\AuditLogger::EVENT_SIGNED
			)
		);

		return $row ?: null;
	}
}
