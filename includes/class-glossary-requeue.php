<?php
/**
 * Omöversättning av innehåll som berörs av en ändrad ordlista.
 *
 * Hittar sidor och strängar vars källtext innehåller ändrade termer och som
 * inte är manuellt rättade, och köar dem för ny översättning via pluginets
 * befintliga köer: poster får status 'pending', strängar får tom
 * translated_text. Manuella rättningar rörs aldrig, men kan listas och
 * släppas på begäran.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Glossary_Requeue {

	/**
	 * @var CoTranslate_Translation_Store
	 */
	private $store;

	public function __construct( CoTranslate_Translation_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Köa berört, icke-manuellt innehåll för omöversättning.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param array  $terms       Källtermer (svenska) som ändrats.
	 * @return array array( 'posts' => int, 'strings' => int ).
	 */
	public function requeue( $target_lang, array $terms ) {
		$terms = $this->clean_terms( $terms );
		if ( empty( $terms ) ) {
			return array( 'posts' => 0, 'strings' => 0 );
		}

		global $wpdb;
		$t_trans   = $wpdb->prefix . 'cotranslate_translations';
		$t_strings = $wpdb->prefix . 'cotranslate_strings';

		// --- Poster -----------------------------------------------------------
		$post_types = cotranslate_get_supported_post_types();
		$type_sql   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		list( $like_sql, $like_args ) = $this->build_like( array( 'p.post_title', 'p.post_content', 'p.post_excerpt' ), $terms );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$t_trans} t ON t.post_id = p.ID AND t.language = %s AND t.is_manual = 0
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$type_sql}) AND ({$like_sql})",
			array_merge( array( $target_lang ), $post_types, $like_args )
		) );

		foreach ( $post_ids as $post_id ) {
			$this->store->mark_pending( (int) $post_id, $target_lang );
		}

		// --- Strängar ---------------------------------------------------------
		list( $like_sql, $like_args ) = $this->build_like( array( 'source_text' ), $terms );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$string_count = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$t_strings} SET translated_text = ''
			 WHERE language = %s AND is_manual = 0 AND translated_text <> '' AND ({$like_sql})",
			array_merge( array( $target_lang ), $like_args )
		) );

		if ( $string_count > 0 ) {
			$this->store->clear_string_cache();
		}

		$this->schedule_background();

		return array(
			'posts'   => count( $post_ids ),
			'strings' => $string_count,
		);
	}

	/**
	 * Manuellt rättade texter som innehåller någon av termerna.
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @param array  $terms       Källtermer.
	 * @param int    $limit       Max antal per typ.
	 * @return array array( 'posts' => array( array( 'post_id', 'title' ) ), 'strings' => array( array( 'id', 'source_text', 'translated_text' ) ) ).
	 */
	public function find_manual_conflicts( $target_lang, array $terms, $limit = 50 ) {
		$terms = $this->clean_terms( $terms );
		if ( empty( $terms ) ) {
			return array( 'posts' => array(), 'strings' => array() );
		}

		global $wpdb;
		$t_trans   = $wpdb->prefix . 'cotranslate_translations';
		$t_strings = $wpdb->prefix . 'cotranslate_strings';

		list( $like_sql, $like_args ) = $this->build_like( array( 'p.post_title', 'p.post_content', 'p.post_excerpt' ), $terms );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS post_id, p.post_title AS title FROM {$wpdb->posts} p
			 INNER JOIN {$t_trans} t ON t.post_id = p.ID AND t.language = %s AND t.is_manual = 1
			 WHERE p.post_status = 'publish' AND ({$like_sql})
			 ORDER BY p.post_title ASC LIMIT %d",
			array_merge( array( $target_lang ), $like_args, array( $limit ) )
		), ARRAY_A );

		list( $like_sql, $like_args ) = $this->build_like( array( 'source_text' ), $terms );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$strings = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_text, translated_text FROM {$t_strings}
			 WHERE language = %s AND is_manual = 1 AND ({$like_sql})
			 ORDER BY source_text ASC LIMIT %d",
			array_merge( array( $target_lang ), $like_args, array( $limit ) )
		), ARRAY_A );

		return array(
			'posts'   => $posts ?: array(),
			'strings' => $strings ?: array(),
		);
	}

	/**
	 * Släpp en manuell post-rättning och köa för omöversättning.
	 */
	public function release_post( $post_id, $target_lang ) {
		$ok = $this->store->reset_to_auto( (int) $post_id, $target_lang );
		$this->schedule_background();
		return $ok;
	}

	/**
	 * Släpp en manuell sträng-rättning och köa för omöversättning.
	 */
	public function release_string( $string_id ) {
		global $wpdb;
		$t_strings = $wpdb->prefix . 'cotranslate_strings';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$t_strings} SET is_manual = 0, translated_text = '' WHERE id = %d",
			(int) $string_id
		) );

		$this->store->clear_string_cache();
		$this->schedule_background();

		return false !== $result;
	}

	/**
	 * Antal poster och strängar som väntar på översättning (alla språk).
	 *
	 * @return array array( 'posts' => int, 'strings' => int ).
	 */
	public function count_pending() {
		global $wpdb;
		$t_trans   = $wpdb->prefix . 'cotranslate_translations';
		$t_strings = $wpdb->prefix . 'cotranslate_strings';

		return array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_trans} WHERE status = 'pending' AND is_manual = 0" ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'strings' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_strings} WHERE translated_text = '' AND is_manual = 0" ),
		);
	}

	/**
	 * Kör en omgång av båda köerna i förgrunden (anropas från AJAX-loop).
	 *
	 * @return array Kvarvarande antal, se count_pending().
	 */
	public function process_batch() {
		$plugin = CoTranslate_Plugin::get_instance();

		if ( $plugin->post_translator ) {
			$plugin->post_translator->process_queue();
		}
		if ( $plugin->string_translator ) {
			$plugin->string_translator->process_string_queue();
		}

		return $this->count_pending();
	}

	/**
	 * Se till att bakgrundsjobben är schemalagda om ingen kör förgrundsloopen.
	 */
	public function schedule_background() {
		if ( ! wp_next_scheduled( 'cotranslate_translate_strings' ) ) {
			wp_schedule_single_event( time() + 30, 'cotranslate_translate_strings' );
		}
		if ( ! wp_next_scheduled( 'cotranslate_process_queue' ) ) {
			wp_schedule_event( time(), 'every_minute', 'cotranslate_process_queue' );
		}
	}

	// =========================================================================
	// PRIVATA HJÄLPARE
	// =========================================================================

	/**
	 * Trimma och ta bort tomma termer.
	 */
	private function clean_terms( array $terms ) {
		$clean = array();
		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			if ( '' !== $term ) {
				$clean[ $term ] = true;
			}
		}
		return array_keys( $clean );
	}

	/**
	 * Bygg ett OR-villkor med LIKE för varje kolumn × term.
	 *
	 * @return array array( string $sql, array $args ).
	 */
	private function build_like( array $columns, array $terms ) {
		global $wpdb;
		$parts = array();
		$args  = array();

		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			foreach ( $columns as $column ) {
				$parts[] = "{$column} LIKE %s";
				$args[]  = $like;
			}
		}

		return array( implode( ' OR ', $parts ), $args );
	}
}
