<?php
/**
 * Plugin X-Ray Scanner for WP Site Doctor.
 *
 * Deep analysis of every active plugin: hooks, assets, HTTP calls,
 * WP.org API data, load impact estimation.
 *
 * All WP.org API responses are cached in transients for 24 hours.
 * File scanning is capped at 100 files per plugin for performance.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Plugin_Xray_Scanner extends Abstract_Scanner {

	/**
	 * Maximum files to scan per plugin.
	 */
	const MAX_FILES_PER_PLUGIN = 100;

	public function get_id(): string {
		return 'plugin_xray';
	}

	public function get_label(): string {
		return __( 'Plugin X-Ray', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'plugin_xray';
	}

	public function run(): array {
		$this->reset();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = $this->get_active_plugins();
		$all_plugins    = get_plugins();
		$xray_data      = array();

		foreach ( $active_plugins as $plugin_path ) {
			if ( ! isset( $all_plugins[ $plugin_path ] ) ) {
				continue;
			}

			$plugin_data = $all_plugins[ $plugin_path ];
			$folder      = explode( '/', $plugin_path )[0];
			$plugin_dir  = WP_PLUGIN_DIR . '/' . $folder;

			// Build X-Ray analysis for this plugin.
			$xray = $this->analyze_plugin( $plugin_path, $plugin_data, $plugin_dir, $folder );
			$xray_data[] = $xray;

			// Report individual plugin issues.
			$this->report_plugin_issues( $xray );
		}

		// Store the full X-Ray data as a special issue entry for the template.
		// The issues array doubles as the X-Ray table data source.
		// Actual scoring issues are added via report_plugin_issues above.

		// Save xray_data for the X-Ray template page via a transient.
		set_transient( 'wpsd_xray_data', $xray_data, HOUR_IN_SECONDS );

		return $this->build_result();
	}

	/**
	 * Perform deep analysis on a single plugin.
	 *
	 * @param string $plugin_path Plugin file path relative to plugins dir.
	 * @param array  $plugin_data Plugin header data.
	 * @param string $plugin_dir  Absolute path to the plugin folder.
	 * @param string $folder      Plugin folder slug.
	 * @return array Analysis data for this plugin.
	 */
	private function analyze_plugin( $plugin_path, $plugin_data, $plugin_dir, $folder ) {
		$xray = array(
			'path'            => $plugin_path,
			'folder'          => $folder,
			'name'            => $plugin_data['Name'] ?? $folder,
			'version'         => $plugin_data['Version'] ?? '—',
			'author'          => $plugin_data['AuthorName'] ?? '',
			'impact'          => 'low',
			'last_updated'    => '—',
			'active_installs' => null,
			'rating'          => null,
			'tested'          => '—',
			'requires_php'    => '—',
			'hook_count'      => 0,
			'asset_count'     => 0,
			'http_calls'      => 0,
			'adds_admin_menu' => false,
			'custom_tables'   => false,
			'custom_cpt'      => false,
			'issues'          => array(),
		);

		// Fetch WP.org data (cached for 24 hours).
		$wporg = $this->get_wporg_plugin_info( $folder );

		if ( $wporg ) {
			$xray['last_updated']    = isset( $wporg->last_updated ) ? date_i18n( get_option( 'date_format' ), strtotime( $wporg->last_updated ) ) : '—';
			$xray['active_installs'] = isset( $wporg->active_installs ) ? (int) $wporg->active_installs : null;
			$xray['rating']          = isset( $wporg->rating ) ? round( (float) $wporg->rating / 20, 1 ) : null;
			$xray['tested']          = isset( $wporg->tested ) ? $wporg->tested : '—';
			$xray['requires_php']    = isset( $wporg->requires_php ) ? $wporg->requires_php : '—';

			// Check compatibility with current WP version.
			if ( ! empty( $wporg->tested ) ) {
				global $wp_version;
				$tested_major = implode( '.', array_slice( explode( '.', $wporg->tested ), 0, 2 ) );
				$current_major = implode( '.', array_slice( explode( '.', $wp_version ), 0, 2 ) );

				if ( version_compare( $tested_major, $current_major, '<' ) ) {
					$xray['issues'][] = array(
						'severity' => 'warning',
						'message'  => sprintf(
							/* translators: 1: tested version, 2: current version */
							__( 'Only tested up to WP %1$s (you have %2$s)', 'wp-site-doctor' ),
							$wporg->tested,
							$wp_version
						),
					);
				}
			}

			// Check if abandoned (not updated in 2 years).
			if ( ! empty( $wporg->last_updated ) ) {
				$days_since = ( time() - strtotime( $wporg->last_updated ) ) / DAY_IN_SECONDS;

				if ( $days_since > 730 ) {
					$xray['issues'][] = array(
						'severity' => 'warning',
						'message'  => sprintf(
							/* translators: %s: time since update */
							__( 'Not updated in %s — potentially abandoned', 'wp-site-doctor' ),
							human_time_diff( strtotime( $wporg->last_updated ) )
						),
					);
				} elseif ( $days_since > 365 ) {
					$xray['issues'][] = array(
						'severity' => 'info',
						'message'  => sprintf(
							/* translators: %s: time since update */
							__( 'Last updated %s ago', 'wp-site-doctor' ),
							human_time_diff( strtotime( $wporg->last_updated ) )
						),
					);
				}
			}
		}

		// Scan plugin files for patterns (capped at MAX_FILES_PER_PLUGIN).
		$file_analysis = $this->scan_plugin_files( $plugin_dir );

		$xray['hook_count']      = $file_analysis['hooks'];
		$xray['asset_count']     = $file_analysis['assets'];
		$xray['http_calls']      = $file_analysis['http_calls'];
		$xray['adds_admin_menu'] = $file_analysis['admin_menu'];
		$xray['custom_tables']   = $file_analysis['custom_tables'];
		$xray['custom_cpt']      = $file_analysis['custom_cpt'];

		// Calculate load impact.
		$xray['impact'] = $this->calculate_impact(
			$file_analysis['hooks'],
			$file_analysis['assets'],
			$file_analysis['http_calls']
		);

		return $xray;
	}

	/**
	 * Scan plugin PHP files for hooks, assets, HTTP calls, etc.
	 *
	 * Capped at self::MAX_FILES_PER_PLUGIN files for performance.
	 *
	 * @param string $plugin_dir Absolute plugin directory path.
	 * @return array Analysis results.
	 */
	private function scan_plugin_files( $plugin_dir ) {
		$results = array(
			'hooks'         => 0,
			'assets'        => 0,
			'http_calls'    => 0,
			'admin_menu'    => false,
			'custom_tables' => false,
			'custom_cpt'    => false,
		);

		if ( ! is_dir( $plugin_dir ) ) {
			return $results;
		}

		$php_files = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( count( $php_files ) >= self::MAX_FILES_PER_PLUGIN ) {
					break;
				}

				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$php_files[] = $file->getPathname();
				}
			}
		} catch ( \Exception $e ) {
			return $results;
		}

		foreach ( $php_files as $filepath ) {
			// Read file content safely.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not URL.
			$content = file_get_contents( $filepath );

			if ( false === $content ) {
				continue;
			}

			// Count hooks (add_action / add_filter).
			$results['hooks'] += preg_match_all( '/\badd_action\s*\(/', $content );
			$results['hooks'] += preg_match_all( '/\badd_filter\s*\(/', $content );

			// Count asset enqueues.
			$results['assets'] += preg_match_all( '/\bwp_enqueue_script\s*\(/', $content );
			$results['assets'] += preg_match_all( '/\bwp_enqueue_style\s*\(/', $content );

			// Count external HTTP calls.
			$results['http_calls'] += preg_match_all( '/\bwp_remote_get\s*\(/', $content );
			$results['http_calls'] += preg_match_all( '/\bwp_remote_post\s*\(/', $content );
			$results['http_calls'] += preg_match_all( '/\bwp_remote_request\s*\(/', $content );
			$results['http_calls'] += preg_match_all( '/\bfile_get_contents\s*\(\s*["\']https?:/', $content );
			$results['http_calls'] += preg_match_all( '/\bcurl_init\s*\(/', $content );

			// Check for admin menu registration.
			if ( ! $results['admin_menu'] && preg_match( '/\badd_menu_page\s*\(|\badd_submenu_page\s*\(/', $content ) ) {
				$results['admin_menu'] = true;
			}

			// Check for custom table creation.
			if ( ! $results['custom_tables'] && preg_match( '/\bdbDelta\s*\(|\bCREATE\s+TABLE\b/i', $content ) ) {
				$results['custom_tables'] = true;
			}

			// Check for custom post types.
			if ( ! $results['custom_cpt'] && preg_match( '/\bregister_post_type\s*\(/', $content ) ) {
				$results['custom_cpt'] = true;
			}
		}

		return $results;
	}

	/**
	 * Calculate load impact based on hooks, assets, and HTTP calls.
	 *
	 * @param int $hooks      Number of hooks.
	 * @param int $assets     Number of enqueued assets.
	 * @param int $http_calls Number of HTTP call patterns found.
	 * @return string 'low', 'medium', or 'high'.
	 */
	private function calculate_impact( $hooks, $assets, $http_calls ) {
		$score = 0;

		// Hooks scoring.
		if ( $hooks > 50 ) {
			$score += 3;
		} elseif ( $hooks > 20 ) {
			$score += 2;
		} elseif ( $hooks > 5 ) {
			$score += 1;
		}

		// Assets scoring.
		if ( $assets > 10 ) {
			$score += 3;
		} elseif ( $assets > 5 ) {
			$score += 2;
		} elseif ( $assets > 2 ) {
			$score += 1;
		}

		// HTTP calls scoring (each is potentially blocking).
		if ( $http_calls > 5 ) {
			$score += 3;
		} elseif ( $http_calls > 2 ) {
			$score += 2;
		} elseif ( $http_calls > 0 ) {
			$score += 1;
		}

		if ( $score >= 6 ) {
			return 'high';
		} elseif ( $score >= 3 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Report issues for a single plugin's X-Ray data.
	 *
	 * Adds scanner-level issues for plugins that are problematic.
	 *
	 * @param array $xray Plugin X-Ray data.
	 */
	private function report_plugin_issues( $xray ) {
		// Flag high-impact plugins.
		if ( 'high' === $xray['impact'] ) {
			$this->add_issue(
				'warning',
				'high_impact_' . sanitize_key( $xray['folder'] ),
				sprintf(
					/* translators: 1: plugin name, 2: hook count, 3: asset count, 4: HTTP call count */
					__( '%1$s has high load impact (%2$d hooks, %3$d assets, %4$d HTTP call patterns).', 'wp-site-doctor' ),
					$xray['name'],
					$xray['hook_count'],
					$xray['asset_count'],
					$xray['http_calls']
				),
				__( 'Review whether this plugin is essential. Consider lightweight alternatives if performance is a concern.', 'wp-site-doctor' )
			);
		}

		// Report issues from the per-plugin analysis.
		foreach ( $xray['issues'] as $issue ) {
			if ( 'warning' === $issue['severity'] ) {
				$this->add_issue(
					'warning',
					'xray_' . sanitize_key( $xray['folder'] ) . '_' . sanitize_key( substr( $issue['message'], 0, 30 ) ),
					$xray['name'] . ': ' . $issue['message'],
					__( 'Check the Plugin X-Ray page for detailed analysis.', 'wp-site-doctor' )
				);
			}
		}
	}
}
