<?php
/**
 * Document edit / manage view.
 *
 * @package ComSign
 *
 * @var object     $document
 * @var array      $signers
 * @var array      $fields
 * @var array      $audit
 * @var string     $action_url
 * @var array      $nonces
 * @var array      $download
 * @var array|null $notice
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Admin\DocumentsListTable;
use ComSign\Database\DocumentRepository;
use ComSign\Database\SignerRepository;

$document_id = (int) $document->id;
$is_draft    = DocumentRepository::STATUS_DRAFT === $document->status;
$is_complete = DocumentRepository::STATUS_COMPLETED === $document->status;

// Prepare field data for the editor (page index is 1-based).
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
		'options'   => \ComSign\Database\FieldRepository::decode_options( $field ),
	);
}

$signers_for_js = array();
foreach ( $signers as $signer ) {
	$signers_for_js[] = array(
		'id'    => (int) $signer->id,
		'name'  => (string) ( $signer->name ?: $signer->email ),
	);
}
?>
<div class="wrap comsign-wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $document->title ?: __( '(untitled)', 'comsign' ) ); ?></h1>
	<span class="comsign-badge comsign-badge--<?php echo esc_attr( $document->status ); ?>">
		<?php echo esc_html( DocumentsListTable::status_label( $document->status ) ); ?>
	</span>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=comsign' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'comsign' ); ?></a>
	<hr class="wp-header-end">

	<?php require __DIR__ . '/partials/notice.php'; ?>

	<div class="comsign-grid">
		<div class="comsign-col-main">

			<?php if ( $is_complete && $document->signed_path ) : ?>
				<div class="comsign-card" id="comsign-signed">
					<h2><?php esc_html_e( 'Signed document', 'comsign' ); ?></h2>
					<p><?php esc_html_e( 'All parties have signed. The signed PDF and verification details are available below.', 'comsign' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $download['signed'] ); ?>"><?php esc_html_e( 'Download signed PDF', 'comsign' ); ?></a>
						<a class="button" href="<?php echo esc_url( $download['source'] ); ?>"><?php esc_html_e( 'Original PDF', 'comsign' ); ?></a>
					</p>
					<?php if ( $document->signed_hash ) : ?>
						<p class="comsign-hash">
							<strong><?php esc_html_e( 'SHA-256:', 'comsign' ); ?></strong>
							<code><?php echo esc_html( $document->signed_hash ); ?></code>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="comsign-card">
				<h2><?php esc_html_e( 'Place signature fields', 'comsign' ); ?></h2>
				<?php if ( empty( $signers ) ) : ?>
					<p class="description"><?php esc_html_e( 'Add at least one signer first, then drop signature fields onto the document.', 'comsign' ); ?></p>
				<?php else : ?>
					<div class="comsign-editor-toolbar">
						<label for="comsign-active-signer"><?php esc_html_e( 'Field for:', 'comsign' ); ?></label>
						<select id="comsign-active-signer">
							<?php foreach ( $signers as $signer ) : ?>
								<option value="<?php echo esc_attr( (int) $signer->id ); ?>">
									<?php echo esc_html( $signer->name ?: $signer->email ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select id="comsign-field-type">
							<option value="signature"><?php esc_html_e( 'Signature', 'comsign' ); ?></option>
							<option value="initials"><?php esc_html_e( 'Initials', 'comsign' ); ?></option>
							<option value="date"><?php esc_html_e( 'Date (auto)', 'comsign' ); ?></option>
							<option value="name"><?php esc_html_e( 'Name (auto)', 'comsign' ); ?></option>
							<option value="email"><?php esc_html_e( 'Email (auto)', 'comsign' ); ?></option>
							<option value="text"><?php esc_html_e( 'Text', 'comsign' ); ?></option>
							<option value="number"><?php esc_html_e( 'Number', 'comsign' ); ?></option>
							<option value="checkbox"><?php esc_html_e( 'Checkbox', 'comsign' ); ?></option>
							<option value="choice"><?php esc_html_e( 'Choice (dropdown)', 'comsign' ); ?></option>
							<option value="attachment"><?php esc_html_e( 'File upload', 'comsign' ); ?></option>
						</select>
						<label class="comsign-required-toggle"><input type="checkbox" id="comsign-field-required"> <?php esc_html_e( 'Required', 'comsign' ); ?></label>
						<button type="button" class="button" id="comsign-add-field"><?php esc_html_e( 'Add field', 'comsign' ); ?></button>
						<span class="description"><?php esc_html_e( 'Drag to reposition; double-click to remove; click a field label to toggle “required”.', 'comsign' ); ?></span>
					</div>

					<div id="comsign-pdf" class="comsign-pdf" aria-live="polite"></div>

					<form method="post" action="<?php echo esc_url( $action_url ); ?>">
						<input type="hidden" name="action" value="comsign_save_fields">
						<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['save_fields'] ); ?>">
						<input type="hidden" name="fields" id="comsign-fields-input" value="">
						<p><button type="submit" class="button button-primary" id="comsign-save-fields"><?php esc_html_e( 'Save fields', 'comsign' ); ?></button></p>
					</form>

					<script type="application/json" id="comsign-existing-fields"><?php echo wp_json_encode( $fields_for_js ); ?></script>
					<script type="application/json" id="comsign-signers"><?php echo wp_json_encode( $signers_for_js ); ?></script>
				<?php endif; ?>
			</div>

			<?php
			// Map signer ids to names for the timeline.
			$signer_names = array();
			foreach ( $signers as $sg ) {
				$signer_names[ (int) $sg->id ] = $sg->name ? $sg->name : $sg->email;
			}
			$expires_label = '';
			if ( ! empty( $document->expires_at ) ) {
				$expires_label = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $document->expires_at ) );
			}
			?>
			<div class="comsign-card">
				<h2><?php esc_html_e( 'Timeline', 'comsign' ); ?></h2>

				<p>
					<strong><?php esc_html_e( 'Status:', 'comsign' ); ?></strong>
					<?php echo esc_html( ucfirst( (string) $document->status ) ); ?>
					<?php if ( '' !== $expires_label ) : ?>
						&nbsp;·&nbsp;<strong><?php esc_html_e( 'Expires:', 'comsign' ); ?></strong> <?php echo esc_html( $expires_label ); ?>
					<?php endif; ?>
				</p>

				<?php if ( empty( $audit ) ) : ?>
					<p class="description"><?php esc_html_e( 'No events recorded yet.', 'comsign' ); ?></p>
				<?php else : ?>
					<ol class="comsign-timeline">
						<?php foreach ( array_reverse( $audit ) as $entry ) : ?>
							<?php $who = ! empty( $entry->signer_id ) && isset( $signer_names[ (int) $entry->signer_id ] ) ? $signer_names[ (int) $entry->signer_id ] : ''; ?>
							<li class="comsign-timeline-item comsign-tl--<?php echo esc_attr( $entry->event ); ?>">
								<span class="comsign-tl-event"><?php echo esc_html( \ComSign\Audit\AuditLogger::label( (string) $entry->event ) ); ?></span>
								<?php if ( '' !== $who ) : ?>
									<span class="comsign-tl-who"><?php echo esc_html( $who ); ?></span>
								<?php endif; ?>
								<span class="comsign-tl-when"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( (string) $entry->created_at ) ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>

				<div class="comsign-timeline-actions">
					<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-extend-form">
						<input type="hidden" name="action" value="comsign_extend_expiry">
						<input type="hidden" name="document_id" value="<?php echo (int) $document->id; ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['extend_expiry'] ); ?>">
						<label for="comsign-extend-days"><?php esc_html_e( 'Extend deadline by', 'comsign' ); ?></label>
						<select name="days" id="comsign-extend-days">
							<option value="7"><?php esc_html_e( '7 days', 'comsign' ); ?></option>
							<option value="14"><?php esc_html_e( '14 days', 'comsign' ); ?></option>
							<option value="30"><?php esc_html_e( '30 days', 'comsign' ); ?></option>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Extend', 'comsign' ); ?></button>
					</form>
					<a class="button" href="<?php echo esc_url( $download['audit_pdf'] ); ?>"><?php esc_html_e( 'Download audit trail (PDF)', 'comsign' ); ?></a>
				</div>
			</div>

			<div class="comsign-card">
				<h2><?php esc_html_e( 'Audit trail', 'comsign' ); ?></h2>
				<?php if ( empty( $audit ) ) : ?>
					<p class="description"><?php esc_html_e( 'No events recorded yet.', 'comsign' ); ?></p>
				<?php else : ?>
					<table class="widefat striped comsign-audit">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Event', 'comsign' ); ?></th>
								<th><?php esc_html_e( 'When', 'comsign' ); ?></th>
								<th><?php esc_html_e( 'IP', 'comsign' ); ?></th>
								<th><?php esc_html_e( 'Browser', 'comsign' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $audit as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( \ComSign\Audit\AuditLogger::label( (string) $entry->event ) ); ?></td>
									<td><?php echo esc_html( $entry->created_at ); ?></td>
									<td><?php echo esc_html( $entry->ip ); ?></td>
									<td class="comsign-ua"><?php echo esc_html( $entry->user_agent ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="comsign-col-side">
			<div class="comsign-card">
				<h2><?php esc_html_e( 'Signers', 'comsign' ); ?></h2>
				<?php if ( empty( $signers ) ) : ?>
					<p class="description"><?php esc_html_e( 'No signers yet.', 'comsign' ); ?></p>
				<?php else : ?>
					<ul class="comsign-signers">
						<?php foreach ( $signers as $signer ) : ?>
							<li id="comsign-signer-<?php echo esc_attr( (int) $signer->id ); ?>">
								<div class="comsign-signer-head">
									<span class="comsign-signer-name"><?php echo esc_html( $signer->name ?: '—' ); ?></span>
									<span class="comsign-badge comsign-badge--<?php echo esc_attr( $signer->status ); ?>"><?php echo esc_html( $signer->status ); ?></span>
								</div>
								<span class="comsign-signer-email"><?php echo esc_html( $signer->email ); ?></span>
								<?php if ( ! empty( $signer->phone ) ) : ?>
									<span class="comsign-signer-email"><?php echo esc_html( $signer->phone ); ?></span>
								<?php endif; ?>

								<div class="comsign-signer-actions">
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-mini-form">
										<input type="hidden" name="action" value="comsign_signer_link">
										<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
										<input type="hidden" name="signer_id" value="<?php echo esc_attr( (int) $signer->id ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['signer_link'] ); ?>">
										<button type="submit" class="button-link"><?php esc_html_e( 'Get signing link', 'comsign' ); ?></button>
									</form>
									<?php if ( SignerRepository::STATUS_SIGNED !== $signer->status ) : ?>
										<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-mini-form">
											<input type="hidden" name="action" value="comsign_sign_in_person">
											<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
											<input type="hidden" name="signer_id" value="<?php echo esc_attr( (int) $signer->id ); ?>">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['sign_in_person'] ); ?>">
											<button type="submit" class="button-link"><?php esc_html_e( 'Sign in person', 'comsign' ); ?></button>
										</form>
									<?php endif; ?>
									<?php if ( ! empty( $signer->email ) ) : ?>
										<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-mini-form">
											<input type="hidden" name="action" value="comsign_resend_signer">
											<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
											<input type="hidden" name="signer_id" value="<?php echo esc_attr( (int) $signer->id ); ?>">
											<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['resend_signer'] ); ?>">
											<button type="submit" class="button-link"><?php esc_html_e( 'Email link', 'comsign' ); ?></button>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-mini-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this signer?', 'comsign' ) ); ?>');">
										<input type="hidden" name="action" value="comsign_delete_signer">
										<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
										<input type="hidden" name="signer_id" value="<?php echo esc_attr( (int) $signer->id ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['delete_signer'] ); ?>">
										<button type="submit" class="button-link comsign-link-delete"><?php esc_html_e( 'Remove', 'comsign' ); ?></button>
									</form>
								</div>

								<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form comsign-signer-auth">
									<input type="hidden" name="action" value="comsign_set_signer_auth">
									<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
									<input type="hidden" name="signer_id" value="<?php echo esc_attr( (int) $signer->id ); ?>">
									<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['signer_auth'] ); ?>">
									<label class="comsign-auth-label"><?php esc_html_e( 'Verify identity:', 'comsign' ); ?></label>
									<select name="auth_method">
										<?php foreach ( \ComSign\Frontend\SignerAuth::methods() as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $signer->auth_method, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="auth_code" placeholder="<?php esc_attr_e( 'Access code', 'comsign' ); ?>" autocomplete="off">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'comsign' ); ?></button>
								</form>

								<?php if ( ! empty( $link_flash ) && (int) $link_flash['signer_id'] === (int) $signer->id ) : ?>
									<?php
									$share_link = $link_flash['url'];
									$wa_text    = sprintf(
										/* translators: %s: signing link. */
										__( 'Please sign the document at the following secure link: %s', 'comsign' ),
										$share_link
									);
									$wa_base = ! empty( $signer->phone ) ? 'https://wa.me/' . rawurlencode( ltrim( $signer->phone, '+' ) ) : 'https://wa.me/';
									$wa_url  = $wa_base . '?text=' . rawurlencode( $wa_text );
									?>
									<div class="comsign-link-box">
										<input type="text" readonly value="<?php echo esc_attr( $share_link ); ?>" class="widefat comsign-link-input" onclick="this.select();">
										<div class="comsign-link-buttons">
											<button type="button" class="button comsign-copy" data-clipboard="<?php echo esc_attr( $share_link ); ?>"><?php esc_html_e( 'Copy link', 'comsign' ); ?></button>
											<a class="button comsign-wa" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Share via WhatsApp', 'comsign' ); ?></a>
										</div>
										<p class="description"><?php esc_html_e( 'This link is personal and replaces any link previously issued to this signer.', 'comsign' ); ?></p>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form">
					<input type="hidden" name="action" value="comsign_add_signer">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['add_signer'] ); ?>">
					<p><input type="text" name="name" placeholder="<?php esc_attr_e( 'Full name', 'comsign' ); ?>" class="widefat"></p>
					<p><input type="email" name="email" placeholder="<?php esc_attr_e( 'email@example.com', 'comsign' ); ?>" class="widefat"></p>
					<p><input type="tel" name="phone" placeholder="<?php esc_attr_e( 'Phone for WhatsApp (optional)', 'comsign' ); ?>" class="widefat"></p>
					<p>
						<select name="auth_method" class="widefat">
							<?php foreach ( \ComSign\Frontend\SignerAuth::methods() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><input type="text" name="auth_code" placeholder="<?php esc_attr_e( 'Access code (for the access-code method)', 'comsign' ); ?>" class="widefat" autocomplete="off"></p>
					<p class="description"><?php esc_html_e( 'Email is optional — leave it empty to share a signing link by WhatsApp or any other channel.', 'comsign' ); ?></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Add signer', 'comsign' ); ?></button></p>
				</form>

				<hr>
				<h3><?php esc_html_e( 'CC recipients', 'comsign' ); ?></h3>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form">
					<input type="hidden" name="action" value="comsign_save_cc">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['save_cc'] ); ?>">
					<p><textarea name="cc_emails" rows="2" class="widefat" placeholder="cc1@example.com, cc2@example.com"><?php echo esc_textarea( (string) ( $document->cc_emails ?? '' ) ); ?></textarea></p>
					<p class="description"><?php esc_html_e( 'These people receive the fully signed PDF by email when signing completes. They do not sign.', 'comsign' ); ?></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Save CC recipients', 'comsign' ); ?></button></p>
				</form>
			</div>

			<div class="comsign-card">
				<h2><?php esc_html_e( 'Actions', 'comsign' ); ?></h2>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<input type="hidden" name="action" value="comsign_send">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['send'] ); ?>">

					<p>
						<label>
							<input type="checkbox" name="sequential" value="1" <?php checked( ! empty( $document->sequential ) ); ?>>
							<?php esc_html_e( 'Require signing in order', 'comsign' ); ?>
						</label>
					</p>
					<p>
						<label for="comsign-expiry"><?php esc_html_e( 'Link expires after', 'comsign' ); ?></label>
						<input type="number" id="comsign-expiry" name="expiry_days" min="0" max="365" value="0" style="width:70px;">
						<?php esc_html_e( 'days (0 = never)', 'comsign' ); ?>
					</p>
					<p>
						<label for="comsign-message"><?php esc_html_e( 'Message to signers (optional)', 'comsign' ); ?></label>
						<textarea id="comsign-message" name="message" rows="3" class="widefat"><?php echo esc_textarea( $document->message ?? '' ); ?></textarea>
					</p>

					<button type="submit" class="button button-primary" <?php disabled( empty( $signers ) ); ?>>
						<?php echo $is_draft ? esc_html__( 'Send for signing', 'comsign' ) : esc_html__( 'Re-send invitations', 'comsign' ); ?>
					</button>
				</form>

				<p>
					<a class="button" href="<?php echo esc_url( $download['source'] ); ?>"><?php esc_html_e( 'View original', 'comsign' ); ?></a>
					<a class="button" href="<?php echo esc_url( $download['audit_pdf'] ); ?>"><?php esc_html_e( 'Audit report (PDF)', 'comsign' ); ?></a>
				</p>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="comsign-inline-form">
					<input type="hidden" name="action" value="comsign_save_template">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['save_template'] ); ?>">
					<p><input type="text" name="template_name" class="widefat" placeholder="<?php esc_attr_e( 'Template name', 'comsign' ); ?>"></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Save as template', 'comsign' ); ?></button></p>
				</form>

				<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this document and all its data?', 'comsign' ) ); ?>');">
					<input type="hidden" name="action" value="comsign_delete">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( $document_id ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonces['delete'] ); ?>">
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete document', 'comsign' ); ?></button>
				</form>
			</div>
		</div>
	</div>
</div>
