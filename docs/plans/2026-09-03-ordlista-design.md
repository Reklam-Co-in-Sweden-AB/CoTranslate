# Design — Ordlista (termordlista per språk) och branschkontext

Datum: 2026-09-03
Status: implementerat 2026-09-03 (version 3.2.0)

## Syfte

Låta administratören styra hur specifika branschtermer översätts, t.ex.
`räka → prawn`, `torskrygg → cod loin`, `kolja → haddock`. Bakgrund: First
Seafood får korrekta men "tokiga" översättningar eftersom motorn saknar
branschkunskap och väljer olika termer på olika sidor.

Två delar:

1. **Ordlista** — tvingande termpar per målspråk.
2. **Kontext** — en kort branschbeskrivning som skickas med varje anrop och
   styr ordvalet även för termer som inte står i ordlistan.

Båda ska fungera oavsett motor (DeepL och Claude). DeepL är fortsatt
standardmotor.

**Krav från kunden:** First Seafood kommer ha många åsikter om enskilda ord.
Flödet från "det här ordet är fel" till "det är rättat överallt" måste vara
kort och inte kräva att kunden går in i admin, letar upp sidor och översätter
om för hand. Se avsnittet "Smidigt flöde för kunden".

## Hur motorerna stödjer detta

### DeepL

- **Glossaries** (`POST /v2/glossaries`): egen ordlista per språkpar som
  DeepL följer strikt. Skapas en gång, returnerar `glossary_id`, som sedan
  skickas med i `POST /v2/translate`. Kräver att `source_lang` är satt, vilket
  pluginet redan gör. Ordlistor i v2 är oföränderliga — ändring innebär
  radera + skapa ny.
- **Stödda språkpar** hämtas från `GET /v2/glossary-language-pairs`. Svenska
  finns med i DeepLs lista, men pluginet ska verifiera mot API:et och visa
  varning för par som inte stöds i stället för att anta.
- **`context`**-parametern i `/v2/translate`: fritext som inte översätts och
  inte debiteras, men som påverkar ordval.
- **Begränsning att känna till:** DeepL matchar ordlistans källterm som helt
  ord, inte böjningar. `räka` matchar inte `räkor` eller `räkan`. Böjda
  former måste läggas in som egna rader. Detta ska stå i hjälptexten i UI.

### Claude

- Ordlistan injiceras i prompten som en tvingande termlista för aktuellt
  målspråk. Kontexten läggs in som bakgrundsbeskrivning. Claude hanterar
  böjningar själv.

## Datalagring

Två nya WordPress-options.

`cotranslate_glossary_terms` — samma form som `cotranslate_no_translate_terms`:

```php
array(
    'en' => "räka = prawn\nräkor = prawns\ntorskrygg = cod loin",
    'de' => "räka = Garnele",
)
```

Nyckel = målspråkets kod, värde = textruta med `källterm = målterm` per rad.
Ingen `_all`-nyckel — en målterm är alltid språkspecifik.

`cotranslate_translation_context` — en sträng, t.ex. "Text för ett svenskt
fiskeri- och skaldjursföretag som säljer färsk och fryst fisk till grossister
och restauranger. Använd handelns etablerade termer."

`cotranslate_deepl_glossary_ids` — intern, ej synlig i UI:

```php
array(
    'en' => array( 'id' => 'abc-123', 'hash' => 'md5 av rader' ),
)
```

Hashen används för att slippa skapa om ordlistan hos DeepL när inget ändrats.

## UI — egen undersida "Ordlista"

Ny submeny **"Ordlista"** (`cotranslate-glossary`) bredvid "Översätt inte".
Innehåll, uppifrån:

- **Kontext** — en `<textarea>` med hjälptext: "Beskriv kort verksamheten och
  vilken typ av text som översätts. Skickas med varje översättning och styr
  ordvalet."
- **Per aktiverat målspråk** (alla utom huvudspråket): rubrik med flagga och
  namn, en `<textarea>` med hjälptext: "En term per rad i formen
  `källterm = målterm`. DeepL matchar hela ord, så böjningar behöver egna
  rader (räka, räkor, räkan)."
- **Synkstatus per språk** under textrutan när DeepL är vald motor:
  "Synkad med DeepL", "Språkparet stöds inte av DeepL-ordlistor" eller
  felmeddelande från senaste synk.
- **Spara**-knapp. Sparar lokalt och synkar till DeepL i samma POST.
- Efter spara: statusrad "N sidor och M strängar översätts om" med
  förloppsindikator, samt lista över manuellt rättade texter som innehåller
  ändrade termer.

Spara-mönstret följer "Översätt inte"-sidan (form POST + nonce, radvis
sanering). Rader utan `=` eller med tom sida ignoreras.

## Integration — DeepL

Ny klass `CoTranslate_DeepL_Glossary` (eget ansvar, håller DeepL-API-klassen
ren):

- `sync( $target_lang, array $entries )`: bygger TSV, hämtar stödda språkpar
  (cachas i transient ett dygn), raderar gammal ordlista om id finns, skapar
  ny, sparar id + hash. Returnerar `true` eller `WP_Error`.
- `get_id( $source_lang, $target_lang )`: returnerar sparat id eller tom
  sträng.
- `delete_all()`: används vid byte av API-nyckel och vid avinstallation.

Ändring i `CoTranslate_DeepL_API::call_api()`:

- Lägg till `glossary_id` om `get_id()` ger träff.
- Lägg till `context` om `cotranslate_translation_context` inte är tom.
- Om DeepL svarar 400 med felmeddelande som nämner glossary: kasta det
  sparade id:t och gör om anropet en gång utan ordlista, så att en trasig
  synk aldrig stoppar översättning.

