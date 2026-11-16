<?php
// Fristående CLI-test för <meta>-taggar, og:locale och schemats språk (ingen WordPress).
//
//     php tests/test-meta-og.php
//
// Före 3.5.5 översattes content-attributet i alla <meta>: og:type blev
// «nettsted» och «网站», og:locale stod på en_US och schemat på en-US på
// alla språk.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['test_lang'] = 'nb';

function cotranslate_get_current_language() {
	return $GLOBALS['test_lang'];
}
function cotranslate_get_default_language() {
	return 'en';
}
function cotranslate_is_default_language() {
	return 'en' === $GLOBALS['test_lang'];
}
$GLOBALS['test_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default;
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false );
}

// Bara locale-funktionen ur helpers.php — resten kräver WordPress.
$helpers = file_get_contents( __DIR__ . '/../includes/helpers.php' );
preg_match( '/function cotranslate_get_locale\(.*?\n}\n/s', $helpers, $m );
eval( $m[0] );
require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-string-translator.php';
class CoTranslate_Translation_Store {}
class CoTranslate_URL_Handler {}
require __DIR__ . '/../includes/class-seo.php';

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

// --- content i <meta> ---
$obj  = ( new ReflectionClass( 'CoTranslate_String_Translator' ) )->newInstanceWithoutConstructor();
$prop = new ReflectionProperty( $obj, 'dictionary' );
if ( PHP_VERSION_ID < 80100 ) {
	$prop->setAccessible( true );
}
$prop->setValue( $obj, array(
	'website'                  => 'nettsted',
	'article'                  => 'artikkel',
	'Norwegian seafood'        => 'Norsk sjømat',
	'Our products'             => 'Våre produkter',
	'Est. reading time'        => 'Beregnet lesetid',
	'width=device-width'       => 'bredde=enhetsbredde',
	'Products'                 => 'Produkter',
) );
$attr = new ReflectionMethod( $obj, 'translate_attributes' );
if ( PHP_VERSION_ID < 80100 ) {
	$attr->setAccessible( true );
}
$html = '<meta property="og:type" content="website" />'
	. '<meta content="article" property="og:type" />'
	. '<meta name="viewport" content="width=device-width" />'
	. '<meta property="og:description" content="Norwegian seafood" />'
	. '<meta name="description" content="Norwegian seafood" />'
	. '<meta property="og:title" content="Our products" />'
	. '<meta name="twitter:label1" content="Est. reading time" />'
	. '<img alt="Products" src="x.jpg" />';
$ut = $attr->invoke( $obj, $html );

check( 'og:type orörd', str_contains( $ut, '<meta property="og:type" content="website" />' ), true );
check( 'og:type orörd även när content står först', str_contains( $ut, '<meta content="article" property="og:type" />' ), true );
check( 'viewport orörd', str_contains( $ut, 'content="width=device-width"' ), true );
check( 'og:description översätts', str_contains( $ut, '<meta property="og:description" content="Norsk sjømat" />' ), true );
check( 'description översätts', str_contains( $ut, '<meta name="description" content="Norsk sjømat" />' ), true );
check( 'og:title översätts', str_contains( $ut, 'content="Våre produkter"' ), true );
check( 'twitter:label1 översätts', str_contains( $ut, 'content="Beregnet lesetid"' ), true );
check( 'alt utanför meta översätts som förut', str_contains( $ut, 'alt="Produkter"' ), true );
check( 'inga platshållare kvar', str_contains( $ut, 'cotranslate-meta' ), false );

// --- locale ---
check( 'locale nb', cotranslate_get_locale( 'nb' ), 'nb_NO' );
check( 'locale nl', cotranslate_get_locale( 'nl' ), 'nl_NL' );
check( 'locale zh', cotranslate_get_locale( 'zh' ), 'zh_CN' );
check( 'locale sv med bindestreck', cotranslate_get_locale( 'sv', '-' ), 'sv-SE' );
check( 'locale en utan vald variant', cotranslate_get_locale( 'en' ), 'en_US' );
$GLOBALS['test_options']['cotranslate_english_variant'] = 'EN-GB';
check( 'locale en med brittisk variant', cotranslate_get_locale( 'en' ), 'en_GB' );
unset( $GLOBALS['test_options']['cotranslate_english_variant'] );

$seo = ( new ReflectionClass( 'CoTranslate_SEO' ) )->newInstanceWithoutConstructor();
check( 'og:locale på /nb/', $seo->filter_og_locale( 'en_US' ), 'nb_NO' );
$GLOBALS['test_lang'] = 'zh';
check( 'og:locale på /zh/', $seo->filter_og_locale( 'en_US' ), 'zh_CN' );

// --- schema ---
$graph = array(
	array( '@type' => 'WebPage', 'inLanguage' => 'en-US', 'breadcrumb' => array( '@id' => 'x' ) ),
	array( '@type' => 'WebSite', 'inLanguage' => 'en-US', 'potentialAction' => array( array( 'inLanguage' => 'en-US' ) ) ),
	array( '@type' => 'Organization', 'name' => 'First Seafood' ),
);
$ut = $seo->filter_schema_language( $graph );
check( 'schema: WebPage', $ut[0]['inLanguage'], 'zh-CN' );
check( 'schema: nästlat', $ut[1]['potentialAction'][0]['inLanguage'], 'zh-CN' );
check( 'schema: delar utan inLanguage får inget', isset( $ut[2]['inLanguage'] ), false );

$GLOBALS['test_lang'] = 'en';
check( 'engelska: og:locale orörd', $seo->filter_og_locale( 'en_US' ), 'en_US' );
check( 'engelska: schema orört', $seo->filter_schema_language( $graph ), $graph );

echo $fails ? "\n$fails FEL\n" : "\nAlla tester gick igenom.\n";
exit( $fails ? 1 : 0 );
