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

/**
 * Finalises a document by stamping the captured signature images/text onto the
 * source PDF and appending a signature-certificate page built from the audit
 * trail. This is the baseline provider; {@see PadesSignatureProvider} adds a
 * cryptographic signature on top behind the same interface.
 */
final class ElectronicSignatureProvider implements SignatureProviderInterface {

	private PdfSigner $pdf_signer;

	public function __construct( ?PdfSigner $pdf_signer = null ) {
		$this->pdf_signer = $pdf_signer ?? new PdfSigner();
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
			CertificatePage::build( $document, $signers )
		);

		return $output;
	}
}
