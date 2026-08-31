<?php
// Fristående CLI-test för URL-omskrivningen i rewrite_remaining_urls()
// (ingen WordPress). Verifierar att hårdkodade länkar får språkprefix:
// rot-relativa länkar och naken domän utan avslutande snedstreck.
define( 'ABSPATH', __DIR__ . '/' );

// Minimala WP-/plugin-stubbar som metoden behöver.
$GLOBALS['test_current_lang'] = 'en';

function cotranslate_get_current_language() {
	return $GLOBALS['test_current_lang'];
}
function cotranslate_get_default_language() {
	return 'nb';
}
function cotranslate_get_enabled_languages() {
	return array( 'nb', 'en', 'nl', 'zh' );
}
function get_option( $name, $default = false ) {
	if ( 'home' === $name ) {
		return 'https://firstseafood.hemsida.eu';
	}
	return $default;
}

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

// Nå den privata metoden via reflection.
$ref = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj = $ref->newInstanceWithoutConstructor();

$method = $ref->getMethod( 'rewrite_remaining_urls' );
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }

$rewrite = function ( $html ) use ( $method, $obj ) {
	return $method->invoke( $obj, $html );
};

// --- Rot-relativa länkar (luckan från firstseafood: BB-knappar) ---
check(
	'relativ länk prefixas',
	$rewrite( '<a href="/kontakt/">Contact</a>' ),
	'<a href="/en/kontakt/">Contact</a>'
);
check(
	'relativ action prefixas',
	$rewrite( '<form action="/produkter/">' ),
	'<form action="/en/produkter/">'
);
check(
	'href="/" prefixas',
	$rewrite( '<a href="/">Home</a>' ),
	'<a href="/en/">Home</a>'
);

// --- Naken domän utan avslutande snedstreck (luckan från headerns Home-länk) ---
check(
	'naken domän prefixas',
	$rewrite( '<a href="https://firstseafood.hemsida.eu">Home</a>' ),
	'<a href="https://firstseafood.hemsida.eu/en">Home</a>'
);

// --- Regression: absolut länk med path fungerar som förut ---
check(
	'absolut länk med path prefixas',
	$rewrite( '<a href="https://firstseafood.hemsida.eu/om-oss/">About</a>' ),
	'<a href="https://firstseafood.hemsida.eu/en/om-oss/">About</a>'
);

// --- Länkar som INTE ska röras ---
$untouched = array(
	'protokoll-relativ URL'            => '<a href="//cdn.example.com/x">CDN</a>',
	'redan prefixad relativ länk'      => '<a href="/en/produkter/">Products</a>',
	'naket språkprefix utan snedstreck' => '<a href="/en">English</a>',
	'annat aktiverat språk'            => '<a href="/nl/x/">NL</a>',
	'wp-content-path'                  => '<a href="/wp-content/uploads/a.png">Img</a>',
	'fil-URL (pdf)'                    => '<a href="/dokument.pdf">PDF</a>',
	'ankarlänk'                        => '<a href="#top">Up</a>',
	'extern absolut länk'              => '<a href="https://external.se/">Ext</a>',
	'liknande extern domän'            => '<a href="https://firstseafood.hemsida.eu.evil.com/">Evil</a>',
	'redan prefixad absolut länk'      => '<a href="https://firstseafood.hemsida.eu/en/x/">X</a>',
	'absolut wp-content'               => '<a href="https://firstseafood.hemsida.eu/wp-content/a.css">CSS</a>',
);

foreach ( $untouched as $label => $html ) {
	check( "$label lämnas orörd", $rewrite( $html ), $html );
}

// --- Skyddade taggar (hreflang/canonical) lämnas orörda ---
$switcher = '<a href="https://firstseafood.hemsida.eu/" class="cotranslate-lang-option" hreflang="nb">Norsk</a>';
check( 'språkväljarens hreflang-länk skyddas', $rewrite( $switcher ), $switcher );

$hreflang_rel = '<a href="/" hreflang="nb">Norsk</a>';
check( 'relativ hreflang-länk skyddas', $rewrite( $hreflang_rel ), $hreflang_rel );

$canonical = '<link rel="canonical" href="https://firstseafood.hemsida.eu/en/om-oss/" />';
check( 'canonical skyddas', $rewrite( $canonical ), $canonical );

// --- Standardspråk: ingen omskrivning alls ---
$GLOBALS['test_current_lang'] = 'nb';
$mixed = '<a href="/kontakt/">K</a><a href="https://firstseafood.hemsida.eu">H</a>';
check( 'standardspråk lämnar allt orört', $rewrite( $mixed ), $mixed );
$GLOBALS['test_current_lang'] = 'en';

echo "\n" . ( $fails === 0 ? "ALLA TESTER GRÖNA\n" : "$fails TEST(ER) FALLERADE\n" );
exit( $fails === 0 ? 0 : 1 );
