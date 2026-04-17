<?php
/**
 * SEO Scanner for WP Site Doctor.
 *
 * Checks SEO plugin presence, robots.txt, sitemap, visibility settings,
 * permalinks, and meta descriptions.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class SEO_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'seo';
	}

	public function get_label(): string {
		return __( 'SEO', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'seo';
	}

	public function run(): array {
		$this->reset();

		$this->check_seo_plugin();
		$this->check_search_visibility();
		$this->check_permalink_structure();
		$this->check_robots_txt();
		$this->check_sitemap();
		$this->check_homepage_meta();

		return $this->build_result();
	}

	private function check_seo_plugin() {
		$seo_plugins = array(
			'wordpress-seo'           => 'Yoast SEO',
			'all-in-one-seo-pack'     => 'All in One SEO',
			'seo-by-rank-math'        => 'Rank Math',
			'rank-math-seo'           => 'Rank Math',
			'the-seo-framework'       => 'The SEO Framework',
			'squirrly-seo'            => 'Squirrly SEO',
			'smartcrawl-seo'          => 'SmartCrawl',
			'slim-seo'                => 'Slim SEO',
		);

		$active_seo = array();
		$all_active = $this->get_active_plugins();

		foreach ( $all_active as $plugin_path ) {
			$folder = explode( '/', $plugin_path )[0];
			if ( isset( $seo_plugins[ $folder ] ) ) {
				$active_seo[ $folder ] = $seo_plugins[ $folder ];
			}
		}

		if ( empty( $active_seo ) ) {
			$this->add_issue(
				'warning',
				'no_seo_plugin',
				__( 'No SEO plugin is active.', 'wp-site-doctor' ),
				__( 'Install an SEO plugin for meta titles, descriptions, sitemaps, and schema. Free options: Yoast SEO, Rank Math, or The SEO Framework.', 'wp-site-doctor' )
			);
		} elseif ( count( $active_seo ) > 1 ) {
			$this->add_issue(
				'critical',
				'multiple_seo_plugins',
				sprintf(
					/* translators: %s: list of SEO plugins */
					__( 'Multiple SEO plugins active: %s. This causes duplicate meta tags and conflicts.', 'wp-site-doctor' ),
					implode( ', ', $active_seo )
				),
				__( 'Keep only ONE SEO plugin. Check the Plugin Conflicts scanner for a recommendation.', 'wp-site-doctor' )
			);
		} else {
			$name = reset( $active_seo );
			$this->add_pass(
				'seo_plugin_active',
				sprintf(
					/* translators: %s: SEO plugin name */
					__( 'SEO plugin active: %s', 'wp-site-doctor' ),
					$name
				)
			);
		}
	}

	private function check_search_visibility() {
		$blog_public = get_option( 'blog_public' );

		if ( '0' === (string) $blog_public ) {
			$this->add_issue(
				'critical',
				'search_visibility_off',
				__( '"Discourage search engines from indexing this site" is enabled. Search engines will not index your site.', 'wp-site-doctor' ),
				sprintf(
					/* translators: %s: settings URL */
					__( 'If this is a production site, uncheck this at %s.', 'wp-site-doctor' ),
					$this->admin_link( 'options-reading.php' )
				)
			);
		} else {
			$this->add_pass( 'search_visibility_ok', __( 'Search engine visibility is enabled.', 'wp-site-doctor' ) );
		}
	}

	private function check_permalink_structure() {
		$structure = get_option( 'permalink_structure' );

		if ( empty( $structure ) ) {
			$this->add_issue(
				'warning',
				'plain_permalinks',
				__( 'Using plain permalink structure (?p=123). This is bad for SEO.', 'wp-site-doctor' ),
				sprintf(
					/* translators: %s: settings URL */
					__( 'Change permalink structure to "Post name" at %s.', 'wp-site-doctor' ),
					$this->admin_link( 'options-permalink.php' )
				)
			);
		} else {
			$this->add_pass(
				'permalinks_ok',
				sprintf(
					/* translators: %s: permalink structure */
					__( 'Permalink structure: %s', 'wp-site-doctor' ),
					$structure
				)
			);
		}
	}

	private function check_robots_txt() {
		$response = $this->remote_get( home_url( '/robots.txt' ), 5 );

		if ( is_wp_error( $response ) ) {
			$this->add_issue(
				'info',
				'robots_check_failed',
				__( 'Could not check robots.txt — request failed.', 'wp-site-doctor' )
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code && ! empty( $body ) ) {
			if ( false !== stripos( $body, 'Disallow: /' ) && false === stripos( $body, 'Disallow: /wp-admin' ) ) {
				$this->add_issue(
					'warning',
					'robots_blocks_all',
					__( 'robots.txt appears to block all crawlers. This prevents search engine indexing.', 'wp-site-doctor' ),
					__( 'Review your robots.txt to ensure it does not block important content.', 'wp-site-doctor' )
				);
			} else {
				$this->add_pass( 'robots_txt_ok', __( 'robots.txt is present and accessible.', 'wp-site-doctor' ) );
			}
		} else {
			$this->add_issue(
				'info',
				'no_robots_txt',
				__( 'No robots.txt found. WordPress generates a virtual one, but a custom one is recommended.', 'wp-site-doctor' ),
				__( 'Your SEO plugin can manage robots.txt, or create one manually.', 'wp-site-doctor' )
			);
		}
	}

	private function check_sitemap() {
		$sitemap_urls = array(
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/wp-sitemap.xml' ), // WordPress core sitemap (5.5+).
		);

		foreach ( $sitemap_urls as $url ) {
			$response = $this->remote_get( $url, 5 );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				if ( false !== stripos( $body, '<urlset' ) || false !== stripos( $body, '<sitemapindex' ) ) {
					$this->add_pass(
						'sitemap_found',
						sprintf(
							/* translators: %s: sitemap URL */
							__( 'XML sitemap found at %s', 'wp-site-doctor' ),
							$url
						)
					);
					return;
				}
			}
		}

		$this->add_issue(
			'warning',
			'no_sitemap',
			__( 'No XML sitemap found. Sitemaps help search engines discover your content.', 'wp-site-doctor' ),
			__( 'Enable the sitemap feature in your SEO plugin, or use WordPress\'s built-in sitemap (/wp-sitemap.xml).', 'wp-site-doctor' )
		);
	}

	private function check_homepage_meta() {
		$response = $this->remote_get( home_url( '/' ), 5 );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = wp_remote_retrieve_body( $response );

		// Check for meta description.
		if ( false === stripos( $body, 'name="description"' ) ) {
			$this->add_issue(
				'warning',
				'no_meta_description',
				__( 'Homepage is missing a meta description tag.', 'wp-site-doctor' ),
				__( 'Set a meta description in your SEO plugin\'s homepage settings.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'meta_description_ok', __( 'Homepage has a meta description.', 'wp-site-doctor' ) );
		}

		// Check for Open Graph tags.
		if ( false === stripos( $body, 'og:title' ) ) {
			$this->add_issue(
				'info',
				'no_og_tags',
				__( 'Open Graph meta tags not found on homepage.', 'wp-site-doctor' ),
				__( 'Open Graph tags improve how your site appears when shared on social media. Most SEO plugins add these automatically.', 'wp-site-doctor' )
			);
		}
	}
}
