# UTF-8 Database Converter

A WordPress plugin that modernizes legacy database schemas:

1. **Backs up your entire database** and downloads it as a compressed `databasename.sql.zip` file.
2. **Converts tables and their text columns to `utf8mb4`** (`utf8mb4_unicode_ci` collation) — the current, recommended UTF-8 character set for MySQL/MariaDB and WordPress — with per-table selection (all tables selected by default).
3. **Converts MyISAM tables to InnoDB**, with per-table selection (all MyISAM tables selected by default).
4. **Reminds you to remove the plugin** once conversions are complete.

This is a modernization of Yihui Xie's original 2009–2011 plugin, rewritten for PHP 7.4 through 8.5 and current WordPress APIs.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 through 8.5
- MySQL 5.5+ or MariaDB 10.x (any version supporting `utf8mb4`)
- The PHP **Zip extension** (`ZipArchive`) for compressed backups
- A WordPress administrator account (`manage_options` capability)

## Installation

1. Go to **Plugins** in the main menu.
2. Click the **Add Plugin** button (top of the page).
3. Click the **Upload Plugin** button (same spot on the top of the page).
4. Wait for the plugin to install then click **Activate Plugin**.
5. Go to **Tools → UTF-8 DB Converter** to use it.
6. *Please remember to deactivate then delete the plugin when you're done using it!*

## Usage

The admin screen walks you through four steps:

### Step 1 — Back up the entire database

Click **Back up & download now**. The plugin generates a full SQL dump (structure + data for every table), compresses it into `databasename.sql.zip`, and streams it to your browser. The temporary copy on the server is deleted immediately after download (and after 10 minutes the download link expires). Any leftover archives are also deleted when the plugin is deactivated.

> **Do not skip this step.** `ALTER TABLE` operations can lose data if interrupted.

### Step 2 — Convert tables to utf8mb4

The screen lists every table in the database with its engine and current collation. All tables are selected by default; use the checkboxes (or the *Select all / Select none / Select only non-utf8mb4 tables* links) to choose a subset, then click **Convert selected tables to utf8mb4**.

Each selected table is converted with:

```sql
ALTER TABLE `table_name` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

This updates the table default **and** every `CHAR`/`VARCHAR`/`TEXT`/`ENUM`/`SET` column in one statement. Results (success or the MySQL error) are shown per table.

### Step 3 — Convert MyISAM tables to InnoDB

If any tables use the MyISAM engine they are listed (all selected by default). Click **Convert selected tables to InnoDB** to run:

```sql
ALTER TABLE `table_name` ENGINE=InnoDB;
```

### Step 4 — Remove this plugin

Once all tables show `utf8mb4` collations and no MyISAM tables remain, **deactivate and delete the plugin** from the Plugins screen. Leaving one-off administrative tools installed is an unnecessary security risk. The plugin detects the completed state and shows a reminder with a direct link.

## Security notes

- All actions require the `manage_options` capability and WordPress nonces.
- Table names are validated server-side against the real tables in the database; identifiers are backtick-escaped before reaching SQL.
- All output is escaped (`esc_html`, `esc_attr`, `esc_url`).
- Backup files are written to `wp-content/utf8dc-backups/` with `.htaccess` denial and an `index.php` guard, use random unguessable file names, expire after 10 minutes, and are deleted after streaming.
- The plugin performs no front-end activity whatsoever.

## Limitations

- Row data is dumped in chunks of 500 rows to limit memory usage, but very large databases may still take a long time to back up or convert. For multi-gigabyte databases, prefer `mysqldump` and command-line conversion.
- Views are included in the backup dump (`SHOW CREATE TABLE` output), but charset/engine conversion only targets base tables.
- Conversion does **not** attempt to repair data that was double-encoded by a historical misconfiguration; it only changes the declared charset/collation.

## Credits

- Original plugin (2009–2011): [Yihui Xie](https://yihui.org/) — released under GPL-2.0-or-later ([archived repo](https://github.com/wp-plugins/utf-8-db-converter)).
- Modernized rewrite (2026): PHP 7.4–8.5 compatibility, `utf8mb4` target, backups, table/engine selection.

## License

This program is free software: you can redistribute it and/or modify it under the terms of the **GNU General Public License version 3 only** as published by the Free Software Foundation. See [LICENSE](LICENSE) for the full text.
