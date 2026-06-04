<?php
/**
 * Signature provider contract.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Abstraction over "how a document is finalised once everyone has signed".
 *
 * The current phase ships {@see ElectronicSignatureProvider} — a DocuSign-style
 * electronic signature (image stamp + audit trail). A future phase can add a
 * PKI / PAdES provider (cryptographic certificate, ComSign / eIDAS) by
 * implementing this same interface, with no changes to the calling code.
 */
interface SignatureProviderInterface {

	/**
	 * Stable identifier, e.g. "electronic" or "pades".
	 */
	public function id(): string;

	/**
	 * Human-readable label (translated).
	 */
	public function label(): string;

	/**
	 * Produce the finalised, signed PDF.
	 *
	 * @param object $document Document row.
	 * @param array  $fields   Field rows with captured values.
	 * @param array  $signers  Signer rows (for the certificate/footer).
	 *
	 * @return string Absolute path to the generated signed PDF.
	 *
	 * @throws \RuntimeException If the document cannot be produced.
	 */
	public function finalize( object $document, array $fields, array $signers ): string;
}
