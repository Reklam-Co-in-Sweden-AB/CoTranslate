<?php
/**
 * Databaslager för all översättningsdata.
 *
 * DEN VIKTIGASTE KLASSEN — enda sanningskällan.
 * Alla översättningar (auto och manuella) hanteras här.
 * is_manual-flaggan skyddar manuella redigeringar från att skrivas över.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Translation_Store {

	/**
	 * Tabellnamn (med prefix).
	 */
	private $table_translations;
	private $table_strings;

	/**
	 * Statisk cache per request.
	 */
	private static $post_cache = array();
	private static $string_cache = array();
	private static $string_cache_loaded = array();

	public function __construct() {
		global $wpdb;
		$this->table_translations = $wpdb->prefix . 'cotranslate_translations';
		$this->table_strings      = $wpdb->prefix . 'cotranslate_strings';
	}

	// =========================================================================
	// POST-ÖVERSÄTTNINGAR
	// =========================================================================

	/**
	 * Spara auto-översättning av post.
	 *
	 * HOPPAR ÖVER om is_manual = 1 (skyddar manuella redigeringar).
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk.
	 * @param array  $data     Array med 'title', 'content', 'excerpt', 'slug', 'meta_desc'.
	 * @param int    $chars    Antal tecken som förbrukades i DeepL.
	 * @return bool True om raden uppdaterades, false om manuell eller fel.
	 */
	public function save_post_translation( $post_id, $language, array $data, $chars = 0 ) {
		global $wpdb;

		$title    = $data['title'] ?? '';
		$content  = $data['content'] ?? '';
		$excerpt  = $data['excerpt'] ?? '';
		$slug     = $data['slug'] ?? '';
		$meta_desc = $data['meta_desc'] ?? '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table_translations}
				(post_id, language, translated_title, translated_content, translated_excerpt,
				 translated_slug, translated_meta_desc, is_manual, status, deepl_chars_used)
			VALUES (%d, %s, %s, %s, %s, %s, %s, 0, 'auto', %d)
			ON DUPLICATE KEY UPDATE
				translated_title = IF(is_manual = 0, VALUES(translated_title), translated_title),
				translated_content = IF(is_manual = 0, VALUES(translated_content), translated_content),
				translated_excerpt = IF(is_manual = 0, VALUES(translated_excerpt), translated_excerpt),
				translated_slug = IF(is_manual = 0, VALUES(translated_slug), translated_slug),
				translated_meta_desc = IF(is_manual = 0, VALUES(translated_meta_desc), translated_meta_desc),
				status = IF(is_manual = 0, 'auto', status),
				deepl_chars_used = IF(is_manual = 0, VALUES(deepl_chars_used), deepl_chars_used)",
			$post_id,
			$language,
			$title,
			$content,
			$excerpt,
			$slug,
			$meta_desc,
			$chars
		);
		// phpcs:enable

		$result = $wpdb->query( $sql );

		// Rensa cache
		unset( self::$post_cache[ $post_id . '_' . $language ] );

		return false !== $result;
	}

	/**
	 * Spara manuell översättning av post (admin eller frontend-editor).
	 *
	 * Sätter ALLTID is_manual = 1. Skriver alltid över.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk.
	 * @param array  $data     Array med fält att uppdatera.
	 * @return bool True vid lyckad sparning.
	 */
	public function save_manual_post_translation( $post_id, $language, array $data ) {
		global $wpdb;

		$title     = $data['title'] ?? '';
		$content   = $data['content'] ?? '';
		$excerpt   = $data['excerpt'] ?? '';
		$slug      = $data['slug'] ?? '';
		$meta_desc = $data['meta_desc'] ?? '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table_translations}
				(post_id, language, translated_title, translated_content, translated_excerpt,
				 translated_slug, translated_meta_desc, is_manual, status)
			VALUES (%d, %s, %s, %s, %s, %s, %s, 1, 'manual')
			ON DUPLICATE KEY UPDATE
				translated_title = VALUES(translated_title),
				translated_content = VALUES(translated_content),
				translated_excerpt = VALUES(translated_excerpt),
				translated_slug = VALUES(translated_slug),
				translated_meta_desc = VALUES(translated_meta_desc),
				is_manual = 1,
				status = 'manual'",
			$post_id,
			$language,
			$title,
			$content,
			$excerpt,
			$slug,
			$meta_desc
		);
		// phpcs:enable

		$result = $wpdb->query( $sql );

		// Rensa cache
		unset( self::$post_cache[ $post_id . '_' . $language ] );

		return false !== $result;
	}

	/**
	 * Hämta översättning för en post.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk.
	 * @return object|null Objekt med översättningsdata eller null.
	 */
	public function get_post_translation( $post_id, $language ) {
		$cache_key = $post_id . '_' . $language;

		if ( isset( self::$post_cache[ $cache_key ] ) ) {
			return self::$post_cache[ $cache_key ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_translations} WHERE post_id = %d AND language = %s",
			$post_id,
			$language
		) );

		self::$post_cache[ $cache_key ] = $row;

		return $row;
	}

	/**
	 * Förladda översättningar för flera poster (undvik N+1 queries).
	 *
	 * @param array  $post_ids Array med post-ID:n.
	 * @param string $language Målspråk.
	 */
	public function preload_post_translations( array $post_ids, $language ) {
		if ( empty( $post_ids ) ) {
			return;
		}

		// Filtrera bort redan cachade
		$needed = array();
		foreach ( $post_ids as $post_id ) {
			$cache_key = $post_id . '_' . $language;
			if ( ! isset( self::$post_cache[ $cache_key ] ) ) {
				$needed[] = absint( $post_id );
			}
		}

		if ( empty( $needed ) ) {
			return;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $needed ), '%d' ) );
		$args         = array_merge( $needed, array( $language ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table_translations} WHERE post_id IN ({$placeholders}) AND language = %s",
			...$args
		) );

		// Cacha hämtade rader
		$found_ids = array();
		foreach ( $rows as $row ) {
			$cache_key                      = $row->post_id . '_' . $language;
			self::$post_cache[ $cache_key ] = $row;
			$found_ids[]                    = (int) $row->post_id;
		}

		// Markera poster utan översättning som null i cache (undvik nya queries)
		foreach ( $needed as $post_id ) {
			if ( ! in_array( $post_id, $found_ids, true ) ) {
				self::$post_cache[ $post_id . '_' . $language ] = null;
			}
		}
	}

	/**
	 * Markera post som "behöver ny översättning".
	 *
	 * Sätter status = 'pending' BARA om is_manual = 0.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk. Om tomt, alla språk.
	 * @return bool True vid lyckad uppdatering.
	 */
	public function mark_pending( $post_id, $language = '' ) {
		global $wpdb;

		if ( ! empty( $language ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table_translations}
				 SET status = 'pending'
				 WHERE post_id = %d AND language = %s AND is_manual = 0",
				$post_id,
				$language
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table_translations}
				 SET status = 'pending'
				 WHERE post_id = %d AND is_manual = 0",
				$post_id
			) );
		}

		// Rensa hela cachen för denna post
		foreach ( array_keys( self::$post_cache ) as $key ) {
			if ( strpos( $key, $post_id . '_' ) === 0 ) {
				unset( self::$post_cache[ $key ] );
			}
		}

		return false !== $result;
	}

	/**
	 * Återställ manuell översättning till automatisk.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk.
	 * @return bool True vid lyckad uppdatering.
	 */
	public function reset_to_auto( $post_id, $language ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table_translations}
			 SET is_manual = 0, status = 'pending'
			 WHERE post_id = %d AND language = %s",
			$post_id,
			$language
		) );

		unset( self::$post_cache[ $post_id . '_' . $language ] );

		return false !== $result;
	}

	/**
	 * Hämta poster som väntar på översättning.
	 *
	 * @param int $limit Max antal att hämta.
	 * @return array Array med rader.
	 */
	public function get_pending_translations( $limit = 5 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table_translations}
			 WHERE status = 'pending' AND is_manual = 0
			 ORDER BY updated_at ASC
			 LIMIT %d",
			$limit
		) );
	}

	/**
	 * Radera översättning.
	 *
	 * @param int    $post_id  Post-ID.
	 * @param string $language Målspråk. Om tomt, alla språk.
	 * @return bool True vid lyckad radering.
	 */
	public function delete_post_translation( $post_id, $language = '' ) {
		global $wpdb;

		if ( ! empty( $language ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_translations} WHERE post_id = %d AND language = %s",
				$post_id,
				$language
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_translations} WHERE post_id = %d",
				$post_id
			) );
		}

		// Rensa cache
		foreach ( array_keys( self::$post_cache ) as $key ) {
			if ( strpos( $key, $post_id . '_' ) === 0 ) {
				unset( self::$post_cache[ $key ] );
			}
		}

		return false !== $result;
	}

	/**
	 * Hämta statistik för post-översättningar.
	 *
	 * @return array Array med statistik per språk.
	 */
	public function get_post_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT language,
				COUNT(*) as total,
				SUM(CASE WHEN status = 'auto' THEN 1 ELSE 0 END) as auto_count,
				SUM(CASE WHEN status = 'manual' THEN 1 ELSE 0 END) as manual_count,
				SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
				SUM(CASE WHEN is_manual = 1 THEN 1 ELSE 0 END) as manual_overrides,
				SUM(deepl_chars_used) as total_chars
			FROM {$this->table_translations}
			GROUP BY language
			ORDER BY language"
		);
	}

	// =========================================================================
	// STRÄNG-ÖVERSÄTTNINGAR (tema, menyer, widgets)
	// =========================================================================

	/**
	 * Generera hash för källtext.
	 *
	 * @param string $text Källtext.
	 * @return string SHA-256 hash.
	 */
	private function hash_text( $text ) {
		return hash( 'sha256', trim( $text ) );
	}

	/**
	 * Spara auto-översättning av sträng.
	 *
	 * HOPPAR ÖVER om is_manual = 1.
	 *
	 * @param string $source_text    Källtext.
	 * @param string $language       Målspråk.
	 * @param string $translated_text Översatt text.
	 * @param string $context        Kontext (menu, widget, theme, woocommerce, general).
	 * @return bool True vid lyckad sparning.
	 */
	public function save_string_translation( $source_text, $language, $translated_text, $context = 'general' ) {
		global $wpdb;

		$hash = $this->hash_text( $source_text );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table_strings}
				(source_hash, source_text, language, translated_text, context, is_manual)
			VALUES (%s, %s, %s, %s, %s, 0)
			ON DUPLICATE KEY UPDATE
				translated_text = IF(is_manual = 0, VALUES(translated_text), translated_text),
				context = IF(is_manual = 0, VALUES(context), context)",
			$hash,
			$source_text,
			$language,
			$translated_text,
			$context
		);
		// phpcs:enable

		$result = $wpdb->query( $sql );

		// Uppdatera cache
		if ( isset( self::$string_cache[ $language ] ) ) {
			self::$string_cache[ $language ][ $hash ] = $translated_text;
		}

		return false !== $result;
	}

	/**
	 * Spara manuell översättning av sträng.
	 *
	 * Sätter ALLTID is_manual = 1.
	 *
	 * @param string $source_text     Källtext.
	 * @param string $language        Målspråk.
	 * @param string $translated_text Översatt text.
	 * @param string $context         Kontext.
	 * @return bool True vid lyckad sparning.
	 */
	public function save_manual_string_translation( $source_text, $language, $translated_text, $context = 'general' ) {
		global $wpdb;

		$hash = $this->hash_text( $source_text );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table_strings}
				(source_hash, source_text, language, translated_text, context, is_manual)
			VALUES (%s, %s, %s, %s, %s, 1)
			ON DUPLICATE KEY UPDATE
				translated_text = VALUES(translated_text),
				context = VALUES(context),
				is_manual = 1",
			$hash,
			$source_text,
			$language,
			$translated_text,
			$context
		);
		// phpcs:enable

		$result = $wpdb->query( $sql );

		// Uppdatera cache
		if ( isset( self::$string_cache[ $language ] ) ) {
			self::$string_cache[ $language ][ $hash ] = $translated_text;
		}

		return false !== $result;
	}

	/**
	 * Hämta översättning av en enskild sträng.
	 *
	 * @param string $source_text Källtext.
	 * @param string $language    Målspråk.
	 * @return string|null Översatt text eller null.
	 */
	public function get_string_translation( $source_text, $language ) {
		$hash = $this->hash_text( $source_text );

		// Kontrollera förladda cache först
		if ( isset( self::$string_cache[ $language ][ $hash ] ) ) {
			return self::$string_cache[ $language ][ $hash ];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$translated = $wpdb->get_var( $wpdb->prepare(
			"SELECT translated_text FROM {$this->table_strings}
			 WHERE source_hash = %s AND language = %s",
			$hash,
			$language
		) );

		return $translated;
	}

	/**
	 * Ladda ALLA strängar för ett språk till cache.
	 *
	 * Anropas en gång per request för att undvika N+1 queries i output buffer.
	 *
	 * @param string $language Målspråk.
	 * @return array Associativ array med hash => translated_text.
	 */
	public function load_all_strings( $language ) {
		if ( isset( self::$string_cache_loaded[ $language ] ) ) {
			return self::$string_cache[ $language ] ?? array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT source_hash, source_text, translated_text FROM {$this->table_strings} WHERE language = %s",
			$language
		) );

		self::$string_cache[ $language ] = array();
		$source_map                      = array();

		foreach ( $rows as $row ) {
			self::$string_cache[ $language ][ $row->source_hash ] = $row->translated_text;
			$source_map[ $row->source_text ]                      = $row->translated_text;
		}

		self::$string_cache_loaded[ $language ] = true;

		return $source_map;
	}

	/**
	 * Batch-hämta strängöversättningar.
	 *
	 * @param array  $source_texts Array med källtexter.
	 * @param string $language     Målspråk.
	 * @return array Associativ array med source_text => translated_text.
	 */
	public function get_string_translations_batch( array $source_texts, $language ) {
		$result = array();

		foreach ( $source_texts as $text ) {
			$hash = $this->hash_text( $text );

			if ( isset( self::$string_cache[ $language ][ $hash ] ) ) {
				$result[ $text ] = self::$string_cache[ $language ][ $hash ];
			}
		}

		return $result;
	}

	/**
	 * Radera alla strängar för ett språk.
	 *
	 * @param string $language Målspråk.
	 * @return int Antal raderade rader.
	 */
	public function clear_strings( $language = '' ) {
		global $wpdb;

		if ( ! empty( $language ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_strings} WHERE language = %s",
				$language
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "TRUNCATE TABLE {$this->table_strings}" );
		}

		self::$string_cache         = array();
		self::$string_cache_loaded  = array();

		return (int) $result;
	}

	/**
	 * Hämta statistik för strängöversättningar.
	 *
	 * @return array Array med statistik per språk.
	 */
	public function get_string_stats() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT language,
				COUNT(*) as total,
				SUM(CASE WHEN is_manual = 1 THEN 1 ELSE 0 END) as manual_count,
				SUM(CASE WHEN is_manual = 0 THEN 1 ELSE 0 END) as auto_count
			FROM {$this->table_strings}
			GROUP BY language
			ORDER BY language"
		);
	}
}
