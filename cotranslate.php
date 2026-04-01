<?php
/**
 * Plugin Name: CoTranslate
 * Plugin URI: https://coscribe.se/cotranslate
 * Description: Automatisk AI-driven översättning med DeepL API. Översätter sidor, inlägg och WooCommerce-produkter med stöd för manuella överrider.
 * Version: 3.0.0
 * Author: Coscribe
 * Author URI: https://coscribe.se
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cotranslate
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COTRANSLATE_VERSION', '3.0.0' );
define( 'COTRANSLATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COTRANSLATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'COTRANSLATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Ladda hjälpfunktioner
require_once COTRANSLATE_PLUGIN_DIR . 'includes/helpers.php';

// Aktivering och avaktivering
require_once COTRANSLATE_PLUGIN_DIR . 'includes/class-activator.php';
register_activation_hook( __FILE__, array( 'CoTranslate_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CoTranslate_Activator', 'deactivate' ) );

// Starta pluginet
require_once COTRANSLATE_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Returnera plugin-instansen.
 *
 * @return CoTranslate_Plugin
 */
function cotranslate() {
	return CoTranslate_Plugin::get_instance();
}

// Initiera vid plugins_loaded (tidigt, innan WordPress parsar URL:er)
add_action( 'plugins_loaded', 'cotranslate', 0 );
