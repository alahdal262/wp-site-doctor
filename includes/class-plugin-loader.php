<?php
/**
 * Plugin Loader for WP Site Doctor.
 *
 * Central registration hub for all hooks, filters, and sub-components.
 *
 * @package WPSiteDoctor
 */

namespace WPSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin_Loader
 *
 * Bootstraps all plugin components: admin menu, AJAX handlers, cron,
 * and conditionally loads admin assets only on plugin pages.
 */
class Plugin_Loader {

	/**
	 * Admin menu instance.
	 *
	 * @var Admin_Menu
	 */
	private $admin_menu;

	/**
	 * AJAX handler instance.
	 *
	 * @var Ajax_Handler
	 */
	private $ajax_handler;

	/**
	 * Array of plugin page hook suffixes for conditional asset loading.
	 *
	 * @var array
	 */
	private $page_hook_suffixes = array();

	/**
	 * Initialize all plugin components.
	 */
	public function init() {
		$this->load_textdomain();

		if ( is_admin() ) {
			$this->admin_menu   = new Admin_Menu();
			$this->ajax_handler = new Ajax_Handler();

			add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		}

		// Register AJAX handlers (must be available even outside admin pages).
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->ajax_handler = new Ajax_Handler();
			$this->ajax_handler->register_handlers();
		}

