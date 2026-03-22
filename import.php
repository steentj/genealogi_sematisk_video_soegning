<?php
// =============================================================================
// import.php – Import og embedding af video-artikler
// =============================================================================
//
// Dette script læser joomla4_videoer.json og joomla4_videolinks.json,
// opretter OpenAI embeddings for hver artikel og gemmer dem i MySQL.
//
// BRUG:
//   Kør én gang fra kommandolinjen:   php import.php
//   Eller åbn i browser (se password-beskyttelse nedenfor)
//
// Scriptet er sikkert at køre flere gange – eksisterende rækker opdateres
// (UPSERT via INSERT ... ON DUPLICATE KEY UPDATE).
//
// FORUDSÆTNINGER:
//   1. Udfyld config.php med gyldige DB- og OpenAI-oplysninger
//   2. Kør schema.sql mod din database (mysql -u user -p db < schema.sql)
//   3. PHP skal have curl-udvidelsen aktiveret
// =============================================================================

require_once __DIR__ . '/config.php';

// -----------------------------------------------------------------------------
// Simpel adgangskontrol (valgfri – fjern kommentar for at aktivere)
// Sæt et password i config.php og uncomment nedenstående blok
// -----------------------------------------------------------------------------
// define('IMPORT_PASSWORD', 'dit-hemmelige-password');
// if (php_sapi_name() !== 'cli') {
//     $inputPassword = $_GET['pw'] ?? '';
//     if ($inputPassword !== IMPORT_PASSWORD) {
//         http_response_code(403);
//         die('Adgang nægtet. Tilføj ?pw=PASSWORD til URL.');
//     }
// }

// Sæt tidslimit højt – API-kald kan tage tid ved mange artikler
set_time_limit(300);

// Bestem om vi kører i browser eller terminal
$isCli = (php_sapi_name() === 'cli');

/**
 * Udskriv en besked til terminal eller browser.
 */
