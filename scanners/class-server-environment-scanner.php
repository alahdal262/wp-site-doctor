<?php
/**
 * Server Environment Scanner for WP Site Doctor.
 *
 * Checks PHP version, MySQL version, PHP extensions, limits, and server software.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Class Server_Environment_Scanner
 */
class Server_Environment_Scanner extends Abstract_Scanner {

	/** @inheritDoc */
	public function get_id(): string {
		return 'server_environment';
	}

	/** @inheritDoc */
	public function get_label(): string {
		return __( 'Server Environment', 'wp-site-doctor' );
	}

	/** @inheritDoc */
	public function get_category(): string {
		return 'server_environment';
	}

	/** @inheritDoc */
	public function run(): array {
		$this->reset();

		$this->check_php_version();
		$this->check_mysql_version();
		$this->check_web_server();
		$this->check_php_memory_limit();
		$this->check_max_execution_time();
		$this->check_upload_limits();
		$this->check_max_input_vars();
		$this->check_required_extensions();
		$this->check_wp_memory_limit();
		$this->check_multisite();
		$this->check_wp_cron_method();
		$this->check_uploads_writable();
		$this->check_wp_content_writable();
		$this->check_subdirectory_install();

		return $this->build_result();
	}

	/**
	 * Check PHP version.
	 */
	private function check_php_version() {
		$version = PHP_VERSION;

		if ( version_compare( $version, '7.4', '<' ) ) {
			$this->add_issue(
				'critical',
				'php_version_critical',
				sprintf(
					/* translators: %s: PHP version */
					__( 'PHP version %s is below the minimum required (7.4).', 'wp-site-doctor' ),
					$version
				),
				__( 'Contact your hosting provider to upgrade PHP to at least 8.1.', 'wp-site-doctor' )
			);
		} elseif ( version_compare( $version, '8.0', '<' ) ) {
			$this->add_issue(
				'warning',
				'php_version_outdated',
				sprintf(
					/* translators: %s: PHP version */
					__( 'PHP version %s is outdated. PHP 8.1+ is recommended for performance and security.', 'wp-site-doctor' ),
					$version
				),
				__( 'Contact your hosting provider to upgrade to PHP 8.1 or later.', 'wp-site-doctor' )
			);
		} elseif ( version_compare( $version, '8.1', '<' ) ) {
			$this->add_issue(
				'info',
				'php_version_acceptable',
				sprintf(
					/* translators: %s: PHP version */
					__( 'PHP version %s is acceptable. PHP 8.1+ is recommended.', 'wp-site-doctor' ),
					$version
				),
				__( 'Consider upgrading to PHP 8.1+ for better performance.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'php_version_ok',
				sprintf(
					/* translators: %s: PHP version */
					__( 'PHP version %s is up to date.', 'wp-site-doctor' ),
					$version
				)
			);
		}
	}

	/**
	 * Check MySQL/MariaDB version.
	 */
	private function check_mysql_version() {
		global $wpdb;

		$db_version = $wpdb->db_version();
		$db_server  = $wpdb->db_server_info();
		$is_maria   = ( false !== stripos( $db_server, 'mariadb' ) );
		$label      = $is_maria ? 'MariaDB' : 'MySQL';

		$min_version = $is_maria ? '10.3' : '5.7';

		if ( version_compare( $db_version, $min_version, '<' ) ) {
			$this->add_issue(
				'warning',
				'db_version_outdated',
				sprintf(
					/* translators: 1: database type, 2: version, 3: minimum version */
					__( '%1$s version %2$s is below the recommended minimum (%3$s).', 'wp-site-doctor' ),
					$label,
					$db_version,
					$min_version
				),
				__( 'Contact your hosting provider to upgrade your database server.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'db_version_ok',
				sprintf(
					/* translators: 1: database type, 2: version */
					__( '%1$s version %2$s is up to date.', 'wp-site-doctor' ),
					$label,
					$db_version
				)
			);
		}
	}

	/**
	 * Check web server software.
	 */
	private function check_web_server() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'wp-site-doctor' );

		$this->add_issue(
			'info',
			'web_server',
			sprintf(
				/* translators: %s: web server software string */
				__( 'Web server: %s', 'wp-site-doctor' ),
				$server
			)
		);
	}

