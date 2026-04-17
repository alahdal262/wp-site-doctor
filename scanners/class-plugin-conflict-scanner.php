<?php
/**
 * Plugin Conflict Scanner for WP Site Doctor.
 *
 * Detects duplicate plugins in the same functional category,
 * recommends which to keep, and identifies cross-category overlaps.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Plugin_Conflict_Scanner extends Abstract_Scanner {

	/**
	 * Plugin category taxonomy — maps categories to known plugin folder slugs.
	 *
	 * @var array
	 */
	private $categories;

	public function get_id(): string {
		return 'plugin_conflicts';
	}

	public function get_label(): string {
		return __( 'Plugin Conflicts', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'plugin_conflicts';
	}

	/**
	 * Get the plugin category taxonomy.
	 *
	 * @return array Map of category => array of plugin slugs.
	 */
	private function get_categories() {
		if ( null === $this->categories ) {
			$this->categories = array(
				'caching'      => array(
					'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'litespeed-cache',
					'wp-rocket', 'breeze', 'sg-cachepress', 'hummingbird-performance',
					'cache-enabler', 'comet-cache', 'swift-performance-lite',
				),
				'seo'          => array(
					'wordpress-seo', 'all-in-one-seo-pack', 'seo-by-rank-math', 'rank-math-seo',
					'squirrly-seo', 'the-seo-framework', 'smartcrawl-seo', 'slim-seo',
				),
				'security'     => array(
					'wordfence', 'sucuri-scanner', 'better-wp-security', 'ithemes-security-pro',
					'all-in-one-wp-security-and-firewall', 'defender-security', 'wp-cerber',
					'shield-security',
				),
				'image_optim'  => array(
					'imagify', 'ewww-image-optimizer', 'shortpixel-image-optimiser',
					'wp-smushit', 'optimole-wp', 'tiny-compress-images',
				),
				'lazy_load'    => array(
					'rocket-lazy-load', 'a3-lazy-load', 'lazy-load-for-videos',
					'lazy-loader', 'native-lazyload',
				),
				'minify'       => array(
					'autoptimize', 'fast-velocity-minify', 'wp-rocket', 'w3-total-cache',
					'sg-cachepress', 'asset-cleanup-page-speed-booster',
				),
				'backup'       => array(
					'updraftplus', 'backwpup', 'duplicator', 'blogvault-real-time-backup',
					'jebackup', 'backup-backup', 'backuply',
				),
				'analytics'    => array(
					'google-analytics-for-wordpress', 'google-site-kit', 'analytify',
					'ga-google-analytics', 'wp-statistics', 'independent-analytics',
				),
				'contact_forms' => array(
					'contact-form-7', 'wpforms-lite', 'forminator', 'ninja-forms',
					'formidable', 'happyforms',
				),
				'page_builders' => array(
					'elementor', 'beaver-builder-lite-version', 'js_composer', 'divi-builder',
					'brizy', 'fusion-builder', 'generateblocks',
				),
				'smtp'         => array(
					'wp-mail-smtp', 'post-smtp-mailer', 'fluent-smtp', 'easy-wp-smtp',
					'smtp-mailer',
				),
			);

			/**
			 * Filter the plugin category taxonomy.
			 *
			 * @param array $categories Map of category => plugin slugs.
			 */
			$this->categories = apply_filters( 'wpsd_plugin_categories', $this->categories );
		}

		return $this->categories;
	}

	public function run(): array {
		$this->reset();

		$categories     = $this->get_categories();
		$active_plugins = $this->get_active_plugins();

		// Map active plugin folder slugs.
		$active_folders = array();
		foreach ( $active_plugins as $plugin_path ) {
			$folder = explode( '/', $plugin_path )[0];
			$active_folders[ $folder ] = $plugin_path;
		}

		$conflicts_found = false;

		// Check each category for duplicates.
		foreach ( $categories as $category => $slugs ) {
			$active_in_category = array();

			foreach ( $slugs as $slug ) {
				if ( isset( $active_folders[ $slug ] ) ) {
					$active_in_category[ $slug ] = $this->get_plugin_name( $active_folders[ $slug ] );
				}
			}

			if ( count( $active_in_category ) > 1 ) {
				$conflicts_found = true;
				$this->report_conflict( $category, $active_in_category );
			}
		}

		// Check cross-category feature overlaps.
		$this->check_cross_category_overlaps( $active_folders );

		if ( ! $conflicts_found ) {
			$this->add_pass(
				'no_conflicts',
				__( 'No plugin conflicts detected. Each category has at most one active plugin.', 'wp-site-doctor' )
			);
		}

		return $this->build_result();
	}

	/**
	 * Report a conflict in a specific category.
	 *
	 * @param string $category          Category name.
	 * @param array  $active_in_category Map of slug => display name.
	 */
	private function report_conflict( $category, $active_in_category ) {
		$category_label = $this->get_category_label( $category );
		$plugin_list    = implode( ', ', $active_in_category );

		// Try to determine the best plugin to keep.
		$recommendation = $this->get_recommendation( $category, $active_in_category );

		$message = sprintf(
			/* translators: 1: category name, 2: list of conflicting plugins */
			__( 'Multiple %1$s plugins active: %2$s', 'wp-site-doctor' ),
			$category_label,
			$plugin_list
		);

		$rec_text = sprintf(
			/* translators: %s: recommended plugin name */
			__( 'Keep only one plugin in this category. %s', 'wp-site-doctor' ),
			$recommendation
		);

		$this->add_issue(
			'critical',
			'conflict_' . $category,
			$message,
			$rec_text
		);
	}

	/**
	 * Check for cross-category feature overlaps.
	 *
	 * For example, WP Rocket does caching + minify + lazy-load.
	 *
	 * @param array $active_folders Map of folder slug => plugin path.
	 */
	private function check_cross_category_overlaps( $active_folders ) {
		// Known multi-feature plugins.
		$multi_feature = array(
			'wp-rocket'     => array( 'caching', 'minify', 'lazy_load' ),
			'litespeed-cache' => array( 'caching', 'minify', 'image_optim' ),
			'sg-cachepress' => array( 'caching', 'minify' ),
			'w3-total-cache' => array( 'caching', 'minify' ),
			'hummingbird-performance' => array( 'caching', 'minify' ),
		);

		foreach ( $multi_feature as $slug => $covers_categories ) {
			if ( ! isset( $active_folders[ $slug ] ) ) {
				continue;
			}

			$name = $this->get_plugin_name( $active_folders[ $slug ] );

			foreach ( $covers_categories as $covered_cat ) {
				// Check if another plugin in that category is also active.
				$categories = $this->get_categories();

				if ( ! isset( $categories[ $covered_cat ] ) ) {
					continue;
				}

				foreach ( $categories[ $covered_cat ] as $cat_slug ) {
					if ( $cat_slug === $slug ) {
						continue; // Same plugin.
					}

					if ( isset( $active_folders[ $cat_slug ] ) ) {
						$other_name     = $this->get_plugin_name( $active_folders[ $cat_slug ] );
						$category_label = $this->get_category_label( $covered_cat );

						$this->add_issue(
							'warning',
							'overlap_' . $slug . '_' . $cat_slug,
							sprintf(
								/* translators: 1: plugin name, 2: feature, 3: other plugin */
								__( '%1$s already includes %2$s features, making %3$s redundant for that purpose.', 'wp-site-doctor' ),
								$name,
								$category_label,
								$other_name
							),
							sprintf(
								/* translators: 1: redundant plugin, 2: feature name, 3: main plugin */
								__( 'Consider deactivating %1$s if you\'re only using it for %2$s, since %3$s handles that.', 'wp-site-doctor' ),
								$other_name,
								$category_label,
								$name
							)
						);
					}
				}
			}
		}
	}

	/**
	 * Get a recommendation for which plugin to keep in a category.
	 *
	 * Uses WP.org API data (active installs, last updated) cached in transients.
	 *
	 * @param string $category Category name.
	 * @param array  $plugins  Map of slug => name.
	 * @return string Recommendation text.
	 */
	private function get_recommendation( $category, $plugins ) {
		$best_slug  = '';
		$best_score = -1;

		foreach ( $plugins as $slug => $name ) {
			$info = $this->get_wporg_plugin_info( $slug );

			if ( ! $info ) {
				continue;
			}

			// Simple heuristic: active_installs * recency factor.
			$installs = isset( $info->active_installs ) ? (int) $info->active_installs : 0;
			$updated  = isset( $info->last_updated ) ? strtotime( $info->last_updated ) : 0;
			$age_days = $updated ? max( 1, ( time() - $updated ) / DAY_IN_SECONDS ) : 365;

			// Score: installs divided by age penalty (older = lower score).
			$recency_factor = min( 1.0, 90 / $age_days ); // Full score if updated in last 90 days.
			$score          = $installs * $recency_factor;

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_slug  = $slug;
			}
		}

		if ( $best_slug && isset( $plugins[ $best_slug ] ) ) {
			$best_info = $this->get_wporg_plugin_info( $best_slug );
			$reason    = '';

			if ( $best_info ) {
				$parts = array();
				if ( ! empty( $best_info->active_installs ) ) {
					$parts[] = number_format_i18n( $best_info->active_installs ) . '+ active installs';
				}
				if ( ! empty( $best_info->last_updated ) ) {
					$parts[] = 'updated ' . human_time_diff( strtotime( $best_info->last_updated ) ) . ' ago';
				}
				if ( ! empty( $parts ) ) {
					$reason = ' (' . implode( ', ', $parts ) . ')';
				}
			}

			return sprintf(
				/* translators: 1: recommended plugin, 2: reason */
				__( 'Recommendation: Keep %1$s%2$s.', 'wp-site-doctor' ),
				$plugins[ $best_slug ],
				$reason
			);
		}

		return __( 'Compare features and active install counts to decide which to keep.', 'wp-site-doctor' );
	}

	/**
	 * Get the display name for a plugin by its file path.
	 *
	 * @param string $plugin_path Plugin file path relative to plugins dir.
	 * @return string Plugin name.
	 */
	private function get_plugin_name( $plugin_path ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$full_path = WP_PLUGIN_DIR . '/' . $plugin_path;

		if ( file_exists( $full_path ) ) {
			$data = get_plugin_data( $full_path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}

		// Fallback to folder name.
		return ucwords( str_replace( '-', ' ', explode( '/', $plugin_path )[0] ) );
	}

	/**
	 * Get human-readable category label.
	 *
	 * @param string $category Category key.
	 * @return string Translated label.
	 */
	private function get_category_label( $category ) {
		$labels = array(
			'caching'        => __( 'caching', 'wp-site-doctor' ),
			'seo'            => __( 'SEO', 'wp-site-doctor' ),
			'security'       => __( 'security', 'wp-site-doctor' ),
			'image_optim'    => __( 'image optimization', 'wp-site-doctor' ),
			'lazy_load'      => __( 'lazy loading', 'wp-site-doctor' ),
			'minify'         => __( 'minification', 'wp-site-doctor' ),
			'backup'         => __( 'backup', 'wp-site-doctor' ),
			'analytics'      => __( 'analytics', 'wp-site-doctor' ),
			'contact_forms'  => __( 'contact form', 'wp-site-doctor' ),
			'page_builders'  => __( 'page builder', 'wp-site-doctor' ),
			'smtp'           => __( 'SMTP/mail', 'wp-site-doctor' ),
		);

		return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
	}
}
