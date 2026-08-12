<?php
// Fristående CLI-test för textnod-översättning (ingen WordPress).
// Reproducerar delsträngs-korruptionen och verifierar exakt hel-nod-matchning.
define( 'ABSPATH', __DIR__ . '/' );

// Minimala WP-stubbar som metoden behöver.
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
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

// Nå den privata metoden och dictionary-egenskapen via reflection.
$ref = new ReflectionClass( 'CoTranslate_String_Translator' );
$obj = $ref->newInstanceWithoutConstructor();

$dict = $ref->getProperty( 'dictionary' );
if ( PHP_VERSION_ID < 80100 ) { $dict->setAccessible( true ); }
$dict->setValue( $obj, array(
	'Vad'  => 'What is',
	'av'   => 'of',
	'Hem'  => 'Home',
	'Stanna upp ett tag' => 'Pause for a while',
) );

$method = $ref->getMethod( 'translate_text_nodes' );
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }

$html = '<a>Vadstena</a><p>have</p><span>cava</span><nav>Hem</nav><b>Vad</b><h2>Stanna upp ett tag</h2>';
$out  = $method->invoke( $obj, $html );

// Korruption får INTE ske (delsträngar inuti längre ord).
check( '"Vadstena" förblir oförändrat', strpos( $out, 'Vadstena' ) !== false, true );
check( 'ingen "What isstena"-korruption', strpos( $out, 'What isstena' ) === false, true );
check( '"have" förblir oförändrat', strpos( $out, '>have<' ) !== false, true );
check( 'ingen "hofe"-korruption', strpos( $out, 'hofe' ) === false, true );
check( 'ingen "cofa"-korruption', strpos( $out, 'cofa' ) === false, true );

// Legitima hela strängar SKA fortfarande översättas.
check( '"Hem" → "Home"', strpos( $out, '>Home<' ) !== false, true );
check( '"Vad" (hel nod) → "What is"', strpos( $out, '>What is<' ) !== false, true );
check( 'hel fras översätts', strpos( $out, '>Pause for a while<' ) !== false, true );

// --- HTML-kommentarer med litteral tagg i sig ---
// En kommentar som innehåller t.ex. `<img>` fick tidigare sitt `-->` behandlat
// som en textnod. Efter esc_html() blev det `--&gt;`, kommentaren blev
// oavslutad och svalde efterföljande element fram till nästa `-->`.
$dict->setValue( $obj, array(
	'(inte CSS-bakgrund) så den kan prioriteras. -->' => '(not a CSS background) so it can be prioritised. -->',
	'Utforska' => 'Explore',
) );

$hero = '<div class="hero__video">'
	. '<!-- Poster som riktig <img> (inte CSS-bakgrund) så den kan prioriteras. -->'
	. '<img class="hero__poster" src="/p.webp" alt="">'
	. '<video class="hero__mp4" muted></video>'
	. '</div>'
	. '<!-- Overlay --><div class="hero__overlay"></div><span>Utforska</span>';

$out = $method->invoke( $obj, $hero );

check( 'kommentarens "-->" är intakt', strpos( $out, '--&gt;' ) === false, true );
check( '<img> finns kvar', strpos( $out, 'hero__poster' ) !== false, true );
check( '<video> finns kvar', strpos( $out, 'hero__mp4' ) !== false, true );
check( 'div-balansen är oförändrad', substr_count( $out, '</div>' ), substr_count( $hero, '</div>' ) );
check( 'text utanför kommentaren översätts fortfarande', strpos( $out, '>Explore<' ) !== false, true );

// Kommentarsinnehåll ska inte heller köas som oöversatt sträng.
$collect = $ref->getMethod( 'collect_untranslated_strings' );
if ( PHP_VERSION_ID < 80100 ) { $collect->setAccessible( true ); }
$untranslated = $ref->getProperty( 'untranslated' );
if ( PHP_VERSION_ID < 80100 ) { $untranslated->setAccessible( true ); }
$untranslated->setValue( $obj, array() );
$collect->invoke( $obj, $hero );
$collected = array_keys( $untranslated->getValue( $obj ) );

$has_comment_junk = false;
foreach ( $collected as $string ) {
	if ( strpos( $string, '-->' ) !== false || strpos( $string, 'CSS-bakgrund' ) !== false ) {
		$has_comment_junk = true;
	}
}
check( 'kommentarstext köas inte för översättning', $has_comment_junk, false );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
