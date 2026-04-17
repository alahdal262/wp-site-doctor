<?php
/**
 * Health Score calculator for WP Site Doctor.
 *
 * Computes a weighted composite score from individual scanner scores.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Health_Score
 *
 * Calculates the weighted health score (0-100) from scanner results,
 * maps scores to grades/colors, and provides comparison utilities.
 */
class Health_Score {

	/**
	 * Default scanner weights (must sum to 1.0).
	 *
	 * @var array
	 */
	private $weights = array(
		'security'           => 0.18,
		'performance'        => 0.14,
		'database'           => 0.10,
		'storage'            => 0.10,
		'plugin_conflicts'   => 0.10,
		'server_environment' => 0.09,
		'file_permissions'   => 0.07,
		'cache'              => 0.06,
		'images'             => 0.05,
		'seo'                => 0.05,
		'cron'               => 0.03,
		'plugin_xray'        => 0.03,
	);

	/**
	 * Constructor.
	 *
	 * Applies filter so third-party code can adjust weights.
	 */
	public function __construct() {
		/**
		 * Filter the scanner weight map.
		 *
		 * @param array $weights Map of scanner_id => weight (0.0-1.0). Must sum to 1.0.
		 */
		$this->weights = apply_filters( 'wpsd_scanner_weights', $this->weights );
	}

	/**
	 * Calculate the composite health score.
	 *
	 * @param array $scanner_scores Map of scanner_id => individual score (0-100).
	 * @return int Composite health score (0-100).
	 */
	public function calculate( $scanner_scores ) {
		if ( empty( $scanner_scores ) ) {
			return 0;
		}

		$weighted_sum   = 0.0;
		$total_weight   = 0.0;

		foreach ( $scanner_scores as $scanner_id => $score ) {
			$weight = isset( $this->weights[ $scanner_id ] ) ? $this->weights[ $scanner_id ] : 0.0;

			if ( $weight > 0 ) {
				$weighted_sum += (float) $score * $weight;
				$total_weight += $weight;
			}
		}

		// Normalize if not all scanners ran (excluded scanners).
		if ( $total_weight > 0 && $total_weight < 1.0 ) {
			$weighted_sum = $weighted_sum / $total_weight;
		}

		return max( 0, min( 100, (int) round( $weighted_sum ) ) );
	}

	/**
	 * Get letter grade for a score.
	 *
	 * @param int $score Health score (0-100).
	 * @return string Letter grade: A, B, C, D, or F.
	 */
	public function get_grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		} elseif ( $score >= 70 ) {
			return 'B';
		} elseif ( $score >= 50 ) {
			return 'C';
		} elseif ( $score >= 30 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * Get human-readable grade label for a score.
	 *
	 * @param int $score Health score (0-100).
	 * @return string Label such as "Excellent", "Good", etc.
	 */
	public static function get_grade_label( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'wp-site-doctor' );
		} elseif ( $score >= 70 ) {
			return __( 'Good', 'wp-site-doctor' );
		} elseif ( $score >= 50 ) {
			return __( 'Needs Attention', 'wp-site-doctor' );
		}
		return __( 'Critical', 'wp-site-doctor' );
	}

	/**
	 * Get hex color for the gauge display.
	 *
	 * @param int $score Health score (0-100).
	 * @return string Hex color code.
	 */
	public function get_color( $score ) {
		if ( $score >= 90 ) {
			return '#00a32a'; // Green.
		} elseif ( $score >= 70 ) {
			return '#2271b1'; // Blue.
		} elseif ( $score >= 50 ) {
			return '#dba617'; // Orange.
		}
		return '#d63638'; // Red.
	}

	/**
	 * Get CSS class suffix for score-based styling.
	 *
	 * @param int $score Health score (0-100).
	 * @return string CSS class suffix: excellent, good, warning, or critical.
	 */
	public static function get_score_class( $score ) {
		if ( $score >= 90 ) {
			return 'excellent';
		} elseif ( $score >= 70 ) {
			return 'good';
		} elseif ( $score >= 50 ) {
			return 'warning';
		}
		return 'critical';
	}

	/**
	 * Compare two scores and return the delta.
	 *
	 * @param int $before Previous score.
	 * @param int $after  Current score.
	 * @return array Array with 'delta', 'direction' (up/down/same), and 'label'.
	 */
	public function compare( $before, $after ) {
		$delta     = $after - $before;
		$direction = 'same';
		$label     = __( 'No change', 'wp-site-doctor' );

		if ( $delta > 0 ) {
			$direction = 'up';
			$label     = sprintf(
				/* translators: %d: score improvement points */
				__( '+%d improvement', 'wp-site-doctor' ),
				$delta
			);
		} elseif ( $delta < 0 ) {
			$direction = 'down';
			$label     = sprintf(
				/* translators: %d: score decline points */
				__( '%d decline', 'wp-site-doctor' ),
				$delta
			);
		}

		return array(
			'delta'     => $delta,
			'direction' => $direction,
			'label'     => $label,
		);
	}
}
