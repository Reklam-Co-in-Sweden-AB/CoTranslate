# Design — "Översätt inte"-lista (no-translate terms)

Datum: 2026-06-24

## Syfte

Låta administratören ange ord och fraser som aldrig ska översättas (t.ex.
varumärken, produktnamn, egennamn). Innan text skickas till översättnings-
motorn (Claude eller DeepL) byts varje träff mot en platshållare; efter svaret
återställs originaltexten exakt.

## Beslut (från brainstorming)

- **Matchning:** exakt fras, var som helst i texten (även inuti ord).
- **Skiftläge:** okänsligt (`iPhone` matchar `iphone`).
- **Omfattning:** per målspråk, plus en delad "Alla språk"-ruta överst.
- **Placering:** egen undersida "Översätt inte" i CoTranslate-menyn.
- **Integration:** wrapper-klass runt translatorn (DRY — rör inte motorerna).

## Datalagring

WordPress-option `cotranslate_no_translate_terms`:

```php
array(
    '_all' => "Reklamco\niPhone",   // gäller alla målspråk
    'en'   => "Starby",             // gäller endast engelska
    'de'   => "...",
)
```

Nyckel = målspråkets kod (eller `_all`), värde = textruta med ett ord/en fras
per rad. Endast aktiverade målspråk (alla utom huvudspråket) visas i UI.

## UI — egen undersida

Ny submeny **"Översätt inte"** (`cotranslate-no-translate`). Innehåll:

- En `<textarea>` "Alla språk" överst.
- En `<textarea>` per aktiverat målspråk, med språkets namn som rubrik.
- Hjälptext: "Ett ord eller en fras per rad."
- Spara via befintligt admin-mönster (form POST eller AJAX som övriga sidor).
- Input saneras radvis vid spar.

## Integration — wrapper-klass

Ny klass `CoTranslate_No_Translate_Wrapper` som implementerar samma gränssnitt
som motorerna (`translate_text`, `translate_html`, `translate_slug`) och lindar
den riktiga translatorn som factoryn returnerar.

Flöde per anrop som har ett `$target_lang`:

1. `protect( $text, $target_lang )` — slå ihop `_all`-termer + språkets termer,
   sortera längsta först, ersätt varje träff (skiftlägesokänsligt) med en unik
   platshållare. Returnera skyddad text + karta.
2. Anropa inre translatorns metod.
3. `restore( $text, $map )` — byt tillbaka varje platshållare mot **original-
   termen** (exakt som angiven i listan).

`translate_slug()` skyddas INTE (slugs ska inte innehålla varumärkesord).

Factoryn (`CoTranslate_Translator_Factory`) returnerar wrappern istället för
den nakna translatorn, så att både Claude och DeepL täcks automatiskt.

## Platshållare

Återanvänd den beprövade HTML-kommentarsstilen som redan finns i
`class-string-translator.php`: `<!--COTRANSLATE_NT_n-->`. Den överlever
HTML-översättning hos båda motorer. Claude-prompten förstärks med instruktion
om att bevara `<!--COTRANSLATE_NT_*-->`-kommentarer orörda. Efter implementering
verifieras att inga platshållare läcker ut i färdig text.

## Edge-cases

- Tom lista → snabb retur, ingen påverkan.
- Längsta fraser skyddas först så att ett kortare ord inte bryter en längre fras.
- Termer trimmas; tomma rader ignoreras.
- Om en term råkar innehålla regex-tecken används `str_ireplace` (ej regex) för
  matchning, vilket undviker injektionsproblem.

## Avgränsningar (YAGNI)

- Ingen hela-ord-matchning (medvetet bortvald).
- Ingen import/export av listan.
- Ingen per-term-inställning för skiftläge.
