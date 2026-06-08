<?php
/**
 * Portal: insights (read-only analytics over the user's workspaces).
 *
 * @package ComSign
 *
 * @var string     $page_title
 * @var array      $nav
 * @var int        $total
 * @var float      $rate
 * @var int        $avg_seconds
 * @var array      $counts   status => count.
 * @var array      $signers  total/signed/declined/pending.
 * @var array      $series   Y-m-d => completed count.
 * @var array      $stuck    Document rows out > 7 days.
 * @var array|null $switcher
 */

defined( 'ABSPATH' ) || exit;

use ComSign\Services\Analytics;

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/portal-nav.php';

$max = max( 1, max( array_map( 'intval', $series ? $series : array( 0 ) ) ) );
?>
	<main id="comsign-main" tabindex="-1" class="comsign-portal">
		<h1><?php esc_html_e( 'Insights', 'comsign' ); ?></h1>

		<?php if ( 0 === $total ) : ?>
			<p class="comsign-empty"><?php esc_html_e( 'No data yet — send a document to start seeing insights.', 'comsign' ); ?></p>
		<?php else : ?>

			<div class="comsign-portal-cards">
				<div class="comsign-portal-stat">
					<span class="comsign-portal-stat-num"><?php echo esc_html( (string) $total ); ?></span>
					<span class="comsign-portal-stat-label"><?php esc_html_e( 'Total documents', 'comsign' ); ?></span>
				</div>
				<div class="comsign-portal-stat">
					<span class="comsign-portal-stat-num"><?php echo esc_html( number_format_i18n( $rate, 1 ) . '%' ); ?></span>
					<span class="comsign-portal-stat-label"><?php esc_html_e( 'Completion rate', 'comsign' ); ?></span>
				</div>
				<div class="comsign-portal-stat">
					<span class="comsign-portal-stat-num"><?php echo esc_html( $avg_seconds > 0 ? Analytics::humanize( $avg_seconds ) : '—' ); ?></span>
					<span class="comsign-portal-stat-label"><?php esc_html_e( 'Avg. time to complete', 'comsign' ); ?></span>
				</div>
				<div class="comsign-portal-stat">
					<span class="comsign-portal-stat-num"><?php echo esc_html( (string) (int) ( $signers['pending'] ?? 0 ) ); ?></span>
					<span class="comsign-portal-stat-label"><?php esc_html_e( 'Signatures pending', 'comsign' ); ?></span>
				</div>
			</div>

			<section class="comsign-card" aria-labelledby="comsign-spark-h">
				<h2 id="comsign-spark-h"><?php esc_html_e( 'Completions (last 14 days)', 'comsign' ); ?></h2>
				<div class="comsign-spark" role="img" aria-label="<?php esc_attr_e( 'Daily completions for the last 14 days. A text table follows below.', 'comsign' ); ?>" aria-hidden="false">
					<?php foreach ( $series as $day => $count ) : ?>
						<span class="comsign-spark-bar" style="height: <?php echo esc_attr( (string) max( 3, (int) round( ( (int) $count / $max ) * 100 ) ) ); ?>%;" title="<?php echo esc_attr( $day . ': ' . (int) $count ); ?>"></span>
					<?php endforeach; ?>
				</div>
				<table class="comsign-sr-only">
					<caption><?php esc_html_e( 'Completions per day (last 14 days)', 'comsign' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Date', 'comsign' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Completed', 'comsign' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $series as $day => $count ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $day ) ); ?></th>
								<td><?php echo esc_html( (string) (int) $count ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="comsign-card" aria-labelledby="comsign-bystatus-h">
				<h2 id="comsign-bystatus-h"><?php esc_html_e( 'Documents by status', 'comsign' ); ?></h2>
				<ul class="comsign-status-list">
					<?php foreach ( $counts as $status => $n ) : ?>
						<li>
							<span class="comsign-pill comsign-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( \ComSign\Admin\DocumentsListTable::status_label( (string) $status ) ); ?></span>
							<strong><?php echo esc_html( (string) (int) $n ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="comsign-card" aria-labelledby="comsign-stuck-h">
				<h2 id="comsign-stuck-h"><?php esc_html_e( 'Needs attention (out > 7 days)', 'comsign' ); ?></h2>
				<?php if ( empty( $stuck ) ) : ?>
					<p class="comsign-empty"><?php esc_html_e( 'Nothing stuck — every document is moving.', 'comsign' ); ?></p>
				<?php else : ?>
					<ul class="comsign-status-list">
						<?php foreach ( $stuck as $doc ) : ?>
							<li>
								<a href="<?php echo esc_url( \ComSign\Frontend\PortalController::url( array( 'view' => 'document', 'doc' => (int) $doc->id ) ) ); ?>">
									<?php echo esc_html( $doc->title ? $doc->title : __( '(untitled)', 'comsign' ) ); ?>
								</a>
								<span class="comsign-template-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $doc->updated_at ) ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

		<?php endif; ?>
	</main>
<?php
require __DIR__ . '/partials/footer.php';
