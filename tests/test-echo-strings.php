<?php
// Fristående CLI-test: färdiga översättningar köas aldrig som källtext (ingen WordPress).
// Reproducerar "Welcome to Vansbro Vintersim!" → "Vansbro Vintersim Welcome!".
define( 'ABSPATH', __DIR__ . '/' );

// Minimala WP-stubbar som queue_untranslated() behöver.
function cotranslate_get_current_language() {
	return 'en';
}
function is_singular() {
	return false;
}
function get_queried_object_id() {
	return 0;
}
function wp_next_scheduled( $hook ) {
	return true;
}
function wp_schedule_single_event( $time, $hook ) {
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

// Låtsaslager: färdiga översättningar och en lista över vad som köas.
class Fake_Store {
	public $saved = array();
	public $calls = 0;
	public function get_translated_outputs( $language, $post_id = 0 ) {
		$this->calls++;
		return array(
			'Welcome to Vansbro Vintersim!'                         => true, // inläggstitel
			"Registration for the Vansbro Vintersim is now open.\u{00A0}" => true, // sträng med hårt mellanslag
		);
	}
	public function save_string_translation( $text, $language, $translated, $context, $post_id ) {
		$this->saved[] = $text;
	}
	public function backfill_first_seen( $texts, $language, $post_id ) {
	}
}

$ref   = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj   = $ref->newInstanceWithoutConstructor();
$store = new Fake_Store();

$prop = $ref->getProperty( 'store' );
if ( PHP_VERSION_ID < 80100 ) { $prop->setAccessible( true ); }
$prop->setValue( $obj, $store );

$untr = $ref->getProperty( 'untranslated' );
if ( PHP_VERSION_ID < 80100 ) { $untr->setAccessible( true ); }
$untr->setValue( $obj, array(
	'Welcome to Vansbro Vintersim!'                       => true, // eko av titeln
	'Registration for the Vansbro Vintersim is now open.' => true, // eko, utan hårt mellanslag
	'Välkommen till Vansbro Vintersim!'                   => true, // riktig källtext
	'Anmälan är öppen'                                    => true, // riktig källtext
) );

$obj->queue_untranslated();

check( 'bara de svenska texterna köas', $store->saved, array( 'Välkommen till Vansbro Vintersim!', 'Anmälan är öppen' ) );
check( 'översättningarna hämtas en gång per sidvisning', $store->calls, 1 );

// Inget att köa → lagret frågas inte alls.
$store2 = new Fake_Store();
$prop->setValue( $obj, $store2 );
$untr->setValue( $obj, array() );
$tro = $ref->getProperty( 'translated_outputs' );
if ( PHP_VERSION_ID < 80100 ) { $tro->setAccessible( true ); }
$tro->setValue( $obj, null );
$obj->queue_untranslated();
check( 'tom kö frågar inte lagret', $store2->calls, 0 );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
