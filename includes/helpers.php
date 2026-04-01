<?php
/**
 * Hjälpfunktioner för CoTranslate
 *
 * Kryptering, språklistor, konverteringsfunktioner och globala wrappers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kryptera känslig data med WordPress salts.
 *
 * @param string $data Data att kryptera.
 * @return string Krypterad data (base64-kodad) eller tom sträng vid fel.
 */
function cotranslate_encrypt( $data ) {
	if ( empty( $data ) ) {
		return '';
	}

	$key       = wp_salt( 'auth' );
	$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
	$iv        = openssl_random_pseudo_bytes( $iv_length );
	$encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, 0, $iv );

	if ( false === $encrypted ) {
		return '';
	}

	return base64_encode( $iv . $encrypted );
}

/**
 * Dekryptera känslig data med WordPress salts.
 *
 * @param string $data Krypterad data (base64-kodad).
 * @return string Dekrypterad data eller original om dekryptering misslyckas.
 */
function cotranslate_decrypt( $data ) {
	if ( empty( $data ) ) {
		return '';
	}

	$key     = wp_salt( 'auth' );
	$decoded = base64_decode( $data, true );

	if ( false === $decoded ) {
		return $data;
	}

	$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

	if ( strlen( $decoded ) < $iv_length ) {
		return $data;
	}

	$iv        = substr( $decoded, 0, $iv_length );
	$encrypted = substr( $decoded, $iv_length );
	$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );

	if ( false === $decrypted ) {
		return $data;
	}

	return $decrypted;
}

/**
 * Hämta DeepL API-nyckel.
 *
 * Kontrollerar först wp-config.php konstant, sedan krypterad option.
 *
 * @return string API-nyckel eller tom sträng.
 */
function cotranslate_get_api_key() {
	// Konstant i wp-config.php har högsta prioritet
	if ( defined( 'COTRANSLATE_DEEPL_KEY' ) ) {
		return COTRANSLATE_DEEPL_KEY;
	}

	$stored = get_option( 'cotranslate_deepl_api_key', '' );

	if ( empty( $stored ) ) {
		return '';
	}

	// DeepL-nycklar slutar med :fx (Free) eller är UUID-format (Pro)
	if ( strpos( $stored, ':fx' ) !== false || preg_match( '/^[a-f0-9-]{36}/', $stored ) ) {
		return $stored;
	}

	return cotranslate_decrypt( $stored );
}

/**
 * Spara DeepL API-nyckel (krypterad).
 *
 * @param string $api_key API-nyckel att spara.
 * @return bool True vid lyckad sparning.
 */
function cotranslate_save_api_key( $api_key ) {
	$encrypted = cotranslate_encrypt( $api_key );
	return update_option( 'cotranslate_deepl_api_key', $encrypted );
}

/**
 * Avgör om DeepL Free eller Pro används.
 *
 * Free-nycklar slutar med :fx.
 *
 * @return string 'free' eller 'pro'.
 */
function cotranslate_get_api_type() {
	$key = cotranslate_get_api_key();

	if ( ! empty( $key ) && substr( $key, -3 ) === ':fx' ) {
		return 'free';
	}

	return get_option( 'cotranslate_deepl_api_type', 'free' );
}

/**
 * Hämta DeepL API-basURL.
 *
 * @return string API-basURL.
 */
function cotranslate_get_api_base_url() {
	$type = cotranslate_get_api_type();
	return 'free' === $type
		? 'https://api-free.deepl.com/v2'
		: 'https://api.deepl.com/v2';
}

/**
 * Alla språk som DeepL stöder med metadata.
 *
 * @return array Associativ array med språkkod => metadata.
 */
