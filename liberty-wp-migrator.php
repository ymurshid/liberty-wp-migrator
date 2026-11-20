<?php
/**
 * Plugin Name:       Liberty WP Migrator
 * Plugin URI:        https://github.com/ymurshid/liberty-wp-migrator
 * Description:       Enterprise-grade WordPress migration, manual backup, and automated scheduled backup plugin by LibertyDev. Unlimited-size streaming ZIP export/import with serialization-safe domain & path replacement.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            LibertyDev
 * Author URI:        https://github.com/ymurshid
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       liberty-wp-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LWPM_VERSION', '1.1.0' );
define( 'LWPM_PLUGIN_FILE', __FILE__ );
define( 'LWPM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'LWPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LWPM_TIME_BUDGET', 2.5 ); // Interactive AJAX tick time budget in seconds.

require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-i18n.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-storage.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-stream-zip-writer.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-stream-zip-reader.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-replacer.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-file-scanner.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-db-exporter.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-db-importer.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-exporter.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-importer.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-scheduler.php';
require_once LWPM_PLUGIN_DIR . 'includes/class-lwpm-ajax.php';

/**
 * Main Plugin Controller — Liberty WP Migrator by LibertyDev.
 */
final class Liberty_WP_Migrator {

	/**
	 * Singleton instance.
	 *
	 * @var Liberty_WP_Migrator|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Liberty_WP_Migrator
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
		register_activation_hook( LWPM_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( LWPM_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . LWPM_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );

		// Soft front-end maintenance notice while an active DB/file swap is running (never blocks admin-ajax.php).
		add_action( 'template_redirect', array( $this, 'maybe_render_maintenance_mode' ), 0 );

		// Deferred rewrite flush after an import completes.
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites_after_import' ) );

		LWPM_Scheduler::init();
		LWPM_Ajax::init();
	}

	/**
	 * Ensure storage folder is hardened and reschedule active cron jobs upon activation.
	 */
	public function on_activate() {
		LWPM_Storage::ensure_storage_ready();
		LWPM_Scheduler::sync_cron_with_settings();
	}

	/**
	 * Clean temporary maintenance flag and unschedule WP-Cron hooks on deactivation.
	 */
	public function on_deactivate() {
		delete_option( 'lwpm_maintenance_active' );
		LWPM_Scheduler::clear_scheduled_hooks();
	}

	/**
	 * Add top-level LibertyDev Migrator menu item with official SVG mark.
	 */
	public function register_admin_menu() {
		$lang    = LWPM_I18n::get_active_lang();
		$strings = LWPM_I18n::get_strings( $lang );

		// Official LibertyDev geometric L+D cube mark encoded as base64 SVG (fill="black" lets WP Admin colorize it).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="black" d="M7 8h10v38h16l-10 10h-8L7 48V8zm15 0h22l13 13v22L44 56H28l10-10h2l7-7V25l-7-7H22V8zm10 14.5l8.2 4.75L32 32l-8.2-4.75L32 22.5zm-8.2 4.75L32 32v9.5l-8.2-4.75v-9.5zm8.2 4.75l8.2-4.75v9.5L32 41.5V32z"/></svg>';
		$icon_data_uri = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		add_menu_page(
			$strings['plugin_title'],
			$strings['menu_title'],
			'manage_options',
			'liberty-wp-migrator',
			array( $this, 'render_admin_page' ),
			$icon_data_uri,
			76
		);
	}

	/**
	 * Add quick settings/dashboard link in Plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$lang    = LWPM_I18n::get_active_lang();
		$strings = LWPM_I18n::get_strings( $lang );
		$url     = admin_url( 'admin.php?page=liberty-wp-migrator' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '" style="font-weight:700;color:#E07020;">' . esc_html( $strings['open_migrator'] ) . '</a>'
		);
		return $links;
	}

	/**
	 * Enqueue CSS and JS only on Liberty WP Migrator's admin screen.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_liberty-wp-migrator' !== $hook ) {
			return;
		}

		LWPM_Storage::ensure_storage_ready();
		LWPM_Storage::cleanup_stale_jobs();

		wp_enqueue_style(
			'lwpm-admin-css',
			LWPM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LWPM_VERSION
		);

		wp_enqueue_script(
			'lwpm-admin-js',
			LWPM_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			LWPM_VERSION,
			true
		);

		$max_upload  = $this->detect_safe_chunk_size();
		$active_lang = LWPM_I18n::get_active_lang();

		wp_localize_script(
			'lwpm-admin-js',
			'LWPM_CONFIG',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'lwpm_ajax_nonce' ),
				'chunkSize'     => $max_upload,
				'activeLang'    => $active_lang,
				'siteUrl'       => site_url(),
				'homeUrl'       => home_url(),
				'wpVersion'     => get_bloginfo( 'version' ),
				'phpVersion'    => PHP_VERSION,
				'tablePrefix'   => $GLOBALS['wpdb']->prefix,
				'maxServerPost' => size_format( wp_max_upload_size() ),
				'storagePath'   => LWPM_Storage::get_backups_dir(),
				'backups'       => LWPM_Storage::list_backups(),
				'schedule'      => LWPM_Scheduler::get_status_payload(),
				'i18n'          => array(
					'ar' => LWPM_I18n::get_strings( 'ar' ),
					'en' => LWPM_I18n::get_strings( 'en' ),
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'liberty-wp-migrator' ) );
		}
		include LWPM_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Display a branded maintenance screen to front-end visitors while an import is actively swapping files/DB.
	 */
	public function maybe_render_maintenance_mode() {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
		$ts = (int) get_option( 'lwpm_maintenance_active', 0 );
		if ( $ts <= 0 ) {
			return;
		}
		if ( ( time() - $ts ) > 900 ) {
			delete_option( 'lwpm_maintenance_active' );
			return;
		}

		status_header( 503 );
		header( 'Retry-After: 120' );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Site Maintenance</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0B1524;color:#F8FAFC;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;text-align:center}.box{background:#10243C;border:1px solid rgba(224,112,32,.35);padding:40px;border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,.35);max-width:480px}h1{font-size:22px;margin:0 0 12px;color:#F8FAFC}p{color:#94A3B8;line-height:1.6;margin:0}</style></head>';
		echo '<body><div class="box"><h1>Briefly Unavailable for Scheduled Maintenance</h1><p>We are currently updating the site. Please check back in a moment.<br>الموقع في وضع التحديث حالياً، يرجى المحاولة بعد لحظات.</p></div></body></html>';
		exit;
	}

	/**
	 * Flush rewrite rules cleanly on the next admin load after a migration finishes.
	 */
	public function maybe_flush_rewrites_after_import() {
		if ( get_option( 'lwpm_needs_rewrite_flush' ) ) {
			delete_option( 'lwpm_needs_rewrite_flush' );
			flush_rewrite_rules( true );
		}
	}
}

Liberty_WP_Migrator::instance();
