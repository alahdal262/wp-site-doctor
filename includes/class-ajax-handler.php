<?php
/**
 * AJAX Handler for WP Site Doctor.
 *
 * Central router for all AJAX endpoints. Every handler verifies
 * nonce and capability before processing.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Handler
 *
 * Registers and handles all wp_ajax actions for scanning, repair,
 * rollback, and report generation.
 */
class Ajax_Handler {

	/**
	 * Nonce action string used across all AJAX endpoints.
	 */
	const NONCE_ACTION = 'wpsd_nonce';

	/**
	 * Scan lock transient name.
	 */
	const SCAN_LOCK = 'wpsd_scan_lock';

	/**
	 * Repair lock transient name.
	 */
	const REPAIR_LOCK = 'wpsd_repair_lock';

	/**
	 * Lock expiry in seconds (5 minutes safety valve).
	 */
	const LOCK_EXPIRY = 300;

	/**
	 * Register all AJAX action hooks.
	 */
	public function register_handlers() {
		$actions = array(
			'wpsd_start_scan',
			'wpsd_run_scanner',
			'wpsd_finalize_scan',
			'wpsd_run_repair',
			'wpsd_rollback_repair',
			'wpsd_send_report',
			'wpsd_dismiss_notice',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle_' . str_replace( 'wpsd_', '', $action ) ) );
		}
	}

