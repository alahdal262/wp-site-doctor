<?php
/**
 * Auto-Repair template for WP Site Doctor.
 *
 * Shows available repair actions with checkboxes and confirmation.
 *
 * @package WPSiteDoctor
 *
 * @var object|null $latest_scan    Latest scan history row or null.
 * @var array       $repair_actions Array of available repair actions.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wpsd-repair-page">
	<h1>
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'Auto-Repair', 'wp-site-doctor' ); ?>
	</h1>

	<?php if ( ! empty( $repair_actions ) ) : ?>

		<div class="wpsd-card">
			<h2><?php esc_html_e( 'Available Repairs', 'wp-site-doctor' ); ?></h2>
			<p><?php esc_html_e( 'Select the repairs you want to run. A restore point will be created before each action.', 'wp-site-doctor' ); ?></p>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Recommendation:', 'wp-site-doctor' ); ?></strong>
					<?php esc_html_e( 'Take a full backup before running repairs.', 'wp-site-doctor' ); ?>
				</p>
			</div>

			<form id="wpsd-repair-form">
				<?php wp_nonce_field( 'wpsd_nonce', 'wpsd_repair_nonce' ); ?>

				<ul class="wpsd-repair-list">
					<?php foreach ( $repair_actions as $action ) : ?>
						<li class="wpsd-repair-item">
							<label>
								<input type="checkbox" name="repair_actions[]"
									value="<?php echo esc_attr( $action['action_id'] ); ?>"
								/>
								<div>
									<span class="wpsd-repair-desc">
										<?php echo esc_html( $action['label'] ); ?>
									</span>
									<?php if ( ! empty( $action['description'] ) ) : ?>
										<span class="wpsd-repair-detail">
											<?php echo esc_html( $action['description'] ); ?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $action['irreversible'] ) ) : ?>
										<span class="wpsd-irreversible-badge">
											<?php esc_html_e( 'Irreversible', 'wp-site-doctor' ); ?>
										</span>
									<?php endif; ?>
								</div>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="wpsd-repair-confirm">
					<label>
						<input type="checkbox" id="wpsd-confirm-checkbox" required />
						<?php esc_html_e( 'I understand these changes may not be fully reversible and I have a recent backup.', 'wp-site-doctor' ); ?>
					</label>
				</div>

				<p class="submit">
					<button type="submit" id="wpsd-run-repairs" class="button button-primary" disabled>
						<span class="dashicons dashicons-admin-tools"></span>
						<?php esc_html_e( 'Run Selected Repairs', 'wp-site-doctor' ); ?>
					</button>
				</p>
			</form>

			<!-- Repair Progress (hidden by default) -->
			<div id="wpsd-repair-progress" style="display: none;" aria-live="assertive">
				<div class="wpsd-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Repair progress', 'wp-site-doctor' ); ?>">
					<div class="wpsd-progress-fill" id="wpsd-repair-progress-fill"></div>
				</div>
				<p id="wpsd-repair-status" class="wpsd-progress-status" aria-live="polite"></p>
			</div>
		</div>

	<?php else : ?>

		<div class="wpsd-card wpsd-empty-state">
			<span class="dashicons dashicons-admin-tools wpsd-empty-icon"></span>
			<h2><?php esc_html_e( 'No repairs available', 'wp-site-doctor' ); ?></h2>
			<p>
				<?php esc_html_e( 'Run a full scan first to identify issues that can be auto-repaired.', 'wp-site-doctor' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-site-doctor' ) ); ?>">
					<?php esc_html_e( 'Go to Dashboard', 'wp-site-doctor' ); ?>
				</a>
			</p>
		</div>

	<?php endif; ?>
</div>

<script>
(function() {
	var checkbox = document.getElementById('wpsd-confirm-checkbox');
	var btn = document.getElementById('wpsd-run-repairs');
	if (checkbox && btn) {
		checkbox.addEventListener('change', function() {
			btn.disabled = !this.checked;
		});
	}
})();
</script>
