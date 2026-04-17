<?php
/**
 * File Permissions Scanner for WP Site Doctor.
 *
 * Checks critical file/directory permissions and detects
 * suspicious PHP files in the uploads directory.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class File_Permissions_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'file_permissions';
	}

	public function get_label(): string {
		return __( 'File Permissions', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'file_permissions';
	}

	public function run(): array {
		$this->reset();

		$this->check_wp_config_perms();
		$this->check_htaccess_perms();
		$this->check_directory_perms( WP_CONTENT_DIR, 'wp-content', '755' );
		$this->check_directory_perms( WP_CONTENT_DIR . '/uploads', 'wp-content/uploads', '755' );
		$this->check_directory_perms( WP_PLUGIN_DIR, 'wp-content/plugins', '755' );
		$this->check_directory_perms( get_theme_root(), 'wp-content/themes', '755' );
		$this->check_suspicious_uploads();
		$this->check_debug_log_file();

		return $this->build_result();
	}

	private function check_wp_config_perms() {
		$path = ABSPATH . 'wp-config.php';

		if ( ! file_exists( $path ) ) {
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( ! file_exists( $path ) ) {
			$this->add_issue(
				'info',
				'wp_config_not_found',
				__( 'Could not locate wp-config.php to check permissions.', 'wp-site-doctor' )
			);
			return;
		}

		$perms = $this->get_file_permissions( $path );

		if ( false === $perms ) {
			$this->add_issue(
				'info',
				'wp_config_perms_unreadable',
				__( 'Could not read wp-config.php permissions (hosting restriction).', 'wp-site-doctor' )
			);
			return;
		}

		if ( $this->is_world_writable( $path ) ) {
			$this->add_issue(
				'critical',
				'wp_config_world_writable',
				sprintf(
					/* translators: %s: permissions */
					__( 'wp-config.php is world-writable (permissions: %s). Severe security risk.', 'wp-site-doctor' ),
					$perms
				),
				__( 'Change to 400 or 440: chmod 440 wp-config.php', 'wp-site-doctor' ),
				array(
					'action_id'   => 'fix_wp_config_perms',
					'label'       => __( 'Fix wp-config.php permissions to 440', 'wp-site-doctor' ),
					'description' => __( 'Changes wp-config.php permissions to 440.', 'wp-site-doctor' ),
				)
			);
		} elseif ( '644' === $perms || '664' === $perms ) {
			$this->add_issue(
				'warning',
				'wp_config_too_permissive',
				sprintf(
					/* translators: %s: permissions */
					__( 'wp-config.php permissions (%s) are more permissive than recommended (440 or 400).', 'wp-site-doctor' ),
					$perms
				),
				__( 'Change to 440: chmod 440 wp-config.php', 'wp-site-doctor' ),
				array(
					'action_id'   => 'fix_wp_config_perms',
					'label'       => __( 'Fix wp-config.php permissions to 440', 'wp-site-doctor' ),
					'description' => __( 'Changes wp-config.php permissions to 440.', 'wp-site-doctor' ),
				)
			);
		} else {
			$this->add_pass(
				'wp_config_perms_ok',
				sprintf(
					/* translators: %s: permissions */
					__( 'wp-config.php permissions: %s', 'wp-site-doctor' ),
					$perms
				)
			);
		}
	}

	private function check_htaccess_perms() {
		$path = ABSPATH . '.htaccess';

		if ( ! file_exists( $path ) ) {
			return; // Nginx or no htaccess.
		}

		$perms = $this->get_file_permissions( $path );

		if ( false === $perms ) {
			return;
		}

		if ( $this->is_world_writable( $path ) ) {
			$this->add_issue(
				'warning',
				'htaccess_world_writable',
				sprintf(
					/* translators: %s: permissions */
					__( '.htaccess is world-writable (permissions: %s).', 'wp-site-doctor' ),
					$perms
				),
				__( 'Change to 644: chmod 644 .htaccess', 'wp-site-doctor' ),
				array(
					'action_id'   => 'fix_htaccess_perms',
					'label'       => __( 'Fix .htaccess permissions to 644', 'wp-site-doctor' ),
					'description' => __( 'Changes .htaccess permissions to 644.', 'wp-site-doctor' ),
				)
			);
		} else {
			$this->add_pass(
				'htaccess_perms_ok',
				sprintf(
					/* translators: %s: permissions */
					__( '.htaccess permissions: %s', 'wp-site-doctor' ),
					$perms
				)
			);
		}
	}

	/**
	 * Check permissions of a directory.
	 *
	 * @param string $path     Absolute directory path.
	 * @param string $label    Human-readable name.
	 * @param string $expected Expected permission string.
	 */
	private function check_directory_perms( $path, $label, $expected ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$perms = $this->get_file_permissions( $path );

		if ( false === $perms ) {
			$this->add_issue(
				'info',
				'dir_perms_unreadable_' . sanitize_key( $label ),
				sprintf(
					/* translators: %s: directory name */
					__( 'Could not read %s directory permissions.', 'wp-site-doctor' ),
					$label
				)
			);
			return;
		}

		if ( '777' === $perms ) {
			$this->add_issue(
				'critical',
				'dir_777_' . sanitize_key( $label ),
				sprintf(
					/* translators: 1: dir name, 2: permissions */
					__( '%1$s has 777 permissions. This is a severe security risk.', 'wp-site-doctor' ),
					$label
				),
				sprintf(
					/* translators: 1: dir name, 2: recommended permissions */
					__( 'Change to %2$s: chmod %2$s %1$s', 'wp-site-doctor' ),
					$label,
					$expected
				),
				array(
					'action_id'   => 'fix_dir_perms_' . sanitize_key( $label ),
					'label'       => sprintf(
						/* translators: 1: dir name, 2: permissions */
						__( 'Fix %1$s permissions to %2$s', 'wp-site-doctor' ),
						$label,
						$expected
					),
					'description' => sprintf(
						/* translators: %s: dir name */
						__( 'Changes %s directory permissions.', 'wp-site-doctor' ),
						$label
					),
				)
			);
		}
	}

	/**
	 * Check for suspicious PHP files in uploads directory.
	 */
	private function check_suspicious_uploads() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		if ( ! is_dir( $basedir ) ) {
			return;
		}

		$php_files = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			$count = 0;
			foreach ( $iterator as $file ) {
				if ( $count >= 500 ) {
					break; // Safety limit.
				}

				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$php_files[] = str_replace( $basedir . '/', '', $file->getPathname() );
				}
				++$count;
			}
		} catch ( \Exception $e ) {
			$this->add_issue(
				'info',
				'uploads_scan_error',
				__( 'Could not scan uploads directory for suspicious files (permission denied).', 'wp-site-doctor' )
			);
			return;
		}

		if ( ! empty( $php_files ) ) {
			$file_list = implode( ', ', array_slice( $php_files, 0, 5 ) );
			if ( count( $php_files ) > 5 ) {
				$file_list .= '...';
			}

			$this->add_issue(
				'critical',
				'php_in_uploads',
				sprintf(
					/* translators: 1: count, 2: file list */
					__( '%1$d PHP file(s) found in uploads directory: %2$s', 'wp-site-doctor' ),
					count( $php_files ),
					$file_list
				),
				__( 'PHP files in the uploads directory are a strong indicator of a hack. Investigate immediately and delete if suspicious.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'no_php_in_uploads',
				__( 'No PHP files found in the uploads directory.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check if debug.log file exists and is accessible.
	 */
	private function check_debug_log_file() {
		$log_path = WP_CONTENT_DIR . '/debug.log';

		if ( file_exists( $log_path ) ) {
			$size = filesize( $log_path );

			$this->add_issue(
				'warning',
				'debug_log_exists',
				sprintf(
					/* translators: %s: file size */
					__( 'debug.log exists in wp-content/ (%s). Ensure it is not publicly accessible.', 'wp-site-doctor' ),
					$this->format_bytes( $size )
				),
				__( 'Block access via .htaccess or move the log file outside the web root by defining WP_DEBUG_LOG to a custom path.', 'wp-site-doctor' )
			);
		}
	}
}
