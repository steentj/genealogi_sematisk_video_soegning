# Semantisk videosøgning – genealogi.dk

Denne prototype implementerer semantisk søgning i genealogi.dk's videoarkiv ved hjælp af OpenAI embeddings og MySQL.

## Hvad gør dette?

I stedet for at søge på præcise nøgleord analyserer systemet *betydningen* af søgeteksten og finder videoer med lignende indhold – selv om de bruger andre ord. Det fungerer ved at:

1. Konvertere alle video-beskrivelser til matematiske "fingeraftryk" (embeddings) via OpenAI
2. Gemme disse fingeraftryk i MySQL som JSON-strenge
3. Når en bruger søger: lave et fingeraftryk af søgeteksten og finde de videoer, hvis fingeraftryk ligner mest (cosine similarity / dot-produkt)

---

## Filstruktur

```
semantisk-video-søgning/
├── config.php      Konfiguration (database, OpenAI API-nøgle, søgeparametre)
├── schema.sql      MySQL tabel-definition – kør én gang
├── import.php      Engangs-script: læser JSON, laver embeddings, gemmer i DB
├── search.php      Søgesiden (statistik + søgefelt + resultater)
└── README.md       Denne fil
```

Kildedata (i projektets rodmappe):
```
joomla4_videoer.json     196 artikler med YouTube-videoer
joomla4_videolinks.json  30 artikler der linker til video-artikler
```

---

## Forudsætninger

