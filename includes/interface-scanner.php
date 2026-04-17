<?php
/**
 * Scanner Interface for WP Site Doctor.
 *
 * Defines the contract that all scanner modules must implement.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Scanner_Interface
 *
 * Every scanner module must implement these four methods.
 * The run() method performs the scan and returns a standardized result array.
 */
interface Scanner_Interface {

	/**
	 * Get the unique machine-readable scanner identifier.
	 *
	 * @return string Scanner ID (e.g., 'security', 'performance', 'database').
	 */
	public function get_id(): string;

	/**
	 * Get the human-readable scanner label.
	 *
	 * @return string Translated label for display.
	 */
	public function get_label(): string;

	/**
	 * Get the scanner category name.
	 *
	 * @return string Category (e.g., 'Security', 'Performance').
	 */
	public function get_category(): string;

	/**
	 * Execute the scan and return results.
	 *
	 * @return array {
	 *     Standardized result array.
	 *
	 *     @type string $scanner_id Scanner identifier.
	 *     @type array  $issues     Array of issue arrays, each containing:
	 *                              - severity: 'critical', 'warning', 'info', or 'pass'
	 *                              - code: Machine-readable issue code
	 *                              - message: Human-readable issue description
	 *                              - recommendation: Fix suggestion
	 *                              - repair_action: Optional auto-repair descriptor array
	 *     @type int    $score      Scanner score 0-100.
	 *     @type string $category   Category name.
	 * }
	 */
	public function run(): array;
}
