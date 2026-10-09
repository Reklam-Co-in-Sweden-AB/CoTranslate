<?php
// Fristående CLI-test för språkversionerna i Yoasts sitemap (ingen WordPress).
//
//     php tests/test-sitemap.php
//
// Före 3.5.3 lade sitemapen in xhtml:link utan namnrymd (ogiltig XML) och
// alternativen blev tomma eller pekade på språkets startsida. Nu ska varje
// <url>-post följas av en post per språk med språkets adress i <loc>, och
// resultatet ska vara giltig XML.
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

function esc_url( $url ) {
	return str_replace( '&', '&#038;', $url );
}
class CoTranslate_Translation_Store {}
require __DIR__ . '/../includes/class-seo.php';

besok( '/sitemap.xml' );
$seo = new CoTranslate_SEO( new CoTranslate_Translation_Store(), $GLOBALS['test_handler'] );
$H   = 'https://firstseafood.hemsida.eu';

function post( $loc ) {
	return "\t<url>\n\t\t<loc>$loc</loc>\n\t\t<lastmod>2026-09-24T05:53:49+00:00</lastmod>\n"
		. "\t\t<image:image>\n\t\t\t<image:loc>https://firstseafood.hemsida.eu/wp-content/uploads/laks.webp</image:loc>\n\t\t</image:image>\n\t</url>\n";
}
function locs( $xml ) {
	preg_match_all( '#<loc>([^<]*)</loc>#', $xml, $m );
	return $m[1];
}

$ut = $seo->add_sitemap_language_urls( post( "$H/products/" ), array( 'loc' => "$H/products/" ) );
check( 'products: en + tre språk, nb/nl med översatta sluggar, zh engelska', locs( $ut ), array( "$H/products/", "$H/nb/produkter/", "$H/nl/producten/", "$H/zh/products/" ) );
check( 'products: lastmod följer med varje språk', substr_count( $ut, '<lastmod>2026-09-24T05:53:49+00:00</lastmod>' ), 4 );
check( 'products: bilderna orörda', substr_count( $ut, '<image:loc>https://firstseafood.hemsida.eu/wp-content/uploads/laks.webp</image:loc>' ), 4 );
check( 'ingen xhtml:link', strpos( $ut, 'xhtml' ), false );

$ut = $seo->add_sitemap_language_urls( post( "$H/" ), array( 'loc' => "$H/" ) );
check( 'startsidan', locs( $ut ), array( "$H/", "$H/nb/", "$H/nl/", "$H/zh/" ) );

$ut = $seo->add_sitemap_language_urls( post( "$H/careers/positions/sales-coordinator/" ), array( 'loc' => "$H/careers/positions/sales-coordinator/" ) );
check( 'annons under översatt bas', locs( $ut )[1], "$H/nb/karriere/positions/sales-coordinator/" );

$ut = $seo->add_sitemap_language_urls( post( "$H/bergen/" ), array( 'loc' => "$H/bergen/" ) );
check( 'sida utan översatt slug', locs( $ut ), array( "$H/bergen/", "$H/nb/bergen/", "$H/nl/bergen/", "$H/zh/bergen/" ) );

check( 'utan URL-array lämnas posten orörd', $seo->add_sitemap_language_urls( 'x', null ), 'x' );

$xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
	. $seo->add_sitemap_language_urls( post( "$H/products/" ), array( 'loc' => "$H/products/" ) ) . '</urlset>';
libxml_use_internal_errors( true );
check( 'giltig XML', false !== simplexml_load_string( $xml ), true );

echo $fails ? "\n$fails FEL\n" : "\nAlla tester gick igenom.\n";
exit( $fails ? 1 : 0 );
