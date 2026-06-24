<?php
// Fristående CLI-test för ren protect/restore-logik (ingen WordPress).
define( 'ABSPATH', __DIR__ . '/' ); // tillåt att klassfilen laddas utanför WP
require __DIR__ . '/../includes/interface-translator.php';
require __DIR__ . '/../includes/class-no-translate-wrapper.php';

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

// Skiftlägesokänslig matchning, original bevaras vid restore.
list( $prot, $map ) = CoTranslate_No_Translate_Wrapper::protect( 'Köp en iphone hos Reklamco', array( 'iPhone', 'Reklamco' ) );
check( 'iphone ersatt med platshållare', strpos( $prot, 'iphone' ), false );
check( 'restore ger tillbaka original-träffen', CoTranslate_No_Translate_Wrapper::restore( $prot, $map ), 'Köp en iphone hos Reklamco' );

// Längsta fras först: "New York" får inte brytas av "York".
list( $prot2, $map2 ) = CoTranslate_No_Translate_Wrapper::protect( 'Visit New York today', array( 'York', 'New York' ) );
check( 'hela frasen New York skyddad', count( $map2 ), 1 );
check( 'restore New York', CoTranslate_No_Translate_Wrapper::restore( $prot2, $map2 ), 'Visit New York today' );

// Tom termlista → oförändrad text, tom karta.
list( $prot3, $map3 ) = CoTranslate_No_Translate_Wrapper::protect( 'Ingen ändring', array() );
check( 'tom lista lämnar texten orörd', $prot3, 'Ingen ändring' );
check( 'tom lista ger tom karta', $map3, array() );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
