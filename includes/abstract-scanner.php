<?php
/**
 * Abstract Scanner base class for WP Site Doctor.
 *
 * Provides shared utilities for issue accumulation, scoring,
 * and common WordPress environment checks.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abstract_Scanner
 *
 * Base class that all concrete scanner modules extend.
 * Handles issue collection, score calculation, and result formatting.
 */
abstract class Abstract_Scanner implements Scanner_Interface {

	/**
	 * Collected issues during a scan run.
	 *
	 * @var array
	 */
	protected $issues = array();

	/**
	 * Severity score deductions.
	 *
	 * @var array
	 */
	protected $severity_weights = array(
		'critical' => 15,
		'warning'  => 5,
		'info'     => 0,
		'pass'     => 0,
	);

	/**
	 * Add an issue to the collection.
	 *
	 * @param string      $severity       One of: critical, warning, info, pass.
	 * @param string      $code           Machine-readable issue code.
	 * @param string      $message        Human-readable issue description.
	 * @param string      $recommendation Fix suggestion or explanation.
	 * @param array|null  $repair_action  Optional auto-repair descriptor: {
	 *     @type string $action_id   Machine-readable action identifier.
	 *     @type string $label       Human-readable action label.
	 *     @type string $description What the repair will do.
	 *     @type bool   $irreversible Whether the action can be rolled back.
	 * }
	 */
	protected function add_issue( $severity, $code, $message, $recommendation = '', $repair_action = null ) {
		$issue = array(
			'severity'       => sanitize_key( $severity ),
			'code'           => sanitize_key( $code ),
			'message'        => $message,
			'recommendation' => $recommendation,
		);

		if ( null !== $repair_action && is_array( $repair_action ) ) {
			$issue['repair_action'] = array(
				'action_id'   => isset( $repair_action['action_id'] ) ? sanitize_key( $repair_action['action_id'] ) : '',
				'label'       => isset( $repair_action['label'] ) ? $repair_action['label'] : '',
				'description' => isset( $repair_action['description'] ) ? $repair_action['description'] : '',
				'irreversible' => ! empty( $repair_action['irreversible'] ),
			);
		}

		$this->issues[] = $issue;
	}

	/**
	 * Add a passing check (no issue found).
	 *
	 * @param string $code    Check code.
	 * @param string $message Description of what passed.
	 */
	protected function add_pass( $code, $message ) {
		$this->add_issue( 'pass', $code, $message );
	}

