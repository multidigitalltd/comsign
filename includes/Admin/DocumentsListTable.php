<?php
/**
 * Documents list table.
 *
 * @package ComSign
 */

namespace ComSign\Admin;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the paginated list of documents in the admin.
 */
final class DocumentsListTable extends \WP_List_Table {

	private DocumentRepository $documents;
	private SignerRepository $signers;

	/**
	 * Account ids the current user may see (tenant scope).
	 *
	 * @var int[]
	 */
	private array $account_ids;

	/**
	 * @param int[] $account_ids Visible account ids for the current user.
	 */
	public function __construct( DocumentRepository $documents, SignerRepository $signers, array $account_ids = array() ) {
		parent::__construct(
			array(
				'singular' => 'comsign_document',
				'plural'   => 'comsign_documents',
				'ajax'     => false,
			)
		);

		$this->documents   = $documents;
		$this->signers     = $signers;
		$this->account_ids = array_map( 'intval', $account_ids );
	}

	public function get_columns(): array {
		return array(
			'title'   => __( 'Document', 'comsign' ),
			'status'  => __( 'Status', 'comsign' ),
			'signers' => __( 'Signers', 'comsign' ),
			'created' => __( 'Created', 'comsign' ),
		);
	}

	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		// Search + status filter (the list page verifies the page nonce/cap).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, $this->filterable_statuses(), true ) ) {
			$status = '';
		}

		$total = $this->documents->count_for_accounts( $this->account_ids, $search, $status );

		$this->items = $this->documents->paginate_for_accounts( $this->account_ids, $per_page, ( $current_page - 1 ) * $per_page, $search, $status );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Statuses offered in the filter dropdown.
	 *
	 * @return string[]
	 */
	private function filterable_statuses(): array {
		return array(
			DocumentRepository::STATUS_DRAFT,
			DocumentRepository::STATUS_SENT,
			DocumentRepository::STATUS_SIGNED,
			DocumentRepository::STATUS_COMPLETED,
			DocumentRepository::STATUS_DECLINED,
		);
	}

	/**
	 * Status filter dropdown above the table (left of the pagination).
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="comsign-filter-status">' . esc_html__( 'Filter by status', 'comsign' ) . '</label>';
		echo '<select name="status" id="comsign-filter-status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'comsign' ) . '</option>';
		foreach ( $this->filterable_statuses() as $st ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $st ),
				selected( $current, $st, false ),
				esc_html( self::status_label( $st ) )
			);
		}
		echo '</select>';
		submit_button( __( 'Filter', 'comsign' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Title column with row actions.
	 *
	 * @param object $item Document row.
	 */
	public function column_title( $item ): string {
		$edit_url = admin_url( 'admin.php?page=comsign&action=edit&document=' . (int) $item->id );

		$title = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_url ),
			esc_html( $item->title ?: __( '(untitled)', 'comsign' ) )
		);

		$duplicate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=comsign_duplicate&document_id=' . (int) $item->id ),
			'comsign_duplicate_' . (int) $item->id
		);

		$actions = array(
			'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Manage', 'comsign' ) ),
			'duplicate' => sprintf( '<a href="%s">%s</a>', esc_url( $duplicate_url ), esc_html__( 'Duplicate', 'comsign' ) ),
		);

		if ( DocumentRepository::STATUS_COMPLETED === $item->status ) {
			$actions['view'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url . '#comsign-signed' ),
				esc_html__( 'Signed copy', 'comsign' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Status column with a coloured badge.
	 *
	 * @param object $item Document row.
	 */
	public function column_status( $item ): string {
		return sprintf(
			'<span class="comsign-badge comsign-badge--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( self::status_label( $item->status ) )
		);
	}

	/**
	 * Signers summary column.
	 *
	 * @param object $item Document row.
	 */
	public function column_signers( $item ): string {
		$signers = $this->signers->for_document( (int) $item->id );
		if ( empty( $signers ) ) {
			return '<span aria-hidden="true">—</span>';
		}

		$signed = 0;
		foreach ( $signers as $signer ) {
			if ( SignerRepository::STATUS_SIGNED === $signer->status ) {
				++$signed;
			}
		}

		return esc_html(
			sprintf(
				/* translators: 1: signed count, 2: total count. */
				__( '%1$d of %2$d signed', 'comsign' ),
				$signed,
				count( $signers )
			)
		);
	}

	/**
	 * Created-at column.
	 *
	 * @param object $item Document row.
	 */
	public function column_created( $item ): string {
		$ts = strtotime( (string) $item->created_at );
		return $ts ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $ts ) ) : '';
	}

	public function no_items(): void {
		esc_html_e( 'No documents yet. Upload your first PDF to get started.', 'comsign' );
	}

	/**
	 * Translate a status slug to a human label.
	 *
	 * @param string $status Status slug.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			DocumentRepository::STATUS_DRAFT     => __( 'Draft', 'comsign' ),
			DocumentRepository::STATUS_SENT      => __( 'Sent', 'comsign' ),
			DocumentRepository::STATUS_VIEWED    => __( 'Viewed', 'comsign' ),
			DocumentRepository::STATUS_SIGNED    => __( 'Partially signed', 'comsign' ),
			DocumentRepository::STATUS_COMPLETED => __( 'Completed', 'comsign' ),
			DocumentRepository::STATUS_DECLINED  => __( 'Declined', 'comsign' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}
}
