<?php
/**
 * Strängöversättare för tema-strängar, menyer och widgets.
 *
 * Använder en LÄTT output buffer som bara hanterar text utanför post-content.
 * Post-content hanteras av the_title/the_content-filter i class-plugin.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_String_Translator {

	/**
	 * @var CoTranslate_DeepL_API
	 */
	private $api;

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	/**
	 * Förladdat dictionary: source_text => translated_text.
	 * Innehåller BARA färdiga översättningar — rader som väntar på översättning
	 * (tom translated_text) filtreras bort så att de aldrig ersätter synlig text
	 * i attribut eller <title> med tom sträng.
	 */
	private $dictionary = array();

	/**
	 * Ordboken med normaliserade nycklar, byggs vid första behov.
	 *
	 * @var array|null
	 */
	private $normalized_dictionary = null;

	/**
	 * Alla kända källtexter för språket, även de som väntar på översättning.
	 * Används för att inte köa samma sträng om och om igen.
	 */
	private $known = array();

	/**
	 * Kända strängar utan registrerad sida, och de av dem som setts på den
	 * här sidvisningen. Sidan registreras i efterhand i queue_untranslated().
	 */
	private $unplaced      = array();
	private $seen_unplaced = array();

	/**
	 * Strängar som saknar översättning (för köning).
	 */
	private $untranslated = array();

	/**
	 * Om output buffer är aktiv.
	 */
	private $buffer_active = false;

	/**
	 * Text som redan är översatt när den når bufferten: titel, utdrag och
	 * innehåll som filtren i class-plugin.php har bytt ut. Sådan text får
	 * aldrig köas som källtext — då skickas engelska till motorn som om den
	 * vore svenska, kommer tillbaka omformulerad och ersätter den första
	 * översättningen ("it felt right" blev "it felt right right away").
	 *
	 * @var array text => true
	 */
	private static $already_translated = array();

	/**
	 * Registrera en färdigöversatt text så att bufferten inte köar den.
	 *
	 * @param string $text Text utan HTML.
	 */
	public static function mark_translated( $text ) {
		$text = self::normalize_text( $text );
		if ( '' !== $text ) {
			self::$already_translated[ $text ] = true;
		}
	}

	/**
	 * Registrera varje textnod i färdigöversatt HTML.
	 *
	 * @param string $html Översatt HTML, t.ex. ett inläggs innehåll.
	 */
	public static function mark_translated_html( $html ) {
		foreach ( self::text_nodes( $html ) as $text ) {
			self::$already_translated[ $text ] = true;
		}
	}

	/**
	 * Textnoderna i ett HTML-fragment, normaliserade på samma sätt som
	 * insamlingen i collect_untranslated_strings().
	 *
	 * @param string $html HTML-fragment.
	 * @return string[] Unika textnoder.
	 */
	public static function text_nodes( $html ) {
		$clean = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', '', (string) $html );
		$clean = preg_replace( '#<!--.*?-->#s', '', $clean );

		// Fragmentet saknar omgivande taggar, så första och sista noden
		// får dem här.
		preg_match_all( '#>([^<]+)<#', '>' . $clean . '<', $matches );

		$nodes = array();
		foreach ( $matches[1] as $raw ) {
			$text = self::normalize_text( $raw );
			if ( '' !== $text ) {
				$nodes[ $text ] = true;
			}
		}

		return array_keys( $nodes );
	}

	/**
	 * Avkoda entiteter och trimma, som insamlingen gör.
	 *
	 * @param string $text Rå text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		return trim( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
	}

	public function __construct( CoTranslate_Translator $api, CoTranslate_Translation_Store $store ) {
		$this->api   = $api;
		$this->store = $store;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		if ( is_admin() ) {
			return;
		}

		// Starta output buffer SENT (efter post-content-filter)
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 999 );
		add_action( 'shutdown', array( $this, 'end_buffer' ), 0 );

		// Köa oöversatta strängar vid shutdown
		add_action( 'shutdown', array( $this, 'queue_untranslated' ), 10 );

		// Cron för bakgrundsöversättning av strängar
		add_action( 'cotranslate_translate_strings', array( $this, 'process_string_queue' ) );
	}

	/**
	 * Starta output buffer.
	 */
	public function start_buffer() {
		if ( cotranslate_is_default_language() ) {
			return;
		}

		// Hoppa över om det inte är en vanlig sidvisning
		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Förladda alla strängar för aktuellt språk (en enda DB-query)
		$language   = cotranslate_get_current_language();
		$all            = $this->store->load_all_strings( $language );
		$this->known    = $all;
		$this->unplaced = $this->store->get_unplaced_strings( $language );

		// Bara färdiga översättningar får användas för ersättning.
		$this->normalized_dictionary = null;
		$this->dictionary            = array_filter( $all, function ( $translated ) {
			return '' !== (string) $translated;
		} );

		if ( ! empty( $this->dictionary ) || true ) {
			$this->buffer_active = true;
			ob_start( array( $this, 'process_buffer' ) );
		}
	}

	/**
	 * Avsluta output buffer.
	 */
	public function end_buffer() {
		if ( $this->buffer_active ) {
			if ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			$this->buffer_active = false;
		}
	}

	/**
	 * Processa output buffer.
	 *
	 * Ersätter strängar i HTML med översättningar från dictionary.
	 * Fokuserar på text UTANFÖR <article>/<main> (post-content hanteras redan).
	 *
	 * @param string $html Komplett HTML-output.
	 * @return string Översatt HTML.
	 */
	public function process_buffer( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		// Block som redan står på sidans språk (lang="nb" på /nb/) eller som
		// uttryckligen inte ska översättas (translate="no") lyfts ur medan
		// strängar samlas in och ersätts. Länkarna i dem ska däremot få sitt
		// språkprefix, så de sätts tillbaka inför URL-omskrivningen.
		$language = cotranslate_get_current_language();
		$held     = array();

		$html = $this->hold_untranslatable_blocks( $html, $language, $held );
		$this->collect_untranslated_strings( $html );
		$html = $this->release_blocks( $html, $held );

		// URL-rewriting för eventuella missade länkar
		$html = $this->rewrite_remaining_urls( $html );

		$html = $this->hold_untranslatable_blocks( $html, $language, $held );

		if ( ! empty( $this->dictionary ) ) {
			// Översätt <title>-taggen först, medan den fortfarande innehåller
			// originalet och kan träffa ordboken exakt. Körs den efter
			// textnoderna ser den den redan översatta titeln, missar ordboken
			// och köar översatta ord som nya källtexter.
			$html = $this->translate_title_tag( $html );

			// Översätt textnoder utanför script/style/textarea
			$html = $this->translate_text_nodes( $html );

			// Översätt HTML-attribut (alt, title, placeholder, aria-label)
			$html = $this->translate_attributes( $html );
		}

		return $this->release_blocks( $html, $held );
	}

	/**
	 * Lyft ur element som inte ska översättas och lämna en platshållare.
	 *
	 * Två standardattribut styr:
	 *
	 *  - translate="no" — elementet översätts aldrig, på något språk.
	 *  - lang="xx" — elementet står redan på språk xx och lämnas när sidan
	 *    visas på just det språket. På övriga språk översätts det som vanligt.
	 *
	 * Det andra fallet är skälet till att funktionen finns. En sajt med
	 * engelska som huvudspråk kan ha innehåll som bara finns på norska
	 * (platsannonser ur ett rekryteringssystem). Utan märkningen skickas den
	 * norska texten till motorn som "engelska → norska", och motorn skriver
	 * om den: «Sales & Logistics Coordinator» blev «salgs- og
	 * logistikkkoordinator» och meningar formulerades om.
	 *
	 * <html> och <body> hoppas över — de bär sidans eget språk, och hela
	 * sidan skulle annars lyftas ur.
	 *
	 * @param string $html     HTML.
	 * @param string $language Språket sidan visas på.
	 * @param array  $held     Fylls på: platshållare => ursprunglig HTML.
	 * @return string HTML med platshållare.
	 */
	private function hold_untranslatable_blocks( $html, $language, array &$held ) {
		if ( false === stripos( $html, 'translate=' ) && false === stripos( $html, ' lang=' ) ) {
			return $html;
		}

		$pattern = '#<([a-z][a-z0-9]*)\b(?=[^>]*\s(?:translate\s*=\s*["\']no["\']|lang\s*=\s*["\']([a-zA-Z-]+)["\']))[^>]*>#i';
		$offset  = 0;
		$out     = '';

		while ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$tag      = strtolower( $m[1][0] );
			$start    = $m[0][1];
			$open_end = $start + strlen( $m[0][0] );
			$skip     = in_array( $tag, array( 'html', 'body', 'head' ), true );

			// Med bara lang="…" gäller det bara när språket är sidans eget.
			if ( ! $skip && ! preg_match( '#\stranslate\s*=\s*["\']no["\']#i', $m[0][0] ) ) {
				$block_lang = strtolower( substr( (string) ( $m[2][0] ?? '' ), 0, 2 ) );
				$skip       = $block_lang !== strtolower( substr( (string) $language, 0, 2 ) );
			}

			$end = $skip ? false : $this->find_element_end( $html, $tag, $open_end );

			if ( false === $end ) {
				// Inget att lyfta ur (eller ett element utan sluttagg): gå vidare.
				$out   .= substr( $html, $offset, $open_end - $offset );
				$offset = $open_end;
				continue;
			}

			$key          = '<!--COTRANSLATE_HELD_' . count( $held ) . '-->';
			$held[ $key ] = substr( $html, $start, $end - $start );

			$out   .= substr( $html, $offset, $start - $offset ) . $key;
			$offset = $end;
		}

		return $out . substr( $html, $offset );
	}

	/**
	 * Hitta var ett element slutar, med hänsyn till nästlade element av
	 * samma slag.
	 *
	 * @param string $html  HTML.
	 * @param string $tag   Elementets namn i gemener.
	 * @param int    $from  Positionen direkt efter öppningstaggen.
	 * @return int|false Positionen direkt efter sluttaggen, eller false.
	 */
	private function find_element_end( $html, $tag, $from ) {
		$void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

		if ( in_array( $tag, $void, true ) ) {
			return false;
		}

		$depth  = 1;
		$offset = $from;
		$regex  = '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i';

		while ( preg_match( $regex, $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$offset = $m[0][1] + strlen( $m[0][0] );

			if ( '/' === $m[1][0] ) {
				if ( 0 === --$depth ) {
					return $offset;
				}
			} elseif ( '/>' !== substr( $m[0][0], -2 ) ) {
				++$depth;
			}
		}

		return false;
	}

	/**
	 * Sätt tillbaka urlyfta block.
	 *
	 * Töms efteråt, så att samma lista kan användas för nästa urlyftning.
	 */
	private function release_blocks( $html, array &$held ) {
		if ( empty( $held ) ) {
			return $html;
		}

		// Baklänges: ett yttre block kan innehålla platshållare för ett inre.
		foreach ( array_reverse( $held, true ) as $key => $original ) {
			$html = str_replace( $key, $original, $html );
		}

		$held = array();

		return $html;
	}

	/**
	 * Ta bort alla sorters blanksteg i början och slutet av en text.
	 *
	 * Skannern sparar texten med PHP:s trim(), som bara tar bort vanliga
	 * blanksteg. Hårda (U+00A0) och smala hårda (U+202F) mellanslag, vanliga i
	 * text som klistrats in från Word, ligger kvar i källtexten. Matchningen
	 * av textnoder tar däremot bort alla blanksteg, så texten hittades aldrig
	 * i ordboken och visades på originalspråket. Uppslagningen görs därför
	 * också på den normaliserade formen.
	 *
	 * @param string $text Text.
	 * @return string Text utan blanksteg i kanterna.
	 */
	public static function normalize_edges( $text ) {
		$normalized = preg_replace( '/^[\s\x{200B}\x{FEFF}]+|[\s\x{200B}\x{FEFF}]+$/u', '', (string) $text );
		return null === $normalized ? (string) $text : $normalized;
	}

	/**
	 * Ordboken med normaliserade nycklar (se normalize_edges()).
	 *
	 * Exakta nycklar har alltid företräde; den normaliserade formen läggs
	 * bara till där den inte redan finns.
	 *
	 * @return array Normaliserad källtext => normaliserad översättning.
	 */
	private function get_normalized_dictionary() {
		if ( null !== $this->normalized_dictionary ) {
			return $this->normalized_dictionary;
		}
		$this->normalized_dictionary = array();
		foreach ( $this->dictionary as $source => $translated ) {
			$key = self::normalize_edges( $source );
			if ( '' === $key || isset( $this->dictionary[ $key ] ) || isset( $this->normalized_dictionary[ $key ] ) ) {
				continue;
			}
			$value = self::normalize_edges( $translated );
			if ( '' !== $value && $value !== $key ) {
				$this->normalized_dictionary[ $key ] = $value;
			}
		}
		return $this->normalized_dictionary;
	}

	/**
	 * Översätt textnoder i HTML.
	 *
	 * Använder strtr() för simultana ersättningar (undviker dubbel-ersättning).
	 *
	 * @param string $html HTML att processa.
	 * @return string Processad HTML.
	 */
	private function translate_text_nodes( $html ) {
		// Bygg ersättningstabell — filtrera bort tomma och identiska
		$replacements = array();
		foreach ( $this->dictionary as $source => $translated ) {
			if ( ! empty( $source ) && ! empty( $translated ) && $source !== $translated ) {
				$replacements[ $source ] = $translated;
			}
		}

		if ( empty( $replacements ) ) {
			return $html;
		}

		// Steg 1: Ta bort script, style, textarea, code och HTML-kommentarer —
		// skydda från ersättning.
		//
		// Kommentarer MÅSTE skyddas. En kommentar som innehåller en litteral
		// tagg, t.ex. `<!-- Poster som riktig <img> … -->`, gör att regexet i
		// steg 2 ser kommentarens slut som en vanlig textnod: `>` kommer från
		// <img> inuti kommentaren och `<` från nästa riktiga tagg. Då hamnar
		// även `-->` i textnoden, och esc_html() gör om den till `--&gt;`.
		// Kommentaren blir oavslutad och sväljer allt fram till nästa `-->`
		// — i praktiken hela element som <img>, <video> och deras </div>.
		$protected = array();
		$counter   = 0;
		$html = preg_replace_callback(
			'#(<!--.*?-->|<(?:script|style|textarea|code|noscript|svg)[^>]*>.*?</(?:script|style|textarea|code|noscript|svg)>)#si',
			function ( $matches ) use ( &$protected, &$counter ) {
				$placeholder = '<!--COTRANSLATE_PROTECTED_' . $counter . '-->';
				$protected[ $placeholder ] = $matches[0];
				$counter++;
				return $placeholder;
			},
			$html
		);

		$normalized_dictionary = $this->get_normalized_dictionary();

		// Steg 2: Ersätt BARA text mellan HTML-taggar (>text<), inte inne i attribut.
		//
		// Matcha på HELA textnoden (trimmad), inte som delsträng. Tidigare
		// kördes strtr() här, vilket ersatte korta källsträngar var som helst
		// inuti längre ord och korrumperade texten — t.ex. "Vad" => "What is"
		// gjorde "Vadstena" till "What isstena", och "av" => "of" gjorde "have"
		// till "hofe". Ordlistan består alltid av hela textnoder (se
		// collect_untranslated_strings), så exakt hel-nod-matchning är korrekt
		// och konsekvent med övriga översättningsmetoder i klassen.
		$html = preg_replace_callback(
			'#(>)([^<]+)(<)#',
			function ( $matches ) use ( $replacements, $normalized_dictionary ) {
				$text = $matches[2];

				// Hoppa över om bara whitespace
				if ( trim( $text ) === '' ) {
					return $matches[0];
				}

				// Dela upp i ledande whitespace + kärna + avslutande whitespace
				// så att omgivande blanksteg/radbrytningar bevaras exakt.
				preg_match( '/^(\s*)(.*?)(\s*)$/su', $text, $ws );
				$core    = $ws[2];
				$decoded = html_entity_decode( $core, ENT_QUOTES, 'UTF-8' );

				// Exakt matchning mot ordlistan (avkodad eller rå).
				$normalized = self::normalize_edges( $decoded );
				if ( isset( $replacements[ $decoded ] ) ) {
					$translated = $replacements[ $decoded ];
				} elseif ( isset( $replacements[ $core ] ) ) {
					$translated = $replacements[ $core ];
				} elseif ( isset( $replacements[ $normalized ] ) ) {
					$translated = $replacements[ $normalized ];
				} elseif ( isset( $normalized_dictionary[ $normalized ] ) ) {
					$translated = $normalized_dictionary[ $normalized ];
				} else {
					return $matches[0];
				}

				return $matches[1] . $ws[1] . esc_html( $translated ) . $ws[3] . $matches[3];
			},
			$html
		);

		// Steg 3: Återställ skyddade block
		if ( ! empty( $protected ) ) {
			$html = str_replace( array_keys( $protected ), array_values( $protected ), $html );
		}

		return $html;
	}

	/**
	 * Översätt HTML-attribut.
	 *
	 * @param string $html HTML att processa.
	 * @return string Processad HTML.
	 */
	private function translate_attributes( $html ) {
		// Attribut som innehåller översättningsbar text
		$attributes = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-placeholder', 'content', 'data-label' );

		// <meta> med tekniska värden skyddas först, så att content översätts
		// bara i texterna. Före 3.5.5 blev og:type «nettsted» och «网站».
		$protected_meta = array();
		$html           = preg_replace_callback(
			'/<meta\b[^>]*>/i',
			function ( $matches ) use ( &$protected_meta ) {
				if ( self::is_translatable_meta( $matches[0] ) ) {
					return $matches[0];
				}

				$key                    = '<!--cotranslate-meta-' . count( $protected_meta ) . '-->';
				$protected_meta[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		foreach ( $attributes as $attr ) {
			$html = preg_replace_callback(
				'/' . preg_quote( $attr, '/' ) . '="([^"]+)"/i',
				function ( $matches ) {
					$original = $matches[1];
					$decoded  = html_entity_decode( $original, ENT_QUOTES, 'UTF-8' );

					if ( isset( $this->dictionary[ $decoded ] ) ) {
						return str_replace( $original, esc_attr( $this->dictionary[ $decoded ] ), $matches[0] );
					}
					$normalized_dictionary = $this->get_normalized_dictionary();
					$normalized            = self::normalize_edges( $decoded );
					if ( isset( $normalized_dictionary[ $normalized ] ) ) {
						return str_replace( $original, esc_attr( $normalized_dictionary[ $normalized ] ), $matches[0] );
					}

					// Samla som oöversatt
					if ( mb_strlen( $decoded ) >= 2 && preg_match( '/\p{L}/u', $decoded ) ) {
						$this->untranslated[ $decoded ] = true;
					}

					return $matches[0];
				},
				$html
			);
		}

		if ( ! empty( $protected_meta ) ) {
			$html = str_replace( array_keys( $protected_meta ), array_values( $protected_meta ), $html );
		}

		// Översätt value-attribut på submit-knappar och buttons
		$html = preg_replace_callback(
			'/<input([^>]*type=["\'](?:submit|button)["\'][^>]*)value="([^"]+)"([^>]*)>/i',
			function ( $matches ) {
				$value   = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( isset( $this->dictionary[ $value ] ) ) {
					return '<input' . $matches[1] . 'value="' . esc_attr( $this->dictionary[ $value ] ) . '"' . $matches[3] . '>';
				}

				if ( mb_strlen( $value ) >= 2 && preg_match( '/\p{L}/u', $value ) ) {
					$this->untranslated[ $value ] = true;
				}

				return $matches[0];
			},
			$html
		);

		// Översätt option-text i select-element
		$html = preg_replace_callback(
			'/<option([^>]*)>([^<]+)<\/option>/i',
			function ( $matches ) {
				$text = trim( $matches[2] );

				if ( isset( $this->dictionary[ $text ] ) ) {
					return '<option' . $matches[1] . '>' . esc_html( $this->dictionary[ $text ] ) . '</option>';
				}

				if ( mb_strlen( $text ) >= 2 && preg_match( '/\p{L}/u', $text ) ) {
					$this->untranslated[ $text ] = true;
				}

				return $matches[0];
			},
			$html
		);

		return $html;
	}

	/**
	 * Om en <meta>-tagg bär text som ska översättas.
	 *
	 * Bara beskrivningar och titlar. og:type, og:locale, og:url, twitter:card,
	 * viewport, robots m.fl. är tekniska värden och ska stå orörda.
	 *
	 * @param string $tag Hela <meta>-taggen.
	 * @return bool
	 */
	private static function is_translatable_meta( $tag ) {
		if ( ! preg_match( '/\b(?:name|property)="([^"]+)"/i', $tag, $m ) ) {
			return false;
		}

		$name = strtolower( $m[1] );

		return in_array( $name, array( 'description', 'og:title', 'og:description', 'og:image:alt', 'twitter:title', 'twitter:description', 'twitter:image:alt' ), true )
			|| (bool) preg_match( '/^twitter:(?:label|data)\d$/', $name );
	}

	/**
	 * Översätt <title>-taggen.
	 *
	 * @param string $html HTML att processa.
	 * @return string Processad HTML.
	 */
	private function translate_title_tag( $html ) {
		return preg_replace_callback(
			'#<title>([^<]+)</title>#i',
			function ( $matches ) {
				// Avkoda entiteter (WordPress skriver "&#8211;" för tankstreck)
				// så att nyckeln matchar ordboken, som lagrar avkodad text.
				$original = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );

				if ( isset( $this->dictionary[ $original ] ) ) {
					return '<title>' . esc_html( $this->dictionary[ $original ] ) . '</title>';
				}

				// Försök delvis matchning (t.ex. "Sidtitel — Sajtnamn").
				// /u är nödvändigt: utan det delas strängen mitt i tankstreckets
				// bytes, esc_html() får ogiltig UTF-8 och returnerar tom sträng,
				// vilket gav <title></title> på alla översatta undersidor.
				$parts      = preg_split( '/\s*[\-–—|]\s*/u', $original );
				$translated = array();
				$changed    = false;

				foreach ( $parts as $part ) {
					if ( isset( $this->dictionary[ $part ] ) ) {
						$translated[] = $this->dictionary[ $part ];
						$changed      = true;
					} else {
						$translated[] = $part;
						$this->untranslated[ $part ] = true;
					}
				}

				if ( $changed ) {
					// Återskapa med samma separator
					preg_match( '/\s*([\-–—|])\s*/u', $original, $sep_match );
					$separator = isset( $sep_match[0] ) ? $sep_match[0] : ' — ';
					return '<title>' . esc_html( implode( $separator, $translated ) ) . '</title>';
				}

				$this->untranslated[ $original ] = true;
				return $matches[0];
			},
			$html
		);
	}

	/**
	 * Skriv om eventuella missade URL:er i output.
	 *
	 * @param string $html HTML att processa.
	 * @return string Processad HTML.
	 */
	private function rewrite_remaining_urls( $html ) {
		$current_lang     = cotranslate_get_current_language();
		$default_language = cotranslate_get_default_language();

		if ( $current_lang === $default_language ) {
			return $html;
		}

		$home_url          = rtrim( get_option( 'home' ), '/' );
		$escaped           = preg_quote( $home_url, '#' );
		$enabled_languages = cotranslate_get_enabled_languages();

		// Skydda länkar som MEDVETET pekar på ett visst språk (språkväljare och
		// SEO:s hreflang/canonical). Dessa har redan rätt mål och får INTE ett
		// språkprefix påklistrat — annars hamnar t.ex. huvudspråkets länk fel.
		$protected = array();
		$prot_idx  = 0;
		$protect   = function ( $regex ) use ( &$html, &$protected, &$prot_idx ) {
			$html = preg_replace_callback(
				$regex,
				function ( $m ) use ( &$protected, &$prot_idx ) {
					$key               = '<!--COTRANSLATE_NOURL_' . $prot_idx . '-->';
					$protected[ $key ] = $m[0];
					$prot_idx++;
					return $key;
				},
				$html
			);
		};
		// <a ...> och <link ...> med hreflang, samt <link rel="canonical">
		$protect( '#<(?:a|link)\b[^>]*\bhreflang\s*=\s*["\'][^"\']*["\'][^>]*>#i' );
		$protect( '#<link\b[^>]*\brel\s*=\s*["\']?canonical["\']?[^>]*>#i' );

		// Bygg negative lookahead som skippar ALLA språkprefix (inte bara aktuellt)
		$lang_patterns = array();
		foreach ( $enabled_languages as $lang ) {
			if ( $lang === $default_language ) {
				continue;
			}
			// Matcha både "en/..." och naket "en" följt av citattecken
			$lang_patterns[] = preg_quote( $lang, '#' ) . '(?:\/|["\'])';
		}
		$skip_patterns = array_merge( $lang_patterns, array(
			'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login',
		) );
		$skip_regex = implode( '|', $skip_patterns );

		// Fil-URL:er ska aldrig prefixas (delas av båda mönstren nedan)
		$file_ext_regex = '/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|pdf|zip|woff2?)$/i';

		// Matcha href="..." och action="..." som pekar till sajten utan språkprefix.
		// Path-delen är valfri så att även naken domän utan avslutande snedstreck
		// (href="https://sajt.se") får prefix.
		$pattern = '#((?:href|action)\s*=\s*["\'])(' . $escaped . ')((?:\/(?!' . $skip_regex . ')[^"\']*?)?)(["\'])#i';

		$html = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $current_lang, $file_ext_regex ) {
				// Hoppa över fil-URL:er
				if ( preg_match( $file_ext_regex, $matches[3] ) ) {
					return $matches[0];
				}

				return $matches[1] . $matches[2] . '/' . $current_lang . $this->translate_link_path( $matches[3], $current_lang ) . $matches[4];
			},
			$html
		);

		// Rot-relativa länkar (href="/kontakt/") innehåller inte domänen och
		// fångas inte av mönstret ovan — prefixas separat. Protokoll-relativa
		// URL:er (//cdn.se) och paths i skip-listan hoppas över.
		$rel_pattern = '#((?:href|action)\s*=\s*["\'])\/(?!\/|' . $skip_regex . ')([^"\']*)(["\'])#i';

		$html = preg_replace_callback(
			$rel_pattern,
			function ( $matches ) use ( $current_lang, $file_ext_regex ) {
				if ( preg_match( $file_ext_regex, $matches[2] ) ) {
					return $matches[0];
				}

				return $matches[1] . '/' . $current_lang . $this->translate_link_path( '/' . $matches[2], $current_lang ) . $matches[3];
			},
			$html
		);

		// Återställ de skyddade språk-/hreflang-länkarna
		if ( ! empty( $protected ) ) {
			$html = str_replace( array_keys( $protected ), array_values( $protected ), $html );
		}

		return $html;
	}

	/**
	 * Byt originalsluggarna i en länks sökväg mot språkets översatta.
	 *
	 * Länkar som står inskrivna i innehållet (knappar, kort, löptext) passerar
	 * aldrig WordPress länkfilter, så de får sina sluggar här. Query och
	 * fragment lämnas orörda.
	 *
	 * @param string $path     Sökväg med inledande snedstreck, ev. följd av ?… eller #…
	 * @param string $language Aktuellt språk.
	 * @return string
	 */
	private function translate_link_path( $path, $language ) {
		if ( '' === $path || ! function_exists( 'cotranslate_translate_path' ) ) {
			return $path;
		}

		$cut  = strcspn( $path, '?#' );
		$tail = substr( $path, $cut );

		return cotranslate_translate_path( substr( $path, 0, $cut ), $language ) . $tail;
	}

	/**
	 * Samla oöversatta strängar från HTML.
	 *
	 * @param string $html HTML att analysera.
	 */
	private function collect_untranslated_strings( $html ) {
		// Ta bort script, style, noscript, svg — dessa ska inte översättas
		$clean = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', '', $html );

		// Ta bort HTML-kommentarer. Innehållet i dem är inte synlig text, och
		// en kommentar med en litteral tagg i sig (t.ex. `<!-- … <img> … -->`)
		// gör annars att kommentarens `-->` följer med in i ordlistan.
		$clean = preg_replace( '#<!--.*?-->#s', '', $clean );

		// 1. Extrahera text mellan HTML-taggar (per element)
		preg_match_all( '#>([^<]+)<#', $clean, $matches );

		$candidates = ! empty( $matches[1] ) ? $matches[1] : array();

		// 2. Extrahera formulärattribut (placeholder, value, aria-label)
		preg_match_all( '/(?:placeholder|aria-label|aria-placeholder|data-label)="([^"]+)"/i', $clean, $attr_matches );
		if ( ! empty( $attr_matches[1] ) ) {
			$candidates = array_merge( $candidates, $attr_matches[1] );
		}

		// 3. Extrahera submit/button value
		preg_match_all( '/<input[^>]*type=["\'](?:submit|button)["\'][^>]*value="([^"]+)"/i', $clean, $btn_matches );
		if ( ! empty( $btn_matches[1] ) ) {
			$candidates = array_merge( $candidates, $btn_matches[1] );
		}

		// 4. Extrahera option-text
		preg_match_all( '/<option[^>]*>([^<]+)<\/option>/i', $clean, $opt_matches );
		if ( ! empty( $opt_matches[1] ) ) {
			$candidates = array_merge( $candidates, $opt_matches[1] );
		}

		foreach ( $candidates as $raw_text ) {
			$text = trim( html_entity_decode( $raw_text, ENT_QUOTES, 'UTF-8' ) );

			// Filtrera bort korta, tomma eller tekniska strängar
			if ( mb_strlen( $text ) < 2 || mb_strlen( $text ) > 500 ) {
				continue;
			}

			// Hoppa över rena siffror, symboler
			if ( preg_match( '/^[0-9\s\.\,\-\/\:\;\#\@\!\?\&\=\+\*\%\(\)\[\]]+$/', $text ) ) {
				continue;
			}

			// Hoppa över URL:er och e-post
			if ( filter_var( $text, FILTER_VALIDATE_URL ) || filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}

			// Måste innehålla minst en bokstav
			if ( ! preg_match( '/\p{L}/u', $text ) ) {
				continue;
			}

			// Redan översatt av titel-, utdrags- eller innehållsfiltret.
			if ( isset( self::$already_translated[ $text ] ) ) {
				continue;
			}

			// Hoppa över om redan känd (översatt eller köad), men kom ihåg
			// den om den saknar sida så att sidan kan registreras i efterhand.
			if ( isset( $this->known[ $text ] ) ) {
				if ( isset( $this->unplaced[ $text ] ) ) {
					$this->seen_unplaced[ $text ] = true;
				}
				continue;
			}

			$this->untranslated[ $text ] = true;
		}
	}

	/**
	 * Köa oöversatta strängar för bakgrundsöversättning.
	 */
	public function queue_untranslated() {
		$language = cotranslate_get_current_language();
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;

		// Registrera sidan för kända strängar som saknade en. Utan sida får
		// de ingen sidkontext när de översätts om.
		if ( $post_id > 0 && ! empty( $this->seen_unplaced ) ) {
			$this->store->backfill_first_seen( array_keys( $this->seen_unplaced ), $language, $post_id );
			$this->seen_unplaced = array();
		}

		if ( empty( $this->untranslated ) ) {
			return;
		}

		$texts = array_keys( $this->untranslated );

		// Spara med tom translated_text som markör för "behöver översättas",
		// och kom ihåg vilken sida texten först sågs på (filtret "Hittad på").
		foreach ( $texts as $text ) {
			$this->store->save_string_translation( $text, $language, '', 'general', $post_id );
		}

		// Schemalägg bakgrundsöversättning
		if ( ! wp_next_scheduled( 'cotranslate_translate_strings' ) ) {
			wp_schedule_single_event( time() + 30, 'cotranslate_translate_strings' );
		}
	}

	/**
	 * Bakgrundsöversätt köade strängar.
	 */
	public function process_string_queue() {
		$api_key = cotranslate_get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$saved             = 0;

		foreach ( $enabled_languages as $language ) {
			if ( $language === $default_language ) {
				continue;
			}

			// Hämta strängar som saknar översättning
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_text, first_seen_post_id FROM {$table}
				 WHERE language = %s AND translated_text = '' AND is_manual = 0
				 ORDER BY first_seen_post_id, id
				 LIMIT 50",
				$language
			) );

			if ( empty( $pending ) ) {
				continue;
			}

			// Rader utan innehåll (bara blanksteg) kan aldrig lämna kön: MySQL
			// räknar en sträng av mellanslag som lika med tom sträng, så även en
			// sparad "översättning" ser ut som väntande. Radera dem i stället.
			// Sådana rader kommer från äldre versioner; skannern släpper inte
			// igenom dem längre.
			foreach ( $pending as $key => $row ) {
				if ( '' === trim( (string) $row->source_text ) ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $row->id ) );
					unset( $pending[ $key ] );
				}
			}

			if ( empty( $pending ) ) {
				continue;
			}

			// Gruppera per sida så att motorn får sidtitel och grannar som
			// sammanhang. Texter utan känd sida (äldre rader) hamnar i grupp 0
			// och får bara den globala kontexten.
			$groups = array();
			foreach ( $pending as $row ) {
				$groups[ (int) $row->first_seen_post_id ][] = $row->source_text;
			}

			foreach ( $groups as $post_id => $texts ) {
				$texts = array_values( $texts );

				CoTranslate_Glossary::set_page_context( CoTranslate_Glossary::build_page_context( $post_id, $texts ) );
				$result = $this->api->translate_text( $texts, $default_language, $language );
				CoTranslate_Glossary::clear_page_context();

				if ( is_wp_error( $result ) ) {
					// Spara felet så att "Kör kön nu" kan visa varför kön står stilla
					// i stället för att gissa mellan kvot och API-fel.
					set_transient( 'cotranslate_last_queue_error', $result->get_error_message(), 10 * MINUTE_IN_SECONDS );
					continue;
				}
				delete_transient( 'cotranslate_last_queue_error' );

				// Spara översättningarna
				foreach ( $result as $i => $translated_text ) {
					if ( ! isset( $texts[ $i ] ) ) {
						continue;
					}

					$translated_text = is_string( $translated_text ) ? $translated_text : '';

					// Tomt svar (t.ex. en sträng utan synliga tecken) sparas som
					// källtexten. Tidigare sparades inget alls, och strängen låg då
					// kvar som väntande för evigt, vilket fick "Kör kön nu" att
					// stanna med "kvot slut eller API-fel" trots att allt annat
					// var översatt. Synligt blir det ingen skillnad: väntande
					// strängar visar redan källtexten.
					if ( '' === trim( $translated_text ) ) {
						$translated_text = $texts[ $i ];
					}

					$this->store->save_string_translation(
						$texts[ $i ],
						$language,
						$translated_text,
						'general'
					);
					$saved++;
				}
			}
		}

		// Sidcachen visar annars gamla texter tills någon rensar den för hand.
		if ( $saved > 0 ) {
			cotranslate_purge_page_cache();
		}
	}
}