	/**
	 * Verify nonce and capability for an AJAX request.
	 *
	 * Sends JSON error and dies if verification fails.
	 */
	private function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( wpsd_required_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'wp-site-doctor' ) ),
				403
			);
		}
	}

	/**
	 * Handle wpsd_start_scan: Initialize a new scan session.
	 *
	 * Creates a session UUID, sets the scan lock transient, and returns
	 * the list of scanners to run.
	 */
	public function handle_start_scan() {
		$this->verify_request();

		// Check for concurrent scan.
		if ( get_transient( self::SCAN_LOCK ) ) {
			wp_send_json_error(
				array( 'message' => __( 'A scan is already in progress. Please wait for it to complete.', 'wp-site-doctor' ) )
			);
		}

		// Generate a unique session ID.
		$session_id = wp_generate_uuid4();

		// Set the scan lock.
		set_transient( self::SCAN_LOCK, $session_id, self::LOCK_EXPIRY );

		// Get the list of scanners, excluding any disabled ones.
		$excluded = Settings::get( 'excluded_checks', array() );
		$scanners = array(
			'server_environment' => __( 'Server Environment', 'wp-site-doctor' ),
			'security'           => __( 'Security', 'wp-site-doctor' ),
			'performance'        => __( 'Performance', 'wp-site-doctor' ),
			'database'           => __( 'Database', 'wp-site-doctor' ),
			'cache'              => __( 'Cache', 'wp-site-doctor' ),
			'file_permissions'   => __( 'File Permissions', 'wp-site-doctor' ),
			'cron'               => __( 'Cron Jobs', 'wp-site-doctor' ),
			'seo'                => __( 'SEO', 'wp-site-doctor' ),
			'images'             => __( 'Images', 'wp-site-doctor' ),
			'plugin_conflicts'   => __( 'Plugin Conflicts', 'wp-site-doctor' ),
			'plugin_xray'        => __( 'Plugin X-Ray', 'wp-site-doctor' ),
			'storage'            => __( 'Storage & Cleanup', 'wp-site-doctor' ),
		);

		// Remove excluded scanners.
		foreach ( $excluded as $excluded_id ) {
			unset( $scanners[ $excluded_id ] );
		}

		$scanner_list = array();
		foreach ( $scanners as $id => $label ) {
			$scanner_list[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}

		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'scanners'   => $scanner_list,
				'total'      => count( $scanner_list ),
			)
		);
	}

	/**
	 * Handle wpsd_run_scanner: Execute a single scanner module.
	 *
	 * Receives scanner_id and session_id, runs the scanner, stores the result,
	 * and returns the issues and score.
	 */
	public function handle_run_scanner() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_request().
		$scanner_id = isset( $_POST['scanner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scanner_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $scanner_id ) || empty( $session_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing required parameters.', 'wp-site-doctor' ) )
			);
		}

		// Verify the scan lock matches this session.
		$lock = get_transient( self::SCAN_LOCK );
		if ( $lock !== $session_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Scan session expired or invalid. Please start a new scan.', 'wp-site-doctor' ) )
			);
		}

		// Refresh the lock expiry.
		set_transient( self::SCAN_LOCK, $session_id, self::LOCK_EXPIRY );

		try {
			$engine  = new Scanner_Engine();
			$scanner = $engine->get_scanner( $scanner_id );

			if ( ! $scanner ) {
				wp_send_json_error(
					array(
						'message'    => sprintf(
							/* translators: %s: scanner ID */
							__( 'Unknown scanner: %s', 'wp-site-doctor' ),
							$scanner_id
						),
						'scanner_id' => $scanner_id,
					)
				);
			}

			$start_time = microtime( true );
			$result     = $scanner->run();
			$duration   = round( microtime( true ) - $start_time, 2 );

			// Store the result.
			Database::save_scan_result(
				$session_id,
				$result['scanner_id'],
				$result['score'],
				$result['issues']
			);

			wp_send_json_success(
				array(
					'scanner_id' => $result['scanner_id'],
					'category'   => $result['category'],
					'score'      => $result['score'],
					'issues'     => $result['issues'],
					'duration'   => $duration,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message'    => sprintf(
						/* translators: 1: scanner ID, 2: error message */
						__( 'Scanner "%1$s" failed: %2$s', 'wp-site-doctor' ),
						$scanner_id,
						$e->getMessage()
					),
					'scanner_id' => $scanner_id,
				)
			);
		}
	}

	/**
	 * Handle wpsd_finalize_scan: Compute aggregate score and store history.
	 *
	 * Called after all individual scanners have completed.
	 */
	public function handle_finalize_scan() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing session ID.', 'wp-site-doctor' ) )
			);
		}

		// Get all results for this session.
		$results = Database::get_scan_results( $session_id );

		if ( empty( $results ) ) {
			delete_transient( self::SCAN_LOCK );
			wp_send_json_error(
				array( 'message' => __( 'No scan results found for this session.', 'wp-site-doctor' ) )
			);
		}

		// Build scanner scores map and count issues.
		$scanner_scores = array();
		$total_issues   = 0;
		$critical_count = 0;
		$warning_count  = 0;

		foreach ( $results as $result ) {
			$scanner_scores[ $result->scanner_id ] = absint( $result->score );

			$issues = json_decode( $result->issues, true );
			if ( is_array( $issues ) ) {
				$total_issues += count( $issues );
				foreach ( $issues as $issue ) {
					if ( isset( $issue['severity'] ) ) {
						if ( 'critical' === $issue['severity'] ) {
							++$critical_count;
						} elseif ( 'warning' === $issue['severity'] ) {
							++$warning_count;
						}
					}
				}
			}
		}

		// Calculate the composite health score.
		$health_score_calculator = new Health_Score();
		$health_score            = $health_score_calculator->calculate( $scanner_scores );

		// Store in scan history.
		Database::save_scan_history(
			$session_id,
			$health_score,
			$scanner_scores,
			$total_issues,
			$critical_count,
			$warning_count
		);

		// Prune old scan history.
		Database::prune_history( 10 );

		// Clear the scan lock.
		delete_transient( self::SCAN_LOCK );

		// Get the previous scan for comparison.
		$history       = Database::get_scan_history( 2 );
		$previous_score = ( count( $history ) > 1 ) ? absint( $history[1]->health_score ) : null;

		wp_send_json_success(
			array(
				'session_id'     => $session_id,
				'health_score'   => $health_score,
				'grade'          => $health_score_calculator->get_grade( $health_score ),
				'color'          => $health_score_calculator->get_color( $health_score ),
				'scanner_scores' => $scanner_scores,
				'total_issues'   => $total_issues,
				'critical_count' => $critical_count,
				'warning_count'  => $warning_count,
				'previous_score' => $previous_score,
			)
		);
	}

	/**
	 * Handle wpsd_run_repair: Execute a single repair action.
	 */
	public function handle_run_repair() {
		$this->verify_request();

		// Check for concurrent repair.
		if ( get_transient( self::REPAIR_LOCK ) ) {
			wp_send_json_error(
				array( 'message' => __( 'A repair is already in progress. Please wait for it to complete.', 'wp-site-doctor' ) )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action_id  = isset( $_POST['action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $action_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing repair action ID.', 'wp-site-doctor' ) )
			);
		}

		// Set the repair lock.
		set_transient( self::REPAIR_LOCK, $action_id, self::LOCK_EXPIRY );

		try {
			$repair = new Auto_Repair();
			$result = $repair->execute( $action_id, $session_id );

			delete_transient( self::REPAIR_LOCK );

			if ( $result['success'] ) {
				wp_send_json_success(
					array(
						'action_id' => $action_id,
						'message'   => $result['message'],
						'log_id'    => $result['log_id'],
					)
				);
			} else {
				wp_send_json_error(
					array(
						'action_id' => $action_id,
						'message'   => $result['message'],
					)
				);
			}
		} catch ( \Exception $e ) {
			delete_transient( self::REPAIR_LOCK );

			wp_send_json_error(
				array(
					'action_id' => $action_id,
					'message'   => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Handle wpsd_rollback_repair: Reverse a previously executed repair action.
	 */
	public function handle_rollback_repair() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;

		if ( ! $log_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing repair log ID.', 'wp-site-doctor' ) )
			);
		}

		$log_entry = Database::get_repair_log_entry( $log_id );

		if ( ! $log_entry ) {
			wp_send_json_error(
				array( 'message' => __( 'Repair log entry not found.', 'wp-site-doctor' ) )
			);
		}

		if ( 'completed' !== $log_entry->status ) {
			wp_send_json_error(
				array( 'message' => __( 'Only completed repairs can be rolled back.', 'wp-site-doctor' ) )
			);
		}

		try {
			$restore_point = new Restore_Point();

			if ( ! $restore_point->can_rollback( $log_id ) ) {
				wp_send_json_error(
					array( 'message' => __( 'This action cannot be rolled back.', 'wp-site-doctor' ) )
				);
			}

			$result = $restore_point->rollback( $log_id );

			if ( $result ) {
				wp_send_json_success(
					array(
						'log_id'  => $log_id,
						'message' => __( 'Rollback completed successfully.', 'wp-site-doctor' ),
					)
				);
			} else {
				wp_send_json_error(
					array( 'message' => __( 'Rollback failed. Check the repair log for details.', 'wp-site-doctor' ) )
				);
			}
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array( 'message' => $e->getMessage() )
			);
		}
	}

	/**
	 * Handle wpsd_send_report: Generate and email a diagnostic report.
	 */
	public function handle_send_report() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$report_type = isset( $_POST['report_type'] ) ? sanitize_text_field( wp_unslash( $_POST['report_type'] ) ) : 'summary';

		if ( empty( $session_id ) ) {
			// Use latest scan if no session specified.
			$latest = Database::get_latest_scan();
			if ( $latest ) {
				$session_id = $latest->scan_session_id;
			}
		}

		if ( empty( $session_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No scan data available. Please run a scan first.', 'wp-site-doctor' ) )
			);
		}

		if ( empty( $email ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please provide an email address.', 'wp-site-doctor' ) )
			);
		}

		try {
			$report  = new Report_Generator();
			$success = $report->send_report( $email, $session_id, $report_type );

			if ( $success ) {
				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %s: email address */
							__( 'Report sent successfully to %s.', 'wp-site-doctor' ),
							$email
						),
					)
				);
			} else {
				wp_send_json_error(
					array( 'message' => __( 'Failed to send the report. Please check your email settings.', 'wp-site-doctor' ) )
				);
			}
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array( 'message' => $e->getMessage() )
			);
		}
	}

	/**
	 * Handle wpsd_dismiss_notice: Dismiss a plugin admin notice.
	 */
	public function handle_dismiss_notice() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';

		if ( empty( $notice_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing notice ID.', 'wp-site-doctor' ) )
			);
		}

		update_user_meta( get_current_user_id(), 'wpsd_dismissed_' . $notice_id, true );

		wp_send_json_success();
	}
}