	/**
	 * Check PHP memory_limit.
	 */
	private function check_php_memory_limit() {
		$limit       = ini_get( 'memory_limit' );
		$limit_bytes = $this->convert_to_bytes( $limit );

		if ( -1 === (int) $limit ) {
			$this->add_issue(
				'info',
				'php_memory_unlimited',
				__( 'PHP memory_limit is set to unlimited (-1).', 'wp-site-doctor' ),
				__( 'This is acceptable but consider setting a defined limit in production.', 'wp-site-doctor' )
			);
		} elseif ( $limit_bytes < 128 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'php_memory_low',
				sprintf(
					/* translators: %s: memory limit value */
					__( 'PHP memory_limit is %s. At least 128M is recommended.', 'wp-site-doctor' ),
					$limit
				),
				__( 'Increase memory_limit in php.ini or .htaccess, or contact your host.', 'wp-site-doctor' )
			);
		} elseif ( $limit_bytes < 256 * 1024 * 1024 ) {
			$this->add_issue(
				'info',
				'php_memory_acceptable',
				sprintf(
					/* translators: %s: memory limit value */
					__( 'PHP memory_limit is %s. 256M is recommended for optimal performance.', 'wp-site-doctor' ),
					$limit
				),
				__( 'Consider increasing to 256M if you experience memory issues.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'php_memory_ok',
				sprintf(
					/* translators: %s: memory limit value */
					__( 'PHP memory_limit is %s.', 'wp-site-doctor' ),
					$limit
				)
			);
		}
	}