	/**
	 * Calculate the scanner score based on accumulated issues.
	 *
	 * Starts at 100 and deducts based on severity:
	 * - Critical: -15 points each
	 * - Warning: -5 points each
	 * - Info/Pass: no deduction
	 *
	 * @return int Score from 0 to 100.
	 */
	protected function calculate_score() {
		$score = 100;

		foreach ( $this->issues as $issue ) {
			$severity  = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
			$deduction = isset( $this->severity_weights[ $severity ] ) ? $this->severity_weights[ $severity ] : 0;
			$score    -= $deduction;
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Build the standardized result array.
	 *
	 * Call this at the end of run() after all add_issue() calls.
	 *
	 * @return array Standardized scanner result.
	 */
	protected function build_result() {
		return array(
			'scanner_id' => $this->get_id(),
			'issues'     => $this->issues,
			'score'      => $this->calculate_score(),
			'category'   => $this->get_category(),
		);
	}

	/**
	 * Reset the issues collection for a fresh run.
	 */
	protected function reset() {
		$this->issues = array();
	}

	// ──────────────────────────────────────────────
	// Utility methods shared across scanners
	// ──────────────────────────────────────────────

	/**
	 * Format bytes to human-readable string.
	 *
	 * @param int $bytes    Number of bytes.
	 * @param int $decimals Decimal places.
	 * @return string Formatted string (e.g., "2.5 MB").
	 */
	protected function format_bytes( $bytes, $decimals = 2 ) {
		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$pow   = floor( log( $bytes ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		return round( $bytes / pow( 1024, $pow ), $decimals ) . ' ' . $units[ $pow ];
	}

	/**
	 * Convert a PHP ini shorthand value to bytes.
	 *
	 * Handles values like '128M', '2G', '512K'.
	 *
	 * @param string $value PHP ini value.
	 * @return int Value in bytes.
	 */
	protected function convert_to_bytes( $value ) {
		$value = trim( $value );
		$last  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;

		switch ( $last ) {
			case 'g':
				$num *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$num *= 1024 * 1024;
				break;
			case 'k':
				$num *= 1024;
				break;
		}

		return $num;
	}

	/**
	 * Check if a PHP extension is loaded.
	 *
	 * @param string $extension Extension name.
	 * @return bool True if loaded.
	 */
	protected function is_extension_loaded( $extension ) {
		return extension_loaded( $extension );
	}

	/**
	 * Compare WordPress version against a minimum.
	 *
	 * @param string $min_version Minimum version string.
	 * @return bool True if current WP version >= min_version.
	 */
	protected function wp_version_gte( $min_version ) {
		global $wp_version;
		return version_compare( $wp_version, $min_version, '>=' );
	}

	/**
	 * Check if a constant is defined and truthy.
	 *
	 * @param string $constant Constant name.
	 * @return bool True if defined and truthy.
	 */
	protected function is_constant_true( $constant ) {
		return defined( $constant ) && constant( $constant );
	}

	/**
	 * Check if a plugin is active by its slug (folder/file).
	 *
	 * @param string $plugin_slug Plugin slug (e.g., 'wordfence/wordfence.php').
	 * @return bool True if active.
	 */
	protected function is_plugin_active( $plugin_slug ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return \is_plugin_active( $plugin_slug );
	}

	/**
	 * Get all active plugins as an array of file paths relative to plugins dir.
	 *
	 * @return array Active plugin paths.
	 */
	protected function get_active_plugins() {
		return (array) get_option( 'active_plugins', array() );
	}

	/**
	 * Get active plugin data by folder slug.
	 *
	 * Searches active plugins for one whose directory matches the given slug.
	 *
	 * @param string $folder_slug Plugin folder name (e.g., 'wordfence').
	 * @return array|null Plugin data array or null if not found.
	 */
	protected function get_active_plugin_by_folder( $folder_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = $this->get_active_plugins();

		foreach ( $active as $plugin_path ) {
			$parts = explode( '/', $plugin_path );
			if ( isset( $parts[0] ) && $parts[0] === $folder_slug ) {
				$all_plugins = get_plugins();
				if ( isset( $all_plugins[ $plugin_path ] ) ) {
					$data          = $all_plugins[ $plugin_path ];
					$data['_path'] = $plugin_path;
					return $data;
				}
			}
		}

		return null;
	}

	/**
	 * Make a safe HTTP GET request with a short timeout.
	 *
	 * @param string $url     URL to request.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	protected function remote_get( $url, $timeout = 5 ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'   => $timeout,
				'sslverify' => false,
			)
		);
	}

	/**
	 * Get a WordPress admin URL for a settings page.
	 *
	 * @param string $path Admin path relative to admin root.
	 * @return string Full admin URL.
	 */
	protected function admin_link( $path ) {
		return admin_url( $path );
	}

	/**
	 * Get file permissions as an octal string.
	 *
	 * @param string $file_path Absolute file path.
	 * @return string|false Octal permission string (e.g., '644') or false.
	 */
	protected function get_file_permissions( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$perms = fileperms( $file_path );
		if ( false === $perms ) {
			return false;
		}

		return substr( decoct( $perms ), -3 );
	}

	/**
	 * Check if a file is world-writable (permission ends in 7 or 6 for others).
	 *
	 * @param string $file_path Absolute file path.
	 * @return bool True if world-writable.
	 */
	protected function is_world_writable( $file_path ) {
		$perms = $this->get_file_permissions( $file_path );
		if ( false === $perms ) {
			return false;
		}

		$other = (int) substr( $perms, -1 );
		return ( $other & 2 ) !== 0;
	}

	/**
	 * Fetch plugin info from the WP.org API with 24-hour transient caching.
	 *
	 * @param string $slug Plugin slug.
	 * @return object|null Plugin API info object or null on failure.
	 */
	protected function get_wporg_plugin_info( $slug ) {
		$transient_key = 'wpsd_wporg_' . md5( $slug );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'active_installs' => true,
					'last_updated'    => true,
					'rating'          => true,
					'ratings'         => false,
					'downloaded'      => false,
					'sections'        => false,
					'tags'            => false,
					'banners'         => false,
					'icons'           => false,
					'screenshots'     => false,
					'tested'          => true,
					'requires'        => true,
					'requires_php'    => true,
					'compatibility'   => false,
				),
			)
		);

		if ( is_wp_error( $info ) ) {
			// Cache the failure briefly to avoid hammering the API.
			set_transient( $transient_key, null, HOUR_IN_SECONDS );
			return null;
		}

		// Cache successful response for 24 hours.
		set_transient( $transient_key, $info, DAY_IN_SECONDS );

		return $info;
	}
}