function output(string $message, bool $isError = false): void
{
    global $isCli;
    if ($isCli) {
        echo ($isError ? '[FEJL] ' : '') . $message . PHP_EOL;
    } else {
        $class = $isError ? 'error' : 'info';
        echo '<p class="' . $class . '">' . htmlspecialchars($message) . '</p>' . PHP_EOL;
        // Flush output til browser løbende
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// =============================================================================
// HTML-header (kun i browser)
// =============================================================================
if (!$isCli) {
    echo '<!DOCTYPE html><html lang="da"><head><meta charset="utf-8">'
       . '<title>Import embeddings</title>'
       . '<style>body{font-family:monospace;max-width:900px;margin:2em auto;padding:0 1em}'
       . 'p{margin:0.2em 0}.error{color:red}.success{color:green}.header{font-weight:bold;margin-top:1em}</style>'
       . '</head><body><h1>Import af video-embeddings</h1>';
    ob_implicit_flush(true);
}

// =============================================================================
// Hjælpefunktioner
// =============================================================================

/**
 * Fjern HTML-tags og normaliser whitespace i en tekstreng.
 * Bruges til at rense introtext inden embedding.
 */
function cleanHtml(string $html): string
{
    // Erstat blok-tags med mellemrum så ord ikke flyder sammen
    $text = preg_replace('/<(p|br|div|h[1-6]|li|td|th)[^>]*>/i', ' ', $html);

    // Fjern alle resterende HTML-tags
    $text = strip_tags($text);

    // Dekod HTML-entiteter (f.eks. &nbsp; → mellemrum, &amp; → &)
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Erstat multiple whitespace-tegn (inkl. linjeskift) med ét mellemrum
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

/**
 * Sammensæt den tekst der skal bruges til embedding.
 * Format: "Titel. Renset introtext"
 */
function buildEmbeddingText(string $title, string $introtext): string
{
    $cleanTitle     = trim($title);
    $cleanIntrotext = cleanHtml($introtext);

    return $cleanTitle . '. ' . $cleanIntrotext;
}

/**
 * Kald OpenAI Embeddings API og returnér embedding-array.
 *
 * @param  string $text     Teksten der skal embeddes
 * @return float[]|null     Array med floats, eller null ved fejl
 */
function getEmbedding(string $text): ?array
{
    $url     = 'https://api.openai.com/v1/embeddings';
    $payload = json_encode([
        'model' => OPENAI_EMBEDDING_MODEL,
        'input' => $text,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        output("curl-fejl: $curlError", true);
        return null;
    }

    if ($httpCode !== 200) {
        output("OpenAI API fejl (HTTP $httpCode): $response", true);
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['data'][0]['embedding'])) {
        output("Uventet API-svar: $response", true);
        return null;
    }

    return $data['data'][0]['embedding'];
}

/**
 * Gem eller opdatér én række i databasen (UPSERT).
 *
 * @param PDO    $pdo
 * @param array  $row  Data der skal gemmes
 */
function upsertEmbedding(PDO $pdo, array $row): void
{
    $sql = 'INSERT INTO ' . DB_TABLE . '
                (source_type, source_id, target_article_id, category, video_title, linker_title, introtext, embedding)
            VALUES
                (:source_type, :source_id, :target_article_id, :category, :video_title, :linker_title, :introtext, :embedding)
            ON DUPLICATE KEY UPDATE
                category     = VALUES(category),
                video_title  = VALUES(video_title),
                linker_title = VALUES(linker_title),
                introtext    = VALUES(introtext),
                embedding    = VALUES(embedding)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($row);
}

// =============================================================================
// Indlæs og valider JSON-kildefiler
// =============================================================================
output('=== Starter import ===', false);
output('Indlæser kildefiler...');

/**
 * Hent tabel-data fra en phpMyAdmin JSON-eksport.
 * Returnerer data-array fra første 'table'-entry.
 */
function loadJsonExport(string $filePath): array
{
    if (!file_exists($filePath)) {
        die("Fil ikke fundet: $filePath" . PHP_EOL);
    }

    $raw  = file_get_contents($filePath);
    $json = json_decode($raw, true);

    if ($json === null) {
        die("JSON-parse-fejl i $filePath" . PHP_EOL);
    }

    foreach ($json as $entry) {
        if (isset($entry['type']) && $entry['type'] === 'table') {
            return $entry['data'] ?? [];
        }
    }

    return [];
}

$videoer    = loadJsonExport(JSON_VIDEOER);
$videolinks = loadJsonExport(JSON_VIDEOLINKS);

output('Video-artikler fundet:  ' . count($videoer));
// Ingen deduplikering: samme linker_id kan pege på flere video-artikler
// (target_article_id). Alle rækker med forskellig target_article_id indekseres.
output('Videolink-artikler fundet: ' . count($videolinks));

// =============================================================================
// Forbind til databasen
// =============================================================================
output('Opretter database-forbindelse...');
$pdo = getDatabaseConnection();
output('Database-forbindelse OK.');

// =============================================================================
// Importér video-artikler (source_type = 'video')
// =============================================================================
output('');
output('--- Importerer video-artikler ---');

$videoOk    = 0;
$videoFejl  = 0;
$totalVideo = count($videoer);

foreach ($videoer as $index => $row) {
    $sourceId   = $row['article'];
    $videoTitle = $row['video_title'];
    $intro      = $row['introtext'] ?? '';
    $category   = $row['category'] ?? '';

    $embeddingText = buildEmbeddingText($videoTitle, $intro);

    output(sprintf('[%d/%d] "%s" (article=%s)', $index + 1, $totalVideo, $videoTitle, $sourceId));

    // Kald OpenAI API
    $embedding = getEmbedding($embeddingText);

    if ($embedding === null) {
        output("  → FEJL: Embedding mislykkedes for artikel $sourceId", true);
        $videoFejl++;
        continue;
    }

    // Gem i database
    upsertEmbedding($pdo, [
        ':source_type'       => 'video',
        ':source_id'         => $sourceId,
        ':target_article_id' => null,
        ':category'          => $category,
        ':video_title'       => $videoTitle,
        ':linker_title'      => null,
        ':introtext'         => cleanHtml($intro),
        ':embedding'         => json_encode($embedding),
    ]);

    $videoOk++;

    // Lille pause for at undgå at ramme OpenAI's rate limit
    // (text-embedding-3-small: 3000 requests/min på Tier 1 – dette er en sikkerhedsmargin)
    usleep(50000); // 50 ms pause
}

output("Video-artikler: $videoOk importeret, $videoFejl fejlede.");

// =============================================================================
// Importér videolink-artikler (source_type = 'videolink')
// =============================================================================
output('');
output('--- Importerer videolink-artikler ---');

$linkOk    = 0;
$linkFejl  = 0;
$totalLink = count($videolinks);

foreach ($videolinks as $index => $row) {
    $sourceId        = $row['linker_id'];
    $videoTitle      = $row['video_title'];
    $linkerTitle     = $row['linker_title'];
    $intro           = $row['introtext'] ?? '';
    $category        = $row['category'] ?? '';
    $targetArticleId = $row['article'];  // artikel-id på video-artiklen

    $embeddingText = buildEmbeddingText($videoTitle, $intro);

    output(sprintf('[%d/%d] "%s" (linker_id=%s → artikel %s)',
        $index + 1, $totalLink, $linkerTitle, $sourceId, $targetArticleId));

    // Kald OpenAI API
    $embedding = getEmbedding($embeddingText);

    if ($embedding === null) {
        output("  → FEJL: Embedding mislykkedes for linker_id $sourceId", true);
        $linkFejl++;
        continue;
    }

    // Gem i database
    upsertEmbedding($pdo, [
        ':source_type'       => 'videolink',
        ':source_id'         => $sourceId,
        ':target_article_id' => $targetArticleId,
        ':category'          => $category,
        ':video_title'       => $videoTitle,
        ':linker_title'      => $linkerTitle,
        ':introtext'         => cleanHtml($intro),
        ':embedding'         => json_encode($embedding),
    ]);

    $linkOk++;

    usleep(50000); // 50 ms pause
}

output("Videolink-artikler: $linkOk importeret, $linkFejl fejlede.");

// =============================================================================
// Opsummering
// =============================================================================
output('');
output('=== Import afsluttet ===');
output(sprintf('Total: %d importeret, %d fejlede.', $videoOk + $linkOk, $videoFejl + $linkFejl));

// Hent statistik fra database
$stmt = $pdo->query('SELECT source_type, COUNT(*) AS antal FROM ' . DB_TABLE . ' GROUP BY source_type');
output('Rækker i databasen:');
foreach ($stmt->fetchAll() as $stat) {
    output('  ' . $stat['source_type'] . ': ' . $stat['antal']);
}

if (!$isCli) {
    echo '</body></html>';
}