		// Initialize cron manager for scheduled scans (must run on all requests).
		$cron_manager = new Cron_Manager();
		$cron_manager->init();
	}

	/**
	 * Load plugin text domain for translations.
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'wp-site-doctor',
			false,
			dirname( WPSD_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register admin menu pages and capture hook suffixes.
	 */
	public function register_admin_menu() {
		$this->admin_menu   = new Admin_Menu();
		$this->page_hook_suffixes = $this->admin_menu->register();
	}

	/**
	 * Register plugin settings via the Settings API.
	 */
	public function register_settings() {
		$settings = new Settings();
		$settings->register();
	}

	/**
	 * Show a dismissible activation notice on first install.
	 *
	 * Displayed once to users with the required capability until dismissed.
	 * Dismissal is stored in user meta via the wpsd_dismiss_notice AJAX endpoint.
	 */
	public function activation_notice() {
		// Only show to users with the required capability.
		if ( ! current_user_can( wpsd_required_capability() ) ) {
			return;
		}

		// Check if already dismissed.
		if ( get_user_meta( get_current_user_id(), 'wpsd_dismissed_activation', true ) ) {
			return;
		}

		// Don't show if a scan has already been run.
		$latest = Database::get_latest_scan();
		if ( $latest ) {
			return;
		}

		$dashboard_url = admin_url( 'admin.php?page=wp-site-doctor' );
		$nonce         = wp_create_nonce( 'wpsd_nonce' );
		?>
		<div class="notice notice-info is-dismissible wpsd-activation-notice" id="wpsd-activation-notice">
			<p>
				<strong><?php esc_html_e( 'WP Site Doctor is ready!', 'wp-site-doctor' ); ?></strong>
				<?php esc_html_e( 'Run your first scan to get a complete health assessment of your WordPress site.', 'wp-site-doctor' ); ?>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary" style="margin-left: 10px; vertical-align: baseline;">
					<?php esc_html_e( 'Run First Scan', 'wp-site-doctor' ); ?> &rarr;
				</a>
			</p>
		</div>
		<script>
		(function() {
			var notice = document.getElementById('wpsd-activation-notice');
			if (!notice) return;

			notice.addEventListener('click', function(e) {
				if (e.target.classList.contains('notice-dismiss') || e.target.closest('.notice-dismiss')) {
					var formData = new FormData();
					formData.append('action', 'wpsd_dismiss_notice');
					formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
					formData.append('notice_id', 'activation');
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						body: formData,
						credentials: 'same-origin'
					});
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Conditionally enqueue admin CSS and JS only on plugin pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// Only load assets on our own pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		// Dashboard styles.
		wp_enqueue_style(
			'wpsd-admin-dashboard',
			WPSD_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			array(),
			WPSD_VERSION
		);

		wp_enqueue_style(
			'wpsd-health-gauge',
			WPSD_PLUGIN_URL . 'assets/css/health-gauge.css',
			array(),
			WPSD_VERSION
		);

		wp_enqueue_style(
			'wpsd-scan-results',
			WPSD_PLUGIN_URL . 'assets/css/scan-results.css',
			array(),
			WPSD_VERSION
		);

		// Health gauge JS.
		wp_enqueue_script(
			'wpsd-health-gauge',
			WPSD_PLUGIN_URL . 'assets/js/health-gauge.js',
			array(),
			WPSD_VERSION,
			true
		);

		// Scan runner JS.
		wp_enqueue_script(
			'wpsd-scan-runner',
			WPSD_PLUGIN_URL . 'assets/js/scan-runner.js',
			array(),
			WPSD_VERSION,
			true
		);

		// Auto-repair JS (only on repair pages).
		if ( $this->is_repair_page( $hook_suffix ) ) {
			wp_enqueue_script(
				'wpsd-auto-repair',
				WPSD_PLUGIN_URL . 'assets/js/auto-repair.js',
				array(),
				WPSD_VERSION,
				true
			);
		}

		// Main dashboard JS.
		wp_enqueue_script(
			'wpsd-admin-dashboard',
			WPSD_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array( 'wpsd-health-gauge', 'wpsd-scan-runner' ),
			WPSD_VERSION,
			true
		);

		// Localize script data for JS.
		$latest_scan = Database::get_latest_scan();

		wp_localize_script(
			'wpsd-admin-dashboard',
			'wpsdData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'wpsd_nonce' ),
				'pluginUrl'    => WPSD_PLUGIN_URL,
				'lastScore'    => $latest_scan ? absint( $latest_scan->health_score ) : null,
				'lastScanDate' => $latest_scan ? esc_html( $latest_scan->created_at ) : null,
				'scanners'     => $this->get_scanner_list(),
				'i18n'         => array(
					'scanning'       => esc_html__( 'Scanning...', 'wp-site-doctor' ),
					'scanComplete'   => esc_html__( 'Scan Complete', 'wp-site-doctor' ),
					'scanFailed'     => esc_html__( 'Scan Failed', 'wp-site-doctor' ),
					'running'        => esc_html__( 'Running', 'wp-site-doctor' ),
					'confirmRepair'  => esc_html__( 'Are you sure you want to run the selected repairs?', 'wp-site-doctor' ),
					'repairing'      => esc_html__( 'Repairing...', 'wp-site-doctor' ),
					'repairComplete' => esc_html__( 'Repair Complete', 'wp-site-doctor' ),
					'repairFailed'   => esc_html__( 'Repair Failed', 'wp-site-doctor' ),
					'rollbackConfirm' => esc_html__( 'Are you sure you want to rollback this action?', 'wp-site-doctor' ),
				),
			)
		);
	}

	/**
	 * Check if the current admin page belongs to this plugin.
	 *
	 * @param string $hook_suffix Current page hook suffix.
	 * @return bool True if this is a plugin page.
	 */
	private function is_plugin_page( $hook_suffix ) {
		// Match against registered page hook suffixes.
		if ( in_array( $hook_suffix, $this->page_hook_suffixes, true ) ) {
			return true;
		}

		// Fallback: check for our page slug in the query string.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking page identity.
		if ( isset( $_GET['page'] ) && 0 === strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'wp-site-doctor' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the current page is a repair-related page.
	 *
	 * @param string $hook_suffix Current page hook suffix.
	 * @return bool True if this is a repair page.
	 */
	private function is_repair_page( $hook_suffix ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just checking page identity.
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

		return in_array( $page, array( 'wp-site-doctor-repair', 'wp-site-doctor-repair-log' ), true );
	}

	/**
	 * Get the list of available scanners with IDs and labels.
	 *
	 * Used for wp_localize_script so JS knows what scanners to run.
	 *
	 * @return array Array of scanner info arrays.
	 */
	private function get_scanner_list() {
		return array(
			array(
				'id'    => 'server_environment',
				'label' => esc_html__( 'Server Environment', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'security',
				'label' => esc_html__( 'Security', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'performance',
				'label' => esc_html__( 'Performance', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'database',
				'label' => esc_html__( 'Database', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'cache',
				'label' => esc_html__( 'Cache', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'file_permissions',
				'label' => esc_html__( 'File Permissions', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'cron',
				'label' => esc_html__( 'Cron Jobs', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'seo',
				'label' => esc_html__( 'SEO', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'images',
				'label' => esc_html__( 'Images', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'plugin_conflicts',
				'label' => esc_html__( 'Plugin Conflicts', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'plugin_xray',
				'label' => esc_html__( 'Plugin X-Ray', 'wp-site-doctor' ),
			),
			array(
				'id'    => 'storage',
				'label' => esc_html__( 'Storage & Cleanup', 'wp-site-doctor' ),
			),
		);
	}
}
