<?php
// Fristående CLI-test för översatta sluggar i URL-hanteraren och i
// buffertens länkomskrivning (ingen WordPress).
//
//     php tests/test-url-slugs.php
//
// Verifierar kedjan som saknades före 3.5.0: att en inkommande adress med
// översatt slug slås upp till originalet, att utgående länkar får språkets
// sluggar, att språkväxlaren går rätt mellan två språk som BÅDA har egna
// sluggar, och att kinesiskan — där funktionen är avstängd — lämnas orörd.
define( 'ABSPATH', __DIR__ . '/' );
define( 'COTRANSLATE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['test_options'] = array(
	'home'                          => 'https://firstseafood.hemsida.eu',
	'cotranslate_default_language'  => 'en',
	'cotranslate_enabled_languages' => array( 'en', 'nb', 'nl', 'zh' ),
	'cotranslate_translate_slugs'   => true,
	'cotranslate_slug_languages'    => array( 'nb', 'nl' ),
);

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function wp_doing_cron() {
	return false;
}
function cotranslate_get_default_language() {
	return get_option( 'cotranslate_default_language' );
}
function cotranslate_get_enabled_languages() {
	return get_option( 'cotranslate_enabled_languages' );
}
function cotranslate_get_current_language() {
	return $GLOBALS['test_handler']->get_current_language();
}

require __DIR__ . '/../includes/class-slug-map.php';

// Slugkartan med firstseafoods data, i stället för databasen.
function cotranslate_slug_map() {
	static $map = null;
	if ( null === $map ) {
		$rader = array(
			'nb' => array(
				array( 'original' => 'products', 'translated' => 'produkter' ),
				array( 'original' => 'about-us', 'translated' => 'om-oss' ),
				array( 'original' => 'careers', 'translated' => 'karriere' ),
				array( 'original' => 'contact', 'translated' => 'kontakt' ),
			),
			'nl' => array(
				array( 'original' => 'products', 'translated' => 'producten' ),
				array( 'original' => 'contact', 'translated' => 'contact' ),
			),
			'zh' => array(
				array( 'original' => 'products', 'translated' => '%e4%ba%a7%e5%93%81' ),
			),
		);
		$map = new CoTranslate_Slug_Map(
			function ( $sprak ) use ( $rader ) {
				return $rader[ $sprak ] ?? array();
			},
			function () {
				return array( 'products', 'about-us', 'careers', 'contact', 'bergen', 'oslo' );
			},
			get_option( 'cotranslate_slug_languages' )
		);
	}
	return $map;
}
function cotranslate_translate_path( $path, $language ) {
	return cotranslate_slug_map()->translate_path( $path, $language );
}
function cotranslate_original_path( $path, $language ) {
	return cotranslate_slug_map()->original_path( $path, $language );
}

require __DIR__ . '/../includes/class-url-handler.php';
require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-string-translator.php';

$fails = 0;
function check( $label, $got, $expected ) {
	global $fails;
	if ( $got === $expected ) {
		echo "PASS: $label\n";
	} else {
		$fails++;
		echo "FAIL: $label\n  fick:      " . var_export( $got, true ) . "\n  förväntat: " . var_export( $expected, true ) . "\n";
	}
}

/**
 * Ny hanterare för en given adress — konstruktorn tolkar REQUEST_URI.
 */
function besok( $uri ) {
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$GLOBALS['test_handler']   = new CoTranslate_URL_Handler();
	return $GLOBALS['test_handler'];
}
function privat( $objekt, $metod, ...$args ) {
	$m = new ReflectionMethod( $objekt, $metod );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invoke( $objekt, ...$args );
}

$H = 'https://firstseafood.hemsida.eu';

// --- Inkommande: WordPress ska få originalsluggen ---
$h = besok( '/nb/produkter/' );
check( 'in: språket känns igen', $h->get_current_language(), 'nb' );
check( 'in: /nb/produkter/ blir /products/ för WordPress', $_SERVER['REQUEST_URI'], '/products/' );
check( 'in: ursprunglig adress bevaras', $h->get_original_request_uri(), '/nb/produkter/' );

besok( '/nb/om-oss/kontakt/?ref=mail' );
check( 'in: undersida och query', $_SERVER['REQUEST_URI'], '/about-us/contact/?ref=mail' );

