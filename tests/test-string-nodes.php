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

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
