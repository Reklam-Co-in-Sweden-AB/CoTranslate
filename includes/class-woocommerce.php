<?php
/**
 * WooCommerce-integration för CoTranslate.
 *
 * Hanterar översättning av produkter, kundvagn, kassa, attribut
 * och AJAX-operationer med korrekt språkkontext.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_WooCommerce {

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
	 * Registrera hooks (bara om WooCommerce är aktivt).
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Produktnamn och beskrivningar
		add_filter( 'woocommerce_product_get_name', array( $this, 'filter_product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'filter_product_short_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'filter_product_description' ), 10, 2 );

		// Produktvariationer
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'filter_product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_description', array( $this, 'filter_product_description' ), 10, 2 );

		// Kundvagn: produktnamn
		add_filter( 'woocommerce_cart_item_name', array( $this, 'filter_cart_item_name' ), 10, 3 );

		// Attribut-etiketter
		add_filter( 'woocommerce_attribute_label', array( $this, 'filter_attribute_label' ), 10, 3 );

		// Beställningsdetaljer
		add_filter( 'woocommerce_order_item_name', array( $this, 'filter_order_item_name' ), 10, 2 );

		// Kundvagns- och kassa-URL:er (hanteras redan av URL-handler, men säkerställ)
		add_filter( 'woocommerce_get_cart_url', array( $this, 'filter_wc_url' ), 10, 1 );
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'filter_wc_url' ), 10, 1 );
		add_filter( 'wc_get_cart_url', array( $this, 'filter_wc_url' ), 10, 1 );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( $this, 'filter_wc_url' ), 10, 1 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_endpoint_url' ), 10, 4 );

		// AJAX: skicka med språkkontext
		add_action( 'wp_enqueue_scripts', array( $this, 'add_ajax_language_context' ), 30 );

		// Kassa: hantera språk i AJAX-request
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_language_context' ), 10, 1 );

		// Produktkategorier och -taggar
		add_filter( 'woocommerce_product_get_category_ids', array( $this, 'pass_through' ), 10, 1 );

		// Breadcrumbs
		add_filter( 'woocommerce_get_breadcrumb', array( $this, 'filter_breadcrumbs' ), 10, 1 );

		// Förladda produktöversättningar vid arkivsidor
		add_action( 'woocommerce_before_shop_loop', array( $this, 'preload_product_translations' ), 5 );

		// Meddelanden och notiser
		add_filter( 'woocommerce_add_to_cart_message_html', array( $this, 'filter_add_to_cart_message' ), 10, 2 );

		// Mini-cart widget
		add_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'pass_through' ), 10, 1 );

		// Översätt knappar och texter i strängtabellen
		add_action( 'init', array( $this, 'register_wc_strings' ), 20 );
	}

	// =========================================================================
	// PRODUKTÖVERSÄTTNINGAR
	// =========================================================================

	/**
	 * Filtrera produktnamn.
	 *
	 * @param string      $name    Produktnamn.
	 * @param WC_Product  $product Produktobjekt.
	 * @return string Översatt namn eller original.
	 */
	public function filter_product_name( $name, $product ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $name;
		}

		$post_id     = $product->get_id();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_title ) ) {
			return $translation->translated_title;
		}

		return $name;
	}

	/**
	 * Filtrera kort produktbeskrivning.
	 *
	 * @param string      $description Kort beskrivning.
	 * @param WC_Product  $product     Produktobjekt.
	 * @return string Översatt beskrivning eller original.
	 */
	public function filter_product_short_description( $description, $product ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $description;
		}

		$post_id     = $product->get_id();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_excerpt ) ) {
			return $translation->translated_excerpt;
		}

		return $description;
	}

	/**
	 * Filtrera lång produktbeskrivning.
	 *
	 * @param string      $description Lång beskrivning.
	 * @param WC_Product  $product     Produktobjekt.
	 * @return string Översatt beskrivning eller original.
	 */
	public function filter_product_description( $description, $product ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $description;
		}

		$post_id     = $product->get_id();
		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( $translation && ! empty( $translation->translated_content ) ) {
			return $translation->translated_content;
		}

		return $description;
	}

	// =========================================================================
	// KUNDVAGN OCH KASSA
	// =========================================================================

	/**
	 * Filtrera produktnamn i kundvagn.
	 *
	 * @param string $name      Produktnamn (HTML).
	 * @param array  $cart_item Kundvagnsartikel.
	 * @param string $cart_item_key Kundvagnsnyckel.
	 * @return string Översatt namn.
	 */
	public function filter_cart_item_name( $name, $cart_item, $cart_item_key ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $name;
		}

		$product_id = $cart_item['product_id'] ?? 0;
		if ( empty( $product_id ) ) {
			return $name;
		}

		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $product_id, $language );

		if ( $translation && ! empty( $translation->translated_title ) ) {
			// Byt ut produktnamnet i HTML-länken
			$translated = $translation->translated_title;
			$name = preg_replace( '#>([^<]+)</a>#', '>' . esc_html( $translated ) . '</a>', $name );
		}

		return $name;
	}

	/**
	 * Filtrera produktnamn i beställning.
	 *
	 * @param string        $name Produktnamn.
	 * @param WC_Order_Item $item Beställningsartikel.
	 * @return string Översatt namn.
	 */
	public function filter_order_item_name( $name, $item ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $name;
		}

		$product_id = $item->get_product_id();
		if ( empty( $product_id ) ) {
			return $name;
		}

		$language    = cotranslate_get_current_language();
		$translation = $this->store->get_post_translation( $product_id, $language );

		if ( $translation && ! empty( $translation->translated_title ) ) {
			return $translation->translated_title;
		}

		return $name;
	}

	/**
	 * Filtrera attribut-etikett.
	 *
	 * @param string $label   Etikett.
	 * @param string $name    Attributnamn.
	 * @param object $product Produkt (kan vara null).
	 * @return string Översatt etikett.
	 */
	public function filter_attribute_label( $label, $name, $product = null ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $label;
		}

		$language   = cotranslate_get_current_language();
		$translated = $this->store->get_string_translation( $label, $language );

		return $translated ?: $label;
	}

	// =========================================================================
	// URL-HANTERING
	// =========================================================================

	/**
	 * Filtrera WooCommerce-URL:er med språkprefix.
	 *
	 * @param string $url URL.
	 * @return string URL med språkprefix.
	 */
	public function filter_wc_url( $url ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $url;
		}

		$language = cotranslate_get_current_language();
		return $this->url_handler->get_url_for_language( $url, $language );
	}

	/**
	 * Filtrera WooCommerce endpoint-URL:er.
	 *
	 * @param string $url       Endpoint-URL.
	 * @param string $endpoint  Endpoint-slug.
	 * @param string $value     Endpoint-värde.
	 * @param string $permalink Permalänk.
	 * @return string URL med språkprefix.
	 */
	public function filter_endpoint_url( $url, $endpoint, $value, $permalink ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $url;
		}

		$language = cotranslate_get_current_language();
		return $this->url_handler->get_url_for_language( $url, $language );
	}

	// =========================================================================
	// AJAX SPRÅKKONTEXT
	// =========================================================================

	/**
	 * Lägg till språkkontext i WooCommerce AJAX-parametrar.
	 */
	public function add_ajax_language_context() {
		if ( cotranslate_is_default_language() ) {
			return;
		}

		$language = cotranslate_get_current_language();

		// Injicera språk i WooCommerce AJAX-parametrar
		wp_add_inline_script(
			'wc-cart-fragments',
			sprintf(
				'if (typeof wc_cart_fragments_params !== "undefined") { wc_cart_fragments_params.cotranslate_lang = "%s"; }',
				esc_js( $language )
			),
			'before'
		);

		// Generellt: lägg till språk i alla AJAX-requests
		wp_add_inline_script(
			'jquery',
			sprintf(
				'jQuery(document).ajaxSend(function(e, xhr, settings) { '
				. 'if (settings.data && typeof settings.data === "string") { '
				. 'settings.data += "&cotranslate_lang=%s"; '
				. '} });',
				esc_js( $language )
			),
			'after'
		);
	}

	/**
	 * Sätt språkkontext innan WooCommerce beräknar totaler.
	 *
	 * @param WC_Cart $cart Kundvagn.
	 */
	public function set_language_context( $cart ) {
		// Språkkontexten hanteras redan av URL-handler,
		// men vid AJAX-requests kan vi behöva läsa språket från POST-data
		if ( wp_doing_ajax() && isset( $_POST['cotranslate_lang'] ) ) {
			// Språket sätts redan av URL-handler via REQUEST_URI
			// Den här hooken finns som säkerhetsventil
		}
	}

	// =========================================================================
	// BREADCRUMBS
	// =========================================================================

	/**
	 * Filtrera WooCommerce breadcrumbs.
	 *
	 * @param array $crumbs Breadcrumbs-array.
	 * @return array Översatta breadcrumbs.
	 */
	public function filter_breadcrumbs( $crumbs ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $crumbs;
		}

		$language = cotranslate_get_current_language();

		foreach ( $crumbs as &$crumb ) {
			if ( ! empty( $crumb[0] ) ) {
				// Försök hämta strängöversättning
				$translated = $this->store->get_string_translation( $crumb[0], $language );
				if ( $translated ) {
					$crumb[0] = $translated;
				}
			}

			// Lägg till språkprefix på URL:er
			if ( ! empty( $crumb[1] ) ) {
				$crumb[1] = $this->url_handler->get_url_for_language( $crumb[1], $language );
			}
		}

		return $crumbs;
	}

	// =========================================================================
	// PRELOADING
	// =========================================================================

	/**
	 * Förladda produktöversättningar vid arkivsidor.
	 */
	public function preload_product_translations() {
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

	// =========================================================================
	// MEDDELANDEN
	// =========================================================================

	/**
	 * Filtrera "Tillagd i kundvagn"-meddelande.
	 *
	 * @param string $message Meddelande-HTML.
	 * @param array  $products Array med produkt-ID:n.
	 * @return string Översatt meddelande.
	 */
	public function filter_add_to_cart_message( $message, $products ) {
		if ( cotranslate_is_default_language() || is_admin() ) {
			return $message;
		}

		$language = cotranslate_get_current_language();

		// Byt ut produktnamn i meddelandet
		foreach ( $products as $product_id => $qty ) {
			$translation = $this->store->get_post_translation( $product_id, $language );
			if ( $translation && ! empty( $translation->translated_title ) ) {
				$original = get_the_title( $product_id );
				$message  = str_replace(
					'&ldquo;' . $original . '&rdquo;',
					'&ldquo;' . esc_html( $translation->translated_title ) . '&rdquo;',
					$message
				);
			}
		}

		return $message;
	}

	// =========================================================================
	// STRÄNGAR
	// =========================================================================

	/**
	 * Registrera vanliga WooCommerce-strängar för översättning.
	 *
	 * Dessa strängar dyker upp i tema/strängtabellen och kan
	 * översättas via output buffern eller frontend-editorn.
	 */
	public function register_wc_strings() {
		// WooCommerce-strängar hanteras av String Translator
		// via output buffern. Inga extra registreringar behövs.
	}

	/**
	 * Pass-through filter (returnerar oförändrat).
	 *
	 * @param mixed $value Värde.
	 * @return mixed Oförändrat värde.
	 */
	public function pass_through( $value ) {
		return $value;
	}
}
