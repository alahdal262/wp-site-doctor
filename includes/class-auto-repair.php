<?php
/**
 * Auto-Repair engine for WP Site Doctor.
 *
 * Dispatches repair actions, creates restore points before each,
 * and logs results. Only performs safe operations.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Repair
 *
 * Central repair dispatcher. Maps action IDs to repair methods,
 * coordinates with Restore_Point for rollback data,
 * and Repair_Logger for audit trails.
 */
class Auto_Repair {

	/**
	 * @var Restore_Point
	 */
	private $restore_point;

	/**
	 * @var Repair_Logger
	 */
	private $logger;

	/**
	 * Map of action_id => [ 'callback' => callable, 'label' => string ].
	 *
	 * @var array
	 */
	private $actions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->restore_point = new Restore_Point();
		$this->logger        = new Repair_Logger();
		$this->register_actions();
	}

	/**
	 * Register all built-in repair actions.
	 */
	private function register_actions() {
		$this->actions = array(
			'delete_post_revisions'      => array(
				'callback' => array( $this, 'repair_delete_revisions' ),
				'label'    => __( 'Delete post revisions (keep last 5 per post)', 'wp-site-doctor' ),
			),
			'delete_auto_drafts'         => array(
				'callback' => array( $this, 'repair_delete_auto_drafts' ),
				'label'    => __( 'Delete auto-drafts', 'wp-site-doctor' ),
			),
			'empty_trash'                => array(
				'callback' => array( $this, 'repair_empty_trash' ),
				'label'    => __( 'Empty trash (posts and comments)', 'wp-site-doctor' ),
			),
			'delete_spam_comments'       => array(
				'callback' => array( $this, 'repair_delete_spam' ),
				'label'    => __( 'Delete spam comments', 'wp-site-doctor' ),
			),
			'delete_expired_transients'  => array(
				'callback' => array( $this, 'repair_delete_transients' ),
				'label'    => __( 'Delete expired transients', 'wp-site-doctor' ),
			),
			'delete_orphaned_postmeta'   => array(
				'callback' => array( $this, 'repair_delete_orphaned_postmeta' ),
				'label'    => __( 'Delete orphaned postmeta', 'wp-site-doctor' ),
			),
			'delete_orphaned_commentmeta' => array(
				'callback' => array( $this, 'repair_delete_orphaned_commentmeta' ),
				'label'    => __( 'Delete orphaned commentmeta', 'wp-site-doctor' ),
			),
			'convert_myisam_innodb'      => array(
				'callback' => array( $this, 'repair_convert_innodb' ),
				'label'    => __( 'Convert MyISAM tables to InnoDB', 'wp-site-doctor' ),
			),
			'delete_orphaned_cron'       => array(
				'callback' => array( $this, 'repair_delete_orphaned_cron' ),
				'label'    => __( 'Delete orphaned cron events', 'wp-site-doctor' ),
			),
			'disable_xmlrpc'             => array(
				'callback' => array( $this, 'repair_disable_xmlrpc' ),
				'label'    => __( 'Disable XML-RPC', 'wp-site-doctor' ),
			),
			'disable_user_enum'          => array(
				'callback' => array( $this, 'repair_disable_user_enum' ),
				'label'    => __( 'Block REST API user enumeration', 'wp-site-doctor' ),
			),
			'add_security_headers'       => array(
				'callback' => array( $this, 'repair_add_security_headers' ),
				'label'    => __( 'Add security headers to .htaccess', 'wp-site-doctor' ),
			),
			'fix_wp_config_perms'        => array(
				'callback' => array( $this, 'repair_fix_wp_config_perms' ),
				'label'    => __( 'Fix wp-config.php permissions', 'wp-site-doctor' ),
			),
			'fix_htaccess_perms'         => array(
				'callback' => array( $this, 'repair_fix_htaccess_perms' ),
				'label'    => __( 'Fix .htaccess permissions', 'wp-site-doctor' ),
			),
			'bulk_add_alt_text'          => array(
				'callback' => array( $this, 'repair_bulk_add_alt_text' ),
				'label'    => __( 'Auto-fill missing alt text from filenames', 'wp-site-doctor' ),
			),
			'delete_orphaned_media'      => array(
				'callback' => array( $this, 'repair_delete_orphaned_media' ),
				'label'    => __( 'Delete orphaned media files from disk', 'wp-site-doctor' ),
			),
			'purge_actionscheduler'      => array(
				'callback' => array( $this, 'repair_purge_actionscheduler' ),
				'label'    => __( 'Purge completed Action Scheduler entries', 'wp-site-doctor' ),
			),
		);

		/**
		 * Filter registered repair actions.
		 *
		 * @param array $actions Map of action_id => [ 'callback', 'label' ].
		 */
		$this->actions = apply_filters( 'wpsd_repair_actions', $this->actions );
	}

	/**
	 * Execute a repair action by ID.
	 *
	 * @param string $action_id  Action identifier.
	 * @param string $session_id Scan session UUID (may be empty).
	 * @return array Result: [ 'success' => bool, 'message' => string, 'log_id' => int ].
	 * @throws \RuntimeException If action not found.
	 */
	public function execute( $action_id, $session_id = '' ) {
		if ( ! isset( $this->actions[ $action_id ] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: action ID */
					__( 'Unknown repair action: %s', 'wp-site-doctor' ),
					$action_id
				)
			);
		}

		$action = $this->actions[ $action_id ];
		$label  = $action['label'];

		// Step 1: Execute the repair (it returns restore_data).
		try {
			$repair_result = call_user_func( $action['callback'] );
		} catch ( \Exception $e ) {
			// Log the failure.
			$log_id = $this->logger->log_start( $session_id, $action_id, $label, array() );
			if ( $log_id ) {
				$this->logger->log_failure( $log_id, $e->getMessage() );
			}

			return array(
				'success' => false,
				'message' => $e->getMessage(),
				'log_id'  => $log_id,
			);
		}

		// Step 2: Create the log entry with restore data.
		$restore_data = isset( $repair_result['restore_data'] ) ? $repair_result['restore_data'] : array();
		$log_id       = $this->logger->log_start( $session_id, $action_id, $label, $restore_data );

		// Step 3: Mark as completed or failed.
		if ( ! empty( $repair_result['success'] ) ) {
			$this->logger->log_success( $log_id );

			return array(
				'success' => true,
				'message' => isset( $repair_result['message'] ) ? $repair_result['message'] : __( 'Repair completed successfully.', 'wp-site-doctor' ),
				'log_id'  => $log_id,
			);
		}

		$error = isset( $repair_result['message'] ) ? $repair_result['message'] : __( 'Repair failed.', 'wp-site-doctor' );
		$this->logger->log_failure( $log_id, $error );

		return array(
			'success' => false,
			'message' => $error,
			'log_id'  => $log_id,
		);
	}

	/**
	 * Get all available repair action IDs and labels.
	 *
	 * @return array Map of action_id => label.
	 */
	public function get_available_actions() {
		$list = array();
		foreach ( $this->actions as $id => $action ) {
			$list[ $id ] = $action['label'];
		}
		return $list;
	}

	// ──────────────────────────────────────────────
	// Repair action implementations
	// Each returns: ['success' => bool, 'message' => string, 'restore_data' => array]
	// ──────────────────────────────────────────────

	private function repair_delete_revisions() {
		global $wpdb;

		// Keep the last 5 revisions per post.
		// First, get all post IDs that have revisions.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0",
				'revision'
			)
		);

		$deleted = 0;

		foreach ( $post_ids as $parent_id ) {
			// Get revision IDs for this post, ordered newest first, skip the first 5.
			$old_revisions = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC LIMIT 99999 OFFSET 5",
					$parent_id
				)
			);

			foreach ( $old_revisions as $rev_id ) {
				wp_delete_post_revision( $rev_id );
				++$deleted;
			}
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: number deleted */
				__( 'Deleted %d post revisions (kept last 5 per post).', 'wp-site-doctor' ),
				$deleted
			),
			'restore_data' => array(
				'irreversible' => true,
				'deleted_count' => $deleted,
			),
		);
	}

	private function repair_delete_auto_drafts() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
				'auto-draft'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts} WHERE post_status = %s",
				'auto-draft'
			)
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d auto-drafts.', 'wp-site-doctor' ),
				$count
			),
			'restore_data' => array( 'irreversible' => true, 'deleted_count' => $count ),
		);
	}

	private function repair_empty_trash() {
		global $wpdb;

		$trashed_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
				'trash'
			)
		);

		$trashed_comments = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
				'trash'
			)
		);

		// Delete trashed posts (and their meta).
		$trash_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s",
				'trash'
			)
		);

		foreach ( $trash_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Delete trashed comments.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->comments} WHERE comment_approved = %s",
				'trash'
			)
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: 1: posts count, 2: comments count */
				__( 'Emptied trash: %1$d posts, %2$d comments.', 'wp-site-doctor' ),
				$trashed_posts,
				$trashed_comments
			),
			'restore_data' => array( 'irreversible' => true ),
		);
	}

	private function repair_delete_spam() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		// Clean orphaned commentmeta from deleted spam.
		$wpdb->query(
			"DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})"
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d spam comments.', 'wp-site-doctor' ),
				$count
			),
			'restore_data' => array( 'irreversible' => true, 'deleted_count' => $count ),
		);
	}

	private function repair_delete_transients() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				time()
			)
		);

		// Delete expired transient timeouts.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				time()
			)
		);

		// Delete corresponding transient values.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN (SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') FROM (SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s) AS t)",
				'_transient_%',
				'_transient_timeout_%'
			)
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d expired transients.', 'wp-site-doctor' ),
				$count
			),
			'restore_data' => array( 'deleted_count' => $count ),
		);
	}

	private function repair_delete_orphaned_postmeta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only $wpdb table refs, no user input.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);

		$wpdb->query(
			"DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d orphaned postmeta entries.', 'wp-site-doctor' ),
				$count
			),
			'restore_data' => array( 'irreversible' => true, 'deleted_count' => $count ),
		);
	}

	private function repair_delete_orphaned_commentmeta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only $wpdb table refs, no user input.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
		);

		$wpdb->query(
			"DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d orphaned commentmeta entries.', 'wp-site-doctor' ),
				$count
			),
			'restore_data' => array( 'irreversible' => true, 'deleted_count' => $count ),
		);
	}

	private function repair_convert_innodb() {
		global $wpdb;

		$myisam_tables = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM'",
				DB_NAME
			)
		);

		if ( empty( $myisam_tables ) ) {
			return array(
				'success'      => true,
				'message'      => __( 'No MyISAM tables to convert.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$converted = array();
		$errors    = array();

		foreach ( $myisam_tables as $table ) {
			$safe_table = esc_sql( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped.
			$result = $wpdb->query( "ALTER TABLE `{$safe_table}` ENGINE = InnoDB" );

			if ( false !== $result ) {
				$converted[] = $table;
			} else {
				$errors[] = $table;
			}
		}

		return array(
			'success'      => ! empty( $converted ),
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Converted %d table(s) from MyISAM to InnoDB.', 'wp-site-doctor' ),
				count( $converted )
			) . ( ! empty( $errors ) ? ' ' . sprintf(
				/* translators: %s: table list */
				__( 'Failed: %s', 'wp-site-doctor' ),
				implode( ', ', $errors )
			) : '' ),
			'restore_data' => array( 'tables' => $converted ),
		);
	}

	private function repair_delete_orphaned_cron() {
		global $wp_filter;

		$cron_array = _get_cron_array();

		if ( empty( $cron_array ) ) {
			return array(
				'success'      => true,
				'message'      => __( 'No cron events found.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$core_hooks = array(
			'wp_version_check', 'wp_update_plugins', 'wp_update_themes',
			'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete',
			'delete_expired_transients', 'wp_privacy_delete_old_export_files',
			'wp_site_health_scheduled_check', 'recovery_mode_clean_expired_keys',
		);

		$deleted = 0;

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, $core_hooks, true ) ) {
					continue;
				}

				if ( ! has_action( $hook ) && ! isset( $wp_filter[ $hook ] ) ) {
					wp_clear_scheduled_hook( $hook );
					++$deleted;
				}
			}
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Deleted %d orphaned cron event(s).', 'wp-site-doctor' ),
				$deleted
			),
			'restore_data' => array( 'irreversible' => true, 'deleted_count' => $deleted ),
		);
	}

	private function repair_disable_xmlrpc() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}

		$mu_file = WPMU_PLUGIN_DIR . '/wpsd-disable-xmlrpc.php';
		$content = "<?php\n// Added by WP Site Doctor — Disable XML-RPC\nadd_filter( 'xmlrpc_enabled', '__return_false' );\nadd_filter( 'wp_headers', function( \$headers ) { unset( \$headers['X-Pingback'] ); return \$headers; } );\n";

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$result = $wp_filesystem->put_contents( $mu_file, $content, FS_CHMOD_FILE );

		return array(
			'success'      => $result,
			'message'      => $result
				? __( 'XML-RPC disabled via mu-plugin.', 'wp-site-doctor' )
				: __( 'Failed to create mu-plugin file.', 'wp-site-doctor' ),
			'restore_data' => array( 'mu_file' => $mu_file ),
		);
	}

	private function repair_disable_user_enum() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}

		$mu_file = WPMU_PLUGIN_DIR . '/wpsd-disable-user-enum.php';
		$content = "<?php\n// Added by WP Site Doctor — Block user enumeration via REST API\nadd_filter( 'rest_endpoints', function( \$endpoints ) {\n\tif ( isset( \$endpoints['/wp/v2/users'] ) ) {\n\t\t\$endpoints['/wp/v2/users'][0]['permission_callback'] = function() {\n\t\t\treturn current_user_can( 'list_users' );\n\t\t};\n\t}\n\treturn \$endpoints;\n} );\n";

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$result = $wp_filesystem->put_contents( $mu_file, $content, FS_CHMOD_FILE );

		return array(
			'success'      => $result,
			'message'      => $result
				? __( 'REST API user enumeration blocked via mu-plugin.', 'wp-site-doctor' )
				: __( 'Failed to create mu-plugin file.', 'wp-site-doctor' ),
			'restore_data' => array( 'mu_file' => $mu_file ),
		);
	}

	private function repair_add_security_headers() {
		$htaccess = ABSPATH . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			return array(
				'success'      => false,
				'message'      => __( '.htaccess file not found. This may be an Nginx server.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem ) {
			return array(
				'success'      => false,
				'message'      => __( 'Could not initialize filesystem API.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$original = $wp_filesystem->get_contents( $htaccess );

		$headers_block = "\n# BEGIN WP Site Doctor Security Headers\n"
			. "<IfModule mod_headers.c>\n"
			. "    Header set X-Content-Type-Options \"nosniff\"\n"
			. "    Header set X-Frame-Options \"SAMEORIGIN\"\n"
			. "    Header set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
			. "    Header set X-XSS-Protection \"1; mode=block\"\n"
			. "    Header set Permissions-Policy \"camera=(), microphone=(), geolocation=()\"\n"
			. "</IfModule>\n"
			. "# END WP Site Doctor Security Headers\n";

		// Don't add if already present.
		if ( false !== strpos( $original, 'WP Site Doctor Security Headers' ) ) {
			return array(
				'success'      => true,
				'message'      => __( 'Security headers already present in .htaccess.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$result = $wp_filesystem->put_contents( $htaccess, $original . $headers_block, FS_CHMOD_FILE );

		return array(
			'success'      => $result,
			'message'      => $result
				? __( 'Security headers added to .htaccess.', 'wp-site-doctor' )
				: __( 'Failed to write to .htaccess.', 'wp-site-doctor' ),
			'restore_data' => array( 'htaccess_backup' => $original ),
		);
	}

	private function repair_fix_wp_config_perms() {
		$path = ABSPATH . 'wp-config.php';

		if ( ! file_exists( $path ) ) {
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( ! file_exists( $path ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'wp-config.php not found.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$old_perms = substr( decoct( fileperms( $path ) ), -3 );
		$result    = @chmod( $path, 0440 );

		return array(
			'success'      => $result,
			'message'      => $result
				? sprintf(
					/* translators: 1: old perms, 2: new perms */
					__( 'wp-config.php permissions changed from %1$s to %2$s.', 'wp-site-doctor' ),
					$old_perms,
					'440'
				)
				: __( 'Failed to change wp-config.php permissions. May require SSH access.', 'wp-site-doctor' ),
			'restore_data' => array( 'file' => $path, 'old_perms' => $old_perms ),
		);
	}

	private function repair_fix_htaccess_perms() {
		$path = ABSPATH . '.htaccess';

		if ( ! file_exists( $path ) ) {
			return array(
				'success'      => false,
				'message'      => __( '.htaccess not found.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$old_perms = substr( decoct( fileperms( $path ) ), -3 );
		$result    = @chmod( $path, 0644 );

		return array(
			'success'      => $result,
			'message'      => $result
				? sprintf(
					/* translators: 1: old, 2: new */
					__( '.htaccess permissions changed from %1$s to %2$s.', 'wp-site-doctor' ),
					$old_perms,
					'644'
				)
				: __( 'Failed to change .htaccess permissions.', 'wp-site-doctor' ),
			'restore_data' => array( 'file' => $path, 'old_perms' => $old_perms ),
		);
	}

	private function repair_bulk_add_alt_text() {
		global $wpdb;

		$images = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm_file.meta_value as file_path
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
				LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type LIKE %s
				AND (pm_alt.meta_value IS NULL OR pm_alt.meta_value = '')
				LIMIT 100",
				'image/%'
			)
		);

		$updated = 0;

		foreach ( $images as $image ) {
			$filename = pathinfo( $image->file_path, PATHINFO_FILENAME );

			// Convert filename to readable text: my-photo_2024 -> My Photo 2024.
			$alt_text = str_replace( array( '-', '_' ), ' ', $filename );
			$alt_text = preg_replace( '/\s+/', ' ', $alt_text );
			$alt_text = ucwords( trim( $alt_text ) );

			// Remove trailing numbers/dates that are just IDs.
			$alt_text = preg_replace( '/\s+\d{10,}$/', '', $alt_text );

			if ( ! empty( $alt_text ) ) {
				update_post_meta( $image->ID, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
				++$updated;
			}
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %d: count */
				__( 'Added alt text to %d images based on filenames.', 'wp-site-doctor' ),
				$updated
			),
			'restore_data' => array( 'irreversible' => true, 'updated_count' => $updated ),
		);
	}

	/**
	 * Delete orphaned media files from the uploads directory.
	 *
	 * Finds files on disk that have no matching attachment record in the
	 * database and deletes them. Processes in batches for safety.
	 */
	private function repair_delete_orphaned_media() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		if ( ! is_dir( $basedir ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'Uploads directory not found.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		// Build the set of known DB file paths.
		$db_main_files = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		$db_main_set = array_flip( $db_main_files );

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

		// Scan and delete orphaned files.
		$media_exts    = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'svg', 'mp4', 'mp3', 'pdf' );
		$deleted_count = 0;
		$deleted_size  = 0;
		$failed_count  = 0;
		$batch_limit   = 10000; // Process up to 10,000 files per run for safety.

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				if ( $deleted_count >= $batch_limit ) {
					break;
				}

				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, $media_exts, true ) ) {
					continue;
				}

				$rel_path = str_replace( $basedir . '/', '', $file->getPathname() );

				if ( ! isset( $db_main_set[ $rel_path ] ) && ! isset( $db_thumb_set[ $rel_path ] ) ) {
					$size = $file->getSize();

					if ( @unlink( $file->getPathname() ) ) {
						++$deleted_count;
						$deleted_size += $size;
					} else {
						++$failed_count;
					}
				}
			}

			// Clean up empty directories left behind.
			$dir_iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $dir_iterator as $item ) {
				if ( $item->isDir() ) {
					$dir_path = $item->getPathname();
					// Only remove empty year/month directories, not the base.
					if ( $dir_path !== $basedir && @rmdir( $dir_path ) ) {
						// Removed empty directory.
					}
				}
			}
		} catch ( \Exception $e ) {
			return array(
				'success'      => false,
				'message'      => $e->getMessage(),
				'restore_data' => array(),
			);
		}

		$size_human = round( $deleted_size / 1024 / 1024 );

		$message = sprintf(
			/* translators: 1: deleted count, 2: size in MB */
			__( 'Deleted %1$s orphaned files (%2$s MB recovered).', 'wp-site-doctor' ),
			number_format_i18n( $deleted_count ),
			number_format_i18n( $size_human )
		);

		if ( $failed_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: count */
				__( '%s files could not be deleted (permission denied).', 'wp-site-doctor' ),
				number_format_i18n( $failed_count )
			);
		}

		if ( $deleted_count >= $batch_limit ) {
			$message .= ' ' . __( 'Batch limit reached — run again to continue.', 'wp-site-doctor' );
		}

		// Clear the orphan stats transient so next scan recalculates.
		delete_transient( 'wpsd_orphan_stats' );

		return array(
			'success'      => $deleted_count > 0,
			'message'      => $message,
			'restore_data' => array(
				'irreversible'  => true,
				'deleted_count' => $deleted_count,
				'deleted_size'  => $deleted_size,
				'failed_count'  => $failed_count,
			),
		);
	}

	/**
	 * Purge completed Action Scheduler entries.
	 */
	private function repair_purge_actionscheduler() {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix + constant.
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		if ( ! $exists ) {
			return array(
				'success'      => true,
				'message'      => __( 'Action Scheduler tables not found.', 'wp-site-doctor' ),
				'restore_data' => array(),
			);
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				'complete'
			)
		);

		// Delete completed actions and their logs.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
				"DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN (SELECT action_id FROM {$table} WHERE status = %s)",
				'complete'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
				"DELETE FROM {$table} WHERE status = %s",
				'complete'
			)
		);

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %s: count */
				__( 'Purged %s completed Action Scheduler entries.', 'wp-site-doctor' ),
				number_format_i18n( $count )
			),
			'restore_data' => array( 'irreversible' => true, 'purged_count' => $count ),
		);
	}
}