Byte av API-nyckel (Free ↔ Pro): ordlistor hör till kontot, så vid sparad ny
nyckel rensas `cotranslate_deepl_glossary_ids` och sidan visar "Behöver
synkas om".

## Integration — Claude

Ny hjälpfunktion i `helpers.php`:

```php
cotranslate_get_glossary_entries( $target_lang ) // array( 'räka' => 'prawn', … )
```

`build_single_prompt()` och `build_batch_prompt()` får ett block efter de
befintliga instruktionerna:

```
Context: <kontext>

Mandatory terminology (source = target). Always use these exact target
terms, including for inflected forms:
räka = prawn
torskrygg = cod loin
```

Blocket utelämnas helt när båda är tomma. Befintligt fält
`cotranslate_claude_prompt` ("stil") behålls som det är.

## Smidigt flöde för kunden

Kunden jobbar i första hand från den översatta sajten, inte från admin.
Frontend-editorn kräver bara `edit_posts`, så en redaktör räcker.

### Rätta ett ord överallt direkt från sidan

I frontend-editorns modal, under textfältet, läggs en sektion
**"Gäller överallt"** med två fält: *Ord på svenska* och *Ska bli*. Fältet
*Ska bli* förifylls när kunden bara ändrat ett ord eller en kort fras
(ord-diff mellan gammal och ny översättning). Kunden fyller i det svenska
ordet, sparar, och då sker tre saker:

1. Den aktuella texten sparas som manuell rättning (som idag).
2. Termparet läggs till i ordlistan för aktuellt målspråk och synkas till
   DeepL.
3. Berörda översättningar översätts om (se nedan).

Sparar kunden utan att fylla i sektionen blir det en vanlig engångsrättning.

### Automatisk omöversättning av berört innehåll

När ordlistan ändras (från adminsidan eller från frontend-editorn) letar
pluginet upp översättningar vars **källtext innehåller termen** och som
**inte** är manuellt rättade (`is_manual = 0`):

- `cotranslate_strings`: `source_text LIKE %term%` och `language = mål`.
- `cotranslate_translations`: inlägg vars titel, innehåll eller utdrag
  innehåller termen, kopplat till rad med `language = mål`.

Träffarna sätts till `status = 'pending'` (befintlig mekanism) och körs
genom befintlig batch-omöversättning i bakgrunden via AJAX-loop, samma som
"Översätt alla". Adminsidan visar "N sidor och M strängar översätts om" och
en förloppsindikator. Kostnad: bara berört innehåll skickas till motorn,
inte hela sajten.

Manuella rättningar rörs aldrig. Ordlistesidan visar därför per språk en
lista "Manuellt rättade texter som innehåller termen" så att kunden kan se
om en gammal handrättning nu strider mot ordlistan, med knapp "Släpp
rättningen och översätt om".

### Klistra in från Excel

Kunden kommer sannolikt skicka en lista i Excel. Radparsningen accepterar
därför både `källterm = målterm` och tab-separerat (`källterm<TAB>målterm`),
så att en kolumnmarkering från Excel kan klistras in rakt av. Rader med
rubriker eller tomma celler ignoreras tyst.

## Samspel med "Översätt inte"

Wrappern för "Översätt inte" ligger ovanför motorn och byter termer mot
platshållare innan texten når DeepL/Claude. En term som står i båda listorna
skyddas alltså av "Översätt inte" och når aldrig ordlistan. Det är rimligt
(skydd vinner) och dokumenteras i hjälptexten.

## Befintliga översättningar

Pluginet cachar färdiga översättningar i `cotranslate_translations` och
`cotranslate_strings`. Utan åtgärd slår en ändrad ordlista bara igenom på ny
text. Därför är den automatiska omöversättningen ovan en del av första
versionen, inte ett tillägg.

## Tester

Ny fil `tests/test-glossary.php` i befintlig stil:

- Radparsning: `a = b`, extra mellanslag, rad utan `=`, tom målsida, dubblett
  av källterm (sista vinner).
- TSV-bygge: tab och radbrytning i termer avvisas.
- Radparsning från Excel: tab-separerat, rubrikrad, tomma celler.
- Claude-promptblock: utelämnas när tomt, innehåller alla par när fyllt.
- Urval för omöversättning: träffar bara rader med `is_manual = 0` och
  källtext som innehåller termen.
- DeepL-fallback: simulerat 400-svar om glossary ger nytt anrop utan
  `glossary_id`.

Synk mot riktiga DeepL testas manuellt mot en Free-nyckel.

## Avgränsningar (YAGNI)

- Ingen export av ordlistan (inklistring från Excel räcker som import).
- Ingen ordlista för slugs.
- Ingen `_all`-ruta.
- Ingen automatisk upptäckt av vilket svenskt ord som motsvarar rättningen
  i frontend-editorn. Kunden skriver det själv.
- Inget modellval för Claude i denna ändring (kan bli egen ändring senare).

## Steg vid implementation

1. Option + hjälpfunktioner + radparsning (med tester).
2. Adminsida "Ordlista" med spara.
3. Claude-promptblock.
4. `CoTranslate_DeepL_Glossary` + `call_api`-ändringar + fallback.
5. Urval av berört innehåll + `pending` + bakgrundsloop för omöversättning.
6. "Gäller överallt" i frontend-editorn.
7. Rensning vid nyckelbyte och i `uninstall.php`.
8. Manuellt test mot DeepL Free med svenska → engelska, hela kundflödet
   från sida till omöversatt sajt.
9. Versionsbump och lärdomar i CLAUDE.md.
