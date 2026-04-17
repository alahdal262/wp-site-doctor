<?php
/**
 * Restore Point manager for WP Site Doctor.
 *
 * Creates per-action restore points before repair execution,
 * reads restore data, and performs rollbacks when possible.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Restore_Point
 *
 * Restore points are stored in the wpsd_repair_log table's restore_data column.
 * Each restore point captures the exact data that will change so it can be reversed.
 */
class Restore_Point {

	/**
	 * Rollback handlers keyed by action_id.
	 *
	 * Each handler receives the decoded restore_data array and reverses the change.
	 *
	 * @var array
	 */
	private $rollback_handlers = array();

	/**
	 * Constructor. Registers built-in rollback handlers.
	 */
	public function __construct() {
		$this->register_handlers();
	}

	/**
	 * Register built-in rollback handlers for reversible actions.
	 */
	private function register_handlers() {
		$this->rollback_handlers = array(
			'disable_xmlrpc'        => array( $this, 'rollback_disable_xmlrpc' ),
			'disable_user_enum'     => array( $this, 'rollback_disable_user_enum' ),
			'add_security_headers'  => array( $this, 'rollback_security_headers' ),
			'disable_file_edit'     => array( $this, 'rollback_disable_file_edit' ),
			'convert_myisam_innodb' => array( $this, 'rollback_convert_innodb' ),
			'deactivate_plugin'     => array( $this, 'rollback_deactivate_plugin' ),
		);

		/**
		 * Filter rollback handlers to allow extensions.
		 *
		 * @param array $handlers Map of action_id => callable.
		 */
		$this->rollback_handlers = apply_filters( 'wpsd_rollback_handlers', $this->rollback_handlers );
	}

	/**
	 * Create a restore point before executing a repair action.
	 *
	 * @param string $session_id  Scan session UUID.
	 * @param string $action_id   Machine-readable action identifier.
	 * @param string $action_label Human-readable action description.
	 * @param array  $restore_data Original values for potential rollback.
	 * @return int|false Log entry ID on success, false on failure.
	 */
	public function create( $session_id, $action_id, $action_label, $restore_data ) {
		return Database::log_repair_action(
			$session_id,
			$action_id,
			$action_label,
			$restore_data,
			'pending'
		);
	}

	/**
	 * Mark a restore point as completed (action was executed successfully).
	 *
	 * @param int $log_id Log entry ID.
	 * @return bool True on success.
	 */
	public function mark_completed( $log_id ) {
		return Database::update_repair_status( $log_id, 'completed' );
	}

	/**
	 * Mark a restore point as failed.
	 *
	 * @param int    $log_id Log entry ID.
	 * @param string $error  Error message.
	 * @return bool True on success.
	 */
	public function mark_failed( $log_id, $error = '' ) {
		return Database::update_repair_status( $log_id, 'failed', $error );
	}

	/**
	 * Check if a repair action can be rolled back.
	 *
	 * An action can be rolled back if:
	 * 1. Its status is 'completed'
	 * 2. Its restore_data does not have 'irreversible' = true
	 * 3. A rollback handler exists for its action_id
	 *
	 * @param int $log_id Repair log entry ID.
	 * @return bool True if rollback is possible.
	 */
	public function can_rollback( $log_id ) {
		$entry = Database::get_repair_log_entry( $log_id );

		if ( ! $entry || 'completed' !== $entry->status ) {
			return false;
		}

		$restore_data = json_decode( $entry->restore_data, true );

		if ( ! is_array( $restore_data ) ) {
			return false;
		}

		// Check if explicitly marked as irreversible.
		if ( ! empty( $restore_data['irreversible'] ) ) {
			return false;
		}

		// Check if a handler exists.
		return isset( $this->rollback_handlers[ $entry->action_id ] );
	}

	/**
	 * Execute a rollback for a previously completed repair action.
	 *
	 * @param int $log_id Repair log entry ID.
	 * @return bool True on successful rollback.
	 * @throws \RuntimeException If rollback fails.
	 */
	public function rollback( $log_id ) {
		$entry = Database::get_repair_log_entry( $log_id );

		if ( ! $entry ) {
			throw new \RuntimeException( __( 'Repair log entry not found.', 'wp-site-doctor' ) );
		}

		if ( 'completed' !== $entry->status ) {
			throw new \RuntimeException( __( 'Only completed repairs can be rolled back.', 'wp-site-doctor' ) );
		}

		$restore_data = json_decode( $entry->restore_data, true );

		if ( ! is_array( $restore_data ) || ! empty( $restore_data['irreversible'] ) ) {
			throw new \RuntimeException( __( 'This action is irreversible and cannot be rolled back.', 'wp-site-doctor' ) );
		}

		if ( ! isset( $this->rollback_handlers[ $entry->action_id ] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: action ID */
					__( 'No rollback handler registered for action: %s', 'wp-site-doctor' ),
					$entry->action_id
				)
			);
		}

