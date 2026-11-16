<?php
// Fristående CLI-test (ingen WordPress).
//
//     php tests/test-translated-output.php
//
// Två fel som syntes på en färdigöversatt sajt:
//
//  1. Sluggen översattes utan å, ä och ö: "mot-nils" blev "toward-nils"
//     och "anga-kondensat" blev "handle-condensate".
//  2. Text som titel- och innehållsfiltren redan översatt köades som ny
//     källtext och översattes en gång till.
define( 'ABSPATH', __DIR__ . '/' );

function remove_accents( $text ) {
	return strtr( $text, array( 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'A', 'Ä' => 'A', 'Ö' => 'O', 'é' => 'e', 'ø' => 'o' ) );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-post-translator.php';
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

// --- 1. Slugord får tillbaka sina diakriter ---
$restore = array( 'CoTranslate_Post_Translator', 'restore_slug_diacritics' );

check( 'slug: "mot" blir "Möt" ur titeln',
	call_user_func( $restore, 'mot-nils', 'Möt Nils! Vi gick mot verkstaden.' ),
	'Möt-Nils' );

check( 'slug: titelns ord går före brödtextens',
	call_user_func( $restore, 'mot-nils', 'Möt Nils!  Han lutar sig mot bänken.' ),
	'Möt-Nils' );

check( 'slug: entiteter och taggar i titeln stör inte',
	call_user_func( $restore, 'anga-kondensat', 'Ånga &amp; Kondensat <p>Vad är ånga?</p>' ),
	'Ånga-Kondensat' );

check( 'slug: sammansatt ord',
	call_user_func( $restore, 'angfallematning', 'Ångfällemätning' ),
	'Ångfällemätning' );

check( 'slug: ord hämtas ur brödtexten när sluggen inte följer titeln',
	call_user_func( $restore, 'vara-tjanster', 'Tjänster Här är våra tjänster.' ),
	'våra-Tjänster' );

check( 'slug: ord utan motsvarighet lämnas',
	call_user_func( $restore, 'oslo', 'VIMAB Norge' ),
	'oslo' );

check( 'slug: siffror följer med',
	call_user_func( $restore, 'jobb-kompetensmassa-2024', 'Jobb- &amp; Kompetensmässa 2024' ),
	'Jobb-Kompetensmässa-2024' );

check( 'slug: ord på var sin sida om en tagg växer inte ihop',
	call_user_func( $restore, 'mot', '<h2>Rubrik</h2><p>Möt oss</p>' ),
	'Möt' );

check( 'slug: tom referens ger sluggen tillbaka',
	call_user_func( $restore, 'mot-nils', '' ),
	'mot-nils' );

// --- 2. Textnoder ur ett fragment ---
check( 'textnoder: första och sista noden kommer med, entiteter avkodas',
	CoTranslate_String_Translator::text_nodes( 'Start <p>Power &amp; Heat</p><!-- wp:x --><script>var a = 1;</script> slut' ),
	array( 'Start', 'Power & Heat', 'slut' ) );

// --- 3. Redan översatt text köas inte ---
$ref = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj = $ref->newInstanceWithoutConstructor();

$collect = $ref->getMethod( 'collect_untranslated_strings' );
$untr    = $ref->getProperty( 'untranslated' );
if ( PHP_VERSION_ID < 80100 ) {
	$collect->setAccessible( true );
	$untr->setAccessible( true );
}

// Så som filtren i class-plugin.php anmäler det de byter ut.
CoTranslate_String_Translator::mark_translated( 'Chemicals &amp; Petrochemicals' );
CoTranslate_String_Translator::mark_translated_html(
	'<p>– I had started looking around, and it felt right.</p><h2>What did you want to be?</h2>'
);

$page = '<nav><a>Våra tjänster</a></nav>'
	. '<h1>Chemicals &amp; Petrochemicals</h1>'
	. '<article><p>– I had started looking around, and it felt right.</p><h2>What did you want to be?</h2></article>'
	. '<footer><span>Ledande inom ventilservice</span></footer>';

$collect->invoke( $obj, $page );

check( 'insamling: bara den oöversatta texten köas',
	array_keys( $untr->getValue( $obj ) ),
	array( 'Våra tjänster', 'Ledande inom ventilservice' ) );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
