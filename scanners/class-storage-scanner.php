<?php
/**
 * Storage Scanner for WP Site Doctor.
 *
 * Analyzes disk usage, inode consumption, orphaned media files,
 * database bloat, and provides cleanup recommendations.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Class Storage_Scanner
 *
 * Deep storage analysis: finds orphaned files on disk that have no
 * matching attachment record in the database, calculates recoverable space,
 * and reports on database table sizes.
 */
class Storage_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'storage';
	}

	public function get_label(): string {
		return __( 'Storage & Cleanup', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'storage';
	}

	public function run(): array {
		$this->reset();

		$this->check_disk_usage();
		$this->check_inode_usage();
		$this->check_orphaned_media();
		$this->check_database_size();
		$this->check_postmeta_bloat();
		$this->check_actionscheduler_bloat();
		$this->check_trash_and_revisions();
		$this->check_litespeed_cache();
		$this->check_log_files();

		return $this->build_result();
	}

	/**
	 * Check overall disk usage of the uploads directory.
	 */
	private function check_disk_usage() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		if ( ! is_dir( $basedir ) ) {
			return;
		}

		$total_size  = 0;
		$total_files = 0;
		$by_year     = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$size         = $file->getSize();
				$total_size  += $size;
				++$total_files;

				// Extract year from path.
				$rel = str_replace( $basedir . '/', '', $file->getPathname() );
				$parts = explode( '/', $rel );
				if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
					if ( ! isset( $by_year[ $parts[0] ] ) ) {
						$by_year[ $parts[0] ] = array( 'size' => 0, 'files' => 0 );
					}
					$by_year[ $parts[0] ]['size']  += $size;
					$by_year[ $parts[0] ]['files'] += 1;
				}
			}
		} catch ( \Exception $e ) {
			$this->add_issue(
				'info',
				'disk_scan_error',
				__( 'Could not fully scan uploads directory (permission denied on some paths).', 'wp-site-doctor' )
			);
			return;
		}

		// Report total.
		$size_human = $this->format_bytes( $total_size );

		$severity = 'info';
		if ( $total_size > 10 * 1024 * 1024 * 1024 ) {
			$severity = 'warning';
		}

		// Build year breakdown.
		krsort( $by_year );
		$year_details = array();
		foreach ( $by_year as $year => $data ) {
			$year_details[] = $year . ': ' . $this->format_bytes( $data['size'] ) . ' (' . number_format_i18n( $data['files'] ) . ' files)';
		}

		$this->add_issue(
			$severity,
			'uploads_disk_usage',
			sprintf(
				/* translators: 1: total size, 2: file count */
				__( 'Uploads directory: %1$s across %2$s files.', 'wp-site-doctor' ),
				$size_human,
				number_format_i18n( $total_files )
			),
			! empty( $year_details )
				? __( 'Breakdown by year: ', 'wp-site-doctor' ) . implode( ' | ', array_slice( $year_details, 0, 5 ) )
				: ''
		);
	}

	/**
	 * Check inode usage (total files + directories).
	 */
	private function check_inode_usage() {
		$wp_root = ABSPATH;

		// Count inodes in wp-content only (most relevant).
		$inode_count = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( WP_CONTENT_DIR, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				++$inode_count;
				if ( $inode_count > 500000 ) {
					break; // Safety cap.
				}
			}
		} catch ( \Exception $e ) {
			// Silently skip.
		}

		if ( $inode_count > 200000 ) {
			$this->add_issue(
				'warning',
				'high_inode_count',
				sprintf(
					/* translators: %s: inode count */
					__( 'wp-content directory contains %s+ inodes (files + directories). High inode usage can hit hosting limits.', 'wp-site-doctor' ),
					number_format_i18n( $inode_count )
				),
				__( 'Delete orphaned media files and unnecessary thumbnails to reduce inode count. Each image creates 5-10 thumbnail files.', 'wp-site-doctor' )
			);
		} elseif ( $inode_count > 100000 ) {
			$this->add_issue(
				'info',
				'moderate_inode_count',
				sprintf(
					/* translators: %s: inode count */
					__( 'wp-content directory contains %s inodes.', 'wp-site-doctor' ),
					number_format_i18n( $inode_count )
				)
			);
		}
	}

	/**
	 * Detect orphaned media files — files on disk with no matching DB attachment.
	 *
	 * This is the critical check: when posts are deleted but their media files
	 * are left on disk, the space is never recovered.
	 */
	private function check_orphaned_media() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		if ( ! is_dir( $basedir ) ) {
			return;
		}

		// Get all attachment file paths from the database.
		$db_main_files = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		$db_main_set = array_flip( $db_main_files );

		// Get all thumbnail paths from _wp_attachment_metadata.
		$db_thumb_set = array();
		$meta_rows    = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
		);

		foreach ( $meta_rows as $raw ) {
			$meta = maybe_unserialize( $raw );
			if ( is_array( $meta ) && isset( $meta['sizes'] ) ) {
				$dir = isset( $meta['file'] ) ? dirname( $meta['file'] ) : '';
				foreach ( $meta['sizes'] as $size_data ) {
					if ( isset( $size_data['file'] ) ) {
						$db_thumb_set[ $dir . '/' . $size_data['file'] ] = 1;
					}
				}
			}
		}

		// Scan uploads directory for files not in DB.
		$media_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'svg', 'mp4', 'mp3', 'pdf', 'doc', 'docx' );
		$orphan_count     = 0;
		$orphan_size      = 0;
		$scanned          = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, $media_extensions, true ) ) {
					continue;
				}

				++$scanned;

				$rel_path = str_replace( $basedir . '/', '', $file->getPathname() );

				// Check if this file is tracked in the database.
				if ( ! isset( $db_main_set[ $rel_path ] ) && ! isset( $db_thumb_set[ $rel_path ] ) ) {
					++$orphan_count;
					$orphan_size += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			$this->add_issue(
				'info',
				'orphan_scan_error',
				__( 'Could not fully scan for orphaned media (permission issue).', 'wp-site-doctor' )
			);
			return;
		}

		// Store the orphan data in a transient for the repair action to use.
		set_transient( 'wpsd_orphan_stats', array(
			'count'   => $orphan_count,
			'size'    => $orphan_size,
			'scanned' => $scanned,
			'db_main' => count( $db_main_files ),
			'db_thumbs' => count( $db_thumb_set ),
		), HOUR_IN_SECONDS );

		if ( $orphan_count > 0 ) {
			$severity = 'critical';
			if ( $orphan_count < 100 ) {
				$severity = 'warning';
			}
			if ( $orphan_count < 10 ) {
				$severity = 'info';
			}

			$this->add_issue(
				$severity,
				'orphaned_media_files',
				sprintf(
					/* translators: 1: orphan count, 2: orphan size, 3: scanned count */
					__( '%1$s orphaned media files found (%2$s) — these exist on disk but have no database record.', 'wp-site-doctor' ),
					number_format_i18n( $orphan_count ),
					$this->format_bytes( $orphan_size )
				),
				__( 'These are typically left behind when posts are deleted but their media files are not removed from disk. Deleting them will recover disk space and reduce inode count.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_orphaned_media',
					'label'        => sprintf(
						/* translators: 1: count, 2: size */
						__( 'Delete %1$s orphaned files (%2$s)', 'wp-site-doctor' ),
						number_format_i18n( $orphan_count ),
						$this->format_bytes( $orphan_size )
					),
					'description'  => __( 'Permanently deletes media files from disk that have no matching attachment in the database. These files are not used anywhere on the site.', 'wp-site-doctor' ),
					'irreversible' => true,
				)
			);
		} else {
			$this->add_pass(
				'no_orphaned_media',
				sprintf(
					/* translators: %s: scanned count */
					__( 'No orphaned media files detected (%s files scanned).', 'wp-site-doctor' ),
					number_format_i18n( $scanned )
				)
			);
		}

		// Report DB vs disk summary.
		$this->add_issue(
			'info',
			'media_summary',
			sprintf(
				/* translators: 1: disk files, 2: DB main files, 3: DB thumbnails */
				__( 'Media inventory: %1$s files on disk, %2$s main attachments in DB, %3$s thumbnails tracked.', 'wp-site-doctor' ),
				number_format_i18n( $scanned ),
				number_format_i18n( count( $db_main_files ) ),
				number_format_i18n( count( $db_thumb_set ) )
			)
		);
	}

	/**
	 * Check database table sizes for bloat.
	 */
	private function check_database_size() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		$total_size = 0;
		$large      = array();

		foreach ( $rows as $row ) {
			$size        = ( isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0 )
						 + ( isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0 );
			$total_size += $size;

			if ( $size > 50 * 1024 * 1024 ) { // Tables > 50MB.
				$large[] = $row['Name'] . ' (' . $this->format_bytes( $size ) . ')';
			}
		}

		$db_human = $this->format_bytes( $total_size );

		if ( $total_size > 1024 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'large_database',
				sprintf(
					/* translators: %s: database size */
					__( 'Database size is %s. This is large and may slow backups and queries.', 'wp-site-doctor' ),
					$db_human
				),
				! empty( $large )
					? sprintf(
						/* translators: %s: list of large tables */
						__( 'Largest tables: %s', 'wp-site-doctor' ),
						implode( ', ', array_slice( $large, 0, 5 ) )
					)
					: ''
			);
		} else {
			$this->add_issue(
				'info',
				'database_size',
				sprintf(
					/* translators: %s: database size */
					__( 'Database size: %s', 'wp-site-doctor' ),
					$db_human
				)
			);
		}
	}

	/**
	 * Check for wp_postmeta bloat.
	 */
	private function check_postmeta_bloat() {
		global $wpdb;

		$postmeta_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT (Data_length + Index_length) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$wpdb->postmeta
			)
		);

		if ( $postmeta_size > 500 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'postmeta_bloat',
				sprintf(
					/* translators: %s: table size */
					__( 'wp_postmeta table is %s — this is unusually large and impacts all queries.', 'wp-site-doctor' ),
					$this->format_bytes( $postmeta_size )
				),
				__( 'Common causes: _wp_attachment_metadata for deleted attachments, plugin data from uninstalled plugins, or excessive custom fields. Run the orphaned postmeta cleanup.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'delete_orphaned_postmeta',
					'label'       => __( 'Delete orphaned postmeta', 'wp-site-doctor' ),
					'description' => __( 'Removes postmeta entries that reference non-existent posts.', 'wp-site-doctor' ),
					'irreversible' => true,
				)
			);
		}
	}

	/**
	 * Check for Action Scheduler table bloat.
	 */
	private function check_actionscheduler_bloat() {
		global $wpdb;

		$as_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(Data_length + Index_length) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
				DB_NAME,
				$wpdb->prefix . 'actionscheduler%'
			)
		);

		if ( $as_size > 50 * 1024 * 1024 ) {
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = %s",
					'complete'
				)
			);

			$this->add_issue(
				'warning',
				'actionscheduler_bloat',
				sprintf(
					/* translators: 1: table size, 2: completed action count */
					__( 'Action Scheduler tables: %1$s (%2$s completed actions). Old completed actions can be purged.', 'wp-site-doctor' ),
					$this->format_bytes( $as_size ),
					number_format_i18n( $completed )
				),
				__( 'Action Scheduler automatically purges old actions, but the process may be behind. WooCommerce and other plugins use this.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'purge_actionscheduler',
					'label'        => __( 'Purge completed Action Scheduler entries', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: count */
						__( 'Deletes %s completed actions from the scheduler.', 'wp-site-doctor' ),
						number_format_i18n( $completed )
					),
					'irreversible' => true,
				)
			);
		}
	}

	/**
	 * Check for trash and revision bloat.
	 */
	private function check_trash_and_revisions() {
		global $wpdb;

		$revisions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);

		$trash = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
				'trash'
			)
		);

		if ( $revisions > 1000 ) {
			$this->add_issue(
				'warning',
				'revision_bloat',
				sprintf(
					/* translators: %s: count */
					__( '%s post revisions in database.', 'wp-site-doctor' ),
					number_format_i18n( $revisions )
				),
				__( 'Each revision stores a full copy of the post content. Limit to 5 per post via wp-config.php: define(\'WP_POST_REVISIONS\', 5);', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_post_revisions',
					'label'        => __( 'Delete post revisions (keep last 5)', 'wp-site-doctor' ),
					'description'  => __( 'Removes revisions beyond the 5 most recent per post.', 'wp-site-doctor' ),
					'irreversible' => true,
				)
			);
		}

		if ( $trash > 100 ) {
			$this->add_issue(
				'info',
				'trash_bloat',
				sprintf(
					/* translators: %s: count */
					__( '%s trashed posts in database.', 'wp-site-doctor' ),
					number_format_i18n( $trash )
				),
				__( 'Empty the trash to free database space.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'empty_trash',
					'label'        => __( 'Empty trash', 'wp-site-doctor' ),
					'description'  => __( 'Permanently deletes trashed posts and comments.', 'wp-site-doctor' ),
					'irreversible' => true,
				)
			);
		}
	}

	/**
	 * Check for LiteSpeed cache files.
	 */
	private function check_litespeed_cache() {
		$ls_dir = WP_CONTENT_DIR . '/litespeed';

		if ( ! is_dir( $ls_dir ) ) {
			return;
		}

		$ls_size = 0;
		$ls_count = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $ls_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$ls_size += $file->getSize();
					++$ls_count;
				}
			}
		} catch ( \Exception $e ) {
			return;
		}

		if ( $ls_size > 100 * 1024 * 1024 ) {
			$this->add_issue(
				'info',
				'litespeed_cache_size',
				sprintf(
					/* translators: 1: size, 2: file count */
					__( 'LiteSpeed cache: %1$s (%2$s files). Purge via LiteSpeed Cache plugin if excessive.', 'wp-site-doctor' ),
					$this->format_bytes( $ls_size ),
					number_format_i18n( $ls_count )
				)
			);
		}
	}

	/**
	 * Check for large log files.
	 */
	private function check_log_files() {
		$log_files = array(
			WP_CONTENT_DIR . '/debug.log',
			ABSPATH . 'error_log',
		);

		foreach ( $log_files as $log ) {
			if ( file_exists( $log ) ) {
				$size = filesize( $log );
				if ( $size > 10 * 1024 * 1024 ) {
					$this->add_issue(
						'warning',
						'large_log_' . sanitize_key( basename( $log ) ),
						sprintf(
							/* translators: 1: filename, 2: size */
							__( 'Large log file: %1$s (%2$s). Consider truncating or deleting.', 'wp-site-doctor' ),
							basename( $log ),
							$this->format_bytes( $size )
						)
					);
				}
			}
		}
	}
}
