<?php
/**
 * Ordlista — tvingande termpar per målspråk samt branschkontext.
 *
 * Ren logik (parsning, formatering, promptblock) är statisk och beroende-fri
 * så att den kan testas utan WordPress. Läsning/skrivning av options sker i
 * de metoder som uttryckligen nämner det.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Glossary {

	const OPTION_TERMS   = 'cotranslate_glossary_terms';
	const OPTION_CONTEXT = 'cotranslate_translation_context';

	// =========================================================================
	// REN LOGIK (ingen WordPress)
	// =========================================================================

	/**
	 * Parsa textrutans rader till termpar.
	 *
	 * Accepterar både `källterm = målterm` och tab-separerat (inklistrat från
	 * Excel). Tab har företräde om raden innehåller tab. Rader utan avgränsare,
	 * med tom sida eller som bara är rubrik ignoreras. Vid dubblett vinner den
	 * sista raden.
	 *
	 * @param string $text Rå text från textruta.
	 * @return array Associativ array källterm => målterm.
	 */
	public static function parse_lines( $text ) {
		$entries = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $text );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, "\t" ) ) {
				$parts = explode( "\t", $line, 2 );
			} elseif ( false !== strpos( $line, '=' ) ) {
				$parts = explode( '=', $line, 2 );
			} else {
				continue;
			}

			$source = self::clean_term( $parts[0] );
			$target = self::clean_term( $parts[1] ?? '' );

			if ( '' === $source || '' === $target ) {
				continue;
			}

			$entries[ $source ] = $target;
		}

		return $entries;
	}

	/**
	 * Formatera termpar till textrutans radformat.
	 *
	 * @param array $entries källterm => målterm.
	 * @return string
	 */
	public static function format_lines( array $entries ) {
		$lines = array();
		foreach ( $entries as $source => $target ) {
			$lines[] = $source . ' = ' . $target;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Bygg TSV för DeepL:s glossary-API.
	 *
	 * @param array $entries källterm => målterm.
	 * @return string
	 */
	public static function to_tsv( array $entries ) {
		$lines = array();
		foreach ( $entries as $source => $target ) {
			$lines[] = $source . "\t" . $target;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Bygg promptblock för Claude med kontext och tvingande termer.
	 *
	 * @param string $context Branschkontext (kan vara tom).
	 * @param array  $entries källterm => målterm (kan vara tom).
	 * @return string Tom sträng om inget att lägga till.
	 */
	public static function build_prompt_block( $context, array $entries ) {
		$block = '';

		if ( '' !== trim( (string) $context ) ) {
			$block .= "\n\nContext: " . trim( $context );
		}

		if ( ! empty( $entries ) ) {
			$block .= "\n\nMandatory terminology (source = target). Always use these exact target terms, including for inflected forms of the source term:";
			foreach ( $entries as $source => $target ) {
				$block .= "\n" . $source . ' = ' . $target;
			}
		}

		return $block;
	}

	/**
	 * Rensa en term: trimma och ta bort kontrolltecken (tab, radbrytning).
	 *
	 * @param string $term Rå term.
	 * @return string
	 */
	private static function clean_term( $term ) {
		$term = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $term );
		return trim( preg_replace( '/\s+/u', ' ', $term ) );
	}

	// =========================================================================
	// LAGRING (WordPress options)
	// =========================================================================

	/**
	 * Hämta termpar för ett målspråk.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return array källterm => målterm.
	 */
	public static function get_entries( $target_lang ) {
		$all = get_option( self::OPTION_TERMS, array() );
		if ( empty( $all[ $target_lang ] ) ) {
			return array();
		}
		return self::parse_lines( $all[ $target_lang ] );
	}

	/**
	 * Hämta rå text för ett målspråk (till textrutan).
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return string
	 */
	public static function get_raw( $target_lang ) {
		$all = get_option( self::OPTION_TERMS, array() );
		return isset( $all[ $target_lang ] ) ? (string) $all[ $target_lang ] : '';
	}

	/**
	 * Spara rå text för ett målspråk. Texten normaliseras via parse/format.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param string $raw         Rå text från textruta.
	 * @return array De sparade termparen.
	 */
	public static function save_raw( $target_lang, $raw ) {
		$entries = self::parse_lines( $raw );
		$all     = get_option( self::OPTION_TERMS, array() );

		if ( empty( $entries ) ) {
			unset( $all[ $target_lang ] );
		} else {
			$all[ $target_lang ] = self::format_lines( $entries );
		}

		update_option( self::OPTION_TERMS, $all );
		return $entries;
	}

	/**
	 * Lägg till eller ersätt ett termpar för ett målspråk.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param string $source      Källterm.
	 * @param string $target      Målterm.
	 * @return array Uppdaterade termpar.
	 */
	public static function add_entry( $target_lang, $source, $target ) {
		$entries = self::get_entries( $target_lang );
		$parsed  = self::parse_lines( $source . ' = ' . $target );

		foreach ( $parsed as $s => $t ) {
			$entries[ $s ] = $t;
		}

		$all                 = get_option( self::OPTION_TERMS, array() );
		$all[ $target_lang ] = self::format_lines( $entries );
		update_option( self::OPTION_TERMS, $all );

		return $entries;
	}

	/**
	 * Hämta branschkontexten.
	 *
	 * @return string
	 */
	public static function get_context() {
		return (string) get_option( self::OPTION_CONTEXT, '' );
	}

	/**
	 * Jämför två termuppsättningar och returnera källtermer som ändrats:
	 * nya, med ny målterm eller borttagna.
	 *
	 * @param array $old källterm => målterm.
	 * @param array $new källterm => målterm.
	 * @return array Lista med källtermer.
	 */
	public static function changed_terms( array $old, array $new ) {
		$changed = array();

		foreach ( $new as $source => $target ) {
			if ( ! isset( $old[ $source ] ) || $old[ $source ] !== $target ) {
				$changed[] = $source;
			}
		}

		foreach ( $old as $source => $target ) {
			if ( ! isset( $new[ $source ] ) ) {
				$changed[] = $source;
			}
		}

		return array_values( array_unique( $changed ) );
	}
}
