# Design — Tydligare adminflöde: vad översätts, hur och när

Datum: 2026-09-03
Status: implementerat 2026-09-03 (version 3.3.0)

## Syfte

Göra det begripligt för en administratör (byrå eller kund) vad som händer
med innehållet: vad som översätts, på vilket sätt, när det sker och var man
går för att rätta. Ingen översättningslogik ändras — detta är enbart admin-UX.

## Nuläge och vad som skaver

Pluginet har två oberoende översättningsvägar som aldrig förklaras i UI:

| Väg | Vad | Hur | När |
|---|---|---|---|
| **Sidor** (`cotranslate_translations`) | Titel, brödtext, utdrag, slug per inlägg | Hela inlägget skickas till motorn | Vid spara (direkt), "Köa alla poster" + cron varje minut |
| **Strängar** (`cotranslate_strings`) | All synlig text som inte fångas ovan: menyer, knappar, widgets, **och all brödtext på page builder-sidor** | Output-bufferten byter text för text vid sidvisning | Första besöket köar, cron 30 s senare översätter; eller "Skanna alla" |

Konsekvenser för den som administrerar:

1. **Beaver Builder-sidor ser "oöversatta" ut under Översättningar.** Raden
   finns men innehållskolumnen är tom, eftersom brödtexten ligger i
   strängtabellen. Ingen förklaring ges.
2. **Ingen vet om kön står still.** Cron kan vara avstängd eller kvoten slut.
   Enda ledtråden är statuskolumnen "Väntar" på enskilda rader, eller
   statistiktabellen längst ner i Verktyg-fliken.
3. **Fem knappar utan inbördes ordning** under Verktyg: "Köa alla poster",
   "Översätt enskild post", "Skanna och översätt alla sidor", "Skanna enskild
   sida", "Översätt alla köade strängar nu". Texterna säger vad knappen gör
   tekniskt, inte när man ska trycka.
4. **Terminologi på tre ställen:** "Översätt inte" (egen sida), "Ordlista" +
   kontext (egen sida), Claude-stilprompt (Inställningar). Alla styr ordval.
5. **Inställningar är startsidan.** Det första man ser är API-nycklar och
   språkval, som man ändrar en gång. Det man vill se dagligen — status —
   ligger som sista sektion i en flik.
6. **Vokabulär från kodens perspektiv:** "poster", "strängar", "manuell
   override", "auto", "pending", "kontext: general/scanned/frontend". En
   kund förstår "sidor", "texter", "handrättad", "översatt", "väntar".

## Målbild

Menyn blir:

```
CoTranslate
├── Översikt          ← ny startsida
├── Sidor             ← Översättningar, omdöpt
├── Texter            ← Strängar, omdöpt
├── Terminologi       ← Ordlista + Översätt inte + kontext/stil, tre flikar
└── Inställningar     ← som idag, minus verktyg och statistik
```

Principen: **en sida per fråga användaren ställer.**
"Hur ligger vi till?" → Översikt. "Var är den här sidan?" → Sidor.
"Var är den här knapptexten?" → Texter. "Varför blev ordet så?" → Terminologi.

## Översikt (ny startsida)

### 1. "Så fungerar det" (infoblock, går att fälla ihop, minns läge i localStorage)

Fyra korta stycken med samma ord som används i resten av UI:

- **Sidor** översätts som helhet när du publicerar eller uppdaterar dem.
- **Texter** (menyer, knappar, och allt innehåll på sidor byggda med Beaver
  Builder eller annan sidbyggare) plockas upp första gången någon besöker
  sidan på ett annat språk, och översätts strax därefter.
- **Allt nytt hamnar i en kö** som körs i bakgrunden varje minut. Du kan
  trycka "Kör kön nu" för att slippa vänta.
- **Handrättade** översättningar skrivs aldrig över automatiskt.

### 2. Statuskort per språk

Ett kort per aktiverat målspråk, med flagga och namn:

