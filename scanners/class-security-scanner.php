<?php
/**
 * Security Scanner for WP Site Doctor.
 *
 * Checks core integrity, permissions, SSL, headers, debug mode,
 * XML-RPC, REST API exposure, and common security misconfigurations.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Security_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'security';
	}

	public function get_label(): string {
		return __( 'Security', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'security';
	}

	public function run(): array {
		$this->reset();

		$this->check_ssl();
		$this->check_wp_debug();
		$this->check_file_editing();
		$this->check_db_prefix();
		$this->check_admin_username();
		$this->check_xmlrpc();
		$this->check_rest_api_users();
		$this->check_security_headers();
		$this->check_directory_listing();
		$this->check_wp_config_permissions();
		$this->check_core_integrity();
		$this->check_unused_themes();
		$this->check_wp_version_exposed();

		return $this->build_result();
	}

	private function check_ssl() {
		if ( ! is_ssl() ) {
			$this->add_issue(
				'critical',
				'no_ssl',
				__( 'Site is not using HTTPS/SSL.', 'wp-site-doctor' ),
				__( 'Install an SSL certificate and force HTTPS. Most hosts offer free Let\'s Encrypt certificates.', 'wp-site-doctor' )
			);
		} elseif ( ! $this->is_constant_true( 'FORCE_SSL_ADMIN' ) ) {
			$this->add_issue(
				'warning',
				'no_force_ssl_admin',
				__( 'FORCE_SSL_ADMIN is not enabled.', 'wp-site-doctor' ),
				__( 'Add define(\'FORCE_SSL_ADMIN\', true); to wp-config.php to enforce SSL on admin pages.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'ssl_ok', __( 'SSL is active and enforced for admin.', 'wp-site-doctor' ) );
		}
	}

	private function check_wp_debug() {
		if ( $this->is_constant_true( 'WP_DEBUG' ) && $this->is_constant_true( 'WP_DEBUG_DISPLAY' ) ) {
			$this->add_issue(
				'critical',
				'debug_display_on',
				__( 'WP_DEBUG and WP_DEBUG_DISPLAY are both enabled. Errors are visible to visitors.', 'wp-site-doctor' ),
				__( 'Set WP_DEBUG_DISPLAY to false in wp-config.php. Use WP_DEBUG_LOG instead.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'disable_debug_display',
					'label'       => __( 'Disable debug display', 'wp-site-doctor' ),
					'description' => __( 'Sets WP_DEBUG_DISPLAY to false via a mu-plugin filter.', 'wp-site-doctor' ),
				)
			);
		} elseif ( $this->is_constant_true( 'WP_DEBUG' ) ) {
			$this->add_issue(
				'warning',
				'debug_on',
				__( 'WP_DEBUG is enabled. This should be disabled in production.', 'wp-site-doctor' ),
				__( 'Set WP_DEBUG to false in wp-config.php for production sites.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'debug_off', __( 'Debug mode is properly disabled.', 'wp-site-doctor' ) );
		}

		if ( $this->is_constant_true( 'WP_DEBUG_LOG' ) ) {
			$log_path = WP_CONTENT_DIR . '/debug.log';
			if ( file_exists( $log_path ) ) {
				$response = $this->remote_get( content_url( 'debug.log' ), 3 );
				if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
					$this->add_issue(
						'critical',
						'debug_log_accessible',
						__( 'debug.log is publicly accessible via URL.', 'wp-site-doctor' ),
						__( 'Block access to debug.log in .htaccess or nginx config, or move it outside the web root.', 'wp-site-doctor' )
					);
				}
			}
		}
	}

	private function check_file_editing() {
		if ( ! $this->is_constant_true( 'DISALLOW_FILE_EDIT' ) ) {
			$this->add_issue(
				'warning',
				'file_editing_enabled',
				__( 'File editing is enabled in the WordPress dashboard.', 'wp-site-doctor' ),
				__( 'Add define(\'DISALLOW_FILE_EDIT\', true); to wp-config.php to prevent code editing from the admin panel.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'disable_file_edit',
					'label'       => __( 'Disable file editing', 'wp-site-doctor' ),
					'description' => __( 'Adds DISALLOW_FILE_EDIT constant via mu-plugin.', 'wp-site-doctor' ),
				)
			);
		} else {
			$this->add_pass( 'file_editing_disabled', __( 'Dashboard file editing is disabled.', 'wp-site-doctor' ) );
		}
	}

	private function check_db_prefix() {
		global $wpdb;

		if ( 'wp_' === $wpdb->prefix ) {
			$this->add_issue(
				'warning',
				'default_db_prefix',
				__( 'Database table prefix is the default "wp_". This makes SQL injection attacks easier.', 'wp-site-doctor' ),
				__( 'Changing the prefix on an existing site is complex. Consider using a security plugin to add extra protection layers.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'db_prefix_custom', __( 'Database table prefix has been customized.', 'wp-site-doctor' ) );
		}
	}

	private function check_admin_username() {
		$admin_user = get_user_by( 'login', 'admin' );

		if ( $admin_user ) {
			$this->add_issue(
				'warning',
				'admin_username',
				__( 'A user with the username "admin" exists. This is the first username attackers try.', 'wp-site-doctor' ),
				__( 'Create a new administrator account with a unique username, transfer content, then delete the "admin" account.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'no_admin_user', __( 'No user with the default "admin" username found.', 'wp-site-doctor' ) );
		}
	}

	private function check_xmlrpc() {
		$response = $this->remote_get( home_url( '/xmlrpc.php' ), 5 );

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code && false !== strpos( $body, 'XML-RPC server accepts POST requests only' ) ) {
				$this->add_issue(
					'warning',
					'xmlrpc_enabled',
					__( 'XML-RPC is enabled and accessible. It can be exploited for brute force and DDoS attacks.', 'wp-site-doctor' ),
					__( 'Disable XML-RPC if you do not use the WordPress mobile app, Jetpack, or pingbacks.', 'wp-site-doctor' ),
					array(
						'action_id'   => 'disable_xmlrpc',
						'label'       => __( 'Disable XML-RPC', 'wp-site-doctor' ),
						'description' => __( 'Adds a filter to block XML-RPC requests.', 'wp-site-doctor' ),
					)
				);
			}
		}
	}

	private function check_rest_api_users() {
		$response = $this->remote_get( rest_url( 'wp/v2/users' ), 5 );

		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				$users = json_decode( $body, true );
				if ( is_array( $users ) && ! empty( $users ) ) {
					$this->add_issue(
						'warning',
						'rest_api_user_enum',
						__( 'User enumeration is possible via the REST API. Usernames are publicly exposed.', 'wp-site-doctor' ),
						__( 'Restrict the wp/v2/users endpoint to authenticated users only using a security plugin or custom code.', 'wp-site-doctor' ),
						array(
							'action_id'   => 'disable_user_enum',
							'label'       => __( 'Block user enumeration', 'wp-site-doctor' ),
							'description' => __( 'Adds a filter to restrict the users REST API endpoint.', 'wp-site-doctor' ),
						)
					);
					return;
				}
			}
		}

		$this->add_pass( 'rest_api_users_protected', __( 'REST API user enumeration is restricted.', 'wp-site-doctor' ) );
	}

	private function check_security_headers() {
		$response = $this->remote_get( home_url( '/' ), 5 );

		if ( is_wp_error( $response ) ) {
			$this->add_issue(
				'info',
				'headers_check_failed',
				__( 'Could not check security headers — site request failed.', 'wp-site-doctor' )
			);
			return;
		}

		$headers = wp_remote_retrieve_headers( $response );

		$checks = array(
			'x-content-type-options' => array(
				'code'    => 'no_xcto_header',
				'message' => __( 'X-Content-Type-Options header is missing.', 'wp-site-doctor' ),
				'fix'     => __( 'Add "X-Content-Type-Options: nosniff" header via .htaccess or server config.', 'wp-site-doctor' ),
			),
			'x-frame-options'        => array(
				'code'    => 'no_xfo_header',
				'message' => __( 'X-Frame-Options header is missing. Site may be vulnerable to clickjacking.', 'wp-site-doctor' ),
				'fix'     => __( 'Add "X-Frame-Options: SAMEORIGIN" header via .htaccess or server config.', 'wp-site-doctor' ),
			),
			'referrer-policy'        => array(
				'code'    => 'no_referrer_policy',
				'message' => __( 'Referrer-Policy header is missing.', 'wp-site-doctor' ),
				'fix'     => __( 'Add "Referrer-Policy: strict-origin-when-cross-origin" header.', 'wp-site-doctor' ),
			),
		);

		$missing_count = 0;
		foreach ( $checks as $header => $info ) {
			if ( ! isset( $headers[ $header ] ) ) {
				++$missing_count;
			}
		}

		if ( $missing_count > 0 ) {
			$missing_list = array();
			foreach ( $checks as $header => $info ) {
				if ( ! isset( $headers[ $header ] ) ) {
					$missing_list[] = $header;
				}
			}

			$this->add_issue(
				'warning',
				'missing_security_headers',
				sprintf(
					/* translators: %s: comma-separated list of missing headers */
					__( 'Missing security headers: %s', 'wp-site-doctor' ),
					implode( ', ', $missing_list )
				),
				__( 'Add these headers via .htaccess (Apache) or server configuration (Nginx).', 'wp-site-doctor' ),
				array(
					'action_id'   => 'add_security_headers',
					'label'       => __( 'Add security headers', 'wp-site-doctor' ),
					'description' => __( 'Adds recommended security headers to .htaccess.', 'wp-site-doctor' ),
				)
			);
		} else {
			$this->add_pass( 'security_headers_ok', __( 'All recommended security headers are present.', 'wp-site-doctor' ) );
		}
	}

	private function check_directory_listing() {
		$test_dirs = array(
			WP_CONTENT_DIR . '/uploads/',
			WP_PLUGIN_DIR . '/',
		);

		foreach ( $test_dirs as $dir ) {
			$url      = str_replace( ABSPATH, home_url( '/' ), $dir );
			$response = $this->remote_get( $url, 3 );

			if ( ! is_wp_error( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				$code = wp_remote_retrieve_response_code( $response );

				if ( 200 === $code && ( false !== stripos( $body, 'Index of' ) || false !== stripos( $body, '<title>Index' ) ) ) {
					$this->add_issue(
						'warning',
						'directory_listing',
						__( 'Directory listing is enabled. Visitors can browse your file structure.', 'wp-site-doctor' ),
						__( 'Add "Options -Indexes" to your .htaccess file or configure your web server to disable directory browsing.', 'wp-site-doctor' )
					);
					return;
				}
			}
		}

		$this->add_pass( 'no_directory_listing', __( 'Directory listing is disabled.', 'wp-site-doctor' ) );
	}

	private function check_wp_config_permissions() {
		$config_path = ABSPATH . 'wp-config.php';

		if ( ! file_exists( $config_path ) ) {
			$config_path = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( file_exists( $config_path ) ) {
			$perms = $this->get_file_permissions( $config_path );

			if ( false !== $perms && $this->is_world_writable( $config_path ) ) {
				$this->add_issue(
					'critical',
					'wp_config_world_writable',
					sprintf(
						/* translators: %s: file permissions */
						__( 'wp-config.php is world-writable (permissions: %s). This is a severe security risk.', 'wp-site-doctor' ),
						$perms
					),
					__( 'Change wp-config.php permissions to 400 or 440.', 'wp-site-doctor' ),
					array(
						'action_id'   => 'fix_wp_config_perms',
						'label'       => __( 'Fix wp-config.php permissions', 'wp-site-doctor' ),
						'description' => __( 'Sets wp-config.php permissions to 440.', 'wp-site-doctor' ),
					)
				);
			}
		}
	}

	private function check_core_integrity() {
		$locale  = get_locale();
		$version = get_bloginfo( 'version' );

		$response = wp_remote_get(
			'https://api.wordpress.org/core/checksums/1.0/?version=' . $version . '&locale=' . $locale,
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			$this->add_issue(
				'info',
				'core_integrity_unavailable',
				__( 'Could not verify core file integrity — WordPress.org API unavailable.', 'wp-site-doctor' )
			);
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['checksums'] ) ) {
			$this->add_issue(
				'info',
				'core_checksums_unavailable',
				__( 'Core checksums not available for this WordPress version/locale.', 'wp-site-doctor' )
			);
			return;
		}

		$modified = 0;
		$checked  = 0;
		$limit    = 200; // Limit for performance.

		foreach ( $body['checksums'] as $file => $checksum ) {
			if ( $checked >= $limit ) {
				break;
			}

			$filepath = ABSPATH . $file;
			if ( file_exists( $filepath ) ) {
				if ( md5_file( $filepath ) !== $checksum ) {
					++$modified;
				}
			}
			++$checked;
		}

		if ( $modified > 0 ) {
			$this->add_issue(
				'warning',
				'core_files_modified',
				sprintf(
					/* translators: %d: number of modified files */
					__( '%d WordPress core files have been modified.', 'wp-site-doctor' ),
					$modified
				),
				__( 'This could indicate a hack or intentional modification. Reinstall WordPress core files via Dashboard > Updates.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'core_integrity_ok',
				sprintf(
					/* translators: %d: number of files checked */
					__( 'Core file integrity verified (%d files checked).', 'wp-site-doctor' ),
					$checked
				)
			);
		}
	}

	private function check_unused_themes() {
		$themes       = wp_get_themes();
		$active_theme = get_stylesheet();
		$parent_theme = get_template();
		$unused       = 0;

		foreach ( $themes as $slug => $theme ) {
			if ( $slug !== $active_theme && $slug !== $parent_theme ) {
				++$unused;
			}
		}

		if ( $unused > 1 ) {
			$this->add_issue(
				'info',
				'unused_themes',
				sprintf(
					/* translators: %d: number of unused themes */
					__( '%d unused themes are installed. Unused themes can be a security risk.', 'wp-site-doctor' ),
					$unused
				),
				__( 'Keep only the active theme and one default theme as fallback. Delete the rest from Appearance > Themes.', 'wp-site-doctor' )
			);
		}
	}

	private function check_wp_version_exposed() {
		$response = $this->remote_get( home_url( '/' ), 3 );

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );

			if ( false !== strpos( $body, 'generator" content="WordPress' ) ) {
				$this->add_issue(
					'info',
					'wp_version_exposed',
					__( 'WordPress version is exposed in the page source via the generator meta tag.', 'wp-site-doctor' ),
					__( 'Remove the generator tag by adding remove_action(\'wp_head\', \'wp_generator\'); to your theme or a plugin.', 'wp-site-doctor' )
				);
			}
		}
	}
}
