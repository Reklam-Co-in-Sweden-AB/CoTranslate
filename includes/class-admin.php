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

	public function __construct( CoTranslate_DeepL_API $api, CoTranslate_Translation_Store $store, CoTranslate_Post_Translator $post_translator ) {
		$this->api             = $api;
		$this->store           = $store;
		$this->post_translator = $post_translator;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
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
	}

	/**
	 * Lägg till menypost i admin.
	 */
	public function add_admin_menu() {
		add_menu_page(
			'CoTranslate',
			'CoTranslate',
			'manage_options',
			'cotranslate',
			array( $this, 'render_settings_page' ),
			'dashicons-translation',
			80
		);

		add_submenu_page(
			'cotranslate',
			'Inställningar',
			'Inställningar',
			'manage_options',
			'cotranslate',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'cotranslate',
			'Översättningar',
			'Översättningar',
			'manage_options',
			'cotranslate-translations',
			array( $this, 'render_translations_page' )
		);

		add_submenu_page(
			'cotranslate',
			'Strängar',
			'Strängar',
			'manage_options',
			'cotranslate-strings',
			array( $this, 'render_strings_page' )
		);
	}

	/**
	 * Ladda admin CSS och JS.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'cotranslate' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'cotranslate-admin',
			COTRANSLATE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			COTRANSLATE_VERSION
		);

		wp_enqueue_script(
			'cotranslate-admin',
			COTRANSLATE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			COTRANSLATE_VERSION,
			true
		);

		wp_localize_script( 'cotranslate-admin', 'cotranslateAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cotranslate_admin' ),
		) );
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
		$claude_prompt      = get_option( 'cotranslate_claude_prompt', '' );
		$default_language   = cotranslate_get_default_language();
		$enabled_languages  = cotranslate_get_enabled_languages();
		$supported          = cotranslate_get_supported_languages();
		$post_types         = cotranslate_get_supported_post_types();
		$translate_slugs    = get_option( 'cotranslate_translate_slugs', false );
		$frontend_editor    = get_option( 'cotranslate_enable_frontend_editor', false );
		$floating_switcher  = get_option( 'cotranslate_show_floating_switcher', true );
		$floating_position  = get_option( 'cotranslate_floating_position', 'bottom-right' );
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
				<button class="cotranslate-tab" data-tab="tools">Verktyg</button>
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
							<th>Översättningsinstruktioner</th>
							<td>
								<textarea id="cotranslate-claude-prompt" rows="4" class="large-text"
									placeholder="T.ex. &quot;Använd en varm, personlig ton. Behåll tekniska termer på engelska.&quot;"
								><?php echo esc_textarea( $claude_prompt ); ?></textarea>
								<p class="description">
									Fritext-instruktioner som styr Claudes översättningsstil.
									Lämna tomt för neutral översättning.
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
							<select id="cotranslate-floating-position">
								<option value="bottom-right" <?php selected( $floating_position, 'bottom-right' ); ?>>Nere till höger</option>
								<option value="bottom-left" <?php selected( $floating_position, 'bottom-left' ); ?>>Nere till vänster</option>
								<option value="top-right" <?php selected( $floating_position, 'top-right' ); ?>>Uppe till höger</option>
								<option value="top-left" <?php selected( $floating_position, 'top-left' ); ?>>Uppe till vänster</option>
							</select>
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

			<!-- VERKTYG -->
			<div class="cotranslate-tab-content" id="tab-tools">
				<h2>Översättningsverktyg</h2>

				<table class="form-table">
					<tr>
						<th>Översätt allt innehåll</th>
						<td>
							<button type="button" class="button button-primary" id="cotranslate-translate-all">
								Köa alla poster för översättning
							</button>
							<p class="description">Köar alla publicerade poster, sidor och produkter för översättning via DeepL.</p>
							<div id="cotranslate-translate-all-status"></div>
						</td>
					</tr>
					<tr>
						<th>Översätt enskild post</th>
						<td>
							<input type="number" id="cotranslate-post-id" placeholder="Post-ID" class="small-text" />
							<select id="cotranslate-post-language">
								<?php
								foreach ( $enabled_languages as $lang ) :
									if ( $lang === $default_language ) {
										continue;
									}
									$data = $supported[ $lang ] ?? array( 'native' => $lang );
									?>
									<option value="<?php echo esc_attr( $lang ); ?>">
										<?php echo esc_html( $data['native'] ?? $lang ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button" id="cotranslate-translate-post">Översätt</button>
							<div id="cotranslate-translate-post-status"></div>
						</td>
					</tr>
				</table>

				<h2>Strängöversättning (tematext, menyer, knappar)</h2>
				<p class="description">
					Sidor med page builder (Uncode/WPBakery, Divi, Beaver Builder) översätts via strängtabellen.
					Skanna en sida för att samla alla strängar, sedan översätt dem med DeepL.
				</p>
				<table class="form-table">
					<tr>
						<th>Skanna alla sidor</th>
						<td>
							<button type="button" class="button button-primary" id="cotranslate-scan-all">
								Skanna och översätt alla sidor
							</button>
							<p class="description">Skannar alla publicerade sidor för varje aktiverat språk, samlar strängar och översätter dem via DeepL. Allt i ett steg.</p>
							<div id="cotranslate-scan-all-status"></div>
						</td>
					</tr>
					<tr>
						<th>Skanna enskild sida</th>
						<td>
							<input type="url" id="cotranslate-scan-url" class="regular-text"
								placeholder="https://trako.se/en/" />
							<button type="button" class="button" id="cotranslate-scan-page">Skanna</button>
							<p class="description">Ange en URL med språkprefix.</p>
							<div id="cotranslate-scan-status"></div>
						</td>
					</tr>
					<tr>
						<th>Översätt strängar</th>
						<td>
							<button type="button" class="button button-primary" id="cotranslate-process-strings">
								Översätt alla köade strängar nu
							</button>
							<p class="description">Översätter alla strängar som saknar översättning via DeepL. Kräver inte att du väntar på WP-Cron.</p>
							<div id="cotranslate-process-strings-status"></div>
						</td>
					</tr>
				</table>

				<h2>Exportera / Importera</h2>
				<table class="form-table">
					<tr>
						<th>Exportera</th>
						<td>
							<button type="button" class="button" id="cotranslate-export-posts">Exportera post-översättningar (CSV)</button>
							<button type="button" class="button" id="cotranslate-export-strings">Exportera strängar (CSV)</button>
						</td>
					</tr>
					<tr>
						<th>Importera</th>
						<td>
							<input type="file" id="cotranslate-import-file" accept=".csv" />
							<select id="cotranslate-import-type">
								<option value="posts">Post-översättningar</option>
								<option value="strings">Strängar</option>
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
							<p class="description">Importerar strängar och manuella overrides från Coscribe Translator v2 (om installerat).</p>
							<div id="cotranslate-migrate-status"></div>
						</td>
					</tr>
				</table>

				<h2>Statistik</h2>
				<div id="cotranslate-stats">
					<?php $this->render_stats(); ?>
				</div>
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
			<h1>CoTranslate — Översättningar</h1>

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
						<option value="auto" <?php selected( $filter_status, 'auto' ); ?>>Auto</option>
						<option value="manual" <?php selected( $filter_status, 'manual' ); ?>>Manuell</option>
						<option value="pending" <?php selected( $filter_status, 'pending' ); ?>>Väntar</option>
					</select>
					<button type="submit" class="button">Filtrera</button>
				</form>
			</div>

			<!-- Tabell -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Post</th>
						<th>Typ</th>
						<th>Språk</th>
						<th>Översatt titel</th>
						<th>Status</th>
						<th>Manuell</th>
						<th>Uppdaterad</th>
						<th>Åtgärder</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $translations ) ) : ?>
						<tr>
							<td colspan="8">Inga översättningar hittade.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $translations as $t ) : ?>
							<tr data-id="<?php echo esc_attr( $t->id ); ?>">
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $t->post_id ) ); ?>">
										<?php echo esc_html( $t->original_title ?: '#' . $t->post_id ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $t->post_type ?? '-' ); ?></td>
								<td>
									<?php
									$lang_data = $supported[ $t->language ] ?? null;
									echo esc_html( $lang_data ? $lang_data['flag'] . ' ' . $lang_data['native'] : $t->language );
									?>
								</td>
								<td><?php echo esc_html( mb_substr( $t->translated_title, 0, 60 ) ); ?></td>
								<td>
									<span class="cotranslate-status cotranslate-status-<?php echo esc_attr( $t->status ); ?>">
										<?php echo esc_html( $t->status ); ?>
									</span>
								</td>
								<td><?php echo (int) $t->is_manual ? 'Ja' : 'Nej'; ?></td>
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
											Återställ
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
						Spara (manuell override)
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
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		global $wpdb;
		$table = $wpdb->prefix . 'cotranslate_strings';

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filter_lang ) ) {
			$where[] = 'language = %s';
			$args[]  = $filter_lang;
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
			<h1>CoTranslate — Strängar <span class="title-count">(<?php echo (int) $total; ?>)</span></h1>
			<p class="description">Tema-strängar, menytexter, formulärfält och övrig text som inte tillhör en specifik post. Redigera för att skapa manuella overrides.</p>

			<div class="cotranslate-filters">
				<form method="get">
					<input type="hidden" name="page" value="cotranslate-strings" />
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
						<option value="">Alla</option>
						<option value="auto" <?php selected( $filter_status, 'auto' ); ?>>Auto-översatta</option>
						<option value="manual" <?php selected( $filter_status, 'manual' ); ?>>Manuella overrides</option>
						<option value="untranslated" <?php selected( $filter_status, 'untranslated' ); ?>>Ej översatta</option>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="Sök sträng..." />
					<button type="submit" class="button">Filtrera</button>
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:30%">Originaltext</th>
						<th>Språk</th>
						<th style="width:30%">Översättning</th>
						<th>Kontext</th>
						<th>Manuell</th>
						<th>Åtgärder</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $strings ) ) : ?>
						<tr><td colspan="6">Inga strängar hittade.</td></tr>
					<?php else : ?>
						<?php foreach ( $strings as $s ) : ?>
							<tr data-id="<?php echo esc_attr( $s->id ); ?>">
								<td><code style="word-break:break-all;font-size:12px;"><?php echo esc_html( mb_substr( $s->source_text, 0, 80 ) ); ?></code></td>
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
										<em style="color:#999;">Ej översatt</em>
									<?php endif; ?>
								</td>
								<td><span style="font-size:11px;color:#888;"><?php echo esc_html( $s->context ); ?></span></td>
								<td><?php echo (int) $s->is_manual ? '<strong>Ja</strong>' : 'Nej'; ?></td>
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
				<h2>Redigera sträng</h2>
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
					<button type="button" class="button button-primary" id="cotranslate-save-string">Spara (manuell override)</button>
					<button type="button" class="button cotranslate-modal-close">Avbryt</button>
				</p>
				<div id="cotranslate-string-edit-status"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendera statistik.
	 */
	private function render_stats() {
		$post_stats   = $this->store->get_post_stats();
		$string_stats = $this->store->get_string_stats();
		$supported    = cotranslate_get_supported_languages();

		if ( empty( $post_stats ) && empty( $string_stats ) ) {
			echo '<p>Inga översättningar ännu.</p>';
			return;
		}

		if ( ! empty( $post_stats ) ) {
			echo '<h3>Post-översättningar</h3>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr><th>Språk</th><th>Totalt</th><th>Auto</th><th>Manuell</th><th>Väntar</th><th>Tecken</th></tr></thead>';
			echo '<tbody>';
			foreach ( $post_stats as $stat ) {
				$lang_data = $supported[ $stat->language ] ?? null;
				$lang_name = $lang_data ? $lang_data['flag'] . ' ' . $lang_data['native'] : $stat->language;
				printf(
					'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
					esc_html( $lang_name ),
					(int) $stat->total,
					(int) $stat->auto_count,
					(int) $stat->manual_count,
					(int) $stat->pending_count,
					esc_html( number_format_i18n( (int) $stat->total_chars ) )
				);
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $string_stats ) ) {
			echo '<h3>Strängöversättningar</h3>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr><th>Språk</th><th>Totalt</th><th>Auto</th><th>Manuell</th></tr></thead>';
			echo '<tbody>';
			foreach ( $string_stats as $stat ) {
				$lang_data = $supported[ $stat->language ] ?? null;
				$lang_name = $lang_data ? $lang_data['flag'] . ' ' . $lang_data['native'] : $stat->language;
				printf(
					'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
					esc_html( $lang_name ),
					(int) $stat->total,
					(int) $stat->auto_count,
					(int) $stat->manual_count
				);
			}
			echo '</tbody></table>';
		}
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
					$errors += count( $chunk );
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

		wp_send_json_success( array(
			'message'    => sprintf( '%d strängar översatta, %d fel.', $translated, $errors ),
			'translated' => $translated,
			'errors'     => $errors,
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

		// Filtrera bort strängar som redan finns i databasen
		$new_count = 0;
		foreach ( $strings as $text ) {
			$existing = $this->store->get_string_translation( $text, $language );
			if ( null === $existing || '' === $existing ) {
				$this->store->save_string_translation( $text, $language, '', 'scanned' );
				$new_count++;
			}
		}

		wp_send_json_success( array(
			'message'       => sprintf( '%d strängar hittade, %d nya köade för översättning.', count( $strings ), $new_count ),
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
					$this->store->save_string_translation( $text, $current_lang, '', 'scanned' );
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
