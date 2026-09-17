<?php
// Fristående CLI-test för block som inte ska översättas (ingen WordPress).
//
//     php tests/test-held-blocks.php
//
// lang="xx" lämnar ett block orört när sidan visas på språk xx;
// translate="no" lämnar det orört på alla språk.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['test_lang'] = 'nb';

function cotranslate_get_current_language() {
	return $GLOBALS['test_lang'];
}
function cotranslate_get_default_language() {
	return 'en';
}
function cotranslate_get_enabled_languages() {
	return array( 'en', 'nb', 'nl', 'zh' );
}
function get_option( $name, $default = false ) {
	return 'home' === $name ? 'https://example.test' : $default;
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false );
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

$ref = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj = $ref->newInstanceWithoutConstructor();

function anropa( $obj, $metod, array $args ) {
	$m = new ReflectionMethod( $obj, $metod );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( $obj, $args );
}
// Lyft ur och returnera (html med platshållare, antal urlyfta block).
function lyft( $obj, $html, $sprak ) {
	$held = array();
	$ut   = anropa( $obj, 'hold_untranslatable_blocks', array( $html, $sprak, &$held ) );
	return array( $ut, $held );
}
function tur_och_retur( $obj, $html, $sprak ) {
	list( $ut, $held ) = lyft( $obj, $html, $sprak );
	return anropa( $obj, 'release_blocks', array( $ut, &$held ) );
}

$annons = '<article lang="nb"><h1>Vil du bli vår nye Sales & Logistics Coordinator?</h1><p>Her får du påvirke.</p></article>';

// --- lang: lyfts bara ur på sitt eget språk ---
list( $ut, $held ) = lyft( $obj, "<main><p>Open positions</p>$annons</main>", 'nb' );
check( 'nb: det norska blocket lyfts ur', count( $held ), 1 );
check( 'nb: texten utanför står kvar', strpos( $ut, 'Open positions' ) !== false, true );
check( 'nb: annonstexten syns inte för insamlingen', strpos( $ut, 'Sales & Logistics' ), false );

list( $ut, $held ) = lyft( $obj, "<main>$annons</main>", 'zh' );
check( 'zh: det norska blocket översätts som vanligt', count( $held ), 0 );
check( 'zh: html oförändrad', $ut, "<main>$annons</main>" );

list( , $held ) = lyft( $obj, '<div lang="nb-NO"><p>Hei</p></div>', 'nb' );
check( 'regionkod (nb-NO) räknas som nb', count( $held ), 1 );

list( , $held ) = lyft( $obj, "<div lang='nb'><p>Hei</p></div>", 'nb' );
check( 'enkla citattecken', count( $held ), 1 );

// --- translate="no": alla språk ---
foreach ( array( 'nb', 'nl', 'zh' ) as $sprak ) {
	list( , $held ) = lyft( $obj, '<span translate="no">Kaiyo Seafood</span>', $sprak );
	check( "translate=no lyfts ur på $sprak", count( $held ), 1 );
}
list( , $held ) = lyft( $obj, '<span translate="yes">Hej</span>', 'nb' );
check( 'translate=yes lyfts inte ur', count( $held ), 0 );

// --- Det som INTE får lyftas ur ---
$sida = '<html lang="nb"><head><title>x</title></head><body lang="nb"><p>Tekst</p></body></html>';
list( $ut, $held ) = lyft( $obj, $sida, 'nb' );
check( '<html lang> och <body lang> lyfter inte ur hela sidan', count( $held ), 0 );
check( 'sidan oförändrad', $ut, $sida );

$vaxlare = '<a href="/nb/" hreflang="nb">Norsk</a><link rel="alternate" hreflang="nb" href="/nb/">';
list( $ut, $held ) = lyft( $obj, $vaxlare, 'nb' );
check( 'hreflang är inte språkmärkning', count( $held ), 0 );

list( $ut, $held ) = lyft( $obj, '<img lang="nb" src="a.png" alt="Bilde"><p>Text</p>', 'nb' );
check( 'element utan sluttagg lyfts inte ur', count( $held ), 0 );
check( 'och resten av sidan är kvar', $ut, '<img lang="nb" src="a.png" alt="Bilde"><p>Text</p>' );

// --- Nästling ---
$nastlad = '<div lang="nb"><div class="inre"><div>djup</div></div><p>slut</p></div><div>efter</div>';
list( $ut, $held ) = lyft( $obj, $nastlad, 'nb' );
check( 'nästlade div: hela yttre blocket lyfts ur', array_values( $held )[0], '<div lang="nb"><div class="inre"><div>djup</div></div><p>slut</p></div>' );
check( 'nästlade div: det efter står kvar', substr( $ut, -16 ), '<div>efter</div>' );

$tva = '<p lang="nb">ett</p><p>mellan</p><p lang="nb">två</p>';
list( $ut, $held ) = lyft( $obj, $tva, 'nb' );
check( 'två block på samma sida', count( $held ), 2 );
check( 'texten mellan dem står kvar', strpos( $ut, '<p>mellan</p>' ) !== false, true );

$oavslutad = '<div lang="nb"><p>aldrig stängd';
list( $ut, $held ) = lyft( $obj, $oavslutad, 'nb' );
check( 'oavslutat element: inget lyfts ur, inget går förlorat', $ut, $oavslutad );

// --- Tur och retur är förlustfri ---
foreach ( array( $annons, $nastlad, $tva, $sida, $vaxlare, "<main>$annons<p>x</p>$annons</main>" ) as $i => $html ) {
	check( "tur och retur $i", tur_och_retur( $obj, $html, 'nb' ), $html );
}

// --- Platshållaren är en kommentar: osynlig för insamling och ersättning ---
list( $ut, ) = lyft( $obj, "<main>$annons</main>", 'nb' );
check( 'platshållaren är en HTML-kommentar', (bool) preg_match( '#^<main><!--COTRANSLATE_HELD_0--></main>$#', $ut ), true );

// --- Snabbväg ---
list( $ut, $held ) = lyft( $obj, '<p>Ingen märkning alls</p>', 'nb' );
check( 'sida utan märkning rörs inte', $ut, '<p>Ingen märkning alls</p>' );

echo "\n" . ( $fails ? "$fails FEL\n" : "Alla tester passerade.\n" );
exit( $fails ? 1 : 0 );
