<?php
/**
 * Uninstall handler for Liberty WP Migrator by LibertyDev.
 *
 * Unschedules WP-Cron events, deletes temporary job workspaces and plugin options,
 * while preserving completed .zip backups unless the user already deleted them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'lwpm_run_scheduled_backup' );
wp_clear_scheduled_hook( 'lwpm_continue_scheduled_backup' );

delete_option( 'lwpm_maintenance_active' );
delete_option( 'lwpm_needs_rewrite_flush' );
delete_option( 'lwpm_schedule_settings' );
delete_option( 'lwpm_schedule_status' );
delete_transient( 'lwpm_bg_runner_lock' );

$backups_dir = WP_CONTENT_DIR . '/lwpm-backups';
$jobs_dir    = $backups_dir . '/jobs';

/**
 * Recursively remove a directory.
 *
 * @param string $dir Directory path.
 */
function lwpm_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = @scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			lwpm_uninstall_rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

lwpm_uninstall_rrmdir( $jobs_dir );

if ( is_dir( $backups_dir ) ) {
	$remaining_zips = glob( $backups_dir . '/*.zip' );
	if ( empty( $remaining_zips ) ) {
		lwpm_uninstall_rrmdir( $backups_dir );
	}
}
