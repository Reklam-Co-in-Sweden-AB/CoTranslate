<?php
/**
 * Synk av ordlistan till DeepL:s glossary-API.
 *
 * DeepL-ordlistor (v2) är oföränderliga: en ändring innebär radera + skapa
 * ny. Vi sparar id och en hash av innehållet per målspråk så att vi bara
 * skapar om när något faktiskt ändrats. Källspråk är alltid huvudspråket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_DeepL_Glossary {

	const OPTION_IDS      = 'cotranslate_deepl_glossary_ids';
	const TRANSIENT_PAIRS = 'cotranslate_deepl_glossary_pairs';
	const TIMEOUT         = 30;

	/**
	 * Hämta sparat glossary-id för ett målspråk.
	 *
	 * @param string $target_lang Målspråkets kod (WordPress-format).
	 * @return string Id eller tom sträng.
	 */
	public static function get_id( $target_lang ) {
		$ids = get_option( self::OPTION_IDS, array() );
		return isset( $ids[ $target_lang ]['id'] ) ? (string) $ids[ $target_lang ]['id'] : '';
	}

	/**
	 * Hämta synkstatus för ett målspråk (till UI).
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return array array( 'id' => '', 'hash' => '', 'error' => '', 'synced_at' => 0 ).
	 */
	public static function get_status( $target_lang ) {
		$ids = get_option( self::OPTION_IDS, array() );
		return wp_parse_args(
			$ids[ $target_lang ] ?? array(),
			array( 'id' => '', 'hash' => '', 'error' => '', 'synced_at' => 0 )
		);
	}

	/**
	 * Glöm sparade id:n (utan att radera hos DeepL). Används vid byte av
	 * API-nyckel, eftersom ordlistorna hör till kontot.
	 */
	public static function clear_ids() {
		delete_option( self::OPTION_IDS );
		delete_transient( self::TRANSIENT_PAIRS );
	}

	/**
	 * Glöm ett enskilt språks id (t.ex. när DeepL svarar att ordlistan är
	 * borta). Nästa synk skapar en ny.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param string $error       Valfritt felmeddelande att visa i UI.
	 */
	public static function forget_id( $target_lang, $error = '' ) {
		$ids = get_option( self::OPTION_IDS, array() );
		$ids[ $target_lang ] = array(
			'id'        => '',
			'hash'      => '',
			'error'     => $error,
			'synced_at' => 0,
		);
		update_option( self::OPTION_IDS, $ids );
	}

	/**
	 * Synka ett målspråks ordlista till DeepL.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param bool   $force       Skapa om även om hashen är oförändrad.
	 * @return true|WP_Error
	 */
	public static function sync_language( $target_lang, $force = false ) {
		$source_lang = cotranslate_get_default_language();
		$entries     = CoTranslate_Glossary::get_entries( $target_lang );
		$status      = self::get_status( $target_lang );

		// Tom ordlista: radera eventuell befintlig och glöm id.
		if ( empty( $entries ) ) {
			if ( '' !== $status['id'] ) {
				self::delete_remote( $status['id'] );
			}
			$ids = get_option( self::OPTION_IDS, array() );
			unset( $ids[ $target_lang ] );
			update_option( self::OPTION_IDS, $ids );
			return true;
		}

		$hash = md5( $source_lang . '|' . $target_lang . '|' . CoTranslate_Glossary::to_tsv( $entries ) );

		if ( ! $force && '' !== $status['id'] && $status['hash'] === $hash ) {
			return true; // Oförändrad
		}

		$pair_check = self::check_pair_supported( $source_lang, $target_lang );
		if ( is_wp_error( $pair_check ) ) {
			self::store_error( $target_lang, $pair_check->get_error_message() );
			return $pair_check;
		}

		// Radera gammal innan ny skapas (DeepL har en gräns för antal ordlistor).
		// Glöm id:t direkt så att ett misslyckat skapande inte lämnar ett dött id.
		if ( '' !== $status['id'] ) {
			self::delete_remote( $status['id'] );
			self::forget_id( $target_lang );
		}

		$created = self::create_remote( $source_lang, $target_lang, $entries );
		if ( is_wp_error( $created ) ) {
			self::store_error( $target_lang, $created->get_error_message() );
			return $created;
		}

		$ids                 = get_option( self::OPTION_IDS, array() );
		$ids[ $target_lang ] = array(
			'id'        => $created,
			'hash'      => $hash,
			'error'     => '',
			'synced_at' => time(),
		);
		update_option( self::OPTION_IDS, $ids );

		return true;
	}

	/**
	 * Synka alla aktiverade målspråk.
	 *
	 * @param bool $force Skapa om alla.
	 * @return array målspråk => true|WP_Error.
	 */
	public static function sync_all( $force = false ) {
		$default = cotranslate_get_default_language();
		$results = array();

		foreach ( cotranslate_get_enabled_languages() as $lang ) {
			if ( $lang === $default ) {
				continue;
			}
			$results[ $lang ] = self::sync_language( $lang, $force );
		}

		return $results;
	}

	// =========================================================================
	// PRIVATA HJÄLPARE
	// =========================================================================

	/**
	 * Kontrollera att DeepL stödjer ordlistor för språkparet.
	 *
	 * @return true|WP_Error
	 */
	private static function check_pair_supported( $source_lang, $target_lang ) {
		$pairs = get_transient( self::TRANSIENT_PAIRS );

		if ( ! is_array( $pairs ) ) {
			$response = wp_remote_get(
				cotranslate_get_api_base_url() . '/glossary-language-pairs',
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Authorization' => 'DeepL-Auth-Key ' . cotranslate_get_api_key() ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['supported_languages'] ) ) {
				return new WP_Error( 'glossary_pairs', 'Kunde inte hämta DeepL:s lista över språkpar för ordlistor.' );
			}

			$pairs = array();
			foreach ( $data['supported_languages'] as $pair ) {
				$pairs[] = strtolower( $pair['source_lang'] ) . '>' . strtolower( $pair['target_lang'] );
			}
			set_transient( self::TRANSIENT_PAIRS, $pairs, DAY_IN_SECONDS );
		}

		$key = strtolower( cotranslate_wp_to_deepl_lang( $source_lang ) ) . '>' . strtolower( cotranslate_wp_to_deepl_lang( $target_lang ) );

		if ( ! in_array( $key, $pairs, true ) ) {
			return new WP_Error( 'glossary_pair_unsupported', 'DeepL stödjer inte ordlistor för det här språkparet.' );
		}

		return true;
	}

	/**
	 * Skapa ordlista hos DeepL.
	 *
	 * @return string|WP_Error glossary_id.
	 */
	private static function create_remote( $source_lang, $target_lang, array $entries ) {
		$body = array(
			'name'           => 'CoTranslate ' . strtoupper( $source_lang ) . '-' . strtoupper( $target_lang ) . ' ' . wp_date( 'Y-m-d H:i' ),
			'source_lang'    => cotranslate_wp_to_deepl_lang( $source_lang ),
			'target_lang'    => cotranslate_wp_to_deepl_lang( $target_lang ),
			'entries'        => CoTranslate_Glossary::to_tsv( $entries ),
			'entries_format' => 'tsv',
		);

		$response = wp_remote_post(
			cotranslate_get_api_base_url() . '/glossaries',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . cotranslate_get_api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $code && 200 !== $code ) {
			$message = $data['message'] ?? ( $data['detail'] ?? 'DeepL-fel (HTTP ' . $code . ')' );
			return new WP_Error( 'glossary_create', 'Kunde inte skapa ordlista hos DeepL: ' . $message );
		}

		if ( empty( $data['glossary_id'] ) ) {
			return new WP_Error( 'glossary_create', 'DeepL returnerade inget ordliste-id.' );
		}

		return (string) $data['glossary_id'];
	}

	/**
	 * Radera ordlista hos DeepL. Fel ignoreras (ordlistan kan redan vara borta).
	 */
	private static function delete_remote( $glossary_id ) {
		wp_remote_request(
			cotranslate_get_api_base_url() . '/glossaries/' . rawurlencode( $glossary_id ),
			array(
				'method'  => 'DELETE',
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'DeepL-Auth-Key ' . cotranslate_get_api_key() ),
			)
		);
	}

	/**
	 * Spara felmeddelande för ett språk (till UI) utan att röra id/hash.
	 */
	private static function store_error( $target_lang, $error ) {
		$ids                            = get_option( self::OPTION_IDS, array() );
		$ids[ $target_lang ]            = wp_parse_args( $ids[ $target_lang ] ?? array(), array( 'id' => '', 'hash' => '', 'synced_at' => 0 ) );
		$ids[ $target_lang ]['error']   = $error;
		update_option( self::OPTION_IDS, $ids );
	}
}
