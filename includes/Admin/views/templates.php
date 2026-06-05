<?php
/**
 * Templates list view.
 *
 * @package ComSign
 *
 * @var array      $templates
 * @var string     $base_url
 * @var string     $action_url
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Database\TemplateRepository;
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Templates', 'comsign' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Save a document as a template from its management screen, then reuse it here for new documents or bulk sending.', 'comsign' ); ?></p>

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<?php if ( empty( $templates ) ) : ?>
		<div class="comsign-card"><p><?php esc_html_e( 'No templates yet.', 'comsign' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Template', 'comsign' ); ?></th>
					<th><?php esc_html_e( 'Roles', 'comsign' ); ?></th>
					<th><?php esc_html_e( 'Created', 'comsign' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'comsign' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $templates as $template ) :
					$roles    = TemplateRepository::roles( $template );
					$use_url  = add_query_arg( array( 'action' => 'use', 'template' => (int) $template->id ), $base_url );
					$bulk_url = add_query_arg( array( 'action' => 'bulk', 'template' => (int) $template->id ), $base_url );
					$del_url  = wp_nonce_url(
						admin_url( 'admin-post.php?action=comsign_delete_template&template_id=' . (int) $template->id ),
						'comsign_delete_template_' . (int) $template->id
					);
					?>
					<tr>
						<td><strong><?php echo esc_html( $template->name ?: __( '(untitled)', 'comsign' ) ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', $roles ) ); ?></td>
						<td><?php echo esc_html( (string) $template->created_at ); ?></td>
						<td>
							<a class="button button-primary" href="<?php echo esc_url( $use_url ); ?>"><?php esc_html_e( 'Use', 'comsign' ); ?></a>
							<?php if ( 1 === count( $roles ) ) : ?>
								<a class="button" href="<?php echo esc_url( $bulk_url ); ?>"><?php esc_html_e( 'Bulk send', 'comsign' ); ?></a>
							<?php endif; ?>
							<a class="button button-link-delete" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this template?', 'comsign' ) ); ?>');"><?php esc_html_e( 'Delete', 'comsign' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
