<?php
/**
 * Image Scanner for WP Site Doctor.
 *
 * Checks for missing alt text, large unoptimized images, missing
 * lazy loading, and broken image URLs.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor\Scanners;

use WPSiteDoctor\Abstract_Scanner;

defined( 'ABSPATH' ) || exit;

class Image_Scanner extends Abstract_Scanner {

	public function get_id(): string {
		return 'images';
	}

	public function get_label(): string {
		return __( 'Images', 'wp-site-doctor' );
	}

	public function get_category(): string {
		return 'images';
	}

	public function run(): array {
		$this->reset();

		$this->check_total_images();
		$this->check_missing_alt_text();
		$this->check_large_images();
		$this->check_oversized_images();
		$this->check_lazy_loading();
		$this->check_image_optimization_plugin();
		$this->check_broken_images();

		return $this->build_result();
	}

	private function check_total_images() {
		$counts = wp_count_attachments();
		$count  = 0;

		// wp_count_attachments() returns an object with properties like "image/jpeg", "image/png", etc.
		// Sum all image/* mime types. There is no single "image" property.
		foreach ( (array) $counts as $mime => $num ) {
			if ( 0 === strpos( $mime, 'image/' ) ) {
				$count += (int) $num;
			}
		}

		$this->add_issue(
			'info',
			'total_images',
			sprintf(
				/* translators: %s: image count */
				__( 'Media Library contains %s images.', 'wp-site-doctor' ),
				number_format_i18n( $count )
			)
		);
	}

	private function check_missing_alt_text() {
		global $wpdb;

		// Count images missing alt text (last 200 images for performance).
		$total_recent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s ORDER BY ID DESC LIMIT 200",
				'image/%'
			)
		);

		$missing_alt = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type LIKE %s
				AND (pm.meta_value IS NULL OR pm.meta_value = '')
				ORDER BY p.ID DESC
				LIMIT 200",
				'image/%'
			)
		);

		if ( $missing_alt > 0 ) {
			$pct = ( $total_recent > 0 ) ? round( ( $missing_alt / $total_recent ) * 100, 1 ) : 0;

			$severity = $pct > 50 ? 'warning' : 'info';

			$this->add_issue(
				$severity,
				'missing_alt_text',
				sprintf(
					/* translators: 1: count, 2: percentage */
					__( '%1$s images are missing alt text (%2$s%% of recent images).', 'wp-site-doctor' ),
					number_format_i18n( $missing_alt ),
					$pct
				),
				__( 'Alt text improves accessibility and SEO. Add descriptive alt text to all images via Media Library.', 'wp-site-doctor' ),
				array(
					'action_id'   => 'bulk_add_alt_text',
					'label'       => __( 'Auto-fill missing alt text from filenames', 'wp-site-doctor' ),
					'description' => __( 'Generates alt text from image filenames for images that have none.', 'wp-site-doctor' ),
				)
			);
		} else {
			$this->add_pass( 'alt_text_ok', __( 'All recent images have alt text.', 'wp-site-doctor' ) );
		}
	}

	private function check_large_images() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Check last 50 images for file size.
		$images = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value as file_path
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				ORDER BY p.ID DESC LIMIT 50",
				'image/%'
			)
		);

		$large_count = 0;

		foreach ( $images as $image ) {
			$filepath = $basedir . '/' . $image->file_path;

			if ( file_exists( $filepath ) ) {
				$size = filesize( $filepath );
				if ( $size > 500 * 1024 ) { // > 500KB.
					++$large_count;
				}
			}
		}

		if ( $large_count > 0 ) {
			$this->add_issue(
				'warning',
				'large_images',
				sprintf(
					/* translators: %d: count of large images */
					__( '%d images are larger than 500KB (of the last 50 checked). These need optimization.', 'wp-site-doctor' ),
					$large_count
				),
				__( 'Use an image optimization plugin to compress images. Free options: ShortPixel, Imagify, or EWWW Image Optimizer.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'image_sizes_ok', __( 'Recent images are reasonably sized (under 500KB).', 'wp-site-doctor' ) );
		}
	}

	private function check_oversized_images() {
		global $wpdb;

		// Check for images with dimensions > 2560px.
		$oversized = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_metadata'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				ORDER BY p.ID DESC LIMIT 50",
				'image/%'
			)
		);

		$meta_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_metadata'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				ORDER BY p.ID DESC LIMIT 50",
				'image/%'
			)
		);

		$oversized_count = 0;
		foreach ( $meta_results as $row ) {
			$meta = maybe_unserialize( $row->meta_value );
			if ( is_array( $meta ) ) {
				$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
				$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

				if ( $width > 2560 || $height > 2560 ) {
					++$oversized_count;
				}
			}
		}

		if ( $oversized_count > 0 ) {
			$this->add_issue(
				'info',
				'oversized_images',
				sprintf(
					/* translators: %d: count */
					__( '%d image(s) have dimensions exceeding 2560px.', 'wp-site-doctor' ),
					$oversized_count
				),
				__( 'WordPress 5.3+ automatically scales large uploads to 2560px max. Older uploads may need manual resizing.', 'wp-site-doctor' )
			);
		}
	}

	private function check_lazy_loading() {
		// WordPress 5.5+ adds native lazy loading.
		if ( $this->wp_version_gte( '5.5' ) ) {
			$this->add_pass(
				'lazy_loading_native',
				__( 'Native lazy loading is supported (WordPress 5.5+).', 'wp-site-doctor' )
			);
		} else {
			$this->add_issue(
				'info',
				'no_native_lazy_loading',
				__( 'WordPress version does not support native lazy loading.', 'wp-site-doctor' ),
				__( 'Update WordPress to 5.5+ or install a lazy loading plugin.', 'wp-site-doctor' )
			);
		}
	}

	private function check_image_optimization_plugin() {
		$optim_plugins = array(
			'imagify'                     => 'Imagify',
			'ewww-image-optimizer'        => 'EWWW Image Optimizer',
			'shortpixel-image-optimiser'  => 'ShortPixel',
			'wp-smushit'                  => 'Smush',
			'optimole-wp'                 => 'Optimole',
			'tiny-compress-images'        => 'TinyPNG',
		);

		$active_optim = array();
		$all_active   = $this->get_active_plugins();

		foreach ( $all_active as $plugin_path ) {
			$folder = explode( '/', $plugin_path )[0];
			if ( isset( $optim_plugins[ $folder ] ) ) {
				$active_optim[ $folder ] = $optim_plugins[ $folder ];
			}
		}

		if ( empty( $active_optim ) ) {
			$this->add_issue(
				'info',
				'no_image_optimization',
				__( 'No image optimization plugin is active.', 'wp-site-doctor' ),
				__( 'Install an image optimization plugin to automatically compress uploads. Free options: ShortPixel, Imagify, or EWWW.', 'wp-site-doctor' )
			);
		} elseif ( count( $active_optim ) > 1 ) {
			$this->add_issue(
				'warning',
				'multiple_image_plugins',
				sprintf(
					/* translators: %s: plugin list */
					__( 'Multiple image optimization plugins active: %s. Use only one.', 'wp-site-doctor' ),
					implode( ', ', $active_optim )
				),
				__( 'Keep the one with the best results and deactivate the others.', 'wp-site-doctor' )
			);
		} else {
			$name = reset( $active_optim );
			$this->add_pass(
				'image_optimization_active',
				sprintf(
					/* translators: %s: plugin name */
					__( 'Image optimization active: %s', 'wp-site-doctor' ),
					$name
				)
			);
		}
	}

	private function check_broken_images() {
		global $wpdb;

		// Sample last 20 images for broken URL check.
		$images = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value as file_path
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				ORDER BY p.ID DESC LIMIT 20",
				'image/%'
			)
		);

		$upload_dir = wp_upload_dir();
		$broken     = 0;

		foreach ( $images as $image ) {
			$filepath = $upload_dir['basedir'] . '/' . $image->file_path;
			if ( ! file_exists( $filepath ) ) {
				++$broken;
			}
		}

		if ( $broken > 0 ) {
			$this->add_issue(
				'warning',
				'broken_images',
				sprintf(
					/* translators: %d: count */
					__( '%d of the last 20 images have missing source files on disk.', 'wp-site-doctor' ),
					$broken
				),
				__( 'These images exist in the Media Library but their files are missing. They may need to be re-uploaded.', 'wp-site-doctor' )
			);
		} else {
			$this->add_pass( 'no_broken_images', __( 'No broken image files detected in recent uploads.', 'wp-site-doctor' ) );
		}
	}
}
