<?php
/**
 * PKI / PAdES signature provider.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

use ComSign\Pdf\PdfSigner;
use ComSign\Support\Storage;

/**
 * Finalises a document like the electronic provider, then applies a real
 * cryptographic (PKCS#7 / PAdES) signature using the configured certificate.
 * This is what raises the document from "electronic signature" to a
 * certificate-backed digital signature (eIDAS / ComSign compatible).
 */
final class PadesSignatureProvider implements SignatureProviderInterface {

	private PdfSigner $pdf_signer;

	public function __construct( ?PdfSigner $pdf_signer = null ) {
		$this->pdf_signer = $pdf_signer ?? new PdfSigner();
	}

	public function id(): string {
		return 'pades';
	}

	public function label(): string {
		return __( 'Digital signature (PKI/PAdES)', 'comsign' );
	}

	/**
	 * @inheritDoc
	 */
	public function finalize( object $document, array $fields, array $signers ): string {
		$output = Storage::document_path( (int) $document->id, 'signed' );

		$pem = Certificate::read();
		if ( null === $pem ) {
			// No usable certificate — fall back to a plain electronic signature.
			$this->pdf_signer->render( $document->source_path, $output, $fields, CertificatePage::build( $document, $signers ) );
			return $output;
		}

		$crypto = array(
			'cert' => $pem['cert'],
			'pkey' => $pem['pkey'],
			'pass' => '',
			'tsa'  => Certificate::tsa_url(),
			'info' => array(
				'Name'        => get_bloginfo( 'name' ),
				'Location'    => home_url(),
				'Reason'      => __( 'Document signed via ComSign', 'comsign' ),
				'ContactInfo' => get_bloginfo( 'admin_email' ),
			),
		);

		$this->pdf_signer->render(
			$document->source_path,
			$output,
			$fields,
			CertificatePage::build( $document, $signers ),
			$crypto
		);

		return $output;
	}
}
