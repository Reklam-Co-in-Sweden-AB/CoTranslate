# "Översätt inte"-lista Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Låta administratören ange ord/fraser som aldrig översätts (varumärken, produktnamn), per målspråk plus en delad "Alla språk"-lista.

**Architecture:** En wrapper-klass lindar translatorn som factoryn returnerar. Före varje översättning byts varje term mot en HTML-kommentar-platshållare (skiftlägesokänsligt, längsta fras först); efter svaret återställs originaltermen. Wrappern täcker både Claude och DeepL eftersom den sitter ovanför dem i factoryn.

**Tech Stack:** PHP 8.x, WordPress plugin-API. Inget PHPUnit finns — ren logik testas med fristående PHP-CLI-skript, WP-integration verifieras manuellt.

## Global Constraints

- Kommentarer och all användarvänd text på svenska, med korrekt å/ä/ö.
- Funktions-/variabelnamn på engelska; WordPress Coding Standards (tabs i PHP).
- Prefix `cotranslate_` / `CoTranslate_` på allt globalt.
- Sanera all input, escapa all output (`esc_html`, `esc_attr`, `esc_textarea`).
- Nonce på formulär; `current_user_can( 'manage_options' )` före spar.
- Håll ändringar minimala och fokuserade — rör inte motorklasserna internt.
- Optionsnyckel: `cotranslate_no_translate_terms` (array `lang|'_all' => "rad\nrad"`).
- Platshållarformat: `<!--COTRANSLATE_NT_{n}-->`.

---

### Task 1: Ren protect/restore-logik i wrapper-klassen

**Files:**
- Create: `includes/class-no-translate-wrapper.php`
- Test: `tests/test-no-translate.php`

**Interfaces:**
- Produces:
  - `CoTranslate_No_Translate_Wrapper::protect( string $text, array $terms ): array` — returnerar `array( string $protected_text, array $map )` där `$map` är `placeholder => originalterm`.
  - `CoTranslate_No_Translate_Wrapper::restore( string $text, array $map ): string`.

- [ ] **Step 1: Skriv det fallerande testet**

Skapa `tests/test-no-translate.php`:

