<?php
/**
 * Huvudklass för CoTranslate.
 *
 * Singleton som initierar alla komponenter och registrerar hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Plugin {

	private static $instance = null;

	/**
	 * @var CoTranslate_DeepL_API
	 */
	public $api;

	/**
	 * @var CoTranslate_Translation_Store
	 */
	public $store;

	/**
	 * @var CoTranslate_Post_Translator
	 */
	public $post_translator;

	/**
	 * @var CoTranslate_URL_Handler
	 */
	public $url_handler;

	/**
	 * @var CoTranslate_Admin
	 */
	public $admin;

	/**
	 * @var CoTranslate_Language_Switcher
	 */
	public $language_switcher;

	/**
	 * @var CoTranslate_String_Translator
	 */
	public $string_translator;

	/**
	 * @var CoTranslate_Frontend_Editor
	 */
	public $frontend_editor;

	/**
	 * @var CoTranslate_WooCommerce
	 */
	public $woocommerce;

	/**
	 * @var CoTranslate_SEO
	 */
	public $seo;

	/**
	 * Hämta singleton-instans.
	 *
	 * @return CoTranslate_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_includes();
		$this->init_url_handler();

		add_action( 'init', array( $this, 'init_components' ), 1 );
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
	}

	/**
	 * Ladda alla include-filer.
	 */
	private function load_includes() {
		$dir = COTRANSLATE_PLUGIN_DIR . 'includes/';

		require_once $dir . 'class-deepl-api.php';
		require_once $dir . 'class-claude-api.php';
		require_once $dir . 'class-translator-factory.php';
		require_once $dir . 'class-translation-store.php';
		require_once $dir . 'class-post-translator.php';
		require_once $dir . 'class-url-handler.php';
		require_once $dir . 'class-language-switcher.php';
		require_once $dir . 'class-string-translator.php';
		require_once $dir . 'class-frontend-editor.php';
		require_once $dir . 'class-woocommerce.php';
		require_once $dir . 'class-seo.php';

		if ( is_admin() ) {
			require_once $dir . 'class-admin.php';
		}
	}

	/**
	 * Initiera URL-handler tidigt (innan WordPress parsar URL:er).
	 */
	private function init_url_handler() {
		$this->url_handler = new CoTranslate_URL_Handler();
	}

	/**
	 * Initiera komponenter vid init.
	 */
	public function init_components() {
		// Kontrollera och uppgradera databas vid behov
		CoTranslate_Activator::maybe_upgrade();

		$this->api             = CoTranslate_Translator_Factory::create();
		$this->store           = new CoTranslate_Translation_Store();
		$this->post_translator = new CoTranslate_Post_Translator( $this->api, $this->store );

		// Registrera hooks för komponenter
		$this->url_handler->init();
		$this->post_translator->init();

		// Språkväljare (frontend)
		$this->language_switcher = new CoTranslate_Language_Switcher( $this->url_handler );
		$this->language_switcher->init();

		// Strängöversättare (tema-strängar, menyer, widgets)
		$this->string_translator = new CoTranslate_String_Translator( $this->api, $this->store );
		$this->string_translator->init();

		// Frontend-editor (valfri)
		$this->frontend_editor = new CoTranslate_Frontend_Editor( $this->store );
		$this->frontend_editor->init();

		// WooCommerce-integration
		$this->woocommerce = new CoTranslate_WooCommerce( $this->store, $this->url_handler );
		$this->woocommerce->init();

		// SEO-integration
		$this->seo = new CoTranslate_SEO( $this->store, $this->url_handler );
		$this->seo->init();

		if ( is_admin() ) {
			$this->admin = new CoTranslate_Admin( $this->api, $this->store, $this->post_translator );
			$this->admin->init();
		}

		// Frontend: registrera content-filter
		if ( ! is_admin() ) {
			$this->init_frontend_filters();
		}
	}

	/**
	 * Ladda textdomän.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'cotranslate',
			false,
			dirname( COTRANSLATE_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Registrera frontend content-filter.
	 *
	 * Dessa byter ut post-content mot översättningar från databasen.
	 * INGEN output buffer — ren filter-kedja.
	 */
	private function init_frontend_filters() {
		add_filter( 'the_title', array( $this, 'filter_the_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 10 );
		add_filter( 'the_excerpt', array( $this, 'filter_the_excerpt' ), 10 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_the_excerpt' ), 10 );

		// Förladda översättningar för main query (undvik N+1)
		add_action( 'wp', array( $this, 'preload_translations' ) );

		// SEO: filtrera HTML lang-attribut
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 10 );

		// hreflang-taggar hanteras av CoTranslate_SEO
	}

	/**
	 * Förladda översättningar för alla poster i main query.
	 */
	public function preload_translations() {
		if ( cotranslate_is_default_language() ) {
			return;
		}

		global $wp_query;
		if ( ! $wp_query || empty( $wp_query->posts ) ) {
			return;
		}

		$post_ids = wp_list_pluck( $wp_query->posts, 'ID' );
		$language = cotranslate_get_current_language();

		$this->store->preload_post_translations( $post_ids, $language );
	}

	/**
	 * Filtrera post-titel.
	 *
	 * @param string $title   Original titel.
	 * @param int    $post_id Post-ID.
	 * @return string Översatt titel eller original.
	 */
	public function filter_the_title( $title, $post_id = 0 ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $title;
		}

		if ( empty( $post_id ) ) {
			return $title;
		}

		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_title ) ) {
			return $translation->translated_title;
		}

		return $title;
	}

	/**
	 * Filtrera post-innehåll.
	 *
	 * @param string $content Original innehåll.
	 * @return string Översatt innehåll eller original.
	 */
	public function filter_the_content( $content ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		// Hoppa över om innehållet har page builder-shortcodes.
		// Dessa måste renderas av sin page builder först — översättningen
		// hanteras av String Translator (output buffer) istället.
		$raw_content = get_post_field( 'post_content', $post_id );
		if ( $this->has_page_builder_content( $raw_content ) ) {
			return $content;
		}

		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_content ) ) {
			return $translation->translated_content;
		}

		return $content;
	}

	/**
	 * Kontrollera om innehåll har page builder-shortcodes.
	 *
	 * @param string $content Rå post_content.
	 * @return bool True om page builder-shortcodes hittades.
	 */
	private function has_page_builder_content( $content ) {
		if ( empty( $content ) ) {
			return false;
		}

		$page_builder_patterns = array(
			'[vc_row',          // WPBakery / Visual Composer / Uncode
			'[vc_column',
			'[vc_section',
			'[et_pb_',          // Divi
			'[fl_builder',      // Beaver Builder
			'[fusion_',         // Avada / Fusion Builder
			'[elementor',       // Elementor (sällan i post_content men kan förekomma)
			'[cs_',             // Cornerstone
			'[tatsu_',          // Flavor / Flavor Builder
		);

		foreach ( $page_builder_patterns as $pattern ) {
			if ( strpos( $content, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filtrera post-excerpt.
	 *
	 * @param string $excerpt Original excerpt.
	 * @return string Översatt excerpt eller original.
	 */
	public function filter_the_excerpt( $excerpt ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $excerpt;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $excerpt;
		}

		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_excerpt ) ) {
			return $translation->translated_excerpt;
		}

		return $excerpt;
	}

	/**
	 * Filtrera HTML lang-attribut.
	 *
	 * @param string $output Befintlig output.
	 * @return string Uppdaterad output med rätt lang.
	 */
	public function filter_language_attributes( $output ) {
		if ( is_admin() ) {
			return $output;
		}

		$current_lang = cotranslate_get_current_language();
		$output       = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $current_lang ) . '"', $output );

		return $output;
	}

}
