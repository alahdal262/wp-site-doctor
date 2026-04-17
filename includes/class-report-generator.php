<?php
/**
 * Report Generator for WP Site Doctor.
 *
 * Generates HTML email reports with scan summaries, issue breakdowns,
 * and server environment details.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Report_Generator
 *
 * Builds inline-styled HTML email reports and sends them via wp_mail().
 */
class Report_Generator {

	/**
	 * Generate an HTML summary report for a scan session.
	 *
	 * @param string $session_id Scan session UUID.
	 * @return string HTML report body.
	 */
	public function generate_summary_report( $session_id ) {
		$history = $this->get_history_by_session( $session_id );
		$results = Database::get_scan_results( $session_id );

		$score         = $history ? absint( $history->health_score ) : 0;
		$total_issues  = $history ? absint( $history->total_issues ) : 0;
		$critical      = $history ? absint( $history->critical_count ) : 0;
		$warnings      = $history ? absint( $history->warning_count ) : 0;
		$score_color   = ( new Health_Score() )->get_color( $score );
		$grade_label   = Health_Score::get_grade_label( $score );
		$scan_date     = $history ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $history->created_at ) ) : '';

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head><meta charset="UTF-8"></head>
		<body style="margin:0; padding:0; background:#f6f7f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<div style="max-width:600px; margin:20px auto; background:#fff; border:1px solid #c3c4c7; border-radius:4px;">

			<!-- Header -->
			<div style="background:#1d2327; color:#fff; padding:20px; border-radius:4px 4px 0 0; text-align:center;">
				<h1 style="margin:0; font-size:22px;">&#9829; WP Site Doctor Report</h1>
				<p style="margin:5px 0 0; color:#a7aaad; font-size:13px;"><?php echo esc_html( home_url() ); ?></p>
			</div>

			<!-- Health Score -->
			<div style="text-align:center; padding:30px 20px;">
				<div style="display:inline-block; width:120px; height:120px; border-radius:50%; border:8px solid <?php echo esc_attr( $score_color ); ?>; line-height:120px;">
					<span style="font-size:42px; font-weight:700; color:<?php echo esc_attr( $score_color ); ?>;"><?php echo esc_html( $score ); ?></span>
				</div>
				<p style="font-size:18px; font-weight:600; color:<?php echo esc_attr( $score_color ); ?>; margin:10px 0 0;"><?php echo esc_html( $grade_label ); ?></p>
				<p style="color:#646970; font-size:13px; margin:5px 0 0;">
					<?php
					printf(
						/* translators: %s: scan date */
						esc_html__( 'Scanned: %s', 'wp-site-doctor' ),
						esc_html( $scan_date )
					);
					?>
				</p>
			</div>

			<!-- Summary Stats -->
			<div style="display:flex; text-align:center; border-top:1px solid #f0f0f1; border-bottom:1px solid #f0f0f1;">
				<div style="flex:1; padding:15px; border-right:1px solid #f0f0f1;">
					<div style="font-size:24px; font-weight:700;"><?php echo esc_html( $total_issues ); ?></div>
					<div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Total Issues', 'wp-site-doctor' ); ?></div>
				</div>
				<div style="flex:1; padding:15px; border-right:1px solid #f0f0f1;">
					<div style="font-size:24px; font-weight:700; color:#d63638;"><?php echo esc_html( $critical ); ?></div>
					<div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Critical', 'wp-site-doctor' ); ?></div>
				</div>
				<div style="flex:1; padding:15px;">
					<div style="font-size:24px; font-weight:700; color:#dba617;"><?php echo esc_html( $warnings ); ?></div>
					<div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Warnings', 'wp-site-doctor' ); ?></div>
				</div>
			</div>

			<!-- Issues by Category -->
			<div style="padding:20px;">
				<h2 style="font-size:16px; margin:0 0 15px; border-bottom:1px solid #f0f0f1; padding-bottom:10px;">
					<?php esc_html_e( 'Issues by Category', 'wp-site-doctor' ); ?>
				</h2>

				<?php foreach ( $results as $result ) : ?>
					<?php
					$issues  = json_decode( $result->issues, true );
					$r_score = absint( $result->score );
					$criticals = 0;
					$warns     = 0;

					if ( is_array( $issues ) ) {
						foreach ( $issues as $issue ) {
							if ( 'critical' === ( $issue['severity'] ?? '' ) ) {
								++$criticals;
							} elseif ( 'warning' === ( $issue['severity'] ?? '' ) ) {
								++$warns;
							}
						}
					}

					if ( 0 === $criticals && 0 === $warns ) {
						continue;
					}
					?>
					<div style="margin-bottom:12px; padding:12px; background:#f6f7f7; border-radius:4px; border-left:4px solid <?php echo esc_attr( $criticals > 0 ? '#d63638' : '#dba617' ); ?>;">
						<strong style="font-size:14px;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $result->scanner_id ) ) ); ?></strong>
						<span style="float:right; font-size:13px; color:#646970;">
							<?php echo esc_html( $r_score ); ?>/100
						</span>
						<div style="margin-top:6px; font-size:12px; color:#646970;">
							<?php if ( $criticals > 0 ) : ?>
								<span style="color:#d63638; font-weight:600;"><?php echo esc_html( $criticals ); ?> critical</span>
							<?php endif; ?>
							<?php if ( $warns > 0 ) : ?>
								<span style="color:#dba617; font-weight:600; margin-left:8px;"><?php echo esc_html( $warns ); ?> warning(s)</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Footer -->
			<div style="padding:15px 20px; background:#f6f7f7; border-top:1px solid #c3c4c7; border-radius:0 0 4px 4px; text-align:center; font-size:12px; color:#646970;">
				<?php
				printf(
					/* translators: %s: dashboard URL */
					esc_html__( 'View full results at %s', 'wp-site-doctor' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-site-doctor' ) ) . '">' . esc_html__( 'Site Doctor Dashboard', 'wp-site-doctor' ) . '</a>'
				);
				?>
				<br />
				<small>WP Site Doctor v<?php echo esc_html( WPSD_VERSION ); ?></small>
			</div>
		</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate a detailed developer report.
	 *
	 * Includes everything in the summary plus server environment,
	 * active plugins list, theme info, and debug log excerpt.
	 *
	 * @param string $session_id Scan session UUID.
	 * @return string HTML report body.
	 */
	public function generate_developer_report( $session_id ) {
		$summary = $this->generate_summary_report( $session_id );

		// Insert additional sections before the footer.
		$env_section = $this->build_environment_section();
		$plugins_section = $this->build_plugins_section();
		$debug_section = $this->build_debug_section();

		$additional = '<div style="padding:20px;">'
			. '<h2 style="font-size:16px; border-bottom:1px solid #f0f0f1; padding-bottom:10px;">'
			. esc_html__( 'Server Environment', 'wp-site-doctor' ) . '</h2>'
			. $env_section
			. '<h2 style="font-size:16px; border-bottom:1px solid #f0f0f1; padding-bottom:10px; margin-top:20px;">'
			. esc_html__( 'Active Plugins', 'wp-site-doctor' ) . '</h2>'
			. $plugins_section
			. $debug_section
			. '</div>';

		// Insert before the footer div.
		$footer_pos = strrpos( $summary, '<!-- Footer -->' );
		if ( false !== $footer_pos ) {
			$summary = substr_replace( $summary, $additional, $footer_pos, 0 );
		} else {
			// Fallback: insert before closing </div></body>.
			$summary = str_replace( '</body>', $additional . '</body>', $summary );
		}

		return $summary;
	}

	/**
	 * Send a report email.
	 *
	 * @param string $email      Recipient email address.
	 * @param string $session_id Scan session UUID.
	 * @param string $type       Report type: 'summary' or 'developer'.
	 * @return bool True if email sent.
	 */
	public function send_report( $email, $session_id, $type = 'summary' ) {
		if ( 'developer' === $type ) {
			$html = $this->generate_developer_report( $session_id );
		} else {
			$html = $this->generate_summary_report( $session_id );
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[WP Site Doctor] Health Report — %s', 'wp-site-doctor' ),
			$site_name
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $email, $subject, $html, $headers );
	}

	/**
	 * Build server environment section HTML for developer report.
	 *
	 * @return string HTML table.
	 */
	private function build_environment_section() {
		global $wpdb;

		$rows = array(
			__( 'WordPress Version', 'wp-site-doctor' )  => get_bloginfo( 'version' ),
			__( 'PHP Version', 'wp-site-doctor' )        => PHP_VERSION,
			__( 'Database', 'wp-site-doctor' )            => $wpdb->db_server_info(),
			__( 'Server Software', 'wp-site-doctor' )     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '—',
			__( 'PHP Memory Limit', 'wp-site-doctor' )    => ini_get( 'memory_limit' ),
			__( 'WP Memory Limit', 'wp-site-doctor' )     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—',
			__( 'Max Execution Time', 'wp-site-doctor' )  => ini_get( 'max_execution_time' ) . 's',
			__( 'Upload Max Filesize', 'wp-site-doctor' ) => ini_get( 'upload_max_filesize' ),
			__( 'Active Theme', 'wp-site-doctor' )        => wp_get_theme()->get( 'Name' ) . ' v' . wp_get_theme()->get( 'Version' ),
			__( 'Multisite', 'wp-site-doctor' )           => is_multisite() ? __( 'Yes', 'wp-site-doctor' ) : __( 'No', 'wp-site-doctor' ),
			__( 'HTTPS', 'wp-site-doctor' )               => is_ssl() ? __( 'Yes', 'wp-site-doctor' ) : __( 'No', 'wp-site-doctor' ),
			__( 'WP_DEBUG', 'wp-site-doctor' )            => defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'Enabled', 'wp-site-doctor' ) : __( 'Disabled', 'wp-site-doctor' ),
		);

		$html = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="padding:6px 8px; border-bottom:1px solid #f0f0f1; font-weight:600; width:40%;">' . esc_html( $label ) . '</td>';
			$html .= '<td style="padding:6px 8px; border-bottom:1px solid #f0f0f1;">' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</table>';

		return $html;
	}

	/**
	 * Build active plugins list HTML for developer report.
	 *
	 * @return string HTML table.
	 */
	private function build_plugins_section() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active     = (array) get_option( 'active_plugins', array() );
		$all        = get_plugins();
		$html       = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
		$html      .= '<tr><th style="padding:6px 8px; border-bottom:1px solid #c3c4c7; text-align:left;">' . esc_html__( 'Plugin', 'wp-site-doctor' ) . '</th>';
		$html      .= '<th style="padding:6px 8px; border-bottom:1px solid #c3c4c7; text-align:left;">' . esc_html__( 'Version', 'wp-site-doctor' ) . '</th></tr>';

		foreach ( $active as $plugin_path ) {
			if ( isset( $all[ $plugin_path ] ) ) {
				$html .= '<tr><td style="padding:4px 8px; border-bottom:1px solid #f0f0f1;">' . esc_html( $all[ $plugin_path ]['Name'] ) . '</td>';
				$html .= '<td style="padding:4px 8px; border-bottom:1px solid #f0f0f1;">' . esc_html( $all[ $plugin_path ]['Version'] ) . '</td></tr>';
			}
		}

		$html .= '</table>';
		return $html;
	}

	/**
	 * Build debug log excerpt for developer report.
	 *
	 * @return string HTML section or empty string.
	 */
	private function build_debug_section() {
		$log_path = WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return '';
		}

		// Read last 100 lines.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$content = file_get_contents( $log_path );

		if ( empty( $content ) ) {
			return '';
		}

		$lines = explode( "\n", $content );
		$last  = array_slice( $lines, -100 );
		$excerpt = implode( "\n", $last );

		$html  = '<h2 style="font-size:16px; border-bottom:1px solid #f0f0f1; padding-bottom:10px; margin-top:20px;">';
		$html .= esc_html__( 'Debug Log (last 100 lines)', 'wp-site-doctor' ) . '</h2>';
		$html .= '<pre style="background:#1d2327; color:#a7aaad; padding:15px; border-radius:4px; font-size:11px; overflow-x:auto; max-height:300px; white-space:pre-wrap;">';
		$html .= esc_html( $excerpt );
		$html .= '</pre>';

		return $html;
	}

	/**
	 * Get scan history entry by session ID.
	 *
	 * @param string $session_id Session UUID.
	 * @return object|null History row.
	 */
	private function get_history_by_session( $session_id ) {
		global $wpdb;

		$table = Database::get_table_name( Database::TABLE_SCAN_HISTORY );

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
				"SELECT * FROM {$table} WHERE scan_session_id = %s LIMIT 1",
				$session_id
			)
		);
	}
}
