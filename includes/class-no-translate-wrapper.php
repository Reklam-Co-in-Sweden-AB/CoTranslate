<?php
/**
 * Wrapper som skyddar ord/fraser från översättning.
 *
 * Lindar den verkliga översättningsmotorn. Före översättning byts varje
 * skyddad term mot en HTML-kommentar-platshållare; efter svaret återställs
 * originaltermen. Eftersom wrappern sitter ovanför både Claude och DeepL i
 * factoryn täcks båda motorerna automatiskt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_No_Translate_Wrapper {

	/**
	 * Ersätt skyddade termer med platshållare.
	 *
	 * @param string $text  Text som ska översättas.
	 * @param array  $terms Lista med termer (strängar).
	 * @return array array( string $protected_text, array $map ) där $map är
	 *               platshållare => originalterm.
	 */
	public static function protect( $text, array $terms ) {
		$map     = array();
		$counter = 0;

		// Trimma, ta bort tomma, deduplicera och sortera längsta först så att
		// en längre fras skyddas innan en kortare delsträng av den.
		$clean = array();
		foreach ( $terms as $term ) {
			$term = trim( $term );
			if ( '' !== $term ) {
				$clean[ $term ] = true;
			}
		}
		$clean = array_keys( $clean );
		usort( $clean, function ( $a, $b ) {
			return mb_strlen( $b ) - mb_strlen( $a );
		} );

		foreach ( $clean as $term ) {
			// Skiftlägesokänslig sökning utan regex (säkert mot specialtecken).
			$offset = 0;
			while ( false !== ( $pos = mb_stripos( $text, $term, $offset ) ) ) {
				$placeholder         = '<!--COTRANSLATE_NT_' . $counter . '-->';
				$match               = mb_substr( $text, $pos, mb_strlen( $term ) );
				$map[ $placeholder ] = $match;
				$text                = mb_substr( $text, 0, $pos ) . $placeholder . mb_substr( $text, $pos + mb_strlen( $term ) );
				$offset              = $pos + mb_strlen( $placeholder );
				$counter++;
			}
		}

		return array( $text, $map );
	}

	/**
	 * Återställ platshållare till originaltermer.
	 *
	 * @param string $text Text med platshållare.
	 * @param array  $map  platshållare => originalterm.
	 * @return string
	 */
	public static function restore( $text, array $map ) {
		if ( empty( $map ) ) {
			return $text;
		}
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}
}
