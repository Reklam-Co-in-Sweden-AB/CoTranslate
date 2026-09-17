<?php
/**
 * URL-hantering och språkrouting.
 *
 * Hanterar språkprefix i URL:er, detekterar aktuellt språk,
 * och filtrerar alla WordPress-genererade länkar.
 * Baserad på beprövad logik från Coscribe Translator v2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_URL_Handler {

	/**
	 * Aktuellt språk.
	 */
	private $current_language;

	/**
	 * Originalt REQUEST_URI innan vi modifierar det.
	 */
	private $original_request_uri;

	/**
	 * Sökvägen som besökaren bad om, utan språkprefix och FÖRE uppslaget av
	 * översatta sluggar. Används för att omdirigera /nb/products/ till
	 * /nb/produkter/.
	 */
	private $requested_path;

	/**
	 * Förhindra oändlig rekursion i URL-filter.
	 */
	private $is_filtering_url = false;

	/**
	 * Statisk cache för options.
	 */
	private static $option_cache = array();

	public function __construct() {
		// Skriv om REQUEST_URI INNAN WordPress parsar den
		$this->rewrite_request_uri();
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		// Språkredirect (domänmappning, webbläsardetektering)
		add_action( 'template_redirect', array( $this, 'handle_language_redirect' ) );

		// Förhindra WordPress canonical redirect
		add_filter( 'redirect_canonical', array( $this, 'prevent_language_redirect' ), 10, 2 );

		// Filtrera alla länktyper
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 4 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'post_type_archive_link', array( $this, 'filter_generic_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_generic_link' ), 10, 3 );
		add_filter( 'category_link', array( $this, 'filter_generic_link' ), 10, 2 );
		add_filter( 'tag_link', array( $this, 'filter_generic_link' ), 10, 2 );
		add_filter( 'author_link', array( $this, 'filter_generic_link' ), 10, 2 );
		add_filter( 'search_link', array( $this, 'filter_generic_link' ), 10, 1 );
		add_filter( 'get_pagenum_link', array( $this, 'filter_generic_link' ), 10, 1 );

		// WooCommerce-specifika filter
		add_filter( 'woocommerce_product_get_permalink', array( $this, 'filter_generic_link' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_url', array( $this, 'filter_generic_link' ), 10, 1 );
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'filter_generic_link' ), 10, 1 );
		add_filter( 'wc_get_cart_url', array( $this, 'filter_generic_link' ), 10, 1 );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( $this, 'filter_generic_link' ), 10, 1 );
	}

	/**
	 * Hämta aktuellt språk.
	 *
	 * @return string Språkkod.
	 */
	public function get_current_language() {
		if ( null === $this->current_language ) {
			$this->current_language = $this->get_default_language();
		}
		return $this->current_language;
	}

	/**
	 * Hämta originalt REQUEST_URI.
	 *
	 * @return string Original URI.
	 */
	public function get_original_request_uri() {
		return $this->original_request_uri ?? ( $_SERVER['REQUEST_URI'] ?? '/' );
	}

	/**
	 * Bygg URL för ett specifikt språk.
	 *
	 * @param string $url      Aktuell URL.
	 * @param string $language Målspråk.
	 * @return string URL med korrekt språkprefix.
	 */
	public function get_url_for_language( $url, $language ) {
		$home_url         = rtrim( $this->get_raw_home_url(), '/' );
		$default_language = $this->get_default_language();

		// Normalisera bort dubbla snedstreck i path-delen (bevara protokollets //).
		// Skyddar mot t.ex. home_url med avslutande slash som ger 'site.com//en/...'.
		$url = preg_replace( '#([^:])//+#', '$1/', $url );

		// Ta bort eventuellt befintligt språkprefix. URL:en kan bära det
		// NUVARANDE språkets översatta sluggar (/nb/produkter/), så de slås
		// tillbaka till originalet innan målspråkets sluggar sätts.
		$from_language = $this->detect_url_language( $url );
		$clean_url     = $this->strip_language_prefix( $url );
		$clean_url     = $this->map_url_path( $clean_url, $from_language, 'original' );

		if ( $language === $default_language ) {
			return $clean_url;
		}

		$clean_url = $this->map_url_path( $clean_url, $language, 'translate' );

		// Extrahera path efter domänen
		$parsed = wp_parse_url( $clean_url );
		$path   = $parsed['path'] ?? '/';

		// Ta bort home_url:s path-del (om WP i undermapp)
		$home_parsed = wp_parse_url( $home_url );
		$home_path   = rtrim( $home_parsed['path'] ?? '', '/' );

		if ( ! empty( $home_path ) && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		// Normalisera: säkerställ att path börjar med /
		$path = '/' . ltrim( $path, '/' );

		// Bygg ny URL: home + /språk + /path
		$new_url = $home_url . '/' . $language . $path;

		if ( ! empty( $parsed['query'] ) ) {
			$new_url .= '?' . $parsed['query'];
		}

		if ( ! empty( $parsed['fragment'] ) ) {
			$new_url .= '#' . $parsed['fragment'];
		}

		return $new_url;
	}

	// =========================================================================
	// PRIVATA METODER
	// =========================================================================

	/**
	 * Skriv om REQUEST_URI innan WordPress parsar den.
	 *
	 * Tar bort språkprefix så att WordPress hittar rätt post.
	 */
	private function rewrite_request_uri() {
		// Kör inte i admin, AJAX, REST eller cron
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$this->original_request_uri = $request_uri;

		// Parsa URL-path
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return;
		}

		// Kontrollera om URL:en börjar med ett språkprefix
		$enabled_languages = $this->get_enabled_languages();
		$default_language  = $this->get_default_language();

		foreach ( $enabled_languages as $lang ) {
			if ( $lang === $default_language ) {
				continue;
			}

			$prefix = '/' . $lang . '/';
			$exact  = '/' . $lang;

			if ( strpos( $path, $prefix ) === 0 || $path === $exact ) {
				$this->current_language = $lang;

				// Strip språkprefix från REQUEST_URI
				$new_path = substr( $path, strlen( $exact ) );
				if ( empty( $new_path ) || $new_path[0] !== '/' ) {
					$new_path = '/' . $new_path;
				}

				// Översatta sluggar (/nb/produkter/) slås upp till originalet
				// (/products/) — WordPress känner bara originalsluggarna.
				$this->requested_path = $new_path;
				$new_path             = cotranslate_original_path( $new_path, $lang );

				// Bevara query string
				$query = wp_parse_url( $request_uri, PHP_URL_QUERY );
				$_SERVER['REQUEST_URI'] = $new_path . ( $query ? '?' . $query : '' );

				break;
			}
		}

		// Om inget språkprefix hittades, använd standardspråk
		if ( null === $this->current_language ) {
			$this->current_language = $default_language;
		}
	}

	/**
	 * Ta bort språkprefix från en URL.
	 *
	 * @param string $url URL att rensa.
	 * @return string Ren URL utan språkprefix.
	 */
	private function strip_language_prefix( $url ) {
		$enabled_languages = $this->get_enabled_languages();
		$home_url          = rtrim( $this->get_raw_home_url(), '/' );

		// Matcha /språk direkt efter domänen, följt av / eller slutet på URL:en.
		// Regex-baserat för att tåla både home_url/lang/rest och home_url/lang.
		foreach ( $enabled_languages as $lang ) {
			$pattern = '#^(' . preg_quote( $home_url, '#' ) . ')/' . preg_quote( $lang, '#' ) . '(/|$)#';

			if ( preg_match( $pattern, $url ) ) {
				// Byt bara ut den matchade prefix-delen mot home_url/.
				return preg_replace( $pattern, '$1/', $url, 1 );
			}
		}

		return $url;
	}

	/**
	 * Lägg till språkprefix på en URL.
	 *
	 * @param string $url URL att prefixa.
	 * @return string URL med språkprefix.
	 */
	private function add_language_prefix( $url ) {
		$current_lang     = $this->get_current_language();
		$default_language = $this->get_default_language();

		if ( $current_lang === $default_language ) {
			return $url;
		}

		$home_url = $this->get_raw_home_url();

		// Kontrollera att URL:en tillhör denna sajt
		if ( strpos( $url, $home_url ) !== 0 ) {
			return $url;
		}

		// Hoppa över om prefix redan finns
		if ( preg_match( '#/' . preg_quote( $current_lang, '#' ) . '(/|$)#', $url ) ) {
			return $url;
		}

		// Hoppa över admin-, wp-content-, REST-URL:er
		$skip_patterns = array( '/wp-admin', '/wp-content', '/wp-includes', '/wp-json', '/wp-login' );
		foreach ( $skip_patterns as $pattern ) {
			if ( strpos( $url, $pattern ) !== false ) {
				return $url;
			}
		}

		// Byt originalsluggarna mot språkets översatta, och lägg sedan till
		// prefixet efter domänen.
		$url = $this->map_url_path( $url, $current_lang, 'translate' );

		return preg_replace(
			'#^(' . preg_quote( $home_url, '#' ) . ')(/|$)#',
			'$1/' . $current_lang . '$2',
			$url
		);
	}

	/**
	 * Översätt sluggarna i en sajt-URL:s sökväg, åt valfritt håll.
	 *
	 * URL:en får inte ha något språkprefix. Query och fragment rörs inte.
	 *
	 * @param string $url       Absolut URL på den här sajten.
	 * @param string $language  Språket sluggarna gäller.
	 * @param string $direction 'translate' (original → översatt) eller 'original'.
	 * @return string
	 */
	private function map_url_path( $url, $language, $direction ) {
		$home_url = rtrim( $this->get_raw_home_url(), '/' );

		if ( strpos( $url, $home_url ) !== 0 ) {
			return $url;
		}

		$rest = substr( $url, strlen( $home_url ) );

		// Dela av query och fragment — bara sökvägen har sluggar.
		$tail = '';
		$cut  = strcspn( $rest, '?#' );

		if ( $cut < strlen( $rest ) ) {
			$tail = substr( $rest, $cut );
			$rest = substr( $rest, 0, $cut );
		}

		$rest = 'original' === $direction
			? cotranslate_original_path( $rest, $language )
			: cotranslate_translate_path( $rest, $language );

		return $home_url . $rest . $tail;
	}

	/**
	 * Vilket språkprefix en sajt-URL bär, eller standardspråket om inget.
	 *
	 * @param string $url Absolut URL.
	 * @return string Språkkod.
	 */
	private function detect_url_language( $url ) {
		$home_url = rtrim( $this->get_raw_home_url(), '/' );

		foreach ( $this->get_enabled_languages() as $lang ) {
			if ( preg_match( '#^' . preg_quote( $home_url, '#' ) . '/' . preg_quote( $lang, '#' ) . '(/|$|\?|\#)#', $url ) ) {
				return $lang;
			}
		}

		return $this->get_default_language();
	}

	// =========================================================================
	// LINK-FILTER
	// =========================================================================

	/**
	 * Filtrera home_url.
	 */
	public function filter_home_url( $url, $path, $orig_scheme, $blog_id ) {
		if ( $this->is_filtering_url || is_admin() ) {
			return $url;
		}

		$this->is_filtering_url = true;
		$url = $this->add_language_prefix( $url );
		$this->is_filtering_url = false;

		return $url;
	}

	/**
	 * Filtrera page_link.
	 */
	public function filter_page_link( $url, $post_id ) {
		if ( $this->is_filtering_url || is_admin() ) {
			return $url;
		}

		$this->is_filtering_url = true;
		$url = $this->add_language_prefix( $url );
		$this->is_filtering_url = false;

		return $url;
	}

	/**
	 * Filtrera post_link.
	 */
	public function filter_post_link( $url, $post ) {
		if ( $this->is_filtering_url || is_admin() ) {
			return $url;
		}

		$this->is_filtering_url = true;
		$url = $this->add_language_prefix( $url );
		$this->is_filtering_url = false;

		return $url;
	}

	/**
	 * Filtrera post_type_link.
	 */
	public function filter_post_type_link( $url, $post ) {
		if ( $this->is_filtering_url || is_admin() ) {
			return $url;
		}

		$this->is_filtering_url = true;
		$url = $this->add_language_prefix( $url );
		$this->is_filtering_url = false;

		return $url;
	}

	/**
	 * Generiskt filter för alla andra länktyper.
	 */
	public function filter_generic_link( $url ) {
		if ( $this->is_filtering_url || is_admin() ) {
			return $url;
		}

		$this->is_filtering_url = true;
		$url = $this->add_language_prefix( $url );
		$this->is_filtering_url = false;

		return $url;
	}

	// =========================================================================
	// REDIRECT-HANTERING
	// =========================================================================

	/**
	 * Hantera språkredirect.
	 */
	public function handle_language_redirect() {
		// /nb/products/ → /nb/produkter/ när språket har översatta sluggar
		$this->maybe_redirect_to_translated_slug();

		// Domänmappning (om aktiverad)
		$domain_map = get_option( 'cotranslate_domain_language_map', array() );
		if ( ! empty( $domain_map ) ) {
			$this->maybe_redirect_by_domain( $domain_map );
		}

		// Webbläsardetektering (om aktiverad)
		if ( get_option( 'cotranslate_auto_detect_language', false ) ) {
			$this->maybe_redirect_by_browser();
		}

		// Sätt cookie för aktuellt språk
		$this->set_language_cookie( $this->get_current_language() );
	}

	/**
	 * Omdirigera till adressen med översatta sluggar.
	 *
	 * Båda adresserna svarar annars — /nb/products/ och /nb/produkter/ — och
	 * sökmotorer ser det som dubbelt innehåll. Den översatta är den riktiga.
	 * Förhandsvisningar, formulärinskick och 404 lämnas.
	 */
	private function maybe_redirect_to_translated_slug() {
		$language = $this->get_current_language();

		if ( null === $this->requested_path || $language === $this->get_default_language() ) {
			return;
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || is_404() || is_preview() || is_feed() ) {
			return;
		}

		if ( ! cotranslate_slug_map()->is_enabled_for( $language ) ) {
			return;
		}

		$canonical = cotranslate_translate_path( cotranslate_original_path( $this->requested_path, $language ), $language );

		if ( $canonical === $this->requested_path ) {
			return;
		}

		$target = rtrim( $this->get_raw_home_url(), '/' ) . '/' . $language . $canonical;
		$query  = wp_parse_url( $this->get_original_request_uri(), PHP_URL_QUERY );

		if ( $query ) {
			$target .= '?' . $query;
		}

		wp_safe_redirect( $target, 301, 'CoTranslate' );
		exit;
	}

	/**
	 * Redirect nya besökare baserat på webbläsarspråk.
	 *
	 * Kollar Accept-Language header och omdirigerar till matchande språk
	 * om besökaren inte redan har en språkcookie.
	 */
	private function maybe_redirect_by_browser() {
		// Hoppa över om cookie redan finns (återkommande besökare)
		if ( isset( $_COOKIE['cotranslate_lang'] ) ) {
			return;
		}

		// Hoppa över om vi redan är på ett icke-default-språk (URL-prefix satt)
		$current_lang    = $this->get_current_language();
		$default_language = $this->get_default_language();
		if ( $current_lang !== $default_language ) {
			return;
		}

		// Hoppa över sökmotorbots
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ( preg_match( '/bot|crawl|spider|slurp|googlebot|bingbot|yandex|baidu|duckduck/i', $user_agent ) ) {
			return;
		}

		// Parsa Accept-Language header
		$accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
		if ( empty( $accept_language ) ) {
			return;
		}

		$browser_lang = $this->parse_accept_language( $accept_language );
		if ( empty( $browser_lang ) ) {
			return;
		}

		// Matcha mot aktiverade språk
		$enabled_languages = $this->get_enabled_languages();
		$matched_lang      = null;

		foreach ( $browser_lang as $lang_code => $quality ) {
			// Exakt matchning
			if ( in_array( $lang_code, $enabled_languages, true ) && $lang_code !== $default_language ) {
				$matched_lang = $lang_code;
				break;
			}

			// Basspråk-matchning (t.ex. "nb-NO" → "nb")
			$base = strtolower( substr( $lang_code, 0, 2 ) );
			if ( in_array( $base, $enabled_languages, true ) && $base !== $default_language ) {
				$matched_lang = $base;
				break;
			}

			// Speciellt: "no" → "nb" (norska)
			if ( 'no' === $base && in_array( 'nb', $enabled_languages, true ) && 'nb' !== $default_language ) {
				$matched_lang = 'nb';
				break;
			}
		}

		if ( ! $matched_lang ) {
			return;
		}

		// Sätt cookie innan redirect (undvik loop)
		$this->set_language_cookie( $matched_lang );

		// 302-redirect (temporär — besökaren kan byta språk)
		$path   = $this->original_request_uri ?? '/';
		$target = $this->get_raw_home_url() . '/' . $matched_lang . $path;

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Parsa Accept-Language header.
	 *
	 * @param string $header Accept-Language header-värde.
	 * @return array Associativ array med språkkod => kvalitet, sorterad fallande.
	 */
	private function parse_accept_language( $header ) {
		$languages = array();

		$parts = explode( ',', $header );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			$quality = 1.0;
			if ( preg_match( '/;q=([0-9.]+)/', $part, $matches ) ) {
				$quality = (float) $matches[1];
				$part    = preg_replace( '/;q=[0-9.]+/', '', $part );
			}

			$lang_code = strtolower( trim( $part ) );
			if ( ! empty( $lang_code ) ) {
				$languages[ $lang_code ] = $quality;
			}
		}

		// Sortera efter kvalitet (högst först)
		arsort( $languages );

		return $languages;
	}

	/**
	 * Förhindra WordPress canonical redirect från att strippa språkprefix.
	 */
	public function prevent_language_redirect( $redirect_url, $requested_url ) {
		$current_lang     = $this->get_current_language();
		$default_language = $this->get_default_language();

		if ( $current_lang !== $default_language ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Redirect baserat på domänmappning.
	 *
	 * @param array $domain_map Array med domän => språk.
	 */
	private function maybe_redirect_by_domain( array $domain_map ) {
		$host      = $_SERVER['HTTP_HOST'] ?? '';
		$home_host = wp_parse_url( $this->get_raw_home_url(), PHP_URL_HOST );

		// Om vi är på huvuddomänen, ingen redirect
		if ( $host === $home_host ) {
			return;
		}

		// Kolla om hosten matchar en mappad domän
		foreach ( $domain_map as $domain => $language ) {
			$clean_domain = rtrim( str_replace( array( 'https://', 'http://' ), '', $domain ), '/' );
			if ( $host === $clean_domain ) {
				// 301-redirect till huvuddomänen med språkprefix
				$path = $_SERVER['REQUEST_URI'] ?? '/';
				$target = $this->get_raw_home_url() . '/' . $language . $path;

				wp_safe_redirect( $target, 301 );
				exit;
			}
		}
	}

	// =========================================================================
	// HJÄLPMETODER
	// =========================================================================

	/**
	 * Hämta raw home URL utan filter (förhindrar rekursion).
	 */
	private function get_raw_home_url() {
		return get_option( 'home' );
	}

	/**
	 * Hämta standardspråk med cachning.
	 */
	private function get_default_language() {
		if ( ! isset( self::$option_cache['default_language'] ) ) {
			self::$option_cache['default_language'] = get_option( 'cotranslate_default_language', 'sv' );
		}
		return self::$option_cache['default_language'];
	}

	/**
	 * Hämta aktiverade språk med cachning.
	 */
	private function get_enabled_languages() {
		if ( ! isset( self::$option_cache['enabled_languages'] ) ) {
			self::$option_cache['enabled_languages'] = get_option( 'cotranslate_enabled_languages', array( 'sv', 'en' ) );
		}
		return self::$option_cache['enabled_languages'];
	}

	/**
	 * Sätt cookie för språkval.
	 *
	 * @param string $language Språkkod.
	 */
	public function set_language_cookie( $language ) {
		if ( ! headers_sent() ) {
			setcookie(
				'cotranslate_lang',
				$language,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
	}
}