besok( '/nb/karriere/positions/sales-coordinator/' );
check( 'in: annonsadress med översatt bas', $_SERVER['REQUEST_URI'], '/careers/positions/sales-coordinator/' );

besok( '/nb/products/' );
check( 'in: engelsk slug under /nb/ fungerar fortfarande', $_SERVER['REQUEST_URI'], '/products/' );

$h = besok( '/zh/products/' );
check( 'in: kinesiska, engelsk slug', $_SERVER['REQUEST_URI'], '/products/' );
check( 'in: kinesiska, språket känns igen', $h->get_current_language(), 'zh' );

besok( '/products/' );
check( 'in: standardspråket rörs inte', $_SERVER['REQUEST_URI'], '/products/' );

// --- Utgående: länkfiltren ---
$h = besok( '/nb/' );
check( 'ut: sidlänk får prefix och norsk slug', $h->filter_page_link( "$H/products/", 188 ), "$H/nb/produkter/" );
check( 'ut: undersida', $h->filter_page_link( "$H/about-us/contact/", 1 ), "$H/nb/om-oss/kontakt/" );
check( 'ut: annonslänk', $h->filter_post_type_link( "$H/careers/positions/sales-coordinator/", null ), "$H/nb/karriere/positions/sales-coordinator/" );
check( 'ut: startsidan', $h->filter_home_url( "$H/", '/', null, null ), "$H/nb/" );
check( 'ut: query och ankare står kvar', $h->filter_generic_link( "$H/contact/?a=1#ovrige" ), "$H/nb/kontakt/?a=1#ovrige" );
check( 'ut: mediefil rörs inte', $h->filter_generic_link( "$H/wp-content/uploads/products/bild.jpg" ), "$H/wp-content/uploads/products/bild.jpg" );
check( 'ut: annan domän rörs inte', $h->filter_generic_link( 'https://kaiyoseafood.com/products/' ), 'https://kaiyoseafood.com/products/' );

$h = besok( '/zh/' );
check( 'ut: kinesiska får prefix men engelsk slug', $h->filter_page_link( "$H/products/", 188 ), "$H/zh/products/" );

$h = besok( '/' );
check( 'ut: standardspråket får varken prefix eller ny slug', $h->filter_page_link( "$H/products/", 188 ), "$H/products/" );

// --- Språkväxlaren, hreflang, canonical: get_url_for_language() ---
$h = besok( '/nb/produkter/' );
check( 'byte: norska → engelska', $h->get_url_for_language( "$H/nb/produkter/", 'en' ), "$H/products/" );
check( 'byte: norska → nederländska (båda har egna sluggar)', $h->get_url_for_language( "$H/nb/produkter/", 'nl' ), "$H/nl/producten/" );
check( 'byte: norska → kinesiska', $h->get_url_for_language( "$H/nb/produkter/", 'zh' ), "$H/zh/products/" );
check( 'byte: norska → norska', $h->get_url_for_language( "$H/nb/produkter/", 'nb' ), "$H/nb/produkter/" );
check( 'byte: engelska → norska', $h->get_url_for_language( "$H/products/", 'nb' ), "$H/nb/produkter/" );
check( 'byte: undersida med query', $h->get_url_for_language( "$H/nb/om-oss/kontakt/?a=1", 'nl' ), "$H/nl/about-us/contact/?a=1" );
check( 'byte: startsidan', $h->get_url_for_language( "$H/nb/", 'nl' ), "$H/nl/" );
check( 'byte: naken språkrot', $h->get_url_for_language( "$H/nb", 'en' ), "$H/" );

// --- Omdirigeringens mål: den kanoniska sökvägen ---
$kanon = function ( $sokvag, $sprak ) {
	return cotranslate_translate_path( cotranslate_original_path( $sokvag, $sprak ), $sprak );
};
check( 'kanon: engelsk slug under /nb/ ska till den norska', $kanon( '/products/', 'nb' ), '/produkter/' );
check( 'kanon: redan rätt — ingen omdirigering', $kanon( '/produkter/', 'nb' ), '/produkter/' );
check( 'kanon: stabil efter ett varv (ingen slinga)', $kanon( $kanon( '/products/', 'nb' ), 'nb' ), '/produkter/' );
check( 'kanon: kinesiska omdirigeras aldrig', $kanon( '/products/', 'zh' ), '/products/' );
check( 'kanon: sida utan översatt slug', $kanon( '/bergen/', 'nb' ), '/bergen/' );

