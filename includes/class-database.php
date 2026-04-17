<?php
/**
 * Database table management for WP Site Doctor.
 *
 * Handles creation, version checking, and removal of custom tables.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 *
 * Manages the three custom tables used by WP Site Doctor:
 * - wpsd_scan_results: per-scanner results with JSON issues
 * - wpsd_scan_history: aggregate scan scores over time
 * - wpsd_repair_log: repair actions with restore data
 */
class Database {

	/**
	 * Table suffix constants.
	 */
	const TABLE_SCAN_RESULTS = 'wpsd_scan_results';
	const TABLE_SCAN_HISTORY = 'wpsd_scan_history';
	const TABLE_REPAIR_LOG   = 'wpsd_repair_log';

	/**
	 * Get the full table name with the site prefix.
	 *
	 * @param string $suffix Table suffix constant.
	 * @return string Full table name.
	 */
	public static function get_table_name( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	/**
	 * Create or update all custom tables.
	 *
	 * Uses dbDelta for safe table creation and upgrades.
	 * Called on plugin activation and version mismatch.
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$scan_results_table = self::get_table_name( self::TABLE_SCAN_RESULTS );
		$scan_history_table = self::get_table_name( self::TABLE_SCAN_HISTORY );
		$repair_log_table   = self::get_table_name( self::TABLE_REPAIR_LOG );

		// dbDelta requires:
		// - Each field on its own line
		// - Two spaces after PRIMARY KEY
		// - KEY (not INDEX) for indexes
		// - No IF NOT EXISTS
		$sql = "CREATE TABLE {$scan_results_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_session_id char(36) NOT NULL DEFAULT '',
			scanner_id varchar(50) NOT NULL DEFAULT '',
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			issues longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_session (scan_session_id),
			KEY idx_scanner (scanner_id)
		) {$charset_collate};

		CREATE TABLE {$scan_history_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_session_id char(36) NOT NULL DEFAULT '',
			health_score tinyint(3) unsigned NOT NULL DEFAULT 0,
			scanner_scores text NOT NULL,
			total_issues smallint(5) unsigned NOT NULL DEFAULT 0,
			critical_count smallint(5) unsigned NOT NULL DEFAULT 0,
			warning_count smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_session (scan_session_id),
			KEY idx_created (created_at)
		) {$charset_collate};

		CREATE TABLE {$repair_log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_session_id char(36) NOT NULL DEFAULT '',
			action_id varchar(100) NOT NULL DEFAULT '',
			action_label varchar(255) NOT NULL DEFAULT '',
			restore_data longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text,
			executed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			executed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_session (scan_session_id),
			KEY idx_status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wpsd_db_version', WPSD_DB_VERSION );
	}

	/**
	 * Check if database version matches plugin version and upgrade if needed.
	 *
	 * Hooked to plugins_loaded via the main plugin file.
	 */
	public static function check_version() {
		$installed_version = get_option( 'wpsd_db_version', '0' );

		if ( version_compare( $installed_version, WPSD_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Remove all plugin data from the database.
	 *
	 * Called from uninstall.php. Drops all custom tables, deletes
	 * all plugin options and transients.
	 */
	public static function uninstall() {
		global $wpdb;

		// Drop custom tables.
		$tables = array(
			self::get_table_name( self::TABLE_SCAN_RESULTS ),
			self::get_table_name( self::TABLE_SCAN_HISTORY ),
			self::get_table_name( self::TABLE_REPAIR_LOG ),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is from constant.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		// Delete plugin options.
		delete_option( 'wpsd_settings' );
		delete_option( 'wpsd_db_version' );

		// Delete all transients created by the plugin.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wpsd_%',
				'_transient_timeout_wpsd_%'
			)
		);

		// Clean up user meta (dismissible notices).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				'wpsd_%'
			)
		);
	}

	/**
	 * Store a scan result for a specific scanner.
	 *
	 * @param string $session_id UUID for this scan session.
	 * @param string $scanner_id Scanner identifier.
	 * @param int    $score      Scanner score (0-100).
	 * @param array  $issues     Array of issue data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function save_scan_result( $session_id, $scanner_id, $score, $issues ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name( self::TABLE_SCAN_RESULTS ),
			array(
				'scan_session_id' => sanitize_text_field( $session_id ),
				'scanner_id'      => sanitize_text_field( $scanner_id ),
				'score'           => absint( $score ),
				'issues'          => wp_json_encode( $issues ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Store the aggregate scan history entry.
	 *
	 * @param string $session_id    UUID for this scan session.
	 * @param int    $health_score  Composite health score (0-100).
	 * @param array  $scanner_scores Map of scanner_id => score.
	 * @param int    $total_issues  Total number of issues found.
	 * @param int    $critical_count Number of critical issues.
	 * @param int    $warning_count  Number of warning issues.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function save_scan_history( $session_id, $health_score, $scanner_scores, $total_issues, $critical_count, $warning_count ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name( self::TABLE_SCAN_HISTORY ),
			array(
				'scan_session_id' => sanitize_text_field( $session_id ),
				'health_score'    => absint( $health_score ),
				'scanner_scores'  => wp_json_encode( $scanner_scores ),
				'total_issues'    => absint( $total_issues ),
				'critical_count'  => absint( $critical_count ),
				'warning_count'   => absint( $warning_count ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%d', '%s' )
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get the latest scan history entry.
	 *
	 * @return object|null Latest scan row or null.
	 */
	public static function get_latest_scan() {
		global $wpdb;

		$table = self::get_table_name( self::TABLE_SCAN_HISTORY );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1" );
	}

	/**
	 * Get scan history for trend display.
	 *
	 * @param int $limit Number of entries to retrieve.
	 * @return array Array of scan history rows.
	 */
	public static function get_scan_history( $limit = 10 ) {
		global $wpdb;

		$table = self::get_table_name( self::TABLE_SCAN_HISTORY );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Get scan results for a specific session.
	 *
	 * @param string $session_id UUID of the scan session.
	 * @return array Array of scan result rows.
	 */
	public static function get_scan_results( $session_id ) {
		global $wpdb;

		$table = self::get_table_name( self::TABLE_SCAN_RESULTS );

		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
				"SELECT * FROM {$table} WHERE scan_session_id = %s ORDER BY id ASC",
				$session_id
			)
		);
	}

