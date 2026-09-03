<?php
/**
 * Admin-panel för CoTranslate.
 *
 * Hanterar inställningssidor, AJAX-endpoints och översättningshantering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Admin {

	/**
	 * @var CoTranslate_DeepL_API
	 */
	private $api;

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	/**
	 * @var CoTranslate_Post_Translator
	 */
	private $post_translator;

	public function __construct( CoTranslate_Translator $api, CoTranslate_Translation_Store $store, CoTranslate_Post_Translator $post_translator ) {
		$this->api             = $api;
		$this->store           = $store;
		$this->post_translator = $post_translator;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX-endpoints
		add_action( 'wp_ajax_cotranslate_test_api', array( $this, 'ajax_test_api' ) );
		add_action( 'wp_ajax_cotranslate_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_cotranslate_translate_all', array( $this, 'ajax_translate_all' ) );
		add_action( 'wp_ajax_cotranslate_translate_post', array( $this, 'ajax_translate_post' ) );
		add_action( 'wp_ajax_cotranslate_update_translation', array( $this, 'ajax_update_translation' ) );
		add_action( 'wp_ajax_cotranslate_reset_translation', array( $this, 'ajax_reset_translation' ) );
		add_action( 'wp_ajax_cotranslate_delete_translation', array( $this, 'ajax_delete_translation' ) );
		add_action( 'wp_ajax_cotranslate_get_usage', array( $this, 'ajax_get_usage' ) );
		add_action( 'wp_ajax_cotranslate_bulk_translate_batch', array( $this, 'ajax_bulk_translate_batch' ) );
		add_action( 'wp_ajax_cotranslate_export_translations', array( $this, 'ajax_export_translations' ) );
		add_action( 'wp_ajax_cotranslate_import_translations', array( $this, 'ajax_import_translations' ) );
		add_action( 'wp_ajax_cotranslate_migrate_v2', array( $this, 'ajax_migrate_v2' ) );
		add_action( 'wp_ajax_cotranslate_process_strings', array( $this, 'ajax_process_strings' ) );
		add_action( 'wp_ajax_cotranslate_update_string', array( $this, 'ajax_update_string' ) );
		add_action( 'wp_ajax_cotranslate_delete_string', array( $this, 'ajax_delete_string' ) );
		add_action( 'wp_ajax_cotranslate_scan_page', array( $this, 'ajax_scan_page' ) );
		add_action( 'wp_ajax_cotranslate_scan_all', array( $this, 'ajax_scan_all' ) );
		add_action( 'wp_ajax_cotranslate_process_queue_now', array( $this, 'ajax_process_queue_now' ) );
		add_action( 'wp_ajax_cotranslate_glossary_release', array( $this, 'ajax_glossary_release' ) );
	}

	/**
	 * Lägg till menyposter i admin.
	 *
	 * En sida per fråga: "Hur ligger vi till?" (Översikt), "Var är den här
	 * sidan?" (Sidor), "Var är den här knapptexten?" (Texter), "Varför blev
	 * ordet så?" (Terminologi), och Inställningar för det man ändrar en gång.
	 */
	public function add_admin_menu() {
		add_menu_page(
			'CoTranslate',
			'CoTranslate',
			'edit_posts',
			'cotranslate',
			array( $this, 'render_overview_page' ),
			'dashicons-translation',
			80
		);

		add_submenu_page( 'cotranslate', 'Översikt', 'Översikt', 'edit_posts', 'cotranslate', array( $this, 'render_overview_page' ) );
		add_submenu_page( 'cotranslate', 'Sidor', 'Sidor', 'manage_options', 'cotranslate-translations', array( $this, 'render_translations_page' ) );
		add_submenu_page( 'cotranslate', 'Texter', 'Texter', 'manage_options', 'cotranslate-strings', array( $this, 'render_strings_page' ) );
		add_submenu_page( 'cotranslate', 'Terminologi', 'Terminologi', 'edit_posts', 'cotranslate-terminology', array( $this, 'render_terminology_page' ) );
		add_submenu_page( 'cotranslate', 'Inställningar', 'Inställningar', 'manage_options', 'cotranslate-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Omdirigera gamla menylänkar (bokmärken) till rätt flik under Terminologi.
	 */
	public function redirect_legacy_pages() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$map  = array(
			'cotranslate-glossary'     => 'glossary',
			'cotranslate-no-translate' => 'protected',
		);

		if ( isset( $map[ $page ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cotranslate-terminology&tab=' . $map[ $page ] ) );
			exit;
		}
	}

	/**
	 * Sektion "Skyddade ord" (f.d. "Översätt inte") — ord/fraser som aldrig översätts.
	 * Renderas som flik under Terminologi. Formuläret postar till samma sida.
	 */
	private function render_no_translate_section() {
		// Spara vid POST.
		if ( isset( $_POST['cotranslate_no_translate_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cotranslate_no_translate_nonce'] ) ), 'cotranslate_save_no_translate' ) ) {

			$raw   = isset( $_POST['cotranslate_nt'] ) && is_array( $_POST['cotranslate_nt'] ) ? wp_unslash( $_POST['cotranslate_nt'] ) : array();
			$saved = array();
			foreach ( $raw as $key => $value ) {
				$key   = sanitize_key( $key );
				$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
				$lines = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $lines ) ) );
				if ( ! empty( $lines ) ) {
					$saved[ $key ] = implode( "\n", $lines );
				}
			}
			update_option( 'cotranslate_no_translate_terms', $saved );
			echo '<div class="notice notice-success"><p>Sparat. Gäller allt som översätts från och med nu.</p></div>';
		}

		$terms             = get_option( 'cotranslate_no_translate_terms', array() );
		$default_language  = cotranslate_get_default_language();
		$enabled_languages = cotranslate_get_enabled_languages();
		$supported         = cotranslate_get_supported_languages();
		?>
		<p>
			Ord och fraser här lämnas exakt som de är i alla översättningar — varumärken, produktnamn, egennamn.
			Skriv ett ord eller en fras per rad. Matchningen är skiftlägesokänslig.
			Skyddade ord vinner över ordlistan: ett ord som står här nås aldrig av ordlistan.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=protected' ) ); ?>">
			<?php wp_nonce_field( 'cotranslate_save_no_translate', 'cotranslate_no_translate_nonce' ); ?>

			<h2>Alla språk</h2>
			<p class="description">Gäller oavsett målspråk (t.ex. varumärken).</p>
			<textarea name="cotranslate_nt[_all]" rows="6" class="large-text code"><?php echo esc_textarea( $terms['_all'] ?? '' ); ?></textarea>

			<?php
			foreach ( $enabled_languages as $lang ) :
				if ( $lang === $default_language ) {
					continue;
				}
				$data  = $supported[ $lang ] ?? array( 'native' => $lang, 'flag' => '' );
				$label = trim( ( $data['flag'] ?? '' ) . ' ' . ( $data['native'] ?? $lang ) );
				?>
				<h2><?php echo esc_html( $label ); ?></h2>
				<textarea name="cotranslate_nt[<?php echo esc_attr( $lang ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( $terms[ $lang ] ?? '' ); ?></textarea>
			<?php endforeach; ?>

			<p><button type="submit" class="button button-primary">Spara</button></p>
		</form>
		<?php
	}

	/**
	 * Sektion "Ordlista" — tvingande termpar per målspråk.
	 * Renderas som flik under Terminologi. Formuläret postar till samma sida.
	 */
	private function render_glossary_section() {

		$default_language  = cotranslate_get_default_language();
		$enabled_languages = cotranslate_get_enabled_languages();
		$supported         = cotranslate_get_supported_languages();
		$engine_is_deepl   = CoTranslate_Translator_Factory::get_current_engine() === CoTranslate_Translator_Factory::ENGINE_DEEPL;
		$requeue           = new CoTranslate_Glossary_Requeue( $this->store );
		$errors            = array();
		$queued            = array( 'posts' => 0, 'strings' => 0 );
		$saved             = false;

		// Spara vid POST.
		if ( isset( $_POST['cotranslate_glossary_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cotranslate_glossary_nonce'] ) ), 'cotranslate_save_glossary' ) ) {

			$saved = true;

			$force_sync = ! empty( $_POST['cotranslate_glossary_resync'] );
			$raw_all    = isset( $_POST['cotranslate_gl'] ) && is_array( $_POST['cotranslate_gl'] ) ? wp_unslash( $_POST['cotranslate_gl'] ) : array();

			foreach ( $enabled_languages as $lang ) {
				if ( $lang === $default_language ) {
					continue;
				}

				// Tabbar måste överleva (Excel-inklistring) — därför inte sanitize_text_field.
				$raw = isset( $raw_all[ $lang ] ) && is_string( $raw_all[ $lang ] ) ? wp_strip_all_tags( $raw_all[ $lang ] ) : '';
				$old = CoTranslate_Glossary::get_entries( $lang );
				$new = CoTranslate_Glossary::save_raw( $lang, $raw );

				if ( $engine_is_deepl ) {
					$sync = CoTranslate_DeepL_Glossary::sync_language( $lang, $force_sync );
					if ( is_wp_error( $sync ) ) {
						$errors[] = ( $supported[ $lang ]['native'] ?? $lang ) . ': ' . $sync->get_error_message();
					}
				}

				$changed = CoTranslate_Glossary::changed_terms( $old, $new );
				if ( ! empty( $changed ) ) {
					$result             = $requeue->requeue( $lang, $changed );
					$queued['posts']   += $result['posts'];
					$queued['strings'] += $result['strings'];
				}
			}
		}

		$pending = $requeue->count_pending();
		?>
			<p>
				Styr hur specifika termer översätts, t.ex. <code>räka = prawn</code>. Ordlistan följs av både DeepL och Claude.
				När du sparar översätts alla sidor och texter som innehåller ändrade termer om automatiskt. Handrättade texter rörs inte.
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p>
					Sparat.
					<?php if ( $queued['posts'] + $queued['strings'] > 0 ) : ?>
						<?php echo esc_html( sprintf( '%d sidor och %d texter översätts om.', $queued['posts'], $queued['strings'] ) ); ?>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php foreach ( $errors as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>

			<div id="cotranslate-glossary-progress"
				data-pending="<?php echo esc_attr( $saved ? $queued['posts'] + $queued['strings'] : 0 ); ?>"
				style="<?php echo ( $saved && $queued['posts'] + $queued['strings'] > 0 ) ? '' : 'display:none;'; ?>">
				<div class="cotranslate-usage-bar"><div class="cotranslate-usage-fill" id="cotranslate-glossary-bar" style="width:30%"></div></div>
				<p id="cotranslate-glossary-progress-text">Startar omöversättning...</p>
			</div>

			<?php if ( ! $saved && $pending['posts'] + $pending['strings'] > 0 ) : ?>
				<div class="notice notice-info"><p>
					<?php echo esc_html( sprintf( '%d sidor och %d texter väntar på översättning och körs i bakgrunden.', $pending['posts'], $pending['strings'] ) ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=glossary' ) ); ?>">
				<?php wp_nonce_field( 'cotranslate_save_glossary', 'cotranslate_glossary_nonce' ); ?>

				<?php
				foreach ( $enabled_languages as $lang ) :
					if ( $lang === $default_language ) {
						continue;
					}
					$data      = $supported[ $lang ] ?? array( 'native' => $lang, 'flag' => '' );
					$label     = trim( ( $data['flag'] ?? '' ) . ' ' . ( $data['native'] ?? $lang ) );
					$status    = CoTranslate_DeepL_Glossary::get_status( $lang );
					$entries   = CoTranslate_Glossary::get_entries( $lang );
					$conflicts = $requeue->find_manual_conflicts( $lang, array_keys( $entries ) );
					?>
					<div class="cotranslate-glossary-lang">
						<h2><?php echo esc_html( $label ); ?></h2>
						<textarea name="cotranslate_gl[<?php echo esc_attr( $lang ); ?>]" rows="8" class="large-text code"
							placeholder="räka = prawn&#10;räkor = prawns&#10;torskrygg = cod loin"><?php echo esc_textarea( CoTranslate_Glossary::get_raw( $lang ) ); ?></textarea>
						<p class="description">
							En term per rad i formen <code>källterm = målterm</code>. Går även att klistra in två kolumner direkt från Excel.
							DeepL matchar hela ord, så böjningar behöver egna rader (räka, räkor, räkan). Claude klarar böjningar själv.
							Ord under fliken Skyddade ord nås aldrig av ordlistan.
						</p>

						<?php if ( $engine_is_deepl ) : ?>
							<p class="cotranslate-glossary-status">
								<?php if ( '' !== $status['error'] ) : ?>
									<span class="cotranslate-error">DeepL: <?php echo esc_html( $status['error'] ); ?></span>
								<?php elseif ( '' !== $status['id'] ) : ?>
									<span class="cotranslate-success">Synkad med DeepL <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $status['synced_at'] ) ); ?></span>
								<?php elseif ( ! empty( $entries ) ) : ?>
									<span class="cotranslate-error">Inte synkad med DeepL — spara för att synka.</span>
								<?php else : ?>
									<span class="description">Ingen ordlista för det här språket.</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $conflicts['posts'] ) || ! empty( $conflicts['strings'] ) ) : ?>
							<div class="cotranslate-glossary-conflicts">
								<strong>Handrättade texter som innehåller ord ur ordlistan</strong>
								<p class="description">Dessa rörs inte automatiskt. Släpp rättningen om ordlistan ska gälla även här.</p>
								<ul>
									<?php foreach ( $conflicts['posts'] as $row ) : ?>
										<li>
											<span class="cotranslate-conflict-text" title="<?php echo esc_attr( $row['title'] ); ?>">Sida: <?php echo esc_html( $row['title'] ); ?></span>
											<button type="button" class="button button-small cotranslate-glossary-release"
												data-kind="post" data-id="<?php echo esc_attr( $row['post_id'] ); ?>" data-language="<?php echo esc_attr( $lang ); ?>">Släpp och översätt om</button>
										</li>
									<?php endforeach; ?>
									<?php foreach ( $conflicts['strings'] as $row ) : ?>
										<li>
											<span class="cotranslate-conflict-text" title="<?php echo esc_attr( $row['source_text'] . ' → ' . $row['translated_text'] ); ?>"><?php echo esc_html( $row['source_text'] ); ?> → <?php echo esc_html( $row['translated_text'] ); ?></span>
											<button type="button" class="button button-small cotranslate-glossary-release"
												data-kind="string" data-id="<?php echo esc_attr( $row['id'] ); ?>" data-language="<?php echo esc_attr( $lang ); ?>">Släpp och översätt om</button>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $engine_is_deepl ) : ?>
					<p>
						<label>
							<input type="checkbox" name="cotranslate_glossary_resync" value="1" />
							Skapa om alla ordlistor hos DeepL vid spar (använd om huvudspråket eller API-nyckeln bytts)
						</label>
					</p>
				<?php endif; ?>

				<p><button type="submit" class="button button-primary">Spara och översätt om berört innehåll</button></p>
			</form>
		<?php
	}

	/**
	 * Sektion "Kontext och stil" — branschkontext (båda motorerna) och
	 * stilinstruktion (bara Claude).
	 */
	private function render_context_section() {
		if ( isset( $_POST['cotranslate_context_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cotranslate_context_nonce'] ) ), 'cotranslate_save_context' ) ) {

			$context = isset( $_POST['cotranslate_context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cotranslate_context'] ) ) : '';
			update_option( CoTranslate_Glossary::OPTION_CONTEXT, $context );

			if ( current_user_can( 'manage_options' ) && isset( $_POST['cotranslate_claude_prompt'] ) ) {
				update_option( 'cotranslate_claude_prompt', sanitize_textarea_field( wp_unslash( $_POST['cotranslate_claude_prompt'] ) ) );
			}

			echo '<div class="notice notice-success"><p>Sparat. Gäller allt som översätts från och med nu. Redan översatt innehåll påverkas inte.</p></div>';
		}

		$context       = CoTranslate_Glossary::get_context();
		$claude_prompt = get_option( 'cotranslate_claude_prompt', '' );
		$engine        = CoTranslate_Translator_Factory::get_current_engine();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=context' ) ); ?>">
			<?php wp_nonce_field( 'cotranslate_save_context', 'cotranslate_context_nonce' ); ?>

			<h2>Kontext</h2>
			<p class="description">
				Beskriv kort verksamheten och vilken typ av text som översätts. Skickas med varje översättning
				och styr ordvalet även för ord som inte står i ordlistan. Gäller både DeepL och Claude. Kostar inga extra tecken hos DeepL.
			</p>
			<textarea name="cotranslate_context" rows="3" class="large-text"
				placeholder="T.ex. Text för ett svenskt fiskeri- och skaldjursföretag som säljer färsk och fryst fisk till grossister och restauranger. Använd handelns etablerade termer."><?php echo esc_textarea( $context ); ?></textarea>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2>Stil (bara Claude)</h2>
				<p class="description">
					Fritext som styr Claudes ton och stil, t.ex. "Använd en varm, personlig ton". Lämna tomt för neutral översättning.
					<?php if ( CoTranslate_Translator_Factory::ENGINE_CLAUDE !== $engine ) : ?>
						<strong>Används inte just nu</strong> — vald motor är DeepL.
					<?php endif; ?>
				</p>
				<textarea name="cotranslate_claude_prompt" rows="3" class="large-text"
					placeholder="T.ex. Använd en varm, personlig ton. Behåll tekniska termer på engelska."><?php echo esc_textarea( $claude_prompt ); ?></textarea>
			<?php endif; ?>

			<p><button type="submit" class="button button-primary">Spara</button></p>
		</form>
		<?php
	}

	/**
	 * Rendera sidan "Terminologi" med flikarna Ordlista, Skyddade ord och
	 * Kontext och stil. Fliken väljs via ?tab= så att formulär-POST landar rätt.
	 */
	public function render_terminology_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$tabs = array(
			'glossary'  => 'Ordlista',
			'protected' => 'Skyddade ord',
			'context'   => 'Kontext och stil',
		);
		$active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'glossary';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'glossary';
		}
		?>
		<div class="wrap cotranslate-admin">
			<h1>CoTranslate — Terminologi</h1>
			<p>Allt som styr <em>vilka ord</em> översättningen väljer. Ändringar gäller allt som översätts från och med nu; ordlistan översätter dessutom om berört innehåll direkt.</p>

			<nav class="cotranslate-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="cotranslate-tab cotranslate-tab-link <?php echo $slug === $active ? 'active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="cotranslate-tab-content active">
				<?php
				if ( 'protected' === $active ) {
					$this->render_no_translate_section();
				} elseif ( 'context' === $active ) {
					$this->render_context_section();
				} else {
					$this->render_glossary_section();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Kör en omgång av omöversättningskön (poster + strängar).
	 *
	 * Anropas i loop från både adminsidan och frontend-editorn, därför
	 * accepteras båda nonce-typerna och behörigheten är edit_posts.
	 */
	public function ajax_process_queue_now() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cotranslate_admin' ) && ! wp_verify_nonce( $nonce, 'cotranslate_frontend' ) ) {
			wp_send_json_error( 'Ogiltig säkerhetstoken.' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$requeue = new CoTranslate_Glossary_Requeue( $this->store );
		$before  = $requeue->count_pending();
		$after   = $requeue->process_batch();

		// Ingen framgång (kvot slut, API-fel) — stoppa loopen i stället för att snurra.
		if ( $after === $before && $after['posts'] + $after['strings'] > 0 ) {
			wp_send_json_error( 'Kön går inte framåt just nu (kvot slut eller API-fel).' );
		}

		if ( 0 === $after['posts'] + $after['strings'] ) {
			$plugin = CoTranslate_Plugin::get_instance();
			if ( $plugin->frontend_editor ) {
				$plugin->frontend_editor->purge_cache();
			}
		}

		wp_send_json_success( $after );
	}

	/**
	 * AJAX: Släpp en manuell rättning och köa för omöversättning.
	 */
	public function ajax_glossary_release() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$kind     = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'Id saknas.' );
		}

		$requeue = new CoTranslate_Glossary_Requeue( $this->store );

		if ( 'post' === $kind && '' !== $language ) {
			$ok = $requeue->release_post( $id, $language );
		} elseif ( 'string' === $kind ) {
			$ok = $requeue->release_string( $id );
		} else {
			wp_send_json_error( 'Ogiltig typ.' );
			return;
		}

		if ( ! $ok ) {
			wp_send_json_error( 'Kunde inte släppa rättningen.' );
		}

		wp_send_json_success();
	}

	/**
	 * Ladda admin CSS och JS.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'cotranslate' ) === false ) {
			return;
		}

		// WordPress inbyggda färgväljare (Iris)
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			'cotranslate-admin',
			COTRANSLATE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			COTRANSLATE_VERSION
		);

		wp_enqueue_script(
			'cotranslate-admin',
			COTRANSLATE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			COTRANSLATE_VERSION,
			true
		);

		wp_localize_script( 'cotranslate-admin', 'cotranslateAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cotranslate_admin' ),
		) );
	}

	/**
	 * Rendera startsidan "Översikt": hur det fungerar, status per språk,
	 * kö och bakgrundsjobb, kvot och kom igång-checklista.
	 */
	public function render_overview_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$is_admin          = current_user_can( 'manage_options' );
		$default_language  = cotranslate_get_default_language();
		$enabled_languages = cotranslate_get_enabled_languages();
		$supported         = cotranslate_get_supported_languages();
		$engine            = CoTranslate_Translator_Factory::get_current_engine();
		$engine_is_deepl   = CoTranslate_Translator_Factory::ENGINE_DEEPL === $engine;
		$requeue           = new CoTranslate_Glossary_Requeue( $this->store );
		$pending           = $requeue->count_pending();

		$post_stats   = array();
		$string_stats = array();
		foreach ( $this->store->get_post_stats() as $row ) {
			$post_stats[ $row->language ] = $row;
		}
		foreach ( $this->store->get_string_stats() as $row ) {
			$string_stats[ $row->language ] = $row;
		}

		$target_languages = array_values( array_diff( $enabled_languages, array( $default_language ) ) );

		// Kö och bakgrundsjobb
		global $wpdb;
		$t_trans   = $wpdb->prefix . 'cotranslate_translations';
		$t_strings = $wpdb->prefix . 'cotranslate_strings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_post_run = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$t_trans} WHERE status = 'auto'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_string_run = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$t_strings} WHERE translated_text <> '' AND is_manual = 0" );
		$last_run       = max( (string) $last_post_run, (string) $last_string_run );
		$cron_next      = wp_next_scheduled( 'cotranslate_process_queue' );
		$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$pending_total  = $pending['posts'] + $pending['strings'];

		// Kvot (DeepL, cachad 1 h)
		$usage = null;
		if ( $engine_is_deepl && ! empty( cotranslate_get_api_key() ) ) {
			$usage = $this->api->get_usage();
			if ( is_wp_error( $usage ) ) {
				$usage = null;
			}
		}

		// Kom igång
		$has_key         = $engine_is_deepl ? ! empty( cotranslate_get_api_key() ) : ! empty( get_option( 'cotranslate_claude_api_key', '' ) );
		$has_target      = ! empty( $target_languages );
		$default_enabled = in_array( $default_language, $enabled_languages, true );
		$has_content     = ! empty( $post_stats ) || ! empty( $string_stats );
		$setup_done      = $has_key && $has_target && $default_enabled && $has_content;
		$settings_url    = admin_url( 'admin.php?page=cotranslate-settings' );
		?>
		<div class="wrap cotranslate-admin cotranslate-overview">
			<h1>CoTranslate — Översikt</h1>

			<?php if ( ! $setup_done ) : ?>
				<div class="cotranslate-card cotranslate-setup">
					<h2>Kom igång</h2>
					<ul class="cotranslate-checklist">
						<li class="<?php echo $has_key ? 'done' : ''; ?>">
							API-nyckel sparad
							<?php if ( ! $has_key && $is_admin ) : ?> — <a href="<?php echo esc_url( $settings_url ); ?>">lägg till under Inställningar</a><?php endif; ?>
						</li>
						<li class="<?php echo $has_target ? 'done' : ''; ?>">
							Minst ett målspråk aktiverat
							<?php if ( ! $has_target && $is_admin ) : ?> — <a href="<?php echo esc_url( $settings_url ); ?>">välj språk</a><?php endif; ?>
						</li>
						<li class="<?php echo $default_enabled ? 'done' : ''; ?>">
							Standardspråket (<?php echo esc_html( $supported[ $default_language ]['native'] ?? $default_language ); ?>) är med bland aktiverade språk
							<?php if ( ! $default_enabled ) : ?> — annars visas inte språkväljaren<?php endif; ?>
						</li>
						<li class="<?php echo $has_content ? 'done' : ''; ?>">
							Innehållet är översatt en första gång
							<?php if ( ! $has_content && $is_admin && $has_key && $has_target ) : ?> — använd "Översätt hela sajten" nedan<?php endif; ?>
						</li>
					</ul>
				</div>
			<?php endif; ?>

			<details class="cotranslate-card cotranslate-howto" id="cotranslate-howto" open>
				<summary>Så fungerar det</summary>
				<ul>
					<li><strong>Sidor</strong> översätts som helhet när du publicerar eller uppdaterar dem.</li>
					<li><strong>Texter</strong> — menyer, knappar, och allt innehåll på sidor byggda med Beaver Builder eller annan sidbyggare — plockas upp första gången någon besöker sidan på ett annat språk, och översätts strax därefter.</li>
					<li><strong>Allt nytt hamnar i en kö</strong> som körs i bakgrunden varje minut. Tryck "Kör kön nu" för att slippa vänta.</li>
					<li><strong>Handrättade</strong> översättningar skrivs aldrig över automatiskt. Rätta direkt på sajten med Redigera-knappen, eller under Sidor och Texter.</li>
				</ul>
			</details>

			<div class="cotranslate-card cotranslate-queue">
				<h2>Kö och bakgrundsjobb</h2>
				<div class="cotranslate-queue-grid">
					<div>
						<span class="cotranslate-label">Kö</span>
						<?php if ( $pending_total > 0 ) : ?>
							<strong><?php echo esc_html( sprintf( '%d sidor och %d texter väntar.', $pending['posts'], $pending['strings'] ) ); ?></strong>
						<?php else : ?>
							<strong>Kön är tom.</strong>
						<?php endif; ?>
						<?php if ( $last_run ) : ?>
							<br /><span class="description">Kördes senast <?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $last_run ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<div>
						<span class="cotranslate-label">Bakgrundsjobb</span>
						<?php if ( $cron_next && ! $cron_disabled ) : ?>
							<span class="cotranslate-dot cotranslate-dot-ok"></span> <strong>Igång</strong>
							<br /><span class="description">Nästa körning <?php echo esc_html( wp_date( 'H:i', $cron_next ) ); ?></span>
						<?php elseif ( $cron_disabled ) : ?>
							<span class="cotranslate-dot cotranslate-dot-warn"></span> <strong>WP-Cron är avstängd</strong>
							<br /><span class="description">Servern måste anropa wp-cron.php själv, annars körs kön bara när du trycker "Kör kön nu".</span>
						<?php else : ?>
							<span class="cotranslate-dot cotranslate-dot-warn"></span> <strong>Inte schemalagt</strong>
							<br /><span class="description">Schemaläggs igen när du trycker "Kör kön nu".</span>
						<?php endif; ?>
					</div>
					<?php if ( $usage ) : ?>
						<?php $percent = round( $usage['character_count'] / max( $usage['character_limit'], 1 ) * 100, 1 ); ?>
						<div>
							<span class="cotranslate-label">DeepL-kvot</span>
							<div class="cotranslate-usage-bar" style="margin:4px 0;">
								<div class="cotranslate-usage-fill <?php echo $percent > 95 ? 'danger' : ( $percent > 80 ? 'warning' : '' ); ?>" style="width:<?php echo esc_attr( min( 100, $percent ) ); ?>%"></div>
							</div>
							<span class="description"><?php echo esc_html( number_format_i18n( $usage['character_count'] ) . ' / ' . number_format_i18n( $usage['character_limit'] ) . ' tecken (' . $percent . ' %)' ); ?></span>
							<?php if ( $percent >= 95 ) : ?>
								<br /><span class="cotranslate-error">Kön pausas vid 95 %.</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="cotranslate-queue-actions">
					<button type="button" class="button button-primary" id="cotranslate-run-queue" <?php disabled( ! $has_key ); ?>>Kör kön nu</button>
					<?php if ( $is_admin ) : ?>
						<button type="button" class="button" id="cotranslate-translate-site" <?php disabled( ! $has_key || ! $has_target ); ?>>Översätt hela sajten</button>
						<span class="description">Går igenom alla sidor och texter. Handrättat innehåll hoppas över.</span>
					<?php endif; ?>
				</div>
				<div id="cotranslate-queue-progress" style="display:none;">
					<div class="cotranslate-usage-bar"><div class="cotranslate-usage-fill" id="cotranslate-queue-bar" style="width:0%"></div></div>
					<p id="cotranslate-queue-text"></p>
				</div>
			</div>

			<h2>Status per språk</h2>
			<?php if ( empty( $target_languages ) ) : ?>
				<p>Inga målspråk aktiverade ännu.</p>
			<?php endif; ?>
			<div class="cotranslate-lang-grid">
				<?php
				foreach ( $target_languages as $lang ) :
					$data      = $supported[ $lang ] ?? array( 'native' => $lang, 'flag' => '' );
					$ps        = $post_stats[ $lang ] ?? null;
					$ss        = $string_stats[ $lang ] ?? null;
					$entries   = CoTranslate_Glossary::get_entries( $lang );
					$gl        = CoTranslate_DeepL_Glossary::get_status( $lang );
					$s_pending = 0;
					if ( $ss ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$s_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t_strings} WHERE language = %s AND translated_text = '' AND is_manual = 0", $lang ) );
					}
					$pages_url = admin_url( 'admin.php?page=cotranslate-translations&lang=' . $lang );
					$texts_url = admin_url( 'admin.php?page=cotranslate-strings&lang=' . $lang );
					?>
					<div class="cotranslate-card cotranslate-lang-card">
						<h3><?php echo esc_html( trim( ( $data['flag'] ?? '' ) . ' ' . ( $data['native'] ?? $lang ) ) ); ?></h3>
						<dl>
							<dt>Sidor</dt>
							<dd>
								<?php if ( $ps ) : ?>
									<a href="<?php echo esc_url( $pages_url . '&status=auto' ); ?>"><?php echo (int) $ps->auto_count; ?> översatta</a> ·
									<a href="<?php echo esc_url( $pages_url . '&status=pending' ); ?>"><?php echo (int) $ps->pending_count; ?> väntar</a> ·
									<a href="<?php echo esc_url( $pages_url . '&status=manual' ); ?>"><?php echo (int) $ps->manual_overrides; ?> handrättade</a>
								<?php else : ?>
									<span class="description">Inga ännu</span>
								<?php endif; ?>
							</dd>
							<dt>Texter</dt>
							<dd>
								<?php if ( $ss ) : ?>
									<a href="<?php echo esc_url( $texts_url . '&status=auto' ); ?>"><?php echo max( 0, (int) $ss->auto_count - $s_pending ); ?> översatta</a> ·
									<a href="<?php echo esc_url( $texts_url . '&status=untranslated' ); ?>"><?php echo (int) $s_pending; ?> väntar</a> ·
									<a href="<?php echo esc_url( $texts_url . '&status=manual' ); ?>"><?php echo (int) $ss->manual_count; ?> handrättade</a>
								<?php else : ?>
									<span class="description">Inga ännu</span>
								<?php endif; ?>
							</dd>
							<dt>Terminologi</dt>
							<dd>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=glossary' ) ); ?>"><?php echo count( $entries ); ?> termer i ordlistan</a>
								<?php if ( $engine_is_deepl && ! empty( $entries ) ) : ?>
									<?php if ( '' !== $gl['error'] ) : ?>
										· <span class="cotranslate-error">DeepL-synk misslyckades</span>
									<?php elseif ( '' !== $gl['id'] ) : ?>
										· <span class="description">synkad med DeepL <?php echo esc_html( wp_date( 'H:i', (int) $gl['synced_at'] ) ); ?></span>
									<?php else : ?>
										· <span class="cotranslate-error">inte synkad</span>
									<?php endif; ?>
								<?php endif; ?>
							</dd>
						</dl>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendera inställningssidan.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_engine     = CoTranslate_Translator_Factory::get_current_engine();
		$engines            = CoTranslate_Translator_Factory::get_available_engines();
		$api_key            = cotranslate_get_api_key();
		$has_api_key        = ! empty( $api_key );
		$claude_api_key     = get_option( 'cotranslate_claude_api_key', '' );
		$has_claude_key     = ! empty( $claude_api_key );
		$default_language   = cotranslate_get_default_language();
		$enabled_languages  = cotranslate_get_enabled_languages();
		$supported          = cotranslate_get_supported_languages();
		$post_types         = cotranslate_get_supported_post_types();
		$translate_slugs    = get_option( 'cotranslate_translate_slugs', false );
		$frontend_editor    = get_option( 'cotranslate_enable_frontend_editor', false );
		$floating_switcher  = get_option( 'cotranslate_show_floating_switcher', true );
		$floating_position  = get_option( 'cotranslate_floating_position', 'bottom-right' );
		$floating_style     = get_option( 'cotranslate_floating_style', 'dropdown' );
		$switcher_bg_color  = get_option( 'cotranslate_switcher_bg_color', '' );
		$switcher_text_color = get_option( 'cotranslate_switcher_text_color', '' );
		$auto_detect        = get_option( 'cotranslate_auto_detect_language', false );
		$domain_map         = get_option( 'cotranslate_domain_language_map', array() );
		$delete_on_uninstall = get_option( 'cotranslate_delete_data_on_uninstall', false );

		// Hämta alla tillgängliga post-typer
		$available_post_types = get_post_types( array( 'public' => true ), 'objects' );

		?>
		<div class="wrap cotranslate-admin">
			<h1>CoTranslate — Inställningar</h1>

			<div class="cotranslate-tabs">
				<button class="cotranslate-tab active" data-tab="settings">Inställningar</button>
				<button class="cotranslate-tab" data-tab="advanced">Avancerat</button>
				<button class="cotranslate-tab" data-tab="usage">Användning</button>
			</div>

			<!-- INSTÄLLNINGAR -->
			<div class="cotranslate-tab-content active" id="tab-settings">

				<h2>Översättningsmotor</h2>
				<table class="form-table">
					<tr>
						<th>Motor</th>
						<td>
							<select id="cotranslate-engine">
								<?php foreach ( $engines as $id => $engine_data ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current_engine, $id ); ?>>
										<?php echo esc_html( $engine_data['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="cotranslate-engine-desc">
								<?php echo esc_html( $engines[ $current_engine ]['description'] ?? '' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div id="cotranslate-deepl-settings" style="<?php echo 'deepl' !== $current_engine ? 'display:none;' : ''; ?>">
					<h2>DeepL API</h2>
					<table class="form-table">
						<tr>
							<th>API-nyckel</th>
							<td>
								<input type="password" id="cotranslate-api-key"
									value="<?php echo esc_attr( $has_api_key ? '••••••••' : '' ); ?>"
									class="regular-text" placeholder="Din DeepL API-nyckel" />
								<button type="button" class="button" id="cotranslate-test-api">Testa anslutning</button>
								<button type="button" class="button" id="cotranslate-save-api-key">Spara nyckel</button>
								<p class="description">
									Hämta en gratis API-nyckel på <a href="https://www.deepl.com/pro-api" target="_blank">deepl.com/pro-api</a>.
									Free-nycklar (slutar med :fx) ger 500 000 tecken/månad.
								</p>
								<div id="cotranslate-api-status"></div>
							</td>
						</tr>
					</table>
				</div>

				<div id="cotranslate-claude-settings" style="<?php echo 'claude' !== $current_engine ? 'display:none;' : ''; ?>">
					<h2>Claude API (Anthropic)</h2>
					<table class="form-table">
						<tr>
							<th>API-nyckel</th>
							<td>
								<input type="password" id="cotranslate-claude-key"
									value="<?php echo esc_attr( $has_claude_key ? '••••••••' : '' ); ?>"
									class="regular-text" placeholder="Din Anthropic API-nyckel (sk-ant-...)" />
								<button type="button" class="button" id="cotranslate-test-claude">Testa anslutning</button>
								<p class="description">
									Hämta en API-nyckel på <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>.
									Använder Claude Haiku (~$0.80/miljon tokens input).
								</p>
								<div id="cotranslate-claude-status"></div>
							</td>
						</tr>
						<tr>
							<th>Stil och terminologi</th>
							<td>
								<p class="description">
									Ton, stil, ordlista och skyddade ord ställs in under
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-terminology&tab=context' ) ); ?>">Terminologi</a>.
								</p>
							</td>
						</tr>
					</table>
				</div>

				<h2>Språk</h2>
				<table class="form-table">
					<tr>
						<th>Standardspråk (källspråk)</th>
						<td>
							<select id="cotranslate-default-language">
								<?php foreach ( $supported as $code => $data ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"
										<?php selected( $default_language, $code ); ?>>
										<?php echo esc_html( $data['flag'] . ' ' . $data['native'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th>Aktiverade språk</th>
						<td>
							<fieldset>
								<?php foreach ( $supported as $code => $data ) : ?>
									<label>
										<input type="checkbox" name="cotranslate_enabled_languages[]"
											value="<?php echo esc_attr( $code ); ?>"
											<?php checked( in_array( $code, $enabled_languages, true ) ); ?> />
										<?php echo esc_html( $data['flag'] . ' ' . $data['native'] ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2>Innehåll</h2>
				<table class="form-table">
					<tr>
						<th>Post-typer att översätta</th>
						<td>
							<fieldset>
								<?php foreach ( $available_post_types as $pt ) : ?>
									<label>
										<input type="checkbox" name="cotranslate_post_types[]"
											value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, $post_types, true ) ); ?> />
										<?php echo esc_html( $pt->labels->name ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th>Översätt URL-sluggar</th>
						<td>
							<label>
								<input type="checkbox" id="cotranslate-translate-slugs"
									<?php checked( $translate_slugs ); ?> />
								Översätt post-sluggar till målspråket
							</label>
						</td>
					</tr>
				</table>

				<h2>Visning</h2>
				<table class="form-table">
					<tr>
						<th>Flytande språkväljare</th>
						<td>
							<label>
								<input type="checkbox" id="cotranslate-floating-switcher"
									<?php checked( $floating_switcher ); ?> />
								Visa flytande språkväljare
							</label>
							<br /><br />
							<label for="cotranslate-floating-style" style="display:block;margin-bottom:4px;font-weight:600;">Stil</label>
							<select id="cotranslate-floating-style">
								<option value="dropdown" <?php selected( $floating_style, 'dropdown' ); ?>>Dropdown (pill)</option>
								<option value="compact" <?php selected( $floating_style, 'compact' ); ?>>Text (minimalistisk)</option>
								<option value="flags" <?php selected( $floating_style, 'flags' ); ?>>Flaggor</option>
							</select>
							<br /><br />
							<label for="cotranslate-floating-position" style="display:block;margin-bottom:4px;font-weight:600;">Position</label>
							<select id="cotranslate-floating-position">
								<option value="bottom-right" <?php selected( $floating_position, 'bottom-right' ); ?>>Nere till höger</option>
								<option value="bottom-left" <?php selected( $floating_position, 'bottom-left' ); ?>>Nere till vänster</option>
								<option value="top-right" <?php selected( $floating_position, 'top-right' ); ?>>Uppe till höger</option>
								<option value="top-left" <?php selected( $floating_position, 'top-left' ); ?>>Uppe till vänster</option>
							</select>
							<br /><br />
							<label for="cotranslate-bg-color" style="display:block;margin-bottom:4px;font-weight:600;">Bakgrundsfärg</label>
							<input type="text" id="cotranslate-bg-color" class="cotranslate-color-field"
								value="<?php echo esc_attr( $switcher_bg_color ); ?>" data-default-color="" />
							<br /><br />
							<label for="cotranslate-text-color" style="display:block;margin-bottom:4px;font-weight:600;">Textfärg</label>
							<input type="text" id="cotranslate-text-color" class="cotranslate-color-field"
								value="<?php echo esc_attr( $switcher_text_color ); ?>" data-default-color="" />
							<p class="description">Lämna tomt för att ärva sajtens färger (standard).</p>
						</td>
					</tr>
					<tr>
						<th>Frontend-editor</th>
						<td>
							<label>
								<input type="checkbox" id="cotranslate-frontend-editor"
									<?php checked( $frontend_editor ); ?> />
								Tillåt visuell redigering av översättningar på sajten
							</label>
							<p class="description">Kräver inloggad användare med redigeringsbehörighet.</p>
						</td>
					</tr>
					<tr>
						<th>Autodetektera språk</th>
						<td>
							<label>
								<input type="checkbox" id="cotranslate-auto-detect"
									<?php checked( $auto_detect ); ?> />
								Omdirigera nya besökare baserat på webbläsarspråk
							</label>
						</td>
					</tr>
				</table>

				<h2>Domänmappning</h2>
				<table class="form-table">
					<tr>
						<th>Koppla domäner till språk</th>
						<td>
							<div id="cotranslate-domain-map">
								<?php if ( ! empty( $domain_map ) ) : ?>
									<?php foreach ( $domain_map as $domain => $lang ) : ?>
										<div class="cotranslate-domain-row">
											<input type="text" class="cotranslate-domain" value="<?php echo esc_attr( $domain ); ?>" placeholder="exempel.no" />
											<select class="cotranslate-domain-lang">
												<?php foreach ( $supported as $code => $data ) : ?>
													<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>>
														<?php echo esc_html( $data['flag'] . ' ' . $data['native'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<button type="button" class="button cotranslate-remove-domain">Ta bort</button>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<button type="button" class="button" id="cotranslate-add-domain">Lägg till domän</button>
							<p class="description">Besökare på mappade domäner 301-omdirigeras till huvuddomänen med rätt språkprefix.</p>
						</td>
					</tr>
				</table>

				<h2>Avinstallation</h2>
				<table class="form-table">
					<tr>
						<th>Radera data</th>
						<td>
							<label>
								<input type="checkbox" id="cotranslate-delete-on-uninstall"
									<?php checked( $delete_on_uninstall ); ?> />
								Radera alla översättningar och inställningar vid avinstallation
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="button" class="button button-primary" id="cotranslate-save-settings">Spara inställningar</button>
				</p>
			</div>

			<!-- AVANCERAT -->
			<div class="cotranslate-tab-content" id="tab-advanced">
				<p class="description">
					Verktyg för att översätta innehåll finns på <a href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate' ) ); ?>">Översikt</a>
					("Kör kön nu" och "Översätt hela sajten"), som radknappar under Sidor, och som "Hämta texter från en sida" under Texter.
				</p>

				<h2>Exportera / Importera</h2>
				<table class="form-table">
					<tr>
						<th>Exportera</th>
						<td>
							<button type="button" class="button" id="cotranslate-export-posts">Exportera sidor (CSV)</button>
							<button type="button" class="button" id="cotranslate-export-strings">Exportera texter (CSV)</button>
						</td>
					</tr>
					<tr>
						<th>Importera</th>
						<td>
							<input type="file" id="cotranslate-import-file" accept=".csv" />
							<select id="cotranslate-import-type">
								<option value="posts">Sidor</option>
								<option value="strings">Texter</option>
							</select>
							<button type="button" class="button" id="cotranslate-import-btn">Importera CSV</button>
						</td>
					</tr>
				</table>

				<h2>Migrering</h2>
				<table class="form-table">
					<tr>
						<th>Importera från v2</th>
						<td>
							<button type="button" class="button" id="cotranslate-migrate-v2">
								Importera från Coscribe Translator v2
							</button>
							<p class="description">Importerar texter och handrättningar från Coscribe Translator v2 (om installerat).</p>
							<div id="cotranslate-migrate-status"></div>
						</td>
					</tr>
				</table>
			</div>

			<!-- ANVÄNDNING -->
			<div class="cotranslate-tab-content" id="tab-usage">
				<h2>DeepL API-användning</h2>
				<div id="cotranslate-usage">
					<button type="button" class="button" id="cotranslate-refresh-usage">Uppdatera</button>
					<div id="cotranslate-usage-data"></div>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Rendera översättningssidan.
	 */
	public function render_translations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$supported         = cotranslate_get_supported_languages();

		// Hämta filter
		$filter_lang = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_translations';

		// Bygg query
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filter_lang ) ) {
			$where[] = 'language = %s';
			$args[]  = $filter_lang;
		}
		if ( ! empty( $filter_status ) ) {
			$where[] = 'status = %s';
			$args[]  = $filter_status;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = 20;
		$offset    = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query_sql = "SELECT t.*, p.post_title as original_title, p.post_type
			FROM {$table} t
			LEFT JOIN {$wpdb->posts} p ON t.post_id = p.ID
			WHERE {$where_sql}
			ORDER BY t.updated_at DESC
			LIMIT %d OFFSET %d";

		$args_with_limit = array_merge( $args, array( $per_page, $offset ) );

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translations = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$args_with_limit ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$translations = $wpdb->get_results( $wpdb->prepare(
				str_replace( 'WHERE 1=1', 'WHERE 1=1', $query_sql ),
				$per_page,
				$offset
			) );
		}

		$total_pages = ceil( $total / $per_page );

		?>
		<div class="wrap cotranslate-admin">
			<h1>CoTranslate — Sidor</h1>
			<p class="description">
				Sidor, inlägg och produkter som översätts som helhet. Sidor byggda med sidbyggare får bara titeln här —
				innehållet ligger under <a href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-strings' ) ); ?>">Texter</a>.
			</p>

			<!-- Filter -->
			<div class="cotranslate-filters">
				<form method="get">
					<input type="hidden" name="page" value="cotranslate-translations" />
					<select name="lang">
						<option value="">Alla språk</option>
						<?php foreach ( $enabled_languages as $lang ) : ?>
							<?php if ( $lang === $default_language ) continue; ?>
							<?php $data = $supported[ $lang ] ?? array( 'native' => $lang ); ?>
							<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $filter_lang, $lang ); ?>>
								<?php echo esc_html( $data['native'] ?? $lang ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value="">Alla statusar</option>
						<option value="auto" <?php selected( $filter_status, 'auto' ); ?>>Översatt</option>
						<option value="pending" <?php selected( $filter_status, 'pending' ); ?>>Väntar</option>
						<option value="manual" <?php selected( $filter_status, 'manual' ); ?>>Handrättad</option>
					</select>
					<button type="submit" class="button">Filtrera</button>
				</form>
			</div>

			<!-- Tabell -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Sida</th>
						<th>Typ</th>
						<th>Språk</th>
						<th>Översatt titel</th>
						<th>Status</th>
						<th>Översätts via</th>
						<th>Uppdaterad</th>
						<th>Åtgärder</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $translations ) ) : ?>
						<tr>
							<td colspan="8">Inga sidor hittade.</td>
						</tr>
					<?php else : ?>
						<?php
						$status_labels = array( 'auto' => 'Översatt', 'pending' => 'Väntar', 'manual' => 'Handrättad' );
						foreach ( $translations as $t ) :
							$post_obj    = get_post( $t->post_id );
							$via_builder = $post_obj && CoTranslate_Post_Translator::has_page_builder_content( $post_obj->post_content );
							$type_obj    = $t->post_type ? get_post_type_object( $t->post_type ) : null;
							?>
							<tr data-id="<?php echo esc_attr( $t->id ); ?>">
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $t->post_id ) ); ?>">
										<?php echo esc_html( $t->original_title ?: '#' . $t->post_id ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $type_obj ? $type_obj->labels->singular_name : ( $t->post_type ?? '-' ) ); ?></td>
								<td>
									<?php
									$lang_data = $supported[ $t->language ] ?? null;
									echo esc_html( $lang_data ? $lang_data['flag'] . ' ' . $lang_data['native'] : $t->language );
									?>
								</td>
								<td><?php echo esc_html( mb_substr( $t->translated_title, 0, 60 ) ); ?></td>
								<td>
									<span class="cotranslate-status cotranslate-status-<?php echo esc_attr( $t->status ); ?>">
										<?php echo esc_html( $status_labels[ $t->status ] ?? $t->status ); ?>
									</span>
								</td>
								<td>
									<?php if ( $via_builder ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=cotranslate-strings&lang=' . $t->language . '&post_id=' . (int) $t->post_id ) ); ?>"
											title="Sidan är byggd med sidbyggare. Titeln översätts här, innehållet som texter.">Texter (sidbyggare)</a>
									<?php else : ?>
										Hela sidan
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $t->updated_at ); ?></td>
								<td>
									<button type="button" class="button button-small cotranslate-edit-translation"
										data-post-id="<?php echo esc_attr( $t->post_id ); ?>"
										data-language="<?php echo esc_attr( $t->language ); ?>">
										Redigera
									</button>
									<?php if ( (int) $t->is_manual ) : ?>
										<button type="button" class="button button-small cotranslate-reset-translation"
											data-post-id="<?php echo esc_attr( $t->post_id ); ?>"
											data-language="<?php echo esc_attr( $t->language ); ?>">
											Släpp handrättning
										</button>
									<?php else : ?>
										<button type="button" class="button button-small cotranslate-retranslate"
											data-post-id="<?php echo esc_attr( $t->post_id ); ?>"
											data-language="<?php echo esc_attr( $t->language ); ?>">
											Översätt om
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Paginering -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
						) ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Redigera-modal -->
		<div id="cotranslate-edit-modal" class="cotranslate-modal" style="display:none;">
			<div class="cotranslate-modal-content">
				<span class="cotranslate-modal-close">&times;</span>
				<h2>Redigera översättning</h2>
				<input type="hidden" id="edit-post-id" />
				<input type="hidden" id="edit-language" />
				<table class="form-table">
					<tr>
						<th>Titel</th>
						<td><input type="text" id="edit-title" class="large-text" /></td>
					</tr>
					<tr>
						<th>Innehåll</th>
						<td><textarea id="edit-content" rows="10" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th>Utdrag</th>
						<td><textarea id="edit-excerpt" rows="3" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th>Slug</th>
						<td><input type="text" id="edit-slug" class="regular-text" /></td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary" id="cotranslate-save-edit">
						Spara som handrättad
					</button>
					<button type="button" class="button cotranslate-modal-close-btn">Avbryt</button>
				</p>
				<div id="cotranslate-edit-status"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendera stränghanteringssidan.
	 */
	public function render_strings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$supported         = cotranslate_get_supported_languages();

		$filter_lang   = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
		$filter_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$filter_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter_post   = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filter_lang ) ) {
			$where[] = 'language = %s';
			$args[]  = $filter_lang;
		}
		if ( $filter_post > 0 ) {
			$where[] = 'first_seen_post_id = %d';
			$args[]  = $filter_post;
		}
		if ( 'manual' === $filter_status ) {
			$where[] = 'is_manual = 1';
		} elseif ( 'untranslated' === $filter_status ) {
			$where[] = "(translated_text = '' OR translated_text IS NULL)";
		} elseif ( 'auto' === $filter_status ) {
			$where[] = "is_manual = 0 AND translated_text != ''";
		}
		if ( ! empty( $filter_search ) ) {
			$where[] = '(source_text LIKE %s OR translated_text LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $filter_search ) . '%';
			$args[]  = '%' . $wpdb->esc_like( $filter_search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = 30;
		$offset    = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";

		$args_with_limit = array_merge( $args, array( $per_page, $offset ) );

		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total   = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$strings = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$args_with_limit ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total   = $wpdb->get_var( $count_sql );
			$strings = $wpdb->get_results( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT * FROM {$table} WHERE 1=1 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			) );
		}

		$total_pages = ceil( $total / $per_page );

		?>
		<div class="wrap cotranslate-admin">
			<h1>CoTranslate — Texter <span class="title-count">(<?php echo (int) $total; ?>)</span></h1>
			<p class="description">
				Menyer, knappar, formulärfält och allt innehåll på sidor byggda med sidbyggare. Texterna plockas upp
				första gången någon besöker sidan på ett annat språk och översätts strax därefter. Redigera för att handrätta.
			</p>

			<?php if ( $filter_post > 0 ) : ?>
				<div class="notice notice-info inline">
					<p>
						Visar texter som först hittades på
						<strong><?php echo esc_html( get_the_title( $filter_post ) ?: '#' . $filter_post ); ?></strong>.
						Texter som delas med andra sidor (t.ex. menyer) räknas till den sida där de sågs först.
						<a href="<?php echo esc_url( remove_query_arg( array( 'post_id', 'paged' ) ) ); ?>">Visa alla</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="cotranslate-filters">
				<form method="get">
					<input type="hidden" name="page" value="cotranslate-strings" />
					<?php if ( $filter_post > 0 ) : ?>
						<input type="hidden" name="post_id" value="<?php echo esc_attr( $filter_post ); ?>" />
					<?php endif; ?>
					<select name="lang">
						<option value="">Alla språk</option>
						<?php foreach ( $enabled_languages as $lang ) : ?>
							<?php if ( $lang === $default_language ) continue; ?>
							<?php $data = $supported[ $lang ] ?? array( 'native' => $lang ); ?>
							<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $filter_lang, $lang ); ?>>
								<?php echo esc_html( $data['native'] ?? $lang ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value="">Alla statusar</option>
						<option value="auto" <?php selected( $filter_status, 'auto' ); ?>>Översatt</option>
						<option value="untranslated" <?php selected( $filter_status, 'untranslated' ); ?>>Väntar</option>
						<option value="manual" <?php selected( $filter_status, 'manual' ); ?>>Handrättad</option>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="Sök text..." />
					<button type="submit" class="button">Filtrera</button>
				</form>
			</div>

			<div class="cotranslate-inline-tool">
				<label for="cotranslate-scan-url"><strong>Hämta texter från en sida</strong></label>
				<p class="description">Vill du inte vänta på första besöket? Ange sidans adress med språkprefix (t.ex. <code>/en/om-oss/</code>) så hämtas texterna och läggs i kön.</p>
				<input type="url" id="cotranslate-scan-url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' . ( $filter_lang ?: ( $enabled_languages[1] ?? 'en' ) ) . '/' ) ); ?>" />
				<button type="button" class="button" id="cotranslate-scan-page">Hämta texter</button>
				<span id="cotranslate-scan-status"></span>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:28%">Originaltext</th>
						<th>Språk</th>
						<th style="width:28%">Översättning</th>
						<th>Hittad på</th>
						<th>Status</th>
						<th>Åtgärder</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $strings ) ) : ?>
						<tr><td colspan="6">Inga texter hittade.</td></tr>
					<?php else : ?>
						<?php foreach ( $strings as $s ) : ?>
							<tr data-id="<?php echo esc_attr( $s->id ); ?>">
								<td><code style="word-break:break-all;font-size:12px;" title="Källa: <?php echo esc_attr( $s->context ); ?>"><?php echo esc_html( mb_substr( $s->source_text, 0, 80 ) ); ?></code></td>
								<td>
									<?php
									$lang_data = $supported[ $s->language ] ?? null;
									echo esc_html( $lang_data ? $lang_data['flag'] . ' ' . strtoupper( $s->language ) : $s->language );
									?>
								</td>
								<td>
									<?php if ( ! empty( $s->translated_text ) ) : ?>
										<code style="word-break:break-all;font-size:12px;"><?php echo esc_html( mb_substr( $s->translated_text, 0, 80 ) ); ?></code>
									<?php else : ?>
										<em style="color:#999;">Väntar på översättning</em>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $s->first_seen_post_id ) ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'post_id' => (int) $s->first_seen_post_id, 'paged' => false ) ) ); ?>" title="Visa alla texter från den här sidan">
											<?php echo esc_html( get_the_title( (int) $s->first_seen_post_id ) ?: '#' . (int) $s->first_seen_post_id ); ?>
										</a>
									<?php else : ?>
										<span class="description">Okänd sida</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( (int) $s->is_manual ) : ?>
										<span class="cotranslate-status cotranslate-status-manual">Handrättad</span>
									<?php elseif ( '' === (string) $s->translated_text ) : ?>
										<span class="cotranslate-status cotranslate-status-pending">Väntar</span>
									<?php else : ?>
										<span class="cotranslate-status cotranslate-status-auto">Översatt</span>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button button-small cotranslate-edit-string"
										data-id="<?php echo esc_attr( $s->id ); ?>"
										data-source="<?php echo esc_attr( $s->source_text ); ?>"
										data-translated="<?php echo esc_attr( $s->translated_text ); ?>"
										data-language="<?php echo esc_attr( $s->language ); ?>">
										Redigera
									</button>
									<button type="button" class="button button-small cotranslate-delete-string"
										data-id="<?php echo esc_attr( $s->id ); ?>">
										Ta bort
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
						) ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Redigera sträng-modal -->
		<div id="cotranslate-string-modal" class="cotranslate-modal" style="display:none;">
			<div class="cotranslate-modal-content">
				<span class="cotranslate-modal-close">&times;</span>
				<h2>Redigera text</h2>
				<input type="hidden" id="string-edit-id" />
				<input type="hidden" id="string-edit-source" />
				<input type="hidden" id="string-edit-language" />
				<table class="form-table">
					<tr>
						<th>Original</th>
						<td><div id="string-edit-original" class="cotranslate-editor-readonly" style="padding:10px;background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;"></div></td>
					</tr>
					<tr>
						<th>Översättning</th>
						<td><textarea id="string-edit-translation" rows="4" class="large-text"></textarea></td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary" id="cotranslate-save-string">Spara som handrättad</button>
					<button type="button" class="button cotranslate-modal-close">Avbryt</button>
				</p>
				<div id="cotranslate-string-edit-status"></div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX-HANDLERS
	// =========================================================================

	/**
	 * Testa DeepL API-anslutning.
	 */
	public function ajax_test_api() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$engine  = isset( $_POST['engine'] ) ? sanitize_key( $_POST['engine'] ) : '';
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		// Avgör vilken motor att testa
		if ( 'claude' === $engine ) {
			if ( empty( $api_key ) || strpos( $api_key, '••' ) !== false ) {
				$stored = get_option( 'cotranslate_claude_api_key', '' );
				$api_key = ! empty( $stored ) ? cotranslate_decrypt( $stored ) : '';
			}
			$test_api = new CoTranslate_Claude_API();
		} else {
			if ( empty( $api_key ) || strpos( $api_key, '••' ) !== false ) {
				$api_key = cotranslate_get_api_key();
			}
			$test_api = new CoTranslate_DeepL_API();
		}

		$result = $test_api->test_connection( $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Spara nyckeln om testet lyckades och det var en ny nyckel
		if ( isset( $_POST['save_key'] ) && $_POST['save_key'] === 'true' ) {
			$raw_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
			if ( ! empty( $raw_key ) && strpos( $raw_key, '••' ) === false ) {
				cotranslate_save_api_key( $raw_key );
			}
		}

		wp_send_json_success( array(
			'message'         => 'Anslutning lyckades!',
			'character_count' => $result['character_count'],
			'character_limit' => $result['character_limit'],
		) );
	}

	/**
	 * Spara inställningar.
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		// Översättningsmotor
		if ( isset( $_POST['engine'] ) ) {
			update_option( 'cotranslate_translation_engine', sanitize_key( $_POST['engine'] ) );
		}

		// DeepL API-nyckel
		if ( isset( $_POST['api_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
			if ( ! empty( $key ) && strpos( $key, '••' ) === false ) {
				// Ordlistor hos DeepL hör till kontot — glöm id:n vid nyckelbyte
				if ( $key !== cotranslate_get_api_key() ) {
					CoTranslate_DeepL_Glossary::clear_ids();
				}
				cotranslate_save_api_key( $key );
			}
		}

		// Claude API-nyckel
		if ( isset( $_POST['claude_api_key'] ) ) {
			$claude_key = sanitize_text_field( wp_unslash( $_POST['claude_api_key'] ) );
			if ( ! empty( $claude_key ) && strpos( $claude_key, '••' ) === false ) {
				update_option( 'cotranslate_claude_api_key', cotranslate_encrypt( $claude_key ) );
			}
		}

		// Claude-prompt
		if ( isset( $_POST['claude_prompt'] ) ) {
			update_option( 'cotranslate_claude_prompt', sanitize_textarea_field( wp_unslash( $_POST['claude_prompt'] ) ) );
		}

		// Språkinställningar
		if ( isset( $_POST['default_language'] ) ) {
			update_option( 'cotranslate_default_language', sanitize_key( $_POST['default_language'] ) );
		}

		if ( isset( $_POST['enabled_languages'] ) && is_array( $_POST['enabled_languages'] ) ) {
			$languages = array_map( 'sanitize_key', $_POST['enabled_languages'] );
			update_option( 'cotranslate_enabled_languages', $languages );
		}

		if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
			$types = array_map( 'sanitize_key', $_POST['post_types'] );
			update_option( 'cotranslate_supported_post_types', $types );
		}

		// Booleans
		update_option( 'cotranslate_translate_slugs', ! empty( $_POST['translate_slugs'] ) );
		update_option( 'cotranslate_enable_frontend_editor', ! empty( $_POST['frontend_editor'] ) );
		update_option( 'cotranslate_show_floating_switcher', ! empty( $_POST['floating_switcher'] ) );
		update_option( 'cotranslate_auto_detect_language', ! empty( $_POST['auto_detect'] ) );
		update_option( 'cotranslate_delete_data_on_uninstall', ! empty( $_POST['delete_on_uninstall'] ) );

		if ( isset( $_POST['floating_position'] ) ) {
			update_option( 'cotranslate_floating_position', sanitize_key( $_POST['floating_position'] ) );
		}

		if ( isset( $_POST['floating_style'] ) ) {
			// Tillåt bara de tre stödda stilarna
			$style = sanitize_key( $_POST['floating_style'] );
			if ( ! in_array( $style, array( 'dropdown', 'compact', 'flags' ), true ) ) {
				$style = 'dropdown';
			}
			update_option( 'cotranslate_floating_style', $style );
		}

		// Färger — tom sträng vid ogiltigt/tomt värde (= ärv sajtens färger)
		if ( isset( $_POST['switcher_bg_color'] ) ) {
			update_option( 'cotranslate_switcher_bg_color', cotranslate_sanitize_hex_color( wp_unslash( $_POST['switcher_bg_color'] ) ) );
		}

		if ( isset( $_POST['switcher_text_color'] ) ) {
			update_option( 'cotranslate_switcher_text_color', cotranslate_sanitize_hex_color( wp_unslash( $_POST['switcher_text_color'] ) ) );
		}

		// Domänmappning
		if ( isset( $_POST['domain_map'] ) && is_array( $_POST['domain_map'] ) ) {
			$map = array();
			foreach ( $_POST['domain_map'] as $entry ) {
				$domain = sanitize_text_field( $entry['domain'] ?? '' );
				$lang   = sanitize_key( $entry['language'] ?? '' );
				if ( ! empty( $domain ) && ! empty( $lang ) ) {
					$map[ $domain ] = $lang;
				}
			}
			update_option( 'cotranslate_domain_language_map', $map );
		}

		wp_send_json_success( 'Inställningar sparade.' );
	}

	/**
	 * Köa alla poster för översättning.
	 */
	public function ajax_translate_all() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$count = $this->post_translator->queue_all_posts();

		wp_send_json_success( array(
			'message' => sprintf( '%d poster köade för översättning.', $count ),
			'count'   => $count,
		) );
	}

	/**
	 * Översätt en enskild post.
	 */
	public function ajax_translate_post() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( empty( $post_id ) || empty( $language ) ) {
			wp_send_json_error( 'Post-ID och språk krävs.' );
		}

		$result = $this->post_translator->translate_post( $post_id, $language );

		if ( $result ) {
			wp_send_json_success( 'Post översatt.' );
		} else {
			wp_send_json_error( 'Kunde inte översätta posten (manuell override kan finnas).' );
		}
	}

	/**
	 * Uppdatera översättning manuellt.
	 */
	public function ajax_update_translation() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( empty( $post_id ) || empty( $language ) ) {
			wp_send_json_error( 'Post-ID och språk krävs.' );
		}

		$data = array(
			'title'   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'content' => isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '',
			'excerpt' => isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '',
			'slug'    => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
		);

		$result = $this->store->save_manual_post_translation( $post_id, $language, $data );

		if ( $result ) {
			wp_send_json_success( 'Översättning sparad som manuell override.' );
		} else {
			wp_send_json_error( 'Kunde inte spara översättningen.' );
		}
	}

	/**
	 * Återställ manuell översättning till auto.
	 */
	public function ajax_reset_translation() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		$this->store->reset_to_auto( $post_id, $language );

		// Trigga ny DeepL-översättning direkt
		$this->post_translator->translate_post( $post_id, $language );

		wp_send_json_success( 'Översättning återställd och ny DeepL-översättning utförd.' );
	}

	/**
	 * Radera översättning.
	 */
	public function ajax_delete_translation() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		$this->store->delete_post_translation( $post_id, $language );

		wp_send_json_success( 'Översättning raderad.' );
	}

	/**
	 * Hämta DeepL API-användning.
	 */
	public function ajax_get_usage() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		// Rensa cache för att hämta färsk data
		delete_transient( 'cotranslate_deepl_usage' );
		$result = $this->api->get_usage();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$percent = ( $result['character_count'] / max( $result['character_limit'], 1 ) ) * 100;

		wp_send_json_success( array(
			'character_count' => $result['character_count'],
			'character_limit' => $result['character_limit'],
			'percent'         => round( $percent, 1 ),
		) );
	}

	/**
	 * Bulk-översätt en batch av poster.
	 *
	 * Anropas upprepade gånger från admin JS med offset.
	 * Returnerar progress så att frontend kan visa progress bar.
	 */
	public function ajax_bulk_translate_batch() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = 3; // Poster per batch (håll lågt för att undvika timeout)

		$supported_types   = cotranslate_get_supported_post_types();
		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();

		// Hämta totalt antal poster
		$total = 0;
		foreach ( $supported_types as $type ) {
			$total += wp_count_posts( $type )->publish ?? 0;
		}

		// Hämta batch
		$posts = get_posts( array(
			'post_type'      => $supported_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );

		$translated = 0;
		$errors     = 0;

		foreach ( $posts as $post_id ) {
			foreach ( $enabled_languages as $language ) {
				if ( $language === $default_language ) {
					continue;
				}

				$result = $this->post_translator->translate_post( $post_id, $language );
				if ( $result ) {
					$translated++;
				} else {
					$errors++;
				}
			}
		}

		$new_offset = $offset + $batch_size;
		$done       = $new_offset >= $total || empty( $posts );

		wp_send_json_success( array(
			'offset'     => $new_offset,
			'total'      => $total,
			'translated' => $translated,
			'errors'     => $errors,
			'done'       => $done,
			'percent'    => $total > 0 ? round( min( $new_offset, $total ) / $total * 100, 1 ) : 100,
		) );
	}

	/**
	 * Exportera översättningar som CSV.
	 */
	public function ajax_export_translations() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		global $wpdb;

		$type = isset( $_POST['export_type'] ) ? sanitize_key( $_POST['export_type'] ) : 'posts';

		if ( 'strings' === $type ) {
			$table = $wpdb->prefix . 'cotranslate_strings';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( "SELECT source_text, language, translated_text, context, is_manual FROM {$table} ORDER BY language, source_text" );

			$csv_lines = array( 'source_text,language,translated_text,context,is_manual' );
			foreach ( $rows as $row ) {
				$csv_lines[] = sprintf(
					'"%s","%s","%s","%s",%d',
					str_replace( '"', '""', $row->source_text ),
					$row->language,
					str_replace( '"', '""', $row->translated_text ),
					$row->context,
					(int) $row->is_manual
				);
			}
		} else {
			$table = $wpdb->prefix . 'cotranslate_translations';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results(
				"SELECT t.post_id, p.post_title as original_title, t.language, t.translated_title,
						t.translated_content, t.translated_excerpt, t.translated_slug, t.is_manual, t.status
				 FROM {$table} t
				 LEFT JOIN {$wpdb->posts} p ON t.post_id = p.ID
				 ORDER BY t.language, t.post_id"
			);

			$csv_lines = array( 'post_id,original_title,language,translated_title,translated_excerpt,translated_slug,is_manual,status' );
			foreach ( $rows as $row ) {
				$csv_lines[] = sprintf(
					'%d,"%s","%s","%s","%s","%s",%d,"%s"',
					$row->post_id,
					str_replace( '"', '""', $row->original_title ?? '' ),
					$row->language,
					str_replace( '"', '""', $row->translated_title ?? '' ),
					str_replace( '"', '""', mb_substr( $row->translated_excerpt ?? '', 0, 200 ) ),
					$row->translated_slug ?? '',
					(int) $row->is_manual,
					$row->status
				);
			}
		}

		wp_send_json_success( array(
			'csv'      => implode( "\n", $csv_lines ),
			'filename' => 'cotranslate-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv',
		) );
	}

	/**
	 * Importera översättningar från CSV.
	 */
	public function ajax_import_translations() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$csv_data = isset( $_POST['csv_data'] ) ? wp_unslash( $_POST['csv_data'] ) : '';
		$type     = isset( $_POST['import_type'] ) ? sanitize_key( $_POST['import_type'] ) : 'posts';

		if ( empty( $csv_data ) ) {
			wp_send_json_error( 'Ingen CSV-data.' );
		}

		$lines    = explode( "\n", $csv_data );
		$header   = array_shift( $lines ); // Ta bort header-rad
		$imported = 0;
		$skipped  = 0;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$fields = str_getcsv( $line );

			if ( 'strings' === $type && count( $fields ) >= 4 ) {
				$source_text     = $fields[0];
				$language        = sanitize_key( $fields[1] );
				$translated_text = $fields[2];
				$context         = sanitize_key( $fields[3] ?? 'general' );
				$is_manual       = (int) ( $fields[4] ?? 0 );

				if ( $is_manual ) {
					$this->store->save_manual_string_translation( $source_text, $language, $translated_text, $context );
				} else {
					$this->store->save_string_translation( $source_text, $language, $translated_text, $context );
				}
				$imported++;

			} elseif ( 'posts' === $type && count( $fields ) >= 4 ) {
				$post_id    = absint( $fields[0] );
				$language   = sanitize_key( $fields[2] );
				$title      = $fields[3] ?? '';
				$excerpt    = $fields[4] ?? '';
				$slug       = $fields[5] ?? '';
				$is_manual  = (int) ( $fields[6] ?? 0 );

				if ( ! $post_id || ! $language ) {
					$skipped++;
					continue;
				}

				$data = array(
					'title'   => sanitize_text_field( $title ),
					'content' => '', // Innehåll importeras inte via CSV (för stort)
					'excerpt' => sanitize_textarea_field( $excerpt ),
					'slug'    => sanitize_title( $slug ),
				);

				if ( $is_manual ) {
					$this->store->save_manual_post_translation( $post_id, $language, $data );
				} else {
					$this->store->save_post_translation( $post_id, $language, $data );
				}
				$imported++;

			} else {
				$skipped++;
			}
		}

		wp_send_json_success( array(
			'message'  => sprintf( '%d översättningar importerade, %d hoppade över.', $imported, $skipped ),
			'imported' => $imported,
			'skipped'  => $skipped,
		) );
	}

	/**
	 * Migrera från Coscribe Translator v2.
	 */
	public function ajax_migrate_v2() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		global $wpdb;

		// Kontrollera om v2-tabellen finns
		$v2_table = $wpdb->prefix . 'coscribe_translations';
		$exists   = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$v2_table
		) );

		if ( ! $exists ) {
			wp_send_json_error( 'Coscribe Translator v2 hittades inte (tabellen ' . $v2_table . ' saknas).' );
		}

		$migrated_strings = 0;
		$migrated_custom  = 0;

		// 1. Migrera cachade strängar från coscribe_translations
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$v2_rows = $wpdb->get_results(
			"SELECT source_text, source_lang, target_lang, translated_text FROM {$v2_table}
			 WHERE source_text NOT LIKE 'slug_%'
			 ORDER BY updated_at DESC"
		);

		foreach ( $v2_rows as $row ) {
			if ( empty( $row->source_text ) || empty( $row->translated_text ) || empty( $row->target_lang ) ) {
				continue;
			}

			$this->store->save_string_translation(
				$row->source_text,
				$row->target_lang,
				$row->translated_text,
				'migrated_v2'
			);
			$migrated_strings++;
		}

		// 2. Migrera custom translations (manuella overrides)
		$custom = get_option( 'coscribe_custom_translations', array() );
		if ( ! empty( $custom ) && is_array( $custom ) ) {
			foreach ( $custom as $entry ) {
				$source = $entry['source'] ?? $entry['find'] ?? '';
				$target = $entry['target'] ?? $entry['replace'] ?? '';
				$lang   = $entry['language'] ?? $entry['lang'] ?? '';

				if ( empty( $source ) || empty( $target ) ) {
					continue;
				}

				// Om inget specifikt språk, applicera på alla aktiverade
				$languages = ! empty( $lang )
					? array( $lang )
					: cotranslate_get_enabled_languages();

				$default = cotranslate_get_default_language();

				foreach ( $languages as $language ) {
					if ( $language === $default ) {
						continue;
					}

					$this->store->save_manual_string_translation(
						$source,
						$language,
						$target,
						'migrated_v2_custom'
					);
					$migrated_custom++;
				}
			}
		}

		wp_send_json_success( array(
			'message'          => sprintf(
				'Migrering klar! %d strängar och %d manuella overrides importerade.',
				$migrated_strings,
				$migrated_custom
			),
			'migrated_strings' => $migrated_strings,
			'migrated_custom'  => $migrated_custom,
		) );
	}

	/**
	 * Uppdatera en sträng manuellt.
	 */
	public function ajax_update_string() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$source_text     = isset( $_POST['source_text'] ) ? wp_unslash( $_POST['source_text'] ) : '';
		$translated_text = isset( $_POST['translated_text'] ) ? sanitize_text_field( wp_unslash( $_POST['translated_text'] ) ) : '';
		$language        = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';

		if ( empty( $source_text ) || empty( $language ) ) {
			wp_send_json_error( 'Källtext och språk krävs.' );
		}

		$result = $this->store->save_manual_string_translation(
			$source_text,
			$language,
			$translated_text,
			'manual_admin'
		);

		if ( $result ) {
			wp_send_json_success( 'Sträng sparad som manuell override.' );
		} else {
			wp_send_json_error( 'Kunde inte spara strängen.' );
		}
	}

	/**
	 * Radera en sträng.
	 */
	public function ajax_delete_string() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$id = isset( $_POST['string_id'] ) ? absint( $_POST['string_id'] ) : 0;

		if ( empty( $id ) ) {
			wp_send_json_error( 'ID saknas.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $id ) );

		wp_send_json_success( 'Sträng raderad.' );
	}

	/**
	 * Översätt alla köade strängar direkt (utan att vänta på WP-Cron).
	 */
	public function ajax_process_strings() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$translated        = 0;
		$errors            = 0;
		$last_error        = '';

		foreach ( $enabled_languages as $language ) {
			if ( $language === $default_language ) {
				continue;
			}

			// Hämta alla strängar som saknar översättning
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_text FROM {$table}
				 WHERE language = %s AND (translated_text = '' OR translated_text IS NULL) AND is_manual = 0
				 ORDER BY id ASC",
				$language
			) );

			if ( empty( $pending ) ) {
				continue;
			}

			// Översätt i batchar om 50
			$chunks = array_chunk( $pending, 50 );

			foreach ( $chunks as $chunk ) {
				$texts = wp_list_pluck( $chunk, 'source_text' );

				$result = $this->api->translate_text( $texts, $default_language, $language );

				if ( is_wp_error( $result ) ) {
					$errors    += count( $chunk );
					$last_error = $result->get_error_message();
					continue;
				}

				foreach ( $result as $i => $translated_text ) {
					if ( isset( $texts[ $i ] ) && ! empty( $translated_text ) ) {
						$this->store->save_string_translation(
							$texts[ $i ],
							$language,
							$translated_text,
							'general'
						);
						$translated++;
					}
				}
			}
		}

		$message = sprintf( '%d strängar översatta, %d fel.', $translated, $errors );
		if ( $errors > 0 && '' !== $last_error ) {
			$message .= ' Senaste fel: ' . $last_error;
		}

		wp_send_json_success( array(
			'message'    => $message,
			'translated' => $translated,
			'errors'     => $errors,
			'last_error' => $last_error,
		) );
	}

	/**
	 * Skanna en sida och samla oöversatta strängar.
	 *
	 * Hämtar sidan via HTTP och extraherar synlig text som inte finns i strängtabellen.
	 */
	public function ajax_scan_page() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( 'Ange en URL att skanna.' );
		}

		// Hämta sidan
		$response = wp_remote_get( $url, array(
			'timeout'    => 30,
			'user-agent' => 'CoTranslate Scanner/3.0',
			'cookies'    => array(),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Kunde inte hämta sidan: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_send_json_error( 'Sidan returnerade HTTP ' . $code . '.' );
		}

		$html = wp_remote_retrieve_body( $response );

		// Detektera språk från URL
		$language = '';
		$enabled  = cotranslate_get_enabled_languages();
		$default  = cotranslate_get_default_language();

		foreach ( $enabled as $lang ) {
			if ( $lang === $default ) {
				continue;
			}
			if ( preg_match( '#/' . preg_quote( $lang, '#' ) . '(/|$)#', $url ) ) {
				$language = $lang;
				break;
			}
		}

		if ( empty( $language ) ) {
			$language = $enabled[0] !== $default ? $enabled[0] : ( $enabled[1] ?? '' );
		}

		if ( empty( $language ) ) {
			wp_send_json_error( 'Kunde inte avgöra målspråk från URL:en.' );
		}

		// Extrahera synlig text
		$strings = $this->extract_visible_text( $html );

		// Vilken sida var det? (för filtret "Hittad på" under Texter)
		$seen_post_id = (int) url_to_postid( preg_replace( '#/' . preg_quote( $language, '#' ) . '(/|$)#', '$1', $url, 1 ) );

		// Filtrera bort strängar som redan finns i databasen
		$new_count = 0;
		foreach ( $strings as $text ) {
			$existing = $this->store->get_string_translation( $text, $language );
			if ( null === $existing || '' === $existing ) {
				$this->store->save_string_translation( $text, $language, '', 'scanned', $seen_post_id );
				$new_count++;
			}
		}

		wp_send_json_success( array(
			'message'       => sprintf( '%d texter hittade, %d nya lagda i kön. De översätts inom en minut eller när du trycker "Kör kön nu" på Översikt.', count( $strings ), $new_count ),
			'total_found'   => count( $strings ),
			'new_queued'    => $new_count,
			'language'      => $language,
		) );
	}

	/**
	 * Extrahera synlig text från HTML.
	 *
	 * @param string $html HTML att analysera.
	 * @return array Array med unika textsträngar.
	 */
	private function extract_visible_text( $html ) {
		// Ta bort script, style, noscript, svg
		$clean = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', '', $html );

		// Ta bort HTML-kommentarer — samma skäl som i String Translator:
		// en kommentar med en litteral tagg i sig läcker annars in sitt `-->`
		// i ordlistan och förstör markupen vid ersättning.
		$clean = preg_replace( '#<!--.*?-->#s', '', $clean );

		// 1. Extrahera text mellan HTML-taggar
		preg_match_all( '#>([^<]+)<#', $clean, $matches );
		$candidates = ! empty( $matches[1] ) ? $matches[1] : array();

		// 2. Extrahera formulärattribut
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

		$strings = array();

		foreach ( $candidates as $raw_text ) {
			$text = trim( html_entity_decode( $raw_text, ENT_QUOTES, 'UTF-8' ) );

			if ( mb_strlen( $text ) < 2 || mb_strlen( $text ) > 500 ) {
				continue;
			}

			if ( preg_match( '/^[0-9\s\.\,\-\/\:\;\#\@\!\?\&\=\+\*\%\(\)\[\]]+$/', $text ) ) {
				continue;
			}

			if ( filter_var( $text, FILTER_VALIDATE_URL ) || filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}

			if ( preg_match( '/[\{\}]|function\s*\(|var\s+|const\s+|let\s+/', $text ) ) {
				continue;
			}

			if ( ! preg_match( '/\p{L}/u', $text ) ) {
				continue;
			}

			$strings[ $text ] = true;
		}

		return array_keys( $strings );
	}

	/**
	 * Skanna alla publicerade sidor och översätt strängar.
	 *
	 * Hämtar varje sida per språk, extraherar text, köar och översätter direkt.
	 * Batch-baserad med offset så att stora sajter inte timeout:ar.
	 */
	public function ajax_scan_all() {
		check_ajax_referer( 'cotranslate_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Otillräckliga behörigheter.' );
		}

		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$lang_index = isset( $_POST['lang_index'] ) ? absint( $_POST['lang_index'] ) : 0;

		$supported_types   = cotranslate_get_supported_post_types();
		$enabled_languages = cotranslate_get_enabled_languages();
		$default_language  = cotranslate_get_default_language();
		$home_url          = get_option( 'home' );

		// Filtrera bort standardspråk
		$target_languages = array_values( array_filter( $enabled_languages, function ( $lang ) use ( $default_language ) {
			return $lang !== $default_language;
		} ) );

		if ( empty( $target_languages ) ) {
			wp_send_json_error( 'Inga målspråk aktiverade.' );
		}

		// Aktuellt språk att skanna
		if ( $lang_index >= count( $target_languages ) ) {
			// Alla språk klara — kör strängöversättning
			$this->run_string_translation( $target_languages, $default_language );

			wp_send_json_success( array(
				'done'    => true,
				'message' => 'Alla sidor skannade och strängar översatta!',
			) );
			return;
		}

		$current_lang = $target_languages[ $lang_index ];

		// Hämta alla publicerade poster
		$posts = get_posts( array(
			'post_type'      => $supported_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		// Totalt antal poster
		$total = 0;
		foreach ( $supported_types as $type ) {
			$counts = wp_count_posts( $type );
			$total += $counts->publish ?? 0;
		}

		// Om inga fler poster för detta språk, gå vidare till nästa
		if ( empty( $posts ) ) {
			wp_send_json_success( array(
				'done'       => false,
				'offset'     => 0,
				'lang_index' => $lang_index + 1,
				'language'   => $target_languages[ $lang_index + 1 ] ?? '',
				'message'    => sprintf( 'Språk %s klar. Går vidare...', strtoupper( $current_lang ) ),
				'total'      => $total,
				'total_langs' => count( $target_languages ),
			) );
			return;
		}

		$post = $posts[0];

		// Hämta korrekt permalänk (i admin/AJAX-kontext filtreras inte URL:er)
		$permalink = get_permalink( $post->ID );

		// Säkerställ att permalänken inte redan har språkprefix
		$permalink = preg_replace( '#^(' . preg_quote( $home_url, '#' ) . ')/' . preg_quote( $current_lang, '#' ) . '(/|$)#', '$1$2', $permalink );

		// Lägg till språkprefix
		$lang_url = preg_replace( '#^(' . preg_quote( $home_url, '#' ) . ')(/|$)#', '$1/' . $current_lang . '$2', $permalink );

		// Hämta sidan
		$response = wp_remote_get( $lang_url, array(
			'timeout'    => 30,
			'user-agent' => 'CoTranslate Scanner/3.0',
		) );

		$new_strings = 0;

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$html    = wp_remote_retrieve_body( $response );
			$strings = $this->extract_visible_text( $html );

			foreach ( $strings as $text ) {
				$existing = $this->store->get_string_translation( $text, $current_lang );
				if ( null === $existing || '' === $existing ) {
					$this->store->save_string_translation( $text, $current_lang, '', 'scanned', $post->ID );
					$new_strings++;
				}
			}
		}

		$overall_progress = ( ( $lang_index * $total ) + $offset + 1 ) / ( count( $target_languages ) * max( $total, 1 ) ) * 100;

		wp_send_json_success( array(
			'done'        => false,
			'offset'      => $offset + 1,
			'lang_index'  => $lang_index,
			'language'    => $current_lang,
			'post_title'  => $post->post_title,
			'new_strings' => $new_strings,
			'percent'     => round( $overall_progress, 1 ),
			'total'       => $total,
			'total_langs' => count( $target_languages ),
			'message'     => sprintf(
				'%s: %s (%d nya strängar)',
				strtoupper( $current_lang ),
				$post->post_title,
				$new_strings
			),
		) );
	}

	/**
	 * Kör strängöversättning direkt (anropas i slutet av scan_all).
	 *
	 * @param array  $languages        Målspråk.
	 * @param string $default_language Standardspråk.
	 */
	private function run_string_translation( array $languages, $default_language ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		foreach ( $languages as $language ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_text FROM {$table}
				 WHERE language = %s AND (translated_text = '' OR translated_text IS NULL) AND is_manual = 0
				 ORDER BY id ASC",
				$language
			) );

			if ( empty( $pending ) ) {
				continue;
			}

			$chunks = array_chunk( $pending, 50 );

			foreach ( $chunks as $chunk ) {
				$texts  = wp_list_pluck( $chunk, 'source_text' );
				$result = $this->api->translate_text( $texts, $default_language, $language );

				if ( is_wp_error( $result ) ) {
					continue;
				}

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
}
