<?php
/**
 * Översätter poster vid sparning.
 *
 * Hakar in på save_post och skickar innehåll till DeepL.
 * Sparar resultatet via Translation Store. Kö-system för bakgrundsöversättning.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Post_Translator {

	/**
	 * @var CoTranslate_DeepL_API
	 */
	private $api;

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	/**
	 * Förhindra dubbla översättningar under samma request.
	 */
	private $processing = array();

	public function __construct( CoTranslate_DeepL_API $api, CoTranslate_Translation_Store $store ) {
		$this->api   = $api;
		$this->store = $store;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'cotranslate_process_queue', array( $this, 'process_queue' ) );

		// Eget cron-intervall
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	/**
	 * Lägg till cron-intervall (varje minut).
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => 'Varje minut',
		);
		return $schedules;
	}

	/**
	 * Triggas när en post sparas.
	 *
	 * @param int     $post_id Post-ID.
	 * @param WP_Post $post    Post-objekt.
	 * @param bool    $update  Om det är en uppdatering (inte ny post).
	 */
	public function on_save_post( $post_id, $post, $update ) {
		// Hoppa över autosave, revisioner och ogiltiga post-typer
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Kontrollera post-typ
		$supported_types = cotranslate_get_supported_post_types();
		if ( ! in_array( $post->post_type, $supported_types, true ) ) {
			return;
		}

		// Bara publicerade poster
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Förhindra oändlig loop
		if ( isset( $this->processing[ $post_id ] ) ) {
			return;
		}

		// Kontrollera behörighet
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->processing[ $post_id ] = true;

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();

		foreach ( $enabled_languages as $language ) {
			if ( $language === $default_language ) {
				continue;
			}

			// Markera befintliga auto-översättningar som pending
			$this->store->mark_pending( $post_id, $language );

			// Försök direktöversätta om API-nyckel finns
			$api_key = cotranslate_get_api_key();
			if ( ! empty( $api_key ) ) {
				$this->translate_post( $post_id, $language );
			}
		}

		unset( $this->processing[ $post_id ] );
	}

	/**
	 * Översätt en specifik post till ett språk.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk.
	 * @return bool True vid lyckad översättning.
	 */
	public function translate_post( $post_id, $language ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Kontrollera om manuell översättning finns — hoppa i så fall över
		$existing = $this->store->get_post_translation( $post_id, $language );
		if ( $existing && (int) $existing->is_manual === 1 ) {
			return false;
		}

		$default_language = cotranslate_get_default_language();
		$chars_used       = 0;

		// Kontrollera om innehållet har page builder-shortcodes.
		// Om ja, översätt bara titel/excerpt/slug — inte content.
		// Content hanteras av String Translator (output buffer) istället.
		$has_page_builder = $this->has_page_builder_content( $post->post_content );

		// Översätt titel
		$translated_title = '';
		if ( ! empty( $post->post_title ) ) {
			$result = $this->api->translate_text(
				array( $post->post_title ),
				$default_language,
				$language
			);
			if ( ! is_wp_error( $result ) && ! empty( $result[0] ) ) {
				$translated_title = $result[0];
				$chars_used      += mb_strlen( $post->post_title );
			}
		}

		// Översätt innehåll (HTML direkt till DeepL)
		// Hoppa över om page builder — shortcodes förstörs av översättning
		$translated_content = '';
		if ( ! empty( $post->post_content ) && ! $has_page_builder ) {
			$result = $this->api->translate_html(
				$post->post_content,
				$default_language,
				$language
			);
			if ( ! is_wp_error( $result ) ) {
				$translated_content = $result;
				$chars_used        += mb_strlen( $post->post_content );
			}
		}

		// Översätt excerpt
		$translated_excerpt = '';
		if ( ! empty( $post->post_excerpt ) ) {
			$result = $this->api->translate_text(
				array( $post->post_excerpt ),
				$default_language,
				$language
			);
			if ( ! is_wp_error( $result ) && ! empty( $result[0] ) ) {
				$translated_excerpt = $result[0];
				$chars_used        += mb_strlen( $post->post_excerpt );
			}
		}

		// Översätt slug om aktiverat
		$translated_slug = '';
		if ( get_option( 'cotranslate_translate_slugs', false ) && ! empty( $post->post_name ) ) {
			$result = $this->api->translate_slug(
				$post->post_name,
				$default_language,
				$language
			);
			if ( ! is_wp_error( $result ) ) {
				$translated_slug = $result;
				$chars_used     += mb_strlen( $post->post_name );
			}
		}

		// Spara via Translation Store (respekterar is_manual)
		$data = array(
			'title'   => $translated_title,
			'content' => $translated_content,
			'excerpt' => $translated_excerpt,
			'slug'    => $translated_slug,
		);

		return $this->store->save_post_translation( $post_id, $language, $data, $chars_used );
	}

	/**
	 * Processa kön med väntande översättningar.
	 *
	 * Anropas av WP-Cron.
	 */
	public function process_queue() {
		$api_key = cotranslate_get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		// Kontrollera kvot innan vi börjar
		$usage = $this->api->get_usage();
		if ( ! is_wp_error( $usage ) ) {
			$percent = ( $usage['character_count'] / max( $usage['character_limit'], 1 ) ) * 100;
			if ( $percent >= 95 ) {
				return; // Stoppa vid 95% förbrukning
			}
		}

		$pending = $this->store->get_pending_translations( 5 );

		foreach ( $pending as $row ) {
			$this->translate_post( (int) $row->post_id, $row->language );
		}
	}

	/**
	 * Köa alla publicerade poster för översättning.
	 *
	 * @return int Antal köade poster.
	 */
	public function queue_all_posts() {
		$supported_types  = cotranslate_get_supported_post_types();
		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$count             = 0;

		$posts = get_posts( array(
			'post_type'      => $supported_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $posts as $post_id ) {
			foreach ( $enabled_languages as $language ) {
				if ( $language === $default_language ) {
					continue;
				}

				// Skapa pending-rad om ingen finns
				$existing = $this->store->get_post_translation( $post_id, $language );
				if ( ! $existing ) {
					$this->store->save_post_translation( $post_id, $language, array(
						'title'   => '',
						'content' => '',
						'excerpt' => '',
						'slug'    => '',
					) );
					$this->store->mark_pending( $post_id, $language );
					$count++;
				} elseif ( 'pending' !== $existing->status && (int) $existing->is_manual === 0 ) {
					$this->store->mark_pending( $post_id, $language );
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Kontrollera om innehåll har page builder-shortcodes.
	 *
	 * Om ja, ska content INTE skickas till DeepL (shortcodes förstörs).
	 * Istället hanteras översättningen av String Translator via output buffer.
	 *
	 * @param string $content Rå post_content.
	 * @return bool True om page builder-shortcodes hittades.
	 */
	private function has_page_builder_content( $content ) {
		if ( empty( $content ) ) {
			return false;
		}

		$page_builder_patterns = array(
			'[vc_row',          // WPBakery / Visual Composer / Uncode
			'[vc_column',
			'[vc_section',
			'[et_pb_',          // Divi
			'[fl_builder',      // Beaver Builder
			'[fusion_',         // Avada / Fusion Builder
			'[cs_',             // Cornerstone
			'[tatsu_',          // Flavor / Flavor Builder
		);

		foreach ( $page_builder_patterns as $pattern ) {
			if ( strpos( $content, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
