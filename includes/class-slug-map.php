<?php
/**
 * Översatta URL-sluggar: slår upp en sökväg åt båda håll.
 *
 * Sluggar har översatts och sparats sedan länge (kolumnen translated_slug),
 * men fram till 3.5.0 lästes de aldrig: länkarna pekade på /nb/products/ och
 * /nb/produkter/ gav 404. Den här klassen är läs-sidan.
 *
 * En sökväg översätts SEGMENT FÖR SEGMENT. Det gör att undersidor och
 * posttyper med fast bas (/careers/positions/<annons>/) fungerar utan
 * specialfall: varje segment som är en känd slug byts, övriga står kvar.
 *
 * Översatta sluggar kan krocka med termernas sluggar: sidan netherlands
 * heter nederland på norska, och det gör även avdelningen /avdeling/nederland/.
 * Ett sådant segment slås bara tillbaka där en sida kan stå, först i sökvägen
 * eller direkt under en annan sida. Under en taxonomibas (avdeling/) är det
 * termens egen slug och lämnas.
 *
 * Klassen rör inte databasen själv. Tabellen laddas av en anropbar funktion
 * som skickas in, så att logiken går att testa utan WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Slug_Map {

	/**
	 * Funktion som ger raderna för ett språk:
	 * array( array( 'original' => 'products', 'translated' => 'produkter' ), … )
	 *
	 * @var callable
	 */
	private $loader;

	/**
	 * Funktion som ger ALLA publicerade originalsluggar, oavsett om de har en
	 * översättning. Behövs för krockskyddet.
	 *
	 * @var callable
	 */
	private $originals_loader;

	/**
	 * Funktion som ger alla termsluggar. Behövs för termkrocken.
	 *
	 * @var callable|null
	 */
	private $terms_loader;

	/**
	 * Språk vars sluggar ska användas.
	 *
	 * @var string[]
	 */
	private $languages;

	/**
	 * Färdiga uppslag per språk: array( 'fram' => …, 'bak' => …,
	 * 'sidor' => postsluggar, 'termkrock' => översatta sluggar som också är termsluggar ).
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private $maps = array();

	/**
	 * @param callable $loader           Ger raderna för ett språk.
	 * @param callable $originals_loader Ger alla publicerade originalsluggar.
	 * @param string[] $languages        Språk där översatta sluggar är påslagna.
	 * @param callable $terms_loader     Ger alla termsluggar (valfri).
	 */
	public function __construct( callable $loader, callable $originals_loader, array $languages, ?callable $terms_loader = null ) {
		$this->loader           = $loader;
		$this->originals_loader = $originals_loader;
		$this->languages        = array_values( $languages );
		$this->terms_loader     = $terms_loader;
	}

	/**
	 * Om språket använder översatta sluggar.
	 */
	public function is_enabled_for( $language ) {
		return in_array( $language, $this->languages, true );
	}

	/**
	 * Originalsökväg → sökväg med översatta sluggar.
	 *
	 * @param string $path     Sökväg utan språkprefix, t.ex. "/products/".
	 * @param string $language Målspråk.
	 * @return string
	 */
	public function translate_path( $path, $language ) {
		return $this->map_path( $path, $language, 'fram' );
	}

	/**
	 * Sökväg med översatta sluggar → originalsökväg.
	 *
	 * @param string $path     Sökväg utan språkprefix, t.ex. "/produkter/".
	 * @param string $language Språket sökvägen är skriven på.
	 * @return string
	 */
	public function original_path( $path, $language ) {
		return $this->map_path( $path, $language, 'bak' );
	}

	/**
	 * Töm uppslagen — anropas när en översättning sparats.
	 */
	public function flush() {
		$this->maps = array();
	}

	// =========================================================================
	// PRIVATA METODER
	// =========================================================================

	/**
	 * Byt varje känt segment i en sökväg.
	 */
	private function map_path( $path, $language, $direction ) {
		if ( ! is_string( $path ) || '' === $path || ! $this->is_enabled_for( $language ) ) {
			return $path;
		}

		$map = $this->get_map( $language );

		if ( empty( $map[ $direction ] ) ) {
			return $path;
		}

		$segments = explode( '/', $path );
		$previous = null; // Föregående segment, som originalslug.

		foreach ( $segments as $i => $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$key = self::normalize( $segment );

			if ( isset( $map[ $direction ][ $key ] ) && ! $this->is_term_segment( $map, $direction, $key, $previous ) ) {
				$segments[ $i ] = $map[ $direction ][ $key ];
			}

			$previous = self::normalize( $segments[ $i ] );
		}

		return implode( '/', $segments );
	}

	/**
	 * Om ett segment på vägen tillbaka är en termslug och inte en sida.
	 *
	 * Gäller bara sluggar som både är en sidas översättning och en term.
	 * En sida står först eller under en annan sida; allt annat (taxonomibas,
	 * posttypsbas) betyder att segmentet är termens egen slug.
	 */
	private function is_term_segment( $map, $direction, $key, $previous ) {
		if ( 'bak' !== $direction || ! isset( $map['termkrock'][ $key ] ) ) {
			return false;
		}

		return null !== $previous && ! isset( $map['sidor'][ $previous ] );
	}

	/**
	 * Bygg uppslagen för ett språk, med krockskydd.
	 *
	 * Två slags krockar sorteras bort, och då används originalsluggen som
	 * förut — hellre en engelsk slug än en länk som leder fel:
	 *
	 *  1. Den översatta sluggen är identisk med en ANNAN posts originalslug.
	 *     Då skulle /nb/kontakt/ inte gå att skilja från en riktig sida som
	 *     heter "kontakt". Originalet vinner alltid.
	 *  2. Två poster har fått samma översatta slug. Då går det inte att veta
	 *     vilken som avses på vägen tillbaka.
	 */
	private function get_map( $language ) {
		if ( isset( $this->maps[ $language ] ) ) {
			return $this->maps[ $language ];
		}

		$rows      = (array) call_user_func( $this->loader, $language );
		$originals = array();

		foreach ( (array) call_user_func( $this->originals_loader ) as $slug ) {
			$originals[ self::normalize( $slug ) ] = true;
		}

		// Räkna hur många olika original som delar varje översatt slug.
		$claims = array();

		foreach ( $rows as $row ) {
			$original   = self::normalize( $row['original'] ?? '' );
			$translated = self::normalize( $row['translated'] ?? '' );

			if ( '' === $original || '' === $translated || $original === $translated ) {
				continue;
			}

			// Nycklarna är normaliserade; värdena behåller den sparade formen,
			// så att adresser skrivs ut procentkodade precis som WordPress gör.
			$claims[ $translated ][ $original ] = array(
				'original'   => (string) $row['original'],
				'translated' => (string) $row['translated'],
			);
		}

		$forward  = array();
		$backward = array();

		foreach ( $claims as $translated => $claimed_by ) {
			if ( count( $claimed_by ) > 1 ) {
				continue; // Krock 2: flera poster vill ha samma slug.
			}

			if ( isset( $originals[ $translated ] ) ) {
				continue; // Krock 1: sluggen är upptagen av en riktig sida.
			}

			$original = (string) key( $claimed_by );
			$raw      = current( $claimed_by );

			$forward[ $original ]    = $raw['translated'];
			$backward[ $translated ] = $raw['original'];
		}

		$term_clashes = array();

		if ( null !== $this->terms_loader ) {
			foreach ( (array) call_user_func( $this->terms_loader ) as $slug ) {
				$slug = self::normalize( $slug );

				if ( isset( $backward[ $slug ] ) ) {
					$term_clashes[ $slug ] = true;
				}
			}
		}

		$this->maps[ $language ] = array(
			'fram'      => $forward,
			'bak'       => $backward,
			'sidor'     => $originals,
			'termkrock' => $term_clashes,
		);

		return $this->maps[ $language ];
	}

	/**
	 * Gör en slug jämförbar: avkodad och i gemener.
	 *
	 * Webbläsare skickar procentkodning med versaler (%C3%B8) medan WordPress
	 * sparar gemener — utan detta skulle sluggar med å, ä, ö eller ø inte
	 * kännas igen på vägen in.
	 */
	private static function normalize( $slug ) {
		$slug = rawurldecode( (string) $slug );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $slug, 'UTF-8' ) : strtolower( $slug );
	}
}
