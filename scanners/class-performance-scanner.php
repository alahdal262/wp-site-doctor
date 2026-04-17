<?php
/**
 * Performance Scanner for WP Site Doctor.
 *
 * Checks object cache, compression, autoload size, plugin count,
 * WordPress/PHP version currency, and HTTP/2 support.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Performance_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'performance';
	}

	public function get_label(): string {
		return __( 'Performance', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'performance';
	}

	public function run(): array {
		$this->reset();

		$this->check_object_cache();
		$this->check_gzip_compression();
		$this->check_autoload_size();
		$this->check_active_plugin_count();
		$this->check_wordpress_updates();
		$this->check_plugin_updates();
		$this->check_php_memory_usage();
		$this->check_http2();
		$this->check_heartbeat_api();

		return $this->build_result();
	}

	private function check_object_cache() {
		if ( wp_using_ext_object_cache() ) {
			$backend = __( 'Unknown', 'wp-site-doctor' );

			if ( class_exists( 'Redis' ) || class_exists( 'Predis\Client' ) ) {
				$backend = 'Redis';
			} elseif ( class_exists( 'Memcached' ) || class_exists( 'Memcache' ) ) {
				$backend = 'Memcached';
			}

			$this->add_pass(
				'object_cache_active',
				sprintf(
					/* translators: %s: cache backend name */
					__( 'Persistent object cache is active (%s).', 'wp-site-doctor' ),
					$backend
				)
			);
		} else {
			$this->add_issue(
				'warning',
				'no_object_cache',
				__( 'No persistent object cache is configured. Using default file-based cache.', 'wp-site-doctor' ),
				__( 'Install Redis or Memcached for significantly faster database query caching. Many managed hosts offer this built-in.', 'wp-site-doctor' )
			);
		}
	}

	private function check_gzip_compression() {
		$response = $this->remote_get( home_url( '/' ), 5 );

		if ( is_wp_error( $response ) ) {
			$this->add_issue(
				'info',
				'compression_check_failed',
				__( 'Could not check compression — request failed.', 'wp-site-doctor' )
			);
			return;
		}

		$encoding = wp_remote_retrieve_header( $response, 'content-encoding' );

		if ( $encoding && ( 'gzip' === $encoding || 'br' === $encoding ) ) {
			$label = ( 'br' === $encoding ) ? 'Brotli' : 'Gzip';
			$this->add_pass(
				'compression_enabled',
				sprintf(
					/* translators: %s: compression type */
					__( '%s compression is enabled.', 'wp-site-doctor' ),
					$label
				)
			);
		} else {
			$this->add_issue(
				'warning',
				'no_compression',
				__( 'Gzip/Brotli compression is not detected. Pages are served uncompressed.', 'wp-site-doctor' ),
				__( 'Enable compression in your server config or via a caching plugin. This typically reduces page size by 60-80%.', 'wp-site-doctor' )
			);
		}
	}

	private function check_autoload_size() {
		global $wpdb;

		$autoload_size = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = %s",
				'yes'
			)
		);

		$size_bytes = (int) $autoload_size;
		$size_human = $this->format_bytes( $size_bytes );

		if ( $size_bytes > 2 * 1024 * 1024 ) {
			$this->add_issue(
				'critical',
				'autoload_critical',
				sprintf(
					/* translators: %s: autoload data size */
					__( 'Autoloaded options total %s. This is severely impacting every page load.', 'wp-site-doctor' ),
					$size_human
				),
				__( 'Identify large autoloaded options and set them to not autoload, or remove unused plugin data.', 'wp-site-doctor' )
			);
		} elseif ( $size_bytes > 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'autoload_large',
				sprintf(
					/* translators: %s: autoload data size */
					__( 'Autoloaded options total %s. Recommended to keep under 800KB.', 'wp-site-doctor' ),
					$size_human
				),
				__( 'Review wp_options for large autoloaded entries using a database management plugin.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'autoload_ok',
				sprintf(
					/* translators: %s: autoload data size */
					__( 'Autoloaded options total %s.', 'wp-site-doctor' ),
					$size_human
				)
			);
		}

		// Check for individual large autoloaded options (> 100KB).
		$large_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload = %s AND LENGTH(option_value) > %d ORDER BY size DESC LIMIT 5",
				'yes',
				102400
			)
		);

		if ( ! empty( $large_options ) ) {
			$names = array();
			foreach ( $large_options as $opt ) {
				$names[] = $opt->option_name . ' (' . $this->format_bytes( (int) $opt->size ) . ')';
			}

			$this->add_issue(
				'info',
				'large_autoload_options',
				sprintf(
					/* translators: %s: list of option names and sizes */
					__( 'Large autoloaded options found: %s', 'wp-site-doctor' ),
					implode( ', ', $names )
				),
				__( 'Consider switching these to non-autoloaded or cleaning up unused data.', 'wp-site-doctor' )
			);
		}
	}

	private function check_active_plugin_count() {
		$active = $this->get_active_plugins();
		$count  = count( $active );

		if ( $count > 30 ) {
			$this->add_issue(
				'warning',
				'too_many_plugins',
				sprintf(
					/* translators: %d: number of active plugins */
					__( '%d active plugins. This many plugins significantly impact performance.', 'wp-site-doctor' ),
					$count
				),
				__( 'Audit your plugins and deactivate any that are unnecessary. Use the Plugin X-Ray scanner for detailed impact analysis.', 'wp-site-doctor' )
			);
		} elseif ( $count > 20 ) {
			$this->add_issue(
				'info',
				'many_plugins',
				sprintf(
					/* translators: %d: number of active plugins */
					__( '%d active plugins. Consider whether all are necessary.', 'wp-site-doctor' ),
					$count
				),
				__( 'Review the Plugin X-Ray page to identify high-impact plugins.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'plugin_count_ok',
				sprintf(
					/* translators: %d: number of active plugins */
					__( '%d active plugins.', 'wp-site-doctor' ),
					$count
				)
			);
		}
	}

	private function check_wordpress_updates() {
		$update_data = get_site_transient( 'update_core' );

		if ( ! empty( $update_data->updates ) && is_array( $update_data->updates ) ) {
			foreach ( $update_data->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$this->add_issue(
						'warning',
						'wp_update_available',
						sprintf(
							/* translators: 1: current version, 2: available version */
							__( 'WordPress update available: %1$s → %2$s', 'wp-site-doctor' ),
							get_bloginfo( 'version' ),
							$update->current
						),
						__( 'Update WordPress from Dashboard > Updates for security and performance improvements.', 'wp-site-doctor' )
					);
					return;
				}
			}
		}

		$this->add_pass( 'wp_up_to_date', __( 'WordPress is up to date.', 'wp-site-doctor' ) );
	}

	private function check_plugin_updates() {
		$update_data = get_site_transient( 'update_plugins' );

		if ( ! empty( $update_data->response ) ) {
			$count = count( $update_data->response );

			if ( $count > 0 ) {
				$this->add_issue(
					'warning',
					'plugin_updates_available',
					sprintf(
						/* translators: %d: number of plugins with updates */
						__( '%d plugin update(s) available. Outdated plugins are a security risk.', 'wp-site-doctor' ),
						$count
					),
					sprintf(
						/* translators: %s: link to updates page */
						__( 'Update plugins from %s.', 'wp-site-doctor' ),
						$this->admin_link( 'update-core.php' )
					)
				);
				return;
			}
		}

		$this->add_pass( 'plugins_up_to_date', __( 'All plugins are up to date.', 'wp-site-doctor' ) );
	}

	private function check_php_memory_usage() {
		$peak      = memory_get_peak_usage( true );
		$limit     = $this->convert_to_bytes( ini_get( 'memory_limit' ) );
		$usage_pct = ( $limit > 0 ) ? round( ( $peak / $limit ) * 100, 1 ) : 0;

		if ( $usage_pct > 80 ) {
			$this->add_issue(
				'warning',
				'high_memory_usage',
				sprintf(
					/* translators: 1: usage percentage, 2: peak usage, 3: limit */
					__( 'Peak PHP memory usage is %1$s%% (%2$s of %3$s limit).', 'wp-site-doctor' ),
					$usage_pct,
					$this->format_bytes( $peak ),
					ini_get( 'memory_limit' )
				),
				__( 'Increase memory_limit or reduce plugin count to avoid out-of-memory errors.', 'wp-site-doctor' )
			);
		}
	}

	private function check_http2() {
		$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '';

		if ( false !== stripos( $protocol, 'HTTP/2' ) || false !== stripos( $protocol, 'HTTP/3' ) ) {
			$this->add_pass( 'http2_active', __( 'HTTP/2 or newer is active.', 'wp-site-doctor' ) );
		} else {
			$this->add_issue(
				'info',
				'no_http2',
				__( 'HTTP/2 was not detected. HTTP/2 improves page load performance.', 'wp-site-doctor' ),
				__( 'Most modern hosts support HTTP/2 with SSL. Contact your host if not available.', 'wp-site-doctor' )
			);
		}
	}

	private function check_heartbeat_api() {
		if ( $this->is_constant_true( 'DISABLE_WP_HEARTBEAT' ) ) {
			$this->add_pass(
				'heartbeat_disabled',
				__( 'WordPress Heartbeat API is disabled (saves admin AJAX requests).', 'wp-site-doctor' )
			);
		}
	}
}
