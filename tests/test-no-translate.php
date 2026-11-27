<?php
// Fristående CLI-test för skyddade termer (ingen WordPress).
define( 'ABSPATH', __DIR__ . '/' ); // tillåt att klassfilen laddas utanför WP
require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-no-translate-wrapper.php';

// Minimal ersättning för get_option() så att wrappern kan läsa termlistan.
$GLOBALS['test_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['test_options'][ $name ] ?? $default;
}

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

$W = 'CoTranslate_No_Translate_Wrapper';

// Skiftlägesokänslig matchning, original bevaras vid restore.
list( $prot, $map ) = $W::protect( 'Köp en iphone hos Reklamco', array( 'iPhone', 'Reklamco' ) );
check( 'termen lindas in med translate="no"', $prot, 'Köp en <span translate="no" data-cotranslate-nt="0">iphone</span> hos <span translate="no" data-cotranslate-nt="1">Reklamco</span>' );
check( 'restore ger tillbaka original-träffen', $W::restore( $prot, $map ), 'Köp en iphone hos Reklamco' );

// Längsta fras först: "New York" får inte brytas av "York", och ingen nästling.
list( $prot2, $map2 ) = $W::protect( 'Visit New York today', array( 'York', 'New York' ) );
check( 'hela frasen New York skyddad', count( $map2 ), 1 );
check( 'ingen span inuti span', substr_count( $prot2, '<span' ), 1 );
check( 'restore New York', $W::restore( $prot2, $map2 ), 'Visit New York today' );

// Tom termlista → oförändrad text, tom karta.
list( $prot3, $map3 ) = $W::protect( 'Ingen ändring', array() );
check( 'tom lista lämnar texten orörd', $prot3, 'Ingen ändring' );
check( 'tom lista ger tom karta', $map3, array() );

// Attribut, kommentarer och script rörs inte – bara synlig text.
$html = '<a href="https://vansbrosimningen.se/vansbro/" title="Vansbro">Till Vansbro</a><!-- Vansbro --><script>var v="Vansbro";</script>';
list( $prot4, $map4 ) = $W::protect( $html, array( 'Vansbro' ) );
check( 'bara texten i länken skyddas', count( $map4 ), 1 );
check( 'href, title, kommentar och script orörda', $W::restore( $prot4, $map4 ), $html );
check( 'html med skydd', $prot4, '<a href="https://vansbrosimningen.se/vansbro/" title="Vansbro">Till <span translate="no" data-cotranslate-nt="0">Vansbro</span></a><!-- Vansbro --><script>var v="Vansbro";</script>' );

// Å, ä och ö matchas skiftlägesokänsligt.
list( $prot5, $map5 ) = $W::protect( 'Simma i VANÅN', array( 'Vanån' ) );
check( 'åäö skiftlägesokänsligt', $map5, array( 0 => 'VANÅN' ) );

// Motorn kan ändra citattecken; restore ska klara det.
check( 'restore med enkla citattecken', $W::restore( "at <span translate='no' data-cotranslate-nt='0'>X</span> now", array( 0 => 'Vansbrosimningen' ) ), 'at Vansbrosimningen now' );
// Gamla kommentarplatshållare får aldrig nå en publicerad översättning.
check( 'gammal platshållare tas bort', $W::restore( 'a<!--COTRANSLATE_NT_3--> b <span translate="no" data-cotranslate-nt="0">X</span>', array( 0 => 'X' ) ), 'a b X' );

// Term med & i HTML-escapad text.
list( $prot6, $map6 ) = $W::protect( 'Köp hos H&amp;M', array( 'H&M' ) );
check( 'H&M hittas i escapad text', count( $map6 ), 1 );

// --- DeepL böjer termen utanför elementet eller tappar mellanslaget ----
$terms = array( 'Vansbro Vintersim', 'Vansbro Tjejsim', 'Sallyprovet', 'Vansbrosimningen' );
list( $p, $m, $a ) = $W::protect( 'Välkommen till Vansbro Vintersim!', $terms );
check( 'ändelse direkt efter termen', $W::restore( 'Welcome to <span translate="no" data-cotranslate-nt="0">Vansbro Vintersim</span>ing!', $m, $a ), 'Welcome to Vansbro Vintersim!' );
list( $p, $m, $a ) = $W::protect( 'Hon vann Vansbro Tjejsim med tiden 11:21', $terms );
check( 'tappat mellanslag läggs tillbaka', $W::restore( 'She won the <span translate="no" data-cotranslate-nt="0">Vansbro Tjejsim</span>with a time of 11:21', $m, $a ), 'She won the Vansbro Tjejsim with a time of 11:21' );
check( 'ihopklistrat ord före termen', $W::restore( 'it was the<span translate="no" data-cotranslate-nt="0">Vansbro Tjejsim</span> that', $m, $a ), 'it was the Vansbro Tjejsim that' );
list( $p, $m, $a ) = $W::protect( 'Sallyprovet är 200 meter', $terms );
check( 'ändelse "t" tas bort', $W::restore( 'The <span translate="no" data-cotranslate-nt="0">Sallyprovet</span>t is 200 meters', $m, $a ), 'The Sallyprovet is 200 meters' );
list( $p, $m, $a ) = $W::protect( 'Vansbrosimningens arena', $terms );
check( 'genitiv-s i källan noteras', $a, array( 0 => array( 'before' => false, 'after' => true ) ) );
check( 'genitiv-s från källan står kvar', $W::restore( 'the <span translate="no" data-cotranslate-nt="0">Vansbrosimningen</span>s arena', $m, $a ), 'the Vansbrosimningens arena' );
check( 'utan attached-uppgift lämnas grannbokstäver', $W::restore( '<span translate="no" data-cotranslate-nt="0">X</span>ing', array( 0 => 'Vintersim' ) ), 'Vintersiming' );
check( 'apostrof-genitiv rörs inte', $W::restore( 'the <span translate="no" data-cotranslate-nt="0">Vansbrosimningen</span>’s finish', array( 0 => 'Vansbrosimningen' ), array( 0 => array( 'before' => false, 'after' => false ) ) ), 'the Vansbrosimningen’s finish' );

