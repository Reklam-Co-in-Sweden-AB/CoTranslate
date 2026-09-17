<?php
// Fristående CLI-test för CoTranslate_Slug_Map (ingen WordPress).
//
//     php tests/test-slug-map.php
//
// Testar sökvägsöversättningen åt båda håll: undersidor, posttyper med fast
// bas, krockskyddet, procentkodade sluggar och att avstängda språk lämnas.
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-slug-map.php';

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

// Datan speglar firstseafood: engelska original, norska och nederländska sluggar.
$rader = array(
	'nb' => array(
		array( 'original' => 'products', 'translated' => 'produkter' ),
		array( 'original' => 'about-us', 'translated' => 'om-oss' ),
		array( 'original' => 'careers', 'translated' => 'karriere' ),
		array( 'original' => 'contact', 'translated' => 'kontakt' ),
		array( 'original' => 'sustainability-quality', 'translated' => 'baerekraft-kvalitet' ),
		array( 'original' => 'bergen', 'translated' => 'bergen' ),        // identisk: ska inte tas med
		array( 'original' => 'home', 'translated' => '' ),                // tom: ska inte tas med
		array( 'original' => 'press', 'translated' => 'oslo' ),           // krock 1: "oslo" är en riktig sida
		array( 'original' => 'investor', 'translated' => 'nyheter' ),     // krock 2: delas med nästa rad
		array( 'original' => 'news', 'translated' => 'nyheter' ),
		array( 'original' => 'smoked-salmon', 'translated' => 'r%c3%b8kt-laks' ),
	),
	'nl' => array(
		array( 'original' => 'products', 'translated' => 'producten' ),
	),
	'zh' => array(
		array( 'original' => 'products', 'translated' => '%e4%ba%a7%e5%93%81' ),
	),
);
$original = array( 'products', 'about-us', 'careers', 'contact', 'sustainability-quality', 'bergen', 'oslo', 'home', 'press', 'investor', 'news', 'smoked-salmon' );

$anrop = 0;
$karta = new CoTranslate_Slug_Map(
	function ( $sprak ) use ( $rader, &$anrop ) {
		$anrop++;
		return $rader[ $sprak ] ?? array();
	},
	function () use ( $original ) {
		return $original;
	},
	array( 'nb', 'nl' ) // kinesiskan behåller engelska sluggar
);

// --- Framåt: original → översatt ---
check( 'enkel sida', $karta->translate_path( '/products/', 'nb' ), '/produkter/' );
check( 'utan avslutande snedstreck', $karta->translate_path( '/products', 'nb' ), '/produkter' );
check( 'roten lämnas', $karta->translate_path( '/', 'nb' ), '/' );
check( 'undersida: varje känt segment byts', $karta->translate_path( '/about-us/contact/', 'nb' ), '/om-oss/kontakt/' );
check( 'posttyp med fast bas: basen byts, resten står kvar', $karta->translate_path( '/careers/positions/sales-coordinator/', 'nb' ), '/karriere/positions/sales-coordinator/' );
check( 'okänd sida lämnas', $karta->translate_path( '/trim-guide/', 'nb' ), '/trim-guide/' );
check( 'identisk slug lämnas', $karta->translate_path( '/bergen/', 'nb' ), '/bergen/' );
check( 'annat språk har egen tabell', $karta->translate_path( '/products/', 'nl' ), '/producten/' );

// --- Bakåt: översatt → original ---
check( 'bakåt, enkel sida', $karta->original_path( '/produkter/', 'nb' ), '/products/' );
check( 'bakåt, undersida', $karta->original_path( '/om-oss/kontakt/', 'nb' ), '/about-us/contact/' );
check( 'bakåt, posttyp med fast bas', $karta->original_path( '/karriere/positions/sales-coordinator/', 'nb' ), '/careers/positions/sales-coordinator/' );
check( 'bakåt: engelsk slug under norskt prefix går igenom orörd', $karta->original_path( '/products/', 'nb' ), '/products/' );
check( 'tur och retur', $karta->original_path( $karta->translate_path( '/sustainability-quality/', 'nb' ), 'nb' ), '/sustainability-quality/' );

// --- Krockskydd ---
check( 'krock 1: översatt slug är en riktig sidas slug — används inte', $karta->translate_path( '/press/', 'nb' ), '/press/' );
check( 'krock 1: den riktiga sidan kapas inte på vägen in', $karta->original_path( '/oslo/', 'nb' ), '/oslo/' );
check( 'krock 2: delad slug används inte (första)', $karta->translate_path( '/investor/', 'nb' ), '/investor/' );
check( 'krock 2: delad slug används inte (andra)', $karta->translate_path( '/news/', 'nb' ), '/news/' );
check( 'krock 2: går inte att slå upp bakåt', $karta->original_path( '/nyheter/', 'nb' ), '/nyheter/' );

// --- Språk utan översatta sluggar ---
check( 'kinesiska: avstängt, engelsk slug behålls', $karta->translate_path( '/products/', 'zh' ), '/products/' );
check( 'kinesiska: inkommande lämnas', $karta->original_path( '/%e4%ba%a7%e5%93%81/', 'zh' ), '/%e4%ba%a7%e5%93%81/' );
check( 'is_enabled_for nb', $karta->is_enabled_for( 'nb' ), true );
check( 'is_enabled_for zh', $karta->is_enabled_for( 'zh' ), false );

// --- Procentkodning ---
check( 'ut: sparad procentkodad form används', $karta->translate_path( '/smoked-salmon/', 'nb' ), '/r%c3%b8kt-laks/' );
check( 'in: gemener känns igen', $karta->original_path( '/r%c3%b8kt-laks/', 'nb' ), '/smoked-salmon/' );
check( 'in: versaler från webbläsaren känns igen', $karta->original_path( '/r%C3%B8kt-laks/', 'nb' ), '/smoked-salmon/' );
check( 'in: avkodad form känns igen', $karta->original_path( '/røkt-laks/', 'nb' ), '/smoked-salmon/' );
check( 'in: versaler i ASCII-slug', $karta->original_path( '/Produkter/', 'nb' ), '/products/' );

// --- Robusthet ---
check( 'tom sträng', $karta->translate_path( '', 'nb' ), '' );
check( 'okänt språk', $karta->translate_path( '/products/', 'de' ), '/products/' );

$fore = $anrop;
$karta->translate_path( '/products/', 'nb' );
$karta->original_path( '/produkter/', 'nb' );
check( 'tabellen laddas en gång per språk', $anrop, $fore );

$karta->flush();
$karta->translate_path( '/products/', 'nb' );
check( 'flush tvingar ny laddning', $anrop, $fore + 1 );

echo "\n" . ( $fails ? "$fails FEL\n" : "Alla tester passerade.\n" );
exit( $fails ? 1 : 0 );
