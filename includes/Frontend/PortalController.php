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

use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\AccountService;

/**
 * Routes and renders the client portal shell: dashboard, documents and a
 * read-only document detail.
 */
final class PortalController {

	private DocumentRepository $documents;
	private SignerRepository $signers;
	private AccountService $accounts;

	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->signers   = new SignerRepository();
		$this->accounts  = new AccountService();
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
		} else {
			$this->render_dashboard( $user_id, $account_ids );
		}
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
				'nav'        => $this->nav( 'dashboard' ),
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
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 20;
		$total   = $this->documents->count_for_accounts( $account_ids );
		$rows    = $this->documents->paginate_for_accounts( $account_ids, $per, ( $page - 1 ) * $per );

		$this->render(
			'portal-documents',
			array(
				'page_title' => __( 'Documents', 'comsign' ),
				'nav'        => $this->nav( 'documents' ),
				'documents'  => $this->decorate( $rows ),
				'page'       => $page,
				'pages'      => max( 1, (int) ceil( $total / $per ) ),
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

		$this->render(
			'portal-document',
			array(
				'page_title' => $document->title ? $document->title : __( 'Document', 'comsign' ),
				'nav'        => $this->nav( 'documents' ),
				'document'   => $document,
				'signers'    => $this->signers->for_document( (int) $document->id ),
				'switcher'   => $this->switcher( $user_id ),
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
	private function nav( string $active ): array {
		return array(
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