### Lokalt (prototype)
- **PHP 7.4+** med udvidelserne `curl` og `pdo_mysql` aktiveret
- **MySQL 5.7+** eller **MariaDB 10.3+**
- En OpenAI API-nøgle (opret på [platform.openai.com/api-keys](https://platform.openai.com/api-keys))
- Anbefalede lokale udviklingsmiljøer: [MAMP](https://www.mamp.info/) (macOS/Windows), [XAMPP](https://www.apachefriends.org/) (alle platforme) eller [Laragon](https://laragon.org/) (Windows)

### one.com (produktion)
- PHP og MySQL er inkluderet i alle one.com hosting-pakker
- Ingen ekstra udvidelser kræves – `curl` og `pdo_mysql` er aktiveret som standard

---

## Opsætning – lokal prototype

### 1. Klargør databasen

Opret en lokal MySQL-database (f.eks. kaldet `genealogi`) og kør schema-scriptet:

```bash
mysql -u root -p genealogi < schema.sql
```

Eller via phpMyAdmin: Vælg databasen → fanen "SQL" → indsæt indholdet af `schema.sql` → klik "Udfør".

### 2. Konfigurér config.php

Åbn `config.php` og udfyld:

```php
define('OPENAI_API_KEY', 'sk-din-api-nøgle-her');

define('DB_HOST', 'localhost');
define('DB_NAME', 'genealogi');
define('DB_USER', 'root');
define('DB_PASS', '');          // Lokalt: ofte tomt eller 'root'
```

### 3. Kør import-scriptet

**Fra kommandolinjen (anbefalet – giver live-output):**

```bash
cd semantisk-video-søgning
php import.php
```

**Fra browser:**

Start din lokale webserver og åbn:
```
http://localhost/genealogi/semantisk-video-søgning/import.php
```

Import-scriptet udfører ca. 226 API-kald til OpenAI (196 video-artikler + 30 link-artikler).
Estimeret tid: 30–90 sekunder afhængigt af netværkshastighed.

Scriptet er sikkert at køre flere gange – eksisterende rækker opdateres (UPSERT).

### 4. Åbn søgesiden

```
http://localhost/genealogi/semantisk-video-søgning/search.php
```

### 5. Lokal test på macOS (hurtig guide)

Hvis du vil teste uden MAMP/XAMPP, kan du bruge PHP's indbyggede webserver direkte fra projektmappen:

```bash
cd /Users/steen/Projects/Genealogi/semantisk-video-søgning
php -S localhost:8080
```

Åbn derefter i browseren:

```
http://localhost:8080/search.php
```

Hvis du vil køre import via browser i samme setup:

```
http://localhost:8080/import.php
```

**Vigtigt om DB_HOST vs localhost:8080**

- `localhost:8080` i browseren er webserveren (PHP-siden)
- `DB_HOST` i `config.php` er databaseserveren og er typisk `localhost` (uden `:8080`)
- Hvis din MySQL ikke kører på standardporten 3306 (f.eks. MAMP kan bruge 8889), skal den port angives i databaseforbindelsen

Ved MAMP er det normalt:

- `DB_HOST = localhost`
- `DB_USER = root`
- `DB_PASS = root`
- MySQL-port = `8889` (afhænger af din MAMP-indstilling)

---

## Brug af søgesiden

Søgesiden viser:
- **Statistik** over antal indexerede artikler
- **Søgefelt** – skriv et emne med egne ord
- **Resultater** rangeret efter relevans (klik for at se beskrivelsen)

**Eksempler på søgninger:**
- `lægdsruller`
- `emigration til Amerika`
- `kirkebøger fra landdistrikter`
- `hvem var mine forfædre i 1800-tallet`

---

## Konfigurationsmuligheder (config.php)

| Konstant | Standard | Beskrivelse |
|----------|----------|-------------|
| `OPENAI_API_KEY` | *(skal udfyldes)* | Din OpenAI API-nøgle |
| `OPENAI_EMBEDDING_MODEL` | `text-embedding-3-small` | OpenAI model til embeddings |
| `EMBEDDING_DIMENSIONS` | `1536` | Antal dimensioner (følger modellen) |
| `SIMILARITY_THRESHOLD` | `0.40` | Minimum cosine similarity for at vise et resultat |
| `MAX_RESULTS` | `20` | Maks. antal resultater (0 = vis alle) |
| `DB_HOST` | `localhost` | MySQL server |
| `DB_NAME` | `genealogi` | Databasenavn |
| `DB_USER` | `root` | Databasebruger |
| `DB_PASS` | *(tomt)* | Database-password |
| `DB_TABLE` | `video_embeddings` | Tabelnavn |

### Justering af søgegrænsen (SIMILARITY_THRESHOLD)

Cosine similarity er en værdi mellem 0 og 1 for OpenAI-embeddings:

| Score | Fortolkning |
|-------|-------------|
| > 0.70 | Meget relevant |
| 0.50–0.70 | Relevant |
| 0.40–0.50 | Svagt relateret |
| < 0.40 | Sandsynligvis ikke relevant |

Hvis søgningen giver **for mange irrelevante resultater**, øg grænsen til f.eks. `0.50`.  
Hvis søgningen giver **for få resultater**, sænk grænsen til f.eks. `0.30`.

---

## Deployment til one.com / Joomla

### 1. Upload filer

Upload mappen `semantisk-video-søgning/` til dit Joomla-webroot via FTP/SFTP (f.eks. med FileZilla).  
Placér den f.eks. under `/public_html/semantisk-video-søgning/`.

### 2. Opret tabellen i one.com's MySQL

Log ind på one.com → "Databaser" → phpMyAdmin → Vælg din Joomla-database → Fanen "SQL" → Indsæt indholdet af `schema.sql` → Udfør.

### 3. Opdatér config.php

Skift til one.com's databaseoplysninger (find dem i one.com control panel under "Databaser"):

```php
define('OPENAI_API_KEY', 'sk-din-api-nøgle-her');

define('DB_HOST', 'din-db-host.one.com');  // F.eks. 'mysql52.one.com'
define('DB_NAME', 'dit-databasenavn');
define('DB_USER', 'dit-databasebrugernavn');
define('DB_PASS', 'dit-database-password');
```

**Bemærk:** Du kan også bruge den samme MySQL-database som Joomla – `video_embeddings`-tabellen vil ikke kollidere med Joomla's tabeller.

### 4. Kør import.php på serveren

Åbn i browser (én gang):
```
https://genealogi.dk/semantisk-video-søgning/import.php
```

> **Sikkerhedstip:** Aktivér password-beskyttelsen i `import.php` (se kommentarerne øverst i scriptet) inden upload, så uvedkommende ikke kan kalde scriptet.

### 5. Test søgesiden

```
https://genealogi.dk/semantisk-video-søgning/search.php
```

### 6. Indlejring i Joomla (valgfrit)

For at integrere søgesiden i Joomla's design kan du:

**Mulighed A:** Opret en ny Joomla-artikel og indsæt via et Custom HTML-modul der indlæser siden med en iframe.

**Mulighed B:** Opret et Custom HTML-modul i Joomla og kald `search.php` med `include` (kræver tilpasning af stier og at filen ligger inden for Joomla's kontekst).

**Mulighed C (enklest):** Link direkte til `search.php` fra Joomla's menu som en "Ekstern URL"-menupost.

---

## Vedligeholdelse

### Tilføj nye videoer

Når nye video-artikler publiceres på genealogi.dk:
1. Eksportér en ny version af `joomla4_videoer.json` fra MySQL (phpMyAdmin → Export → JSON)
2. Kør `import.php` igen – eksisterende artikler opdateres, nye tilføjes

### OpenAI-omkostninger

- **Model:** `text-embedding-3-small`
- **Pris:** ca. $0.02 per 1 million tokens
- **Estimat for 226 artikler:** < $0.01 (meget billigt)
- **Per søgning:** én API-kald til embedding af søgeteksten (~$0.000001)

---

## Tekniske noter

### Søgealgoritme

Søgningen bruger **brute-force cosine similarity**:
1. Hent alle rækker fra databasen (inkl. embedding-JSON)
2. Parse JSON til PHP-array for hver række
3. Beregn dot-produktet mellem søge-embedding og hver rækkes embedding
4. Da OpenAI-embeddings er normaliserede (L2-norm = 1), er dot-produkt = cosine similarity
5. Filtrer på `SIMILARITY_THRESHOLD` og sorter

Ved 226 artikler er dette hurtigt (< 100 ms). Hvis antallet vokser til tusinder, bør man overveje at implementere en approximativ søgning eller bruge en dedikeret vektordatabase.

### HTML-stripping

`introtext`-felterne fra Joomla indeholder HTML-markup (spans, iframes, osv.).  
Inden embedding fjernes al HTML med `strip_tags()` kombineret med `html_entity_decode()` og normalisering af whitespace.

### Databeskyttelse

`config.php` bør **aldrig** committes til et offentligt repository. Tilføj den til `.gitignore`:
```
semantisk-video-søgning/config.php
```