```php
<?php
// Fristående CLI-test för ren protect/restore-logik (ingen WordPress).
define( 'ABSPATH', __DIR__ . '/' ); // tillåt att klassfilen laddas utanför WP
require __DIR__ . '/../includes/class-no-translate-wrapper.php';

$fails = 0;
function check( $label, $got, $expected ) {
	global $fails;
	if ( $got === $expected ) {
		echo "PASS: $label\n";
	} else {
		$fails++;
		echo "FAIL: $label\n  fick:      " . var_export( $got, true ) . "\n  förväntat: " . var_export( $expected, true ) . "\n";
	}
}

// Skiftlägesokänslig matchning, original bevaras vid restore.
list( $prot, $map ) = CoTranslate_No_Translate_Wrapper::protect( 'Köp en iphone hos Reklamco', array( 'iPhone', 'Reklamco' ) );
check( 'iphone ersatt med platshållare', strpos( $prot, 'iphone' ), false );
check( 'restore ger tillbaka original-träffen', CoTranslate_No_Translate_Wrapper::restore( $prot, $map ), 'Köp en iphone hos Reklamco' );

// Längsta fras först: "New York" får inte brytas av "York".
list( $prot2, $map2 ) = CoTranslate_No_Translate_Wrapper::protect( 'Visit New York today', array( 'York', 'New York' ) );
check( 'hela frasen New York skyddad', count( $map2 ), 1 );
check( 'restore New York', CoTranslate_No_Translate_Wrapper::restore( $prot2, $map2 ), 'Visit New York today' );

// Tom termlista → oförändrad text, tom karta.
list( $prot3, $map3 ) = CoTranslate_No_Translate_Wrapper::protect( 'Ingen ändring', array() );
check( 'tom lista lämnar texten orörd', $prot3, 'Ingen ändring' );
check( 'tom lista ger tom karta', $map3, array() );

echo $fails ? "\n$fails test misslyckades\n" : "\nAlla test gröna\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Kör testet och se att det fallerar**

Run: `php tests/test-no-translate.php`
Expected: FAIL — `Failed opening required '.../includes/class-no-translate-wrapper.php'` (filen finns inte än).

- [ ] **Step 3: Skapa klassfilen med minimal protect/restore**

Skapa `includes/class-no-translate-wrapper.php`:

```php
<?php
/**
 * Wrapper som skyddar ord/fraser från översättning.
 *
 * Lindar den verkliga översättningsmotorn. Före översättning byts varje
 * skyddad term mot en HTML-kommentar-platshållare; efter svaret återställs
 * originaltermen. Eftersom wrappern sitter ovanför både Claude och DeepL i
 * factoryn täcks båda motorerna automatiskt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_No_Translate_Wrapper {

	/**
	 * Ersätt skyddade termer med platshållare.
	 *
	 * @param string $text  Text som ska översättas.
	 * @param array  $terms Lista med termer (strängar).
	 * @return array array( string $protected_text, array $map ) där $map är
	 *               platshållare => originalterm.
	 */
	public static function protect( $text, array $terms ) {
		$map     = array();
		$counter = 0;

		// Trimma, ta bort tomma, deduplicera och sortera längsta först så att
		// en längre fras skyddas innan en kortare delsträng av den.
		$clean = array();
		foreach ( $terms as $term ) {
			$term = trim( $term );
			if ( '' !== $term ) {
				$clean[ $term ] = true;
			}
		}
		$clean = array_keys( $clean );
		usort( $clean, function ( $a, $b ) {
			return mb_strlen( $b ) - mb_strlen( $a );
		} );

		foreach ( $clean as $term ) {
			// Skiftlägesokänslig sökning utan regex (säkert mot specialtecken).
			$offset = 0;
			while ( false !== ( $pos = mb_stripos( $text, $term, $offset ) ) ) {
				$placeholder       = '<!--COTRANSLATE_NT_' . $counter . '-->';
				$match             = mb_substr( $text, $pos, mb_strlen( $term ) );
				$map[ $placeholder ] = $match;
				$text              = mb_substr( $text, 0, $pos ) . $placeholder . mb_substr( $text, $pos + mb_strlen( $term ) );
				$offset            = $pos + mb_strlen( $placeholder );
				$counter++;
			}
		}

		return array( $text, $map );
	}

	/**
	 * Återställ platshållare till originaltermer.
	 *
	 * @param string $text Text med platshållare.
	 * @param array  $map  platshållare => originalterm.
	 * @return string
	 */
	public static function restore( $text, array $map ) {
		if ( empty( $map ) ) {
			return $text;
		}
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}
}
```

- [ ] **Step 4: Kör testet och se att det blir grönt**

Run: `php tests/test-no-translate.php`
Expected: PASS — `Alla test gröna`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-no-translate-wrapper.php tests/test-no-translate.php
git commit -m "Lägger till protect/restore-logik för Översätt inte-lista"
```

---

### Task 2: WP-integration i wrappern + inkoppling i factoryn

**Files:**
- Modify: `includes/class-no-translate-wrapper.php`
- Modify: `includes/class-translator-factory.php:33-43`
- Modify: `includes/class-plugin.php` (laddning av ny klassfil, om autoload saknas)

**Interfaces:**
- Consumes: `CoTranslate_No_Translate_Wrapper::protect/restore` från Task 1.
- Produces:
  - `new CoTranslate_No_Translate_Wrapper( $inner )` — konstruktor tar inre translator.
  - `get_terms_for_language( string $target_lang ): array` — slår ihop `_all` + språkets termer från optionen.
  - `translate_text( array $texts, $source_lang, $target_lang )`, `translate_html( $html, $source_lang, $target_lang )`, `translate_slug( $slug, $source_lang, $target_lang )` — skyddar (ej slug) och vidarebefordrar.
  - `__call( $name, $args )` — vidarebefordrar alla övriga metoder (`get_usage`, `test_connection`, `get_supported_languages` m.fl.) till inre translatorn.

