<?php
/**
 * Plugin Name:       LibertyDev Site Migrator
 * Plugin URI:        https://github.com/ymurshid/liberty-wp-migrator
 * Description:       Site migration, manual backup, and automated scheduled backup solution by LibertyDev. Unlimited-size streaming ZIP export/import with serialization-safe domain & path replacement.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            LibertyDev
 * Author URI:        https://github.com/ymurshid
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       libertydev-site-migrator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LDSM_VERSION', '1.3.0' );
define( 'LDSM_PLUGIN_FILE', __FILE__ );
define( 'LDSM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'LDSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LDSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LDSM_TIME_BUDGET', 2.5 );

require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-i18n.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-storage.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-stream-zip-writer.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-stream-zip-reader.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-replacer.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-file-scanner.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-db-exporter.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-db-importer.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-exporter.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-importer.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-scheduler.php';
require_once LDSM_PLUGIN_DIR . 'includes/class-ldsm-ajax.php';

/**
 * Main Plugin Controller — LibertyDev Site Migrator.
 */
final class LibertyDev_Site_Migrator {

	/**
	 * Singleton instance.
	 *
	 * @var LibertyDev_Site_Migrator|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return LibertyDev_Site_Migrator
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( LDSM_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( LDSM_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . LDSM_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );

		// Soft front-end maintenance notice while an active DB/file swap is running (never blocks admin-ajax.php).
		add_action( 'template_redirect', array( $this, 'maybe_render_maintenance_mode' ), 0 );

		// Deferred rewrite flush after an import completes.
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites_after_import' ) );

		LDSM_Scheduler::init();
		LDSM_Ajax::init();
	}

	/**
	 * Ensure storage folder is hardened and reschedule active cron jobs upon activation.
	 */
	public function on_activate() {
		LDSM_Storage::ensure_storage_ready();
		LDSM_Scheduler::sync_cron_with_settings();
	}

	/**
	 * Clean temporary maintenance flag and unschedule WP-Cron hooks on deactivation.
	 */
	public function on_deactivate() {
		delete_option( 'ldsm_maintenance_active' );
		LDSM_Scheduler::clear_scheduled_hooks();
	}

	/**
	 * Add top-level LibertyDev Site Migrator menu item with official SVG mark.
	 */
	public function register_admin_menu() {
		$lang    = LDSM_I18n::get_active_lang();
		$strings = LDSM_I18n::get_strings( $lang );

		$svg          = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="black" d="M7 8h10v38h16l-10 10h-8L7 48V8zm15 0h22l13 13v22L44 56H28l10-10h2l7-7V25l-7-7H22V8zm10 14.5l8.2 4.75L32 32l-8.2-4.75L32 22.5zm-8.2 4.75L32 32v9.5l-8.2-4.75v-9.5zm8.2 4.75l8.2-4.75v9.5L32 41.5V32z"/></svg>';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by WordPress add_menu_page() data:image/svg+xml;base64 menu icon specification.
		$icon_data_uri = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		add_menu_page(
			$strings['plugin_title'],
			$strings['menu_title'],
			'manage_options',
			'libertydev-site-migrator',
			array( $this, 'render_admin_page' ),
			$icon_data_uri,
			76
		);
	}

	/**
	 * Add quick dashboard link in Plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$lang    = LDSM_I18n::get_active_lang();
		$strings = LDSM_I18n::get_strings( $lang );
		$url     = admin_url( 'admin.php?page=libertydev-site-migrator' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '" style="font-weight:700;color:#E07020;">' . esc_html( $strings['open_migrator'] ) . '</a>'
		);
		return $links;
	}

	/**
	 * Enqueue CSS and JS only on LibertyDev Site Migrator's admin screen.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_libertydev-site-migrator' !== $hook ) {
			return;
		}

		LDSM_Storage::ensure_storage_ready();
		LDSM_Storage::cleanup_stale_jobs();

		wp_enqueue_style(
			'ldsm-admin-css',
			LDSM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LDSM_VERSION
		);

		wp_enqueue_script(
			'ldsm-admin-js',
			LDSM_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			LDSM_VERSION,
			true
		);

		$max_upload  = $this->detect_safe_chunk_size();
		$active_lang = LDSM_I18n::get_active_lang();

		wp_localize_script(
			'ldsm-admin-js',
			'LDSM_CONFIG',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'ldsm_ajax_nonce' ),
				'chunkSize'     => $max_upload,
				'activeLang'    => $active_lang,
				'siteUrl'       => site_url(),
				'homeUrl'       => home_url(),
				'wpVersion'     => get_bloginfo( 'version' ),
				'phpVersion'    => PHP_VERSION,
				'tablePrefix'   => $GLOBALS['wpdb']->prefix,
				'maxServerPost' => size_format( wp_max_upload_size() ),
				'backups'       => LDSM_Storage::list_backups(),
				'schedule'      => LDSM_Scheduler::get_status_payload(),
				'i18n'          => array(
					'ar' => LDSM_I18n::get_strings( 'ar' ),
					'en' => LDSM_I18n::get_strings( 'en' ),
				),
			)
		);
	}

	/**
	 * Compute a safe upload chunk size strictly below PHP upload_max_filesize and post_max_size.
	 *
	 * @return int Bytes per chunk (between 256 KB and 2 MB).
	 */
	private function detect_safe_chunk_size() {
		$wp_max = (int) wp_max_upload_size();
		if ( $wp_max <= 0 ) {
			$wp_max = 2 * 1024 * 1024;
		}
		$safe = (int) floor( $wp_max * 0.75 );
		$cap  = 2 * 1024 * 1024;
		$min  = 256 * 1024;
		return max( $min, min( $cap, $safe ) );
	}

	/**
	 * Render the admin UI template.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'libertydev-site-migrator' ) );
		}
		include LDSM_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Display a neutral maintenance notice to front-end visitors while an import is swapping files/DB.
	 * Contains zero external links or credits (Guideline 10 compliant).
	 */
	public function maybe_render_maintenance_mode() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
		$ts = (int) get_option( 'ldsm_maintenance_active', 0 );
		if ( $ts <= 0 ) {
			return;
		}
		if ( ( time() - $ts ) > 900 ) {
			delete_option( 'ldsm_maintenance_active' );
			return;
		}

		header( 'Retry-After: 120' );
		wp_die(
			esc_html__( 'We are currently updating the site. Please check back in a moment.', 'libertydev-site-migrator' ),
			esc_html__( 'Briefly Unavailable for Scheduled Maintenance', 'libertydev-site-migrator' ),
			array( 'response' => 503 )
		);
	}

	/**
	 * Flush rewrite rules cleanly on the next admin load after a migration finishes.
	 */
	public function maybe_flush_rewrites_after_import() {
		if ( get_option( 'ldsm_needs_rewrite_flush' ) ) {
			delete_option( 'ldsm_needs_rewrite_flush' );
			flush_rewrite_rules( true );
		}
	}
}

LibertyDev_Site_Migrator::instance();
