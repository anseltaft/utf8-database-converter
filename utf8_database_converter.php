<?php
/**
 * Plugin Name:       UTF-8 Database Converter
 * Description:       Backs up your database (as a compressed SQL file), converts tables and columns to utf8mb4, and converts MyISAM tables to InnoDB. Remove this plugin when finished.
 * Version:           1.0.1
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Ansel Taft
 * Author URI:        https://anseltaft.com/
 * License:           GPL-3.0-only
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       utf8-database-converter
 */

/*
 * UTF-8 Database Converter
 *
 * Original work Copyright (C) 2009-2011 Yihui Xie <xie@yihui.name>
 * Modernization    Copyright (C) 2026 UTF-8 Database Converter contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License only.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UTF8_Database_Converter {

	const MENU_SLUG        = 'utf8-database-converter';
	const NONCE_BACKUP     = 'utf8dc_backup';
	const NONCE_DOWNLOAD   = 'utf8dc_download';
	const NONCE_CHARSET    = 'utf8dc_charset';
	const NONCE_ENGINE     = 'utf8dc_engine';
	const NONCE_LOG        = 'utf8dc_log';
	const LOG_FILE         = 'debug.log';
	const TARGET_CHARSET   = 'utf8mb4';
	const TARGET_COLLATION = 'utf8mb4_unicode_ci';
	const COLLATION_CHOICES = array( 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci' );
	const TARGET_ENGINE    = 'InnoDB';
	const BACKUP_TTL       = 600; // 10 minutes.
	const REMINDER_DELAY   = 1800; // 30 minutes.
	const OPTION_ACTIVATED = 'utf8dc_activated_at';
	const DUMP_CHUNK_ROWS  = 500;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_network_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'reminder_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'reminder_notice' ) );
		add_action( 'admin_post_utf8dc_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_utf8dc_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_utf8dc_charset', array( __CLASS__, 'handle_charset' ) );
		add_action( 'admin_post_utf8dc_engine', array( __CLASS__, 'handle_engine' ) );
		add_action( 'admin_post_utf8dc_log', array( __CLASS__, 'handle_log_download' ) );
		add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		add_action( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
	}

	/** Capability required everywhere: super admin on multisite, admin otherwise. */
	private static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/** Base admin URL for the context the plugin screen lives in. */
	private static function admin_base(): string {
		return is_multisite() ? network_admin_url() : admin_url();
	}

	private static function plugins_url(): string {
		return self::admin_base() . 'plugins.php';
	}

	private static function admin_post_url(): string {
		return self::admin_base() . 'admin-post.php';
	}

	/** Records the activation time so the reminder notice can fire 30 minutes later. */
	public static function activate(): void {
		update_site_option( self::OPTION_ACTIVATED, time() );
	}

	/**
	 * Admin-area reminder (never runs on the front end): if the plugin has been
	 * active for more than 30 minutes, nudge the admin to deactivate and delete it.
	 */
	public static function reminder_notice(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$activated = (int) get_site_option( self::OPTION_ACTIVATED, 0 );
		if ( ! $activated || ( time() - $activated ) < self::REMINDER_DELAY ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return; // The plugin's own page already shows removal reminders.
		}

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: URL of the Plugins screen */
			wp_kses_post( __( '<strong>UTF-8 Database Converter</strong> is still installed and activated. It is a one-off tool — once your conversion is done, please <a href="%s">deactivate and delete it</a>.', 'utf8-database-converter' ) ),
			esc_url( self::plugins_url() )
		);
		echo '</p></div>';
	}

	public static function register_menu(): void {
		if ( is_multisite() ) {
			return; // On multisite the plugin lives in Network Admin only.
		}
		add_management_page(
			__( 'UTF-8 Database Converter', 'utf8-database-converter' ),
			__( 'UTF-8 DB Converter', 'utf8-database-converter' ),
			self::capability(),
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_network_menu(): void {
		add_menu_page(
			__( 'UTF-8 Database Converter', 'utf8-database-converter' ),
			__( 'UTF-8 DB Converter', 'utf8-database-converter' ),
			self::capability(),
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			self::menu_icon(),
			99 // Bottom of the menu.
		);
	}

	/** Inline SVG menu icon: the number 8. */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><text x="10" y="14.5" font-family="Arial,Helvetica,sans-serif" font-size="14" font-weight="bold" text-anchor="middle" fill="#a7aaad">8</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Convert', 'utf8-database-converter' ) . '</a>' );
		return $links;
	}

	public static function deactivate(): void {
		self::log( 'Plugin deactivated — deleting backup archives and the debug log.' );
		self::cleanup_backups();
		delete_site_option( self::OPTION_ACTIVATED );
	}

	/* --------------------------------------------------------------------- *
	 * Data helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Returns metadata for every table in the WordPress database.
	 *
	 * @return array<int, array{name:string, engine:string, collation:string, type:string}>
	 */
	private static function tables_info(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation, TABLE_TYPE AS type
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s
				 ORDER BY TABLE_NAME',
				DB_NAME
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** Quotes an SQL identifier (table/column name) with backticks. */
	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Classifies a table collation:
	 * - 'good':   already utf8mb4_unicode_ci or utf8mb4_unicode_520_ci — an accepted target, nothing to do.
	 * - 'ok':     utf8mb4 with a different collation (e.g. utf8mb4_general_ci) — fine, optionally convertible.
	 * - 'legacy': anything else (utf8, latin1, …) — should be converted.
	 */
	private static function collation_state( string $collation ): string {
		if ( in_array( $collation, self::COLLATION_CHOICES, true ) ) {
			return 'good';
		}
		if ( '' !== $collation && 0 === strpos( $collation, self::TARGET_CHARSET . '_' ) ) {
			return 'ok';
		}
		return 'legacy';
	}

	/**
	 * Validates a user-supplied list of table names against the real tables
	 * in the database. Anything not actually present is silently dropped.
	 *
	 * @param array<int|string, mixed> $selected Raw selection from the request.
	 * @return string[] Valid table names.
	 */
	private static function validate_selected_tables( $selected ): array {
		if ( ! is_array( $selected ) ) {
			return array();
		}
		$valid = array_column( self::tables_info(), 'name' );
		$out   = array();
		foreach ( $selected as $table ) {
			$table = sanitize_text_field( wp_unslash( (string) $table ) );
			if ( in_array( $table, $valid, true ) ) {
				$out[] = $table;
			}
		}
		return $out;
	}

	private static function page_url(): string {
		if ( is_multisite() ) {
			return network_admin_url( 'admin.php?page=' . self::MENU_SLUG );
		}
		return admin_url( 'tools.php?page=' . self::MENU_SLUG );
	}

	private static function require_capability(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'utf8-database-converter' ),
				esc_html__( 'Forbidden', 'utf8-database-converter' ),
				array( 'response' => 403 )
			);
		}
	}

	private static function raise_limits(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Backup
	 * --------------------------------------------------------------------- */

	/** Creates (and protects) the directory used for backup files. */
	private static function backup_dir(): string {
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'utf8dc-backups/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) ) {
			if ( ! file_exists( $dir . '.htaccess' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" );
			}
			if ( ! file_exists( $dir . 'index.php' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			}
		}
		return $dir;
	}

	private static function cleanup_backups(): void {
		$dir = self::backup_dir();
		foreach ( glob( $dir . '*.{zip,sql,log}', GLOB_BRACE ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/** Deletes leftover backup archives whose download window has expired. */
	private static function sweep_expired_backups(): void {
		$dir = self::backup_dir();
		foreach ( glob( $dir . '*.zip' ) ?: array() as $file ) {
			$mtime = is_file( $file ) ? filemtime( $file ) : false;
			if ( $mtime && $mtime < time() - self::BACKUP_TTL ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}

	/* --------------------------------------------------------------------- *
	 * Debug log
	 * --------------------------------------------------------------------- */

	private static function log_path(): string {
		return self::backup_dir() . self::LOG_FILE;
	}

	/** Appends one timestamped line to the debug log (never throws). */
	private static function log( string $message ): void {
		$line = gmdate( 'Y-m-d H:i:s' ) . ' UTC [user ' . get_current_user_id() . '] ' . $message . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( self::log_path(), $line, FILE_APPEND | LOCK_EX );
	}

	/** Logs environment details at the start of every operation. */
	private static function log_environment( string $operation ): void {
		global $wpdb, $wp_version;
		self::log( sprintf(
			'--- %s started (WordPress %s, PHP %s, MySQL/MariaDB %s, memory_limit %s, max_execution_time %s) ---',
			$operation,
			$wp_version,
			PHP_VERSION,
			$wpdb->get_var( 'SELECT VERSION()' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			(string) ini_get( 'memory_limit' ),
			(string) ini_get( 'max_execution_time' )
		) );
	}

	/** Clears any open output buffers so streamed downloads are not corrupted. */
	private static function clean_output_buffers(): void {
		while ( ob_get_level() > 0 && ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** admin-post handler: download the debug log as a text file. */
	public static function handle_log_download(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_LOG );

		$path = self::log_path();
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'No debug log exists yet. Run a backup or conversion first.', 'utf8-database-converter' ), '', array( 'response' => 404 ) );
		}

		self::clean_output_buffers();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="utf8dc-debug.log"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Writes a full SQL dump of the database to a temporary file.
	 *
	 * @return string|WP_Error Path to the dump file, or WP_Error on failure.
	 */
	private static function write_sql_dump() {
		global $wpdb;

		$tmp = wp_tempnam( sanitize_file_name( DB_NAME ) . '.sql' );
		if ( ! $tmp ) {
			return new WP_Error( 'utf8dc_tmp', __( 'Could not create a temporary file for the SQL dump.', 'utf8-database-converter' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = @fopen( $tmp, 'wb' );
		if ( ! $fh ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			return new WP_Error( 'utf8dc_fopen', __( 'Could not open the temporary file for writing.', 'utf8-database-converter' ) );
		}

		$header  = '-- WordPress database backup generated by UTF-8 Database Converter' . "\n";
		$header .= '-- Database: ' . DB_NAME . "\n";
		$header .= '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		$header .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
		fwrite( $fh, $header );

		$dumped  = 0;
		$skipped = 0;
		foreach ( self::tables_info() as $info ) {
			$table  = $info['name'];
			$quoted = self::quote_identifier( $table );

			$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $create ) && isset( $create[1] ) ) {
				fwrite( $fh, "\n\n-- --------------------------------------------------------\n-- Structure for table {$quoted}\n-- --------------------------------------------------------\n\n" );
				fwrite( $fh, "DROP TABLE IF EXISTS {$quoted};\n" . $create[1] . ";\n" );
			} else {
				$skipped++;
				self::log( "WARNING: could not read the structure of {$table} ({$wpdb->last_error}) — it is missing from this backup." );
			}

			$offset = 0;
			while ( true ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( "SELECT * FROM {$quoted} LIMIT {$offset}, " . self::DUMP_CHUNK_ROWS, ARRAY_A );
				if ( ! is_array( $rows ) ) {
					$skipped++;
					self::log( "WARNING: failed reading rows from {$table} at row {$offset} ({$wpdb->last_error}) — the backup of this table is INCOMPLETE." );
					break;
				}
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						$values[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
					}
					fwrite( $fh, "\nINSERT INTO {$quoted} VALUES (" . implode( ',', $values ) . ');' );
				}
				if ( count( $rows ) < self::DUMP_CHUNK_ROWS ) {
					break;
				}
				$offset += self::DUMP_CHUNK_ROWS;
			}
			$dumped++;
			fwrite( $fh, "\n" );
		}

		fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fh );

		self::log( sprintf( 'SQL dump covers %d tables; %d problem(s) encountered (see WARNING lines above, if any).', $dumped, $skipped ) );

		return $tmp;
	}

	/** admin-post handler: build the dump, zip it, redirect to the download URL. */
	public static function handle_backup(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_BACKUP );
		self::raise_limits();
		self::log_environment( 'Backup' );

		try {
			if ( ! class_exists( 'ZipArchive' ) ) {
				self::log( 'Backup aborted: the PHP Zip extension (ZipArchive) is not available.' );
				wp_die( esc_html__( 'The PHP Zip extension (ZipArchive) is required to create compressed backups. Please ask your host to enable it.', 'utf8-database-converter' ) );
			}

			self::sweep_expired_backups();

			self::log( 'Writing SQL dump to a temporary file…' );
			$sql_path = self::write_sql_dump();
			if ( is_wp_error( $sql_path ) ) {
				self::log( 'Backup failed: ' . $sql_path->get_error_message() );
				wp_die( esc_html( $sql_path->get_error_message() ) );
			}
			self::log( 'SQL dump written (' . size_format( (float) filesize( $sql_path ) ) . '). Compressing…' );

			$zip_name = sanitize_file_name( DB_NAME ) . '.sql.zip';
			$zip_path = self::backup_dir() . wp_generate_password( 20, false, false ) . '-' . $zip_name;

			$zip    = new ZipArchive();
			$opened = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			if ( true !== $opened ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $sql_path );
				self::log( 'Backup failed: could not create the ZIP archive (ZipArchive::open returned ' . $opened . ').' );
				wp_die( esc_html__( 'Could not create the ZIP archive in wp-content/utf8dc-backups/. Please check directory permissions.', 'utf8-database-converter' ) );
			}
			$zip->addFile( $sql_path, sanitize_file_name( DB_NAME ) . '.sql' );
			$zip->close();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $sql_path );
			self::log( 'Backup archive created (' . size_format( (float) filesize( $zip_path ) ) . '); starting download.' );

			set_site_transient( self::download_key(), $zip_path, self::BACKUP_TTL );

			wp_safe_redirect(
				add_query_arg(
					array(
						'action'   => 'utf8dc_download',
						'_wpnonce' => wp_create_nonce( self::NONCE_DOWNLOAD ),
					),
					self::admin_post_url()
				)
			);
			exit;
		} catch ( Throwable $e ) {
			self::log( 'FATAL during backup: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			wp_die( esc_html__( 'The backup failed with a fatal error. Download the debug log from the plugin page for details — nothing after the last log line was completed.', 'utf8-database-converter' ) );
		}
	}

	private static function download_key(): string {
		return 'utf8dc_backup_' . get_current_user_id();
	}

	/** admin-post handler: stream the prepared zip, then delete it. */
	public static function handle_download(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_DOWNLOAD );

		$path     = get_site_transient( self::download_key() );
		$real_dir = realpath( self::backup_dir() );

		$valid = is_string( $path )
			&& false !== $real_dir
			&& file_exists( $path )
			&& is_file( $path )
			&& 0 === strpos( (string) realpath( $path ), $real_dir );

		if ( ! $valid ) {
			wp_die( esc_html__( 'Backup not found or download link expired. Please create the backup again.', 'utf8-database-converter' ), '', array( 'response' => 404 ) );
		}

		self::raise_limits();
		self::clean_output_buffers();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( DB_NAME ) . '.sql.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
		delete_site_transient( self::download_key() );
		self::log( 'Backup archive downloaded by the browser and deleted from the server.' );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Conversions
	 * --------------------------------------------------------------------- */

	/**
	 * Converts the given tables to utf8mb4 with the given collation.
	 *
	 * @param string[] $tables    Validated table names.
	 * @param string   $collation One of self::COLLATION_CHOICES.
	 * @return array<int, array{table:string, ok:bool, message:string}>
	 */
	private static function convert_charset( array $tables, string $collation ): array {
		global $wpdb;

		$results = array();
		foreach ( $tables as $table ) {
			$sql = sprintf(
				'ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s',
				self::quote_identifier( $table ),
				self::TARGET_CHARSET,
				$collation
			);
			self::log( "Running: {$sql}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ok        = false !== $wpdb->query( $sql );
			$message   = $ok ? '' : $wpdb->last_error;
			self::log( $ok ? "OK: {$table} converted to " . self::TARGET_CHARSET . ' / ' . $collation : "FAILED: {$table} — {$message}" );
			$results[] = array(
				'table'   => $table,
				'ok'      => $ok,
				'message' => $message,
			);
		}
		return $results;
	}

	/**
	 * Converts the given tables to InnoDB.
	 *
	 * @param string[] $tables Validated table names.
	 * @return array<int, array{table:string, ok:bool, message:string}>
	 */
	private static function convert_engine( array $tables ): array {
		global $wpdb;
		$myisam  = array();
		foreach ( self::tables_info() as $info ) {
			if ( 'myisam' === strtolower( (string) $info['engine'] ) ) {
				$myisam[] = $info['name'];
			}
		}

		$results = array();
		foreach ( $tables as $table ) {
			if ( ! in_array( $table, $myisam, true ) ) {
				continue; // Only actual MyISAM tables may be converted.
			}
			$sql = 'ALTER TABLE ' . self::quote_identifier( $table ) . ' ENGINE=' . self::TARGET_ENGINE;
			self::log( "Running: {$sql}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ok        = false !== $wpdb->query( $sql );
			$message   = $ok ? '' : $wpdb->last_error;
			self::log( $ok ? "OK: {$table} converted to " . self::TARGET_ENGINE : "FAILED: {$table} — {$message}" );
			$results[] = array(
				'table'   => $table,
				'ok'      => $ok,
				'message' => $message,
			);
		}
		return $results;
	}

	public static function handle_charset(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_CHARSET );
		self::raise_limits();
		self::log_environment( 'Character set conversion' );

		try {
			$collation = isset( $_POST['utf8dc_collation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['utf8dc_collation'] ) ) : '';
			if ( ! in_array( $collation, self::COLLATION_CHOICES, true ) ) {
				$collation = self::TARGET_COLLATION;
			}
			self::log( 'Chosen collation: ' . $collation );
			$tables  = isset( $_POST['utf8dc_tables'] ) ? self::validate_selected_tables( $_POST['utf8dc_tables'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			self::log( 'Tables selected for character set conversion: ' . ( $tables ? implode( ', ', $tables ) : '(none)' ) );
			$results = empty( $tables ) ? array() : self::convert_charset( $tables, $collation );
			self::log( 'Character set conversion finished: ' . count( array_filter( $results, fn( $r ) => $r['ok'] ) ) . ' succeeded, ' . count( array_filter( $results, fn( $r ) => ! $r['ok'] ) ) . ' failed.' );

			set_site_transient( 'utf8dc_results_' . get_current_user_id(), array( 'type' => 'charset', 'collation' => $collation, 'results' => $results ), MINUTE_IN_SECONDS * 5 );
			wp_safe_redirect( add_query_arg( 'utf8dc_done', 'charset', self::page_url() ) );
			exit;
		} catch ( Throwable $e ) {
			self::log( 'FATAL during character set conversion: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			wp_die( esc_html__( 'The conversion failed with a fatal error. Download the debug log from the plugin page — every table listed after the last "OK:" line was NOT converted.', 'utf8-database-converter' ) );
		}
	}

	public static function handle_engine(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_ENGINE );
		self::raise_limits();
		self::log_environment( 'Engine conversion' );

		try {
			$tables  = isset( $_POST['utf8dc_tables'] ) ? self::validate_selected_tables( $_POST['utf8dc_tables'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			self::log( 'Tables selected for engine conversion: ' . ( $tables ? implode( ', ', $tables ) : '(none)' ) );
			$results = empty( $tables ) ? array() : self::convert_engine( $tables );
			self::log( 'Engine conversion finished: ' . count( array_filter( $results, fn( $r ) => $r['ok'] ) ) . ' succeeded, ' . count( array_filter( $results, fn( $r ) => ! $r['ok'] ) ) . ' failed.' );

			set_site_transient( 'utf8dc_results_' . get_current_user_id(), array( 'type' => 'engine', 'results' => $results ), MINUTE_IN_SECONDS * 5 );
			wp_safe_redirect( add_query_arg( 'utf8dc_done', 'engine', self::page_url() ) );
			exit;
		} catch ( Throwable $e ) {
			self::log( 'FATAL during engine conversion: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			wp_die( esc_html__( 'The conversion failed with a fatal error. Download the debug log from the plugin page — every table listed after the last "OK:" line was NOT converted.', 'utf8-database-converter' ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Admin page
	 * --------------------------------------------------------------------- */

	private static function render_results( array $data ): void {
		$results = $data['results'] ?? array();

		if ( empty( $results ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No tables were selected, so nothing was converted.', 'utf8-database-converter' ) . '</p></div>';
			return;
		}

		$errors = array_filter( $results, fn( $r ) => ! $r['ok'] );
		$class  = empty( $errors ) ? 'notice-success' : 'notice-error';
		$title  = 'charset' === ( $data['type'] ?? '' )
			/* translators: %s: target collation, e.g. utf8mb4_unicode_ci */
			? sprintf( __( 'Character set conversion results (target: %s)', 'utf8-database-converter' ), (string) ( $data['collation'] ?? self::TARGET_COLLATION ) )
			/* translators: %s: target storage engine, e.g. InnoDB */
			: sprintf( __( 'Engine conversion results (target: %s)', 'utf8-database-converter' ), self::TARGET_ENGINE );

		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html( $title ) . '</strong></p><ul style="list-style:disc;margin-left:2em;">';
		foreach ( $results as $r ) {
			if ( $r['ok'] ) {
				/* translators: %s: table name */
				echo '<li>' . esc_html( sprintf( __( '%s — converted successfully.', 'utf8-database-converter' ), $r['table'] ) ) . '</li>';
			} else {
				/* translators: 1: table name, 2: MySQL error message */
				echo '<li style="color:#b32d2e;">' . esc_html( sprintf( __( '%1$s — FAILED: %2$s', 'utf8-database-converter' ), $r['table'], $r['message'] ) ) . '</li>';
			}
		}
		echo '</ul></div>';
	}

	public static function render_page(): void {
		self::require_capability();
		self::sweep_expired_backups();

		$flash = get_site_transient( 'utf8dc_results_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_site_transient( 'utf8dc_results_' . get_current_user_id() );
		}

		$tables    = self::tables_info();
		$base      = array_filter( $tables, fn( $t ) => 'BASE TABLE' === strtoupper( (string) ( $t['type'] ?? 'BASE TABLE' ) ) );
		$myisam    = array_filter( $base, fn( $t ) => 'myisam' === strtolower( (string) $t['engine'] ) );
		$pending   = array_filter( $base, fn( $t ) => 'good' !== self::collation_state( (string) $t['collation'] ) );
		$all_done  = empty( $myisam ) && empty( $pending );
		$log_exists = is_file( self::log_path() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UTF-8 Database Converter', 'utf8-database-converter' ); ?></h1>

			<?php if ( is_array( $flash ) ) : ?>
				<?php self::render_results( $flash ); ?>
			<?php endif; ?>

			<?php if ( $all_done ) : ?>
				<div class="notice notice-success" style="padding:1em;">
					<p style="font-size:15px;"><strong><?php esc_html_e( 'All done!', 'utf8-database-converter' ); ?></strong></p>
					<p><?php
						printf(
							/* translators: %s: URL of the Plugins screen */
							wp_kses_post( __( 'Every table already uses <code>utf8mb4</code> and no MyISAM tables remain. This plugin has finished its job — for security and cleanliness, please <a href="%s">deactivate and delete it now</a>.', 'utf8-database-converter' ) ),
							esc_url( self::plugins_url() )
						);
					?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-error" style="padding:.8em;">
				<p style="font-weight:bold;margin:0;">
					<?php esc_html_e( 'WARNING: schema changes can lose data if interrupted. Back up your database first (Section 1), and consider putting the site in maintenance mode while converting.', 'utf8-database-converter' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Section 1 — Back up the entire database', 'utf8-database-converter' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: backup file name, e.g. wordpress.sql.zip */
					esc_html__( 'Downloads a complete SQL dump of every table in this database, compressed as %s. Store it somewhere safe before converting anything.', 'utf8-database-converter' ),
					'<code>' . esc_html( sanitize_file_name( DB_NAME ) . '.sql.zip' ) . '</code>'
				);
			?></p>
			<form method="post" action="<?php echo esc_url( self::admin_post_url() ); ?>">
				<input type="hidden" name="action" value="utf8dc_backup" />
				<?php wp_nonce_field( self::NONCE_BACKUP ); ?>
				<?php submit_button( __( 'Back up & download now', 'utf8-database-converter' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php
				/* translators: %s: target storage engine, e.g. InnoDB */
				printf( esc_html__( 'Section 2 — Convert MyISAM tables to %s', 'utf8-database-converter' ), esc_html( self::TARGET_ENGINE ) );
			?></h2>
			<?php if ( empty( $myisam ) ) : ?>
				<p><?php
					/* translators: %s: storage engine name */
					printf( esc_html__( 'No MyISAM tables found — everything already uses %s or another engine.', 'utf8-database-converter' ), esc_html( self::TARGET_ENGINE ) );
				?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::admin_post_url() ); ?>">
					<input type="hidden" name="action" value="utf8dc_engine" />
					<?php wp_nonce_field( self::NONCE_ENGINE ); ?>
					<p>
						<a href="#" id="utf8dc-engine-all"><?php esc_html_e( 'Select all', 'utf8-database-converter' ); ?></a> |
						<a href="#" id="utf8dc-engine-none"><?php esc_html_e( 'Select none', 'utf8-database-converter' ); ?></a>
					</p>
					<table class="widefat striped" style="max-width:900px;">
						<thead>
							<tr>
								<th style="width:2.2em;"></th>
								<th><?php esc_html_e( 'Table (MyISAM)', 'utf8-database-converter' ); ?></th>
								<th><?php esc_html_e( 'Current collation', 'utf8-database-converter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $myisam as $t ) : ?>
								<tr>
									<td><input type="checkbox" class="utf8dc-engine-cb" name="utf8dc_tables[]" value="<?php echo esc_attr( $t['name'] ); ?>" <?php checked( true ); ?> /></td>
									<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
									<td><?php echo esc_html( (string) $t['collation'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="max-width:900px;">
						<?php
						printf(
							/* translators: %s: target storage engine, e.g. InnoDB */
							esc_html__( 'Selected tables will be converted with ALTER TABLE … ENGINE=%s.', 'utf8-database-converter' ),
							'<code>' . esc_html( self::TARGET_ENGINE ) . '</code>'
						);
						?>
					</p>
					<?php submit_button( __( 'Convert selected tables to InnoDB', 'utf8-database-converter' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php
				/* translators: %s: target character set, e.g. utf8mb4 */
				printf( esc_html__( 'Section 3 — Convert non-UTF-8 tables to %s', 'utf8-database-converter' ), esc_html( self::TARGET_CHARSET ) );
			?></h2>
			<?php if ( empty( $base ) ) : ?>
				<p><?php esc_html_e( 'No tables were found in this database.', 'utf8-database-converter' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::admin_post_url() ); ?>">
					<input type="hidden" name="action" value="utf8dc_charset" />
					<?php wp_nonce_field( self::NONCE_CHARSET ); ?>
					<p>
						<a href="#" id="utf8dc-charset-all"><?php esc_html_e( 'Select all', 'utf8-database-converter' ); ?></a> |
						<a href="#" id="utf8dc-charset-none"><?php esc_html_e( 'Select none', 'utf8-database-converter' ); ?></a> |
						<a href="#" id="utf8dc-charset-legacy"><?php esc_html_e( 'Select only tables needing conversion', 'utf8-database-converter' ); ?></a>
					</p>
					<table class="widefat striped" style="max-width:900px;">
						<thead>
							<tr>
								<th style="width:2.2em;"></th>
								<th><?php esc_html_e( 'Table', 'utf8-database-converter' ); ?></th>
								<th><?php esc_html_e( 'Engine', 'utf8-database-converter' ); ?></th>
								<th><?php esc_html_e( 'Current collation', 'utf8-database-converter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $base as $t ) :
								$state   = self::collation_state( (string) $t['collation'] );
								$checked = 'good' !== $state;
								?>
								<tr>
									<td><input type="checkbox" class="utf8dc-charset-cb" name="utf8dc_tables[]" value="<?php echo esc_attr( $t['name'] ); ?>" <?php checked( $checked ); ?> data-legacy="<?php echo $checked ? '1' : '0'; ?>" /></td>
									<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
									<td><?php echo esc_html( (string) $t['engine'] ); ?></td>
									<td>
										<?php echo esc_html( (string) $t['collation'] ); ?>
										<?php if ( 'good' === $state ) : ?>
											<span style="color:#46b450;font-weight:bold;" title="<?php esc_attr_e( 'Already utf8mb4_unicode_ci or utf8mb4_unicode_520_ci — an accepted target, nothing to do.', 'utf8-database-converter' ); ?>">✔</span>
										<?php elseif ( 'ok' === $state ) : ?>
											<span style="color:#ffb900;font-weight:bold;" title="<?php esc_attr_e( 'Already utf8mb4, just a different collation — fine as-is, but can be converted to utf8mb4_unicode_ci or utf8mb4_unicode_520_ci.', 'utf8-database-converter' ); ?>">✔</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="max-width:900px;">
						<span style="color:#46b450;font-weight:bold;">✔</span> <?php esc_html_e( 'already utf8mb4_unicode_ci or utf8mb4_unicode_520_ci (left unselected)', 'utf8-database-converter' ); ?> ·
						<span style="color:#ffb900;font-weight:bold;">✔</span> <?php esc_html_e( 'utf8mb4 with another collation — fine, optionally convertible (pre-selected)', 'utf8-database-converter' ); ?> ·
						<?php esc_html_e( 'no mark: non-utf8mb4 (pre-selected)', 'utf8-database-converter' ); ?>
					</p>

					<h3><?php esc_html_e( 'Choose the target collation', 'utf8-database-converter' ); ?></h3>
					<ul style="max-width:900px;list-style:disc;margin-left:2em;">
						<li><code>utf8mb4_unicode_ci</code>: <?php esc_html_e( 'A faster collation based on UCA 4.0.0, trading some linguistic accuracy for speed.', 'utf8-database-converter' ); ?></li>
						<li><code>utf8mb4_unicode_520_ci</code>: <?php esc_html_e( 'A more linguistically accurate collation based on UCA 5.2.0, trading speed for correctness.', 'utf8-database-converter' ); ?></li>
					</ul>
					<p>
						<label for="utf8dc-collation"><strong><?php esc_html_e( 'Convert to:', 'utf8-database-converter' ); ?></strong></label>
						<select name="utf8dc_collation" id="utf8dc-collation">
							<option value="utf8mb4_unicode_ci" selected>utf8mb4_unicode_ci</option>
							<option value="utf8mb4_unicode_520_ci">utf8mb4_unicode_520_ci</option>
						</select>
					</p>
					<p style="max-width:900px;">
						<?php
						printf(
							/* translators: %s: target character set, e.g. utf8mb4 */
							esc_html__( 'Selected tables (and all their text columns) will be converted to %s with the chosen collation using ALTER TABLE … CONVERT TO CHARACTER SET.', 'utf8-database-converter' ),
							'<code>' . esc_html( self::TARGET_CHARSET ) . '</code>'
						);
						?>
					</p>
					<?php submit_button( __( 'Convert selected tables to utf8mb4_unicode_ci', 'utf8-database-converter' ), 'primary', 'submit', false, array( 'id' => 'utf8dc-charset-submit' ) ); ?>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Debug log', 'utf8-database-converter' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: server path of the debug log */
					esc_html__( 'Every backup and conversion step is written to %s, so after a failure you can see exactly which tables were and were not converted. The log is deleted when the plugin is deactivated.', 'utf8-database-converter' ),
					'<code>' . esc_html( self::log_path() ) . '</code>'
				);
			?></p>
			<?php if ( $log_exists ) : ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::admin_post_url() . '?action=utf8dc_log', self::NONCE_LOG ) ); ?>"><?php esc_html_e( 'Download debug log', 'utf8-database-converter' ); ?></a></p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No log entries yet — run a backup or conversion first.', 'utf8-database-converter' ); ?></em></p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Remove this plugin', 'utf8-database-converter' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: URL of the Plugins screen */
					wp_kses_post( __( 'Once your conversions are complete and you have verified your site works correctly, this plugin has served its purpose. Please <a href="%s">deactivate and delete it</a> — leaving unused administrative tools installed is a security risk. Deactivation also deletes any leftover backup archives and the debug log.', 'utf8-database-converter' ) ),
					esc_url( self::plugins_url() )
				);
			?></p>
		</div>

		<script>
		(function () {
			function wire(allId, noneId, selector, legacyId) {
				function setAll(v) {
					document.querySelectorAll(selector).forEach(function (cb) { cb.checked = v; });
				}
				var all = document.getElementById(allId), none = document.getElementById(noneId),
					legacy = legacyId ? document.getElementById(legacyId) : null;
				if (all) all.addEventListener('click', function (e) { e.preventDefault(); setAll(true); });
				if (none) none.addEventListener('click', function (e) { e.preventDefault(); setAll(false); });
				if (legacy) legacy.addEventListener('click', function (e) {
					e.preventDefault();
					document.querySelectorAll(selector).forEach(function (cb) { cb.checked = cb.dataset.legacy === '1'; });
				});
			}
			wire('utf8dc-charset-all', 'utf8dc-charset-none', '.utf8dc-charset-cb', 'utf8dc-charset-legacy');
			wire('utf8dc-engine-all', 'utf8dc-engine-none', '.utf8dc-engine-cb', null);

			var collation = document.getElementById('utf8dc-collation'),
				submitBtn = document.getElementById('utf8dc-charset-submit');
			if (collation && submitBtn) {
				collation.addEventListener('change', function () {
					submitBtn.value = 'Convert selected tables to ' + collation.value;
				});
			}
		})();
		</script>
		<?php
	}
}

UTF8_Database_Converter::init();
