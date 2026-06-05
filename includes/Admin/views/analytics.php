<?php
/**
 * Analytics dashboard view.
 *
 * @package ComSign
 *
 * @var array  $counts
 * @var int    $total
 * @var float  $rate
 * @var int    $avg_seconds
 * @var array  $signers
 * @var array  $stuck
 * @var array  $series
 * @var string $edit_base
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Services\Analytics;

$status_labels = array(
	'draft'     => __( 'Draft', 'comsign' ),
	'sent'      => __( 'Sent', 'comsign' ),
	'viewed'    => __( 'Viewed', 'comsign' ),
	'signed'    => __( 'Partially signed', 'comsign' ),
	'completed' => __( 'Completed', 'comsign' ),
	'declined'  => __( 'Declined', 'comsign' ),
);

$max = max( 1, max( array_map( 'intval', $series ) ) );
?>
<div class="wrap comsign-wrap">
	<h1><?php esc_html_e( 'Analytics', 'comsign' ); ?></h1>

	<div class="comsign-stat-grid">
		<div class="comsign-stat-card">
			<span class="comsign-stat-num"><?php echo esc_html( (string) $total ); ?></span>
			<span class="comsign-stat-label"><?php esc_html_e( 'Total documents', 'comsign' ); ?></span>
		</div>
		<div class="comsign-stat-card">
			<span class="comsign-stat-num"><?php echo esc_html( (string) $rate ); ?>%</span>
			<span class="comsign-stat-label"><?php esc_html_e( 'Completion rate', 'comsign' ); ?></span>
		</div>
		<div class="comsign-stat-card">
			<span class="comsign-stat-num"><?php echo esc_html( Analytics::humanize( $avg_seconds ) ); ?></span>
			<span class="comsign-stat-label"><?php esc_html_e( 'Avg. time to complete', 'comsign' ); ?></span>
		</div>
		<div class="comsign-stat-card">
			<span class="comsign-stat-num"><?php echo esc_html( (string) $signers['pending'] ); ?></span>
			<span class="comsign-stat-label"><?php esc_html_e( 'Signatures pending', 'comsign' ); ?></span>
		</div>
	</div>

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Documents by status', 'comsign' ); ?></h2>
		<table class="widefat striped" style="max-width:480px;">
			<tbody>
				<?php foreach ( $status_labels as $key => $label ) : ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td style="text-align:end;font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) ( $counts[ $key ] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Completions (last 14 days)', 'comsign' ); ?></h2>
		<div class="comsign-spark" role="img" aria-label="<?php esc_attr_e( 'Completions per day', 'comsign' ); ?>">
			<?php foreach ( $series as $day => $value ) : ?>
				<span class="comsign-spark-bar" title="<?php echo esc_attr( $day . ': ' . $value ); ?>" style="height:<?php echo esc_attr( (string) max( 2, (int) round( ( $value / $max ) * 100 ) ) ); ?>%;"></span>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="comsign-card">
		<h2><?php esc_html_e( 'Needs attention (out > 7 days)', 'comsign' ); ?></h2>
		<?php if ( empty( $stuck ) ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing stuck — every document is moving.', 'comsign' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Document', 'comsign' ); ?></th>
						<th><?php esc_html_e( 'Status', 'comsign' ); ?></th>
						<th><?php esc_html_e( 'Last activity', 'comsign' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stuck as $doc ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $edit_base . (int) $doc->id ); ?>"><?php echo esc_html( $doc->title ?: __( '(untitled)', 'comsign' ) ); ?></a></td>
							<td><?php echo esc_html( $status_labels[ $doc->status ] ?? $doc->status ); ?></td>
							<td><?php echo esc_html( (string) $doc->updated_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
