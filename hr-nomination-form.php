<?php
/**
 * Plugin Name:       HR Nomination Form
 * Plugin URI:        https://thehrreview.com/nominate/
 * Description:       Fixes and handles HR nomination HTML forms, captures submissions via admin-post, sends HTML emails with attached CSV, provides database logging with CSV/Excel exports, and supports automated updates.
 * Version:           1.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Harshvardhan Kumar (Krish Goswami)
 * Author URI:        https://thehrreview.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hr-nomination-form
 * Domain Path:       /languages
 *
 * @package           HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'HR_NOMINATION_VERSION', '1.0.4' );
define( 'HR_NOMINATION_PLUGIN_FILE', __FILE__ );
define( 'HR_NOMINATION_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'HR_NOMINATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HR_NOMINATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include core classes
require_once HR_NOMINATION_PLUGIN_DIR . 'includes/class-database.php';
require_once HR_NOMINATION_PLUGIN_DIR . 'includes/class-form-handler.php';
require_once HR_NOMINATION_PLUGIN_DIR . 'includes/class-frontend.php';
require_once HR_NOMINATION_PLUGIN_DIR . 'includes/class-admin.php';
require_once HR_NOMINATION_PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Main Plugin Bootstrap Class.
 */
class HR_Nomination_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var HR_Nomination_Plugin|null
	 */
	private static ?HR_Nomination_Plugin $instance = null;

	/**
	 * Form handler instance.
	 *
	 * @var HR_Nomination_Form_Handler
	 */
	public HR_Nomination_Form_Handler $form_handler;

	/**
	 * Frontend assets loader instance.
	 *
	 * @var HR_Nomination_Frontend
	 */
	public HR_Nomination_Frontend $frontend;

	/**
	 * Admin manager instance.
	 *
	 * @var HR_Nomination_Admin
	 */
	public HR_Nomination_Admin $admin;

	/**
	 * Auto updater instance.
	 *
	 * @var HR_Nomination_Updater
	 */
	public HR_Nomination_Updater $updater;

	/**
	 * Get singleton instance.
	 *
	 * @return HR_Nomination_Plugin
	 */
	public static function get_instance(): HR_Nomination_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin components.
	 */
	private function init(): void {
		// Load text domain for translations
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Ensure database schema is ready immediately
		HR_Nomination_Database::ensure_table_exists();

		// Instantiate modules
		$this->form_handler = new HR_Nomination_Form_Handler();
		$this->frontend     = new HR_Nomination_Frontend();
		$this->admin        = new HR_Nomination_Admin();
		$this->updater      = new HR_Nomination_Updater( HR_NOMINATION_PLUGIN_FILE, HR_NOMINATION_VERSION );

		// Add quick links in Plugins list
		add_filter( 'plugin_action_links_' . HR_NOMINATION_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Load translation text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'hr-nomination-form',
			false,
			dirname( HR_NOMINATION_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Add custom links to plugin listing in wp-admin.
	 *
	 * @param array $links Default links.
	 * @return array
	 */
	public function add_plugin_action_links( array $links ): array {
		$custom_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=hr-nominations' ) ) . '">' . esc_html__( 'Submissions', 'hr-nomination-form' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=hr-nomination-settings' ) ) . '">' . esc_html__( 'Settings', 'hr-nomination-form' ) . '</a>',
		);
		return array_merge( $custom_links, $links );
	}

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		HR_Nomination_Database::ensure_table_exists();

		if ( false === get_option( 'hr_recipient_email' ) ) {
			add_option( 'hr_recipient_email', get_option( 'admin_email' ) );
		}
		if ( false === get_option( 'hr_subject_template' ) ) {
			add_option( 'hr_subject_template', 'New HR Nomination: {company_name} - {category}' );
		}
		if ( false === get_option( 'hr_from_name' ) ) {
			add_option( 'hr_from_name', get_bloginfo( 'name' ) );
		}
		if ( false === get_option( 'hr_from_email' ) ) {
			add_option( 'hr_from_email', get_option( 'admin_email' ) );
		}
		if ( false === get_option( 'hr_csv_attachment' ) ) {
			add_option( 'hr_csv_attachment', 1 );
		}
		if ( false === get_option( 'hr_db_storage' ) ) {
			add_option( 'hr_db_storage', 1 );
		}
	}

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		delete_transient( 'hr_gh_rel_' . md5( 'Hunter28-lucky/Work_HR_new_Plugin' ) );
	}
}

// Register activation and deactivation hooks
register_activation_hook( HR_NOMINATION_PLUGIN_FILE, array( 'HR_Nomination_Plugin', 'activate' ) );
register_deactivation_hook( HR_NOMINATION_PLUGIN_FILE, array( 'HR_Nomination_Plugin', 'deactivate' ) );

// Initialize plugin
function hr_nomination_form_init(): HR_Nomination_Plugin {
	return HR_Nomination_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'hr_nomination_form_init' );
