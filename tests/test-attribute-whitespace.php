<?php
// Fristående CLI-test: attribut med blanksteg i kanten (ingen WordPress).
// Reproducerar vansbrosimningen.se: aria-label="Anmäl dig här " (avslutande
// blanksteg) missade ordboken, köades som ny text och tömde den befintliga
// översättningen "Sign up here" för hela sajten – sidor som renderades innan
// cron översatt den igen cachades på svenska.
define( 'ABSPATH', __DIR__ . '/' );

// Minimala WP-stubbar.
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
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

// Låtsaslager: registrerar vad som köas (sparas med tom översättning).
class Fake_Store {
	public $saved = array();
	public function get_translated_outputs( $language, $post_id = 0 ) {
		return array();
	}
	public function save_string_translation( $text, $language, $translated, $context, $post_id ) {
		$this->saved[] = $text;
	}
	public function backfill_first_seen( $texts, $language, $post_id ) {
	}
}

$ref = new ReflectionClass( 'CoTranslate_String_Translator' );

function prop( $ref, $obj, $name, $value ) {
	$p = $ref->getProperty( $name );
	if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
	$p->setValue( $obj, $value );
}
function get_prop( $ref, $obj, $name ) {
	$p = $ref->getProperty( $name );
	if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
	return $p->getValue( $obj );
}

$obj   = $ref->newInstanceWithoutConstructor();
$store = new Fake_Store();
$known = array(
	'Anmäl dig här'            => 'Sign up here',
	'Läs mer'                  => 'Read more',
	'Anmälan öppnar inom kort' => '', // köad, väntar på översättning
);
prop( $ref, $obj, 'store', $store );
prop( $ref, $obj, 'known', $known );
prop( $ref, $obj, 'dictionary', array_filter( $known ) );
prop( $ref, $obj, 'normalized_dictionary', null );
prop( $ref, $obj, 'untranslated', array() );

$attr = $ref->getMethod( 'translate_attributes' );
if ( PHP_VERSION_ID < 80100 ) { $attr->setAccessible( true ); }

$out = $attr->invoke( $obj, '<a aria-label="Anmäl dig här ">x</a><a aria-label=" Läs mer">y</a><a aria-label="Anmälan öppnar inom kort ">z</a>' );

check( 'aria-label med avslutande blanksteg översätts', strpos( $out, 'aria-label="Sign up here"' ) !== false, true );
check( 'aria-label med inledande blanksteg översätts', strpos( $out, 'aria-label="Read more"' ) !== false, true );

$obj->queue_untranslated();
check( 'kända texter köas aldrig om (översättningen töms inte)', $store->saved, array() );

// En helt ny text köas fortfarande.
prop( $ref, $obj, 'untranslated', array( 'Helt ny knapp ' => true, 'Anmäl dig här ' => true ) );
$obj->queue_untranslated();
check( 'ny text köas, känd text med blanksteg gör det inte', $store->saved, array( 'Helt ny knapp ' ) );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
