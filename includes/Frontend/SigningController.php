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

		// Dedicated public verification page at /comsign/verify (also reachable
		// via ?comsign_verify=1, which works without a rewrite flush).
		add_action( 'init', array( __CLASS__, 'register_verify_route' ) );
		add_filter( 'query_vars', array( $this, 'register_verify_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_verify_page' ) );

		// Public authenticity check: place [comsign_verify] on any page.
		add_shortcode( 'comsign_verify', array( $this, 'render_verify_shortcode' ) );

		add_action( 'admin_post_nopriv_comsign_sign_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_comsign_sign_submit', array( $this, 'handle_submit' ) );

		add_action( 'admin_post_nopriv_comsign_sign_decline', array( $this, 'handle_decline' ) );
		add_action( 'admin_post_comsign_sign_decline', array( $this, 'handle_decline' ) );

		add_action( 'admin_post_nopriv_comsign_sign_delegate', array( $this, 'handle_delegate' ) );
		add_action( 'admin_post_comsign_sign_delegate', array( $this, 'handle_delegate' ) );

		add_action( 'admin_post_nopriv_comsign_sign_view', array( $this, 'handle_view_document' ) );
		add_action( 'admin_post_comsign_sign_view', array( $this, 'handle_view_document' ) );

		add_action( 'admin_post_nopriv_comsign_sign_auth', array( $this, 'handle_auth' ) );
		add_action( 'admin_post_comsign_sign_auth', array( $this, 'handle_auth' ) );
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

		// Expired signing window.
		if ( $this->service->is_expired( $document ) ) {
			$this->render_message(
				__( 'Link expired', 'comsign' ),
				__( 'This signing link has expired. Please ask the sender for a new one.', 'comsign' )
			);
		}

		// Sequential signing: not this signer's turn yet.
		if ( ! empty( $document->sequential ) && $this->signers->has_earlier_unsigned( $signer ) ) {
			$this->render_message(
				__( 'Waiting for an earlier signer', 'comsign' ),
				__( 'This document is signed in order. You will be notified when it is your turn.', 'comsign' )
			);
		}

		// Identity challenge (access code / email OTP) before viewing.
		if ( SignerAuth::requires( $signer ) && ! SignerAuth::verified( $signer ) ) {
			$this->render_auth_challenge( $signer, $raw_token );
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

		$signer_fields = $this->fields->for_signer_in_document( (int) $document->id, (int) $signer->id );

		$needs_signature = false;
		$input_fields    = array();
		foreach ( $signer_fields as $field ) {
			if ( in_array( $field->type, array( FieldRepository::TYPE_SIGNATURE, FieldRepository::TYPE_INITIALS ), true ) ) {
				$needs_signature = true;
			} elseif ( in_array( $field->type, FieldRepository::INPUT_TYPES, true ) ) {
				// Fields the signer fills in themselves (auto fields are excluded).
				$input_fields[] = $field;
			}
		}

		$data = array(
			'document'        => $document,
			'signer'          => $signer,
			'raw_token'       => $raw_token,
			'nonce'           => $nonce,
			'delegate_nonce'  => wp_create_nonce( 'comsign_delegate_' . $signer->id ),
			'can_delegate'    => ! empty( $document->allow_delegation ),
			'view_url'        => $view_url,
			'post_url'        => $post_url,
			'needs_signature' => $needs_signature,
			'input_fields'    => $input_fields,
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
		return $this->verify_markup();
	}

	/**
	 * Register the pretty /comsign/verify rewrite rule. Static so the activator
	 * can call it before flushing rewrite rules.
	 */
	public static function register_verify_route(): void {
		add_rewrite_rule( '^comsign/verify/?$', 'index.php?comsign_verify=1', 'top' );
	}

	/**
	 * Allow the comsign_verify query var.
	 *
	 * @param string[] $vars Registered query vars.
	 *
	 * @return string[]
	 */
	public function register_verify_query_var( array $vars ): array {
		$vars[] = 'comsign_verify';
		return $vars;
	}

	/**
	 * Build a verification URL for a document (used by links and the QR code).
	 *
	 * Uses the query-var form so it resolves even if rewrite rules were never
	 * flushed. The verifier still supplies the secret code separately.
	 *
	 * @param int $document_id Document id.
	 */
	public static function verify_url( int $document_id ): string {
		return add_query_arg(
			array(
				'comsign_verify' => '1',
				'comsign_doc'    => $document_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Render the standalone verification page when /comsign/verify is requested.
	 */
	public function maybe_render_verify_page(): void {
		if ( empty( $_GET['comsign_verify'] ) && ! get_query_var( 'comsign_verify' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$this->render_template( 'verify', array( 'content' => $this->verify_markup() ) );
	}

	/**
	 * The verification form + result markup, shared by the shortcode and the
	 * dedicated /comsign/verify page.
	 */
	private function verify_markup(): string {
		// Read-only public lookup — no nonce required, inputs are sanitised.
		$document_id = isset( $_GET['comsign_doc'] ) ? absint( wp_unslash( $_GET['comsign_doc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hash        = isset( $_GET['comsign_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['comsign_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();

		echo '<form method="get" class="comsign-verify-form">';
		echo '<input type="hidden" name="comsign_verify" value="1">';
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
				echo '<p><strong>' . esc_html__( 'This document is authentic.', 'comsign' ) . '</strong></p>';
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

	/**
	 * Render the identity-challenge page and exit. For email OTP, a code is sent
	 * automatically the first time (no code is sent on a simple page refresh).
	 *
	 * @param object $signer    Signer row.
	 * @param string $raw_token Raw token.
	 * @param string $error     Optional error message to show.
	 */
	private function render_auth_challenge( object $signer, string $raw_token, string $error = '' ): void {
		$method = (string) $signer->auth_method;

		if ( SignerAuth::METHOD_OTP === $method && ! get_transient( 'comsign_otp_' . (int) $signer->id ) ) {
			SignerAuth::send_otp( $signer );
		}

		$this->render_template(
			'auth',
			array(
				'signer'    => $signer,
				'method'    => $method,
				'raw_token' => $raw_token,
				'post_url'  => admin_url( 'admin-post.php' ),
				'nonce'     => wp_create_nonce( 'comsign_auth_' . $signer->id ),
				'error'     => $error,
				'email'     => SignerAuth::METHOD_OTP === $method ? $this->mask_email( (string) $signer->email ) : '',
			)
		);
	}

	/**
	 * Verify a submitted access code / OTP and, on success, mark the session
	 * verified and return the signer to the signing page.
	 */
	public function handle_auth(): void {
		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			$this->render_message( __( 'Invalid link', 'comsign' ), __( 'This signing link is no longer valid.', 'comsign' ) );
		}

		check_admin_referer( 'comsign_auth_' . $signer->id );

		// Stop credential brute-forcing: lock the challenge after too many misses.
		if ( SignerAuth::is_locked_out( $signer ) ) {
			$this->audit->record( AuditLogger::EVENT_VIEWED, (int) $signer->document_id, (int) $signer->id, array( 'auth' => 'locked' ) );
			$this->render_message(
				__( 'Too many attempts', 'comsign' ),
				__( 'Too many incorrect attempts. Please wait a few minutes and try again.', 'comsign' )
			);
		}

		// Resend OTP on request.
		if ( ! empty( $_POST['resend'] ) && SignerAuth::METHOD_OTP === (string) $signer->auth_method ) {
			delete_transient( 'comsign_otp_' . (int) $signer->id );
			$this->render_auth_challenge( $signer, $raw_token, __( 'A new code has been sent.', 'comsign' ) );
		}

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$ok = SignerAuth::METHOD_OTP === (string) $signer->auth_method
			? SignerAuth::verify_otp( $signer, $code )
			: SignerAuth::verify_code( $signer, $code );

		if ( ! $ok ) {
			SignerAuth::register_failed_attempt( $signer );
			$this->audit->record( AuditLogger::EVENT_VIEWED, (int) $signer->document_id, (int) $signer->id, array( 'auth' => 'failed' ) );
			$this->render_auth_challenge( $signer, $raw_token, __( 'That code was not correct. Please try again.', 'comsign' ) );
		}

		SignerAuth::clear_attempts( $signer );
		SignerAuth::mark_verified( $signer );
		$this->audit->record( AuditLogger::EVENT_VIEWED, (int) $signer->document_id, (int) $signer->id, array( 'auth' => 'passed' ) );

		wp_safe_redirect( $this->service->signing_url( $raw_token ) );
		exit;
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

		if ( SignerAuth::requires( $signer ) && ! SignerAuth::verified( $signer ) ) {
			$this->render_message( __( 'Verification required', 'comsign' ), __( 'Please verify your identity before signing.', 'comsign' ) );
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

		if ( $this->service->is_expired( $document ) ) {
			$this->render_message( __( 'Link expired', 'comsign' ), __( 'This signing link has expired.', 'comsign' ) );
		}

		if ( ! empty( $document->sequential ) && $this->signers->has_earlier_unsigned( $signer ) ) {
			$this->render_message( __( 'Waiting for an earlier signer', 'comsign' ), __( 'It is not your turn to sign yet.', 'comsign' ) );
		}

		$uploads = $this->collect_uploads( $document, $signer );

		try {
			$this->service->record_signature( $document, $signer, $base64, $field_values, $uploads );
		} catch ( \Throwable $e ) {
			$this->render_message( __( 'Could not complete signing', 'comsign' ), $e->getMessage() );
		}

		// Tailor the confirmation to whether the whole document is now complete.
		$fresh = $this->documents->find( (int) $document->id );
		if ( $fresh && DocumentRepository::STATUS_COMPLETED === $fresh->status ) {
			$this->render_message(
				__( 'Signed successfully', 'comsign' ),
				__( 'The document is now fully signed by all parties. A signed copy will be sent to the relevant recipients.', 'comsign' ),
				'success'
			);
		}

		$this->render_message(
			__( 'Signed successfully', 'comsign' ),
			__( 'Thank you — your signature has been recorded. The document will be completed once the remaining participants have signed.', 'comsign' ),
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
	 * Reassign this signing slot to someone else (delegation).
	 */
	public function handle_delegate(): void {
		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$signer    = $this->resolve_signer( $raw_token );

		if ( ! $signer ) {
			$this->render_message( __( 'Invalid link', 'comsign' ), __( 'This signing link is no longer valid.', 'comsign' ) );
		}

		check_admin_referer( 'comsign_delegate_' . $signer->id );

		// Delegation transfers control of the signing slot, so it must pass the
		// same identity gate as viewing/signing — never reassign on a token alone.
		if ( SignerAuth::requires( $signer ) && ! SignerAuth::verified( $signer ) ) {
			$this->render_message( __( 'Verification required', 'comsign' ), __( 'Please verify your identity before assigning to someone else.', 'comsign' ) );
		}

		if ( SignerRepository::STATUS_SIGNED === $signer->status ) {
			$this->render_message( __( 'Already signed', 'comsign' ), __( 'You have already signed this document.', 'comsign' ) );
		}

		$document = $this->documents->find( (int) $signer->document_id );
		if ( ! $document ) {
			$this->render_message( __( 'Document unavailable', 'comsign' ), __( 'The document could not be found.', 'comsign' ) );
		}

		$name  = isset( $_POST['delegate_name'] ) ? sanitize_text_field( wp_unslash( $_POST['delegate_name'] ) ) : '';
		$email = isset( $_POST['delegate_email'] ) ? sanitize_email( wp_unslash( $_POST['delegate_email'] ) ) : '';
		$phone = isset( $_POST['delegate_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['delegate_phone'] ) ) : '';

		try {
			$this->service->delegate_signer( $document, $signer, $name, $email, $phone );
		} catch ( \Throwable $e ) {
			$this->render_message( __( 'Could not reassign', 'comsign' ), $e->getMessage() );
		}

		$this->render_message(
			__( 'Assigned to someone else', 'comsign' ),
			__( 'Thank you. We have emailed the document to the person you chose, and your access to this link has ended.', 'comsign' ),
			'success'
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

		if ( SignerAuth::requires( $signer ) && ! SignerAuth::verified( $signer ) ) {
			wp_die( esc_html__( 'Verification required.', 'comsign' ), '', array( 'response' => 403 ) );
		}

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
	 * Validate and store any uploaded attachment files for this signer.
	 *
	 * Only fields that are this signer's attachment fields are accepted, so a
	 * tampered field id cannot write a file against someone else's field.
	 *
	 * @param object $document Document row.
	 * @param object $signer   Signer row.
	 *
	 * @return array<int,array{path:string,name:string}>
	 */
	private function collect_uploads( object $document, object $signer ): array {
		if ( empty( $_FILES['attachments']['name'] ) || ! is_array( $_FILES['attachments']['name'] ) ) {
			return array();
		}

		// Build the allowlist of this signer's attachment field ids.
		$allowed = array();
		foreach ( $this->fields->for_signer_in_document( (int) $document->id, (int) $signer->id ) as $field ) {
			if ( FieldRepository::TYPE_ATTACHMENT === $field->type ) {
				$allowed[ (int) $field->id ] = true;
			}
		}

		$uploads = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array_keys( $_FILES['attachments']['name'] ) as $field_id ) {
			$field_id = (int) $field_id;
			if ( ! isset( $allowed[ $field_id ] ) ) {
				continue;
			}
			$entry = array(
				'name'     => $_FILES['attachments']['name'][ $field_id ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'tmp_name' => $_FILES['attachments']['tmp_name'][ $field_id ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'error'    => $_FILES['attachments']['error'][ $field_id ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'size'     => $_FILES['attachments']['size'][ $field_id ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);
			$stored = Storage::store_attachment( $entry, (int) $document->id, $field_id );
			if ( $stored ) {
				$uploads[ $field_id ] = $stored;
			}
		}

		return $uploads;
	}

	/**
	 * Partially mask an email for display on the OTP challenge.
	 *
	 * @param string $email Email address.
	 */
	private function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '';
		}
		$name   = $parts[0];
		$masked = mb_substr( $name, 0, 1 ) . str_repeat( '*', max( 1, mb_strlen( $name ) - 1 ) );
		return $masked . '@' . $parts[1];
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
