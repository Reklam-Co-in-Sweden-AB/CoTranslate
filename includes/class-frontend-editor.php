<?php
/**
 * Frontend-editor för visuell redigering av översättningar.
 *
 * Tillåter inloggade administratörer att redigera översättningar
 * direkt på live-sajten. Sparar via Translation Store med is_manual=1.
 * Exakt samma kodväg som admin-redigeringar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Frontend_Editor {

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	public function __construct( CoTranslate_Translation_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		if ( ! get_option( 'cotranslate_enable_frontend_editor', false ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_editor_ui' ), 100 );

		// AJAX-endpoints
		add_action( 'wp_ajax_cotranslate_frontend_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_cotranslate_frontend_get', array( $this, 'ajax_get_translation' ) );
		add_action( 'wp_ajax_cotranslate_frontend_source', array( $this, 'ajax_get_source' ) );
	}

	/**
	 * Ladda assets om användaren har behörighet.
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->can_edit() ) {
			return;
		}

		if ( cotranslate_is_default_language() ) {
			return;
		}

		wp_enqueue_style(
			'cotranslate-frontend-editor',
			COTRANSLATE_PLUGIN_URL . 'assets/css/frontend-editor.css',
			array(),
			COTRANSLATE_VERSION
		);

		wp_enqueue_script(
			'cotranslate-frontend-editor',
			COTRANSLATE_PLUGIN_URL . 'assets/js/frontend-editor.js',
			array(),
			COTRANSLATE_VERSION,
			true
		);

		wp_localize_script( 'cotranslate-frontend-editor', 'cotranslateFrontend', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cotranslate_frontend' ),
			'language' => cotranslate_get_current_language(),
			'postId'   => get_the_ID(),
			'sourceLanguageName' => cotranslate_get_supported_languages()[ cotranslate_get_default_language() ]['native'] ?? cotranslate_get_default_language(),
		) );
	}

	/**
	 * Rendera editor-UI i footer.
	 */
	public function render_editor_ui() {
		if ( ! $this->can_edit() || cotranslate_is_default_language() ) {
			return;
		}

		?>
		<!-- CoTranslate Frontend Editor -->
		<div id="cotranslate-editor-toggle" translate="no">
			<button type="button" id="cotranslate-edit-mode-btn" title="Aktivera översättningsredigering">
				<span class="cotranslate-edit-icon">&#9998;</span>
				<span class="cotranslate-edit-label">Redigera</span>
			</button>
		</div>

		<div id="cotranslate-editor-modal" style="display:none;" translate="no">
			<div class="cotranslate-editor-modal-content">
				<button type="button" class="cotranslate-editor-close">&times;</button>
				<h3 id="cotranslate-editor-modal-title">Redigera översättning</h3>

				<div class="cotranslate-editor-field">
					<label>Original</label>
					<div id="cotranslate-editor-original" class="cotranslate-editor-readonly"></div>
				</div>

				<div class="cotranslate-editor-field">
					<label>Översättning</label>
					<textarea id="cotranslate-editor-translation" rows="4"></textarea>
				</div>

				<div class="cotranslate-editor-field cotranslate-editor-glossary">
					<label>Gäller överallt</label>
					<p class="cotranslate-editor-hint">
						Är det ett ord som är fel på hela sajten? Ange ordet på
						<?php echo esc_html( cotranslate_get_supported_languages()[ cotranslate_get_default_language() ]['native'] ?? cotranslate_get_default_language() ); ?>
						och vad det ska bli, så läggs det i ordlistan och alla berörda sidor översätts om.
						Lämna tomt för att bara rätta den här texten.
					</p>
					<div id="cotranslate-editor-source-hint" class="cotranslate-editor-readonly" style="display:none;"></div>
					<div class="cotranslate-editor-glossary-row">
						<input type="text" id="cotranslate-editor-gl-source" placeholder="Ord i originalet" autocomplete="off" />
						<span class="cotranslate-editor-glossary-sep">ska bli</span>
						<input type="text" id="cotranslate-editor-gl-target" placeholder="Ny översättning" autocomplete="off" />
					</div>
				</div>

				<input type="hidden" id="cotranslate-editor-type" value="" />
				<input type="hidden" id="cotranslate-editor-post-id" value="" />
				<input type="hidden" id="cotranslate-editor-field" value="" />
				<input type="hidden" id="cotranslate-editor-source-text" value="" />

				<div class="cotranslate-editor-actions">
					<button type="button" id="cotranslate-editor-save" class="cotranslate-btn-primary">
						Spara (manuell override)
					</button>
					<button type="button" id="cotranslate-editor-cancel" class="cotranslate-btn-secondary">
						Avbryt
					</button>
				</div>

				<div id="cotranslate-editor-status"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Spara översättning från frontend.
	 *
	 * SAMMA kodväg som admin — använder Translation Store med is_manual=1.
	 */
	public function ajax_save() {
		check_ajax_referer( 'cotranslate_frontend', 'nonce' );

		if ( ! $this->can_edit() ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( empty( $language ) ) {
			wp_send_json_error( 'Språk saknas.' );
		}

		if ( 'post' === $type ) {
			// Post-innehåll (titel, content, excerpt)
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			$field   = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
			$value   = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';

			if ( empty( $post_id ) || empty( $field ) ) {
				wp_send_json_error( 'Post-ID och fält krävs.' );
			}

			// Hämta befintlig översättning och uppdatera bara det aktuella fältet
			$existing = $this->store->get_post_translation( $post_id, $language );

			$data = array(
				'title'   => $existing ? $existing->translated_title : '',
				'content' => $existing ? $existing->translated_content : '',
				'excerpt' => $existing ? $existing->translated_excerpt : '',
				'slug'    => $existing ? $existing->translated_slug : '',
			);

			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = $value;
			}

			// Spara som manuell — EXAKT samma som admin-redigering
			$result = $this->store->save_manual_post_translation( $post_id, $language, $data );

		} elseif ( 'string' === $type ) {
			// Tema-sträng — texten som skickas kan vara redan översatt
			$visible_text    = isset( $_POST['source_text'] ) ? wp_unslash( $_POST['source_text'] ) : '';
			$translated_text = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

			if ( empty( $visible_text ) ) {
				wp_send_json_error( 'Källtext saknas.' );
			}

			// Försök hitta originaltexten (svenska) som gav denna synliga text
			global $wpdb;
			$table       = $wpdb->prefix . 'cotranslate_strings';
			$source_text = $visible_text;

			// Kolla om texten matchar en translated_text — i så fall är originalet source_text
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$original = $wpdb->get_var( $wpdb->prepare(
				"SELECT source_text FROM {$table} WHERE translated_text = %s AND language = %s LIMIT 1",
				$visible_text,
				$language
			) );

			if ( $original ) {
				$source_text = $original;
			}

			// Spara som manuell — EXAKT samma som admin-redigering
			$result = $this->store->save_manual_string_translation(
				$source_text,
				$language,
				$translated_text,
				'frontend'
			);

		} else {
			wp_send_json_error( 'Ogiltig typ.' );
			return;
		}

		if ( ! $result ) {
			wp_send_json_error( 'Kunde inte spara översättningen.' );
		}

		$response = array(
			'message'  => 'Översättning sparad som manuell override.',
			'requeued' => array( 'posts' => 0, 'strings' => 0 ),
			'pending'  => array( 'posts' => 0, 'strings' => 0 ),
		);

		// "Gäller överallt": lägg termparet i ordlistan och köa berört innehåll
		$gl_source = isset( $_POST['gl_source'] ) ? sanitize_text_field( wp_unslash( $_POST['gl_source'] ) ) : '';
		$gl_target = isset( $_POST['gl_target'] ) ? sanitize_text_field( wp_unslash( $_POST['gl_target'] ) ) : '';

		if ( '' !== $gl_source && '' !== $gl_target ) {
			CoTranslate_Glossary::add_entry( $language, $gl_source, $gl_target );

			if ( CoTranslate_Translator_Factory::get_current_engine() === CoTranslate_Translator_Factory::ENGINE_DEEPL ) {
				$sync = CoTranslate_DeepL_Glossary::sync_language( $language );
				if ( is_wp_error( $sync ) ) {
					$response['message'] .= ' Ordlistan sparades men kunde inte synkas till DeepL: ' . $sync->get_error_message();
				}
			}

			$requeue              = new CoTranslate_Glossary_Requeue( $this->store );
			$response['requeued'] = $requeue->requeue( $language, array( $gl_source ) );
			$response['pending']  = $requeue->count_pending();
			$response['message']  = sprintf(
				'Sparat. "%s" ska nu bli "%s" överallt. %d sidor och %d texter översätts om.',
				$gl_source,
				$gl_target,
				$response['requeued']['posts'],
				$response['requeued']['strings']
			);
		}

		// Rensa cache efter sparning
		$this->purge_cache();

		wp_send_json_success( $response );
	}

	/**
	 * Rensa sidcache efter att en översättning sparats.
	 *
	 * Stöder LiteSpeed Cache, WP Super Cache, W3 Total Cache,
	 * WP Rocket, WP Fastest Cache och Autoptimize.
	 */
	public function purge_cache() {
		// LiteSpeed Cache
		if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
			LiteSpeed_Cache_API::purge_all();
		} elseif ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// WP Fastest Cache
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache();
		}

		// Autoptimize
		if ( class_exists( 'autoptimizeCache' ) ) {
			autoptimizeCache::clearall();
		}

		// WordPress object cache
		wp_cache_flush();
	}

	/**
	 * AJAX: Hämta befintlig översättning.
	 */
	public function ajax_get_translation() {
		check_ajax_referer( 'cotranslate_frontend', 'nonce' );

		if ( ! $this->can_edit() ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( empty( $post_id ) || empty( $language ) ) {
			wp_send_json_error( 'Post-ID och språk krävs.' );
		}

		$translation = $this->store->get_post_translation( $post_id, $language );

		if ( ! $translation ) {
			wp_send_json_error( 'Ingen översättning hittad.' );
		}

		wp_send_json_success( array(
			'title'     => $translation->translated_title,
			'content'   => $translation->translated_content,
			'excerpt'   => $translation->translated_excerpt,
			'slug'      => $translation->translated_slug,
			'is_manual' => (bool) $translation->is_manual,
		) );
	}

	/**
	 * AJAX: Hämta originaltexten (huvudspråket) bakom en synlig text.
	 *
	 * Används av "Gäller överallt" så att redaktören ser vilket ord i
	 * originalet som ska in i ordlistan.
	 */
	public function ajax_get_source() {
		check_ajax_referer( 'cotranslate_frontend', 'nonce' );

		if ( ! $this->can_edit() ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';
		$visible  = isset( $_POST['source_text'] ) ? wp_unslash( $_POST['source_text'] ) : '';
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( 'post' === $type && $post_id > 0 ) {
			$post = get_post( $post_id );
			wp_send_json_success( array( 'source' => $post ? $post->post_title : '' ) );
		}

		if ( '' === $visible || '' === $language ) {
			wp_send_json_success( array( 'source' => '' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$source = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_text FROM {$table} WHERE translated_text = %s AND language = %s LIMIT 1",
			$visible,
			$language
		) );

		wp_send_json_success( array( 'source' => (string) $source ) );
	}

	/**
	 * Kontrollera om användaren kan redigera översättningar.
	 *
	 * @return bool True om behörig.
	 */
	private function can_edit() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}
}
