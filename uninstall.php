<?php
/**
 * WP Site Doctor Uninstall Handler.
 *
 * Fired when the plugin is deleted through the WordPress admin.
 * Removes all custom database tables, options, transients, and user meta.
 *
 * @package WPSiteDoctor
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the Database class for the uninstall method.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';

// Remove all custom tables and plugin data.
\WPSiteDoctor\Database::uninstall();

// Clear any remaining scheduled events.
wp_clear_scheduled_hook( 'wpsd_scheduled_scan' );
wp_clear_scheduled_hook( 'wpsd_initial_scan' );

// Delete the scan lock and repair lock transients.
delete_transient( 'wpsd_scan_lock' );
delete_transient( 'wpsd_repair_lock' );
