<?php
// Fristående CLI-test: Ninja Tables kolumnrubriker (ingen WordPress).
// Reproducerar vansbrosimningen.se/en/about-us/statistics-and-facts/: tabellens
// JavaScript ritade om <thead> med de svenska rubrikerna från skriptet.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lang']      = 'en';
$GLOBALS['scheduled'] = 0;
function cotranslate_is_default_language() {
	return 'sv' === $GLOBALS['lang'];
}
function cotranslate_get_current_language() {
	return $GLOBALS['lang'];
}
function is_singular() {
	return true;
}
function get_queried_object_id() {
	return 395;
}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_schedule_single_event( $time, $hook ) {
	$GLOBALS['scheduled']++;
}

require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-string-translator.php';

// Låtsaslager med samma kontrakt: null = saknas, '' = köad.
class CoTranslate_Translation_Store {
	public $rows  = array(
		'Årtal'   => 'Year',
		'Anmälda' => '',
		'Sök'     => 'Search',
	);
	public $saved = array();
	public function get_string_translation( $text, $language ) {
		return array_key_exists( trim( $text ), $this->rows ) ? $this->rows[ trim( $text ) ] : null;
	}
	public function save_string_translation( $text, $language, $translated, $context, $post_id ) {
		$this->saved[] = array( $text, $translated, $post_id );
	}
}

require __DIR__ . '/../includes/class-ninja-tables.php';

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

$vars = array(
	'table_id' => '397',
	'columns'  => array(
		array( 'name' => 'ninja_column_1', 'title' => 'Årtal ' ),
		array( 'name' => 'ninja_column_2', 'title' => '' ),
		array( 'name' => 'ninja_column_3', 'title' => 'Anmälda' ),
		array( 'name' => 'ninja_column_4', 'title' => 'Helt ny rubrik' ),
		array( 'name' => 'ninja_column_5', 'title' => '2018' ),
	),
	'settings' => array(
		'filtering' => array( 'dropdownTitle' => 'Sök i', 'placeholder' => 'Sök' ),
	),
);

$store = new CoTranslate_Translation_Store();
$nt    = new CoTranslate_Ninja_Tables( $store );
$out   = $nt->filter_table_vars( $vars );

check( 'översatt rubrik (blanksteg i kanten)', $out['columns'][0]['title'], 'Year' );
check( 'tom rubrik orörd', $out['columns'][1]['title'], '' );
check( 'köad rubrik står kvar tills den är översatt', $out['columns'][2]['title'], 'Anmälda' );
check( 'ny rubrik står kvar', $out['columns'][3]['title'], 'Helt ny rubrik' );
check( 'siffror orörda', $out['columns'][4]['title'], '2018' );
check( 'sökfältets platshållare översätts', $out['settings']['filtering']['placeholder'], 'Search' );
check( 'övriga nycklar orörda', $out['table_id'], '397' );
check( 'bara saknade texter köas, aldrig befintliga', $store->saved, array(
	array( 'Helt ny rubrik', '', 395 ),
	array( 'Sök i', '', 395 ),
) );
check( 'cron schemaläggs', $GLOBALS['scheduled'], 1 );

// Samma text två gånger på sidan köas en gång.
$nt->filter_table_vars( $vars );
check( 'ingen dubbelköning', count( $store->saved ), 2 );

// Standardspråket rörs inte.
$GLOBALS['lang'] = 'sv';
$store2 = new CoTranslate_Translation_Store();
$sv     = ( new CoTranslate_Ninja_Tables( $store2 ) )->filter_table_vars( $vars );
check( 'svenska sidan oförändrad', $sv, $vars );
check( 'svenska sidan köar inget', $store2->saved, array() );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