```
🇬🇧 English
Sidor      142 översatta · 3 väntar · 12 handrättade
Texter     1 204 översatta · 17 väntar · 41 handrättade
Terminologi 23 termer i ordlistan · synkad med DeepL 09:14
```

Siffrorna länkar till respektive sida med filter förvalt (t.ex. "3 väntar"
→ Sidor filtrerat på språk + status väntar).

### 3. Kö och bakgrundsjobb

En rad som svarar på "händer det något?":

- **Kö:** "20 objekt väntar. Kördes senast 09:14." eller "Kön är tom."
- **Bakgrundsjobb:** grön/röd indikator för om WP-Cron är schemalagd
  (`wp_next_scheduled`), med hjälptext om `DISABLE_WP_CRON` upptäcks.
- **Kvot:** DeepL-mätaren som idag ligger under fliken Användning, med
  varning vid 80 % och stopp-notis vid 95 % (som kön redan respekterar).
- **Knapp "Kör kön nu"** med förloppsindikator. Återanvänder loopen och
  endpointen `cotranslate_glossary_process` från ordlistan; den döps om till
  `cotranslate_process_queue_now` eftersom den nu är generell.
- Om kön inte rör sig visas orsaken (kvot slut, API-fel, saknad nyckel),
  samma logik som stall-detekteringen i loopen.

### 4. Kom igång (visas bara tills allt är gjort)

Checklista som försvinner rad för rad:

- [ ] API-nyckel sparad och testad
- [ ] Minst ett målspråk aktiverat
- [ ] Standardspråket är med bland aktiverade språk (den kända fällan där
      växlaren blir osynlig)
- [ ] Innehållet är översatt en första gång → knapp "Översätt hela sajten"
      som gör det som idag kräver två knappar (köa alla sidor + skanna alla
      sidor) i en enda förloppsindikator

## Sidor (f.d. Översättningar)

- Omdöpt i meny och rubrik. "Post" → "Sida", "Auto" → "Översatt",
  "Pending" → "Väntar", "Manuell override" → "Handrättad".
- **Ny kolumn "Översätts via":** "Hela sidan" eller "Texter (sidbyggare)".
  Beräknas med befintliga `has_page_builder_content()`. För sidbyggarsidor
  visas i innehållskolumnen texten "Innehållet hanteras under Texter" med
  länk dit, filtrerat på sidans strängar (se nedan).
- Statusfiltret får värdena Alla / Översatt / Väntar / Handrättad.

## Texter (f.d. Strängar)

- Omdöpt. Kolumnen "Kontext" (general/scanned/frontend/menu…) tas bort ur
  tabellen; den är intern och visas i stället som tooltip på originaltexten.
- **Nytt filter "Hittad på sida":** kräver att vi vet var en text sågs. Vi
  lägger till kolumnen `first_seen_post_id` i strängtabellen och sätter den i
  `queue_untranslated()` via `get_queried_object_id()`. Bara första
  förekomsten sparas — det räcker för att svara på "var finns den här
  texten". Befintliga rader får NULL och visas som "Okänd sida".
- Statusfiltret får samma ordval som Sidor.

## Terminologi (samlar tre sidor)

Flikar i samma stil som Inställningar:

1. **Ordlista** — dagens Ordlista-sida oförändrad (termpar per språk,
   omöversättning, konflikter).
2. **Skyddade ord** — dagens "Översätt inte", omdöpt. Hjälptexten förklarar
   relationen: skyddade ord vinner över ordlistan.
3. **Kontext och stil** — kontextfältet (flyttas hit från Ordlista) och
   Claude-stilprompten (flyttas hit från Inställningar). Rubriken förklarar
   att kontexten gäller båda motorerna och stilen bara Claude.

Undermenyerna "Översätt inte" och "Ordlista" tas bort; gamla länkar
(`page=cotranslate-no-translate`, `page=cotranslate-glossary`) omdirigeras
till rätt flik så att bokmärken fungerar.

