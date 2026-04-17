<?php
/**
 * Plugin Name:       WP Site Doctor
 * Plugin URI:        https://noorweb.uk/wp-site-doctor
 * Description:       Comprehensive WordPress site health scanner, conflict resolver, and auto-repair engine. Diagnoses performance, security, caching, database, image, SEO, and plugin issues with one-click fixes.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Noor Web Limited
 * Author URI:        https://noorweb.uk
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-doctor
 * Domain Path:       /languages
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'WPSD_VERSION', '1.1.0' );
define( 'WPSD_DB_VERSION', '1.0.0' );
define( 'WPSD_PLUGIN_FILE', __FILE__ );
define( 'WPSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-style autoloader for plugin classes.
 *
 * Maps WPSiteDoctor\ to includes/ and WPSiteDoctor\Scanners\ to scanners/.
 * Does not depend on Composer at runtime.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPSiteDoctor\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );

		// Check Scanners sub-namespace first.
		if ( 0 === strpos( $relative_class, 'Scanners\\' ) ) {
			$class_name = substr( $relative_class, 9 );
			$file       = WPSD_PLUGIN_DIR . 'scanners/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Handle Abstract_Xxx → includes/abstract-xxx.php (strip "Abstract_" prefix).
		if ( 0 === strpos( $relative_class, 'Abstract_' ) ) {
			$name = strtolower( str_replace( '_', '-', substr( $relative_class, 9 ) ) );
			$file = WPSD_PLUGIN_DIR . 'includes/abstract-' . $name . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Handle Xxx_Interface → includes/interface-xxx.php (strip "_Interface" suffix).
		if ( '_Interface' === substr( $relative_class, -10 ) ) {
			$name = strtolower( str_replace( '_', '-', substr( $relative_class, 0, -10 ) ) );
			$file = WPSD_PLUGIN_DIR . 'includes/interface-' . $name . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Regular class: includes/class-xxx.php
		$file = WPSD_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Returns the required capability based on multisite context.
 *
 * On multisite with network activation, requires manage_network.
 * On single site, requires manage_options.
 *
 * @return string The required capability.
 */
function wpsd_required_capability() {
	if ( is_multisite() && is_plugin_active_for_network( WPSD_PLUGIN_BASENAME ) ) {
		return 'manage_network';
	}
	return 'manage_options';
}

/**
 * Run on plugin activation.
 *
 * Creates custom database tables and sets default options.
 */
function wpsd_activate() {
	Database::install();

	// Set default settings if not already present.
	if ( false === get_option( 'wpsd_settings' ) ) {
		$defaults = array(
			'developer_email'        => '',
			'auto_scan_schedule'     => 'off',
			'health_alert_threshold' => 60,
			'admin_email'            => get_option( 'admin_email' ),
			'wpvulndb_api_key'       => '',
			'excluded_plugins'       => array(),
			'excluded_checks'        => array(),
			'restore_point_limit'    => 5,
		);
		add_option( 'wpsd_settings', $defaults );
	}

	// Schedule an initial scan 30 minutes after activation.
	if ( ! wp_next_scheduled( 'wpsd_scheduled_scan' ) ) {
		wp_schedule_single_event( time() + 1800, 'wpsd_initial_scan' );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\wpsd_activate' );

/**
 * Run on plugin deactivation.
 *
 * Clears scheduled cron events but preserves data.
 */
function wpsd_deactivate() {
	wp_clear_scheduled_hook( 'wpsd_scheduled_scan' );
	wp_clear_scheduled_hook( 'wpsd_initial_scan' );

	// Clear any active scan/repair locks.
	delete_transient( 'wpsd_scan_lock' );
	delete_transient( 'wpsd_repair_lock' );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\wpsd_deactivate' );

/**
 * Initialize the plugin on plugins_loaded.
 *
 * This ensures all WordPress APIs are available and other plugins have loaded.
 */
function wpsd_init() {
	// Check database version and upgrade if needed.
	Database::check_version();

	// Boot the plugin loader which registers all hooks.
	$loader = new Plugin_Loader();
	$loader->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\wpsd_init' );
