<?php
/**
 * Front-end client portal (/comsign/app).
 *
 * A logged-in account user works here instead of WP Admin. Everything is scoped
 * to the accounts the user may see (their memberships + descendant sub-accounts
 * for managers), so a user never sees another tenant's documents.
 *
 * @package ComSign
 */

namespace ComSign\Frontend;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\ContactRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Database\TemplateRepository;
use ComSign\Billing\CardcomSettings;
use ComSign\Billing\Plans;
use ComSign\Database\AccountRepository;
use ComSign\Services\AccountService;
use ComSign\Services\BillingService;
use ComSign\Services\DocumentService;
use ComSign\Services\SendReadiness;
use ComSign\Services\SubscriptionService;
use ComSign\Support\Roles;
use ComSign\Support\Storage;

/**
 * Routes and renders the client portal shell: dashboard, documents, document
 * detail, and (for users with the right role) creating and sending documents
 * from their account's templates.
 */
final class PortalController {

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private TemplateRepository $templates;
	private FieldRepository $fields;
	private ContactRepository $contacts;
	private AccountService $accounts;
	private DocumentService $service;

	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->signers   = new SignerRepository();
		$this->templates = new TemplateRepository();
		$this->fields    = new FieldRepository();
		$this->contacts  = new ContactRepository();
		$this->accounts  = new AccountService();
		$this->service   = new DocumentService();
	}

	/**
	 * Register hooks + the /comsign/app route.
	 */
	public function register(): void {
		add_action( 'init', array( __CLASS__, 'register_route' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		// Switcher for portal users (login required, no admin capability needed).
		add_action( 'admin_post_comsign_portal_switch', array( $this, 'handle_switch' ) );
		// Create/send and per-document actions, all RBAC-checked at the service layer.
		add_action( 'admin_post_comsign_portal_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_comsign_portal_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_comsign_portal_download', array( $this, 'handle_download' ) );
		// Build-your-own document: upload a PDF, manage signers, place fields.
		add_action( 'admin_post_comsign_portal_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_comsign_portal_add_signer', array( $this, 'handle_add_signer' ) );
		add_action( 'admin_post_comsign_portal_delete_signer', array( $this, 'handle_delete_signer' ) );
		add_action( 'admin_post_comsign_portal_save_fields', array( $this, 'handle_save_fields' ) );
		// Address book management.
		add_action( 'admin_post_comsign_portal_contact_add', array( $this, 'handle_contact_add' ) );
		add_action( 'admin_post_comsign_portal_contact_delete', array( $this, 'handle_contact_delete' ) );
		// Billing: start a Cardcom checkout for a plan.
		add_action( 'admin_post_comsign_portal_checkout', array( $this, 'handle_checkout' ) );
		// Team management.
		add_action( 'admin_post_comsign_portal_invite', array( $this, 'handle_invite' ) );
		add_action( 'admin_post_comsign_portal_member_role', array( $this, 'handle_member_role' ) );
		add_action( 'admin_post_comsign_portal_member_remove', array( $this, 'handle_member_remove' ) );
	}

	/**
	 * Switch the current user's working account from the portal.
	 */
	public function handle_switch(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		check_admin_referer( 'comsign_switch_account' );

		$account_id = isset( $_POST['account_id'] ) ? absint( wp_unslash( $_POST['account_id'] ) ) : 0;
		// set_current_account only succeeds if the user is a member of the account.
		$this->accounts->set_current_account( get_current_user_id(), $account_id );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Require a logged-in user for an admin-post action, or bounce to login.
	 */
	private function require_login(): int {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		return get_current_user_id();
	}

	/**
	 * Create a document from a template (and optionally send it), or send an
	 * existing draft. All gated by the user's role in their working account.
	 */
	public function handle_create(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_create' );

		$send = ! empty( $_POST['send'] );

		// Branch: sending an existing draft vs. creating from a template.
		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		if ( $document_id ) {
			$document = $this->documents->find( $document_id );
			if ( ! $document || ! $this->accounts->can_for_document( $user_id, $document, Roles::SEND_DOCUMENTS ) ) {
				$this->bounce( self::url(), __( 'You cannot send this document.', 'comsign' ) );
			}
			$this->send_document( $document_id );
			$this->bounce( self::url( array( 'view' => 'document', 'doc' => $document_id ) ), __( 'Document sent for signing.', 'comsign' ), 'success' );
		}

		// Create from template.
		$account_id  = $this->accounts->current_account_id( $user_id );
		if ( $account_id <= 0 || ! $this->accounts->can_in_account( $user_id, $account_id, Roles::CREATE_DOCUMENTS ) ) {
			$this->bounce( self::url(), __( 'You cannot create documents in this workspace.', 'comsign' ) );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;
		$template    = $template_id ? $this->templates->find( $template_id ) : null;

		// The template must belong to the exact workspace we are creating in —
		// never merely a "visible" descendant/parent — so a template can't be
		// copied across tenant boundaries.
		if ( ! $template || (int) $template->account_id !== $account_id ) {
			$this->bounce( self::url( array( 'view' => 'create' ) ), __( 'Please choose one of your templates.', 'comsign' ) );
		}

		// Collect recipients, indexed by role.
		$recipients = array();
		$posted     = isset( $_POST['recipient'] ) && is_array( $_POST['recipient'] ) ? wp_unslash( $_POST['recipient'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( $posted as $index => $r ) {
			$recipients[ (int) $index ] = array(
				'name'  => isset( $r['name'] ) ? sanitize_text_field( $r['name'] ) : '',
				'email' => isset( $r['email'] ) ? sanitize_email( $r['email'] ) : '',
				'phone' => isset( $r['phone'] ) ? sanitize_text_field( $r['phone'] ) : '',
			);
		}

		try {
			$new_id = $this->service->create_from_template( $template_id, $recipients );
		} catch ( \Throwable $e ) {
			$this->bounce( self::url( array( 'view' => 'create', 'template' => $template_id ) ), $e->getMessage() );
		}

		if ( $send && $this->accounts->can_in_account( $user_id, $account_id, Roles::SEND_DOCUMENTS ) ) {
			$this->send_document( $new_id );
			$this->bounce( self::url( array( 'view' => 'document', 'doc' => $new_id ) ), __( 'Document created and sent for signing.', 'comsign' ), 'success' );
		}

		$this->bounce( self::url( array( 'view' => 'document', 'doc' => $new_id ) ), __( 'Draft created.', 'comsign' ), 'success' );
	}

	/**
	 * Send a document, guarding it with the readiness checklist first.
	 */
	private function send_document( int $document_id ): void {
		$readiness = ( new SendReadiness() )->check( $document_id );
		if ( ! $readiness['ready'] ) {
			foreach ( $readiness['items'] as $item ) {
				if ( ! $item['ok'] ) {
					$this->bounce( self::url( array( 'view' => 'document', 'doc' => $document_id ) ), $item['hint'] );
				}
			}
		}
		try {
			$this->service->send( $document_id );
		} catch ( \Throwable $e ) {
			$this->bounce( self::url( array( 'view' => 'document', 'doc' => $document_id ) ), $e->getMessage() );
		}
	}

	/**
	 * Re-send the invitation to a single signer.
	 */
	public function handle_resend(): void {
		$user_id     = $this->require_login();
		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'comsign_portal_resend_' . $document_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;
		$document  = $this->documents->find( $document_id );
		$target    = self::url( array( 'view' => 'document', 'doc' => $document_id ) );

		if ( ! $document || ! $this->accounts->can_for_document( $user_id, $document, Roles::SEND_DOCUMENTS ) ) {
			$this->bounce( self::url(), __( 'You cannot resend invitations for this document.', 'comsign' ) );
		}

		try {
			$this->service->resend_signer( $document_id, $signer_id );
		} catch ( \Throwable $e ) {
			$this->bounce( $target, $e->getMessage() );
		}

		$this->bounce( $target, __( 'Invitation re-sent.', 'comsign' ), 'success' );
	}

	/**
	 * Stream the original or signed PDF to a portal user who may see it.
	 */
	public function handle_download(): void {
		$user_id     = $this->require_login();
		$document_id = isset( $_GET['doc'] ) ? absint( wp_unslash( $_GET['doc'] ) ) : 0;
		check_admin_referer( 'comsign_portal_download_' . $document_id );

		$which    = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : 'source';
		$document = $this->documents->find( $document_id );

		if ( ! $document || ! $this->accounts->can_access_document( $document, $user_id ) ) {
			wp_die( esc_html__( 'File not available.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		$path = 'signed' === $which ? $document->signed_path : $document->source_path;
		if ( ! $path || ! Storage::is_within_base( $path ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not available.', 'comsign' ), '', array( 'response' => 404 ) );
		}

		$suffix   = 'signed' === $which ? '-signed' : '';
		$filename = sanitize_file_name( ( $document->title ?: 'document' ) . $suffix ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Upload a PDF and start a draft, then jump into the field editor.
	 */
	public function handle_upload(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_upload' );

		$account_id = $this->accounts->current_account_id( $user_id );
		if ( $account_id <= 0 || ! $this->accounts->can_in_account( $user_id, $account_id, Roles::CREATE_DOCUMENTS ) ) {
			$this->bounce( self::url(), __( 'You cannot create documents in this workspace.', 'comsign' ) );
		}

		if ( empty( $_FILES['document'] ) || ! is_array( $_FILES['document'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->bounce( self::url( array( 'view' => 'create' ) ), __( 'Please choose a PDF to upload.', 'comsign' ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		// $_FILES is validated inside the service (mime, magic bytes, size cap).
		$file  = array_map( 'wp_unslash', $_FILES['document'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		try {
			$doc_id = $this->service->create_from_upload( $file, $title );
		} catch ( \Throwable $e ) {
			$this->bounce( self::url( array( 'view' => 'create' ) ), $e->getMessage() );
		}

		$this->bounce(
			self::url( array( 'view' => 'edit', 'doc' => $doc_id ) ),
			__( 'PDF uploaded. Now add signers and place fields.', 'comsign' ),
			'success'
		);
	}

	/**
	 * Resolve a draft the current user may edit, or redirect away.
	 */
	private function require_editable_doc( int $user_id, int $doc_id ): object {
		$document = $this->documents->find( $doc_id );
		if ( ! $document
			|| ! $this->accounts->can_access_document( $document, $user_id )
			|| ! $this->accounts->can_for_document( $user_id, $document, Roles::CREATE_DOCUMENTS ) ) {
			$this->bounce( self::url(), __( 'You cannot edit this document.', 'comsign' ) );
		}
		if ( DocumentRepository::STATUS_DRAFT !== $document->status ) {
			$this->bounce(
				self::url( array( 'view' => 'document', 'doc' => $doc_id ) ),
				__( 'This document has already been sent and can no longer be edited.', 'comsign' )
			);
		}
		return $document;
	}

	/**
	 * Add a signer to a draft from the portal editor.
	 */
	public function handle_add_signer(): void {
		$user_id = $this->require_login();
		$doc_id  = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'comsign_portal_add_signer_' . $doc_id );
		$this->require_editable_doc( $user_id, $doc_id );

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone  = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$target = self::url( array( 'view' => 'edit', 'doc' => $doc_id ) );

		try {
			$this->service->add_signer( $doc_id, $name, $email, $phone );
		} catch ( \Throwable $e ) {
			$this->bounce( $target, $e->getMessage() );
		}

		$this->bounce( $target, __( 'Signer added.', 'comsign' ), 'success' );
	}

	/**
	 * Remove a signer from a draft.
	 */
	public function handle_delete_signer(): void {
		$user_id = $this->require_login();
		$doc_id  = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'comsign_portal_delete_signer_' . $doc_id );
		$this->require_editable_doc( $user_id, $doc_id );

		$signer_id = isset( $_POST['signer_id'] ) ? absint( wp_unslash( $_POST['signer_id'] ) ) : 0;
		$target    = self::url( array( 'view' => 'edit', 'doc' => $doc_id ) );

		try {
			$this->service->delete_signer( $doc_id, $signer_id );
		} catch ( \Throwable $e ) {
			$this->bounce( $target, $e->getMessage() );
		}

		$this->bounce( $target, __( 'Signer removed.', 'comsign' ), 'success' );
	}

	/**
	 * Save the placed fields for a draft (serialised by the shared editor JS).
	 */
	public function handle_save_fields(): void {
		$user_id = $this->require_login();
		$doc_id  = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'comsign_portal_save_fields_' . $doc_id );
		$this->require_editable_doc( $user_id, $doc_id );

		$raw    = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed = json_decode( (string) $raw, true );
		$fields = is_array( $parsed ) ? FieldRepository::sanitize_payload( $parsed ) : array();

		$this->service->save_fields( $doc_id, $fields );

		$this->bounce(
			self::url( array( 'view' => 'edit', 'doc' => $doc_id ) ),
			__( 'Fields saved.', 'comsign' ),
			'success'
		);
	}

	/**
	 * Redirect back to the portal with a flash notice.
	 *
	 * The message is stashed in a short-lived per-user transient (read once on
	 * the next page load) rather than a query parameter, so user-facing text —
	 * including mapped exception messages — never lands in the URL, browser
	 * history, server logs, analytics or referrers.
	 *
	 * @param string $url  Target portal URL (no notice params added).
	 * @param string $msg  Message text.
	 * @param string $type 'error' or 'success'.
	 */
	private function bounce( string $url, string $msg, string $type = 'error' ): void {
		self::set_flash( get_current_user_id(), $msg, $type );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store a one-shot flash message for a user.
	 */
	public static function set_flash( int $user_id, string $msg, string $type = 'error' ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			'comsign_flash_' . $user_id,
			array(
				'msg'  => $msg,
				'type' => 'success' === $type ? 'success' : 'error',
			),
			60
		);
	}

	/**
	 * Read and clear the current user's flash message (or null).
	 *
	 * @return array{msg:string,type:string}|null
	 */
	public static function take_flash(): ?array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$key   = 'comsign_flash_' . $user_id;
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['msg'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'msg'  => (string) $flash['msg'],
			'type' => 'success' === ( $flash['type'] ?? '' ) ? 'success' : 'error',
		);
	}

	/**
	 * Pretty rewrite for the portal (also reachable via ?comsign_app=1).
	 */
	public static function register_route(): void {
		add_rewrite_rule( '^comsign/app/?$', 'index.php?comsign_app=1', 'top' );
	}

	/**
	 * @param string[] $vars Query vars.
	 *
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'comsign_app';
		return $vars;
	}

	/**
	 * Portal URL helper.
	 *
	 * @param array $args Extra query args (view, doc...).
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'comsign_app' => '1' ), $args ), home_url( '/' ) );
	}

	/**
	 * Render the portal if this request targets it.
	 */
	public function maybe_render(): void {
		if ( empty( $_GET['comsign_app'] ) && ! get_query_var( 'comsign_app' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Sign in', 'comsign' ),
					'heading'    => __( 'Please sign in', 'comsign' ),
					'message'    => __( 'You need to sign in to access your workspace.', 'comsign' ),
					'login_url'  => wp_login_url( self::url() ),
				)
			);
		}

		$user_id     = get_current_user_id();
		$account_ids = $this->accounts->visible_account_ids( $user_id );

		if ( ! $account_ids ) {
			// Claim any pending invitations and, failing that, create a personal
			// workspace so a brand-new user (e.g. via Google sign-in) can start.
			$this->accounts->ensure_onboarded( $user_id );
			$account_ids = $this->accounts->visible_account_ids( $user_id );
		}

		if ( ! $account_ids ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'No workspace', 'comsign' ),
					'heading'    => __( 'No workspace yet', 'comsign' ),
					'message'    => __( 'Your account is not part of any workspace. Please contact your administrator.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'document' === $view ) {
			$this->render_document( $user_id, $account_ids );
		} elseif ( 'documents' === $view ) {
			$this->render_documents( $user_id, $account_ids );
		} elseif ( 'create' === $view ) {
			$this->render_create( $user_id, $account_ids );
		} elseif ( 'edit' === $view ) {
			$this->render_edit( $user_id, $account_ids );
		} elseif ( 'contacts' === $view ) {
			$this->render_contacts( $user_id, $account_ids );
		} elseif ( 'billing' === $view ) {
			$this->render_billing( $user_id, $account_ids );
		} elseif ( 'team' === $view ) {
			$this->render_team( $user_id, $account_ids );
		} else {
			$this->render_dashboard( $user_id, $account_ids );
		}
	}

	/**
	 * Whether the user may create/send in their current working account.
	 */
	private function can_create( int $user_id ): bool {
		$account_id = $this->accounts->current_account_id( $user_id );
		return $account_id > 0 && $this->accounts->can_in_account( $user_id, $account_id, Roles::CREATE_DOCUMENTS );
	}

	/**
	 * The single workspace the user is currently acting in.
	 *
	 * Creation-side data (new documents, templates, contacts, address book) is
	 * scoped to this account — never to the wider "visible" set that includes
	 * descendant workspaces. Descendants remain read-only oversight in the
	 * document list; they are not implicit creation/copy targets, which keeps
	 * tenant boundaries airtight.
	 *
	 * @return int Account id (0 when none).
	 */
	private function working_account_id( int $user_id ): int {
		return max( 0, (int) $this->accounts->current_account_id( $user_id ) );
	}

	/**
	 * Whether the user may manage billing for their current workspace
	 * (owner-level: the MANAGE_SETTINGS permission).
	 */
	private function can_manage_billing( int $user_id ): bool {
		$account_id = $this->working_account_id( $user_id );
		return $account_id > 0 && $this->accounts->can_in_account( $user_id, $account_id, Roles::MANAGE_SETTINGS );
	}

	/**
	 * Whether the user may manage team members of their current workspace.
	 */
	private function can_manage_members( int $user_id ): bool {
		$account_id = $this->working_account_id( $user_id );
		return $account_id > 0 && $this->accounts->can_in_account( $user_id, $account_id, Roles::MANAGE_MEMBERS );
	}

	/**
	 * The working account as a single-element scope array (or empty).
	 *
	 * @return int[]
	 */
	private function working_scope( int $user_id ): array {
		$id = $this->working_account_id( $user_id );
		return $id > 0 ? array( $id ) : array();
	}

	/**
	 * "New document" flow: pick a template, then fill recipients and send.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_create( int $user_id, array $account_ids ): void {
		if ( ! $this->can_create( $user_id ) ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not allowed', 'comsign' ),
					'heading'    => __( 'You cannot create documents', 'comsign' ),
					'message'    => __( 'Your role in this workspace does not allow creating documents. Please contact an administrator.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$scope       = $this->working_scope( $user_id );
		$templates   = $this->templates->for_accounts( $scope );
		$template_id = isset( $_GET['template'] ) ? absint( wp_unslash( $_GET['template'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected    = null;
		$roles       = array();

		if ( $template_id ) {
			foreach ( $templates as $t ) {
				if ( (int) $t->id === $template_id ) {
					$selected = $t;
					break;
				}
			}
			if ( $selected ) {
				$roles = TemplateRepository::roles( $selected );
			}
		}

		$this->render(
			'portal-create',
			array(
				'page_title' => __( 'New document', 'comsign' ),
				'nav'        => $this->nav( 'create', $user_id ),
				'templates'  => $templates,
				'selected'   => $selected,
				'roles'      => $roles,
				'contacts'   => $this->contacts->for_accounts( $scope ),
				'action'       => admin_url( 'admin-post.php' ),
				'nonce'        => wp_create_nonce( 'comsign_portal_create' ),
				'upload_nonce' => wp_create_nonce( 'comsign_portal_upload' ),
				'switcher'     => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Address book: list, search, add and remove account contacts.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_contacts( int $user_id, array $account_ids ): void {
		if ( ! $this->can_create( $user_id ) ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not allowed', 'comsign' ),
					'heading'    => __( 'Contacts are not available', 'comsign' ),
					'message'    => __( 'Your role in this workspace does not allow managing contacts.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$search = isset( $_GET['cs'] ) ? sanitize_text_field( wp_unslash( $_GET['cs'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->render(
			'portal-contacts',
			array(
				'page_title'    => __( 'Contacts', 'comsign' ),
				'nav'           => $this->nav( 'contacts', $user_id ),
				'contacts'      => $this->contacts->for_accounts( $this->working_scope( $user_id ), $search, 300 ),
				'search'        => $search,
				'action'        => admin_url( 'admin-post.php' ),
				'add_nonce'     => wp_create_nonce( 'comsign_portal_contact_add' ),
				'delete_nonce'  => wp_create_nonce( 'comsign_portal_contact_delete' ),
				'switcher'      => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Add (or update) a contact in the current workspace.
	 */
	public function handle_contact_add(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_contact_add' );

		$account_id = $this->accounts->current_account_id( $user_id );
		if ( $account_id <= 0 || ! $this->accounts->can_in_account( $user_id, $account_id, Roles::CREATE_DOCUMENTS ) ) {
			$this->bounce( self::url(), __( 'You cannot manage contacts in this workspace.', 'comsign' ) );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$target = self::url( array( 'view' => 'contacts' ) );
		if ( 0 === $this->contacts->upsert( $account_id, $name, $email, $phone ) ) {
			$this->bounce( $target, __( 'Please provide a valid email address for the contact.', 'comsign' ) );
		}

		$this->bounce( $target, __( 'Contact saved.', 'comsign' ), 'success' );
	}

	/**
	 * Delete a contact belonging to the user's current workspace.
	 */
	public function handle_contact_delete(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_contact_delete' );

		$id      = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
		$contact = $id ? $this->contacts->find( $id ) : null;
		$account = $this->working_account_id( $user_id );
		$target  = self::url( array( 'view' => 'contacts' ) );

		// The contact must belong to the exact workspace being managed.
		if ( ! $contact || $account <= 0 || (int) $contact->account_id !== $account ) {
			$this->bounce( $target, __( 'That contact could not be found in your workspace.', 'comsign' ) );
		}

		$this->contacts->delete( $id );
		$this->bounce( $target, __( 'Contact removed.', 'comsign' ), 'success' );
	}

	/**
	 * Build-your-own editor: manage signers and place fields on a draft PDF.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_edit( int $user_id, array $account_ids ): void {
		$doc_id   = isset( $_GET['doc'] ) ? absint( wp_unslash( $_GET['doc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$document = $this->documents->find( $doc_id );

		$can_edit = $document
			&& $this->accounts->can_access_document( $document, $user_id )
			&& $this->accounts->can_for_document( $user_id, $document, Roles::CREATE_DOCUMENTS );

		if ( ! $can_edit ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not available', 'comsign' ),
					'heading'    => __( 'Document not available', 'comsign' ),
					'message'    => __( 'This document is not available for editing in your workspace.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		// Editing fields/signers only makes sense before the document is sent.
		if ( DocumentRepository::STATUS_DRAFT !== $document->status ) {
			$this->bounce(
				self::url( array( 'view' => 'document', 'doc' => $doc_id ) ),
				__( 'This document has already been sent and can no longer be edited.', 'comsign' )
			);
		}

		$signers = $this->signers->for_document( $doc_id );
		$fields  = $this->fields->for_document( $doc_id );

		$fields_for_js = array();
		foreach ( $fields as $field ) {
			$fields_for_js[] = array(
				'signer_id' => (int) $field->signer_id,
				'type'      => (string) $field->type,
				'required'  => ! empty( $field->required ),
				'page'      => (int) $field->page,
				'pos_x'     => (float) $field->pos_x,
				'pos_y'     => (float) $field->pos_y,
				'width'     => (float) $field->width,
				'height'    => (float) $field->height,
				'label'     => (string) ( $field->label ?? '' ),
				'help_text' => (string) ( $field->help_text ?? '' ),
				'options'   => FieldRepository::decode_options( $field ),
			);
		}

		$signers_for_js = array();
		foreach ( $signers as $signer ) {
			$signers_for_js[] = array(
				'id'   => (int) $signer->id,
				'name' => (string) ( $signer->name ?: $signer->email ),
			);
		}

		$this->render(
			'portal-edit',
			array(
				'page_title'     => $document->title ? $document->title : __( 'Edit document', 'comsign' ),
				'nav'            => $this->nav( 'create', $user_id ),
				'document'       => $document,
				// Address-book autocomplete is scoped to THIS document's workspace,
				// never the wider visible set.
				'contacts'       => $this->contacts->for_accounts( array( (int) $document->account_id ) ),
				'signers'        => $signers,
				'fields_for_js'  => $fields_for_js,
				'signers_for_js' => $signers_for_js,
				'readiness'      => ( new SendReadiness() )->check( $doc_id ),
				'switcher'       => $this->switcher( $user_id ),
				'action'         => admin_url( 'admin-post.php' ),
				'pdf_url'        => wp_nonce_url(
					admin_url( 'admin-post.php?action=comsign_portal_download&doc=' . $doc_id . '&file=source' ),
					'comsign_portal_download_' . $doc_id
				),
				'worker_src'     => COMSIGN_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
				'pdfjs_src'      => COMSIGN_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.min.js',
				'editor_src'     => COMSIGN_PLUGIN_URL . 'assets/js/admin-editor.js',
				'editor_css'     => COMSIGN_PLUGIN_URL . 'assets/css/editor.css',
				'nonces'         => array(
					'add_signer'    => wp_create_nonce( 'comsign_portal_add_signer_' . $doc_id ),
					'delete_signer' => wp_create_nonce( 'comsign_portal_delete_signer_' . $doc_id ),
					'save_fields'   => wp_create_nonce( 'comsign_portal_save_fields_' . $doc_id ),
					'send'          => wp_create_nonce( 'comsign_portal_create' ),
				),
			)
		);
	}

	/**
	 * Team: list members + pending invites, invite, change role, remove.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_team( int $user_id, array $account_ids ): void {
		if ( ! $this->can_manage_members( $user_id ) ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not allowed', 'comsign' ),
					'heading'    => __( 'Team management is not available', 'comsign' ),
					'message'    => __( 'Your role in this workspace does not allow managing the team.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$account = $this->working_account_id( $user_id );
		$repo    = $this->accounts->repository();

		// Decorate members with their display name + email.
		$members = array();
		foreach ( $repo->members_of( $account ) as $m ) {
			$u = get_userdata( (int) $m->user_id );
			$members[] = array(
				'user_id' => (int) $m->user_id,
				'role'    => (string) $m->role,
				'name'    => $u ? $u->display_name : '',
				'email'   => $u ? $u->user_email : '',
				'is_self' => (int) $m->user_id === $user_id,
			);
		}

		$invites = ( new \ComSign\Database\InviteRepository() )->for_account( $account );

		$this->render(
			'portal-team',
			array(
				'page_title' => __( 'Team', 'comsign' ),
				'nav'        => $this->nav( 'team', $user_id ),
				'members'    => $members,
				'invites'    => $invites,
				'roles'      => Roles::assignable(),
				'action'     => admin_url( 'admin-post.php' ),
				'nonce'      => wp_create_nonce( 'comsign_portal_team' ),
				'switcher'   => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Resolve and authorise the working account for a team mutation.
	 */
	private function require_member_management( int $user_id ): int {
		$account = $this->working_account_id( $user_id );
		if ( $account <= 0 || ! $this->accounts->can_in_account( $user_id, $account, Roles::MANAGE_MEMBERS ) ) {
			$this->bounce( self::url(), __( 'You cannot manage the team for this workspace.', 'comsign' ) );
		}
		return $account;
	}

	/**
	 * Invite a member (adds an existing user immediately, else records an invite).
	 */
	public function handle_invite(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_team' );
		$account = $this->require_member_management( $user_id );

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$role   = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$target = self::url( array( 'view' => 'team' ) );

		try {
			$outcome = $this->accounts->invite( $account, $email, $role );
		} catch ( \Throwable $e ) {
			$this->bounce( $target, $e->getMessage() );
		}

		$this->bounce(
			$target,
			'added' === $outcome ? __( 'Member added.', 'comsign' ) : __( 'Invitation saved. The user will join when they sign in with this email.', 'comsign' ),
			'success'
		);
	}

	/**
	 * Change a member's role.
	 */
	public function handle_member_role(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_team' );
		$account = $this->require_member_management( $user_id );
		$repo    = $this->accounts->repository();

		$target_user = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$role        = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$target      = self::url( array( 'view' => 'team' ) );

		if ( ! array_key_exists( $role, Roles::assignable() ) || $target_user <= 0 ) {
			$this->bounce( $target, __( 'Please provide a valid email and role.', 'comsign' ) );
		}

		// Never let the last owner be demoted away.
		if ( AccountRepository::ROLE_OWNER === $repo->role_of( $account, $target_user ) && AccountRepository::ROLE_OWNER !== $role && $this->owner_count( $account ) <= 1 ) {
			$this->bounce( $target, __( 'You cannot remove the last owner of a workspace.', 'comsign' ) );
		}

		$repo->add_member( $account, $target_user, $role ); // add_member upserts the role.
		$this->bounce( $target, __( 'Role updated.', 'comsign' ), 'success' );
	}

	/**
	 * Remove a member (or cancel a pending invite when an email is given).
	 */
	public function handle_member_remove(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_team' );
		$account = $this->require_member_management( $user_id );
		$repo    = $this->accounts->repository();
		$target  = self::url( array( 'view' => 'team' ) );

		// Cancel a pending invitation.
		$invite_email = isset( $_POST['invite_email'] ) ? sanitize_email( wp_unslash( $_POST['invite_email'] ) ) : '';
		if ( '' !== $invite_email ) {
			( new \ComSign\Database\InviteRepository() )->delete_for( $account, $invite_email );
			$this->bounce( $target, __( 'Invitation cancelled.', 'comsign' ), 'success' );
		}

		$target_user = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( AccountRepository::ROLE_OWNER === $repo->role_of( $account, $target_user ) && $this->owner_count( $account ) <= 1 ) {
			$this->bounce( $target, __( 'You cannot remove the last owner of a workspace.', 'comsign' ) );
		}

		$repo->remove_member( $account, $target_user );
		$this->bounce( $target, __( 'Member removed.', 'comsign' ), 'success' );
	}

	/**
	 * Count the owners of an account.
	 */
	private function owner_count( int $account_id ): int {
		$owners = array_filter(
			$this->accounts->repository()->members_of( $account_id ),
			static fn( $m ) => AccountRepository::ROLE_OWNER === $m->role
		);
		return count( $owners );
	}

	/**
	 * Plan & billing: subscription status, monthly usage and the plan picker.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_billing( int $user_id, array $account_ids ): void {
		if ( ! $this->can_manage_billing( $user_id ) ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not allowed', 'comsign' ),
					'heading'    => __( 'Billing is not available', 'comsign' ),
					'message'    => __( 'Only a workspace owner can manage the plan and billing.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$account = $this->working_account_id( $user_id );
		$subs    = new SubscriptionService();

		$this->render(
			'portal-billing',
			array(
				'page_title'  => __( 'Plan & billing', 'comsign' ),
				'nav'         => $this->nav( 'billing', $user_id ),
				'status'      => $subs->status( $account ),
				'subscription' => $subs->for_account( $account ),
				'plan_id'     => $subs->plan_id( $account ),
				'plans'       => Plans::all(),
				'currency'    => Plans::currency(),
				'used'        => $subs->documents_used( $account ),
				'remaining'   => $subs->documents_remaining( $account ),
				'configured'  => CardcomSettings::is_configured(),
				'action'      => admin_url( 'admin-post.php' ),
				'nonce'       => wp_create_nonce( 'comsign_portal_checkout' ),
				'switcher'    => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Start a Cardcom checkout for the chosen plan/cycle and redirect to the
	 * hosted payment page.
	 */
	public function handle_checkout(): void {
		$user_id = $this->require_login();
		check_admin_referer( 'comsign_portal_checkout' );

		$account = $this->working_account_id( $user_id );
		if ( $account <= 0 || ! $this->accounts->can_in_account( $user_id, $account, Roles::MANAGE_SETTINGS ) ) {
			$this->bounce( self::url(), __( 'You cannot manage billing for this workspace.', 'comsign' ) );
		}

		$plan   = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$cycle  = ( isset( $_POST['cycle'] ) && 'annual' === $_POST['cycle'] ) ? 'annual' : 'monthly';
		$target = self::url( array( 'view' => 'billing' ) );

		if ( ! Plans::exists( $plan ) ) {
			$this->bounce( $target, __( 'Please choose a valid plan.', 'comsign' ) );
		}
		if ( ! CardcomSettings::is_configured() ) {
			$this->bounce( $target, __( 'Billing is not set up yet. Please contact support.', 'comsign' ) );
		}

		$result = ( new BillingService() )->start_checkout(
			$account,
			$plan,
			$cycle,
			array(
				'success_url' => self::url( array( 'view' => 'billing', 'paid' => '1' ) ),
				'failed_url'  => self::url( array( 'view' => 'billing', 'paid' => '0' ) ),
				'webhook_url' => BillingController::webhook_url(),
			),
			is_rtl() ? 'he' : 'en'
		);

		if ( empty( $result['ok'] ) || empty( $result['url'] ) ) {
			$this->bounce( $target, ! empty( $result['error'] ) ? (string) $result['error'] : __( 'Could not start checkout.', 'comsign' ) );
		}

		// Off-site to the Cardcom hosted payment page.
		wp_redirect( esc_url_raw( (string) $result['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Dashboard: status summary + items needing attention + recent documents.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_dashboard( int $user_id, array $account_ids ): void {
		$recent = $this->documents->paginate_for_accounts( $account_ids, 8, 0 );

		$this->render(
			'portal-dashboard',
			array(
				'page_title' => __( 'Workspace', 'comsign' ),
				'nav'        => $this->nav( 'dashboard', $user_id ),
				'counts'     => $this->documents->status_counts_for_accounts( $account_ids ),
				'recent'     => $this->decorate( $recent ),
				'switcher'   => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Full (paginated) document list.
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_documents( int $user_id, array $account_ids ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Only allow filtering by a real document status.
		$statuses = array(
			DocumentRepository::STATUS_DRAFT,
			DocumentRepository::STATUS_SENT,
			DocumentRepository::STATUS_SIGNED,
			DocumentRepository::STATUS_COMPLETED,
			DocumentRepository::STATUS_DECLINED,
		);
		if ( ! in_array( $status, $statuses, true ) ) {
			$status = '';
		}

		$per   = 20;
		$total = $this->documents->count_for_accounts( $account_ids, $search, $status );
		$rows  = $this->documents->paginate_for_accounts( $account_ids, $per, ( $page - 1 ) * $per, $search, $status );

		$this->render(
			'portal-documents',
			array(
				'page_title' => __( 'Documents', 'comsign' ),
				'nav'        => $this->nav( 'documents', $user_id ),
				'documents'  => $this->decorate( $rows ),
				'page'       => $page,
				'pages'      => max( 1, (int) ceil( $total / $per ) ),
				'search'     => $search,
				'status'     => $status,
				'statuses'   => $statuses,
				'total'      => $total,
				'switcher'   => $this->switcher( $user_id ),
			)
		);
	}

	/**
	 * Read-only document detail (status + signer progress).
	 *
	 * @param int   $user_id     Current user.
	 * @param int[] $account_ids Visible accounts.
	 */
	private function render_document( int $user_id, array $account_ids ): void {
		$doc_id   = isset( $_GET['doc'] ) ? absint( wp_unslash( $_GET['doc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$document = $this->documents->find( $doc_id );

		if ( ! $document || ! $this->accounts->can_access_document( $document, $user_id ) ) {
			$this->render(
				'portal-message',
				array(
					'page_title' => __( 'Not found', 'comsign' ),
					'heading'    => __( 'Document not available', 'comsign' ),
					'message'    => __( 'This document does not exist in your workspace.', 'comsign' ),
					'login_url'  => '',
				)
			);
		}

		$doc_id    = (int) $document->id;
		$can_send  = $this->accounts->can_for_document( $user_id, $document, Roles::SEND_DOCUMENTS );
		$is_draft  = DocumentRepository::STATUS_DRAFT === $document->status;
		$has_signed = $document->signed_path && Storage::is_within_base( $document->signed_path ) && is_file( $document->signed_path );

		$this->render(
			'portal-document',
			array(
				'page_title'    => $document->title ? $document->title : __( 'Document', 'comsign' ),
				'nav'           => $this->nav( 'documents', $user_id ),
				'document'      => $document,
				'signers'       => $this->signers->for_document( $doc_id ),
				'switcher'      => $this->switcher( $user_id ),
				'can_send'      => $can_send,
				'is_draft'      => $is_draft,
				'readiness'     => ( $is_draft && $can_send ) ? ( new SendReadiness() )->check( $doc_id ) : null,
				'has_signed'    => (bool) $has_signed,
				'action'        => admin_url( 'admin-post.php' ),
				'download_url'  => static fn( string $which ) => wp_nonce_url(
					admin_url( 'admin-post.php?action=comsign_portal_download&doc=' . $doc_id . '&file=' . $which ),
					'comsign_portal_download_' . $doc_id
				),
				'resend_nonce'  => wp_create_nonce( 'comsign_portal_resend_' . $doc_id ),
				'send_nonce'    => wp_create_nonce( 'comsign_portal_create' ),
			)
		);
	}

	/**
	 * Attach signer progress (signed/total) to each document row.
	 *
	 * @param array $rows Document rows.
	 */
	private function decorate( array $rows ): array {
		foreach ( $rows as $row ) {
			$signers        = $this->signers->for_document( (int) $row->id );
			$row->signer_total  = count( $signers );
			$row->signer_signed = count(
				array_filter(
					$signers,
					static fn( $s ) => SignerRepository::STATUS_SIGNED === $s->status
				)
			);
		}
		return $rows;
	}

	/**
	 * Build the portal nav links.
	 *
	 * @param string $active Current view.
	 *
	 * @return array<int,array{label:string,url:string,active:bool}>
	 */
	private function nav( string $active, int $user_id = 0 ): array {
		$items = array(
			array(
				'label'  => __( 'Dashboard', 'comsign' ),
				'url'    => self::url(),
				'active' => 'dashboard' === $active,
			),
			array(
				'label'  => __( 'Documents', 'comsign' ),
				'url'    => self::url( array( 'view' => 'documents' ) ),
				'active' => 'documents' === $active,
			),
		);

		if ( $user_id && $this->can_create( $user_id ) ) {
			$items[] = array(
				'label'  => __( 'New document', 'comsign' ),
				'url'    => self::url( array( 'view' => 'create' ) ),
				'active' => 'create' === $active,
			);
			$items[] = array(
				'label'  => __( 'Contacts', 'comsign' ),
				'url'    => self::url( array( 'view' => 'contacts' ) ),
				'active' => 'contacts' === $active,
			);
		}

		if ( $user_id && $this->can_manage_members( $user_id ) ) {
			$items[] = array(
				'label'  => __( 'Team', 'comsign' ),
				'url'    => self::url( array( 'view' => 'team' ) ),
				'active' => 'team' === $active,
			);
		}

		if ( $user_id && $this->can_manage_billing( $user_id ) ) {
			$items[] = array(
				'label'  => __( 'Plan & billing', 'comsign' ),
				'url'    => self::url( array( 'view' => 'billing' ) ),
				'active' => 'billing' === $active,
			);
		}

		return $items;
	}

	/**
	 * Account switcher data (or null when the user has a single account).
	 *
	 * @return array|null
	 */
	private function switcher( int $user_id ): ?array {
		$accounts = $this->accounts->accounts_for_user( $user_id );
		if ( count( $accounts ) < 2 ) {
			return null;
		}
		return array(
			'accounts' => $accounts,
			'current'  => $this->accounts->current_account_id( $user_id ),
			'nonce'    => wp_create_nonce( 'comsign_switch_account' ),
			'action'   => admin_url( 'admin-post.php' ),
			'handler'  => 'comsign_portal_switch',
		);
	}

	/**
	 * Render a portal view (header + view + footer) and exit.
	 *
	 * @param string $name Template name under views/.
	 * @param array  $data Template data.
	 */
	private function render( string $name, array $data ): void {
		$file = COMSIGN_PLUGIN_DIR . 'includes/Frontend/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Portal template missing.', 'comsign' ) );
		}
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		require $file;
		exit;
	}
}
