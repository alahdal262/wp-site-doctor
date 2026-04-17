<?php
/**
 * Settings registration and sanitization for WP Site Doctor.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Registers plugin settings via the WordPress Settings API.
 * All settings stored as a single serialized option: wpsd_settings.
 */
class Settings {

	/**
	 * Option name in wp_options.
	 */
	const OPTION_NAME = 'wpsd_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'wp-site-doctor-settings';

	/**
	 * Register settings, sections, and fields.
	 */
	public function register() {
		register_setting(
			'wpsd_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);

		// General section.
		add_settings_section(
			'wpsd_general',
			__( 'General Settings', 'wp-site-doctor' ),
			array( $this, 'render_general_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'admin_email',
			__( 'Admin Email for Alerts', 'wp-site-doctor' ),
			array( $this, 'render_email_field' ),
			self::PAGE_SLUG,
			'wpsd_general',
			array(
				'field'       => 'admin_email',
				'description' => __( 'Email address to receive health score alerts.', 'wp-site-doctor' ),
			)
		);

		add_settings_field(
			'developer_email',
			__( 'Developer Email', 'wp-site-doctor' ),
			array( $this, 'render_email_field' ),
			self::PAGE_SLUG,
			'wpsd_general',
			array(
				'field'       => 'developer_email',
				'description' => __( 'Email address for "Send Report to Developer" feature.', 'wp-site-doctor' ),
			)
		);

		// Scan schedule section.
		add_settings_section(
			'wpsd_schedule',
			__( 'Scheduled Scans', 'wp-site-doctor' ),
			array( $this, 'render_schedule_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'auto_scan_schedule',
			__( 'Auto-Scan Schedule', 'wp-site-doctor' ),
			array( $this, 'render_select_field' ),
			self::PAGE_SLUG,
			'wpsd_schedule',
			array(
				'field'       => 'auto_scan_schedule',
				'options'     => array(
					'off'    => __( 'Off', 'wp-site-doctor' ),
					'daily'  => __( 'Daily', 'wp-site-doctor' ),
					'weekly' => __( 'Weekly', 'wp-site-doctor' ),
				),
				'description' => __( 'Automatically run a health scan on schedule.', 'wp-site-doctor' ),
			)
		);

		add_settings_field(
			'health_alert_threshold',
			__( 'Health Score Alert Threshold', 'wp-site-doctor' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'wpsd_schedule',
			array(
				'field'       => 'health_alert_threshold',
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Send an alert email if health score drops below this value.', 'wp-site-doctor' ),
			)
		);

		// API Keys section.
		add_settings_section(
			'wpsd_api',
			__( 'API Keys', 'wp-site-doctor' ),
			array( $this, 'render_api_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpvulndb_api_key',
			__( 'WPVulnDB / Patchstack API Key', 'wp-site-doctor' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'wpsd_api',
			array(
				'field'       => 'wpvulndb_api_key',
				'type'        => 'password',
				'description' => __( 'Optional. Used for vulnerability checking against known databases.', 'wp-site-doctor' ),
			)
		);

		// Data Retention section.
		add_settings_section(
			'wpsd_retention',
			__( 'Data Retention', 'wp-site-doctor' ),
			array( $this, 'render_retention_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'restore_point_limit',
			__( 'Restore Point Retention', 'wp-site-doctor' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'wpsd_retention',
			array(
				'field'       => 'restore_point_limit',
				'min'         => 1,
				'max'         => 50,
				'description' => __( 'Number of restore points to keep.', 'wp-site-doctor' ),
			)
		);

		// Exclusions section.
		add_settings_section(
			'wpsd_exclusions',
			__( 'Exclusions', 'wp-site-doctor' ),
			array( $this, 'render_exclusions_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'excluded_checks',
			__( 'Exclude Scanners', 'wp-site-doctor' ),
			array( $this, 'render_multicheck_field' ),
			self::PAGE_SLUG,
			'wpsd_exclusions',
			array(
				'field'   => 'excluded_checks',
				'options' => array(
					'server_environment' => __( 'Server Environment', 'wp-site-doctor' ),
					'security'           => __( 'Security', 'wp-site-doctor' ),
					'performance'        => __( 'Performance', 'wp-site-doctor' ),
					'database'           => __( 'Database', 'wp-site-doctor' ),
					'cache'              => __( 'Cache', 'wp-site-doctor' ),
					'file_permissions'   => __( 'File Permissions', 'wp-site-doctor' ),
					'cron'               => __( 'Cron Jobs', 'wp-site-doctor' ),
					'seo'                => __( 'SEO', 'wp-site-doctor' ),
					'images'             => __( 'Images', 'wp-site-doctor' ),
					'plugin_conflicts'   => __( 'Plugin Conflicts', 'wp-site-doctor' ),
					'plugin_xray'        => __( 'Plugin X-Ray', 'wp-site-doctor' ),
				),
				'description' => __( 'Select scanners to exclude from scans.', 'wp-site-doctor' ),
			)
		);
	}

	/**
	 * Get default settings values.
	 *
	 * @return array Default settings.
	 */
	public static function get_defaults() {
		return array(
			'developer_email'        => '',
			'auto_scan_schedule'     => 'off',
			'health_alert_threshold' => 60,
			'admin_email'            => get_option( 'admin_email' ),
			'wpvulndb_api_key'       => '',
			'excluded_plugins'       => array(),
			'excluded_checks'        => array(),
			'restore_point_limit'    => 5,
		);
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if not set.
	 * @return mixed Setting value.
	 */
	public static function get( $key, $default = null ) {
		$settings = get_option( self::OPTION_NAME, self::get_defaults() );
		$defaults = self::get_defaults();

		if ( null === $default && isset( $defaults[ $key ] ) ) {
			$default = $defaults[ $key ];
		}

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Sanitize all settings on save.
	 *
	 * @param array $input Raw input from the form.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ) {
		$sanitized = array();

		$sanitized['admin_email'] = isset( $input['admin_email'] )
			? sanitize_email( $input['admin_email'] )
			: '';

		$sanitized['developer_email'] = isset( $input['developer_email'] )
			? sanitize_email( $input['developer_email'] )
			: '';

		$valid_schedules                   = array( 'off', 'daily', 'weekly' );
		$sanitized['auto_scan_schedule'] = isset( $input['auto_scan_schedule'] ) && in_array( $input['auto_scan_schedule'], $valid_schedules, true )
			? $input['auto_scan_schedule']
			: 'off';

		$sanitized['health_alert_threshold'] = isset( $input['health_alert_threshold'] )
			? min( 100, max( 0, absint( $input['health_alert_threshold'] ) ) )
			: 60;

		$sanitized['wpvulndb_api_key'] = isset( $input['wpvulndb_api_key'] )
			? sanitize_text_field( $input['wpvulndb_api_key'] )
			: '';

		$sanitized['restore_point_limit'] = isset( $input['restore_point_limit'] )
			? min( 50, max( 1, absint( $input['restore_point_limit'] ) ) )
			: 5;

		$sanitized['excluded_plugins'] = isset( $input['excluded_plugins'] ) && is_array( $input['excluded_plugins'] )
			? array_map( 'sanitize_text_field', $input['excluded_plugins'] )
			: array();

		$valid_checks                  = array(
			'server_environment',
			'security',
			'performance',
			'database',
			'cache',
			'file_permissions',
			'cron',
			'seo',
			'images',
			'plugin_conflicts',
			'plugin_xray',
		);
		$sanitized['excluded_checks'] = isset( $input['excluded_checks'] ) && is_array( $input['excluded_checks'] )
			? array_intersect( array_map( 'sanitize_text_field', $input['excluded_checks'] ), $valid_checks )
			: array();

		return $sanitized;
	}

	/**
	 * Render section descriptions.
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure email addresses for alerts and reports.', 'wp-site-doctor' ) . '</p>';
	}

	/**
	 * Render schedule section description.
	 */
	public function render_schedule_section() {
		echo '<p>' . esc_html__( 'Configure automatic scan scheduling and health alerts.', 'wp-site-doctor' ) . '</p>';
	}

	/**
	 * Render API section description.
	 */
	public function render_api_section() {
		echo '<p>' . esc_html__( 'Optional API keys for enhanced scanning capabilities.', 'wp-site-doctor' ) . '</p>';
	}

	/**
	 * Render retention section description.
	 */
	public function render_retention_section() {
		echo '<p>' . esc_html__( 'Configure data retention policies.', 'wp-site-doctor' ) . '</p>';
	}

	/**
	 * Render exclusions section description.
	 */
	public function render_exclusions_section() {
		echo '<p>' . esc_html__( 'Exclude specific scanners from running.', 'wp-site-doctor' ) . '</p>';
	}

	/**
	 * Render an email input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_email_field( $args ) {
		$value = self::get( $args['field'] );
		printf(
			'<input type="email" id="wpsd_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" />',
			esc_attr( $args['field'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$value = self::get( $args['field'] );
		$type  = isset( $args['type'] ) ? $args['type'] : 'text';
		printf(
			'<input type="%4$s" id="wpsd_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" />',
			esc_attr( $args['field'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value ),
			esc_attr( $type )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number_field( $args ) {
		$value = self::get( $args['field'] );
		printf(
			'<input type="number" id="wpsd_%1$s" name="%2$s[%1$s]" value="%3$s" min="%4$d" max="%5$d" class="small-text" />',
			esc_attr( $args['field'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value ),
			isset( $args['min'] ) ? intval( $args['min'] ) : 0,
			isset( $args['max'] ) ? intval( $args['max'] ) : 100
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a select dropdown field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_select_field( $args ) {
		$value = self::get( $args['field'] );
		printf(
			'<select id="wpsd_%1$s" name="%2$s[%1$s]">',
			esc_attr( $args['field'] ),
			esc_attr( self::OPTION_NAME )
		);
		foreach ( $args['options'] as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render a multi-checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_multicheck_field( $args ) {
		$values = self::get( $args['field'], array() );
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		echo '<fieldset>';
		foreach ( $args['options'] as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s</label><br />',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $args['field'] ),
				esc_attr( $key ),
				checked( in_array( $key, $values, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}
}
