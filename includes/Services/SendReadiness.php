<?php
/**
 * "Ready to send" checklist for a document.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Support\Storage;

/**
 * Computes a human-readable checklist of what a document still needs before it
 * can be sent for signing. Shared by the admin edit screen and the portal so
 * both speak the same language to a non-technical user.
 */
final class SendReadiness {

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private FieldRepository $fields;

	public function __construct(
		?DocumentRepository $documents = null,
		?SignerRepository $signers = null,
		?FieldRepository $fields = null
	) {
		$this->documents = $documents ?? new DocumentRepository();
		$this->signers   = $signers ?? new SignerRepository();
		$this->fields    = $fields ?? new FieldRepository();
	}

	/**
	 * Build the checklist for a document.
	 *
	 * @param int $document_id Document id.
	 *
	 * @return array{ready:bool,items:array<int,array{key:string,label:string,ok:bool,hint:string}>}
	 */
	public function check( int $document_id ): array {
		$document = $this->documents->find( $document_id );
		$signers  = $document ? $this->signers->for_document( $document_id ) : array();
		$fields   = $document ? $this->fields->for_document( $document_id ) : array();

		$items = array();

		// 1) Source file present.
		$has_source = $document && $document->source_path && Storage::is_within_base( $document->source_path ) && is_file( $document->source_path );
		$items[] = array(
			'key'   => 'source',
			'label' => __( 'Document file is uploaded', 'comsign' ),
			'ok'    => (bool) $has_source,
			'hint'  => __( 'Upload a PDF (or compose one) for this document.', 'comsign' ),
		);

		// 2) At least one signer.
		$items[] = array(
			'key'   => 'signers',
			'label' => __( 'At least one signer added', 'comsign' ),
			'ok'    => ! empty( $signers ),
			'hint'  => __( 'Add the people who need to sign.', 'comsign' ),
		);

		// 3) Every signer has at least one field, and at least one is a signature.
		$fields_by_signer = array();
		$sig_by_signer    = array();
		foreach ( $fields as $f ) {
			$sid = (int) $f->signer_id;
			$fields_by_signer[ $sid ] = ( $fields_by_signer[ $sid ] ?? 0 ) + 1;
			if ( in_array( $f->type, array( FieldRepository::TYPE_SIGNATURE, FieldRepository::TYPE_INITIALS ), true ) ) {
				$sig_by_signer[ $sid ] = true;
			}
		}

		$missing_fields = array();
		$missing_sig    = array();
		foreach ( $signers as $signer ) {
			$sid   = (int) $signer->id;
			$who   = $signer->name ? $signer->name : $signer->email;
			if ( empty( $fields_by_signer[ $sid ] ) ) {
				$missing_fields[] = $who;
			} elseif ( empty( $sig_by_signer[ $sid ] ) ) {
				$missing_sig[] = $who;
			}
		}

		$items[] = array(
			'key'   => 'fields',
			'label' => __( 'Every signer has a field to fill', 'comsign' ),
			'ok'    => ! empty( $signers ) && empty( $missing_fields ),
			'hint'  => $missing_fields
				/* translators: %s: comma-separated signer names. */
				? sprintf( __( 'These signers have no fields yet: %s. Open "Place signature fields".', 'comsign' ), implode( ', ', $missing_fields ) )
				: __( 'Place at least one field for each signer.', 'comsign' ),
		);

		$items[] = array(
			'key'   => 'signature',
			'label' => __( 'Every signer has a signature field', 'comsign' ),
			'ok'    => ! empty( $signers ) && empty( $missing_fields ) && empty( $missing_sig ),
			'hint'  => $missing_sig
				/* translators: %s: comma-separated signer names. */
				? sprintf( __( 'Add a signature (or initials) field for: %s.', 'comsign' ), implode( ', ', $missing_sig ) )
				: __( 'Each signer should have somewhere to sign.', 'comsign' ),
		);

		$ready = true;
		foreach ( $items as $item ) {
			if ( ! $item['ok'] ) {
				$ready = false;
				break;
			}
		}

		return array(
			'ready' => $ready,
			'items' => $items,
		);
	}
}
