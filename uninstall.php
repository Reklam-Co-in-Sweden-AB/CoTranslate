<?php
/**
 * CoTranslate avinstallation.
 *
 * Körs när pluginet raderas via WordPress admin.
 * Tar bort tabeller och options om användaren valt det.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Kontrollera om data ska raderas
$delete_data = get_option( 'cotranslate_delete_data_on_uninstall', false );

if ( ! $delete_data ) {
	return;
}

global $wpdb;

// Radera databastabeller
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cotranslate_translations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cotranslate_strings" );

// Radera alla options
$options = array(
	'cotranslate_deepl_api_key',
	'cotranslate_deepl_api_type',
	'cotranslate_default_language',
	'cotranslate_enabled_languages',
	'cotranslate_supported_post_types',
	'cotranslate_translate_slugs',
	'cotranslate_enable_frontend_editor',
	'cotranslate_show_floating_switcher',
	'cotranslate_floating_position',
	'cotranslate_auto_detect_language',
	'cotranslate_domain_language_map',
	'cotranslate_menu_switcher_location',
	'cotranslate_excluded_posts',
	'cotranslate_delete_data_on_uninstall',
	'cotranslate_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Radera transients
delete_transient( 'cotranslate_deepl_usage' );

// Rensa cron
wp_clear_scheduled_hook( 'cotranslate_process_queue' );
