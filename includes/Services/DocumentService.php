<?php
/**
 * Document workflow orchestration.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

use ComSign\Audit\AuditLogger;
use ComSign\Database\AuditRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Email\Mailer;
use ComSign\Signature\ElectronicSignatureProvider;
use ComSign\Signature\SignatureProviderInterface;
use ComSign\Support\Storage;
use ComSign\Support\Tokens;

/**
 * Coordinates the end-to-end document lifecycle: upload, signers, fields,
 * sending invitations, capturing signatures and finalising the signed PDF.
 */
final class DocumentService {

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private FieldRepository $fields;
	private AuditRepository $audit_repo;
	private \ComSign\Database\TemplateRepository $templates;
	private AuditLogger $audit;
	private Mailer $mailer;
	private SignatureProviderInterface $provider;

	public function __construct() {
		$this->documents  = new DocumentRepository();
		$this->signers    = new SignerRepository();
		$this->fields     = new FieldRepository();
		$this->audit_repo = new AuditRepository();
		$this->templates  = new \ComSign\Database\TemplateRepository();
		$this->audit      = new AuditLogger( $this->audit_repo );
		$this->mailer     = new Mailer();
		$this->provider   = new ElectronicSignatureProvider();
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------- */

	/**
	 * Create a document from an uploaded PDF.
	 *
	 * @param array  $file  A single entry from $_FILES.
	 * @param string $title Document title.
	 *
	 * @return int New document id.
	 *
	 * @throws \RuntimeException If the upload is invalid.
	 */
	public function create_from_upload( array $file, string $title ): int {
		$this->validate_pdf_upload( $file );

		$title = $title !== '' ? $title : sanitize_file_name( $file['name'] );

		$document_id = $this->documents->create(
			array(
				'title'      => $title,
				'created_by' => get_current_user_id(),
			)
		);

		$destination = Storage::document_path( $document_id, 'source' );

		if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
			$this->documents->delete( $document_id );
			throw new \RuntimeException( __( 'Could not store the uploaded file.', 'comsign' ) );
		}

		$this->documents->update( $document_id, array( 'source_path' => $destination ) );

		$this->audit->record( AuditLogger::EVENT_CREATED, $document_id, 0, array( 'title' => $title ) );

		return $document_id;
	}

	/**
	 * Create a document by composing a PDF from rich-text (HTML) content.
	 *
	 * @param string $title     Document title.
	 * @param string $html      Sanitised HTML body (already run through wp_kses_post).
	 * @param array  $variables Map of merge variable name => value. Occurrences
	 *                          of {{name}} in the title/body are substituted.
	 *
	 * @return int New document id.
	 *
	 * @throws \RuntimeException If the content is empty or PDF generation fails.
	 */
	public function create_from_text( string $title, string $html, array $variables = array() ): int {
		if ( '' === trim( wp_strip_all_tags( $html ) ) && '' === trim( $title ) ) {
			throw new \RuntimeException( __( 'Please add a title or some content for the document.', 'comsign' ) );
		}

		$variables = $this->merge_variables( $variables );
		$title     = $this->apply_variables( $title, $variables );
		$html      = $this->apply_variables( $html, $variables );

		$title = '' !== $title ? $title : __( 'Untitled document', 'comsign' );

		$document_id = $this->documents->create(
			array(
				'title'      => $title,
				'created_by' => get_current_user_id(),
			)
		);

		$destination = Storage::document_path( $document_id, 'source' );

		try {
			( new \ComSign\Pdf\PdfComposer() )->render( $title, $html, $destination );
		} catch ( \Throwable $e ) {
			$this->documents->delete( $document_id );
			throw new \RuntimeException( $e->getMessage() );
		}

		$this->documents->update( $document_id, array( 'source_path' => $destination ) );
		$this->audit->record( AuditLogger::EVENT_CREATED, $document_id, 0, array( 'title' => $title, 'source' => 'composed' ) );

		return $document_id;
	}

