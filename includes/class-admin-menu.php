<?php
/**
 * Admin Menu registration for WP Site Doctor.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Menu
 *
 * Registers the top-level "Site Doctor" menu and all submenu pages.
 */
class Admin_Menu {

	/**
	 * Required capability for accessing plugin pages.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->capability = wpsd_required_capability();
	}

	/**
	 * Register all menu and submenu pages.
	 *
	 * @return array Array of page hook suffixes for conditional asset loading.
	 */
	public function register() {
		$hook_suffixes = array();

		// Top-level menu: Site Doctor.
		$hook_suffixes[] = add_menu_page(
			__( 'Site Doctor', 'wp-site-doctor' ),
			__( 'Site Doctor', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor',
			array( $this, 'render_dashboard' ),
			'dashicons-heart',
			80
		);

		// Submenu: Dashboard (same as parent).
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Dashboard', 'wp-site-doctor' ),
			__( 'Dashboard', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor',
			array( $this, 'render_dashboard' )
		);

		// Submenu: Plugin X-Ray.
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Plugin X-Ray', 'wp-site-doctor' ),
			__( 'Plugin X-Ray', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor-xray',
			array( $this, 'render_plugin_xray' )
		);

		// Submenu: Auto-Repair.
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Auto-Repair', 'wp-site-doctor' ),
			__( 'Auto-Repair', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor-repair',
			array( $this, 'render_auto_repair' )
		);

		// Submenu: Repair Log.
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Repair Log', 'wp-site-doctor' ),
			__( 'Repair Log', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor-repair-log',
			array( $this, 'render_repair_log' )
		);

		// Submenu: Reports.
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Reports', 'wp-site-doctor' ),
			__( 'Reports', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor-reports',
			array( $this, 'render_reports' )
		);

		// Submenu: Settings.
		$hook_suffixes[] = add_submenu_page(
			'wp-site-doctor',
			__( 'Settings', 'wp-site-doctor' ),
			__( 'Settings', 'wp-site-doctor' ),
			$this->capability,
			'wp-site-doctor-settings',
			array( $this, 'render_settings' )
		);

		return array_filter( $hook_suffixes );
	}

	/**
	 * Render the main dashboard page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		$latest_scan = Database::get_latest_scan();
		$scan_results = null;

		if ( $latest_scan ) {
			$scan_results = Database::get_scan_results( $latest_scan->scan_session_id );
		}

		include WPSD_PLUGIN_DIR . 'templates/dashboard.php';
	}

	/**
	 * Render the Plugin X-Ray page.
	 */
	public function render_plugin_xray() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		$latest_scan = Database::get_latest_scan();
		$xray_data   = null;

		if ( $latest_scan ) {
			$results = Database::get_scan_results( $latest_scan->scan_session_id );
			foreach ( $results as $result ) {
				if ( 'plugin_xray' === $result->scanner_id ) {
					$xray_data = json_decode( $result->issues, true );
					break;
				}
			}
		}

		include WPSD_PLUGIN_DIR . 'templates/plugin-xray.php';
	}

	/**
	 * Render the Auto-Repair page.
	 */
	public function render_auto_repair() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		$latest_scan    = Database::get_latest_scan();
		$repair_actions = array();

		if ( $latest_scan ) {
			$results = Database::get_scan_results( $latest_scan->scan_session_id );
			foreach ( $results as $result ) {
				$issues = json_decode( $result->issues, true );
				if ( is_array( $issues ) ) {
					foreach ( $issues as $issue ) {
						if ( ! empty( $issue['repair_action'] ) ) {
							$repair_actions[] = $issue['repair_action'];
						}
					}
				}
			}
		}

		include WPSD_PLUGIN_DIR . 'templates/auto-repair.php';
	}

	/**
	 * Render the Repair Log page.
	 */
	public function render_repair_log() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		$repair_log = Database::get_repair_log();

		include WPSD_PLUGIN_DIR . 'templates/repair-log.php';
	}

	/**
	 * Render the Reports page.
	 */
	public function render_reports() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		$scan_history = Database::get_scan_history();
		$settings     = get_option( 'wpsd_settings', array() );

		include WPSD_PLUGIN_DIR . 'templates/reports.php';
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-site-doctor' ) );
		}

		include WPSD_PLUGIN_DIR . 'templates/settings.php';
	}
}