- [ ] **Step 1: Verifiera hur ny klassfil laddas**

Run: `grep -n "require\|include\|spl_autoload\|class-translator-factory" includes/class-plugin.php`
Expected: Hitta raden där `class-translator-factory.php` (och övriga klasser) laddas. Lägg `class-no-translate-wrapper.php` i samma laddningslista om ingen autoloader finns. Om en autoloader laddar `includes/*.php` automatiskt behövs inget tillägg.

- [ ] **Step 2: Lägg till instans-logik i wrappern**

Lägg till överst i klassen `CoTranslate_No_Translate_Wrapper` (efter klassdeklarationen, före `protect`):

```php
	/** @var object Inre översättningsmotor. */
	private $inner;

	/**
	 * @param object $inner Translator som implementerar motorernas interface.
	 */
	public function __construct( $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Hämta skyddade termer för ett målspråk (delade `_all` + språkets egna).
	 *
	 * @param string $target_lang Målspråkets kod.
	 * @return array Lista med termer.
	 */
	public function get_terms_for_language( $target_lang ) {
		$all   = get_option( 'cotranslate_no_translate_terms', array() );
		$lines = array();
		foreach ( array( '_all', $target_lang ) as $key ) {
			if ( ! empty( $all[ $key ] ) ) {
				$lines = array_merge( $lines, preg_split( '/\r\n|\r|\n/', $all[ $key ] ) );
			}
		}
		return $lines;
	}

	/**
	 * Översätt en lista texter med skydd av termer.
	 */
	public function translate_text( array $texts, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->inner->translate_text( $texts, $source_lang, $target_lang );
		}

		$maps      = array();
		$protected = array();
		foreach ( $texts as $i => $text ) {
			list( $p, $map ) = self::protect( $text, $terms );
			$protected[ $i ] = $p;
			$maps[ $i ]      = $map;
		}

		$result = $this->inner->translate_text( $protected, $source_lang, $target_lang );

		if ( is_array( $result ) ) {
			foreach ( $result as $i => $translated ) {
				if ( isset( $maps[ $i ] ) ) {
					$result[ $i ] = self::restore( $translated, $maps[ $i ] );
				}
			}
		}
		return $result;
	}

	/**
	 * Översätt HTML med skydd av termer.
	 */
	public function translate_html( $html, $source_lang, $target_lang ) {
		$terms = $this->get_terms_for_language( $target_lang );
		if ( empty( array_filter( $terms ) ) ) {
			return $this->inner->translate_html( $html, $source_lang, $target_lang );
		}
		list( $protected, $map ) = self::protect( $html, $terms );
		$result = $this->inner->translate_html( $protected, $source_lang, $target_lang );
		return is_string( $result ) ? self::restore( $result, $map ) : $result;
	}

	/**
	 * Slugs skyddas inte — de ska inte innehålla varumärkesord.
	 */
	public function translate_slug( $slug, $source_lang, $target_lang ) {
		return $this->inner->translate_slug( $slug, $source_lang, $target_lang );
	}

	/**
	 * Vidarebefordra alla övriga metoder till inre translatorn.
	 */
	public function __call( $name, $args ) {
		return call_user_func_array( array( $this->inner, $name ), $args );
	}
```

- [ ] **Step 3: Koppla in wrappern i factoryn**

I `includes/class-translator-factory.php`, ändra `create()` så att returvärdet lindas. Ersätt raderna:

```php
		if ( self::ENGINE_CLAUDE === $engine ) {
			return new CoTranslate_Claude_API();
		}

		return new CoTranslate_DeepL_API();
```

med:

