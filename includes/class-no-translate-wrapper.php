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

	/** @var object Inre översättningsmotor. */
	private $inner;

	/**
	 * @param object $inner Translator som implementerar motorernas interface.
	 */
	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Hämta skyddade termer för ett målspråk (delade `_all` + språkets egna).
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return array Lista med termer.
	 */
	public function get_terms_for_language( $target_lang ) {
		$all   = get_option( 'cotranslate_no_translate_terms', array() );
		$lines = array();
		foreach ( array( '_all', $target_lang ) as $key ) {
			if ( ! empty( $all[ $key ] ) ) {
				$lines = array_merge( $lines, preg_split( '/\r\n|\r|\n/', $all[ $key ] ) );
			}
		}
		return $lines;
	}

	/**
	 * Översätt en lista texter med skydd av termer.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->inner->translate_text( $texts, $source_lang, $target_lang );
		}

		$maps      = array();
		$protected = array();
		foreach ( $texts as $i => $text ) {
			list( $p, $map ) = self::protect( $text, $terms );
			$protected[ $i ] = $p;
			$maps[ $i ]      = $map;
		}

		$result = $this->inner->translate_text( $protected, $source_lang, $target_lang );

		if ( is_array( $result ) ) {
			foreach ( $result as $i => $translated ) {
				if ( isset( $maps[ $i ] ) ) {
					$result[ $i ] = self::restore( $translated, $maps[ $i ] );
				}
			}
		}
		return $result;
	}

	/**
	 * Översätt HTML med skydd av termer.
	 */
	public function translate_html( $html, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->inner->translate_html( $html, $source_lang, $target_lang );
		}
		list( $protected, $map ) = self::protect( $html, $terms );
		$result = $this->inner->translate_html( $protected, $source_lang, $target_lang );
		return is_string( $result ) ? self::restore( $result, $map ) : $result;
	}

	/**
	 * Slugs skyddas inte — de ska inte innehålla varumärkesord.
	 */
	public function translate_slug( $slug, $source_lang, $target_lang ) {
		return $this->inner->translate_slug( $slug, $source_lang, $target_lang );
	}

	/**
	 * Vidarebefordra alla övriga metoder till inre translatorn.
	 */
	public function __call( $name, $args ) {
		return call_user_func_array( array( $this->inner, $name ), $args );
	}

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
