<?php
/**
 * Public, token-secured signing flow.
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

use ComSign\Audit\AuditLogger;
use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;
use ComSign\Support\Storage;
use ComSign\Support\Tokens;

/**
 * Handles the entire signer-facing experience: viewing the document, capturing
 * the signature and recording the decision — all gated by a single-use,
 * hashed, per-signer token (never a WordPress login).
 */
final class SigningController {

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private FieldRepository $fields;
	private DocumentService $service;
	private AuditLogger $audit;

	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->signers   = new SignerRepository();
		$this->fields    = new FieldRepository();
		$this->service   = new DocumentService();
		$this->audit     = new AuditLogger();
	}

	/**
	 * Register the public hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render_signing_page' ) );

		// Public authenticity check: place [comsign_verify] on any page.
		add_shortcode( 'comsign_verify', array( $this, 'render_verify_shortcode' ) );

		add_action( 'admin_post_nopriv_comsign_sign_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_comsign_sign_submit', array( $this, 'handle_submit' ) );

		add_action( 'admin_post_nopriv_comsign_sign_decline', array( $this, 'handle_decline' ) );
		add_action( 'admin_post_comsign_sign_decline', array( $this, 'handle_decline' ) );

		add_action( 'admin_post_nopriv_comsign_sign_view', array( $this, 'handle_view_document' ) );
		add_action( 'admin_post_comsign_sign_view', array( $this, 'handle_view_document' ) );
	}

	/* ---------------------------------------------------------------------
	 * Page rendering
	 * ------------------------------------------------------------------- */

	/**
	 * If the request carries a signing token, render the signing page.
	 */
	public function maybe_render_signing_page(): void {
		if ( empty( $_GET['comsign_sign'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$raw_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			$this->render_message(
				__( 'Invalid or expired link', 'comsign' ),
				__( 'This signing link is no longer valid. Please ask the sender for a new one.', 'comsign' )
			);
		}

		$document = $this->documents->find( (int) $signer->document_id );
		if ( ! $document ) {
			$this->render_message(
				__( 'Document unavailable', 'comsign' ),
				__( 'The requested document could not be found.', 'comsign' )
			);
		}

		// Already signed / declined → show a closing message.
		if ( SignerRepository::STATUS_SIGNED === $signer->status ) {
			$this->render_message(
				__( 'Already signed', 'comsign' ),
				__( 'You have already signed this document. Thank you.', 'comsign' )
			);
		}
		if ( SignerRepository::STATUS_DECLINED === $signer->status ) {
			$this->render_message(
				__( 'Signing declined', 'comsign' ),
				__( 'You have declined to sign this document.', 'comsign' )
			);
		}

		// First view → mark viewed + audit.
		if ( SignerRepository::STATUS_VIEWED !== $signer->status ) {
			$this->signers->update(
				(int) $signer->id,
				array(
					'status'    => SignerRepository::STATUS_VIEWED,
					'viewed_at' => current_time( 'mysql', true ),
				)
			);
			if ( DocumentRepository::STATUS_SENT === $document->status ) {
				$this->documents->set_status( (int) $document->id, DocumentRepository::STATUS_VIEWED );
			}
			$this->audit->record( AuditLogger::EVENT_VIEWED, (int) $document->id, (int) $signer->id );
		}

		$this->render_signing_page( $document, $signer, $raw_token );
	}

	/**
	 * Output the full standalone signing page and exit.
	 *
	 * @param object $document  Document row.
	 * @param object $signer    Signer row.
	 * @param string $raw_token Raw token (kept only in this request).
	 */
	private function render_signing_page( object $document, object $signer, string $raw_token ): void {
		$nonce     = wp_create_nonce( 'comsign_sign_' . $signer->id );
		$view_url  = add_query_arg(
			array(
				'action' => 'comsign_sign_view',
				'token'  => rawurlencode( $raw_token ),
				'_n'     => wp_create_nonce( 'comsign_view_' . $signer->id ),
			),
			admin_url( 'admin-post.php' )
		);
		$post_url  = admin_url( 'admin-post.php' );

		$signer_fields = $this->fields->for_signer( (int) $signer->id );

		$needs_signature = false;
		$text_fields     = array();
		foreach ( $signer_fields as $field ) {
			if ( in_array( $field->type, array( 'signature', 'initials' ), true ) ) {
				$needs_signature = true;
			} elseif ( 'text' === $field->type ) {
				$text_fields[] = $field;
			}
		}

		$data = array(
			'document'        => $document,
			'signer'          => $signer,
			'raw_token'       => $raw_token,
			'nonce'           => $nonce,
			'view_url'        => $view_url,
			'post_url'        => $post_url,
			'needs_signature' => $needs_signature,
			'text_fields'     => $text_fields,
		);

		$this->render_template( 'sign', $data );
	}

	/**
	 * Render the public document-verification form and result.
	 *
	 * Usage: [comsign_verify]. Anyone holding a document's id + SHA-256 can
	 * confirm it was completed and see who signed and when. The hash is hard to
	 * guess, so this acts as a capability check without exposing the document.
	 *
	 * @return string HTML.
	 */
	public function render_verify_shortcode(): string {
		// Read-only public lookup — no nonce required, inputs are sanitised.
		$document_id = isset( $_GET['comsign_doc'] ) ? absint( wp_unslash( $_GET['comsign_doc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hash        = isset( $_GET['comsign_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['comsign_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();

		echo '<form method="get" class="comsign-verify-form">';
		echo '<p><label>' . esc_html__( 'Document ID', 'comsign' ) . '<br>';
		echo '<input type="number" name="comsign_doc" value="' . esc_attr( $document_id ? (string) $document_id : '' ) . '" min="1"></label></p>';
		echo '<p><label>' . esc_html__( 'Verification code (SHA-256)', 'comsign' ) . '<br>';
		echo '<input type="text" name="comsign_hash" value="' . esc_attr( $hash ) . '" size="64"></label></p>';
		echo '<p><button type="submit">' . esc_html__( 'Verify document', 'comsign' ) . '</button></p>';
		echo '</form>';

		if ( $document_id && '' !== $hash ) {
			$document = $this->service->verify( $document_id, $hash );

			if ( $document ) {
				echo '<div class="comsign-verify-result comsign-verify-ok">';
				echo '<p><strong>' . esc_html__( '✔ This document is authentic.', 'comsign' ) . '</strong></p>';
				echo '<p>' . esc_html__( 'Title:', 'comsign' ) . ' ' . esc_html( $document->title ) . '</p>';
				echo '<p>' . esc_html__( 'Completed:', 'comsign' ) . ' ' . esc_html( (string) $document->updated_at ) . '</p>';

				$signers = $this->signers->for_document( (int) $document->id );
				if ( $signers ) {
					echo '<p>' . esc_html__( 'Signed by:', 'comsign' ) . '</p><ul>';
					foreach ( $signers as $signer ) {
						echo '<li>' . esc_html( $signer->name ?: $signer->email );
						if ( ! empty( $signer->signed_at ) ) {
							echo ' — ' . esc_html( (string) $signer->signed_at );
						}
						echo '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			} else {
				echo '<div class="comsign-verify-result comsign-verify-fail"><p>'
					. esc_html__( 'No matching signed document was found for that ID and code.', 'comsign' )
					. '</p></div>';
			}
		}

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Submission handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Capture and store a signer's signature.
	 */
	public function handle_submit(): void {
		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			$this->render_message( __( 'Invalid link', 'comsign' ), __( 'This signing link is no longer valid.', 'comsign' ) );
		}

		check_admin_referer( 'comsign_sign_' . $signer->id );

		if ( SignerRepository::STATUS_SIGNED === $signer->status ) {
			$this->render_message( __( 'Already signed', 'comsign' ), __( 'You have already signed this document.', 'comsign' ) );
		}

		$consent = ! empty( $_POST['consent'] );
		if ( ! $consent ) {
			$this->render_message( __( 'Consent required', 'comsign' ), __( 'You must agree to sign electronically before continuing.', 'comsign' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded/validated below.
		$raw_signature = isset( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : '';
		$base64        = $this->extract_png_base64( (string) $raw_signature );

		// Collect signer-filled text fields: field id => value.
		$field_values = array();
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			foreach ( wp_unslash( $_POST['fields'] ) as $field_id => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$field_values[ (int) $field_id ] = sanitize_text_field( (string) $value );
			}
		}

		$document = $this->documents->find( (int) $signer->document_id );
		if ( ! $document ) {
			$this->render_message( __( 'Document unavailable', 'comsign' ), __( 'The document could not be found.', 'comsign' ) );
		}

		try {
			$this->service->record_signature( $document, $signer, $base64, $field_values );
		} catch ( \Throwable $e ) {
			$this->render_message( __( 'Could not complete signing', 'comsign' ), $e->getMessage() );
		}

		$this->render_message(
			__( 'Thank you!', 'comsign' ),
			__( 'Your signature has been recorded. A copy will be available once all parties have signed.', 'comsign' ),
			'success'
		);
	}

	/**
	 * Record a decline to sign.
	 */
	public function handle_decline(): void {
		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			$this->render_message( __( 'Invalid link', 'comsign' ), __( 'This signing link is no longer valid.', 'comsign' ) );
		}

		check_admin_referer( 'comsign_sign_' . $signer->id );

		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$document = $this->documents->find( (int) $signer->document_id );

		if ( $document ) {
			$this->service->decline( $document, $signer, $reason );
		}

		$this->render_message(
			__( 'Signing declined', 'comsign' ),
			__( 'You have declined to sign this document. The sender has been notified.', 'comsign' )
		);
	}

	/**
	 * Stream the source PDF to a token-bearing signer (for inline viewing).
	 */
	public function handle_view_document(): void {
		$raw_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			wp_die( esc_html__( 'Invalid link.', 'comsign' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'comsign_view_' . $signer->id, '_n' );

		$document = $this->documents->find( (int) $signer->document_id );
		if ( ! $document || ! $document->source_path || ! Storage::is_within_base( $document->source_path ) || ! is_file( $document->source_path ) ) {
			wp_die( esc_html__( 'Document unavailable.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="document.pdf"' );
		header( 'Content-Length: ' . (string) filesize( $document->source_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $document->source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve a signer from a raw token using its stored hash.
	 *
	 * @param string $raw_token Raw token.
	 */
	private function resolve_signer( string $raw_token ): ?object {
		if ( '' === $raw_token || ! ctype_xdigit( $raw_token ) ) {
			return null;
		}

		$signer = $this->signers->find_by_token_hash( Tokens::hash( $raw_token ) );
		if ( ! $signer || '' === $signer->token_hash ) {
			return null;
		}

		// Constant-time re-check (defence in depth against timing on lookup).
		return Tokens::verify( $raw_token, $signer->token_hash ) ? $signer : null;
	}

	/**
	 * Validate a data: URL and return just the base64 PNG payload.
	 *
	 * @param string $data_url Raw posted value.
	 *
	 * @return string Base64 PNG (no prefix), or '' if invalid.
	 */
	private function extract_png_base64( string $data_url ): string {
		if ( 0 !== strpos( $data_url, 'data:image/png;base64,' ) ) {
			return '';
		}

		$base64 = substr( $data_url, strlen( 'data:image/png;base64,' ) );
		$base64 = preg_replace( '/\s+/', '', $base64 );

		$binary = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || strlen( $binary ) < 8 ) {
			return '';
		}

		// PNG magic number.
		if ( "\x89PNG\r\n\x1a\n" !== substr( $binary, 0, 8 ) ) {
			return '';
		}

		// Cap at ~2 MB of binary to avoid abuse.
		if ( strlen( $binary ) > 2 * MB_IN_BYTES ) {
			return '';
		}

		return base64_encode( $binary ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Render a public template from includes/Frontend/views and exit.
	 *
	 * @param string $name Template name.
	 * @param array  $data Variables for the template.
	 */
	private function render_template( string $name, array $data = array() ): void {
		$file = COMSIGN_PLUGIN_DIR . 'includes/Frontend/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Template missing.', 'comsign' ) );
		}
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		require $file;
		exit;
	}

	/**
	 * Render a simple full-page message and exit.
	 *
	 * @param string $title   Heading.
	 * @param string $message Body text.
	 * @param string $type    'info' or 'success'.
	 */
	private function render_message( string $title, string $message, string $type = 'info' ): void {
		$this->render_template(
			'message',
			array(
				'title'   => $title,
				'message' => $message,
				'type'    => $type,
			)
		);
	}
}