	/**
	 * Check PHP max_execution_time.
	 */
	private function check_max_execution_time() {
		$time = (int) ini_get( 'max_execution_time' );

		if ( 0 === $time ) {
			$this->add_pass(
				'max_execution_unlimited',
				__( 'PHP max_execution_time is unlimited (0).', 'wp-site-doctor' )
			);
		} elseif ( $time < 60 ) {
			$this->add_issue(
				'warning',
				'max_execution_low',
				sprintf(
					/* translators: %d: seconds */
					__( 'PHP max_execution_time is %d seconds. At least 120 seconds is recommended.', 'wp-site-doctor' ),
					$time
				),
				__( 'Increase max_execution_time in php.ini or contact your hosting provider.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'max_execution_ok',
				sprintf(
					/* translators: %d: seconds */
					__( 'PHP max_execution_time is %d seconds.', 'wp-site-doctor' ),
					$time
				)
			);
		}
	}

	/**
	 * Check upload_max_filesize and post_max_size.
	 */
	private function check_upload_limits() {
		$upload_max = ini_get( 'upload_max_filesize' );
		$post_max   = ini_get( 'post_max_size' );

		$upload_bytes = $this->convert_to_bytes( $upload_max );
		$post_bytes   = $this->convert_to_bytes( $post_max );

		if ( $upload_bytes < 8 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'upload_max_low',
				sprintf(
					/* translators: %s: upload_max_filesize value */
					__( 'upload_max_filesize is %s. Consider increasing to at least 8M.', 'wp-site-doctor' ),
					$upload_max
				),
				__( 'Adjust in php.ini, .htaccess, or wp-config.php.', 'wp-site-doctor' )
			);
		}

		if ( $post_bytes < $upload_bytes ) {
			$this->add_issue(
				'warning',
				'post_max_smaller',
				sprintf(
					/* translators: 1: post_max_size, 2: upload_max_filesize */
					__( 'post_max_size (%1$s) is smaller than upload_max_filesize (%2$s). This will limit uploads.', 'wp-site-doctor' ),
					$post_max,
					$upload_max
				),
				__( 'Set post_max_size to at least the same value as upload_max_filesize.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check max_input_vars.
	 */
	private function check_max_input_vars() {
		$vars = (int) ini_get( 'max_input_vars' );

		if ( $vars < 1000 ) {
			$this->add_issue(
				'warning',
				'max_input_vars_low',
				sprintf(
					/* translators: %d: max_input_vars value */
					__( 'max_input_vars is %d. At least 3000 is recommended for complex forms and menus.', 'wp-site-doctor' ),
					$vars
				),
				__( 'Increase max_input_vars in php.ini.', 'wp-site-doctor' )
			);
		} elseif ( $vars < 3000 ) {
			$this->add_issue(
				'info',
				'max_input_vars_acceptable',
				sprintf(
					/* translators: %d: max_input_vars value */
					__( 'max_input_vars is %d. 3000+ is recommended.', 'wp-site-doctor' ),
					$vars
				),
				__( 'Consider increasing if you use complex menus or forms.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check required PHP extensions.
	 */
	private function check_required_extensions() {
		$required = array(
			'curl'     => __( 'Required for HTTP requests and API communication.', 'wp-site-doctor' ),
			'mbstring' => __( 'Required for multibyte string handling.', 'wp-site-doctor' ),
			'xml'      => __( 'Required for XML parsing (feeds, sitemaps).', 'wp-site-doctor' ),
			'zip'      => __( 'Required for plugin/theme updates.', 'wp-site-doctor' ),
			'openssl'  => __( 'Required for SSL/HTTPS connections.', 'wp-site-doctor' ),
		);

		$recommended = array(
			'imagick' => __( 'Recommended for advanced image processing (alternative: gd).', 'wp-site-doctor' ),
			'intl'    => __( 'Recommended for internationalization.', 'wp-site-doctor' ),
		);

		foreach ( $required as $ext => $reason ) {
			if ( ! $this->is_extension_loaded( $ext ) ) {
				$this->add_issue(
					'critical',
					'ext_missing_' . $ext,
					sprintf(
						/* translators: %s: PHP extension name */
						__( 'Required PHP extension "%s" is not loaded.', 'wp-site-doctor' ),
						$ext
					),
					$reason . ' ' . __( 'Contact your hosting provider to enable it.', 'wp-site-doctor' )
				);
			}
		}

		// Check for at least gd OR imagick.
		if ( ! $this->is_extension_loaded( 'gd' ) && ! $this->is_extension_loaded( 'imagick' ) ) {
			$this->add_issue(
				'critical',
				'ext_missing_image',
				__( 'Neither GD nor Imagick PHP extension is loaded.', 'wp-site-doctor' ),
				__( 'At least one image processing extension is required. Contact your host.', 'wp-site-doctor' )
			);
		}

		foreach ( $recommended as $ext => $reason ) {
			if ( ! $this->is_extension_loaded( $ext ) ) {
				$this->add_issue(
					'info',
					'ext_recommended_' . $ext,
					sprintf(
						/* translators: %s: PHP extension name */
						__( 'Recommended PHP extension "%s" is not loaded.', 'wp-site-doctor' ),
						$ext
					),
					$reason
				);
			}
		}
	}

	/**
	 * Check WordPress memory limit constant.
	 */
	private function check_wp_memory_limit() {
		$wp_limit = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '40M';
		$bytes    = $this->convert_to_bytes( $wp_limit );

		if ( $bytes < 64 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'wp_memory_low',
				sprintf(
					/* translators: %s: WP_MEMORY_LIMIT value */
					__( 'WP_MEMORY_LIMIT is %s. At least 128M is recommended.', 'wp-site-doctor' ),
					$wp_limit
				),
				__( 'Add or increase WP_MEMORY_LIMIT in wp-config.php.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check if WordPress is a multisite installation.
	 */
	private function check_multisite() {
		if ( is_multisite() ) {
			$this->add_issue(
				'info',
				'multisite_detected',
				__( 'WordPress Multisite is active.', 'wp-site-doctor' ),
				__( 'Some checks are scoped to this individual site, not the entire network.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check WordPress cron method.
	 */
	private function check_wp_cron_method() {
		if ( $this->is_constant_true( 'DISABLE_WP_CRON' ) ) {
			$this->add_issue(
				'info',
				'wp_cron_disabled',
				__( 'WP-Cron is disabled via DISABLE_WP_CRON constant.', 'wp-site-doctor' ),
				__( 'Ensure a system cron job is configured to call wp-cron.php regularly.', 'wp-site-doctor' )
			);
		}

		if ( $this->is_constant_true( 'ALTERNATE_WP_CRON' ) ) {
			$this->add_issue(
				'info',
				'alternate_cron',
				__( 'ALTERNATE_WP_CRON is enabled.', 'wp-site-doctor' ),
				__( 'This is typically used when standard WP-Cron does not work reliably.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check if uploads directory is writable.
	 */
	private function check_uploads_writable() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		if ( ! wp_is_writable( $basedir ) ) {
			$this->add_issue(
				'critical',
				'uploads_not_writable',
				__( 'The uploads directory is not writable.', 'wp-site-doctor' ),
				sprintf(
					/* translators: %s: directory path */
					__( 'Set proper permissions (755) on %s.', 'wp-site-doctor' ),
					$basedir
				)
			);
		}
	}

	/**
	 * Check if wp-content is writable.
	 */
	private function check_wp_content_writable() {
		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			$this->add_issue(
				'warning',
				'wp_content_not_writable',
				__( 'The wp-content directory is not writable.', 'wp-site-doctor' ),
				__( 'This may prevent plugin/theme updates and file operations.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check if WordPress is installed in a subdirectory.
	 */
	private function check_subdirectory_install() {
		$site_url = get_option( 'siteurl' );
		$home_url = get_option( 'home' );

		if ( $site_url !== $home_url ) {
			$this->add_issue(
				'info',
				'subdirectory_install',
				__( 'WordPress is installed in a subdirectory (site URL differs from home URL).', 'wp-site-doctor' ),
				sprintf(
					/* translators: 1: site URL, 2: home URL */
					__( 'Site URL: %1$s | Home URL: %2$s', 'wp-site-doctor' ),
					$site_url,
					$home_url
				)
			);
		}
	}
}
