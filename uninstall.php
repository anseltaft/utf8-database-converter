<?php
/**
 * Uninstall handler for UTF-8 Database Converter.
 *
 * Removes the activation-timestamp option and any leftover backup archives,
 * SQL dumps, and debug logs (in case deactivation never ran).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'utf8dc_activated_at' );
delete_transient( 'utf8dc_backup_' . get_current_user_id() );

$utf8dc_dir = trailingslashit( WP_CONTENT_DIR ) . 'utf8dc-backups/';
if ( is_dir( $utf8dc_dir ) ) {
	foreach ( glob( $utf8dc_dir . '*' ) ?: array() as $utf8dc_file ) {
		if ( is_file( $utf8dc_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $utf8dc_file );
		}
	}
	foreach ( glob( $utf8dc_dir . '.*' ) ?: array() as $utf8dc_file ) {
		if ( is_file( $utf8dc_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $utf8dc_file );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $utf8dc_dir );
}