## Inställningar (bantad)

Behåller flikarna Inställningar och Användning som idag men:

- **Verktyg-fliken tas bort.** Innehållet fördelas:
  - "Köa alla poster" + "Skanna alla sidor" → ersätts av "Översätt hela
    sajten" på Översikt.
  - "Översätt enskild post" → knapp "Översätt om" på raden under Sidor
    (finns delvis redan som återställ-knapp).
  - "Skanna enskild sida" → knapp "Hämta texter från sida" under Texter.
  - "Översätt alla köade strängar nu" → ersätts av "Kör kön nu" på Översikt.
  - Export/Import och Migrering → ny flik **Avancerat** under Inställningar.
  - Statistik → Översikt.
- Claude-stilprompten flyttas till Terminologi.
- Fliken Användning kan på sikt gå upp i Översikt; behålls i första steget.

## Ordlista och etiketter (gemensam ordbok för hela admin)

| Idag | Föreslås |
|---|---|
| Post, poster | Sida, sidor |
| Sträng, strängar | Text, texter |
| Auto / auto-översatt | Översatt |
| Pending | Väntar |
| Manuell override / manuell | Handrättad |
| Processa / köa | Översätt / Kör kön |
| Skanna | Hämta texter |
| Kontext (på strängar) | Källa (dold som standard) |

Etiketterna byts i PHP-templates och JS-statusmeddelanden. Databasvärden
(`auto`, `pending`, `manual`) rörs inte.

## Vad som INTE ändras

- Ingen ändring i översättningslogik, köer, cron, tabeller utöver kolumnen
  `first_seen_post_id`.
- Frontend-editorn oförändrad (nyss byggd).
- AJAX-endpoints behålls med samma namn utom omdöpningen ovan; JS uppdateras
  samtidigt.

## Tekniska anteckningar

- Översikt hämtar siffror från befintliga `get_post_stats()` och
  `get_string_stats()` plus `count_pending()` i requeue-klassen. Inga nya
  frågor utöver `wp_next_scheduled` och en `SELECT MAX(updated_at)`.
- `has_page_builder_content()` är privat i post-translator; görs publik och
  statisk så att Sidor-listan kan använda den utan att instansiera.
- Kolumnen `first_seen_post_id` läggs till via `dbDelta` i aktivatorn med
  ett `db_version`-steg, som tidigare schemaändringar.
- Omdirigeringen av gamla menylänkar görs på `admin_init` med
  `wp_safe_redirect` till `page=cotranslate-terminology&tab=…`.
- Infoblockets ihopfällda läge sparas i `localStorage`, inte i options.

## Steg vid implementation

1. Ordboken: byt etiketter i Sidor, Texter, JS-meddelanden. Inga nya sidor.
   (Liten, säker, ger direkt effekt.)
2. Terminologi-sidan med tre flikar; flytta kontext och stilprompt;
   omdirigera gamla länkar.
3. Översikt: infoblock, statuskort, kö/bakgrund/kvot, "Kör kön nu".
4. "Översätt hela sajten" som en kombinerad loop; ta bort Verktyg-fliken
   och lägg Avancerat-fliken.
5. Kolumnen "Översätts via" under Sidor.
6. `first_seen_post_id` + filtret "Hittad på sida" under Texter.
7. Kom igång-checklistan.
8. Versionsbump 3.3.0, lärdomar.

Steg 1–3 ger den största tydlighetsvinsten och kan släppas som en första
version. Steg 5–7 kan vänta.

## Öppna frågor

- Ska kunden (redaktörsroll) se Översikt, eller bara administratörer? Idag
  kräver allt `manage_options`. Förslag: Översikt och Terminologi kräver
  `edit_posts`, Inställningar kräver `manage_options`.
- Ska fliken Användning (DeepL-kvot) ligga kvar under Inställningar eller
  helt gå upp i Översikt? Förslag: kvar i första steget.