		try {
			$handler = $this->rollback_handlers[ $entry->action_id ];
			$result  = call_user_func( $handler, $restore_data );

			if ( $result ) {
				Database::update_repair_status( $log_id, 'rolled_back' );
				return true;
			}

			Database::update_repair_status( $log_id, 'completed', __( 'Rollback attempted but returned false.', 'wp-site-doctor' ) );
			return false;
		} catch ( \Exception $e ) {
			Database::update_repair_status( $log_id, 'completed', $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Get restore data for a log entry.
	 *
	 * @param int $log_id Log entry ID.
	 * @return array|null Decoded restore data or null.
	 */
	public function get_restore_data( $log_id ) {
		$entry = Database::get_repair_log_entry( $log_id );

		if ( ! $entry ) {
			return null;
		}

		return json_decode( $entry->restore_data, true );
	}

	// ──────────────────────────────────────────────
	// Built-in rollback handlers
	// ──────────────────────────────────────────────

	/**
	 * Rollback: Re-enable XML-RPC by removing the mu-plugin.
	 *
	 * @param array $data Restore data.
	 * @return bool Success.
	 */
	private function rollback_disable_xmlrpc( $data ) {
		$mu_file = WPMU_PLUGIN_DIR . '/wpsd-disable-xmlrpc.php';

		if ( file_exists( $mu_file ) ) {
			return wp_delete_file( $mu_file ) || ! file_exists( $mu_file );
		}

		return true; // Already removed.
	}

	/**
	 * Rollback: Re-enable REST API user enumeration by removing the mu-plugin.
	 *
	 * @param array $data Restore data.
	 * @return bool Success.
	 */
	private function rollback_disable_user_enum( $data ) {
		$mu_file = WPMU_PLUGIN_DIR . '/wpsd-disable-user-enum.php';

		if ( file_exists( $mu_file ) ) {
			return wp_delete_file( $mu_file ) || ! file_exists( $mu_file );
		}

		return true;
	}

	/**
	 * Rollback: Remove security headers from .htaccess.
	 *
	 * @param array $data Restore data containing 'htaccess_backup'.
	 * @return bool Success.
	 */
	private function rollback_security_headers( $data ) {
		if ( empty( $data['htaccess_backup'] ) ) {
			return false;
		}

		$htaccess = ABSPATH . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			return false;
		}

		// Restore the original .htaccess content.
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return false;
		}

		return $wp_filesystem->put_contents( $htaccess, $data['htaccess_backup'], FS_CHMOD_FILE );
	}

	/**
	 * Rollback: Re-enable file editing by removing the mu-plugin.
	 *
	 * @param array $data Restore data.
	 * @return bool Success.
	 */
	private function rollback_disable_file_edit( $data ) {
		$mu_file = WPMU_PLUGIN_DIR . '/wpsd-disable-file-edit.php';

		if ( file_exists( $mu_file ) ) {
			return wp_delete_file( $mu_file ) || ! file_exists( $mu_file );
		}

		return true;
	}

	/**
	 * Rollback: Convert InnoDB tables back to MyISAM.
	 *
	 * @param array $data Restore data containing 'tables' array.
	 * @return bool Success.
	 */
	private function rollback_convert_innodb( $data ) {
		if ( empty( $data['tables'] ) || ! is_array( $data['tables'] ) ) {
			return false;
		}

		global $wpdb;
		$success = true;

		foreach ( $data['tables'] as $table_name ) {
			$safe_table = esc_sql( $table_name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped above.
			$result = $wpdb->query( "ALTER TABLE `{$safe_table}` ENGINE = MyISAM" );
			if ( false === $result ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Rollback: Re-activate a deactivated plugin.
	 *
	 * @param array $data Restore data containing 'plugin_path'.
	 * @return bool Success.
	 */
	private function rollback_deactivate_plugin( $data ) {
		if ( empty( $data['plugin_path'] ) ) {
			return false;
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $data['plugin_path'] );

		return ! is_wp_error( $result );
	}
}
