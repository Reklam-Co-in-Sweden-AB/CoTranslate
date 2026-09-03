<?php
// Fristående CLI-test för ordlistans rena logik (ingen WordPress).
define( 'ABSPATH', __DIR__ . '/' ); // tillåt att klassfilen laddas utanför WP
require __DIR__ . '/../includes/class-glossary.php';

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

// Radparsning: likhetstecken, extra mellanslag, rad utan avgränsare, tom sida.
$parsed = CoTranslate_Glossary::parse_lines( "räka = prawn\n  torskrygg=cod loin  \nbara text\nkolja =\n= haddock\n\n" );
check( 'parsar likhetstecken och trimmar', $parsed, array( 'räka' => 'prawn', 'torskrygg' => 'cod loin' ) );

// Dubblett: sista raden vinner.
$parsed = CoTranslate_Glossary::parse_lines( "räka = shrimp\nräka = prawn" );
check( 'sista dubbletten vinner', $parsed, array( 'räka' => 'prawn' ) );

// Excel-inklistring: tab-separerat, rubrikrad med tab ignoreras inte men blir ett par —
// det är acceptabelt; tomma celler och rader utan avgränsare hoppas över.
$parsed = CoTranslate_Glossary::parse_lines( "räka\tprawn\r\nkolja\thaddock\r\nsej\t\r\n\tpollock\r\nlax" );
check( 'tab-separerat från Excel', $parsed, array( 'räka' => 'prawn', 'kolja' => 'haddock' ) );

// Tab har företräde framför likhetstecken (målterm kan innehålla =).
$parsed = CoTranslate_Glossary::parse_lines( "a=b\tc=d" );
check( 'tab vinner över likhetstecken', $parsed, array( 'a=b' => 'c=d' ) );

// Kontrolltecken i termer rensas bort.
$parsed = CoTranslate_Glossary::parse_lines( "r\x01äka = pr\x02awn" );
check( 'kontrolltecken tas bort', $parsed, array( 'r äka' => 'pr awn' ) );

// Formatering och TSV.
$entries = array( 'räka' => 'prawn', 'torskrygg' => 'cod loin' );
check( 'format_lines', CoTranslate_Glossary::format_lines( $entries ), "räka = prawn\ntorskrygg = cod loin" );
check( 'to_tsv', CoTranslate_Glossary::to_tsv( $entries ), "räka\tprawn\ntorskrygg\tcod loin" );

// Rundtur: format → parse ger samma uppsättning.
check( 'rundtur format/parse', CoTranslate_Glossary::parse_lines( CoTranslate_Glossary::format_lines( $entries ) ), $entries );

// Promptblock: tomt när inget finns.
check( 'promptblock tomt', CoTranslate_Glossary::build_prompt_block( '', array() ), '' );
check( 'promptblock tomt vid bara blanksteg', CoTranslate_Glossary::build_prompt_block( '   ', array() ), '' );

// Promptblock: innehåller kontext och alla par.
$block = CoTranslate_Glossary::build_prompt_block( 'Fiskgrossist', $entries );
check( 'promptblock har kontext', strpos( $block, 'Context: Fiskgrossist' ) !== false, true );
check( 'promptblock har första paret', strpos( $block, "\nräka = prawn" ) !== false, true );
check( 'promptblock har andra paret', strpos( $block, "\ntorskrygg = cod loin" ) !== false, true );

// Promptblock: bara termer, ingen kontext.
$block = CoTranslate_Glossary::build_prompt_block( '', array( 'x' => 'y' ) );
check( 'promptblock utan kontext saknar Context-rad', strpos( $block, 'Context:' ), false );

// Ändrade termer: nya, ändrade och borttagna.
$changed = CoTranslate_Glossary::changed_terms(
	array( 'räka' => 'shrimp', 'kolja' => 'haddock', 'sej' => 'saithe' ),
	array( 'räka' => 'prawn', 'kolja' => 'haddock', 'lax' => 'salmon' )
);
sort( $changed );
check( 'changed_terms hittar ny, ändrad och borttagen', $changed, array( 'lax', 'räka', 'sej' ) );
check( 'changed_terms tomt vid identiskt', CoTranslate_Glossary::changed_terms( $entries, $entries ), array() );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
