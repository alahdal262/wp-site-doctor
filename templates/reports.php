<?php
/**
 * Reports template for WP Site Doctor.
 *
 * Shows scan history and provides report sending functionality.
 *
 * @package WPSiteDoctor
 *
 * @var array $scan_history Array of scan history rows.
 * @var array $settings     Plugin settings.
 */

defined( 'ABSPATH' ) || exit;

$admin_email     = isset( $settings['admin_email'] ) ? $settings['admin_email'] : get_option( 'admin_email' );
$developer_email = isset( $settings['developer_email'] ) ? $settings['developer_email'] : '';
?>

<div class="wrap wpsd-reports-page">
	<h1>
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'Reports', 'wp-site-doctor' ); ?>
	</h1>

	<!-- Send Report -->
	<div class="wpsd-card">
		<h2><?php esc_html_e( 'Send Diagnostic Report', 'wp-site-doctor' ); ?></h2>

		<?php if ( empty( $scan_history ) ) : ?>
			<p>
				<?php esc_html_e( 'No scan data available. Run a scan first to generate a report.', 'wp-site-doctor' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-site-doctor' ) ); ?>">
					<?php esc_html_e( 'Go to Dashboard', 'wp-site-doctor' ); ?>
				</a>
			</p>
		<?php else : ?>
			<div class="wpsd-report-actions" style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px;">

				<!-- Send to Admin -->
				<div class="wpsd-report-action-box" style="flex: 1; min-width: 250px;">
					<h3><?php esc_html_e( 'Email to Admin', 'wp-site-doctor' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Send a summary report to the site administrator.', 'wp-site-doctor' ); ?>
					</p>
					<p>
						<label for="wpsd-admin-email"><?php esc_html_e( 'Email:', 'wp-site-doctor' ); ?></label><br />
						<input type="email" id="wpsd-admin-email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" />
					</p>
					<button type="button" class="button button-primary wpsd-send-report"
						data-type="summary"
						data-email-field="wpsd-admin-email">
						<span class="dashicons dashicons-email"></span>
						<?php esc_html_e( 'Send Summary Report', 'wp-site-doctor' ); ?>
					</button>
				</div>

				<!-- Send to Developer -->
				<div class="wpsd-report-action-box" style="flex: 1; min-width: 250px;">
					<h3><?php esc_html_e( 'Send to Developer', 'wp-site-doctor' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Send a detailed technical report including server environment and debug info.', 'wp-site-doctor' ); ?>
					</p>
					<p>
						<label for="wpsd-dev-email"><?php esc_html_e( 'Developer Email:', 'wp-site-doctor' ); ?></label><br />
						<input type="email" id="wpsd-dev-email" value="<?php echo esc_attr( $developer_email ); ?>" class="regular-text" />
					</p>
					<button type="button" class="button wpsd-send-report"
						data-type="developer"
						data-email-field="wpsd-dev-email">
						<span class="dashicons dashicons-email"></span>
						<?php esc_html_e( 'Send Developer Report', 'wp-site-doctor' ); ?>
					</button>
				</div>
			</div>
			<p id="wpsd-report-status" style="margin-top: 15px;"></p>
		<?php endif; ?>
	</div>

	<!-- Scan History -->
	<?php if ( ! empty( $scan_history ) ) : ?>
		<div class="wpsd-card" style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Scan History', 'wp-site-doctor' ); ?></h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Health Score', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Total Issues', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Critical', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Warnings', 'wp-site-doctor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $scan_history as $scan ) : ?>
						<tr>
							<td>
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( $scan->created_at )
									)
								);
								?>
							</td>
							<td>
								<span class="wpsd-score-<?php echo esc_attr( \WPSiteDoctor\Health_Score::get_score_class( $scan->health_score ) ); ?>"
									style="font-weight: 600; font-size: 16px;">
									<?php echo esc_html( $scan->health_score ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $scan->total_issues ); ?></td>
							<td>
								<?php if ( $scan->critical_count > 0 ) : ?>
									<span style="color: #d63638; font-weight: 600;"><?php echo esc_html( $scan->critical_count ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $scan->critical_count ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $scan->warning_count ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<script>
(function() {
	document.querySelectorAll('.wpsd-send-report').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();

			var type = this.getAttribute('data-type');
			var emailField = document.getElementById(this.getAttribute('data-email-field'));
			var email = emailField ? emailField.value : '';
			var status = document.getElementById('wpsd-report-status');

			if (!email) {
				if (status) status.textContent = 'Please enter an email address.';
				return;
			}

			this.disabled = true;
			if (status) status.textContent = 'Sending report...';

			var formData = new FormData();
			formData.append('action', 'wpsd_send_report');
			formData.append('nonce', wpsdData.nonce);
			formData.append('email', email);
			formData.append('report_type', type);

			var self = this;
			fetch(wpsdData.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
			.then(function(r) { return r.json(); })
			.then(function(response) {
				self.disabled = false;
				if (status) {
					status.textContent = response.success
						? (response.data.message || 'Report sent!')
						: (response.data.message || 'Failed to send report.');
					status.style.color = response.success ? '#00a32a' : '#d63638';
				}
			})
			.catch(function() {
				self.disabled = false;
				if (status) {
					status.textContent = 'Network error. Please try again.';
					status.style.color = '#d63638';
				}
			});
		});
	});
})();
</script>