// --- Bufferten: länkar inskrivna i innehållet ---
besok( '/nb/' );
$ref     = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj     = $ref->newInstanceWithoutConstructor();
$rewrite = function ( $html ) use ( $obj ) {
	return privat( $obj, 'rewrite_remaining_urls', $html );
};
check( 'buffert: absolut länk får prefix och norsk slug', $rewrite( "<a href=\"$H/products/\">Se</a>" ), "<a href=\"$H/nb/produkter/\">Se</a>" );
check( 'buffert: rotrelativ länk', $rewrite( '<a href="/contact/">Kontakt</a>' ), '<a href="/nb/kontakt/">Kontakt</a>' );
check( 'buffert: ankare bevaras', $rewrite( "<a href=\"$H/contact/#ovrige-avdelinger\">x</a>" ), "<a href=\"$H/nb/kontakt/#ovrige-avdelinger\">x</a>" );
check( 'buffert: naken domän', $rewrite( "<a href=\"$H\">Hjem</a>" ), "<a href=\"$H/nb\">Hjem</a>" );
check( 'buffert: redan prefixad länk lämnas', $rewrite( "<a href=\"$H/nb/produkter/\">x</a>" ), "<a href=\"$H/nb/produkter/\">x</a>" );
check( 'buffert: hreflang-länk lämnas', $rewrite( "<link rel=\"alternate\" hreflang=\"en\" href=\"$H/products/\">" ), "<link rel=\"alternate\" hreflang=\"en\" href=\"$H/products/\">" );
check( 'buffert: bildfil lämnas', $rewrite( "<a href=\"$H/wp-content/uploads/products.pdf\">x</a>" ), "<a href=\"$H/wp-content/uploads/products.pdf\">x</a>" );

besok( '/zh/' );
check( 'buffert: kinesiska får prefix men engelsk slug', $rewrite( "<a href=\"$H/products/\">x</a>" ), "<a href=\"$H/zh/products/\">x</a>" );

// --- <head>: hreflang och canonical utan besökets frågesträng ---
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'cotranslate_is_default_language' ) ) {
	function cotranslate_is_default_language() {
		return cotranslate_get_current_language() === cotranslate_get_default_language();
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action() {
		return true;
	}
}
require __DIR__ . '/../includes/class-seo.php';
$head = function ( $uri, $metod ) {
	$handler = besok( $uri );
	$seo     = ( new ReflectionClass( 'CoTranslate_SEO' ) )->newInstanceWithoutConstructor();
	$prop    = new ReflectionProperty( $seo, 'url_handler' );
	if ( PHP_VERSION_ID < 80100 ) {
		$prop->setAccessible( true );
	}
	$prop->setValue( $seo, $handler );
	ob_start();
	$seo->$metod();
	return ob_get_clean();
};
$alla_href = function ( $html ) {
	preg_match_all( '/href="([^"]*)"/', $html, $m );
	return $m[1];
};
check(
	'hreflang: frågesträngen följer inte med (startsidan)',
	$alla_href( $head( '/?nocache=1&utm_source=x', 'add_hreflang_tags' ) ),
	array( "$H/", "$H/nb/", "$H/nl/", "$H/zh/", "$H/" )
);
check(
	'hreflang: frågesträngen följer inte med (norsk undersida)',
	$alla_href( $head( '/nb/produkter/?fbclid=abc', 'add_hreflang_tags' ) ),
	array( "$H/products/", "$H/nb/produkter/", "$H/nl/producten/", "$H/zh/products/", "$H/products/" )
);
check( 'hreflang: naken frågesträng ger startsidan', $alla_href( $head( '/?x=1', 'add_hreflang_tags' ) )[0], "$H/" );
check( 'canonical: frågesträngen följer inte med', $alla_href( $head( '/nb/produkter/?fbclid=abc', 'fix_canonical' ) ), array( "$H/nb/produkter/" ) );
check( 'canonical: ingen egen tagg på standardspråket', $head( '/products/', 'fix_canonical' ), '' );
define( 'WPSEO_VERSION', 'test' );
check( 'canonical: ingen egen tagg när Yoast skriver ut den', $head( '/nb/produkter/', 'fix_canonical' ), '' );

echo "\n" . ( $fails ? "$fails FEL\n" : "Alla tester passerade.\n" );
exit( $fails ? 1 : 0 );