	/**
	 * Log a repair action.
	 *
	 * @param string $session_id   Scan session UUID.
	 * @param string $action_id    Machine-readable action identifier.
	 * @param string $action_label Human-readable action description.
	 * @param array  $restore_data Original values for potential rollback.
	 * @param string $status       Action status: pending, completed, failed, rolled_back.
	 * @param string $error        Error message if failed.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function log_repair_action( $session_id, $action_id, $action_label, $restore_data, $status = 'pending', $error = '' ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name( self::TABLE_REPAIR_LOG ),
			array(
				'scan_session_id' => sanitize_text_field( $session_id ),
				'action_id'       => sanitize_text_field( $action_id ),
				'action_label'    => sanitize_text_field( $action_label ),
				'restore_data'    => wp_json_encode( $restore_data ),
				'status'          => sanitize_text_field( $status ),
				'error_message'   => sanitize_text_field( $error ),
				'executed_at'     => current_time( 'mysql' ),
				'executed_by'     => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a repair log entry status.
	 *
	 * @param int    $log_id Log entry ID.
	 * @param string $status New status.
	 * @param string $error  Error message if applicable.
	 * @return bool True on success.
	 */
	public static function update_repair_status( $log_id, $status, $error = '' ) {
		global $wpdb;

		$data  = array( 'status' => sanitize_text_field( $status ) );
		$format = array( '%s' );

		if ( ! empty( $error ) ) {
			$data['error_message'] = sanitize_text_field( $error );
			$format[]              = '%s';
		}

		return false !== $wpdb->update(
			self::get_table_name( self::TABLE_REPAIR_LOG ),
			$data,
			array( 'id' => absint( $log_id ) ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Get a single repair log entry.
	 *
	 * @param int $log_id Log entry ID.
	 * @return object|null Log row or null.
	 */
	public static function get_repair_log_entry( $log_id ) {
		global $wpdb;

		$table = self::get_table_name( self::TABLE_REPAIR_LOG );

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
				"SELECT * FROM {$table} WHERE id = %d",
				$log_id
			)
		);
	}

	/**
	 * Get repair log entries, optionally filtered by session.
	 *
	 * @param string $session_id Optional session ID filter.
	 * @param int    $limit      Number of entries.
	 * @return array Array of repair log rows.
	 */
	public static function get_repair_log( $session_id = '', $limit = 50 ) {
		global $wpdb;

		$table = self::get_table_name( self::TABLE_REPAIR_LOG );

		if ( ! empty( $session_id ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
					"SELECT * FROM {$table} WHERE scan_session_id = %s ORDER BY executed_at DESC LIMIT %d",
					$session_id,
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY executed_at DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Prune old scan history and results beyond the configured retention period.
	 *
	 * Keeps the last N scan history entries based on settings.
	 *
	 * @param int $keep Number of scan history entries to keep.
	 */
	public static function prune_history( $keep = 10 ) {
		global $wpdb;

		$history_table = self::get_table_name( self::TABLE_SCAN_HISTORY );
		$results_table = self::get_table_name( self::TABLE_SCAN_RESULTS );

		// Get session IDs to delete.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from constants.
		$old_sessions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT scan_session_id FROM {$history_table} ORDER BY created_at DESC LIMIT 99999 OFFSET %d",
				$keep
			)
		);

		if ( empty( $old_sessions ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $old_sessions ), '%s' ) );

		// Delete old scan results.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$results_table} WHERE scan_session_id IN ({$placeholders})",
				$old_sessions
			)
		);

		// Delete old history entries.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$history_table} WHERE scan_session_id IN ({$placeholders})",
				$old_sessions
			)
		);
	}
}
