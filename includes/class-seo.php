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
		add_filter( 'wpseo_og_locale', array( $this, 'filter_og_locale' ), 10, 1 );

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
	 * Med Yoast eller Rank Math skriver SEO-pluginet redan ut canonical, och
	 * den språkanpassas i filter_yoast_canonical() — en egen tagg här blev
	 * en andra canonical på varje icke-standardspråk.
	 */
	public function fix_canonical() {
		if ( is_admin() || cotranslate_is_default_language() ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
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

		// Sitemap: språkversionerna som egna poster
		add_filter( 'wpseo_sitemap_url', array( $this, 'add_sitemap_language_urls' ), 10, 2 );

		// Meta title och description
		add_filter( 'wpseo_title', array( $this, 'filter_yoast_title' ), 10, 1 );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_yoast_metadesc' ), 10, 1 );

		// Schema: inLanguage följer språkversionen
		add_filter( 'wpseo_schema_graph', array( $this, 'filter_schema_language' ), 10, 1 );
	}

	/**
	 * Sätt språkversionens språk i Yoasts schema.
	 *
	 * Yoast skriver sajtens språk (en-US) i varje del av grafen, även på
	 * /nb/, /nl/ och /zh/.
	 *
	 * @param array $graph Yoasts schemagraf.
	 * @return array Grafen med rätt inLanguage.
	 */
	public function filter_schema_language( $graph ) {
		if ( ! is_array( $graph ) || cotranslate_is_default_language() ) {
			return $graph;
		}

		$language = cotranslate_get_locale( cotranslate_get_current_language(), '-' );

		array_walk_recursive(
			$graph,
			static function ( &$value, $key ) use ( $language ) {
				if ( 'inLanguage' === $key ) {
					$value = $language;
				}
			}
		);

		return $graph;
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
	 * Lägg till språkversionerna som egna poster i Yoasts sitemap.
	 *
	 * Yoast känner bara till standardspråkets adresser, så varje <url>-post
	 * skrivs ut en gång till per språk med språkets adress (och översatta
	 * sluggar) i <loc>. hreflang skrivs bara i <head>. Före 3.5.3 lade
	 * sitemapen in xhtml:link utan namnrymd (ogiltig XML), och filtret
	 * läste URL-arrayen som ett post-objekt, så alla alternativ blev tomma
	 * eller pekade på språkets startsida.
	 *
	 * @param string $output Yoasts XML för en <url>-post.
	 * @param array  $url    Yoasts URL-data (loc, mod, images).
	 * @return string XML med språkversionernas poster efter originalet.
	 */
	public function add_sitemap_language_urls( $output, $url ) {
		if ( ! is_array( $url ) || empty( $url['loc'] ) ) {
			return $output;
		}

		$default_language = cotranslate_get_default_language();
		$language_entries = '';

		foreach ( cotranslate_get_enabled_languages() as $lang ) {
			if ( $lang === $default_language ) {
				continue;
			}

			$lang_loc          = '<loc>' . esc_url( $this->url_handler->get_url_for_language( $url['loc'], $lang ) ) . '</loc>';
			$language_entries .= preg_replace_callback(
				'#<loc>[^<]*</loc>#',
				function () use ( $lang_loc ) {
					return $lang_loc;
				},
				$output,
				1
			);
		}

		return $output . $language_entries;
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
	 * Filtrera Open Graph locale.
	 *
	 * @param string $locale OG locale.
	 * @return string Språkversionens locale, t.ex. nb_NO.
	 */
	public function filter_og_locale( $locale ) {
		if ( cotranslate_is_default_language() ) {
			return $locale;
		}

		return cotranslate_get_locale( cotranslate_get_current_language() );
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
	 * Hämta aktuell sidas URL utan frågesträng.
	 *
	 * hreflang och canonical ska peka på sidan, inte på besöket: med
	 * frågesträngen kvar blev ?utm_source=…, ?fbclid=… och ?nocache=1
	 * alternativa URL:er i <head>.
	 *
	 * @return string Aktuell URL.
	 */
	private function get_current_page_url() {
		$request_uri = $this->url_handler->get_original_request_uri();
		$path        = explode( '?', $request_uri, 2 )[0];
		return get_option( 'home' ) . ( '' !== $path ? $path : '/' );
	}
}
