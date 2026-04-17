<?php
/**
 * Repair Logger for WP Site Doctor.
 *
 * Provides audit logging for every repair action with timestamps,
 * user attribution, and status tracking.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Repair_Logger
 *
 * Wraps Database::log_repair_action with additional audit logic:
 * retention pruning, user context, and formatted output.
 */
class Repair_Logger {

	/**
	 * Log a repair action start (pending status).
	 *
	 * @param string $session_id   Scan session UUID.
	 * @param string $action_id    Machine-readable action identifier.
	 * @param string $action_label Human-readable action description.
	 * @param array  $restore_data Data captured before the action for rollback.
	 * @return int|false Log entry ID or false on failure.
	 */
	public function log_start( $session_id, $action_id, $action_label, $restore_data = array() ) {
		return Database::log_repair_action(
			$session_id,
			$action_id,
			$action_label,
			$restore_data,
			'pending'
		);
	}

	/**
	 * Log a successful repair action completion.
	 *
	 * @param int $log_id Log entry ID.
	 * @return bool True on success.
	 */
	public function log_success( $log_id ) {
		return Database::update_repair_status( $log_id, 'completed' );
	}

	/**
	 * Log a failed repair action.
	 *
	 * @param int    $log_id Log entry ID.
	 * @param string $error  Error message.
	 * @return bool True on success.
	 */
	public function log_failure( $log_id, $error ) {
		return Database::update_repair_status( $log_id, 'failed', $error );
	}

	/**
	 * Log a rollback.
	 *
	 * @param int $log_id Log entry ID.
	 * @return bool True on success.
	 */
	public function log_rollback( $log_id ) {
		return Database::update_repair_status( $log_id, 'rolled_back' );
	}

	/**
	 * Get recent repair log entries.
	 *
	 * @param int $limit Number of entries to retrieve.
	 * @return array Array of log entry objects.
	 */
	public function get_recent( $limit = 50 ) {
		return Database::get_repair_log( '', $limit );
	}

	/**
	 * Get repair log entries for a specific scan session.
	 *
	 * @param string $session_id Scan session UUID.
	 * @return array Array of log entry objects.
	 */
	public function get_by_session( $session_id ) {
		return Database::get_repair_log( $session_id );
	}

	/**
	 * Get a summary of repair actions for a session.
	 *
	 * @param string $session_id Scan session UUID.
	 * @return array Summary with counts by status.
	 */
	public function get_session_summary( $session_id ) {
		$entries = $this->get_by_session( $session_id );

		$summary = array(
			'total'       => count( $entries ),
			'completed'   => 0,
			'failed'      => 0,
			'rolled_back' => 0,
			'pending'     => 0,
		);

		foreach ( $entries as $entry ) {
			if ( isset( $summary[ $entry->status ] ) ) {
				++$summary[ $entry->status ];
			}
		}

		return $summary;
	}

	/**
	 * Prune old repair log entries beyond the configured retention limit.
	 *
	 * Keeps the most recent N entries based on settings.
	 */
	public function prune() {
		global $wpdb;

		$limit = (int) Settings::get( 'restore_point_limit', 5 );

		// Keep at least the last $limit * 10 entries (multiple actions per session).
		$keep = max( 50, $limit * 10 );

		$table = Database::get_table_name( Database::TABLE_REPAIR_LOG );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count <= $keep ) {
			return;
		}

		// Delete oldest entries beyond the retention limit.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY executed_at DESC LIMIT %d) AS recent)",
				$keep
			)
		);
	}

	/**
	 * Format a log entry for display.
	 *
	 * @param object $entry Log entry database row.
	 * @return array Formatted entry data.
	 */
	public function format_entry( $entry ) {
		$user     = get_userdata( $entry->executed_by );
		$restore  = json_decode( $entry->restore_data, true );

		return array(
			'id'            => $entry->id,
			'action_id'     => $entry->action_id,
			'action_label'  => $entry->action_label,
			'status'        => $entry->status,
			'status_label'  => $this->get_status_label( $entry->status ),
			'error'         => $entry->error_message,
			'date'          => wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $entry->executed_at )
			),
			'user'          => $user ? $user->display_name : __( 'Unknown', 'wp-site-doctor' ),
			'can_rollback'  => (
				'completed' === $entry->status
				&& is_array( $restore )
				&& empty( $restore['irreversible'] )
			),
			'is_irreversible' => ( is_array( $restore ) && ! empty( $restore['irreversible'] ) ),
		);
	}

	/**
	 * Get a human-readable status label.
	 *
	 * @param string $status Status string.
	 * @return string Translated label.
	 */
	private function get_status_label( $status ) {
		$labels = array(
			'pending'     => __( 'Pending', 'wp-site-doctor' ),
			'completed'   => __( 'Completed', 'wp-site-doctor' ),
			'failed'      => __( 'Failed', 'wp-site-doctor' ),
			'rolled_back' => __( 'Rolled Back', 'wp-site-doctor' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}
}
