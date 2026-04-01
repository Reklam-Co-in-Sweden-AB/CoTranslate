<?php
/**
 * Claude API-integration (alternativ översättningsmotor).
 *
 * Använder Anthropic Claude för kontextmedveten översättning
 * med stöd för fritext-instruktioner. Samma interface som DeepL-klassen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Claude_API {

	/**
	 * Timeout för API-anrop i sekunder.
	 */
	const TIMEOUT = 60;

	/**
	 * Max antal texter per batch.
	 */
	const MAX_BATCH_SIZE = 20;

	/**
	 * API-endpoint.
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * Modell att använda.
	 */
	const MODEL = 'claude-haiku-4-5-20251001';

	/**
	 * Översätt en eller flera textsträngar.
	 *
	 * @param array  $texts       Array med textsträngar.
	 * @param string $source_lang Källspråk (WordPress-format).
	 * @param string $target_lang Målspråk (WordPress-format).
	 * @return array|WP_Error Array med översatta strängar eller WP_Error.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Claude API-nyckel saknas.' );
		}

		// Dela upp i batchar
		$chunks  = array_chunk( $texts, self::MAX_BATCH_SIZE );
		$results = array();

		foreach ( $chunks as $chunk ) {
			$response = $this->call_api( $chunk, $source_lang, $target_lang, false );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$results = array_merge( $results, $response );
		}

		return $results;
	}

	/**
	 * Översätt HTML-innehåll.
	 *
	 * @param string $html        HTML att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @return string|WP_Error Översatt HTML eller WP_Error.
	 */
	public function translate_html( $html, $source_lang, $target_lang ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Claude API-nyckel saknas.' );
		}

		$response = $this->call_api( array( $html ), $source_lang, $target_lang, true );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response[0] ?? '';
	}

	/**
	 * Översätt en URL-slug.
	 *
	 * @param string $slug        Slug att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @return string|WP_Error Översatt slug eller WP_Error.
	 */
	public function translate_slug( $slug, $source_lang, $target_lang ) {
		$text   = str_replace( '-', ' ', $slug );
		$result = $this->translate_text( array( $text ), $source_lang, $target_lang );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return sanitize_title( $result[0] ?? $slug );
	}

	/**
	 * Testa API-anslutning.
	 *
	 * @param string $api_key Valfri API-nyckel.
	 * @return array|WP_Error Resultat eller WP_Error.
	 */
	public function test_connection( $api_key = '' ) {
		if ( empty( $api_key ) ) {
			$api_key = $this->get_api_key();
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Ingen API-nyckel angiven.' );
		}

		// Skicka en minimal request för att testa nyckeln
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version'  => '2023-06-01',
					'content-type'       => 'application/json',
				),
				'body' => wp_json_encode( array(
					'model'      => self::MODEL,
					'max_tokens' => 10,
					'messages'   => array(
						array( 'role' => 'user', 'content' => 'Svara bara "ok".' ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'success'         => true,
				'character_count' => 0,
				'character_limit' => 0,
				'message'         => 'Anslutning till Claude API lyckades!',
			);
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = $body['error']['message'] ?? 'Okänt fel (HTTP ' . $code . ')';

		if ( 401 === $code ) {
			$message = 'Ogiltig Anthropic API-nyckel.';
		}

		return new WP_Error( 'api_error', $message );
	}

	/**
	 * Hämta API-användning.
	 *
	 * Anthropic har ingen usage-endpoint — returnerar tom data.
	 *
	 * @return array Användningsdata (alltid 0 för Claude).
	 */
	public function get_usage() {
		return array(
			'character_count' => 0,
			'character_limit' => 0,
		);
	}

	/**
	 * Hämta stödda språk.
	 *
	 * Claude stöder alla språk — returnerar samma lista som helpers.php.
	 *
	 * @return array Språklista.
	 */
	public function get_supported_languages() {
		return cotranslate_get_supported_languages();
	}

	// =========================================================================
	// PRIVATA METODER
	// =========================================================================

	/**
	 * Gör API-anrop till Claude.
	 *
	 * @param array  $texts       Texter att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @param bool   $is_html     Om texterna innehåller HTML.
	 * @return array|WP_Error Översatta texter eller WP_Error.
	 */
	private function call_api( array $texts, $source_lang, $target_lang, $is_html = false ) {
		$api_key    = $this->get_api_key();
		$languages  = cotranslate_get_supported_languages();
		$source_name = $languages[ $source_lang ]['name'] ?? $source_lang;
		$target_name = $languages[ $target_lang ]['name'] ?? $target_lang;

		// Hämta valfri stil-instruktion
		$custom_prompt = get_option( 'cotranslate_claude_prompt', '' );

		// Bygg prompt
		if ( count( $texts ) === 1 ) {
			$prompt = $this->build_single_prompt( $texts[0], $source_name, $target_name, $is_html, $custom_prompt );
		} else {
			$prompt = $this->build_batch_prompt( $texts, $source_name, $target_name, $custom_prompt );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version'  => '2023-06-01',
					'content-type'       => 'application/json',
				),
				'body' => wp_json_encode( array(
					'model'      => self::MODEL,
					'max_tokens' => 8192,
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );

		if ( 200 !== $code ) {
			$message = $data['error']['message'] ?? 'Claude API-fel (HTTP ' . $code . ')';
			return new WP_Error( 'claude_error_' . $code, $message );
		}

		$content = $data['content'][0]['text'] ?? '';

		if ( empty( $content ) ) {
			return new WP_Error( 'empty_response', 'Tomt svar från Claude.' );
		}

		// Parsa svar
		if ( count( $texts ) === 1 ) {
			return array( trim( $content ) );
		}

		return $this->parse_batch_response( $content, count( $texts ) );
	}

	/**
	 * Bygg prompt för enskild text.
	 */
	private function build_single_prompt( $text, $source_name, $target_name, $is_html, $custom_prompt ) {
		$prompt = "Translate the following from {$source_name} to {$target_name}.";

		if ( $is_html ) {
			$prompt .= " The text contains HTML markup — preserve all HTML tags exactly as they are, only translate the visible text content.";
		}

		$prompt .= " Output ONLY the translation, nothing else. No explanations, no quotes, no labels.";

		if ( ! empty( $custom_prompt ) ) {
			$prompt .= "\n\nAdditional instructions: " . $custom_prompt;
		}

		$prompt .= "\n\nText to translate:\n" . $text;

		return $prompt;
	}

	/**
	 * Bygg prompt för batch-översättning.
	 *
	 * Använder JSON-format istället för numrering (säkrare parsning).
	 */
	private function build_batch_prompt( array $texts, $source_name, $target_name, $custom_prompt ) {
		$prompt  = "Translate the following texts from {$source_name} to {$target_name}.\n";
		$prompt .= "Output a JSON array with the translations in the same order. No explanations.\n";
		$prompt .= "Example output: [\"translation 1\", \"translation 2\"]\n";

		if ( ! empty( $custom_prompt ) ) {
			$prompt .= "\nAdditional instructions: " . $custom_prompt . "\n";
		}

		$prompt .= "\nTexts to translate:\n";
		$prompt .= wp_json_encode( $texts, JSON_UNESCAPED_UNICODE );

		return $prompt;
	}

	/**
	 * Parsa batch-svar (JSON-array).
	 *
	 * @param string $content API-svar.
	 * @param int    $expected_count Förväntat antal.
	 * @return array|WP_Error Översatta texter.
	 */
	private function parse_batch_response( $content, $expected_count ) {
		// Försök hitta JSON-array i svaret
		$content = trim( $content );

		// Rensa eventuell markdown-kodblock
		$content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		$parsed = json_decode( $content, true );

		if ( is_array( $parsed ) && count( $parsed ) === $expected_count ) {
			return $parsed;
		}

		// Fallback: försök extrahera JSON-array från svaret
		if ( preg_match( '/\[.*\]/s', $content, $matches ) ) {
			$parsed = json_decode( $matches[0], true );
			if ( is_array( $parsed ) && count( $parsed ) === $expected_count ) {
				return $parsed;
			}
		}

		// Sista fallback: splitta på radbrytningar
		$lines = array_values( array_filter(
			array_map( 'trim', explode( "\n", $content ) ),
			function ( $line ) {
				return ! empty( $line ) && $line[0] !== '[' && $line[0] !== ']';
			}
		) );

		if ( count( $lines ) === $expected_count ) {
			// Rensa eventuella citattecken
			return array_map( function ( $line ) {
				return trim( $line, '",' );
			}, $lines );
		}

		return new WP_Error( 'parse_error', 'Kunde inte parsa Claudes svar. Förväntade ' . $expected_count . ' översättningar.' );
	}

	/**
	 * Hämta Claude API-nyckel.
	 *
	 * @return string API-nyckel.
	 */
	private function get_api_key() {
		if ( defined( 'COTRANSLATE_CLAUDE_KEY' ) ) {
			return COTRANSLATE_CLAUDE_KEY;
		}

		$stored = get_option( 'cotranslate_claude_api_key', '' );

		if ( empty( $stored ) ) {
			return '';
		}

		// Anthropic-nycklar börjar med sk-ant-
		if ( strpos( $stored, 'sk-ant-' ) === 0 ) {
			return $stored;
		}

		return cotranslate_decrypt( $stored );
	}
}
