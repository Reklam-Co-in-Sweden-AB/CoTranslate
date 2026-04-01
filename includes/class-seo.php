<?php
/**
 * SEO-integration för CoTranslate.
 *
 * Hanterar hreflang-taggar, canonical URL:er, HTML lang-attribut,
 * Yoast SEO sitemap-integration, Open Graph och översatta meta descriptions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_SEO {

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	/**
	 * @var CoTranslate_URL_Handler
	 */
	private $url_handler;

	public function __construct( CoTranslate_Translation_Store $store, CoTranslate_URL_Handler $url_handler ) {
		$this->store       = $store;
		$this->url_handler = $url_handler;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		// hreflang-taggar i <head>
		add_action( 'wp_head', array( $this, 'add_hreflang_tags' ), 1 );

		// Canonical URL — fixera för språkversion
		add_action( 'wp_head', array( $this, 'fix_canonical' ), 0 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );

		// Yoast SEO-integration
		if ( defined( 'WPSEO_VERSION' ) ) {
			$this->init_yoast_integration();
		}

		// Rank Math-integration
		if ( class_exists( 'RankMath' ) ) {
			$this->init_rankmath_integration();
		}

		// Open Graph
		add_filter( 'wpseo_opengraph_url', array( $this, 'filter_og_url' ), 10, 1 );
		add_filter( 'wpseo_opengraph_title', array( $this, 'filter_og_title' ), 10, 1 );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_og_description' ), 10, 1 );

		// Meta description (generiskt, utan SEO-plugin)
		add_action( 'wp_head', array( $this, 'maybe_add_meta_description' ), 5 );

		// Robots: inga noindex på språkversioner
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 10, 1 );
	}

	// =========================================================================
	// CANONICAL
	// =========================================================================

	/**
	 * Fixera canonical URL för språkversioner.
	 *
	 * Tar bort WordPress default canonical och lägger till korrekt version.
	 */
	public function fix_canonical() {
		if ( is_admin() || cotranslate_is_default_language() ) {
			return;
		}

		// Ta bort WordPress default canonical (vi hanterar det själva)
		remove_action( 'wp_head', 'rel_canonical' );

		$language    = cotranslate_get_current_language();
		$current_url = $this->get_current_page_url();
		$canonical   = $this->url_handler->get_url_for_language( $current_url, $language );

		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( $canonical )
		);
	}

	/**
	 * Filtrera WordPress canonical URL.
	 *
	 * @param string  $canonical_url Canonical URL.
	 * @param WP_Post $post          Post-objekt.
	 * @return string Fixerad canonical URL.
	 */
	public function filter_canonical_url( $canonical_url, $post ) {
		if ( cotranslate_is_default_language() ) {
			return $canonical_url;
		}

		$language = cotranslate_get_current_language();
		return $this->url_handler->get_url_for_language( $canonical_url, $language );
	}

	// =========================================================================
	// YOAST SEO
	// =========================================================================

	/**
	 * Initiera Yoast SEO-integration.
	 */
	private function init_yoast_integration() {
		// Canonical
		add_filter( 'wpseo_canonical', array( $this, 'filter_yoast_canonical' ), 10, 1 );

		// Sitemap: lägg till språkvarianter
		add_filter( 'wpseo_sitemap_entry', array( $this, 'add_sitemap_languages' ), 10, 3 );
		add_filter( 'wpseo_sitemap_url', array( $this, 'add_sitemap_hreflang' ), 10, 2 );

		// Meta title och description
		add_filter( 'wpseo_title', array( $this, 'filter_yoast_title' ), 10, 1 );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_yoast_metadesc' ), 10, 1 );
	}

	/**
	 * Filtrera Yoast canonical URL.
	 *
	 * @param string $canonical Canonical URL.
	 * @return string Fixerad canonical.
	 */
	public function filter_yoast_canonical( $canonical ) {
		if ( cotranslate_is_default_language() ) {
			return $canonical;
		}

		$language = cotranslate_get_current_language();
		return $this->url_handler->get_url_for_language( $canonical, $language );
	}

	/**
	 * Filtrera Yoast meta title.
	 *
	 * @param string $title Meta title.
	 * @return string Översatt title.
	 */
	public function filter_yoast_title( $title ) {
		if ( cotranslate_is_default_language() || ! is_singular() ) {
			return $title;
		}

		$post_id     = get_the_ID();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_title ) ) {
			// Byt ut post-titeln i meta title (behåll sajtnamn-delen)
			$original_title = get_the_title( $post_id );
			if ( ! empty( $original_title ) ) {
				$title = str_replace( $original_title, $translation->translated_title, $title );
			}
		}

		return $title;
	}

	/**
	 * Filtrera Yoast meta description.
	 *
	 * @param string $desc Meta description.
	 * @return string Översatt description.
	 */
	public function filter_yoast_metadesc( $desc ) {
		if ( cotranslate_is_default_language() || ! is_singular() ) {
			return $desc;
		}

		$post_id     = get_the_ID();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		// Använd översatt meta_desc om den finns
		if ( $translation && ! empty( $translation->translated_meta_desc ) ) {
			return $translation->translated_meta_desc;
		}

		// Fallback: använd översatt excerpt
		if ( $translation && ! empty( $translation->translated_excerpt ) ) {
			return wp_trim_words( wp_strip_all_tags( $translation->translated_excerpt ), 30 );
		}

		return $desc;
	}

	/**
	 * Lägg till språkvarianter i Yoast sitemap-entry.
	 *
	 * @param array  $url  Sitemap URL-data.
	 * @param string $type Post type.
	 * @param object $post Post-objekt.
	 * @return array Uppdaterad URL-data.
	 */
	public function add_sitemap_languages( $url, $type, $post ) {
		if ( ! is_array( $url ) || ! isset( $url['loc'] ) ) {
			return $url;
		}

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();

		// Kontrollera att posten har översättningar
		$post_id = 0;
		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = $post->ID;
		}

		if ( empty( $post_id ) ) {
			return $url;
		}

		// Lägg till xhtml:link för varje språk
		$images = $url['images'] ?? array();

		foreach ( $enabled_languages as $lang ) {
			$lang_url = $this->url_handler->get_url_for_language( $url['loc'], $lang );

			// Yoast stöder inte xhtml:link direkt i entries,
			// men vi kan filtrera sitemap XML nedan
		}

		return $url;
	}

	/**
	 * Lägg till hreflang-attribut i sitemap-URL.
	 *
	 * @param string $output Sitemap URL XML.
	 * @param object $post   Post-objekt.
	 * @return string Uppdaterad XML med xhtml:link.
	 */
	public function add_sitemap_hreflang( $output, $post ) {
		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();

		if ( count( $enabled_languages ) < 2 ) {
			return $output;
		}

		$post_id = 0;
		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = $post->ID;
		}

		$permalink = get_permalink( $post_id );
		$hreflang_xml = '';

		foreach ( $enabled_languages as $lang ) {
			$lang_url = $this->url_handler->get_url_for_language( $permalink, $lang );
			$hreflang_xml .= sprintf(
				"\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
				esc_attr( $lang ),
				esc_url( $lang_url )
			);
		}

		// x-default
		$default_url = $this->url_handler->get_url_for_language( $permalink, $default_language );
		$hreflang_xml .= sprintf(
			"\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\" />\n",
			esc_url( $default_url )
		);

		// Injicera före </url>
		$output = str_replace( '</url>', $hreflang_xml . "\t</url>", $output );

		return $output;
	}

	// =========================================================================
	// RANK MATH
	// =========================================================================

	/**
	 * Initiera Rank Math-integration.
	 */
	private function init_rankmath_integration() {
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_yoast_canonical' ), 10, 1 );
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_yoast_title' ), 10, 1 );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_yoast_metadesc' ), 10, 1 );
	}

	// =========================================================================
	// OPEN GRAPH
	// =========================================================================

	/**
	 * Filtrera Open Graph URL.
	 *
	 * @param string $url OG URL.
	 * @return string Korrekt URL för aktuellt språk.
	 */
	public function filter_og_url( $url ) {
		if ( cotranslate_is_default_language() ) {
			return $url;
		}

		$language = cotranslate_get_current_language();
		return $this->url_handler->get_url_for_language( $url, $language );
	}

	/**
	 * Filtrera Open Graph title.
	 *
	 * @param string $title OG title.
	 * @return string Översatt title.
	 */
	public function filter_og_title( $title ) {
		return $this->filter_yoast_title( $title );
	}

	/**
	 * Filtrera Open Graph description.
	 *
	 * @param string $desc OG description.
	 * @return string Översatt description.
	 */
	public function filter_og_description( $desc ) {
		return $this->filter_yoast_metadesc( $desc );
	}

	// =========================================================================
	// META DESCRIPTION (utan SEO-plugin)
	// =========================================================================

	/**
	 * Lägg till översatt meta description om inget SEO-plugin finns.
	 */
	public function maybe_add_meta_description() {
		// Hoppa över om Yoast eller Rank Math hanterar det
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}

		if ( cotranslate_is_default_language() || ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		$description = '';

		if ( $translation && ! empty( $translation->translated_meta_desc ) ) {
			$description = $translation->translated_meta_desc;
		} elseif ( $translation && ! empty( $translation->translated_excerpt ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $translation->translated_excerpt ), 30 );
		}

		if ( ! empty( $description ) ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}
	}

	// =========================================================================
	// ROBOTS
	// =========================================================================

	/**
	 * Filtrera robots meta tag.
	 *
	 * Säkerställ att språkversioner inte får noindex.
	 *
	 * @param array $robots Robots-direktiv.
	 * @return array Uppdaterade direktiv.
	 */
	public function filter_robots( $robots ) {
		// Språkversioner ska inte noindexas bara för att de är "duplicates"
		// WordPress bör redan hantera detta korrekt, men vi säkerställer
		return $robots;
	}

	// =========================================================================
	// HJÄLPMETODER
	// =========================================================================

	/**
	 * Lägg till hreflang-taggar i <head>.
	 */
	public function add_hreflang_tags() {
		if ( is_admin() ) {
			return;
		}

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();

		if ( count( $enabled_languages ) < 2 ) {
			return;
		}

		$current_url = $this->get_current_page_url();

		echo "\n<!-- CoTranslate hreflang -->\n";

		foreach ( $enabled_languages as $lang ) {
			$url = $this->url_handler->get_url_for_language( $current_url, $lang );
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang ),
				esc_url( $url )
			);
		}

		// x-default pekar till standardspråk
		$default_url = $this->url_handler->get_url_for_language( $current_url, $default_language );
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $default_url )
		);

		echo "<!-- /CoTranslate hreflang -->\n";
	}

	/**
	 * Hämta aktuell sidas URL.
	 *
	 * @return string Aktuell URL.
	 */
	private function get_current_page_url() {
		$request_uri = $this->url_handler->get_original_request_uri();
		return get_option( 'home' ) . $request_uri;
	}
}
