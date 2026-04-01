<?php
/**
 * Hanterar plugin-aktivering och databasschema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Activator {

	/**
	 * Databasversion — öka vid schemaändringar.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Körs vid plugin-aktivering.
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();
		update_option( 'cotranslate_db_version', self::DB_VERSION );

		// Schemalägg cron för bakgrundsöversättning
		if ( ! wp_next_scheduled( 'cotranslate_process_queue' ) ) {
			wp_schedule_event( time(), 'every_minute', 'cotranslate_process_queue' );
		}
	}

	/**
	 * Körs vid plugin-avaktivering.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cotranslate_process_queue' );
	}

	/**
	 * Kontrollera och uppgradera databasschema vid behov.
	 */
	public static function maybe_upgrade() {
		$current_version = get_option( 'cotranslate_db_version', '0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( 'cotranslate_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Skapa databastabeller med dbDelta().
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Tabell för post-översättningar
		$table_translations = $wpdb->prefix . 'cotranslate_translations';
		$sql_translations   = "CREATE TABLE {$table_translations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			language varchar(10) NOT NULL,
			translated_title text DEFAULT NULL,
			translated_content longtext DEFAULT NULL,
			translated_excerpt text DEFAULT NULL,
			translated_slug varchar(200) DEFAULT '',
			translated_meta_desc text DEFAULT NULL,
			is_manual tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'auto',
			deepl_chars_used int unsigned DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_lang (post_id, language),
			KEY language (language),
			KEY status (status)
		) {$charset_collate};";

		// Tabell för tema-strängar, menyer, widgets
		$table_strings = $wpdb->prefix . 'cotranslate_strings';
		$sql_strings   = "CREATE TABLE {$table_strings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash varchar(64) NOT NULL,
			source_text text NOT NULL,
			language varchar(10) NOT NULL,
			translated_text text NOT NULL,
			context varchar(100) DEFAULT 'general',
			is_manual tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY hash_lang (source_hash, language),
			KEY language (language),
			KEY is_manual (is_manual)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_translations );
		dbDelta( $sql_strings );
	}

	/**
	 * Sätt standardvärden för options.
	 */
	private static function set_default_options() {
		add_option( 'cotranslate_default_language', 'sv' );
		add_option( 'cotranslate_enabled_languages', array( 'sv', 'en' ) );
		add_option( 'cotranslate_supported_post_types', array( 'post', 'page', 'product' ) );
		add_option( 'cotranslate_translate_slugs', false );
		add_option( 'cotranslate_enable_frontend_editor', false );
		add_option( 'cotranslate_show_floating_switcher', true );
		add_option( 'cotranslate_floating_position', 'bottom-right' );
		add_option( 'cotranslate_auto_detect_language', false );
		add_option( 'cotranslate_delete_data_on_uninstall', false );
	}
}