// Bara hela ord (och genitiv-s) skyddas.
list( $p, $m ) = $W::protect( 'Nattsimmarna och Vansbro Nattsim', array( 'Nattsim', 'Vansbro Nattsim' ) );
check( 'term inne i längre ord skyddas inte', $m, array( 0 => 'Vansbro Nattsim' ) );
list( $p, $m ) = $W::protect( 'Vansbrosimningens arena, Vansbrosimningen.', array( 'Vansbrosimningen' ) );
check( 'genitiv-s och skiljetecken skyddas', count( $m ), 2 );
list( $p, $m ) = $W::protect( 'Ungdomsvansbro', array( 'Vansbro' ) );
check( 'term i slutet av längre ord skyddas inte', $m, array() );
check( 'okänd kort ändelse tas bort', $W::restore( 'the <span translate="no" data-cotranslate-nt="0">X</span>e Senior', array( 0 => 'Vansbro Triathlon Medeldistans' ), array( 0 => array( 'before' => false, 'after' => false ) ) ), 'the Vansbro Triathlon Medeldistans Senior' );
check( 'versalord får mellanslag', $W::restore( '<span translate="no" data-cotranslate-nt="0">X</span>Elite', array( 0 => 'Vansbro Tjejsim' ), array( 0 => array( 'before' => false, 'after' => false ) ) ), 'Vansbro Tjejsim Elite' );
check( 'PM före termen får mellanslag', $W::restore( '2:00 PM<span translate="no" data-cotranslate-nt="0">X</span>', array( 0 => 'Vansbro Halvsim' ), array( 0 => array( 'before' => false, 'after' => false ) ) ), '2:00 PM Vansbro Halvsim' );

// --- translate_text genom wrappern med en låtsasmotor -------------------
class Fake_Engine implements CoTranslate_Translator {
	public $calls = array();
	public function translate_text( array $texts, $s, $t ) {
		$this->calls[] = array( 'text', $texts );
		return array_map( function ( $x ) { return '[en]' . $x; }, $texts );
	}
	public function translate_text_as_html( array $texts, $s, $t ) {
		$this->calls[] = array( 'html', $texts );
		// Som DeepL: lämnar taggarna och översätter texten runt dem.
		return array_map( function ( $x ) { return str_replace( 'och', 'and', $x ); }, $texts );
	}
	public function translate_html( $html, $s, $t ) {
		return $html;
	}
	public function translate_slug( $slug, $s, $t ) {
		return $slug;
	}
}

$GLOBALS['test_options']['cotranslate_no_translate_terms'] = array( '_all' => "Vansbrosimningen\nVansbro" );
$fake    = new Fake_Engine();
$wrapper = new CoTranslate_No_Translate_Wrapper( $fake );
$out     = $wrapper->translate_text( array( 'Ingen term här', 'Vansbrosimningen och <3 & mer', 'Hej' ), 'sv', 'en' );

check( 'ordningen bevaras', count( $out ), 3 );
check( 'text utan term går vanliga vägen', $out[0], '[en]Ingen term här' );
check( 'text med term: term kvar, < och & tillbaka som text', $out[1], 'Vansbrosimningen and <3 & mer' );
check( 'tredje texten', $out[2], '[en]Hej' );
check( 'två anrop: ett vanligt, ett som HTML', array( $fake->calls[0][0], $fake->calls[1][0] ), array( 'text', 'html' ) );
check( 'HTML-vägen fick escapad text med inlindad term', $fake->calls[1][1][0], '<span translate="no" data-cotranslate-nt="0">Vansbrosimningen</span> och &lt;3 &amp; mer' );

$fake2 = new Fake_Engine();
$out2  = ( new CoTranslate_No_Translate_Wrapper( $fake2 ) )->translate_text( array( 'Utan termer' ), 'sv', 'en' );
check( 'utan träffar: bara ett vanligt anrop', array( count( $fake2->calls ), $out2 ), array( 1, array( '[en]Utan termer' ) ) );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
