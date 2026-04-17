<?php
/**
 * Repair Log template for WP Site Doctor.
 *
 * Displays a table of all repair actions with status and rollback buttons.
 *
 * @package WPSiteDoctor
 *
 * @var array $repair_log Array of repair log rows.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wpsd-repair-log-page">
	<h1>
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'Repair Log', 'wp-site-doctor' ); ?>
	</h1>

	<?php if ( ! empty( $repair_log ) ) : ?>
		<div class="wpsd-card">
			<p><?php esc_html_e( 'History of all repair actions. Completed actions with restore data can be rolled back.', 'wp-site-doctor' ); ?></p>

			<table class="wpsd-repair-log-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Executed By', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Error', 'wp-site-doctor' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-site-doctor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $repair_log as $entry ) : ?>
						<?php
						$restore_data  = json_decode( $entry->restore_data, true );
						$can_rollback  = ( 'completed' === $entry->status && ! empty( $restore_data ) && empty( $restore_data['irreversible'] ) );
						$user          = get_userdata( $entry->executed_by );
						$user_display  = $user ? $user->display_name : __( 'Unknown', 'wp-site-doctor' );
						?>
						<tr>
							<td>
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( $entry->executed_at )
									)
								);
								?>
							</td>
							<td><?php echo esc_html( $entry->action_label ); ?></td>
							<td>
								<span class="wpsd-status-<?php echo esc_attr( $entry->status ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', $entry->status ) ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $user_display ); ?></td>
							<td>
								<?php if ( ! empty( $entry->error_message ) ) : ?>
									<span title="<?php echo esc_attr( $entry->error_message ); ?>">
										<?php echo esc_html( wp_trim_words( $entry->error_message, 10 ) ); ?>
									</span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $can_rollback ) : ?>
									<button type="button"
										class="button wpsd-rollback-btn"
										data-log-id="<?php echo esc_attr( $entry->id ); ?>"
										data-action-label="<?php echo esc_attr( $entry->action_label ); ?>"
										aria-label="<?php echo esc_attr( sprintf( __( 'Rollback: %s', 'wp-site-doctor' ), $entry->action_label ) ); ?>"
									>
										<span class="dashicons dashicons-undo" aria-hidden="true"></span>
										<?php esc_html_e( 'Rollback', 'wp-site-doctor' ); ?>
									</button>
								<?php elseif ( 'completed' === $entry->status ) : ?>
									<span class="description"><?php esc_html_e( 'Irreversible', 'wp-site-doctor' ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

	<?php else : ?>
		<div class="wpsd-card wpsd-empty-state">
			<span class="dashicons dashicons-list-view wpsd-empty-icon"></span>
			<h2><?php esc_html_e( 'No repair history', 'wp-site-doctor' ); ?></h2>
			<p><?php esc_html_e( 'Repair actions will appear here after you run auto-repairs.', 'wp-site-doctor' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<script>
(function() {
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.wpsd-rollback-btn');
		if (!btn) return;

		e.preventDefault();

		var logId = btn.getAttribute('data-log-id');
		var label = btn.getAttribute('data-action-label');

		if (!logId) return;

		if (typeof wpsdData !== 'undefined' && wpsdData.i18n.rollbackConfirm) {
			if (!window.confirm(wpsdData.i18n.rollbackConfirm + '\n\n' + label)) {
				return;
			}
		}

		btn.disabled = true;
		btn.textContent = '...';

		var formData = new FormData();
		formData.append('action', 'wpsd_rollback_repair');
		formData.append('nonce', wpsdData.nonce);
		formData.append('log_id', logId);

		fetch(wpsdData.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function(r) { return r.json(); })
		.then(function(response) {
			if (response.success) {
				window.location.reload();
			} else {
				window.alert(response.data.message || 'Rollback failed.');
				btn.disabled = false;
				btn.innerHTML = '<span class="dashicons dashicons-undo"></span> Rollback';
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.innerHTML = '<span class="dashicons dashicons-undo"></span> Rollback';
		});
	});
})();
</script>
