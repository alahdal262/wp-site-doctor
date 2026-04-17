<?php
/**
 * Cache Scanner for WP Site Doctor.
 *
 * Detects page cache, object cache, CDN, browser caching, transient bloat,
 * and multiple caching plugin conflicts.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Cache_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'cache';
	}

	public function get_label(): string {
		return __( 'Cache', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'cache';
	}

	public function run(): array {
		$this->reset();

		$this->check_page_cache();
		$this->check_object_cache();
		$this->check_browser_cache_headers();
		$this->check_cdn();
		$this->check_transient_bloat();
		$this->check_wp_cache_constant();

		return $this->build_result();
	}

	private function check_page_cache() {
		$caching_plugins = array(
			'w3-total-cache'       => 'W3 Total Cache',
			'wp-super-cache'       => 'WP Super Cache',
			'wp-fastest-cache'     => 'WP Fastest Cache',
			'litespeed-cache'      => 'LiteSpeed Cache',
			'wp-rocket'            => 'WP Rocket',
			'breeze'               => 'Breeze',
			'sg-cachepress'        => 'SG Optimizer',
			'hummingbird-performance' => 'Hummingbird',
			'cache-enabler'        => 'Cache Enabler',
			'comet-cache'          => 'Comet Cache',
			'swift-performance-lite' => 'Swift Performance',
		);

		$active_caching = array();
		$all_active     = $this->get_active_plugins();

		foreach ( $all_active as $plugin_path ) {
			$folder = explode( '/', $plugin_path )[0];
			if ( isset( $caching_plugins[ $folder ] ) ) {
				$active_caching[ $folder ] = $caching_plugins[ $folder ];
			}
		}

		if ( empty( $active_caching ) ) {
			$this->add_issue(
				'warning',
				'no_page_cache',
				__( 'No page caching plugin detected.', 'wp-site-doctor' ),
				__( 'Install a page caching plugin for significantly faster page loads. Free options: LiteSpeed Cache, WP Super Cache, or Cache Enabler.', 'wp-site-doctor' )
			);
		} elseif ( count( $active_caching ) > 1 ) {
			$this->add_issue(
				'critical',
				'multiple_cache_plugins',
				sprintf(
					/* translators: %s: list of active caching plugins */
					__( 'Multiple caching plugins active: %s. This causes conflicts.', 'wp-site-doctor' ),
					implode( ', ', $active_caching )
				),
				__( 'Keep only ONE caching plugin and deactivate the rest. Check the Plugin Conflicts scanner for a recommendation.', 'wp-site-doctor' )
			);
		} else {
			$name = reset( $active_caching );
			$this->add_pass(
				'page_cache_active',
				sprintf(
					/* translators: %s: caching plugin name */
					__( 'Page caching is active via %s.', 'wp-site-doctor' ),
					$name
				)
			);
		}
	}

	private function check_object_cache() {
		$object_cache_file = WP_CONTENT_DIR . '/object-cache.php';

		if ( wp_using_ext_object_cache() ) {
			$this->add_pass( 'object_cache_ok', __( 'Persistent object cache is active.', 'wp-site-doctor' ) );
		} elseif ( file_exists( $object_cache_file ) ) {
			$this->add_issue(
				'info',
				'object_cache_file_exists',
				__( 'object-cache.php exists but persistent object caching is not active.', 'wp-site-doctor' ),
				__( 'The object cache drop-in may not be properly configured. Check your caching plugin settings.', 'wp-site-doctor' )
			);
		} else {
			$this->add_issue(
				'info',
				'no_object_cache',
				__( 'No persistent object cache configured.', 'wp-site-doctor' ),
				__( 'Consider enabling Redis or Memcached for faster database query caching.', 'wp-site-doctor' )
			);
		}
	}

	private function check_browser_cache_headers() {
		$test_url = includes_url( 'css/buttons.min.css' );
		$response = $this->remote_get( $test_url, 5 );

		if ( is_wp_error( $response ) ) {
			$this->add_issue(
				'info',
				'browser_cache_check_failed',
				__( 'Could not check browser caching headers.', 'wp-site-doctor' )
			);
			return;
		}

		$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
		$expires       = wp_remote_retrieve_header( $response, 'expires' );

		if ( empty( $cache_control ) && empty( $expires ) ) {
			$this->add_issue(
				'warning',
				'no_browser_cache',
				__( 'Browser caching headers (Cache-Control/Expires) not found on static assets.', 'wp-site-doctor' ),
				__( 'Configure browser caching in your server config or caching plugin to reduce repeat visits load time.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'browser_cache_ok', __( 'Browser caching headers are present on static assets.', 'wp-site-doctor' ) );
		}
	}

	private function check_cdn() {
		$home_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$response   = $this->remote_get( home_url( '/' ), 5 );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$headers = wp_remote_retrieve_headers( $response );

		// Check for known CDN headers.
		$cdn_detected = '';

		if ( isset( $headers['cf-ray'] ) || isset( $headers['cf-cache-status'] ) ) {
			$cdn_detected = 'Cloudflare';
		} elseif ( isset( $headers['x-cdn'] ) ) {
			$cdn_detected = $headers['x-cdn'];
		} elseif ( isset( $headers['x-sucuri-id'] ) ) {
			$cdn_detected = 'Sucuri';
		} elseif ( isset( $headers['x-stackpath-id'] ) || isset( $headers['x-sp-cdn'] ) ) {
			$cdn_detected = 'StackPath';
		} elseif ( isset( $headers['x-amz-cf-id'] ) ) {
			$cdn_detected = 'Amazon CloudFront';
		} elseif ( isset( $headers['x-fastly-request-id'] ) ) {
			$cdn_detected = 'Fastly';
		}

		if ( $cdn_detected ) {
			$this->add_pass(
				'cdn_detected',
				sprintf(
					/* translators: %s: CDN name */
					__( 'CDN detected: %s', 'wp-site-doctor' ),
					$cdn_detected
				)
			);
		} else {
			$this->add_issue(
				'info',
				'no_cdn',
				__( 'No CDN detected.', 'wp-site-doctor' ),
				__( 'A CDN distributes your content globally for faster loading. Consider Cloudflare (free tier) or your host\'s CDN option.', 'wp-site-doctor' )
			);
		}
	}

	private function check_transient_bloat() {
		global $wpdb;

		$total_transients = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_%'
			)
		);

		$total_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_%'
			)
		);

		if ( $total_transients > 500 || $total_size > 5 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'transient_bloat',
				sprintf(
					/* translators: 1: transient count, 2: size */
					__( 'Transient bloat: %1$s transients totaling %2$s.', 'wp-site-doctor' ),
					number_format_i18n( $total_transients ),
					$this->format_bytes( $total_size )
				),
				__( 'Delete expired transients and consider using an object cache to store transients outside the database.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'delete_expired_transients',
					'label'       => __( 'Delete expired transients', 'wp-site-doctor' ),
					'description' => __( 'Removes expired transients from wp_options.', 'wp-site-doctor' ),
				)
			);
		}
	}

	private function check_wp_cache_constant() {
		if ( ! $this->is_constant_true( 'WP_CACHE' ) ) {
			$this->add_issue(
				'info',
				'wp_cache_not_defined',
				__( 'WP_CACHE constant is not enabled in wp-config.php.', 'wp-site-doctor' ),
				__( 'Some caching plugins require define(\'WP_CACHE\', true); in wp-config.php. Check your caching plugin documentation.', 'wp-site-doctor' )
			);
		}
	}
}
