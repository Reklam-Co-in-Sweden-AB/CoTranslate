<?php
/**
 * Ninja Tables-integration.
 *
 * Ninja Tables skriver kolumnrubrikerna i ett inline-skript
 * (window.ninja_table_instance_N), och tabellens JavaScript ritar om
 * <thead> därifrån när sidan laddas. Strängöversättaren rör aldrig
 * <script>, så rubrikerna stod kvar på svenska även när tabellens HTML
 * var översatt. Här översätts de innan skriptet skrivs ut.
 *
 * Raderna översätts av strängöversättaren när tabellen renderas på servern
 * (Ninja Tables: "Legacy Table"). Tabeller av typen "Ajax Table" hämtar
 * raderna via admin-ajax utan språkkontext, och dem täcker integrationen inte.
 *
 * @package CoTranslate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Ninja_Tables {

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	/**
	 * Köade texter under sidvisningen (undviker dubbla skrivningar).
	 *
	 * @var array
	 */
	private $queued = array();

	public function __construct( CoTranslate_Translation_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Registrera hooks. Filtret finns bara om Ninja Tables är aktivt, så
	 * inget behöver kontrolleras här.
	 */
	public function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'ninja_table_rendering_table_vars', array( $this, 'filter_table_vars' ), 10, 1 );
	}

	/**
	 * Översätt kolumnrubriker och filtertexter i tabellens skriptkonfiguration.
	 *
	 * @param array $table_vars Konfigurationen som skrivs ut som JSON.
	 * @return array
	 */
	public function filter_table_vars( $table_vars ) {
		if ( ! is_array( $table_vars ) || cotranslate_is_default_language() ) {
			return $table_vars;
		}

		$language = cotranslate_get_current_language();

		if ( ! empty( $table_vars['columns'] ) && is_array( $table_vars['columns'] ) ) {
			foreach ( $table_vars['columns'] as $i => $column ) {
				if ( isset( $column['title'] ) && is_string( $column['title'] ) ) {
					$table_vars['columns'][ $i ]['title'] = $this->translate( $column['title'], $language );
				}
			}
		}

		if ( ! empty( $table_vars['settings']['filtering'] ) && is_array( $table_vars['settings']['filtering'] ) ) {
			foreach ( array( 'dropdownTitle', 'placeholder' ) as $key ) {
				if ( isset( $table_vars['settings']['filtering'][ $key ] ) && is_string( $table_vars['settings']['filtering'][ $key ] ) ) {
					$table_vars['settings']['filtering'][ $key ] = $this->translate( $table_vars['settings']['filtering'][ $key ], $language );
				}
			}
		}

		$this->schedule_queue();

		return $table_vars;
	}

	/**
	 * Slå upp en text i strängtabellen. Saknas den köas den, men en befintlig
	 * rad skrivs aldrig över (en tom sparning skulle tömma översättningen).
	 *
	 * @param string $text     Källtext.
	 * @param string $language Målspråk.
	 * @return string Översättning, eller källtexten om den inte är klar.
	 */
	public function translate( $text, $language ) {
		$core = CoTranslate_String_Translator::normalize_edges( $text );
		if ( '' === $core || ! preg_match( '/\p{L}/u', $core ) ) {
			return $text;
		}

		$translated = $this->store->get_string_translation( $core, $language );

		if ( null === $translated ) {
			if ( ! isset( $this->queued[ $core ] ) ) {
				$this->queued[ $core ] = true;
				$this->store->save_string_translation( $core, $language, '', 'general', $this->current_post_id() );
			}
			return $text;
		}

		if ( '' === (string) $translated ) {
			return $text;
		}

		return CoTranslate_String_Translator::normalize_edges( $translated );
	}

	/**
	 * Sidan texten först sågs på (filtret "Hittad på" under Texter).
	 *
	 * @return int
	 */
	private function current_post_id() {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Schemalägg bakgrundsöversättning om något köades.
	 */
	private function schedule_queue() {
		if ( ! empty( $this->queued ) && ! wp_next_scheduled( 'cotranslate_translate_strings' ) ) {
			wp_schedule_single_event( time() + 30, 'cotranslate_translate_strings' );
		}
	}
}