```php
		if ( self::ENGINE_CLAUDE === $engine ) {
			$inner = new CoTranslate_Claude_API();
		} else {
			$inner = new CoTranslate_DeepL_API();
		}

		return new CoTranslate_No_Translate_Wrapper( $inner );
```

- [ ] **Step 4: Ladda klassfilen om autoload saknas**

Om Step 1 visade en manuell laddningslista i `class-plugin.php`, lägg till efter raden som laddar `class-translator-factory.php`:

```php
		require_once COTRANSLATE_PLUGIN_DIR . 'includes/class-no-translate-wrapper.php';
```

(Använd exakt samma konstant/sökvägsmönster som de omgivande raderna. Hoppa över om en autoloader redan laddar `includes/`.)

- [ ] **Step 5: Syntax-lint på ändrade filer**

Run: `php -l includes/class-no-translate-wrapper.php && php -l includes/class-translator-factory.php && php -l includes/class-plugin.php`
Expected: `No syntax errors detected` för varje fil.

- [ ] **Step 6: Kör om enhetstestet (regression)**

Run: `php tests/test-no-translate.php`
Expected: PASS — `Alla test gröna`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-no-translate-wrapper.php includes/class-translator-factory.php includes/class-plugin.php
git commit -m "Kopplar in Översätt inte-wrappern i översättningsmotorn"
```

---

### Task 3: Förstärk Claude-prompten att bevara platshållare

**Files:**
- Modify: `includes/class-claude-api.php:279-310` (build_single_prompt / build_batch_prompt)

**Interfaces:**
- Consumes: platshållarformatet `<!--COTRANSLATE_NT_*-->` från Task 1.

- [ ] **Step 1: Lägg till bevarande-instruktion i single-prompten**

I `build_single_prompt()`, direkt efter den befintliga HTML-instruktionen (raden som nämner "preserve all HTML tags"), lägg till:

```php
		$prompt .= " Any HTML comment of the form <!--COTRANSLATE_NT_0--> is a protected placeholder — copy it verbatim into the output and never translate, alter, remove or reorder it.";
```

- [ ] **Step 2: Lägg till samma instruktion i batch-prompten**

I `build_batch_prompt()`, lägg till motsvarande mening i prompt-strängen (samma formulering som Step 1) så att den gäller även vid batch-översättning.

- [ ] **Step 3: Syntax-lint**

Run: `php -l includes/class-claude-api.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-claude-api.php
git commit -m "Instruerar Claude att bevara Översätt inte-platshållare"
```

---

### Task 4: Admin-undersida "Översätt inte"

**Files:**
- Modify: `includes/class-admin.php` (submeny ~rad 94-101, ny render-metod, ny spar-handler, hook-registrering)

**Interfaces:**
- Consumes: optionen `cotranslate_no_translate_terms`; hjälpfunktionerna `cotranslate_get_enabled_languages()`, `cotranslate_get_default_language()`, `cotranslate_get_supported_languages()`.

- [ ] **Step 1: Registrera undersidan**

I `add_admin_menu()`, efter blocket som lägger till "Strängar"-sidan (slutar ~rad 101), lägg till:

```php
		add_submenu_page(
			'cotranslate',
			'Översätt inte',
			'Översätt inte',
			'manage_options',
			'cotranslate-no-translate',
			array( $this, 'render_no_translate_page' )
		);