function cotranslate_get_supported_languages() {
	static $languages = null;

	if ( null !== $languages ) {
		return $languages;
	}

	$languages = array(
		'bg' => array( 'name' => 'Bulgarian', 'native' => 'Български', 'flag' => '🇧🇬', 'deepl' => 'BG', 'rtl' => false ),
		'cs' => array( 'name' => 'Czech', 'native' => 'Čeština', 'flag' => '🇨🇿', 'deepl' => 'CS', 'rtl' => false ),
		'da' => array( 'name' => 'Danish', 'native' => 'Dansk', 'flag' => '🇩🇰', 'deepl' => 'DA', 'rtl' => false ),
		'de' => array( 'name' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪', 'deepl' => 'DE', 'rtl' => false ),
		'el' => array( 'name' => 'Greek', 'native' => 'Ελληνικά', 'flag' => '🇬🇷', 'deepl' => 'EL', 'rtl' => false ),
		'en' => array( 'name' => 'English', 'native' => 'English', 'flag' => '🇬🇧', 'deepl' => 'EN', 'rtl' => false ),
		'es' => array( 'name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸', 'deepl' => 'ES', 'rtl' => false ),
		'et' => array( 'name' => 'Estonian', 'native' => 'Eesti', 'flag' => '🇪🇪', 'deepl' => 'ET', 'rtl' => false ),
		'fi' => array( 'name' => 'Finnish', 'native' => 'Suomi', 'flag' => '🇫🇮', 'deepl' => 'FI', 'rtl' => false ),
		'fr' => array( 'name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷', 'deepl' => 'FR', 'rtl' => false ),
		'hu' => array( 'name' => 'Hungarian', 'native' => 'Magyar', 'flag' => '🇭🇺', 'deepl' => 'HU', 'rtl' => false ),
		'id' => array( 'name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => '🇮🇩', 'deepl' => 'ID', 'rtl' => false ),
		'it' => array( 'name' => 'Italian', 'native' => 'Italiano', 'flag' => '🇮🇹', 'deepl' => 'IT', 'rtl' => false ),
		'ja' => array( 'name' => 'Japanese', 'native' => '日本語', 'flag' => '🇯🇵', 'deepl' => 'JA', 'rtl' => false ),
		'ko' => array( 'name' => 'Korean', 'native' => '한국어', 'flag' => '🇰🇷', 'deepl' => 'KO', 'rtl' => false ),
		'lt' => array( 'name' => 'Lithuanian', 'native' => 'Lietuvių', 'flag' => '🇱🇹', 'deepl' => 'LT', 'rtl' => false ),
		'lv' => array( 'name' => 'Latvian', 'native' => 'Latviešu', 'flag' => '🇱🇻', 'deepl' => 'LV', 'rtl' => false ),
		'nb' => array( 'name' => 'Norwegian', 'native' => 'Norsk bokmål', 'flag' => '🇳🇴', 'deepl' => 'NB', 'rtl' => false ),
		'nl' => array( 'name' => 'Dutch', 'native' => 'Nederlands', 'flag' => '🇳🇱', 'deepl' => 'NL', 'rtl' => false ),
		'pl' => array( 'name' => 'Polish', 'native' => 'Polski', 'flag' => '🇵🇱', 'deepl' => 'PL', 'rtl' => false ),
		'pt' => array( 'name' => 'Portuguese', 'native' => 'Português', 'flag' => '🇵🇹', 'deepl' => 'PT', 'rtl' => false ),
		'ro' => array( 'name' => 'Romanian', 'native' => 'Română', 'flag' => '🇷🇴', 'deepl' => 'RO', 'rtl' => false ),
		'ru' => array( 'name' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺', 'deepl' => 'RU', 'rtl' => false ),
		'sk' => array( 'name' => 'Slovak', 'native' => 'Slovenčina', 'flag' => '🇸🇰', 'deepl' => 'SK', 'rtl' => false ),
		'sl' => array( 'name' => 'Slovenian', 'native' => 'Slovenščina', 'flag' => '🇸🇮', 'deepl' => 'SL', 'rtl' => false ),
		'sv' => array( 'name' => 'Swedish', 'native' => 'Svenska', 'flag' => '🇸🇪', 'deepl' => 'SV', 'rtl' => false ),
		'tr' => array( 'name' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷', 'deepl' => 'TR', 'rtl' => false ),
		'uk' => array( 'name' => 'Ukrainian', 'native' => 'Українська', 'flag' => '🇺🇦', 'deepl' => 'UK', 'rtl' => false ),
		'zh' => array( 'name' => 'Chinese', 'native' => '中文', 'flag' => '🇨🇳', 'deepl' => 'ZH', 'rtl' => false ),
		'ar' => array( 'name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦', 'deepl' => 'AR', 'rtl' => true ),
	);

	return $languages;
}

/**
 * Konvertera WordPress-språkkod till DeepL-format.
 *
 * @param string $wp_code WordPress-språkkod (t.ex. 'sv', 'nb').
 * @return string DeepL-språkkod (t.ex. 'SV', 'NB') eller tom sträng.
 */
function cotranslate_wp_to_deepl_lang( $wp_code ) {
	$languages = cotranslate_get_supported_languages();
	$wp_code   = strtolower( $wp_code );

	if ( isset( $languages[ $wp_code ] ) ) {
		return $languages[ $wp_code ]['deepl'];
	}

	return strtoupper( $wp_code );
}

/**
 * Konvertera DeepL-språkkod till WordPress-format.
 *
 * @param string $deepl_code DeepL-språkkod (t.ex. 'SV', 'NB').
 * @return string WordPress-språkkod (t.ex. 'sv', 'nb').
 */
function cotranslate_deepl_to_wp_lang( $deepl_code ) {
	$languages  = cotranslate_get_supported_languages();
	$deepl_code = strtoupper( $deepl_code );

	foreach ( $languages as $wp_code => $data ) {
		if ( $data['deepl'] === $deepl_code ) {
			return $wp_code;
		}
	}

	return strtolower( $deepl_code );
}

/**
 * Hämta aktuellt språk.
 *
 * @return string Språkkod (t.ex. 'sv', 'en').
 */
function cotranslate_get_current_language() {
	$plugin = CoTranslate_Plugin::get_instance();

	if ( $plugin->url_handler ) {
		return $plugin->url_handler->get_current_language();
	}

	return cotranslate_get_default_language();
}

/**
 * Hämta standardspråk.
 *
 * @return string Språkkod.
 */
function cotranslate_get_default_language() {
	return get_option( 'cotranslate_default_language', 'sv' );
}

/**
 * Kontrollera om aktuellt språk är standardspråket.
 *
 * @return bool True om standardspråk.
 */
function cotranslate_is_default_language() {
	return cotranslate_get_current_language() === cotranslate_get_default_language();
}

/**
 * Hämta aktiverade språk.
 *
 * @return array Array med språkkoder.
 */
function cotranslate_get_enabled_languages() {
	return get_option( 'cotranslate_enabled_languages', array( 'sv', 'en' ) );
}

/**
 * Kontrollera om ett språk är RTL.
 *
 * @param string $lang_code Språkkod.
 * @return bool True om RTL.
 */
function cotranslate_is_rtl_language( $lang_code ) {
	$languages = cotranslate_get_supported_languages();
	return isset( $languages[ $lang_code ] ) && ! empty( $languages[ $lang_code ]['rtl'] );
}

/**
 * Hämta post-typer som ska översättas.
 *
 * @return array Array med post type slugs.
 */
function cotranslate_get_supported_post_types() {
	return get_option( 'cotranslate_supported_post_types', array( 'post', 'page', 'product' ) );
}
