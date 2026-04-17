<?php
/**
 * Cron Scanner for WP Site Doctor.
 *
 * Checks for orphaned, duplicate, and missed cron events.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Cron_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'cron';
	}

	public function get_label(): string {
		return __( 'Cron Jobs', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'cron';
	}

	public function run(): array {
		$this->reset();

		$cron_array = _get_cron_array();

		if ( empty( $cron_array ) ) {
			$this->add_issue(
				'warning',
				'no_cron_events',
				__( 'No WP-Cron events found. This may indicate a problem.', 'wp-site-doctor' )
			);
			return $this->build_result();
		}

		$this->check_total_events( $cron_array );
		$this->check_orphaned_events( $cron_array );
		$this->check_duplicate_events( $cron_array );
		$this->check_missed_events( $cron_array );
		$this->check_cron_config();

		return $this->build_result();
	}

	/**
	 * Count total cron events.
	 *
	 * @param array $cron_array WP-Cron array.
	 */
	private function check_total_events( $cron_array ) {
		$total = 0;

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				$total += count( $events );
			}
		}

		if ( $total > 50 ) {
			$this->add_issue(
				'warning',
				'too_many_cron_events',
				sprintf(
					/* translators: %d: event count */
					__( '%d cron events scheduled. This is unusually high and may slow admin loads.', 'wp-site-doctor' ),
					$total
				),
				__( 'Review and clean up orphaned cron events from deactivated plugins.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass(
				'cron_count_ok',
				sprintf(
					/* translators: %d: event count */
					__( '%d cron events scheduled.', 'wp-site-doctor' ),
					$total
				)
			);
		}
	}

	/**
	 * Detect orphaned cron events (hooks with no registered callback).
	 *
	 * @param array $cron_array WP-Cron array.
	 */
	private function check_orphaned_events( $cron_array ) {
		global $wp_filter;

		// Core hooks that are always valid.
		$core_hooks = array(
			'wp_version_check',
			'wp_update_plugins',
			'wp_update_themes',
			'wp_scheduled_delete',
			'wp_scheduled_auto_draft_delete',
			'delete_expired_transients',
			'wp_privacy_delete_old_export_files',
			'wp_site_health_scheduled_check',
			'recovery_mode_clean_expired_keys',
		);

		$orphaned = array();

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( in_array( $hook, $core_hooks, true ) ) {
					continue;
				}

				// Check if any action is registered for this hook.
				if ( ! has_action( $hook ) && ! isset( $wp_filter[ $hook ] ) ) {
					$orphaned[] = $hook;
				}
			}
		}

		$orphaned = array_unique( $orphaned );

		if ( ! empty( $orphaned ) ) {
			$this->add_issue(
				'warning',
				'orphaned_cron_events',
				sprintf(
					/* translators: 1: count, 2: hook list */
					__( '%1$d orphaned cron hook(s) found with no registered callback: %2$s', 'wp-site-doctor' ),
					count( $orphaned ),
					implode( ', ', array_slice( $orphaned, 0, 10 ) ) . ( count( $orphaned ) > 10 ? '...' : '' )
				),
				__( 'These are likely from deactivated plugins and can be safely deleted.', 'wp-site-doctor' ),
				array(
					'action_id'    => 'delete_orphaned_cron',
					'label'        => __( 'Delete orphaned cron events', 'wp-site-doctor' ),
					'description'  => sprintf(
						/* translators: %d: count */
						__( 'Removes %d orphaned cron hook(s).', 'wp-site-doctor' ),
						count( $orphaned )
					),
					'irreversible' => true,
				)
			);
		} else {
			$this->add_pass( 'no_orphaned_cron', __( 'No orphaned cron events found.', 'wp-site-doctor' ) );
		}
	}

	/**
	 * Detect duplicate cron events (same hook scheduled multiple times).
	 *
	 * @param array $cron_array WP-Cron array.
	 */
	private function check_duplicate_events( $cron_array ) {
		$hook_counts = array();

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! isset( $hook_counts[ $hook ] ) ) {
					$hook_counts[ $hook ] = 0;
				}
				$hook_counts[ $hook ] += count( $events );
			}
		}

		$duplicates = array();
		foreach ( $hook_counts as $hook => $count ) {
			if ( $count > 3 ) {
				$duplicates[ $hook ] = $count;
			}
		}

		if ( ! empty( $duplicates ) ) {
			$list = array();
			foreach ( $duplicates as $hook => $count ) {
				$list[] = $hook . ' (' . $count . 'x)';
			}

			$this->add_issue(
				'warning',
				'duplicate_cron_events',
				sprintf(
					/* translators: %s: list of hooks with counts */
					__( 'Duplicate cron events detected: %s', 'wp-site-doctor' ),
					implode( ', ', $list )
				),
				__( 'This may indicate a plugin bug. Try deactivating and reactivating the responsible plugin.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Detect missed cron events (past due by > 1 hour).
	 *
	 * @param array $cron_array WP-Cron array.
	 */
	private function check_missed_events( $cron_array ) {
		$now       = time();
		$threshold = $now - HOUR_IN_SECONDS;
		$missed    = array();

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( (int) $timestamp > $threshold || (int) $timestamp > $now ) {
				continue;
			}

			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				$hours_overdue = round( ( $now - (int) $timestamp ) / HOUR_IN_SECONDS, 1 );
				$missed[]      = $hook . ' (' . $hours_overdue . 'h overdue)';
			}
		}

		if ( ! empty( $missed ) ) {
			$this->add_issue(
				'warning',
				'missed_cron_events',
				sprintf(
					/* translators: 1: count, 2: list */
					__( '%1$d missed cron event(s): %2$s', 'wp-site-doctor' ),
					count( $missed ),
					implode( ', ', array_slice( $missed, 0, 5 ) ) . ( count( $missed ) > 5 ? '...' : '' )
				),
				__( 'WP-Cron depends on site visits to trigger. Consider setting up a system cron job for reliability.', 'wp-site-doctor' )
			);
		}
	}

	/**
	 * Check cron-related configuration.
	 */
	private function check_cron_config() {
		if ( $this->is_constant_true( 'DISABLE_WP_CRON' ) ) {
			$this->add_issue(
				'info',
				'wp_cron_disabled',
				__( 'WP-Cron is disabled. Ensure a system cron job is configured.', 'wp-site-doctor' ),
				__( 'Add a crontab entry like: */5 * * * * wget -q -O - yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1', 'wp-site-doctor' )
			);
		}

		if ( $this->is_constant_true( 'ALTERNATE_WP_CRON' ) ) {
			$this->add_issue(
				'info',
				'alternate_cron_active',
				__( 'ALTERNATE_WP_CRON is active. This is a fallback method and may be less reliable.', 'wp-site-doctor' )
			);
		}
	}
}
