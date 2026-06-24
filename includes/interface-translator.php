<?php
/**
 * Gemensamt interface för översättningsmotorer.
 *
 * Implementeras av både DeepL- och Claude-motorerna samt av
 * No_Translate-wrappern. Tack vare detta kan konsumenter (post-translator,
 * string-translator m.fl.) typdeklarera mot interfacet i stället för en
 * konkret motorklass, så att wrappern kan skickas in utan typfel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CoTranslate_Translator {

	/**
	 * Översätt en lista texter.
	 *
	 * @param array  $texts       Texter att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @return array Översatta texter i samma ordning.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang );

	/**
	 * Översätt HTML-innehåll.
	 *
	 * @param string $html        HTML att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @return string Översatt HTML.
	 */
	public function translate_html( $html, $source_lang, $target_lang );

	/**
	 * Översätt en slug.
	 *
	 * @param string $slug        Slug att översätta.
	 * @param string $source_lang Källspråk.
	 * @param string $target_lang Målspråk.
	 * @return string Översatt slug.
	 */
	public function translate_slug( $slug, $source_lang, $target_lang );
}