	/**
	 * Merge user-supplied variables with built-in automatic ones.
	 *
	 * @param array $variables User variables (name => value).
	 *
	 * @return array<string,string>
	 */
	private function merge_variables( array $variables ): array {
		$auto = array(
			'date' => date_i18n( get_option( 'date_format' ) ),
			'site' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$clean = array();
		foreach ( $variables as $name => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
			if ( '' !== $key ) {
				$clean[ $key ] = (string) $value;
			}
		}

		// User values win over auto defaults only when explicitly provided.
		return array_merge( $auto, $clean );
	}

	/**
	 * Replace {{name}} placeholders in a string.
	 *
	 * @param string $text      Text containing placeholders.
	 * @param array  $variables name => value map (already normalised).
	 */
	private function apply_variables( string $text, array $variables ): string {
		foreach ( $variables as $name => $value ) {
			$text = str_replace( '{{' . $name . '}}', $value, $text );
		}
		return $text;
	}

	/**
	 * Re-send (or first-send) the invitation to a single signer with email.
	 *
	 * @param int $document_id Document id (ownership guard).
	 * @param int $signer_id   Signer id.
	 *
	 * @throws \RuntimeException If the signer has no email or is not found.
	 */
	public function resend_signer( int $document_id, int $signer_id ): void {
		$document = $this->documents->find( $document_id );
		$signer   = $this->signers->find( $signer_id );

		if ( ! $document || ! $signer || (int) $signer->document_id !== $document_id ) {
			throw new \RuntimeException( __( 'Signer not found.', 'comsign' ) );
		}
		if ( '' === trim( (string) $signer->email ) ) {
			throw new \RuntimeException( __( 'This signer has no email. Share the signing link instead.', 'comsign' ) );
		}

		$raw = Tokens::generate();
		$this->signers->update(
			$signer_id,
			array(
				'token_hash' => Tokens::hash( $raw ),
				'status'     => SignerRepository::STATUS_PENDING,
			)
		);

		$sent = $this->mailer->send_invitation( $document, $signer, $this->signing_url( $raw ) );
		if ( ! $sent ) {
			$this->audit->record( AuditLogger::EVENT_SEND_FAILED, $document_id, $signer_id, array( 'email' => $signer->email ) );
			throw new \RuntimeException( __( 'The email could not be sent. Please check your site email settings.', 'comsign' ) );
		}

		$this->audit->record( AuditLogger::EVENT_SENT, $document_id, $signer_id, array( 'email' => $signer->email, 'channel' => 'resend' ) );
	}

	/**
	 * Whether a document's signing window has expired.
	 *
	 * @param object $document Document row.
	 */
	public function is_expired( object $document ): bool {
		return ! empty( $document->expires_at ) && strtotime( $document->expires_at . ' UTC' ) < time();
	}

	/**
	 * Build a standalone audit-trail report PDF and return its path.
	 *
	 * @param int $document_id Document id.
	 *
	 * @return string Absolute path to a temporary PDF (caller should delete it).
	 *
	 * @throws \RuntimeException If the document is missing.
	 */
	public function generate_audit_pdf( int $document_id ): string {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			throw new \RuntimeException( __( 'Document not found.', 'comsign' ) );
		}

		$entries = $this->audit_repo->for_document( $document_id );

		$html  = '<h2>' . esc_html__( 'Audit trail', 'comsign' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Document', 'comsign' ) . ': ' . esc_html( $document->title ) . ' (#' . (int) $document->id . ')</p>';
		if ( ! empty( $document->signed_hash ) ) {
			$html .= '<p>SHA-256: ' . esc_html( $document->signed_hash ) . '</p>';
		}

		$html .= '<table border="0.5" cellpadding="5"><thead><tr>'
			. '<th><b>' . esc_html__( 'Event', 'comsign' ) . '</b></th>'
			. '<th><b>' . esc_html__( 'When', 'comsign' ) . '</b></th>'
			. '<th><b>' . esc_html__( 'IP', 'comsign' ) . '</b></th>'
			. '<th><b>' . esc_html__( 'Browser', 'comsign' ) . '</b></th>'
			. '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$html .= '<tr>'
				. '<td>' . esc_html( $entry->event ) . '</td>'
				. '<td>' . esc_html( $entry->created_at ) . '</td>'
				. '<td>' . esc_html( $entry->ip ) . '</td>'
				. '<td>' . esc_html( $entry->user_agent ) . '</td>'
				. '</tr>';
		}
		$html .= '</tbody></table>';

		// Write into the plugin's protected dir (wp_tempnam lives in an admin
		// include that isn't always loaded). The caller deletes it after sending.
		$path = trailingslashit( Storage::ensure_protected_dir() )
			. 'audit-' . $document_id . '-' . wp_generate_password( 12, false ) . '.pdf';

		( new \ComSign\Pdf\PdfComposer() )->render(
			/* translators: %s: document title. */
			sprintf( __( 'Audit report — %s', 'comsign' ), $document->title ),
			$html,
			$path
		);

		return $path;
	}

	/**
	 * Find a completed document whose signed hash matches (for public verify).
	 *
	 * @param int    $document_id Document id.
	 * @param string $hash        SHA-256 the verifier holds.
	 *
	 * @return object|null The document if it is completed and the hash matches.
	 */
	public function verify( int $document_id, string $hash ): ?object {
		$document = $this->documents->find( $document_id );
		if ( ! $document
			|| DocumentRepository::STATUS_COMPLETED !== $document->status
			|| '' === $document->signed_hash
			|| ! hash_equals( strtolower( $document->signed_hash ), strtolower( trim( $hash ) ) )
		) {
			return null;
		}
		return $document;
	}

	/**
	 * Validate that an upload is a real, reasonably-sized PDF.
	 *
	 * @param array $file $_FILES entry.
	 *
	 * @throws \RuntimeException On any validation failure.
	 */
	private function validate_pdf_upload( array $file ): void {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			throw new \RuntimeException( __( 'No file was uploaded, or the upload failed.', 'comsign' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new \RuntimeException( __( 'Invalid upload.', 'comsign' ) );
		}

		// 25 MB ceiling.
		if ( (int) $file['size'] > 25 * MB_IN_BYTES ) {
			throw new \RuntimeException( __( 'The file is too large (max 25 MB).', 'comsign' ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( 'application/pdf' !== ( $check['type'] ?? '' ) ) {
			throw new \RuntimeException( __( 'Only PDF files are allowed.', 'comsign' ) );
		}

		// Confirm the magic bytes really are a PDF.
		$handle = fopen( $file['tmp_name'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$magic  = $handle ? fread( $handle, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( 0 !== strncmp( (string) $magic, '%PDF-', 5 ) ) {
			throw new \RuntimeException( __( 'The file does not look like a valid PDF.', 'comsign' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Signers & fields
	 * ------------------------------------------------------------------- */

	/**
	 * Add a signer to a draft document.
	 *
	 * @param int    $document_id Document id.
	 * @param string $name        Signer name.
	 * @param string $email       Signer email (optional for link-only signers).
	 * @param string $phone       Optional phone (E.164-ish, for WhatsApp).
	 *
	 * @return int New signer id.
	 *
	 * @throws \RuntimeException On invalid input.
	 */
	public function add_signer( int $document_id, string $name, string $email, string $phone = '', string $auth_method = 'none', string $auth_code = '' ): int {
		// Email is optional: a signer can be reached by a shared link / WhatsApp
		// instead. But if an email is given, it must be valid.
		if ( '' !== $email && ! is_email( $email ) ) {
			throw new \RuntimeException( __( 'Please provide a valid email address.', 'comsign' ) );
		}

		// With no email, a name is required so the signer is identifiable.
		if ( '' === $email && '' === trim( $name ) ) {
			throw new \RuntimeException( __( 'Please provide a name or an email for the signer.', 'comsign' ) );
		}

		$existing = $this->signers->for_document( $document_id );

		$auth = $this->normalize_auth( $auth_method, $auth_code, $email );

		return $this->signers->create(
			array(
				'document_id'    => $document_id,
				'name'           => $name,
				'email'          => $email,
				'phone'          => $phone,
				'sign_order'     => count( $existing ),
				'auth_method'    => $auth['method'],
				'auth_code_hash' => $auth['hash'],
			)
		);
	}

	/**
	 * Update a signer's identity-verification method.
	 *
	 * @param int    $document_id Owning document (guard).
	 * @param int    $signer_id   Signer id.
	 * @param string $method      none|code|email_otp.
	 * @param string $code        Access code (only for the 'code' method).
	 */
	public function set_signer_auth( int $document_id, int $signer_id, string $method, string $code = '' ): void {
		$signer = $this->signers->find( $signer_id );
		if ( ! $signer || (int) $signer->document_id !== $document_id ) {
			return;
		}

		$auth = $this->normalize_auth( $method, $code, (string) $signer->email );

		$data = array( 'auth_method' => $auth['method'] );
		// Only overwrite the stored code when a new one is supplied (or cleared).
		if ( 'code' !== $auth['method'] || '' !== $code ) {
			$data['auth_code_hash'] = $auth['hash'];
		}

		$this->signers->update( $signer_id, $data );
	}

	/**
	 * Validate an auth method against the signer and prepare the stored hash.
	 *
	 * @param string $method Requested method.
	 * @param string $code   Plain access code (for 'code').
	 * @param string $email  Signer email (required for 'email_otp').
	 *
	 * @return array{method:string,hash:string}
	 */
	private function normalize_auth( string $method, string $code, string $email ): array {
		$method = in_array( $method, array( 'code', 'email_otp' ), true ) ? $method : 'none';

		// Email OTP needs an email; fall back to none otherwise.
		if ( 'email_otp' === $method && '' === $email ) {
			$method = 'none';
		}

		$hash = '';
		if ( 'code' === $method && '' !== $code ) {
			$hash = \ComSign\Frontend\SignerAuth::hash_code( $code );
		}

		return array(
			'method' => $method,
			'hash'   => $hash,
		);
	}

	/**
	 * Remove a signer (and the fields assigned to them).
	 *
	 * @param int $document_id Document id (ownership guard).
	 * @param int $signer_id   Signer id.
	 */
	public function delete_signer( int $document_id, int $signer_id ): void {
		$signer = $this->signers->find( $signer_id );
		if ( ! $signer || (int) $signer->document_id !== $document_id ) {
			return;
		}

		$this->fields->delete_for_signer( $signer_id );
		$this->signers->delete( $signer_id );
	}

	/**
	 * Mint a fresh token for a signer and return its signing URL.
	 *
	 * Used by the "copy link / share to WhatsApp" flow. Minting a new token
	 * invalidates any previously issued link for that signer.
	 *
	 * @param int $document_id Document id (ownership guard).
	 * @param int $signer_id   Signer id.
	 *
	 * @return string The tokenised signing URL.
	 *
	 * @throws \RuntimeException If the signer does not belong to the document.
	 */
	public function generate_link( int $document_id, int $signer_id ): string {
		$signer = $this->signers->find( $signer_id );
		if ( ! $signer || (int) $signer->document_id !== $document_id ) {
			throw new \RuntimeException( __( 'Signer not found.', 'comsign' ) );
		}

		$raw = Tokens::generate();
		$this->signers->update(
			$signer_id,
			array(
				'token_hash' => Tokens::hash( $raw ),
				'status'     => SignerRepository::STATUS_PENDING,
			)
		);

		// Generating a shareable link counts as "sent" for the document state.
		$document = $this->documents->find( $document_id );
		if ( $document && DocumentRepository::STATUS_DRAFT === $document->status ) {
			$this->documents->set_status( $document_id, DocumentRepository::STATUS_SENT );
		}

		$this->audit->record( AuditLogger::EVENT_SENT, $document_id, $signer_id, array( 'channel' => 'link' ) );

		return $this->signing_url( $raw );
	}

	/**
	 * Duplicate a document together with its fields and signers.
	 *
	 * Signers are copied without tokens and reset to pending; the new document
	 * starts as a fresh draft so it can be re-sent.
	 *
	 * @param int $document_id Source document id.
	 *
	 * @return int New document id.
	 *
	 * @throws \RuntimeException If the source is missing.
	 */
	public function duplicate( int $document_id ): int {
		$source = $this->documents->find( $document_id );
		if ( ! $source ) {
			throw new \RuntimeException( __( 'Document not found.', 'comsign' ) );
		}

		/* translators: %s: original document title. */
		$title   = sprintf( __( '%s (copy)', 'comsign' ), $source->title );
		$new_id  = $this->documents->create(
			array(
				'title'      => $title,
				'created_by' => get_current_user_id(),
			)
		);

		// Copy the source PDF file.
		if ( $source->source_path && is_file( $source->source_path ) ) {
			$new_path = Storage::document_path( $new_id, 'source' );
			copy( $source->source_path, $new_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			$this->documents->update( $new_id, array( 'source_path' => $new_path ) );
		}

		// Map old signer ids to new ones so fields stay correctly assigned.
		$signer_map = array();
		foreach ( $this->signers->for_document( $document_id ) as $signer ) {
			$signer_map[ (int) $signer->id ] = $this->signers->create(
				array(
					'document_id' => $new_id,
					'name'        => $signer->name,
					'email'       => $signer->email,
					'phone'       => $signer->phone ?? '',
					'sign_order'  => (int) $signer->sign_order,
				)
			);
		}

		foreach ( $this->fields->for_document( $document_id ) as $field ) {
			$this->fields->create(
				array(
					'document_id' => $new_id,
					'signer_id'   => $signer_map[ (int) $field->signer_id ] ?? 0,
					'type'        => $field->type,
					'required'    => ! empty( $field->required ),
					'page'        => (int) $field->page,
					'pos_x'       => (float) $field->pos_x,
					'pos_y'       => (float) $field->pos_y,
					'width'       => (float) $field->width,
					'height'      => (float) $field->height,
					'options'     => FieldRepository::decode_options( $field ),
				)
			);
		}

		$this->audit->record( AuditLogger::EVENT_CREATED, $new_id, 0, array( 'duplicated_from' => $document_id ) );

		return $new_id;
	}

	/* ---------------------------------------------------------------------
	 * Templates & bulk send
	 * ------------------------------------------------------------------- */

	/**
	 * Save a document's layout (source + fields, keyed by role) as a template.
	 *
	 * @param int    $document_id Source document id.
	 * @param string $name        Template name.
	 *
	 * @return int New template id.
	 *
	 * @throws \RuntimeException If the document is missing.
	 */
	public function save_as_template( int $document_id, string $name ): int {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			throw new \RuntimeException( __( 'Document not found.', 'comsign' ) );
		}

		// Map each signer id to a role index (ordered as they sign).
		$signers   = $this->signers->for_document( $document_id );
		$role_of   = array();
		$roles     = array();
		foreach ( $signers as $index => $signer ) {
			$role_of[ (int) $signer->id ] = $index;
			/* translators: %d: role number. */
			$roles[] = $signer->name ? $signer->name : sprintf( __( 'Role %d', 'comsign' ), $index + 1 );
		}

		// Serialise fields with role_index instead of a concrete signer id.
		$fields = array();
		foreach ( $this->fields->for_document( $document_id ) as $field ) {
			if ( ! isset( $role_of[ (int) $field->signer_id ] ) ) {
				continue;
			}
			$fields[] = array(
				'role_index' => $role_of[ (int) $field->signer_id ],
				'type'       => (string) $field->type,
				'required'   => ! empty( $field->required ),
				'page'       => (int) $field->page,
				'pos_x'      => (float) $field->pos_x,
				'pos_y'      => (float) $field->pos_y,
				'width'      => (float) $field->width,
				'height'     => (float) $field->height,
				'options'    => FieldRepository::decode_options( $field ),
			);
		}

		$name = '' !== trim( $name ) ? $name : (string) $document->title;

		$template_id = $this->templates->create(
			array(
				'name'   => $name,
				'roles'  => $roles,
				'fields' => $fields,
			)
		);

		// Copy the source PDF into a stable template path.
		if ( $document->source_path && is_file( $document->source_path ) ) {
			$dest = Storage::template_path( $template_id );
			copy( $document->source_path, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			global $wpdb;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				\ComSign\Setup\Installer::templates_table(),
				array( 'source_path' => $dest ),
				array( 'id' => $template_id )
			);
		}

		return $template_id;
	}

	/**
	 * Create a draft document from a template, assigning recipients to roles.
	 *
	 * @param int   $template_id  Template id.
	 * @param array $recipients   role_index => array{name,email,phone}.
	 *
	 * @return int New document id.
	 *
	 * @throws \RuntimeException If the template is missing or has no recipients.
	 */
	public function create_from_template( int $template_id, array $recipients ): int {
		$template = $this->templates->find( $template_id );
		if ( ! $template ) {
			throw new \RuntimeException( __( 'Template not found.', 'comsign' ) );
		}

		$roles = \ComSign\Database\TemplateRepository::roles( $template );

		// Validate recipients up front so we never leave an empty/orphan draft.
		$valid = array();
		foreach ( $roles as $index => $label ) {
			$r     = $recipients[ $index ] ?? array();
			$email = isset( $r['email'] ) ? sanitize_email( (string) $r['email'] ) : '';
			$name  = isset( $r['name'] ) ? (string) $r['name'] : '';
			$phone = isset( $r['phone'] ) ? (string) $r['phone'] : '';

			if ( '' !== $email && ! is_email( $email ) ) {
				$email = '';
			}
			if ( '' === $name && '' === $email ) {
				continue; // No usable recipient for this role.
			}

			$valid[ $index ] = array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
			);
		}

		if ( empty( $valid ) ) {
			throw new \RuntimeException( __( 'Please provide at least one recipient.', 'comsign' ) );
		}

		// Every role that carries fields must have a recipient, otherwise those
		// fields would be silently dropped.
		$roles_with_fields = array();
		foreach ( \ComSign\Database\TemplateRepository::fields( $template ) as $field ) {
			$roles_with_fields[ (int) ( $field['role_index'] ?? 0 ) ] = true;
		}
		foreach ( array_keys( $roles_with_fields ) as $role_index ) {
			if ( ! isset( $valid[ $role_index ] ) ) {
				throw new \RuntimeException( __( 'Please provide a recipient for every role that has fields.', 'comsign' ) );
			}
		}

		$document_id = $this->documents->create(
			array(
				'title'      => (string) $template->name,
				'created_by' => get_current_user_id(),
			)
		);

		// Copy the template source PDF for this document.
		if ( $template->source_path && is_file( $template->source_path ) ) {
			$dest = Storage::document_path( $document_id, 'source' );
			copy( $template->source_path, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			$this->documents->update( $document_id, array( 'source_path' => $dest ) );
		}

		// Create a signer per valid role and remember the mapping.
		$signer_of_role = array();
		foreach ( $valid as $index => $r ) {
			$signer_of_role[ $index ] = $this->signers->create(
				array(
					'document_id' => $document_id,
					'name'        => $r['name'],
					'email'       => $r['email'],
					'phone'       => $r['phone'],
					'sign_order'  => $index,
				)
			);
		}

		// Recreate the fields, mapping role_index -> new signer id.
		foreach ( \ComSign\Database\TemplateRepository::fields( $template ) as $field ) {
			$role = (int) ( $field['role_index'] ?? 0 );
			if ( ! isset( $signer_of_role[ $role ] ) ) {
				continue;
			}
			$this->fields->create(
				array(
					'document_id' => $document_id,
					'signer_id'   => $signer_of_role[ $role ],
					'type'        => (string) ( $field['type'] ?? FieldRepository::TYPE_SIGNATURE ),
					'required'    => ! empty( $field['required'] ),
					'page'        => (int) ( $field['page'] ?? 1 ),
					'pos_x'       => (float) ( $field['pos_x'] ?? 0 ),
					'pos_y'       => (float) ( $field['pos_y'] ?? 0 ),
					'width'       => (float) ( $field['width'] ?? 0 ),
					'height'      => (float) ( $field['height'] ?? 0 ),
					'options'     => isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : null,
				)
			);
		}

		$this->audit->record( AuditLogger::EVENT_CREATED, $document_id, 0, array( 'from_template' => $template_id ) );

		return $document_id;
	}

	/**
	 * Bulk: create and send one document per recipient from a 1-role template.
	 *
	 * @param int   $template_id Template id (must have exactly one role).
	 * @param array $recipients  List of array{name,email,phone}.
	 * @param array $options     Send options (message, expiry_days).
	 *
	 * @return int Number of documents created and sent.
	 *
	 * @throws \RuntimeException If the template is unsuitable for bulk send.
	 */
	public function bulk_from_template( int $template_id, array $recipients, array $options = array() ): int {
		$template = $this->templates->find( $template_id );
		if ( ! $template ) {
			throw new \RuntimeException( __( 'Template not found.', 'comsign' ) );
		}

		if ( count( \ComSign\Database\TemplateRepository::roles( $template ) ) !== 1 ) {
			throw new \RuntimeException( __( 'Bulk send requires a template with exactly one role.', 'comsign' ) );
		}

		$count = 0;
		foreach ( $recipients as $recipient ) {
			if ( empty( $recipient['email'] ) || ! is_email( $recipient['email'] ) ) {
				continue;
			}
			$document_id = $this->create_from_template( $template_id, array( 0 => $recipient ) );
			try {
				$this->send( $document_id, $options );
				++$count;
			} catch ( \Throwable $e ) {
				// One bad recipient must not abort the whole batch.
				continue;
			}
		}

		return $count;
	}

	/**
	 * Delete a template and its source file.
	 *
	 * @param int $template_id Template id.
	 */
	public function delete_template( int $template_id ): void {
		$template = $this->templates->find( $template_id );
		if ( ! $template ) {
			return;
		}
		if ( $template->source_path && is_file( $template->source_path ) && Storage::is_within_base( $template->source_path ) ) {
			wp_delete_file( $template->source_path );
		}
		$this->templates->delete( $template_id );
	}

	/**
	 * Replace the signature fields for a document.
	 *
	 * @param int   $document_id Document id.
	 * @param array $fields      List of field definitions.
	 */
	public function save_fields( int $document_id, array $fields ): void {
		// Allowlist of signer ids that actually belong to this document, so a
		// tampered payload cannot attach a field to a signer of another document.
		$allowed = array();
		foreach ( $this->signers->for_document( $document_id ) as $signer ) {
			$allowed[ (int) $signer->id ] = true;
		}

		$this->fields->delete_for_document( $document_id );

		foreach ( $fields as $field ) {
			$signer_id = (int) ( $field['signer_id'] ?? 0 );

			// Skip fields not assigned to a valid signer of this document.
			if ( ! isset( $allowed[ $signer_id ] ) ) {
				continue;
			}

			$this->fields->create(
				array(
					'document_id' => $document_id,
					'signer_id'   => $signer_id,
					'type'        => (string) ( $field['type'] ?? FieldRepository::TYPE_SIGNATURE ),
					'required'    => ! empty( $field['required'] ),
					'page'        => (int) ( $field['page'] ?? 1 ),
					'pos_x'       => $this->clamp_fraction( $field['pos_x'] ?? 0 ),
					'pos_y'       => $this->clamp_fraction( $field['pos_y'] ?? 0 ),
					'width'       => $this->clamp_fraction( $field['width'] ?? 0 ),
					'height'      => $this->clamp_fraction( $field['height'] ?? 0 ),
					'options'     => isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : null,
				)
			);
		}
	}

	/**
	 * Clamp a coordinate fraction into the [0, 1] range.
	 *
	 * @param mixed $value Raw value.
	 */
	private function clamp_fraction( $value ): float {
		return max( 0.0, min( 1.0, (float) $value ) );
	}

	/**
	 * Encode a plain-text field value for PDF stamping.
	 *
	 * @param string $text Text value.
	 */
	private function text_value( string $text ): string {
		return (string) wp_json_encode( array( 'kind' => 'text', 'text' => $text ) );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Mint fresh tokens and email signing invitations.
	 *
	 * @param int   $document_id Document id.
	 * @param array $options     'sequential' (bool), 'message' (string),
	 *                           'expiry_days' (int, 0 = no expiry).
	 *
	 * @return array{emailed:int,link_only:int} Counts describing what happened,
	 *               so the caller can show an accurate notice.
	 *
	 * @throws \RuntimeException On validation or mail failure.
	 */
	public function send( int $document_id, array $options = array() ): array {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			throw new \RuntimeException( __( 'Document not found.', 'comsign' ) );
		}

		$signers = $this->signers->for_document( $document_id );
		if ( empty( $signers ) ) {
			throw new \RuntimeException( __( 'Add at least one signer before sending.', 'comsign' ) );
		}

		$fields = $this->fields->for_document( $document_id );
		if ( empty( $fields ) ) {
			throw new \RuntimeException( __( 'Place at least one signature field before sending.', 'comsign' ) );
		}

		// Every field must belong to a signer of this document, and every signer
		// must have at least one field — otherwise a signer could be invited with
		// nothing to do, or the document could finalise without a visible mark.
		$this->assert_fields_cover_signers( $signers, $fields );

		// Persist per-send settings (sequential / message / expiry) on the document.
		$sequential  = ! empty( $options['sequential'] );
		$expiry_days = max( 0, (int) ( $options['expiry_days'] ?? 0 ) );
		$expires_at  = $expiry_days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expiry_days * DAY_IN_SECONDS ) : null;

		$this->documents->update(
			$document_id,
			array(
				'sequential' => $sequential ? 1 : 0,
				'message'    => isset( $options['message'] ) ? (string) $options['message'] : '',
			)
		);
		$this->documents->set_expiry( $document_id, $expires_at );
		$document = $this->documents->find( $document_id );

		$emailed   = 0;
		$link_only = 0;
		$failures  = array();

		if ( $sequential ) {
			// Only the next unsigned signer is invited now; the rest follow as
			// each one signs.
			$next = $this->signers->next_unsigned( $document_id );
			$this->documents->set_status( $document_id, DocumentRepository::STATUS_SENT );

			if ( $next && '' !== trim( (string) $next->email ) ) {
				$this->invite_signer( $document, $next ) ? $emailed++ : $failures[] = $next->email;
			} elseif ( $next ) {
				$link_only++;
			}
		} else {
			foreach ( $signers as $signer ) {
				if ( '' === trim( (string) $signer->email ) ) {
					// Reached only via a shared link / WhatsApp.
					$link_only++;
					continue;
				}
				$this->invite_signer( $document, $signer ) ? $emailed++ : $failures[] = $signer->email;
			}
			$this->documents->set_status( $document_id, DocumentRepository::STATUS_SENT );
		}

		// Surface mail failures to the admin instead of silently reporting success.
		if ( ! empty( $failures ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: comma-separated list of email addresses. */
					__( 'Some invitations could not be sent: %s. Please check your site email settings and re-send.', 'comsign' ),
					implode( ', ', $failures )
				)
			);
		}

		return array(
			'emailed'   => $emailed,
			'link_only' => $link_only,
		);
	}

	/**
	 * Validate that fields cover the signers, and only valid signers.
	 *
	 * @param array $signers Signer rows.
	 * @param array $fields  Field rows.
	 *
	 * @throws \RuntimeException If a signer has no field or a field is orphaned.
	 */
	private function assert_fields_cover_signers( array $signers, array $fields ): void {
		$signer_ids   = array();
		$with_a_field = array();
		foreach ( $signers as $signer ) {
			$signer_ids[ (int) $signer->id ] = true;
		}

		foreach ( $fields as $field ) {
			$sid = (int) $field->signer_id;
			if ( ! isset( $signer_ids[ $sid ] ) ) {
				throw new \RuntimeException( __( 'A field is assigned to a signer that is not on this document. Please re-save the fields.', 'comsign' ) );
			}
			$with_a_field[ $sid ] = true;
		}

		if ( count( $with_a_field ) < count( $signer_ids ) ) {
			throw new \RuntimeException( __( 'Every signer must have at least one field. Please assign a field to each signer.', 'comsign' ) );
		}
	}

	/**
	 * Mint a token for a signer and email them the invitation.
	 *
	 * @param object $document    Document row.
	 * @param object $signer      Signer row (must have an email).
	 * @param bool   $is_reminder Whether to phrase it as a reminder.
	 *
	 * @return bool Whether the email was accepted for delivery.
	 */
	private function invite_signer( object $document, object $signer, bool $is_reminder = false ): bool {
		$raw    = Tokens::generate();
		$update = array(
			'token_hash' => Tokens::hash( $raw ),
			'status'     => SignerRepository::STATUS_PENDING,
		);
		if ( $is_reminder ) {
			$update['reminded_at'] = current_time( 'mysql', true );
		}
		$this->signers->update( (int) $signer->id, $update );

		$sent = $this->mailer->send_invitation( $document, $signer, $this->signing_url( $raw ), $is_reminder );

		if ( $sent ) {
			$event = $is_reminder ? AuditLogger::EVENT_REMINDED : AuditLogger::EVENT_SENT;
			$this->audit->record( $event, (int) $document->id, (int) $signer->id, array( 'email' => $signer->email ) );
		} else {
			$this->audit->record( AuditLogger::EVENT_SEND_FAILED, (int) $document->id, (int) $signer->id, array( 'email' => $signer->email ) );
		}

		return $sent;
	}

	/**
	 * Send reminder emails to in-progress signers (called from daily cron).
	 *
	 * Honours sequential order (only the active signer is reminded) and a
	 * minimum gap between reminders.
	 *
	 * @param int $min_gap_days Minimum days between reminders for a signer.
	 *
	 * @return int Number of reminders sent.
	 */
	public function run_reminders( int $min_gap_days = 3 ): int {
		$count   = 0;
		$cutoff  = time() - max( 1, $min_gap_days ) * DAY_IN_SECONDS;

		foreach ( $this->signers->reminder_candidates() as $signer ) {
			// Respect the reminder gap.
			if ( ! empty( $signer->reminded_at ) && strtotime( $signer->reminded_at . ' UTC' ) > $cutoff ) {
				continue;
			}

			$document = $this->documents->find( (int) $signer->document_id );
			if ( ! $document ) {
				continue;
			}

			// Skip expired documents.
			if ( ! empty( $document->expires_at ) && strtotime( $document->expires_at . ' UTC' ) < time() ) {
				continue;
			}

			// In sequential mode, only remind the active signer.
			if ( ! empty( $document->sequential ) && $this->signers->has_earlier_unsigned( $signer ) ) {
				continue;
			}

			if ( $this->invite_signer( $document, $signer, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build the public signing URL for a raw token.
	 *
	 * @param string $raw_token Raw token.
	 */
	public function signing_url( string $raw_token ): string {
		return add_query_arg(
			array(
				'comsign_sign' => '1',
				'token'        => rawurlencode( $raw_token ),
			),
			home_url( '/' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Signing & finalisation
	 * ------------------------------------------------------------------- */

	/**
	 * Record a signer's captured signature and finalise if everyone is done.
	 *
	 * @param object $document        Document row.
	 * @param object $signer          Signer row.
	 * @param string $signature_image Base64 PNG (no data: prefix) or empty.
	 * @param array  $field_values    Map of field id => text value, for the
	 *                                text fields the signer fills in themselves.
	 *
	 * @throws \RuntimeException If a required field is missing.
	 */
	public function record_signature( object $document, object $signer, string $signature_image, array $field_values = array() ): void {
		// Scope to the document being signed so a mis-assigned field can never
		// be written through another document's signing flow.
		$signer_fields = $this->fields->for_signer_in_document( (int) $document->id, (int) $signer->id );

		// A signature is only mandatory when the signer has a signature field.
		$needs_signature = false;
		foreach ( $signer_fields as $field ) {
			if ( in_array( $field->type, array( FieldRepository::TYPE_SIGNATURE, FieldRepository::TYPE_INITIALS ), true ) ) {
				$needs_signature = true;
				break;
			}
		}
		if ( $needs_signature && '' === $signature_image ) {
			throw new \RuntimeException( __( 'A signature is required.', 'comsign' ) );
		}

		$today = date_i18n( get_option( 'date_format' ) );

		foreach ( $signer_fields as $field ) {
			$posted = isset( $field_values[ (int) $field->id ] ) ? (string) $field_values[ (int) $field->id ] : '';

			// Enforce required signer-entered fields server-side.
			if ( ! empty( $field->required ) && in_array( $field->type, FieldRepository::INPUT_TYPES, true ) ) {
				$filled = ( FieldRepository::TYPE_CHOICE === $field->type )
					? in_array( $posted, FieldRepository::decode_options( $field ), true )
					: ( '' !== trim( $posted ) );
				if ( ! $filled ) {
					throw new \RuntimeException( __( 'Please complete all required fields before signing.', 'comsign' ) );
				}
			}

			switch ( $field->type ) {
				// Auto-filled from signer/document data (per-signer variables).
				case FieldRepository::TYPE_DATE:
					$value = $this->text_value( $today );
					break;
				case FieldRepository::TYPE_NAME:
					$value = $this->text_value( (string) $signer->name );
					break;
				case FieldRepository::TYPE_EMAIL:
					$value = $this->text_value( (string) $signer->email );
					break;

				// Signer-entered.
				case FieldRepository::TYPE_TEXT:
					$value = $this->text_value( $posted );
					break;
				case FieldRepository::TYPE_NUMBER:
					// Keep digits, separators and a leading sign only.
					$value = $this->text_value( preg_replace( '/[^0-9.,\-+ ]/', '', $posted ) );
					break;
				case FieldRepository::TYPE_CHECKBOX:
					$value = $this->text_value( '' !== $posted ? 'X' : '' );
					break;
				case FieldRepository::TYPE_CHOICE:
					// Only accept a value that is one of the defined options.
					$options = FieldRepository::decode_options( $field );
					$value   = $this->text_value( in_array( $posted, $options, true ) ? $posted : '' );
					break;

				default: // signature / initials.
					$value = wp_json_encode( array( 'kind' => 'image', 'data' => $signature_image ) );
					break;
			}
			$this->fields->set_value( (int) $field->id, (string) $value );
		}

		$now = current_time( 'mysql', true );
		$this->signers->update(
			(int) $signer->id,
			array(
				'status'    => SignerRepository::STATUS_SIGNED,
				'signed_at' => $now,
			)
		);

		// Consent + signature are recorded together for the audit trail.
		$this->audit->record( AuditLogger::EVENT_CONSENTED, (int) $document->id, (int) $signer->id );
		$this->audit->record( AuditLogger::EVENT_SIGNED, (int) $document->id, (int) $signer->id );

		if ( $this->signers->all_signed( (int) $document->id ) ) {
			$this->finalize( (int) $document->id );
		} else {
			$this->documents->set_status( (int) $document->id, DocumentRepository::STATUS_SIGNED );

			// Sequential signing: invite the next signer in line automatically.
			if ( ! empty( $document->sequential ) ) {
				$next = $this->signers->next_unsigned( (int) $document->id );
				if ( $next && '' !== trim( (string) $next->email ) ) {
					$this->invite_signer( $document, $next );
				}
			}
		}
	}

	/**
	 * Generate the final signed PDF and mark the document completed.
	 *
	 * @param int $document_id Document id.
	 */
	public function finalize( int $document_id ): void {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			return;
		}

		$fields  = $this->fields->for_document( $document_id );
		$signers = $this->signers->for_document( $document_id );

		// Use the cryptographic provider when a certificate is configured,
		// otherwise the baseline electronic provider.
		$provider = \ComSign\Signature\Certificate::is_configured()
			? new \ComSign\Signature\PadesSignatureProvider()
			: $this->provider;

		$signed_path = $provider->finalize( $document, $fields, $signers );
		$hash        = is_file( $signed_path ) ? hash_file( 'sha256', $signed_path ) : '';

		$this->documents->update(
			$document_id,
			array(
				'signed_path' => $signed_path,
				'signed_hash' => $hash,
				'status'      => DocumentRepository::STATUS_COMPLETED,
			)
		);

		$this->audit->record( AuditLogger::EVENT_COMPLETED, $document_id, 0, array( 'sha256' => $hash, 'provider' => $provider->id() ) );

		// Email the completed PDF to any CC recipients.
		$this->notify_cc( $this->documents->find( $document_id ), $signed_path );
	}

	/**
	 * Store the CC recipients for a document (validated, comma-joined).
	 *
	 * @param int   $document_id Document id.
	 * @param array $emails      Raw email strings.
	 */
	public function set_cc( int $document_id, array $emails ): void {
		$valid = array();
		foreach ( $emails as $email ) {
			$email = sanitize_email( (string) $email );
			$key   = strtolower( $email );
			if ( '' !== $email && is_email( $email ) && ! isset( $valid[ $key ] ) ) {
				$valid[ $key ] = $email;
			}
		}
		$this->documents->update( $document_id, array( 'cc_emails' => implode( ',', array_values( $valid ) ) ) );
	}

	/**
	 * Parse a document's stored CC list into an array of emails.
	 *
	 * @param object|null $document Document row.
	 *
	 * @return string[]
	 */
	public static function cc_list( ?object $document ): array {
		if ( ! $document || empty( $document->cc_emails ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $document->cc_emails ) ) ) );
	}

	/**
	 * Email the completed signed PDF to the document's CC recipients.
	 *
	 * @param object|null $document    Document row.
	 * @param string      $signed_path Path to the signed PDF.
	 */
	private function notify_cc( ?object $document, string $signed_path ): void {
		$recipients = self::cc_list( $document );
		if ( empty( $recipients ) || ! is_file( $signed_path ) ) {
			return;
		}

		$brand   = \ComSign\Support\Settings::brand_name();
		/* translators: %s: document title. */
		$subject = sprintf( __( 'Completed & signed: "%s"', 'comsign' ), $document->title );
		$body    = implode(
			"\r\n",
			array(
				/* translators: %s: document title. */
				sprintf( __( 'The document "%s" has been signed by all parties.', 'comsign' ), $document->title ),
				'',
				__( 'The fully signed copy is attached.', 'comsign' ),
				'',
				/* translators: %s: brand/site name. */
				sprintf( __( 'Sent by %s', 'comsign' ), $brand ),
			)
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		foreach ( $recipients as $email ) {
			wp_mail( $email, $subject, $body, $headers, array( $signed_path ) );
		}
	}

	/**
	 * Mark a signer (and document) as declined.
	 *
	 * @param object $document Document row.
	 * @param object $signer   Signer row.
	 * @param string $reason   Optional decline reason.
	 */
	public function decline( object $document, object $signer, string $reason = '' ): void {
		$this->signers->update( (int) $signer->id, array( 'status' => SignerRepository::STATUS_DECLINED ) );
		$this->documents->set_status( (int) $document->id, DocumentRepository::STATUS_DECLINED );
		$this->audit->record( AuditLogger::EVENT_DECLINED, (int) $document->id, (int) $signer->id, array( 'reason' => $reason ) );
	}

	/* ---------------------------------------------------------------------
	 * Deletion
	 * ------------------------------------------------------------------- */

	/**
	 * Permanently delete a document and everything attached to it.
	 *
	 * @param int $document_id Document id.
	 */
	public function delete( int $document_id ): void {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			return;
		}

		Storage::delete_document_files( $document );
		$this->fields->delete_for_document( $document_id );
		$this->signers->delete_for_document( $document_id );
		$this->audit_repo->delete_for_document( $document_id );
		$this->documents->delete( $document_id );
	}
}
