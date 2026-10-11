<?php
/**
 * Plugin Name:       UTF-8 Database Converter
 * Plugin URI:        https://github.com/your-username/utf8-database-converter
 * Description:       Backs up your database (as a compressed SQL file), converts tables and columns to utf8mb4, and converts MyISAM tables to InnoDB. Remove this plugin when finished.
 * Version:           2.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Yihui Xie (original), modernized for PHP 7.4–8.5
 * Author URI:        https://yihui.org/
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
	const TARGET_CHARSET   = 'utf8mb4';
	const TARGET_COLLATION = 'utf8mb4_unicode_ci';
	const TARGET_ENGINE    = 'InnoDB';
	const BACKUP_TTL       = 600; // 10 minutes.
	const DUMP_CHUNK_ROWS  = 500;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_utf8dc_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_utf8dc_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_utf8dc_charset', array( __CLASS__, 'handle_charset' ) );
		add_action( 'admin_post_utf8dc_engine', array( __CLASS__, 'handle_engine' ) );
		add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'UTF-8 Database Converter', 'utf8-database-converter' ),
			__( 'UTF-8 DB Converter', 'utf8-database-converter' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( array $links ): array {
		$url = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Convert', 'utf8-database-converter' ) . '</a>' );
		return $links;
	}

	public static function deactivate(): void {
		self::cleanup_backups();
	}

	/* --------------------------------------------------------------------- *
	 * Data helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Returns metadata for every table in the WordPress database.
	 *
	 * @return array<int, array{name:string, engine:string, collation:string}>
	 */
	private static function tables_info(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation
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
		return admin_url( 'tools.php?page=' . self::MENU_SLUG );
	}

	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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
		foreach ( glob( $dir . '*.zip' ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
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

		foreach ( self::tables_info() as $info ) {
			$table  = $info['name'];
			$quoted = self::quote_identifier( $table );

			$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $create ) && isset( $create[1] ) ) {
				fwrite( $fh, "\n\n-- --------------------------------------------------------\n-- Structure for table {$quoted}\n-- --------------------------------------------------------\n\n" );
				fwrite( $fh, "DROP TABLE IF EXISTS {$quoted};\n" . $create[1] . ";\n" );
			}

			$offset = 0;
			while ( true ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( "SELECT * FROM {$quoted} LIMIT {$offset}, " . self::DUMP_CHUNK_ROWS, ARRAY_A );
				if ( ! is_array( $rows ) ) {
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
			fwrite( $fh, "\n" );
		}

		fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fh );

		return $tmp;
	}

	/** admin-post handler: build the dump, zip it, redirect to the download URL. */
	public static function handle_backup(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_BACKUP );
		self::raise_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The PHP Zip extension (ZipArchive) is required to create compressed backups. Please ask your host to enable it.', 'utf8-database-converter' ) );
		}

		$sql_path = self::write_sql_dump();
		if ( is_wp_error( $sql_path ) ) {
			wp_die( esc_html( $sql_path->get_error_message() ) );
		}

		$zip_name = sanitize_file_name( DB_NAME ) . '.sql.zip';
		$zip_path = self::backup_dir() . wp_generate_password( 20, false, false ) . '-' . $zip_name;

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $sql_path );
			wp_die( esc_html__( 'Could not create the ZIP archive in wp-content/utf8dc-backups/. Please check directory permissions.', 'utf8-database-converter' ) );
		}
		$zip->addFile( $sql_path, sanitize_file_name( DB_NAME ) . '.sql' );
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $sql_path );

		set_transient( self::download_key(), $zip_path, self::BACKUP_TTL );

		wp_safe_redirect(
			add_query_arg(
				array(
					'action'   => 'utf8dc_download',
					'_wpnonce' => wp_create_nonce( self::NONCE_DOWNLOAD ),
				),
				admin_url( 'admin-post.php' )
			)
		);
		exit;
	}

	private static function download_key(): string {
		return 'utf8dc_backup_' . get_current_user_id();
	}

	/** admin-post handler: stream the prepared zip, then delete it. */
	public static function handle_download(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_DOWNLOAD );

		$path     = get_transient( self::download_key() );
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
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( DB_NAME ) . '.sql.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
		delete_transient( self::download_key() );
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Conversions
	 * --------------------------------------------------------------------- */

	/**
	 * Converts the given tables to utf8mb4.
	 *
	 * @param string[] $tables Validated table names.
	 * @return array<int, array{table:string, ok:bool, message:string}>
	 */
	private static function convert_charset( array $tables ): array {
		global $wpdb;
		$results = array();

		foreach ( $tables as $table ) {
			$sql = sprintf(
				'ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s',
				self::quote_identifier( $table ),
				self::TARGET_CHARSET,
				self::TARGET_COLLATION
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ok        = false !== $wpdb->query( $sql );
			$results[] = array(
				'table'   => $table,
				'ok'      => $ok,
				'message' => $ok ? '' : $wpdb->last_error,
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
			$sql       = 'ALTER TABLE ' . self::quote_identifier( $table ) . ' ENGINE=' . self::TARGET_ENGINE;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ok        = false !== $wpdb->query( $sql );
			$results[] = array(
				'table'   => $table,
				'ok'      => $ok,
				'message' => $ok ? '' : $wpdb->last_error,
			);
		}
		return $results;
	}

	public static function handle_charset(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_CHARSET );
		self::raise_limits();

		$tables  = isset( $_POST['utf8dc_tables'] ) ? self::validate_selected_tables( $_POST['utf8dc_tables'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$results = empty( $tables ) ? array() : self::convert_charset( $tables );

		set_transient( 'utf8dc_results_' . get_current_user_id(), array( 'type' => 'charset', 'results' => $results ), MINUTE_IN_SECONDS * 5 );
		wp_safe_redirect( add_query_arg( 'utf8dc_done', 'charset', self::page_url() ) );
		exit;
	}

	public static function handle_engine(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_ENGINE );
		self::raise_limits();

		$tables  = isset( $_POST['utf8dc_tables'] ) ? self::validate_selected_tables( $_POST['utf8dc_tables'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$results = empty( $tables ) ? array() : self::convert_engine( $tables );

		set_transient( 'utf8dc_results_' . get_current_user_id(), array( 'type' => 'engine', 'results' => $results ), MINUTE_IN_SECONDS * 5 );
		wp_safe_redirect( add_query_arg( 'utf8dc_done', 'engine', self::page_url() ) );
		exit;
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
			/* translators: %s: target character set, e.g. utf8mb4 */
			? sprintf( __( 'Character set conversion results (target: %s)', 'utf8-database-converter' ), self::TARGET_CHARSET )
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

		$flash = get_transient( 'utf8dc_results_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'utf8dc_results_' . get_current_user_id() );
		}

		$tables  = self::tables_info();
		$myisam  = array_filter( $tables, fn( $t ) => 'myisam' === strtolower( (string) $t['engine'] ) );
		$legacy  = array_filter( $tables, fn( $t ) => '' !== (string) $t['collation'] && 0 !== strpos( (string) $t['collation'], self::TARGET_CHARSET ) );
		$all_done = empty( $myisam ) && empty( $legacy );
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
							esc_url( admin_url( 'plugins.php' ) )
						);
					?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-error" style="padding:.8em;">
				<p style="font-weight:bold;margin:0;">
					<?php esc_html_e( 'WARNING: schema changes can lose data if interrupted. Back up your database first (Step 1), and consider putting the site in maintenance mode while converting.', 'utf8-database-converter' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Step 1 — Back up the entire database', 'utf8-database-converter' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: backup file name, e.g. wordpress.sql.zip */
					esc_html__( 'Downloads a complete SQL dump of every table in this database, compressed as %s. Store it somewhere safe before converting anything.', 'utf8-database-converter' ),
					'<code>' . esc_html( sanitize_file_name( DB_NAME ) . '.sql.zip' ) . '</code>'
				);
			?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="utf8dc_backup" />
				<?php wp_nonce_field( self::NONCE_BACKUP ); ?>
				<?php submit_button( __( 'Back up & download now', 'utf8-database-converter' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php
				/* translators: %s: target character set, e.g. utf8mb4 */
				printf( esc_html__( 'Step 2 — Convert tables to %s', 'utf8-database-converter' ), esc_html( self::TARGET_CHARSET ) );
			?></h2>
			<?php if ( empty( $tables ) ) : ?>
				<p><?php esc_html_e( 'No tables were found in this database.', 'utf8-database-converter' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="utf8dc_charset" />
					<?php wp_nonce_field( self::NONCE_CHARSET ); ?>
					<p>
						<a href="#" id="utf8dc-charset-all"><?php esc_html_e( 'Select all', 'utf8-database-converter' ); ?></a> |
						<a href="#" id="utf8dc-charset-none"><?php esc_html_e( 'Select none', 'utf8-database-converter' ); ?></a> |
						<a href="#" id="utf8dc-charset-legacy"><?php esc_html_e( 'Select only non-utf8mb4 tables', 'utf8-database-converter' ); ?></a>
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
							<?php foreach ( $tables as $t ) :
								$is_utf8mb4 = 0 === strpos( (string) $t['collation'], self::TARGET_CHARSET );
								?>
								<tr>
									<td><input type="checkbox" class="utf8dc-charset-cb" name="utf8dc_tables[]" value="<?php echo esc_attr( $t['name'] ); ?>" <?php checked( true ); ?> data-legacy="<?php echo $is_utf8mb4 ? '0' : '1'; ?>" /></td>
									<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
									<td><?php echo esc_html( (string) $t['engine'] ); ?></td>
									<td><?php echo esc_html( (string) $t['collation'] ); ?><?php echo $is_utf8mb4 ? ' ✅' : ''; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="max-width:900px;">
						<?php
						printf(
							/* translators: 1: target character set, 2: target collation */
							esc_html__( 'Selected tables (and all their text columns) will be converted to %1$s with the %2$s collation using ALTER TABLE … CONVERT TO CHARACTER SET.', 'utf8-database-converter' ),
							'<code>' . esc_html( self::TARGET_CHARSET ) . '</code>',
							'<code>' . esc_html( self::TARGET_COLLATION ) . '</code>'
						);
						?>
					</p>
					<?php submit_button( __( 'Convert selected tables to utf8mb4', 'utf8-database-converter' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php
				/* translators: %s: target storage engine, e.g. InnoDB */
				printf( esc_html__( 'Step 3 — Convert MyISAM tables to %s', 'utf8-database-converter' ), esc_html( self::TARGET_ENGINE ) );
			?></h2>
			<?php if ( empty( $myisam ) ) : ?>
				<p><?php
					/* translators: %s: storage engine name */
					printf( esc_html__( 'No MyISAM tables found — everything already uses %s or another engine.', 'utf8-database-converter' ), esc_html( self::TARGET_ENGINE ) );
				?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
					<?php submit_button( __( 'Convert selected tables to InnoDB', 'utf8-database-converter' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Step 4 — Remove this plugin', 'utf8-database-converter' ); ?></h2>
			<p><?php
				printf(
					/* translators: %s: URL of the Plugins screen */
					wp_kses_post( __( 'Once your conversions are complete and you have verified your site works correctly, this plugin has served its purpose. Please <a href="%s">deactivate and delete it</a> — leaving unused administrative tools installed is a security risk.', 'utf8-database-converter' ) ),
					esc_url( admin_url( 'plugins.php' ) )
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
		})();
		</script>
		<?php
	}
}

UTF8_Database_Converter::init();
