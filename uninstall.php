<?php
/**
 * Uninstall handler for UTF-8 Database Converter.
 *
 * Removes the activation-timestamp option and any leftover backup archives,
 * SQL dumps, and debug logs (in case deactivation never ran).
 *
 * This file runs inside the admin-AJAX delete request: it must never emit
 * output or throw, or the Plugins screen's delete action fails silently.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

ob_start();

try {
	delete_option( 'utf8dc_activated_at' );
	delete_site_option( 'utf8dc_activated_at' );

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
} catch ( Throwable $utf8dc_e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	// Never break plugin deletion; the leftovers are harmless.
}

if ( ob_get_level() > 0 ) {
	ob_end_clean();
}
