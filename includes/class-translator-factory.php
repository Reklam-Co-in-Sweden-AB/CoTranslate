<?php
/**
 * Factory för översättningsmotorer.
 *
 * Returnerar rätt API-klass (DeepL eller Claude) baserat på inställning.
 * Båda klasserna har samma publika interface:
 *   - translate_text( array $texts, $source_lang, $target_lang )
 *   - translate_html( $html, $source_lang, $target_lang )
 *   - translate_slug( $slug, $source_lang, $target_lang )
 *   - test_connection( $api_key = '' )
 *   - get_usage()
 *   - get_supported_languages()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Translator_Factory {

	/**
	 * Tillgängliga motorer.
	 */
	const ENGINE_DEEPL  = 'deepl';
	const ENGINE_CLAUDE = 'claude';

	/**
	 * Skapa en översättningsmotor-instans.
	 *
	 * @param string $engine Motor att använda. Om tom, hämta från inställning.
	 * @return CoTranslate_DeepL_API|CoTranslate_Claude_API
	 */
	public static function create( $engine = '' ) {
		if ( empty( $engine ) ) {
			$engine = get_option( 'cotranslate_translation_engine', self::ENGINE_DEEPL );
		}

		if ( self::ENGINE_CLAUDE === $engine ) {
			return new CoTranslate_Claude_API();
		}

		return new CoTranslate_DeepL_API();
	}

	/**
	 * Hämta aktuell motor.
	 *
	 * @return string 'deepl' eller 'claude'.
	 */
	public static function get_current_engine() {
		return get_option( 'cotranslate_translation_engine', self::ENGINE_DEEPL );
	}

	/**
	 * Hämta alla tillgängliga motorer.
	 *
	 * @return array Associativ array med motor-id => beskrivning.
	 */
	public static function get_available_engines() {
		return array(
			self::ENGINE_DEEPL  => array(
				'name'        => 'DeepL',
				'description' => 'Snabb, konsekvent, stöd för HTML. Bäst för europeiska språk. Gratis tier: 500k tecken/mån.',
			),
			self::ENGINE_CLAUDE => array(
				'name'        => 'Claude (Anthropic)',
				'description' => 'Kontextmedveten AI-översättning med stöd för fritext-instruktioner. Dyrare men mer flexibel.',
			),
		);
	}
}
