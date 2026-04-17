<?php
/**
 * Dashboard template for WP Site Doctor.
 *
 * @package WPSiteDoctor
 *
 * @var object|null $latest_scan   Latest scan history row or null.
 * @var array|null  $scan_results  Array of scan result rows or null.
 */

defined( 'ABSPATH' ) || exit;

$health_score    = $latest_scan ? absint( $latest_scan->health_score ) : 0;
$has_scan        = ! is_null( $latest_scan );
$scanner_scores  = $has_scan ? json_decode( $latest_scan->scanner_scores, true ) : array();
$scan_date       = $has_scan ? $latest_scan->created_at : '';
$total_issues    = $has_scan ? absint( $latest_scan->total_issues ) : 0;
$critical_count  = $has_scan ? absint( $latest_scan->critical_count ) : 0;
$warning_count   = $has_scan ? absint( $latest_scan->warning_count ) : 0;
?>

<div class="wrap wpsd-dashboard">
	<h1 class="wpsd-page-title">
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'WP Site Doctor', 'wp-site-doctor' ); ?>
	</h1>

	<div class="wpsd-dashboard-grid">

		<!-- Health Score Gauge -->
		<div class="wpsd-card wpsd-gauge-card">
			<div id="wpsd-health-gauge" class="wpsd-gauge-container" role="img" aria-label="<?php echo $has_scan ? esc_attr( sprintf( __( 'Health score: %d out of 100', 'wp-site-doctor' ), $health_score ) ) : esc_attr__( 'No scan data', 'wp-site-doctor' ); ?>">
				<div class="wpsd-gauge" data-score="<?php echo esc_attr( $health_score ); ?>">
					<div class="wpsd-gauge-circle" aria-hidden="true">
						<div class="wpsd-gauge-fill"></div>
						<div class="wpsd-gauge-cover">
							<span class="wpsd-gauge-score"><?php echo $has_scan ? esc_html( $health_score ) : '—'; ?></span>
							<span class="wpsd-gauge-label">
								<?php
								if ( $has_scan ) {
									echo esc_html( \WPSiteDoctor\Health_Score::get_grade_label( $health_score ) );
								} else {
									esc_html_e( 'Not Scanned', 'wp-site-doctor' );
								}
								?>
							</span>
						</div>
					</div>
					<!-- SVG fallback for browsers without conic-gradient support -->
					<svg class="wpsd-gauge-fallback" viewBox="0 0 200 200" aria-hidden="true">
						<circle cx="100" cy="100" r="90" fill="none" stroke="#e0e0e0" stroke-width="12" />
						<circle cx="100" cy="100" r="90" fill="none" stroke="currentColor" stroke-width="12"
							stroke-dasharray="565.48" stroke-dashoffset="565.48"
							stroke-linecap="round" transform="rotate(-90 100 100)"
							class="wpsd-gauge-svg-arc" />
					</svg>
				</div>

				<?php if ( $has_scan ) : ?>
					<p class="wpsd-scan-meta">
						<?php
						printf(
							/* translators: %s: date and time of last scan */
							esc_html__( 'Last scanned: %s', 'wp-site-doctor' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $scan_date ) ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="wpsd-scan-actions">
				<button type="button" id="wpsd-run-scan" class="button button-primary button-hero" aria-label="<?php esc_attr_e( 'Run a full site health scan', 'wp-site-doctor' ); ?>">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<?php esc_html_e( 'Run Full Scan', 'wp-site-doctor' ); ?>
				</button>
			</div>
		</div>

		<!-- Summary Stats -->
		<?php if ( $has_scan ) : ?>
			<div class="wpsd-card wpsd-stats-card">
				<h2><?php esc_html_e( 'Summary', 'wp-site-doctor' ); ?></h2>
				<div class="wpsd-stats-grid">
					<div class="wpsd-stat">
						<span class="wpsd-stat-number"><?php echo esc_html( $total_issues ); ?></span>
						<span class="wpsd-stat-label"><?php esc_html_e( 'Total Issues', 'wp-site-doctor' ); ?></span>
					</div>
					<div class="wpsd-stat wpsd-stat-critical">
						<span class="wpsd-stat-number"><?php echo esc_html( $critical_count ); ?></span>
						<span class="wpsd-stat-label"><?php esc_html_e( 'Critical', 'wp-site-doctor' ); ?></span>
					</div>
					<div class="wpsd-stat wpsd-stat-warning">
						<span class="wpsd-stat-number"><?php echo esc_html( $warning_count ); ?></span>
						<span class="wpsd-stat-label"><?php esc_html_e( 'Warnings', 'wp-site-doctor' ); ?></span>
					</div>
					<div class="wpsd-stat wpsd-stat-pass">
						<span class="wpsd-stat-number"><?php echo esc_html( $total_issues - $critical_count - $warning_count ); ?></span>
						<span class="wpsd-stat-label"><?php esc_html_e( 'Info / Pass', 'wp-site-doctor' ); ?></span>
					</div>
				</div>
			</div>
		<?php endif; ?>

	</div>

	<!-- Scan Progress Bar (hidden by default) -->
	<div id="wpsd-progress-container" class="wpsd-card" style="display: none;" aria-live="assertive">
		<h2 id="wpsd-progress-title"><?php esc_html_e( 'Scanning...', 'wp-site-doctor' ); ?></h2>
		<div class="wpsd-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Scan progress', 'wp-site-doctor' ); ?>">
			<div class="wpsd-progress-fill" id="wpsd-progress-fill"></div>
		</div>
		<p id="wpsd-progress-status" class="wpsd-progress-status" aria-live="polite"></p>
	</div>

	<!-- Scan Results Container -->
	<div id="wpsd-scan-results" class="wpsd-scan-results" aria-live="polite">
		<?php if ( $has_scan && ! empty( $scan_results ) ) : ?>

			<!-- Category Breakdown -->
			<div class="wpsd-card wpsd-results-card">
				<h2><?php esc_html_e( 'Scan Results by Category', 'wp-site-doctor' ); ?></h2>

				<div class="wpsd-category-tabs" role="tablist">
					<?php
					$tab_index = 0;
					foreach ( $scan_results as $result ) :
						$issues      = json_decode( $result->issues, true );
						$issue_count = is_array( $issues ) ? count( $issues ) : 0;
						$score       = absint( $result->score );
						$is_active   = 0 === $tab_index;
						?>
						<button
							type="button"
							id="wpsd-tab-<?php echo esc_attr( $result->scanner_id ); ?>"
							class="wpsd-tab <?php echo esc_attr( $is_active ? 'wpsd-tab-active' : '' ); ?>"
							role="tab"
							aria-selected="<?php echo esc_attr( $is_active ? 'true' : 'false' ); ?>"
							aria-controls="wpsd-panel-<?php echo esc_attr( $result->scanner_id ); ?>"
							tabindex="<?php echo esc_attr( $is_active ? '0' : '-1' ); ?>"
							data-scanner="<?php echo esc_attr( $result->scanner_id ); ?>"
						>
							<span class="wpsd-tab-label"><?php echo esc_html( ucwords( str_replace( '_', ' ', $result->scanner_id ) ) ); ?></span>
							<span class="wpsd-tab-score wpsd-score-<?php echo esc_attr( \WPSiteDoctor\Health_Score::get_score_class( $score ) ); ?>">
								<?php echo esc_html( $score ); ?>
							</span>
							<?php if ( $issue_count > 0 ) : ?>
								<span class="wpsd-tab-badge"><?php echo esc_html( $issue_count ); ?></span>
							<?php endif; ?>
						</button>
						<?php
						++$tab_index;
					endforeach;
					?>
				</div>

				<?php
				$panel_index = 0;
				foreach ( $scan_results as $result ) :
					$issues    = json_decode( $result->issues, true );
					$is_active = 0 === $panel_index;
					?>
					<div
						id="wpsd-panel-<?php echo esc_attr( $result->scanner_id ); ?>"
						class="wpsd-panel <?php echo esc_attr( $is_active ? 'wpsd-panel-active' : '' ); ?>"
						role="tabpanel"
						aria-labelledby="wpsd-tab-<?php echo esc_attr( $result->scanner_id ); ?>"
						<?php if ( ! $is_active ) { echo 'hidden'; } ?>
					>
						<?php if ( is_array( $issues ) && ! empty( $issues ) ) : ?>
							<?php foreach ( $issues as $issue ) : ?>
								<div class="wpsd-issue wpsd-issue-<?php echo esc_attr( $issue['severity'] ?? 'info' ); ?>">
									<div class="wpsd-issue-header">
										<span class="wpsd-severity-badge wpsd-severity-<?php echo esc_attr( $issue['severity'] ?? 'info' ); ?>">
											<?php echo esc_html( ucfirst( $issue['severity'] ?? 'info' ) ); ?>
										</span>
										<strong class="wpsd-issue-title"><?php echo esc_html( $issue['message'] ?? '' ); ?></strong>
									</div>
									<?php if ( ! empty( $issue['recommendation'] ) ) : ?>
										<p class="wpsd-issue-recommendation"><?php echo esc_html( $issue['recommendation'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! empty( $issue['repair_action'] ) ) : ?>
										<button type="button" class="button wpsd-fix-btn"
											data-action-id="<?php echo esc_attr( $issue['repair_action']['action_id'] ); ?>"
											data-action-label="<?php echo esc_attr( $issue['repair_action']['label'] ); ?>">
											<span class="dashicons dashicons-admin-tools"></span>
											<?php esc_html_e( 'Fix Now', 'wp-site-doctor' ); ?>
										</button>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<p class="wpsd-no-issues">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'No issues found in this category.', 'wp-site-doctor' ); ?>
							</p>
						<?php endif; ?>
					</div>
					<?php
					++$panel_index;
				endforeach;
				?>
			</div>

		<?php elseif ( ! $has_scan ) : ?>

			<div class="wpsd-card wpsd-empty-state">
				<span class="dashicons dashicons-heart wpsd-empty-icon"></span>
				<h2><?php esc_html_e( 'No scan data yet', 'wp-site-doctor' ); ?></h2>
				<p><?php esc_html_e( 'Run your first scan to get a complete health assessment of your WordPress site.', 'wp-site-doctor' ); ?></p>
			</div>

		<?php endif; ?>
	</div>

</div>
