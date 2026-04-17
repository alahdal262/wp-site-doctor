<?php
/**
 * Plugin X-Ray template for WP Site Doctor.
 *
 * Displays a sortable table of all active plugins with deep analysis.
 *
 * @package WPSiteDoctor
 *
 * @var array|null $xray_data Array of plugin analysis data or null.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wpsd-xray-page">
	<h1>
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'Plugin X-Ray', 'wp-site-doctor' ); ?>
	</h1>

	<?php if ( ! empty( $xray_data ) ) : ?>
		<div class="wpsd-card">
			<p><?php esc_html_e( 'Deep analysis of all active plugins. Click column headers to sort.', 'wp-site-doctor' ); ?></p>

			<table class="wpsd-xray-table widefat" id="wpsd-xray-table">
				<thead>
					<tr>
						<th data-sort="name" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Plugin', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th data-sort="version" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Version', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th data-sort="impact" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Load Impact', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th data-sort="last_updated" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Last Updated', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th data-sort="active_installs" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Active Installs', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th data-sort="rating" role="columnheader" aria-sort="none" tabindex="0"><?php esc_html_e( 'Rating', 'wp-site-doctor' ); ?> <span class="dashicons dashicons-sort" aria-hidden="true"></span></th>
						<th><?php esc_html_e( 'Issues', 'wp-site-doctor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $xray_data as $plugin ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $plugin['name'] ?? '' ); ?></strong>
								<?php if ( ! empty( $plugin['author'] ) ) : ?>
									<br /><small><?php echo esc_html( $plugin['author'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $plugin['version'] ?? '—' ); ?></td>
							<td>
								<span class="wpsd-impact-<?php echo esc_attr( $plugin['impact'] ?? 'low' ); ?>">
									<?php echo esc_html( ucfirst( $plugin['impact'] ?? 'low' ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $plugin['last_updated'] ?? '—' ); ?></td>
							<td><?php echo esc_html( isset( $plugin['active_installs'] ) ? number_format_i18n( $plugin['active_installs'] ) : '—' ); ?></td>
							<td><?php echo esc_html( isset( $plugin['rating'] ) ? $plugin['rating'] . '/5' : '—' ); ?></td>
							<td>
								<?php if ( ! empty( $plugin['issues'] ) ) : ?>
									<?php foreach ( $plugin['issues'] as $issue ) : ?>
										<span class="wpsd-severity-badge wpsd-severity-<?php echo esc_attr( $issue['severity'] ?? 'info' ); ?>">
											<?php echo esc_html( $issue['message'] ?? '' ); ?>
										</span><br />
									<?php endforeach; ?>
								<?php else : ?>
									<span class="wpsd-severity-badge wpsd-severity-pass"><?php esc_html_e( 'OK', 'wp-site-doctor' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

	<?php else : ?>
		<div class="wpsd-card wpsd-empty-state">
			<span class="dashicons dashicons-search wpsd-empty-icon"></span>
			<h2><?php esc_html_e( 'No X-Ray data available', 'wp-site-doctor' ); ?></h2>
			<p>
				<?php esc_html_e( 'Run a full scan from the Dashboard to generate plugin analysis data.', 'wp-site-doctor' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-site-doctor' ) ); ?>">
					<?php esc_html_e( 'Go to Dashboard', 'wp-site-doctor' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</div>
