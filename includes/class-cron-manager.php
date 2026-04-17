<?php
/**
 * Cron Manager for WP Site Doctor.
 *
 * Handles scheduled scan execution, health score alerts,
 * and custom cron schedule registration.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron_Manager
 *
 * Manages scheduled scans via WP-Cron and sends alert emails
 * when the health score drops below the configured threshold.
 */
class Cron_Manager {

	/**
	 * Cron hook name for scheduled scans.
	 */
	const SCAN_HOOK = 'wpsd_scheduled_scan';

	/**
	 * Cron hook name for the initial one-time scan.
	 */
	const INITIAL_HOOK = 'wpsd_initial_scan';

	/**
	 * Initialize cron manager hooks.
	 */
	public function init() {
		add_action( self::SCAN_HOOK, array( $this, 'run_scheduled_scan' ) );
		add_action( self::INITIAL_HOOK, array( $this, 'run_scheduled_scan' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );

		// Update cron schedule when settings change.
		add_action( 'update_option_wpsd_settings', array( $this, 'reschedule' ), 10, 2 );
	}

	/**
	 * Add custom cron schedules (weekly).
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public function add_custom_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'wp-site-doctor' ),
			);
		}

		return $schedules;
	}

	/**
	 * Run a scheduled scan.
	 *
	 * Executes all scanners, computes health score, stores results,
	 * and sends an alert email if the score dropped below threshold.
	 */
	public function run_scheduled_scan() {
		// Check scan lock.
		if ( get_transient( 'wpsd_scan_lock' ) ) {
			return;
		}

		$session_id = wp_generate_uuid4();
		set_transient( 'wpsd_scan_lock', $session_id, 300 );

		try {
			$engine  = new Scanner_Engine();
			$results = $engine->run_all();

			// Store individual results.
			$scanner_scores = array();
			$total_issues   = 0;
			$critical_count = 0;
			$warning_count  = 0;

			foreach ( $results as $scanner_id => $result ) {
				Database::save_scan_result(
					$session_id,
					$result['scanner_id'],
					$result['score'],
					$result['issues']
				);

				$scanner_scores[ $scanner_id ] = $result['score'];

				if ( is_array( $result['issues'] ) ) {
					$total_issues += count( $result['issues'] );
					foreach ( $result['issues'] as $issue ) {
						if ( 'critical' === ( $issue['severity'] ?? '' ) ) {
							++$critical_count;
						} elseif ( 'warning' === ( $issue['severity'] ?? '' ) ) {
							++$warning_count;
						}
					}
				}
			}

			// Calculate health score.
			$calculator   = new Health_Score();
			$health_score = $calculator->calculate( $scanner_scores );

			// Store in history.
			Database::save_scan_history(
				$session_id,
				$health_score,
				$scanner_scores,
				$total_issues,
				$critical_count,
				$warning_count
			);

			// Prune old history.
			Database::prune_history( 10 );

			// Check if alert should be sent.
			$this->maybe_send_alert( $health_score, $session_id );

		} catch ( \Exception $e ) {
			// Silently fail for cron — log to debug.log if available.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP Site Doctor scheduled scan failed: ' . $e->getMessage() );
			}
		}

		delete_transient( 'wpsd_scan_lock' );
	}

	/**
	 * Check if an alert email should be sent based on health score.
	 *
	 * Sends alert if:
	 * - Score is below the configured threshold, OR
	 * - Score dropped by 10+ points from the previous scan
	 *
	 * @param int    $current_score Current health score.
	 * @param string $session_id    Current scan session UUID.
	 */
	private function maybe_send_alert( $current_score, $session_id ) {
		$threshold = (int) Settings::get( 'health_alert_threshold', 60 );
		$email     = Settings::get( 'admin_email', get_option( 'admin_email' ) );

		if ( empty( $email ) ) {
			return;
		}

		$should_alert  = false;
		$alert_reasons = array();

		// Check threshold.
		if ( $current_score < $threshold ) {
			$should_alert    = true;
			$alert_reasons[] = sprintf(
				/* translators: 1: current score, 2: threshold */
				__( 'Health score (%1$d) is below your alert threshold (%2$d).', 'wp-site-doctor' ),
				$current_score,
				$threshold
			);
		}

		// Check for significant drop.
		$history = Database::get_scan_history( 2 );

		if ( count( $history ) >= 2 ) {
			$previous = absint( $history[1]->health_score );
			$drop     = $previous - $current_score;

			if ( $drop >= 10 ) {
				$should_alert    = true;
				$alert_reasons[] = sprintf(
					/* translators: 1: drop amount, 2: previous score, 3: current score */
					__( 'Score dropped by %1$d points (from %2$d to %3$d) since the last scan.', 'wp-site-doctor' ),
					$drop,
					$previous,
					$current_score
				);
			}
		}

		if ( ! $should_alert ) {
			return;
		}

		$this->send_alert_email( $email, $current_score, $alert_reasons, $session_id );
	}

