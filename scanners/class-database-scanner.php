<?php
/**
 * Database Scanner for WP Site Doctor.
 *
 * Checks database size, revisions, orphaned data, transients,
 * autoload bloat, and table engine types.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Database_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'database';
	}

	public function get_label(): string {
		return __( 'Database', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'database';
	}

	public function run(): array {
		$this->reset();

		$this->check_db_size();
		$this->check_post_revisions();
		$this->check_auto_drafts();
		$this->check_trash();
		$this->check_spam_comments();
		$this->check_orphaned_postmeta();
		$this->check_orphaned_commentmeta();
		$this->check_expired_transients();
		$this->check_table_engines();

		return $this->build_result();
	}

	private function check_db_size() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		$total_size = 0;
		$largest    = array();

		foreach ( $rows as $row ) {
			$size         = ( isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0 )
							+ ( isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0 );
			$total_size  += $size;
			$largest[]    = array(
				'name' => $row['Name'],
				'size' => $size,
			);
		}

		usort( $largest, function ( $a, $b ) { return $b['size'] - $a['size']; } );
		$top_10 = array_slice( $largest, 0, 10 );

		$names = array();
		foreach ( $top_10 as $t ) {
			$names[] = $t['name'] . ' (' . $this->format_bytes( $t['size'] ) . ')';
		}

		$this->add_issue(
			'info',
			'db_total_size',
			sprintf(
				/* translators: %s: total database size */
				__( 'Total database size: %s', 'wp-site-doctor' ),
				$this->format_bytes( $total_size )
			),
			sprintf(
				/* translators: %s: list of largest tables */
				__( 'Largest tables: %s', 'wp-site-doctor' ),
				implode( ', ', $names )
			)
		);
	}

	private function check_post_revisions() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);

		if ( $count > 500 ) {
			$this->add_issue(
				'warning',
				'too_many_revisions',
				sprintf(
					/* translators: %s: number of revisions */
					__( '%s post revisions found. This bloats the database.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'Delete old revisions keeping the last 5 per post. Consider limiting revisions via wp-config.php: define(\'WP_POST_REVISIONS\', 5);', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_post_revisions',
					'label'        => __( 'Delete post revisions (keep last 5)', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: number to delete */
						__( 'Deletes revisions beyond the 5 most recent per post (~%s revisions).', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
					'irreversible' => true,
				)
			);
		} elseif ( $count > 100 ) {
			$this->add_issue(
				'info',
				'many_revisions',
				sprintf(
					/* translators: %s: number of revisions */
					__( '%s post revisions found.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'Consider periodically cleaning up old revisions.', 'wp-site-doctor' )
			);
		}
	}

	private function check_auto_drafts() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
				'auto-draft'
			)
		);

		if ( $count > 10 ) {
			$this->add_issue(
				'info',
				'auto_drafts',
				sprintf(
					/* translators: %s: number of auto-drafts */
					__( '%s auto-drafts found.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'These are unused and can be safely deleted.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_auto_drafts',
					'label'        => __( 'Delete auto-drafts', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: count */
						__( 'Deletes %s auto-draft posts.', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
					'irreversible' => true,
				)
			);
		}
	}

	private function check_trash() {
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

		$total = $trashed_posts + $trashed_comments;

		if ( $total > 50 ) {
			$this->add_issue(
				'info',
				'trash_items',
				sprintf(
					/* translators: 1: trashed posts count, 2: trashed comments count */
					__( 'Trash contains %1$s posts and %2$s comments.', 'wp-site-doctor' ),
					number_format_i18n( $trashed_posts ),
					number_format_i18n( $trashed_comments )
				),
				__( 'Empty the trash to free database space.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'empty_trash',
					'label'        => __( 'Empty trash', 'wp-site-doctor' ),
					'description'  => __( 'Permanently deletes all trashed posts and comments.', 'wp-site-doctor' ),
					'irreversible' => true,
				)
			);
		}
	}

	private function check_spam_comments() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		if ( $count > 100 ) {
			$this->add_issue(
				'warning',
				'spam_comments',
				sprintf(
					/* translators: %s: spam comment count */
					__( '%s spam comments found. These waste database space.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'Delete all spam comments and consider installing an anti-spam plugin like Akismet.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_spam_comments',
					'label'        => __( 'Delete spam comments', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: count */
						__( 'Permanently deletes %s spam comments.', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
					'irreversible' => true,
				)
			);
		} elseif ( $count > 0 ) {
			$this->add_issue(
				'info',
				'some_spam',
				sprintf(
					/* translators: %s: spam count */
					__( '%s spam comments found.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'Clean them periodically from Comments > Spam.', 'wp-site-doctor' )
			);
		}
	}

	private function check_orphaned_postmeta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only $wpdb table refs, no user input.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);

		if ( $count > 100 ) {
			$this->add_issue(
				'warning',
				'orphaned_postmeta',
				sprintf(
					/* translators: %s: orphaned meta count */
					__( '%s orphaned postmeta entries (no matching post).', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'These are left behind by deleted posts and waste database space.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_orphaned_postmeta',
					'label'        => __( 'Delete orphaned postmeta', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: count */
						__( 'Deletes %s orphaned postmeta entries.', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
					'irreversible' => true,
				)
			);
		}
	}

	private function check_orphaned_commentmeta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only $wpdb table refs, no user input.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
		);

		if ( $count > 100 ) {
			$this->add_issue(
				'info',
				'orphaned_commentmeta',
				sprintf(
					/* translators: %s: orphaned meta count */
					__( '%s orphaned commentmeta entries.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				),
				__( 'These can be safely deleted.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_orphaned_commentmeta',
					'label'        => __( 'Delete orphaned commentmeta', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %s: count */
						__( 'Deletes %s orphaned commentmeta entries.', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
					'irreversible' => true,
				)
			);
		}
	}

	private function check_expired_transients() {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				time()
			)
		);

		$size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_%'
			)
		);

		if ( $count > 100 || $size > 5 * 1024 * 1024 ) {
			$this->add_issue(
				'warning',
				'expired_transients',
				sprintf(
					/* translators: 1: transient count, 2: total size */
					__( '%1$s expired transients found (total transient data: %2$s).', 'wp-site-doctor' ),
					number_format_i18n( $count ),
					$this->format_bytes( $size )
				),
				__( 'Delete expired transients to clean up the options table.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'delete_expired_transients',
					'label'       => __( 'Delete expired transients', 'wp-site-doctor' ),
					'description' => sprintf(
						/* translators: %s: count */
						__( 'Deletes %s expired transients.', 'wp-site-doctor' ),
						number_format_i18n( $count )
					),
				)
			);
		} elseif ( $count > 0 ) {
			$this->add_issue(
				'info',
				'some_expired_transients',
				sprintf(
					/* translators: %s: count */
					__( '%s expired transients found.', 'wp-site-doctor' ),
					number_format_i18n( $count )
				)
			);
		}
	}

	private function check_table_engines() {
		global $wpdb;

		$myisam_tables = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND ENGINE = 'MyISAM'",
				DB_NAME
			),
			ARRAY_A
		);

		if ( ! empty( $myisam_tables ) ) {
			$names = wp_list_pluck( $myisam_tables, 'TABLE_NAME' );

			$this->add_issue(
				'info',
				'myisam_tables',
				sprintf(
					/* translators: 1: count, 2: table list */
					__( '%1$d table(s) using MyISAM engine: %2$s', 'wp-site-doctor' ),
					count( $myisam_tables ),
					implode( ', ', array_slice( $names, 0, 5 ) ) . ( count( $names ) > 5 ? '...' : '' )
				),
				__( 'InnoDB is recommended for better crash recovery and row-level locking.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'convert_myisam_innodb',
					'label'       => __( 'Convert MyISAM to InnoDB', 'wp-site-doctor' ),
					'description' => sprintf(
						/* translators: %d: count */
						__( 'Converts %d table(s) from MyISAM to InnoDB engine.', 'wp-site-doctor' ),
						count( $myisam_tables )
					),
				)
			);
		}
	}
}
