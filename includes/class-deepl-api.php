<?php
/**
 * DeepL API-integration.
 *
 * Hanterar all kommunikation med DeepL:s översättnings-API.
 * Stöder både Free och Pro endpoints, HTML-översättning och batch-anrop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_DeepL_API {

	/**
	 * Timeout för API-anrop i sekunder.
	 */
	const TIMEOUT = 30;

	/**
	 * Max antal texter per batch-anrop.
	 */
	const MAX_BATCH_SIZE = 50;

	/**
	 * Översätt en eller flera textsträngar.
	 *
	 * @param array  $texts       Array med textsträngar att översätta.
	 * @param string $source_lang Källspråk (WordPress-format, t.ex. 'sv').
	 * @param string $target_lang Målspråk (WordPress-format, t.ex. 'en').
	 * @return array|WP_Error Array med översatta strängar (samma ordning) eller WP_Error.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$api_key = cotranslate_get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'DeepL API-nyckel saknas.' );
		}

		// Dela upp i batchar om det behövs
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
	 * DeepL hanterar HTML-taggar direkt — ingen egen DOM-parsning behövs.
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

		$api_key = cotranslate_get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'DeepL API-nyckel saknas.' );
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
		// Konvertera bindestreck till mellanslag för bättre översättning
		$text = str_replace( '-', ' ', $slug );

		$result = $this->translate_text( array( $text ), $source_lang, $target_lang );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Konvertera tillbaka till slug-format
		$translated = $result[0] ?? $slug;
		return sanitize_title( $translated );
	}

	/**
	 * Testa API-anslutning.
	 *
	 * @param string $api_key Valfri API-nyckel att testa (annars sparad nyckel).
	 * @return array|WP_Error Array med 'success' och 'message' eller WP_Error.
	 */
	public function test_connection( $api_key = '' ) {
		if ( empty( $api_key ) ) {
			$api_key = cotranslate_get_api_key();
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Ingen API-nyckel angiven.' );
		}

		$base_url = $this->get_base_url_for_key( $api_key );
		$response = wp_remote_get(
			$base_url . '/usage',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $body['message'] ) ? $body['message'] : 'Okänt fel (HTTP ' . $code . ')';
			return new WP_Error( 'api_error', $message );
		}

		return array(
			'success'        => true,
			'character_count' => $body['character_count'] ?? 0,
			'character_limit' => $body['character_limit'] ?? 0,
		);
	}

	/**
	 * Hämta API-användningsstatistik.
	 *
	 * @return array|WP_Error Array med 'character_count' och 'character_limit' eller WP_Error.
	 */
	public function get_usage() {
		// Cacha i 1 timme
		$cached = get_transient( 'cotranslate_deepl_usage' );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$usage = array(
			'character_count' => $result['character_count'],
			'character_limit' => $result['character_limit'],
		);

		set_transient( 'cotranslate_deepl_usage', $usage, HOUR_IN_SECONDS );

		return $usage;
	}

	/**
	 * Hämta DeepL:s stödda språk via API.
	 *
	 * @return array|WP_Error Array med språk eller WP_Error.
	 */
	public function get_supported_languages() {
		$api_key = cotranslate_get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'DeepL API-nyckel saknas.' );
		}

		$response = wp_remote_get(
			cotranslate_get_api_base_url() . '/languages?type=target',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'api_error', 'Kunde inte hämta språklista (HTTP ' . $code . ')' );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Gör API-anrop till DeepL.
	 *
	 * @param array  $texts       Array med texter att översätta.
	 * @param string $source_lang Källspråk (WordPress-format).
	 * @param string $target_lang Målspråk (WordPress-format).
	 * @param bool   $html        Om true, aktivera tag_handling=html.
	 * @return array|WP_Error Array med översatta texter eller WP_Error.
	 */
	private function call_api( array $texts, $source_lang, $target_lang, $html = false ) {
		$api_key  = cotranslate_get_api_key();
		$base_url = cotranslate_get_api_base_url();

		$body = array(
			'text'        => $texts,
			'source_lang' => cotranslate_wp_to_deepl_lang( $source_lang ),
			'target_lang' => cotranslate_wp_to_deepl_lang( $target_lang ),
		);

		if ( $html ) {
			$body['tag_handling']    = 'html';
			$body['split_sentences'] = 'nonewlines';
		}

		$response = wp_remote_post(
			$base_url . '/translate',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );

		if ( 200 !== $code ) {
			$message = isset( $data['message'] ) ? $data['message'] : 'DeepL API-fel (HTTP ' . $code . ')';

			// Specifika felkoder
			if ( 456 === $code ) {
				$message = 'DeepL-kvoten har överskridits. Uppgradera till Pro eller vänta till nästa månad.';
			} elseif ( 403 === $code ) {
				$message = 'Ogiltig DeepL API-nyckel.';
			} elseif ( 429 === $code ) {
				$message = 'För många förfrågningar. Försök igen om en stund.';
			}

			return new WP_Error( 'deepl_error_' . $code, $message );
		}

		if ( ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			return new WP_Error( 'invalid_response', 'Oväntat svar från DeepL API.' );
		}

		// Rensa usage-cache vid varje anrop så kvoten uppdateras
		delete_transient( 'cotranslate_deepl_usage' );

		// Extrahera översättningarna i rätt ordning
		$translated = array();
		foreach ( $data['translations'] as $item ) {
			$translated[] = $item['text'];
		}

		return $translated;
	}

	/**
	 * Avgör basURL baserat på API-nyckel.
	 *
	 * @param string $api_key API-nyckel.
	 * @return string BAS-URL.
	 */
	private function get_base_url_for_key( $api_key ) {
		if ( substr( $api_key, -3 ) === ':fx' ) {
			return 'https://api-free.deepl.com/v2';
		}
		return 'https://api.deepl.com/v2';
	}
}