	/**
	 * Send a health score alert email.
	 *
	 * @param string $email      Recipient email.
	 * @param int    $score      Current health score.
	 * @param array  $reasons    Array of alert reason strings.
	 * @param string $session_id Scan session UUID.
	 */
	private function send_alert_email( $email, $score, $reasons, $session_id ) {
		$site_name   = get_bloginfo( 'name' );
		$score_color = ( new Health_Score() )->get_color( $score );
		$grade       = Health_Score::get_grade_label( $score );
		$dashboard   = admin_url( 'admin.php?page=wp-site-doctor' );

		$subject = sprintf(
			/* translators: 1: site name, 2: score */
			__( '[WP Site Doctor] Health Alert — %1$s (Score: %2$d)', 'wp-site-doctor' ),
			$site_name,
			$score
		);

		$body  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
		$body .= '<body style="margin:0; padding:0; background:#f6f7f7; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
		$body .= '<div style="max-width:500px; margin:20px auto; background:#fff; border:1px solid #c3c4c7; border-radius:4px;">';

		// Header.
		$body .= '<div style="background:#d63638; color:#fff; padding:15px 20px; border-radius:4px 4px 0 0;">';
		$body .= '<h2 style="margin:0; font-size:18px;">&#9888; Health Score Alert</h2>';
		$body .= '</div>';

		// Score.
		$body .= '<div style="text-align:center; padding:20px;">';
		$body .= '<div style="font-size:48px; font-weight:700; color:' . esc_attr( $score_color ) . ';">' . esc_html( $score ) . '</div>';
		$body .= '<div style="font-size:16px; color:' . esc_attr( $score_color ) . ';">' . esc_html( $grade ) . '</div>';
		$body .= '</div>';

		// Reasons.
		$body .= '<div style="padding:0 20px 20px;">';
		foreach ( $reasons as $reason ) {
			$body .= '<p style="margin:8px 0; padding:10px; background:#fcecec; border-left:4px solid #d63638; border-radius:0 4px 4px 0; font-size:13px;">';
			$body .= esc_html( $reason ) . '</p>';
		}
		$body .= '</div>';

		// CTA.
		$body .= '<div style="padding:0 20px 20px; text-align:center;">';
		$body .= '<a href="' . esc_url( $dashboard ) . '" style="display:inline-block; padding:10px 24px; background:#2271b1; color:#fff; text-decoration:none; border-radius:4px; font-weight:600;">';
		$body .= esc_html__( 'View Full Report', 'wp-site-doctor' ) . '</a>';
		$body .= '</div>';

		// Footer.
		$body .= '<div style="padding:10px 20px; background:#f6f7f7; border-top:1px solid #c3c4c7; border-radius:0 0 4px 4px; text-align:center; font-size:11px; color:#646970;">';
		$body .= esc_html( home_url() ) . ' &mdash; WP Site Doctor v' . esc_html( WPSD_VERSION );
		$body .= '</div></div></body></html>';

		wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Reschedule cron when settings are updated.
	 *
	 * @param mixed $old_value Previous settings value.
	 * @param mixed $new_value New settings value.
	 */
	public function reschedule( $old_value, $new_value ) {
		// Clear existing schedule.
		wp_clear_scheduled_hook( self::SCAN_HOOK );

		$schedule = isset( $new_value['auto_scan_schedule'] ) ? $new_value['auto_scan_schedule'] : 'off';

		if ( 'off' === $schedule ) {
			return;
		}

		$recurrence = ( 'weekly' === $schedule ) ? 'weekly' : 'daily';

		// Schedule starting tomorrow at 3 AM site time.
		$next_run = strtotime( 'tomorrow 3:00am', current_time( 'timestamp' ) );

		wp_schedule_event( $next_run, $recurrence, self::SCAN_HOOK );
	}

	/**
	 * Get the next scheduled scan time.
	 *
	 * @return int|false Unix timestamp or false if not scheduled.
	 */
	public static function get_next_scheduled() {
		return wp_next_scheduled( self::SCAN_HOOK );
	}

	/**
	 * Check if scheduled scans are enabled.
	 *
	 * @return bool True if enabled.
	 */
	public static function is_enabled() {
		$schedule = Settings::get( 'auto_scan_schedule', 'off' );
		return 'off' !== $schedule;
	}
}
