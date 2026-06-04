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
	private DocumentService $service;

	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->signers   = new SignerRepository();
		$this->fields    = new FieldRepository();
		$this->audit     = new AuditRepository();
		$this->service   = new DocumentService();
	}

	/**
	 * Hook the admin controller into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_comsign_create_document', array( $this, 'handle_create_document' ) );
		add_action( 'admin_post_comsign_add_signer', array( $this, 'handle_add_signer' ) );
		add_action( 'admin_post_comsign_save_fields', array( $this, 'handle_save_fields' ) );
		add_action( 'admin_post_comsign_send', array( $this, 'handle_send' ) );
		add_action( 'admin_post_comsign_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_comsign_stream', array( $this, 'handle_stream' ) );
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
					'signature' => __( 'Signature', 'comsign' ),
					'date'      => __( 'Date', 'comsign' ),
					'remove'    => __( 'Remove', 'comsign' ),
					'loading'   => __( 'Loading document…', 'comsign' ),
					'loadError' => __( 'Could not load the document preview.', 'comsign' ),
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
		$table = new DocumentsListTable( $this->documents, $this->signers );
		$table->prepare_items();

		$this->view(
			'list',
			array(
				'table'    => $table,
				'new_url'  => admin_url( 'admin.php?page=comsign-new' ),
				'notice'   => $this->pull_notice(),
			)
		);
	}

	public function render_new_page(): void {
		$this->guard();

		$this->view(
			'new',
			array(
				'action_url' => admin_url( 'admin-post.php' ),
				'nonce'      => wp_create_nonce( 'comsign_create_document' ),
				'notice'     => $this->pull_notice(),
			)
		);
	}

	private function render_edit_page( int $document_id ): void {
		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			wp_die( esc_html__( 'Document not found.', 'comsign' ) );
		}

		$this->view(
			'edit',
			array(
				'document'   => $document,
				'signers'    => $this->signers->for_document( $document_id ),
				'fields'     => $this->fields->for_document( $document_id ),
				'audit'      => $this->audit->for_document( $document_id ),
				'action_url' => admin_url( 'admin-post.php' ),
				'nonces'     => array(
					'add_signer'  => wp_create_nonce( 'comsign_add_signer_' . $document_id ),
					'save_fields' => wp_create_nonce( 'comsign_save_fields_' . $document_id ),
					'send'        => wp_create_nonce( 'comsign_send_' . $document_id ),
					'delete'      => wp_create_nonce( 'comsign_delete_' . $document_id ),
				),
				'download'   => array(
					'source' => $this->stream_url( $document_id, 'source' ),
					'signed' => $this->stream_url( $document_id, 'signed' ),
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

	public function handle_add_signer(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_add_signer_' . $document_id );

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		try {
			$this->service->add_signer( $document_id, $name, $email );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Signer added.', 'comsign' ) );
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

		try {
			$this->service->send( $document_id );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( $this->edit_url( $document_id ), 'error', $e->getMessage() );
		}

		$this->redirect_with_notice( $this->edit_url( $document_id ), 'success', __( 'Invitations sent to all signers.', 'comsign' ) );
	}

	public function handle_delete(): void {
		$this->guard();
		$document_id = $this->posted_document_id();
		check_admin_referer( 'comsign_delete_' . $document_id );

		$this->service->delete( $document_id );

		$this->redirect_with_notice( admin_url( 'admin.php?page=comsign' ), 'success', __( 'Document deleted.', 'comsign' ) );
	}

	/**
	 * Stream a stored PDF (source or signed) to an authorised admin.
	 */
	public function handle_stream(): void {
		$this->guard();

		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0;
		$which       = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : 'source';

		check_admin_referer( 'comsign_stream_' . $document_id . '_' . $which );

		$document = $this->documents->find( $document_id );
		if ( ! $document ) {
			wp_die( esc_html__( 'Document not found.', 'comsign' ), '', array( 'response' => 404 ) );
		}

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
		);

		$clean = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : FieldRepository::TYPE_SIGNATURE;
			$clean[] = array(
				'signer_id' => isset( $field['signer_id'] ) ? absint( $field['signer_id'] ) : 0,
				'type'      => in_array( $type, $allowed_types, true ) ? $type : FieldRepository::TYPE_SIGNATURE,
				'page'      => isset( $field['page'] ) ? max( 1, absint( $field['page'] ) ) : 1,
				'pos_x'     => isset( $field['pos_x'] ) ? (float) $field['pos_x'] : 0.0,
				'pos_y'     => isset( $field['pos_y'] ) ? (float) $field['pos_y'] : 0.0,
				'width'     => isset( $field['width'] ) ? (float) $field['width'] : 0.0,
				'height'    => isset( $field['height'] ) ? (float) $field['height'] : 0.0,
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
		return isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
