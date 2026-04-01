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
	 */
	private $dictionary = array();

	/**
	 * Strängar som saknar översättning (för köning).
	 */
	private $untranslated = array();

	/**
	 * Om output buffer är aktiv.
	 */
	private $buffer_active = false;

	public function __construct( CoTranslate_DeepL_API $api, CoTranslate_Translation_Store $store ) {
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
		$language         = cotranslate_get_current_language();
		$this->dictionary = $this->store->load_all_strings( $language );

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

		// Samla alltid oöversatta strängar (även om vi har en dictionary)
		$this->collect_untranslated_strings( $html );

		// URL-rewriting för eventuella missade länkar
		$html = $this->rewrite_remaining_urls( $html );

		if ( ! empty( $this->dictionary ) ) {
			// Översätt textnoder utanför script/style/textarea
			$html = $this->translate_text_nodes( $html );

			// Översätt HTML-attribut (alt, title, placeholder, aria-label)
			$html = $this->translate_attributes( $html );

			// Översätt <title>-taggen
			$html = $this->translate_title_tag( $html );
		}

		return $html;
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

		// Steg 1: Ta bort script, style, textarea, code — skydda från ersättning
		$protected = array();
		$counter   = 0;
		$html = preg_replace_callback(
			'#(<(?:script|style|textarea|code|noscript|svg)[^>]*>.*?</(?:script|style|textarea|code|noscript|svg)>)#si',
			function ( $matches ) use ( &$protected, &$counter ) {
				$placeholder = '<!--COTRANSLATE_PROTECTED_' . $counter . '-->';
				$protected[ $placeholder ] = $matches[0];
				$counter++;
				return $placeholder;
			},
			$html
		);

		// Steg 2: Ersätt BARA text mellan HTML-taggar (>text<), inte inne i attribut
		$html = preg_replace_callback(
			'#(>)([^<]+)(<)#',
			function ( $matches ) use ( $replacements ) {
				$text = $matches[2];

				// Hoppa över om bara whitespace
				if ( trim( $text ) === '' ) {
					return $matches[0];
				}

				// Kör strtr bara på textinnehållet
				$translated = strtr( $text, $replacements );

				return $matches[1] . $translated . $matches[3];
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

		foreach ( $attributes as $attr ) {
			$html = preg_replace_callback(
				'/' . preg_quote( $attr, '/' ) . '="([^"]+)"/i',
				function ( $matches ) {
					$original = $matches[1];
					$decoded  = html_entity_decode( $original, ENT_QUOTES, 'UTF-8' );

					if ( isset( $this->dictionary[ $decoded ] ) ) {
						return str_replace( $original, esc_attr( $this->dictionary[ $decoded ] ), $matches[0] );
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
	 * Översätt <title>-taggen.
	 *
	 * @param string $html HTML att processa.
	 * @return string Processad HTML.
	 */
	private function translate_title_tag( $html ) {
		return preg_replace_callback(
			'#<title>([^<]+)</title>#i',
			function ( $matches ) {
				$original = trim( $matches[1] );

				if ( isset( $this->dictionary[ $original ] ) ) {
					return '<title>' . esc_html( $this->dictionary[ $original ] ) . '</title>';
				}

				// Försök delvis matchning (t.ex. "Sidtitel — Sajtnamn")
				$parts      = preg_split( '/\s*[\-–—|]\s*/', $original );
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
					preg_match( '/\s*([\-–—|])\s*/', $original, $sep_match );
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

		// Bygg negative lookahead som skippar ALLA språkprefix (inte bara aktuellt)
		$lang_patterns = array();
		foreach ( $enabled_languages as $lang ) {
			if ( $lang === $default_language ) {
				continue;
			}
			$lang_patterns[] = preg_quote( $lang, '#' ) . '\/';
		}
		$skip_patterns = array_merge( $lang_patterns, array(
			'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login',
		) );
		$skip_regex = implode( '|', $skip_patterns );

		// Matcha href="..." och action="..." som pekar till sajten utan språkprefix
		$pattern = '#((?:href|action)\s*=\s*["\'])(' . $escaped . ')(\/(?!' . $skip_regex . ')[^"\']*?)(["\'])#i';

		$html = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $current_lang ) {
				// Hoppa över fil-URL:er
				if ( preg_match( '/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|pdf|zip|woff2?)$/i', $matches[3] ) ) {
					return $matches[0];
				}

				return $matches[1] . $matches[2] . '/' . $current_lang . $matches[3] . $matches[4];
			},
			$html
		);

		return $html;
	}

	/**
	 * Samla oöversatta strängar från HTML.
	 *
	 * @param string $html HTML att analysera.
	 */
	private function collect_untranslated_strings( $html ) {
		// Ta bort script, style, noscript, svg — dessa ska inte översättas
		$clean = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', '', $html );

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

			// Hoppa över om redan i dictionary
			if ( isset( $this->dictionary[ $text ] ) ) {
				continue;
			}

			$this->untranslated[ $text ] = true;
		}
	}

	/**
	 * Köa oöversatta strängar för bakgrundsöversättning.
	 */
	public function queue_untranslated() {
		if ( empty( $this->untranslated ) ) {
			return;
		}

		$language = cotranslate_get_current_language();
		$texts    = array_keys( $this->untranslated );

		// Spara med tom translated_text som markör för "behöver översättas"
		foreach ( $texts as $text ) {
			$this->store->save_string_translation( $text, $language, '', 'general' );
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

		foreach ( $enabled_languages as $language ) {
			if ( $language === $default_language ) {
				continue;
			}

			// Hämta strängar som saknar översättning
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_text FROM {$table}
				 WHERE language = %s AND translated_text = '' AND is_manual = 0
				 LIMIT 50",
				$language
			) );

			if ( empty( $pending ) ) {
				continue;
			}

			$texts = wp_list_pluck( $pending, 'source_text' );

			$result = $this->api->translate_text( $texts, $default_language, $language );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Spara översättningarna
			foreach ( $result as $i => $translated_text ) {
				if ( isset( $texts[ $i ] ) && ! empty( $translated_text ) ) {
					$this->store->save_string_translation(
						$texts[ $i ],
						$language,
						$translated_text,
						'general'
					);
				}
			}
		}
	}
}
