<?php
/**
 * Settings page template for WP Site Doctor.
 *
 * @package WPSiteDoctor
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wpsd-settings">
	<h1>
		<span class="dashicons dashicons-heart"></span>
		<?php esc_html_e( 'WP Site Doctor Settings', 'wp-site-doctor' ); ?>
	</h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'wpsd_settings_group' );
		do_settings_sections( \WPSiteDoctor\Settings::PAGE_SLUG );
		submit_button( __( 'Save Settings', 'wp-site-doctor' ) );
		?>
	</form>

	<hr />

	<div class="wpsd-card wpsd-settings-info">
		<h2><?php esc_html_e( 'Plugin Information', 'wp-site-doctor' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Plugin Version', 'wp-site-doctor' ); ?></strong></td>
					<td><?php echo esc_html( WPSD_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Database Version', 'wp-site-doctor' ); ?></strong></td>
					<td><?php echo esc_html( get_option( 'wpsd_db_version', '—' ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'PHP Version', 'wp-site-doctor' ); ?></strong></td>
					<td><?php echo esc_html( PHP_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'WordPress Version', 'wp-site-doctor' ); ?></strong></td>
					<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Multisite', 'wp-site-doctor' ); ?></strong></td>
					<td><?php echo is_multisite() ? esc_html__( 'Yes', 'wp-site-doctor' ) : esc_html__( 'No', 'wp-site-doctor' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
