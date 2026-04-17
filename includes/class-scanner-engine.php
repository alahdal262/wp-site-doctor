<?php
/**
 * Scanner Engine for WP Site Doctor.
 *
 * Orchestrates all scanner modules. Maintains a registry of scanners
 * and provides methods to run them individually or as a batch.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scanner_Engine
 *
 * Registry and executor for all scanner modules.
 */
class Scanner_Engine {

	/**
	 * Registered scanner instances keyed by ID.
	 *
	 * @var Scanner_Interface[]
	 */
	private $scanners = array();

	/**
	 * Constructor.
	 *
	 * Auto-registers all built-in scanners. The list is filterable.
	 */
	public function __construct() {
		$this->register_default_scanners();
	}

	/**
	 * Register all built-in scanner modules.
	 */
	private function register_default_scanners() {
		$default_scanners = array(
			new Scanners\Server_Environment_Scanner(),
			new Scanners\Security_Scanner(),
			new Scanners\Performance_Scanner(),
			new Scanners\Database_Scanner(),
			new Scanners\Cache_Scanner(),
			new Scanners\File_Permissions_Scanner(),
			new Scanners\Cron_Scanner(),
			new Scanners\SEO_Scanner(),
			new Scanners\Image_Scanner(),
			new Scanners\Plugin_Conflict_Scanner(),
			new Scanners\Plugin_Xray_Scanner(),
			new Scanners\Storage_Scanner(),
		);

		foreach ( $default_scanners as $scanner ) {
			$this->register_scanner( $scanner );
		}

		/**
		 * Filter the registered scanners.
		 *
		 * Allows third-party code to add or remove scanners.
		 *
		 * @param Scanner_Interface[] $scanners Map of scanner_id => Scanner_Interface.
		 */
		$this->scanners = apply_filters( 'wpsd_registered_scanners', $this->scanners );
	}

	/**
	 * Register a single scanner.
	 *
	 * @param Scanner_Interface $scanner Scanner instance.
	 */
	public function register_scanner( Scanner_Interface $scanner ) {
		$this->scanners[ $scanner->get_id() ] = $scanner;
	}

	/**
	 * Get a scanner by its ID.
	 *
	 * @param string $id Scanner identifier.
	 * @return Scanner_Interface|null Scanner instance or null.
	 */
	public function get_scanner( $id ) {
		return isset( $this->scanners[ $id ] ) ? $this->scanners[ $id ] : null;
	}

	/**
	 * Get all registered scanner IDs.
	 *
	 * @return array Array of scanner ID strings.
	 */
	public function get_all_scanner_ids() {
		return array_keys( $this->scanners );
	}

	/**
	 * Get all registered scanners with their metadata.
	 *
	 * @return array Array of arrays with 'id', 'label', 'category'.
	 */
	public function get_all_scanners_info() {
		$info = array();

		foreach ( $this->scanners as $scanner ) {
			$info[] = array(
				'id'       => $scanner->get_id(),
				'label'    => $scanner->get_label(),
				'category' => $scanner->get_category(),
			);
		}

		return $info;
	}

	/**
	 * Run a specific scanner by ID.
	 *
	 * @param string $id Scanner identifier.
	 * @return array Scanner result array.
	 * @throws \RuntimeException If scanner not found.
	 */
	public function run_scanner( $id ) {
		$scanner = $this->get_scanner( $id );

		if ( ! $scanner ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: scanner ID */
					__( 'Scanner not found: %s', 'wp-site-doctor' ),
					$id
				)
			);
		}

		return $scanner->run();
	}

	/**
	 * Run all registered scanners sequentially.
	 *
	 * @return array Map of scanner_id => result array.
	 */
	public function run_all() {
		$results = array();

		foreach ( $this->scanners as $id => $scanner ) {
			try {
				$results[ $id ] = $scanner->run();
			} catch ( \Exception $e ) {
				$results[ $id ] = array(
					'scanner_id' => $id,
					'issues'     => array(
						array(
							'severity'       => 'warning',
							'code'           => 'scanner_error',
							'message'        => sprintf(
								/* translators: 1: scanner label, 2: error message */
								__( '%1$s scanner failed: %2$s', 'wp-site-doctor' ),
								$scanner->get_label(),
								$e->getMessage()
							),
							'recommendation' => __( 'This scanner encountered an error. Try running the scan again.', 'wp-site-doctor' ),
						),
					),
					'score'      => 50,
					'category'   => $scanner->get_category(),
				);
			}
		}

		return $results;
	}

	/**
	 * Get the count of registered scanners.
	 *
	 * @return int Number of scanners.
	 */
	public function count() {
		return count( $this->scanners );
	}
}
