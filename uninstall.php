<?php
/**
 * Uninstall handler for LibertyDev Site Migrator.
 *
 * Unschedules WP-Cron events, cleans temporary job workspaces and plugin options,
 * while preserving completed .zip backups unless already deleted by the administrator.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'ldsm_run_scheduled_backup' );
wp_clear_scheduled_hook( 'ldsm_continue_scheduled_backup' );

$vault_key   = get_option( 'ldsm_vault_key', '' );
$active_jobs = get_option( 'ldsm_active_jobs', array() );

if ( is_array( $active_jobs ) ) {
	foreach ( array_keys( $active_jobs ) as $jid ) {
		if ( is_string( $jid ) && '' !== $jid ) {
			delete_option( 'ldsm_job_' . $jid );
		}
	}
}

delete_option( 'ldsm_active_jobs' );
delete_option( 'ldsm_maintenance_active' );
delete_option( 'ldsm_needs_rewrite_flush' );
delete_option( 'ldsm_schedule_settings' );
delete_option( 'ldsm_schedule_status' );
delete_transient( 'ldsm_bg_runner_lock' );

$uploads = wp_upload_dir( null, false );
if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
	$base_dir = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) . '/libertydev-site-migrator';

	if ( is_dir( $base_dir ) && ! is_link( $base_dir ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( is_string( $vault_key ) && '' !== $vault_key && preg_match( '/^[a-f0-9]{16,64}$/', $vault_key ) ) {
			$vault_dir = $base_dir . '/vault-' . $vault_key;
			$jobs_dir  = $vault_dir . '/jobs';

			if ( $wp_filesystem && is_dir( $jobs_dir ) && ! is_link( $jobs_dir ) ) {
				$wp_filesystem->delete( $jobs_dir, true );
			}

			$remaining_zips = glob( $vault_dir . '/*.zip' );
			if ( empty( $remaining_zips ) ) {
				delete_option( 'ldsm_vault_key' );
				if ( $wp_filesystem ) {
					$wp_filesystem->delete( $base_dir, true );
				}
			}
		}
	}
}
