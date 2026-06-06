<?php
/**
 * Admin UI controller.
 *
 * @package ComSign
 */

namespace ComSign\Admin;

defined( 'ABSPATH' ) || exit;

use ComSign\Audit\AuditLogger;
use ComSign\Database\AuditRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;
use ComSign\Support\Capabilities;
use ComSign\Support\Settings;
use ComSign\Support\Storage;

/**
 * Registers the admin menu, assets and form handlers.
 */
final class Admin {

	private const MENU_SLUG = 'comsign';

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private FieldRepository $fields;
	private AuditRepository $audit;
	private \ComSign\Database\TemplateRepository $templates;
	private DocumentService $service;
	private \ComSign\Services\AccountService $accounts;

	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->signers   = new SignerRepository();
		$this->fields    = new FieldRepository();
		$this->audit     = new AuditRepository();
		$this->templates = new \ComSign\Database\TemplateRepository();
		$this->service   = new DocumentService();
		$this->accounts  = new \ComSign\Services\AccountService();
	}

	/**
	 * Account ids the current user may see.
	 *
	 * @return int[]
	 */
	private function visible_account_ids(): array {
		return $this->accounts->visible_account_ids( get_current_user_id() );
	}

	/**
	 * Load a document and ensure the current user's account scope allows access.
	 * Dies with 403 otherwise. Centralises tenant authorization for the admin.
	 */
	private function assert_document_access( int $document_id ): object {
		$document = $this->documents->find( $document_id );
		if ( ! $document || ! $this->accounts->can_access_document( $document, get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have access to this document.', 'comsign' ), '', array( 'response' => 403 ) );
		}
		return $document;
	}

	/**
	 * Hook the admin controller into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_comsign_create_document', array( $this, 'handle_create_document' ) );
		add_action( 'admin_post_comsign_create_text', array( $this, 'handle_create_text' ) );
		add_action( 'admin_post_comsign_add_signer', array( $this, 'handle_add_signer' ) );
		add_action( 'admin_post_comsign_set_signer_auth', array( $this, 'handle_set_signer_auth' ) );
		add_action( 'admin_post_comsign_save_cc', array( $this, 'handle_save_cc' ) );
		add_action( 'admin_post_comsign_delete_signer', array( $this, 'handle_delete_signer' ) );
		add_action( 'admin_post_comsign_signer_link', array( $this, 'handle_signer_link' ) );
		add_action( 'admin_post_comsign_sign_in_person', array( $this, 'handle_sign_in_person' ) );
		add_action( 'admin_post_comsign_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'admin_post_comsign_test_webhook', array( $this, 'handle_test_webhook' ) );
		add_action( 'admin_post_comsign_resend_signer', array( $this, 'handle_resend_signer' ) );
		add_action( 'admin_post_comsign_extend_expiry', array( $this, 'handle_extend_expiry' ) );
		add_action( 'admin_post_comsign_switch_account', array( $this, 'handle_switch_account' ) );
		add_action( 'admin_post_comsign_save_fields', array( $this, 'handle_save_fields' ) );
		add_action( 'admin_post_comsign_send', array( $this, 'handle_send' ) );
		add_action( 'admin_post_comsign_duplicate', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_comsign_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_comsign_stream', array( $this, 'handle_stream' ) );
		add_action( 'admin_post_comsign_audit_pdf', array( $this, 'handle_audit_pdf' ) );
		add_action( 'admin_post_comsign_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_comsign_upload_certificate', array( $this, 'handle_upload_certificate' ) );
		add_action( 'admin_post_comsign_remove_certificate', array( $this, 'handle_remove_certificate' ) );
		add_action( 'admin_post_comsign_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_comsign_use_template', array( $this, 'handle_use_template' ) );
		add_action( 'admin_post_comsign_bulk_template', array( $this, 'handle_bulk_template' ) );
		add_action( 'admin_post_comsign_delete_template', array( $this, 'handle_delete_template' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu & assets
	 * ------------------------------------------------------------------- */

	public function register_menu(): void {
		add_menu_page(
			__( 'ComSign', 'comsign' ),
			__( 'ComSign', 'comsign' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render_main_page' ),
			'dashicons-edit-page',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Documents', 'comsign' ),
			__( 'Documents', 'comsign' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render_main_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add Document', 'comsign' ),
			__( 'Add Document', 'comsign' ),
			Capabilities::MANAGE,
			'comsign-new',
			array( $this, 'render_new_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics', 'comsign' ),
			__( 'Analytics', 'comsign' ),
			Capabilities::MANAGE,
			'comsign-analytics',
			array( $this, 'render_analytics_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Templates', 'comsign' ),
			__( 'Templates', 'comsign' ),
			Capabilities::MANAGE,
			'comsign-templates',
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'comsign' ),
			__( 'Settings', 'comsign' ),
			Capabilities::MANAGE,
			'comsign-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'System Status', 'comsign' ),
			__( 'System Status', 'comsign' ),
			Capabilities::MANAGE,
			'comsign-health',
			array( $this, 'render_health_page' )
		);
	}

	/**
	 * Enqueue editor assets only on the document edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'comsign' ) ) {
			return;
		}

		wp_enqueue_style(
			'comsign-admin',
			COMSIGN_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			COMSIGN_VERSION
		);

		// Small helper script (copy-to-clipboard etc.) on all ComSign screens.
		wp_enqueue_script(
			'comsign-admin',
			COMSIGN_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			COMSIGN_VERSION,
			true
		);
		wp_localize_script(
			'comsign-admin',
			'ComSignAdmin',
			array(
				'copied' => __( 'Copied!', 'comsign' ),
				'copy'   => __( 'Copy link', 'comsign' ),
			)
		);

		// The editor (pdf.js placement) is only needed on the edit screen.
		$is_edit = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $is_edit ) {
			return;
		}

		wp_enqueue_script(
			'comsign-pdfjs',
			COMSIGN_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.min.js',
			array(),
			'3.11.174',
			true
		);

		wp_enqueue_script(
			'comsign-admin-editor',
			COMSIGN_PLUGIN_URL . 'assets/js/admin-editor.js',
			array( 'comsign-pdfjs' ),
			COMSIGN_VERSION,
			true
		);

		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'comsign-admin-editor',
			'ComSignEditor',
			array(
				'workerSrc'  => COMSIGN_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
				'pdfUrl'     => $this->stream_url( $document_id, 'source' ),
				'i18n'       => array(
					'signature'    => __( 'Signature', 'comsign' ),
					'initials'     => __( 'Initials', 'comsign' ),
					'date'         => __( 'Date', 'comsign' ),
					'name'         => __( 'Name', 'comsign' ),
					'email'        => __( 'Email', 'comsign' ),
					'text'         => __( 'Text', 'comsign' ),
					'number'       => __( 'Number', 'comsign' ),
					'checkbox'     => __( 'Checkbox', 'comsign' ),
					'choice'       => __( 'Choice', 'comsign' ),
					'attachment'   => __( 'File', 'comsign' ),
					'remove'       => __( 'Remove', 'comsign' ),
					'choicePrompt' => __( 'Enter options separated by commas:', 'comsign' ),
					'labelPrompt'  => __( 'Field label shown to the signer (optional):', 'comsign' ),
					'helpPrompt'   => __( 'Short help text under the field (optional):', 'comsign' ),
					'toggleRequired' => __( 'Click to toggle required', 'comsign' ),
					'loading'      => __( 'Loading document…', 'comsign' ),
					'loadError'    => __( 'Could not load the document preview.', 'comsign' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page rendering
	 * ------------------------------------------------------------------- */

	public function render_main_page(): void {
		$this->guard();

		$action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action && $document_id ) {
			$this->render_edit_page( $document_id );
			return;
		}

		$this->render_list_page();
	}

	private function render_list_page(): void {
		$account_ids = $this->visible_account_ids();
		$table       = new DocumentsListTable( $this->documents, $this->signers, $account_ids );
		$table->prepare_items();

		$user_id = get_current_user_id();
		$this->view(
			'list',
			array(
				'table'           => $table,
				'new_url'         => admin_url( 'admin.php?page=comsign-new' ),
				'counts'          => $this->documents->status_counts_for_accounts( $account_ids ),
				'accounts'        => $this->accounts->accounts_for_user( $user_id ),
				'current_account' => $this->accounts->current_account_id( $user_id ),
				'switch_url'      => admin_url( 'admin-post.php' ),
				'switch_nonce'    => wp_create_nonce( 'comsign_switch_account' ),
				'notice'          => $this->pull_notice(),
			)
		);
	}

	public function render_new_page(): void {
		$this->guard();

		$this->view(
			'new',
			array(
				'action_url'   => admin_url( 'admin-post.php' ),
				'nonce'        => wp_create_nonce( 'comsign_create_document' ),
				'compose_nonce' => wp_create_nonce( 'comsign_create_text' ),
				'notice'       => $this->pull_notice(),
			)
		);
	}

	private function render_edit_page( int $document_id ): void {
		$document = $this->assert_document_access( $document_id );

		$this->view(
			'edit',
			array(
				'document'   => $document,
				'signers'    => $this->signers->for_document( $document_id ),
				'fields'     => $this->fields->for_document( $document_id ),
				'audit'      => $this->audit->for_document( $document_id ),
				'action_url' => admin_url( 'admin-post.php' ),
				'link_flash' => $this->pull_link_flash(),
				'nonces'     => array(
					'add_signer'    => wp_create_nonce( 'comsign_add_signer_' . $document_id ),
					'signer_auth'   => wp_create_nonce( 'comsign_set_signer_auth_' . $document_id ),
					'save_cc'       => wp_create_nonce( 'comsign_save_cc_' . $document_id ),
					'delete_signer' => wp_create_nonce( 'comsign_delete_signer_' . $document_id ),
					'signer_link'   => wp_create_nonce( 'comsign_signer_link_' . $document_id ),
					'sign_in_person' => wp_create_nonce( 'comsign_sign_in_person_' . $document_id ),
					'resend_signer' => wp_create_nonce( 'comsign_resend_signer_' . $document_id ),
					'extend_expiry' => wp_create_nonce( 'comsign_extend_expiry_' . $document_id ),
					'save_fields'   => wp_create_nonce( 'comsign_save_fields_' . $document_id ),
					'send'          => wp_create_nonce( 'comsign_send_' . $document_id ),
					'save_template' => wp_create_nonce( 'comsign_save_template_' . $document_id ),
					'delete'        => wp_create_nonce( 'comsign_delete_' . $document_id ),
				),
				'download'   => array(
					'source'    => $this->stream_url( $document_id, 'source' ),
					'signed'    => $this->stream_url( $document_id, 'signed' ),
					'audit_pdf' => wp_nonce_url(
						admin_url( 'admin-post.php?action=comsign_audit_pdf&document_id=' . $document_id ),
						'comsign_audit_pdf_' . $document_id
					),
				),
				'notice'     => $this->pull_notice(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	public function handle_create_document(): void {
		$this->guard();
		check_admin_referer( 'comsign_create_document' );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		try {
			if ( empty( $_FILES['document'] ) || ! is_array( $_FILES['document'] ) ) {
				throw new \RuntimeException( __( 'Please choose a PDF to upload.', 'comsign' ) );
			}
			// Note: file contents are validated (mime + magic bytes) in the service.
			$file        = $this->sanitize_files( $_FILES['document'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$document_id = $this->service->create_from_upload( $file, $title );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-new' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice(
			admin_url( 'admin.php?page=comsign&action=edit&document=' . $document_id ),
			'success',
			__( 'Document uploaded. Now add signers and place signature fields.', 'comsign' )
		);
	}

	/**
	 * Create a document by composing rich-text content into a PDF.
	 */
	public function handle_create_text(): void {
		$this->guard();
		check_admin_referer( 'comsign_create_text' );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		// wp_kses_post keeps safe formatting markup while stripping anything risky.
		$html  = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$variables = $this->parse_variables(
			isset( $_POST['variables'] ) ? sanitize_textarea_field( wp_unslash( $_POST['variables'] ) ) : ''
		);

		try {
			$document_id = $this->service->create_from_text( $title, $html, $variables );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-new' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice(
			$this->edit_url( $document_id ),
			'success',
			__( 'Document created. Now add signers and place fields.', 'comsign' )
		);
	}

	public function handle_add_signer(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_add_signer_' . $document_id );

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone       = isset( $_POST['phone'] ) ? $this->sanitize_phone( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$auth_method = isset( $_POST['auth_method'] ) ? sanitize_key( wp_unslash( $_POST['auth_method'] ) ) : 'none';
		$auth_code   = isset( $_POST['auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';

		try {
			$this->service->add_signer( $document_id, $name, $email, $phone, $auth_method, $auth_code );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signer added.', 'comsign' ) );
	}

	public function handle_save_cc(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_save_cc_' . $document_id );

		$raw = isset( $_POST['cc_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cc_emails'] ) ) : '';
		// Split on commas, semicolons, whitespace or newlines.
		$emails = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		$this->service->set_cc( $document_id, is_array( $emails ) ? $emails : array() );

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'CC recipients saved.', 'comsign' ) );
	}

	public function handle_set_signer_auth(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_set_signer_auth_' . $document_id );

		$signer_id   = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;
		$auth_method = isset( $_POST['auth_method'] ) ? sanitize_key( wp_unslash( $_POST['auth_method'] ) ) : 'none';
		$auth_code   = isset( $_POST['auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';

		$this->service->set_signer_auth( $document_id, $signer_id, $auth_method, $auth_code );

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signer verification updated.', 'comsign' ) );
	}

	public function handle_delete_signer(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_delete_signer_' . $document_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;
		$this->service->delete_signer( $document_id, $signer_id );

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signer removed.', 'comsign' ) );
	}

	public function handle_resend_signer(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_resend_signer_' . $document_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;

		try {
			$this->service->resend_signer( $document_id, $signer_id );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Invitation re-sent.', 'comsign' ) );
	}

	/**
	 * Switch the current user's working account (tenant context).
	 */
	public function handle_switch_account(): void {
		$this->guard();
		check_admin_referer( 'comsign_switch_account' );

		$account_id = isset( $_POST['account_id'] ) ? absint( wp_unslash( $_POST['account_id'] ) ) : 0;
		$this->accounts->set_current_account( get_current_user_id(), $account_id );

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign' ), 'success', __( 'Workspace switched.', 'comsign' ) );
	}

	/**
	 * Extend a document's signing deadline.
	 */
	public function handle_extend_expiry(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_extend_expiry_' . $document_id );

		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 0;

		try {
			$this->service->extend_expiry( $document_id, $days );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signing deadline extended.', 'comsign' ) );
	}

	/**
	 * Mint a shareable signing link for a signer (copy / WhatsApp).
	 */
	/**
	 * In-person signing: refresh the signer's link, pre-verify the session (the
	 * present admin authorises it) and open the signing page directly.
	 */
	public function handle_sign_in_person(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_sign_in_person_' . $document_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;

		try {
			$url = $this->service->generate_link( $document_id, $signer_id );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
			return;
		}

		// Skip the identity challenge: the signer is physically present and the
		// authorised admin is initiating the session. Record it for the trail.
		$signer = $this->signers->find( $signer_id );
		if ( $signer ) {
			\ComSign\Frontend\SignerAuth::mark_verified( $signer );
			( new AuditLogger( $this->audit ) )->record(
				AuditLogger::EVENT_SENT,
				$document_id,
				$signer_id,
				array( 'channel' => 'in_person' )
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	public function handle_signer_link(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_signer_link_' . $document_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;

		try {
			$url = $this->service->generate_link( $document_id, $signer_id );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		// Stash the freshly minted link to render once on the edit screen.
		set_transient(
			$this->link_flash_key(),
			array( 'signer_id' => $signer_id, 'url' => $url ),
			60
		);

		$this->redirect_with_notice(
			$this->edit_url( $document_id ) . '#comsign-signer-' . $signer_id,
			'success',
			__( 'A signing link was generated. Copy it or share via WhatsApp below.', 'comsign' )
		);
	}

	public function handle_save_fields(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_save_fields_' . $document_id );

		$raw    = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed = json_decode( (string) $raw, true );
		$fields = is_array( $parsed ) ? $this->sanitize_fields( $parsed ) : array();

		$this->service->save_fields( $document_id, $fields );

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signature fields saved.', 'comsign' ) );
	}

	public function handle_send(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_send_' . $document_id );

		$options = array(
			'sequential'  => ! empty( $_POST['sequential'] ),
			'message'     => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
			'expiry_days' => isset( $_POST['expiry_days'] ) ? absint( wp_unslash( $_POST['expiry_days'] ) ) : 0,
		);

		try {
			$result = $this->service->send( $document_id, $options );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$emailed   = (int) ( $result['emailed'] ?? 0 );
		$link_only = (int) ( $result['link_only'] ?? 0 );

		if ( 0 === $emailed && $link_only > 0 ) {
			// Nothing was emailed — all remaining signers are link-only.
			$message = __( 'No emails were sent because the signer(s) have no email. Use "Get signing link" to share their link.', 'comsign' );
		} elseif ( $link_only > 0 ) {
			$message = sprintf(
				/* translators: 1: number emailed, 2: number of link-only signers. */
				__( 'Sent %1$d invitation(s). %2$d signer(s) have no email — share their link manually.', 'comsign' ),
				$emailed,
				$link_only
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of invitations sent. */
				__( 'Sent %d invitation(s).', 'comsign' ),
				$emailed
			);
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', $message );
	}

	public function handle_duplicate(): void {
		$this->guard();
		// Triggered from a row-action link (GET) or a form (POST).
		$document_id = isset( $_REQUEST['document_id'] ) ? absint( wp_unslash( $_REQUEST['document_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'comsign_duplicate_' . $document_id );

		try {
			$new_id = $this->service->duplicate( $document_id );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $new_id ), 'success', __( 'Document duplicated.', 'comsign' ) );
	}

	public function handle_delete(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_delete_' . $document_id );

		$this->service->delete( $document_id );

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign' ), 'success', __( 'Document deleted.', 'comsign' ) );
	}

	/**
	 * Generate and stream a standalone audit-trail PDF.
	 */
	public function handle_audit_pdf(): void {
		$this->guard();

		$document_id = isset( $_REQUEST['document_id'] ) ? absint( wp_unslash( $_REQUEST['document_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'comsign_audit_pdf_' . $document_id );
		$this->assert_document_access( $document_id );

		try {
			$path = $this->service->generate_audit_pdf( $document_id );
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="comsign-audit-' . $document_id . '.pdf"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $path );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$this->guard();

		// Enables the media picker for the brand logo.
		wp_enqueue_media();

		$this->view(
			'settings',
			array(
				'action_url'    => admin_url( 'admin-post.php' ),
				'nonce'         => wp_create_nonce( 'comsign_save_settings' ),
				'cert_nonce'    => wp_create_nonce( 'comsign_upload_certificate' ),
				'remove_nonce'  => wp_create_nonce( 'comsign_remove_certificate' ),
				'settings'      => Settings::all(),
				'pki_available' => \ComSign\Signature\Certificate::openssl_available(),
				'pki_subject'   => \ComSign\Signature\Certificate::is_configured() ? \ComSign\Signature\Certificate::subject() : '',
				'notice'        => $this->pull_notice(),
			)
		);
	}

	/**
	 * Store an uploaded PKCS#12 certificate for PAdES signing.
	 */
	public function handle_upload_certificate(): void {
		$this->guard();
		check_admin_referer( 'comsign_upload_certificate' );

		$password = isset( $_POST['cert_password'] ) ? (string) wp_unslash( $_POST['cert_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		try {
			$file = isset( $_FILES['certificate'] ) ? $_FILES['certificate'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			// Validate the upload using name/size/error — never the tmp path.
			if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				throw new \RuntimeException( __( 'Please choose a .p12/.pfx certificate file.', 'comsign' ) );
			}

			$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'p12', 'pfx' ), true ) ) {
				throw new \RuntimeException( __( 'The certificate must be a .p12 or .pfx file.', 'comsign' ) );
			}

			if ( (int) ( $file['size'] ?? 0 ) > 512 * KB_IN_BYTES ) {
				throw new \RuntimeException( __( 'The certificate file is too large (max 512 KB).', 'comsign' ) );
			}

			// Pass the tmp path through untouched (it is a system path, not input).
			\ComSign\Signature\Certificate::store( $file['tmp_name'], $password ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-settings' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-settings' ), 'success', __( 'Certificate stored. New documents will be signed with PKI/PAdES.', 'comsign' ) );
	}

	/**
	 * Remove the stored certificate (revert to electronic signatures).
	 */
	public function handle_remove_certificate(): void {
		$this->guard();
		check_admin_referer( 'comsign_remove_certificate' );

		\ComSign\Signature\Certificate::remove();

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-settings' ), 'success', __( 'Certificate removed. Documents will use electronic signatures.', 'comsign' ) );
	}

	/**
	 * Persist plugin settings.
	 */
	public function handle_save_settings(): void {
		$this->guard();
		check_admin_referer( 'comsign_save_settings' );

		Settings::update(
			array(
				'reminders_enabled' => ! empty( $_POST['reminders_enabled'] ),
				'reminder_days'     => isset( $_POST['reminder_days'] ) ? absint( wp_unslash( $_POST['reminder_days'] ) ) : 3,
				'webhook_url'       => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
				'regenerate_keys'   => ! empty( $_POST['regenerate_keys'] ),
				'brand_name'        => isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_name'] ) ) : '',
				'brand_logo_url'    => isset( $_POST['brand_logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['brand_logo_url'] ) ) : '',
				'brand_color'       => isset( $_POST['brand_color'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_color'] ) ) : '',
			)
		);

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-settings' ), 'success', __( 'Settings saved.', 'comsign' ) );
	}

	/**
	 * System status / health-check page.
	 */
	public function render_health_page(): void {
		$this->guard();

		$this->view(
			'health',
			array(
				'checks'     => \ComSign\Support\HealthCheck::run(),
				'action_url' => admin_url( 'admin-post.php' ),
				'nonce'      => wp_create_nonce( 'comsign_health_actions' ),
				'admin_mail' => wp_get_current_user()->user_email,
				'notice'     => $this->pull_notice(),
			)
		);
	}

	/**
	 * Send a test email to the current administrator.
	 */
	public function handle_test_email(): void {
		$this->guard();
		check_admin_referer( 'comsign_health_actions' );

		$to   = wp_get_current_user()->user_email;
		$sent = wp_mail(
			$to,
			__( 'ComSign test email', 'comsign' ),
			__( 'This is a test email from ComSign. If you received it, outgoing mail works on this server.', 'comsign' )
		);

		$url = admin_url( 'admin.php?page=comsign-health' );
		if ( $sent ) {
			/* translators: %s: email address. */
			$this->redirect_with_notice( $url, 'success', sprintf( __( 'Test email sent to %s.', 'comsign' ), $to ) );
		}
		$this->redirect_with_notice( $url, 'error', __( 'wp_mail() reported a failure. Check your mail configuration (e.g. an SMTP plugin).', 'comsign' ) );
	}

	/**
	 * Send a blocking test webhook and report the result.
	 */
	public function handle_test_webhook(): void {
		$this->guard();
		check_admin_referer( 'comsign_health_actions' );

		$result = ( new \ComSign\Integrations\Webhooks() )->send_test();
		$url     = admin_url( 'admin.php?page=comsign-health' );
		$this->redirect_with_notice( $url, $result['ok'] ? 'success' : 'error', $result['message'] );
	}

	/**
	 * Analytics dashboard.
	 */
	public function render_analytics_page(): void {
		$this->guard();

		$analytics = new \ComSign\Services\Analytics();

		$this->view(
			'analytics',
			array(
				'counts'       => $analytics->status_counts(),
				'total'        => $analytics->total(),
				'rate'         => $analytics->completion_rate(),
				'avg_seconds'  => $analytics->avg_completion_seconds(),
				'signers'      => $analytics->signer_stats(),
				'stuck'        => $analytics->stuck( 7 ),
				'series'       => $analytics->completions_by_day( 14 ),
				'edit_base'    => admin_url( 'admin.php?page=comsign&action=edit&document=' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------- */

	/**
	 * Templates screen: list, or the "use"/"bulk" sub-forms.
	 */
	public function render_templates_page(): void {
		$this->guard();

		$action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template_id = isset( $_GET['template'] ) ? absint( wp_unslash( $_GET['template'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ( 'use' === $action || 'bulk' === $action ) && $template_id ) {
			$template = $this->templates->find( $template_id );
			if ( ! $template ) {
				wp_die( esc_html__( 'Template not found.', 'comsign' ) );
			}
			$this->view(
				'use' === $action ? 'template-use' : 'template-bulk',
				array(
					'template'   => $template,
					'roles'      => \ComSign\Database\TemplateRepository::roles( $template ),
					'action_url' => admin_url( 'admin-post.php' ),
					'nonce'      => wp_create_nonce( ( 'use' === $action ? 'comsign_use_template_' : 'comsign_bulk_template_' ) . $template_id ),
					'notice'     => $this->pull_notice(),
				)
			);
			return;
		}

		$this->view(
			'templates',
			array(
				'templates' => $this->templates->all(),
				'base_url'  => admin_url( 'admin.php?page=comsign-templates' ),
				'action_url' => admin_url( 'admin-post.php' ),
				'notice'    => $this->pull_notice(),
			)
		);
	}

	public function handle_save_template(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_save_template_' . $document_id );

		$name = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';

		try {
			$this->service->save_as_template( $document_id, $name );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-templates' ), 'success', __( 'Template saved.', 'comsign' ) );
	}

	public function handle_use_template(): void {
		$this->guard();
		$template_id = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
		check_admin_referer( 'comsign_use_template_' . $template_id );

		$recipients = $this->sanitize_recipients( $_POST['recipients'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		try {
			$document_id = $this->service->create_from_template( $template_id, $recipients );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-templates' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Document created from template. Review and send.', 'comsign' ) );
	}

	public function handle_bulk_template(): void {
		$this->guard();
		$template_id = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
		check_admin_referer( 'comsign_bulk_template_' . $template_id );

		$rows    = isset( $_POST['recipients_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['recipients_text'] ) ) : '';
		$options = array(
			'message'     => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
			'expiry_days' => isset( $_POST['expiry_days'] ) ? absint( wp_unslash( $_POST['expiry_days'] ) ) : 0,
		);

		$recipients = $this->parse_bulk_recipients( $rows );

		try {
			$count = $this->service->bulk_from_template( $template_id, $recipients, $options );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-templates' ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice(
			admin_url( 'admin.php?page=comsign' ),
			'success',
			sprintf(
				/* translators: %d: number of documents sent. */
				__( 'Bulk send complete: %d document(s) created and sent.', 'comsign' ),
				$count
			)
		);
	}

	public function handle_delete_template(): void {
		$this->guard();
		$template_id = isset( $_REQUEST['template_id'] ) ? absint( wp_unslash( $_REQUEST['template_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'comsign_delete_template_' . $template_id );

		$this->service->delete_template( $template_id );

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign-templates' ), 'success', __( 'Template deleted.', 'comsign' ) );
	}

	/**
	 * Sanitise the per-role recipients array from the "use template" form.
	 *
	 * @param mixed $raw Raw recipients input.
	 */
	private function sanitize_recipients( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $index => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$out[ (int) $index ] = array(
				'name'  => isset( $r['name'] ) ? sanitize_text_field( wp_unslash( $r['name'] ) ) : '',
				'email' => isset( $r['email'] ) ? sanitize_email( wp_unslash( $r['email'] ) ) : '',
				'phone' => isset( $r['phone'] ) ? $this->sanitize_phone( wp_unslash( $r['phone'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);
		}
		return $out;
	}

	/**
	 * Parse a "name, email, phone" per-line bulk recipients block.
	 *
	 * @param string $raw Raw textarea contents.
	 */
	private function parse_bulk_recipients( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ',', $line ) );
			$out[] = array(
				'name'  => isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '',
				'email' => isset( $parts[1] ) ? sanitize_email( $parts[1] ) : '',
				'phone' => isset( $parts[2] ) ? $this->sanitize_phone( $parts[2] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Stream a stored PDF (source or signed) to an authorised admin.
	 */
	public function handle_stream(): void {
		$this->guard();

		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0;
		$which       = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : 'source';

		check_admin_referer( 'comsign_stream_' . $document_id . '_' . $which );

		$document = $this->assert_document_access( $document_id );

		$path = 'signed' === $which ? $document->signed_path : $document->source_path;

		if ( ! $path || ! Storage::is_within_base( $path ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not available.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		$this->stream_file( $path, sanitize_file_name( $document->title ?: 'document' ) . '.pdf' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Stream a file inline with no-cache headers, then exit.
	 *
	 * @param string $path     Absolute file path (already validated).
	 * @param string $filename Download filename.
	 */
	private function stream_file( string $path, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Sanitise a raw $_FILES entry into typed primitives.
	 *
	 * @param array $file Raw $_FILES entry.
	 */
	private function sanitize_files( array $file ): array {
		return array(
			'name'     => isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '',
			'type'     => isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '',
			'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
			'error'    => isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
		);
	}

	/**
	 * Sanitise field definitions coming from the editor JSON.
	 *
	 * @param array $fields Raw decoded fields.
	 */
	private function sanitize_fields( array $fields ): array {
		$allowed_types = array(
			FieldRepository::TYPE_SIGNATURE,
			FieldRepository::TYPE_INITIALS,
			FieldRepository::TYPE_DATE,
			FieldRepository::TYPE_TEXT,
			FieldRepository::TYPE_NUMBER,
			FieldRepository::TYPE_CHECKBOX,
			FieldRepository::TYPE_CHOICE,
			FieldRepository::TYPE_ATTACHMENT,
			FieldRepository::TYPE_NAME,
			FieldRepository::TYPE_EMAIL,
		);

		$clean = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : FieldRepository::TYPE_SIGNATURE;

			// Choice fields carry a list of options the signer can pick from.
			$options = null;
			if ( FieldRepository::TYPE_CHOICE === $type && isset( $field['options'] ) && is_array( $field['options'] ) ) {
				$options = array();
				foreach ( $field['options'] as $opt ) {
					$opt = sanitize_text_field( (string) $opt );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
			}

			$clean[] = array(
				'signer_id' => isset( $field['signer_id'] ) ? absint( $field['signer_id'] ) : 0,
				'type'      => in_array( $type, $allowed_types, true ) ? $type : FieldRepository::TYPE_SIGNATURE,
				'required'  => ! empty( $field['required'] ),
				'page'      => isset( $field['page'] ) ? max( 1, absint( $field['page'] ) ) : 1,
				'pos_x'     => isset( $field['pos_x'] ) ? (float) $field['pos_x'] : 0.0,
				'pos_y'     => isset( $field['pos_y'] ) ? (float) $field['pos_y'] : 0.0,
				'width'     => isset( $field['width'] ) ? (float) $field['width'] : 0.0,
				'height'    => isset( $field['height'] ) ? (float) $field['height'] : 0.0,
				'label'     => isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '',
				'help_text' => isset( $field['help_text'] ) ? sanitize_text_field( (string) $field['help_text'] ) : '',
				'options'   => $options,
			);
		}

		return $clean;
	}

	/**
	 * Ensure the current user may manage documents.
	 */
	private function guard(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'comsign' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Read and validate the posted document id.
	 */
	private function posted_document_id(): int {
		$id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id > 0 ) {
			// Tenant authorization for every document POST handler.
			$this->assert_document_access( $id );
		}
		return $id;
	}

	/**
	 * Build the edit-screen URL for a document.
	 */
	private function edit_url( int $document_id ): string {
		return admin_url( 'admin.php?page=comsign&action=edit&document=' . $document_id );
	}

	/**
	 * Build a nonce-protected stream URL for a document file.
	 *
	 * @param int    $document_id Document id.
	 * @param string $which       'source' or 'signed'.
	 */
	private function stream_url( int $document_id, string $which ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=comsign_stream&document=' . $document_id . '&file=' . $which ),
			'comsign_stream_' . $document_id . '_' . $which
		);
	}

	/**
	 * Redirect with a flash notice and stop.
	 *
	 * @param string $url     Target URL.
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message text.
	 */
	private function redirect_with_notice( string $url, string $type, string $message ): void {
		set_transient( $this->notice_key(), array( 'type' => $type, 'message' => $message ), 30 );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Retrieve and clear the flash notice for the current user.
	 */
	private function pull_notice(): ?array {
		$notice = get_transient( $this->notice_key() );
		if ( $notice ) {
			delete_transient( $this->notice_key() );
			return is_array( $notice ) ? $notice : null;
		}
		return null;
	}

	private function notice_key(): string {
		return 'comsign_notice_' . get_current_user_id();
	}

	private function link_flash_key(): string {
		return 'comsign_link_' . get_current_user_id();
	}

	/**
	 * Retrieve and clear the one-time signing-link flash.
	 *
	 * @return array{signer_id:int,url:string}|null
	 */
	private function pull_link_flash(): ?array {
		$flash = get_transient( $this->link_flash_key() );
		if ( $flash ) {
			delete_transient( $this->link_flash_key() );
			return is_array( $flash ) ? $flash : null;
		}
		return null;
	}

	/**
	 * Parse a "name = value" per-line variables block into a map.
	 *
	 * @param string $raw Raw textarea contents.
	 *
	 * @return array<string,string>
	 */
	private function parse_variables( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $line, 2 );
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', trim( $key ) );
			if ( '' !== $key ) {
				$out[ $key ] = trim( $value );
			}
		}
		return $out;
	}

	/**
	 * Sanitise a phone number: keep digits and a single leading '+'.
	 *
	 * @param string $raw Raw input.
	 */
	private function sanitize_phone( string $raw ): string {
		$raw    = trim( $raw );
		$plus   = ( '' !== $raw && '+' === $raw[0] ) ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $raw );
		return substr( $plus . (string) $digits, 0, 40 );
	}

	/**
	 * Render an admin view from includes/Admin/views.
	 *
	 * @param string $name Template name (without extension).
	 * @param array  $data Variables exposed to the template.
	 */
	private function view( string $name, array $data = array() ): void {
		$file = COMSIGN_PLUGIN_DIR . 'includes/Admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		// Expose $data keys as locals for the template.
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		require $file;
	}
}
