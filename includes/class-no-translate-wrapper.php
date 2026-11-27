<?php
/**
 * Wrapper som skyddar ord/fraser från översättning.
 *
 * Lindar den verkliga översättningsmotorn. Före översättning läggs varje
 * skyddad term i ett element med translate="no" (se MARKER_ATTR); efter
 * svaret byts elementet tillbaka mot originaltermen. Eftersom wrappern
 * sitter ovanför både Claude och DeepL i factoryn täcks båda motorerna.
 *
 * Termen ligger kvar som text inuti elementet. Tidigare användes en tom
 * HTML-kommentar som platshållare, men den saknar text och är inte bunden
 * till något ord, så DeepL flyttade den fritt i meningen och åt upp
 * mellanslagen runt den ("3Vansbrosimningen,000 m", "dueVansbrosimningen to").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_No_Translate_Wrapper implements CoTranslate_Translator {

	/** Attribut som numrerar skyddade termer i den text som skickas iväg. */
	const MARKER_ATTR = 'data-cotranslate-nt';

	/** Option med namn som står utan bestämd artikel på engelska, ett per rad. */
	const NO_ARTICLE_OPTION = 'cotranslate_no_article_names';

	/**
	 * Ord som kan följa ett namn utan att namnet bestämmer dem. Står ett av
	 * dessa efter namnet hör "the" till namnet och tas bort. Följs namnet av
	 * något annat ord ("the Vansbrosimningen app") får "the" stå kvar.
	 */
	const NO_ARTICLE_FOLLOWERS = 'and|or|but|nor|is|are|was|were|will|would|can|could|has|have|had|does|did|due|in|on|at|for|with|from|to|of|by|as|since|until|after|before|during|which|who|where|when|because|if|so|than|this|that|again|too|also|took|takes|opens|opened|involves|starts|started|begins|began|kicks|returns|offers|attracts';

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
	 *
	 * Texter utan skyddade termer går samma väg som förut. Texter med termer
	 * HTML-escapas, får sina termer inlindade och översätts som HTML, så att
	 * motorn respekterar translate="no". Efteråt återställs termerna och
	 * escapningen tas bort, så att anroparen får tillbaka vanlig text.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->finish_texts( $this->inner->translate_text( $texts, $source_lang, $target_lang ), $target_lang );
		}

		$plain     = array();
		$protected = array();
		$maps      = array();
		$attached  = array();
		foreach ( $texts as $i => $text ) {
			list( $p, $map, $att ) = self::protect( htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8' ), $terms );
			if ( empty( $map ) ) {
				$plain[ $i ] = $text;
			} else {
				$protected[ $i ] = $p;
				$maps[ $i ]      = $map;
				$attached[ $i ]  = $att;
			}
		}

		if ( empty( $protected ) ) {
			return $this->finish_texts( $this->inner->translate_text( $texts, $source_lang, $target_lang ), $target_lang );
		}

		$result = array();

		if ( ! empty( $plain ) ) {
			$translated = $this->inner->translate_text( array_values( $plain ), $source_lang, $target_lang );
			if ( ! is_array( $translated ) ) {
				return $translated;
			}
			foreach ( array_keys( $plain ) as $n => $i ) {
				$result[ $i ] = $translated[ $n ] ?? '';
			}
		}

		$translated = method_exists( $this->inner, 'translate_text_as_html' )
			? $this->inner->translate_text_as_html( array_values( $protected ), $source_lang, $target_lang )
			: $this->inner->translate_text( array_values( $protected ), $source_lang, $target_lang );
		if ( ! is_array( $translated ) ) {
			return $translated;
		}
		foreach ( array_keys( $protected ) as $n => $i ) {
			$restored     = self::restore( $translated[ $n ] ?? '', $maps[ $i ], $attached[ $i ] );
			$result[ $i ] = htmlspecialchars_decode( $restored, ENT_QUOTES | ENT_HTML5 );
		}

		// Samma nycklar och ordning som indata.
		$ordered = array();
		foreach ( array_keys( $texts ) as $i ) {
			$ordered[ $i ] = $result[ $i ] ?? '';
		}
		return $this->finish_texts( array_values( $ordered ), $target_lang );
	}

	/**
	 * Översätt HTML med skydd av termer.
	 */
	public function translate_html( $html, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->finish_html( $this->inner->translate_html( $html, $source_lang, $target_lang ), $target_lang );
		}
		list( $protected, $map, $attached ) = self::protect( $html, $terms );
		$result = $this->inner->translate_html( $protected, $source_lang, $target_lang );
		return $this->finish_html( is_string( $result ) ? self::restore( $result, $map, $attached ) : $result, $target_lang );
	}

	/**
	 * Namn som ska stå utan bestämd artikel när målspråket är engelska.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return array Namn, tomma rader borttagna.
	 */
	public static function get_no_article_names( $target_lang ) {
		if ( 0 !== strpos( strtolower( (string) $target_lang ), 'en' ) ) {
			return array();
		}
		$raw = (string) get_option( self::NO_ARTICLE_OPTION, '' );
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
	}

	/**
	 * Tillämpa artikelregeln på en lista översatta texter.
	 */
	private function finish_texts( $result, $target_lang ) {
		$names = self::get_no_article_names( $target_lang );
		if ( empty( $names ) || ! is_array( $result ) ) {
			return $result;
		}
		foreach ( $result as $i => $text ) {
			$result[ $i ] = self::drop_articles( $text, $names );
		}
		return $result;
	}

	/**
	 * Tillämpa artikelregeln på översatt HTML.
	 */
	private function finish_html( $result, $target_lang ) {
		$names = self::get_no_article_names( $target_lang );
		return ( empty( $names ) || ! is_string( $result ) ) ? $result : self::drop_articles( $result, $names );
	}

	/**
	 * Ta bort "the" direkt före namn som ska stå utan bestämd artikel.
	 *
	 * DeepL skriver "the" före svenska egennamn ("won the Vansbro Tjejsim",
	 * "The Sallyprovet (formerly …"). Engelskan blir naturligare utan, men
	 * "the" hör ibland till ordet efter namnet ("the Vansbrosimningen app",
	 * "the Vansbrosimningen office"). Därför tas "the" bara bort när namnet
	 * följs av genitiv ("’s"), skiljetecken, en tagg, textslut eller ett
	 * småord i NO_ARTICLE_FOLLOWERS. I alla andra fall står "the" kvar.
	 *
	 * @param string $text  Översatt text eller HTML.
	 * @param array  $names Namn utan bestämd artikel.
	 * @return string
	 */
	public static function drop_articles( $text, array $names ) {
		if ( ! is_string( $text ) || '' === $text || empty( $names ) ) {
			return $text;
		}
		usort( $names, function ( $a, $b ) {
			return mb_strlen( $b ) - mb_strlen( $a );
		} );
		$alt     = implode( '|', array_map( function ( $n ) {
			return preg_quote( $n, '/' );
		}, $names ) );
		$pattern = '/\b[Tt]he\s+(' . $alt . ')(?=(?:’s|\'s)(?!\p{L})|\s*(?:$|[.,;:!?)(\]}”"»…<|]|[–—]|-\s)|\s+(?:' . self::NO_ARTICLE_FOLLOWERS . ')\b)/u';
		$out     = preg_replace( $pattern, '$1', $text );
		return null === $out ? $text : $out;
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
	 * Lägg skyddade termer i element som motorn inte översätter.
	 *
	 * Bara text utanför taggar berörs. Termer i attribut (href, alt och
	 * liknande), kommentarer, <script> och <style> lämnas orörda, så att HTML:en
	 * inte går sönder. Alla termer matchas i ett enda svep, längsta först, så
	 * att en kortare term aldrig hamnar inuti en längre som redan skyddats.
	 *
	 * @param string $text  Text eller HTML som ska översättas.
	 * @param array  $terms Lista med termer (strängar).
	 * @return array array( string $protected_text, array $map, array $attached )
	 *               där $map är löpnummer => originalträff och $attached är
	 *               löpnummer => array( 'before' => bool, 'after' => bool ),
	 *               dvs. om träffen satt ihop med en bokstav i källtexten
	 *               (t.ex. genitiv-s: "Vansbrosimningens").
	 */
	public static function protect( $text, array $terms ) {
		$clean = array();
		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			if ( '' !== $term ) {
				$clean[ $term ] = true;
				// HTML och escapad text skriver t.ex. "H&M" som "H&amp;M".
				$clean[ htmlspecialchars( $term, ENT_NOQUOTES, 'UTF-8' ) ] = true;
			}
		}
		if ( empty( $clean ) || '' === (string) $text ) {
			return array( $text, array(), array() );
		}
		$clean = array_keys( $clean );
		usort( $clean, function ( $a, $b ) {
			return mb_strlen( $b ) - mb_strlen( $a );
		} );

		// Bara hela ord, eventuellt med genitiv-s ("Vansbrosimningens"). En
		// term inne i ett längre ord ("Nattsim" i "nattsimmarna") skyddas
		// inte: motorn fick då ett halvt ord och skrev t.ex. "swimmenattsimers".
		$pattern = '/(?<!\p{L})(?:' . implode( '|', array_map( function ( $term ) {
			return preg_quote( $term, '/' );
		}, $clean ) ) . ')(?=s?(?!\p{L}))/iu';

		$parts = preg_split(
			'/(<!--.*?-->|<script\b.*?<\/script\s*>|<style\b.*?<\/style\s*>|<[^>]*>)/is',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);
		if ( false === $parts ) {
			return array( $text, array(), array() );
		}

		$map      = array();
		$attached = array();
		$counter  = 0;
		foreach ( $parts as $k => $part ) {
			// Udda index är taggar, kommentarer, script och style.
			if ( 1 === $k % 2 || '' === $part ) {
				continue;
			}
			$replaced = preg_replace_callback(
				$pattern,
				function ( $m ) use ( &$map, &$attached, &$counter, $part ) {
					list( $match, $offset ) = $m[0];
					$map[ $counter ]        = $match;
					$attached[ $counter ]   = array(
						'before'       => 1 === preg_match( '/\p{L}$/u', substr( $part, 0, $offset ) ),
						'after'        => 1 === preg_match( '/^\p{L}/u', substr( $part, $offset + strlen( $match ) ) ),
						'space_before' => 1 === preg_match( '/\s$/u', substr( $part, 0, $offset ) ),
					);
					return '<span translate="no" ' . self::MARKER_ATTR . '="' . $counter++ . '">' . $match . '</span>';
				},
				$part,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
			if ( null !== $replaced ) {
				$parts[ $k ] = $replaced;
			}
		}

		return array( implode( '', $parts ), $map, $attached );
	}

	/**
	 * Byt tillbaka de inlindade termerna mot originalträffarna.
	 *
	 * Klarar att motorn ändrar citattecken eller ordning på attributen.
	 * Platshållare i det gamla kommentarformatet tas bort om de skulle dyka
	 * upp, så att de aldrig hamnar i en publicerad översättning.
	 *
	 * DeepL låter termen vara men kan böja den utanför elementet
	 * ("<span>Vansbro Vintersim</span>ing") eller tappa mellanslaget efter
	 * det ("<span>Vansbro Tjejsim</span>with"). Satt termen inte ihop med en
	 * bokstav i källtexten tas en kort ändelse (högst tre gemener) bort och
	 * ett längre ord får tillbaka sitt mellanslag. Samma sak gäller ett ord som klistrats ihop
	 * framför termen.
	 *
	 * @param string $text     Översatt text.
	 * @param array  $map      löpnummer => originalträff.
	 * @param array  $attached löpnummer => array( 'before' => bool, 'after' => bool ),
	 *                         från protect(). Utan uppgift lämnas grannbokstäver orörda.
	 * @return string
	 */
	public static function restore( $text, array $map, array $attached = array() ) {
		if ( empty( $map ) || ! is_string( $text ) ) {
			return $text;
		}
		$restored = preg_replace_callback(
			'/(.?)<span\b[^>]*\b' . preg_quote( self::MARKER_ATTR, '/' ) . '\s*=\s*["\']?(\d+)["\']?[^>]*>(.*?)<\/span>(\p{L}*)/isu',
			function ( $m ) use ( $map, $attached ) {
				$n      = (int) $m[2];
				$term   = isset( $map[ $n ] ) ? $map[ $n ] : $m[3];
				$before = $m[1];
				$after  = $m[4];

				if ( isset( $attached[ $n ] ) ) {
					if ( '' !== $after && empty( $attached[ $n ]['after'] ) ) {
						// Kort ändelse med gemener ("s", "e", "ing") är en böjning
						// som tas bort; längre eller versal är ett eget ord.
						$after = preg_match( '/^\p{Ll}{1,3}$/u', $after ) ? '' : ' ' . $after;
					}
					if ( '' !== $before && preg_match( '/^\p{L}$/u', $before ) && empty( $attached[ $n ]['before'] ) ) {
						$before .= ' ';
					} elseif ( '' !== $before && ! empty( $attached[ $n ]['space_before'] )
						&& ! preg_match( '/^[\s>(\[{"\'“‘«\-–—\/]$/u', $before ) ) {
						// Mellanslaget före termen försvann ("4:30 p.m.Vansbro").
						$before .= ' ';
					}
				}

				return $before . $term . $after;
			},
			$text
		);
		if ( null === $restored ) {
			$restored = $text;
		}
		return preg_replace( '/<!--COTRANSLATE_NT_\d+-->/', '', $restored );
	}
}