```

- [ ] **Step 2: Lägg till render-metod med spar-hantering**

Lägg till en ny publik metod i klassen (t.ex. efter `render_strings_page()`):

```php
	/**
	 * Rendera sidan "Översätt inte" — ord/fraser som aldrig översätts.
	 */
	public function render_no_translate_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

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
			echo '<div class="notice notice-success"><p>Sparat.</p></div>';
		}

		$terms             = get_option( 'cotranslate_no_translate_terms', array() );
		$default_language  = cotranslate_get_default_language();
		$enabled_languages = cotranslate_get_enabled_languages();
		$supported         = cotranslate_get_supported_languages();
		?>
		<div class="wrap cotranslate-admin">
			<h1>CoTranslate — Översätt inte</h1>
			<p>Ord och fraser här lämnas oöversatta. Skriv ett ord eller en fras per rad. Matchningen är skiftlägesokänslig.</p>
			<form method="post">
				<?php wp_nonce_field( 'cotranslate_save_no_translate', 'cotranslate_no_translate_nonce' ); ?>

				<h2>Alla språk</h2>
				<p class="description">Gäller oavsett målspråk (t.ex. varumärken).</p>
				<textarea name="cotranslate_nt[_all]" rows="6" class="large-text code"><?php echo esc_textarea( $terms['_all'] ?? '' ); ?></textarea>

				<?php foreach ( $enabled_languages as $lang ) : ?>
					<?php if ( $lang === $default_language ) { continue; } ?>
					<?php $name = $supported[ $lang ]['name'] ?? $lang; ?>
					<h2><?php echo esc_html( $name ); ?></h2>
					<textarea name="cotranslate_nt[<?php echo esc_attr( $lang ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( $terms[ $lang ] ?? '' ); ?></textarea>
				<?php endforeach; ?>

				<p><button type="submit" class="button button-primary">Spara</button></p>
			</form>
		</div>
		<?php
	}
```

- [ ] **Step 3: Syntax-lint**

Run: `php -l includes/class-admin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Manuell verifiering i wp-admin**

1. Öppna **CoTranslate → Översätt inte**.
2. Bekräfta att "Alla språk" plus en ruta per aktiverat målspråk visas (inte huvudspråket).
3. Skriv `Reklamco` i "Alla språk", spara, ladda om → texten ska finnas kvar.
Expected: Sidan visas korrekt och värdet persisteras.

- [ ] **Step 5: Commit**

```bash
git add includes/class-admin.php
git commit -m "Lägger till admin-sidan Översätt inte"
```

---

### Task 5: End-to-end-verifiering och versionsbump

**Files:**
- Modify: `cotranslate.php:6` och `cotranslate.php:21` (version 3.0.3 → 3.1.0)

**Interfaces:**
- Consumes: hela kedjan från Task 1–4.

- [ ] **Step 1: End-to-end-test av en riktig översättning**

1. Lägg `Reklamco` i "Alla språk"-listan och spara.
2. Översätt ett inlägg/sida som innehåller ordet `Reklamco` till ett målspråk.
3. Granska resultatet: `Reklamco` ska stå kvar oöversatt och oförändrat.
4. Sök i den färdiga texten efter `COTRANSLATE_NT` — får INTE förekomma (inga läckta platshållare).
Expected: Termen bevarad, inga synliga platshållare.

- [ ] **Step 2: Bumpa versionen**

I `cotranslate.php`, ändra `Version: 3.0.3` → `Version: 3.1.0` (rad 6) och `define( 'COTRANSLATE_VERSION', '3.0.3' );` → `'3.1.0'` (rad 21).

- [ ] **Step 3: Syntax-lint**

Run: `php -l cotranslate.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add cotranslate.php
git commit -m "Bumpar till 3.1.0 — Översätt inte-lista"
```

## Self-Review

- **Spec coverage:** Datalagring (Task 2/4), per-språk + Alla språk UI (Task 4), wrapper-integration DRY (Task 2), platshållare + Claude-instruktion (Task 1/3), skiftlägesokänslig längsta-först-matchning (Task 1), edge-cases tom lista/specialtecken (Task 1). Täckt.
- **Placeholder scan:** Inga TBD/TODO; all kod fullständig.
- **Type consistency:** `protect()` returnerar `array( $text, $map )` och konsumeras likadant i Task 2; `<!--COTRANSLATE_NT_{n}-->` används konsekvent i Task 1 och 3; optionsnyckeln `cotranslate_no_translate_terms` och formfältet `cotranslate_nt[...]` matchar mellan Task 2 och 4.
