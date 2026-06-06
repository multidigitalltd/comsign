<?php
/**
 * Reusable account-scoped document table for the portal.
 *
 * @package ComSign
 *
 * @var array $documents Decorated document rows (signer_total/signer_signed).
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $documents ) ) {
	echo '<p class="comsign-empty">' . esc_html__( 'No documents to show. Start by uploading a PDF in the admin.', 'comsign' ) . '</p>';
	return;
}
?>
<table class="comsign-portal-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Document', 'comsign' ); ?></th>
			<th><?php esc_html_e( 'Status', 'comsign' ); ?></th>
			<th><?php esc_html_e( 'Signed', 'comsign' ); ?></th>
			<th><?php esc_html_e( 'Created', 'comsign' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $documents as $doc ) : ?>
			<tr>
				<td>
					<a href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'document', 'doc' => (int) $doc->id ) ) ); ?>">
						<?php echo esc_html( $doc->title ? $doc->title : __( '(untitled)', 'comsign' ) ); ?>
					</a>
				</td>
				<td><span class="comsign-pill comsign-pill--<?php echo esc_attr( $doc->status ); ?>"><?php echo esc_html( ucfirst( (string) $doc->status ) ); ?></span></td>
				<td><?php echo esc_html( (int) $doc->signer_signed . ' / ' . (int) $doc->signer_total ); ?></td>
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $doc->created_at ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
