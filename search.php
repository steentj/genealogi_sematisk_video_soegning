<?php
// =============================================================================
// search.php – Semantisk søgning i video-artikler
// =============================================================================
//
// Denne side tilbyder:
//   1. Statistik over indexerede artikler
//   2. Et søgefelt hvor brugeren kan indtaste et emne
//   3. Søgeresultater rangeret efter semantisk lighed
//
// Søgningen fungerer ved at:
//   a) Lave en OpenAI embedding af brugerens søgetekst
//   b) Hente alle embeddings fra databasen
//   c) Beregne dot-produkt (cosine similarity for normaliserede vektorer) for hver
//   d) Filtrere på SIMILARITY_THRESHOLD og sortere bedste match øverst
//
// Kræver: config.php og en udfyldt database (kør import.php først)
// =============================================================================

require_once __DIR__ . '/config.php';

// =============================================================================
// Hjælpefunktioner
// =============================================================================

/**
 * Beregn dot-produktet mellem to vektorer.
 * Da OpenAI-embeddings er normaliserede (længde = 1), er dette
 * identisk med cosine similarity.
 *
 * @param  float[] $a
 * @param  float[] $b
 * @return float   Værdi mellem -1 og 1 (1 = identisk, 0 = ikke relateret)
 */
function dotProduct(array $a, array $b): float
{
    $sum = 0.0;
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) {
        $sum += $a[$i] * $b[$i];
    }
    return $sum;
}

/**
 * Kald OpenAI Embeddings API og returnér embedding-array.
 * (Samme funktion som i import.php – standalone for nem deployment)
 *
 * @param  string $text
 * @return float[]|null
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
        CURLOPT_TIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? null;
}

/**
 * Hent alle rækker med embeddings fra databasen.
 * Returnerer array af associative arrays med nøglerne:
 *   id, source_type, source_id, target_article_id, category, title, introtext, embedding (array)
 */
function getAllEmbeddings(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, source_type, source_id, target_article_id, category, video_title, linker_title, introtext, embedding
         FROM ' . DB_TABLE . '
         ORDER BY source_type, source_id'
    );

    $rows = [];
    while ($row = $stmt->fetch()) {
        // Parse JSON-strengen til PHP-array
        $row['embedding'] = json_decode($row['embedding'], true) ?? [];
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Søg i alle embeddings og returnér de bedste matches.
 *
 * @param  float[] $queryEmbedding  Embedding af søgeteksten
 * @param  array[] $allRows         Alle rækker fra databasen (med embedding som array)
 * @param  float   $threshold       Minimum similarity-score
 * @param  int     $maxResults      Maksimalt antal resultater (0 = alle)
 * @return array   Resultater sorteret efter score (højest først)
 */
function semanticSearch(array $queryEmbedding, array $allRows, float $threshold, int $maxResults): array
{
    $results = [];

    foreach ($allRows as $row) {
        $score = dotProduct($queryEmbedding, $row['embedding']);

        if ($score >= $threshold) {
            $results[] = [
                'id'                => $row['id'],
                'source_type'       => $row['source_type'],
                'source_id'         => $row['source_id'],
                'target_article_id' => $row['target_article_id'],
                'category'          => $row['category'],
                'video_title'       => $row['video_title'],
                'linker_title'      => $row['linker_title'],
                'introtext'         => $row['introtext'],
                'score'             => $score,
            ];
        }
    }

    // Sorter efter score, højest øverst
    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

    // Begræns antal resultater
    if ($maxResults > 0) {
        $results = array_slice($results, 0, $maxResults);
    }

    return $results;
}

/**
 * Hent databasestatistik.
 * Returnerer array med 'video' og 'videolink' som nøgler og antal som værdier.
 */
function getStatistics(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT source_type, COUNT(*) AS antal FROM ' . DB_TABLE . ' GROUP BY source_type'
    );

    $stats = ['video' => 0, 'videolink' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['source_type']] = (int) $row['antal'];
    }

    return $stats;
}

// =============================================================================
// Request-håndtering
// =============================================================================

$pdo        = getDatabaseConnection();
$stats      = getStatistics($pdo);
$query      = trim($_POST['query'] ?? '');
$results    = [];
$searchTime = null;
$errorMsg   = null;

// Kør søgning hvis der er sendt en søgetekst
if ($query !== '') {
    $startTime = microtime(true);

    // Lav embedding af søgeteksten via OpenAI
    $queryEmbedding = getEmbedding($query);

    if ($queryEmbedding === null) {
        $errorMsg = 'Kunne ikke oprette forbindelse til OpenAI API. '
                  . 'Kontrollér at API-nøglen i config.php er korrekt.';
    } else {
        // Hent alle embeddings og søg
        $allRows    = getAllEmbeddings($pdo);
        $results    = semanticSearch($queryEmbedding, $allRows, SIMILARITY_THRESHOLD, MAX_RESULTS);
        $searchTime = round((microtime(true) - $startTime) * 1000); // ms
    }
}

// =============================================================================
// HTML-output
// =============================================================================
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Semantisk videosøgning – Genealogi.dk</title>
    <style>
        /* ------------------------------------------------------------------ */
        /* Generel styling                                                     */
        /* ------------------------------------------------------------------ */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: Georgia, 'Times New Roman', serif;
            background: #f7f4ef;
            color: #222;
            margin: 0 auto;
            padding: 1.5em;
            max-width: 1100px;
        }

        h1 { color: #6b3a1f; border-bottom: 2px solid #c8a96e; padding-bottom: 0.3em; }
        h2 { color: #6b3a1f; margin-top: 1.5em; }

        /* ------------------------------------------------------------------ */
        /* Statistik-boks                                                      */
        /* ------------------------------------------------------------------ */
        .stats {
            background: #fff8ee;
            border: 1px solid #c8a96e;
            border-radius: 6px;
            padding: 1em 1.5em;
            margin-bottom: 1.5em;
            display: flex;
            gap: 2em;
            flex-wrap: wrap;
        }
        .stats-item { text-align: center; }
        .stats-number {
            font-size: 2em;
            font-weight: bold;
            color: #6b3a1f;
            display: block;
        }
        .stats-label { font-size: 0.85em; color: #666; }

        /* ------------------------------------------------------------------ */
        /* Søgeformular                                                        */
        /* ------------------------------------------------------------------ */
        .search-form {
            display: flex;
            gap: 0.5em;
            margin-bottom: 1.5em;
        }
        .search-form input[type="text"] {
            flex: 1;
            padding: 0.6em 0.8em;
            font-size: 1em;
            border: 1px solid #c8a96e;
            border-radius: 4px;
            background: #fff;
            font-family: inherit;
        }
        .search-form input[type="text"]:focus { outline: 2px solid #6b3a1f; }
        .search-form button {
            padding: 0.6em 1.4em;
            background: #6b3a1f;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-family: inherit;
        }
        .search-form button:hover { background: #8b4a2a; }

        .search-info { font-size: 0.85em; color: #666; margin-bottom: 1em; }

        /* ------------------------------------------------------------------ */
        /* Fejlbesked                                                          */
        /* ------------------------------------------------------------------ */
        .error-box {
            background: #fff0f0;
            border: 1px solid #e88;
            border-radius: 4px;
            padding: 0.8em 1em;
            color: #900;
            margin-bottom: 1em;
        }

        /* ------------------------------------------------------------------ */
        /* Resultattabel                                                       */
        /* ------------------------------------------------------------------ */
        .results-header { color: #555; margin-bottom: 0.8em; font-size: 0.9em; }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 0.92em;
        }
        .results-table thead th {
            background: #6b3a1f;
            color: #fff;
            padding: 0.6em 0.8em;
            text-align: left;
            font-weight: normal;
            white-space: nowrap;
        }
        .results-table tbody tr:nth-child(even) { background: #fdf8f2; }
        .results-table tbody tr:hover { background: #fff3e0; }
        .results-table td {
            padding: 0.5em 0.8em;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        /* Kolonne: Lighed */
        .col-score { white-space: nowrap; color: #888; font-size: 0.85em; }

        /* Kolonne: Id og Refereret fra Id – klikbare med popup */
        .id-cell {
            font-family: monospace;
            white-space: nowrap;
        }
        .id-link {
            cursor: pointer;
            color: #6b3a1f;
            text-decoration: underline dotted;
            border: none;
            background: none;
            font-family: monospace;
            font-size: 1em;
            padding: 0;
        }
        .id-link:hover { color: #a05020; }

        /* ------------------------------------------------------------------ */
        /* Popup til introtext                                                 */
        /* ------------------------------------------------------------------ */
        #popup-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 100;
            justify-content: center;
            align-items: center;
        }
        #popup-overlay.visible { display: flex; }

        #popup-box {
            background: #fff;
            border-radius: 8px;
            padding: 1.5em 2em;
            max-width: 640px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,0.25);
            position: relative;
        }
        #popup-title {
            font-weight: bold;
            font-size: 1.05em;
            color: #6b3a1f;
            margin-bottom: 0.8em;
        }
        #popup-text {
            font-size: 0.9em;
            line-height: 1.6;
            color: #333;
            white-space: pre-wrap;
        }
        #popup-close {
            position: absolute;
            top: 0.6em;
            right: 0.8em;
            font-size: 1.4em;
            cursor: pointer;
            color: #aaa;
            background: none;
            border: none;
            line-height: 1;
        }
        #popup-close:hover { color: #333; }

        /* ------------------------------------------------------------------ */
        /* Ingen resultater / hjælpetekst                                     */
        /* ------------------------------------------------------------------ */
        .no-results {
            color: #666;
            font-style: italic;
            padding: 1em;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .help-text {
            font-size: 0.85em;
            color: #666;
            margin-top: 2em;
            padding-top: 1em;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>

<h1>🎬 Semantisk videosøgning</h1>
<p>Find genealogi-videoer ved at beskrive et emne med dine egne ord – ikke kun nøgleord.</p>

<!-- ========================================================= -->
<!-- STATISTIK                                                  -->
<!-- ========================================================= -->
<div class="stats">
    <div class="stats-item">
        <span class="stats-number"><?= $stats['video'] + $stats['videolink'] ?></span>
        <span class="stats-label">Artikler i alt indexeret</span>
    </div>
    <div class="stats-item">
        <span class="stats-number"><?= $stats['video'] ?></span>
        <span class="stats-label">Artikler med video</span>
    </div>
    <div class="stats-item">
        <span class="stats-number"><?= $stats['videolink'] ?></span>
        <span class="stats-label">Artikler med link til video</span>
    </div>
</div>

<!-- ========================================================= -->
<!-- SØGEFORMULAR                                               -->
<!-- ========================================================= -->
<h2>Søg efter videoer</h2>
<form class="search-form" method="post" action="">
    <input
        type="text"
        name="query"
        placeholder="F.eks. »lægdsruller«, »kirkebøger fra 1800-tallet« eller »emigration til Amerika«"
        value="<?= htmlspecialchars($query) ?>"
        autofocus
    >
    <button type="submit">Søg</button>
</form>

<p class="search-info">
    Søgningen bruger semantisk forståelse via OpenAI – du kan beskrive et emne med egne ord.
    Minimumsgrænse for lighed: <?= SIMILARITY_THRESHOLD ?> &nbsp;|&nbsp;
    Maks. resultater: <?= MAX_RESULTS > 0 ? MAX_RESULTS : 'alle' ?><br>
    <em>Hold musen over eller klik på et Id-felt for at se videobeskrivelsen.</em>
</p>

<!-- ========================================================= -->
<!-- FEJLBESKED                                                 -->
<!-- ========================================================= -->
<?php if ($errorMsg): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<!-- ========================================================= -->
<!-- SØGERESULTATER                                             -->
<!-- ========================================================= -->
<?php if ($query !== '' && $errorMsg === null): ?>

    <h2>Resultater</h2>

    <?php if (empty($results)): ?>
        <div class="no-results">
            Ingen videoer fundet for søgningen <em>"<?= htmlspecialchars($query) ?>"</em>
            med den nuværende grænseværdi (<?= SIMILARITY_THRESHOLD ?>).
            Prøv en anden formulering eller sænk grænseværdien i config.php.
        </div>
    <?php else: ?>

        <p class="results-header">
            Fandt <strong><?= count($results) ?></strong>
            <?= count($results) === 1 ? 'resultat' : 'resultater' ?>
            for <em>"<?= htmlspecialchars($query) ?>"</em>
            (<?= $searchTime ?> ms)
        </p>

        <table class="results-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Video titel</th>
                    <th>Kategori</th>
                    <th title="Artikel-id på video-artiklen – klik for beskrivelse">Id</th>
                    <th title="Id på den artikel der linker til videoen – klik for beskrivelse">Refereret fra Id</th>
                    <th>Lighed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $rank => $result): ?>
                <?php
                    // article-id på selve video-artiklen
                    $articleId = ($result['source_type'] === 'video')
                        ? $result['source_id']
                        : $result['target_article_id'];

                    // linker_id – kun for videolink-type
                    $linkerId = ($result['source_type'] === 'videolink')
                        ? $result['source_id']
                        : null;

                    $scorePercent = round($result['score'] * 100, 1);

                    // Introtext bruges i popup – JSON-encode for sikker brug i JS
                    $introJson      = json_encode($result['introtext'], JSON_HEX_QUOT | JSON_HEX_APOS);
                    $videoTitleJson = json_encode($result['video_title'], JSON_HEX_QUOT | JSON_HEX_APOS);
                ?>
                <tr>
                    <!-- Rækkens rangering -->
                    <td><?= $rank + 1 ?></td>

                    <!-- Video titel -->
                    <td><?= htmlspecialchars($result['video_title']) ?></td>

                    <!-- Kategori -->
                    <td><?= htmlspecialchars($result['category'] ?? '') ?></td>

                    <!-- Id: artikel-id på video-artiklen. Hover/klik viser introtext popup -->
                    <td class="id-cell">
                        <button class="id-link"
                                onmouseover="showPopup(<?= $videoTitleJson ?>, <?= $introJson ?>)"
                                onclick="showPopup(<?= $videoTitleJson ?>, <?= $introJson ?>)"
                                title="Klik eller hold musen over for at se beskrivelse">
                            <?= htmlspecialchars($articleId) ?>
                        </button>
                        &nbsp;
                        <a href="https://genealogi.dk/index.php?option=com_content&amp;view=article&amp;id=<?= urlencode($articleId) ?>"
                           target="_blank" rel="noopener" title="Åbn video på genealogi.dk">↗</a>
                    </td>

                    <!-- Refereret fra Id: kun udfyldt for videolink-type -->
                    <td class="id-cell">
                        <?php if ($linkerId !== null): ?>
                            <button class="id-link"
                                    onmouseover="showPopup(<?= $videoTitleJson ?>, <?= $introJson ?>)"
                                    onclick="showPopup(<?= $videoTitleJson ?>, <?= $introJson ?>)"
                                    title="Klik eller hold musen over for at se beskrivelse">
                                <?= htmlspecialchars($linkerId) ?>
                            </button>
                        <?php else: ?>
                            <span style="color:#ccc">–</span>
                        <?php endif; ?>
                    </td>

                    <!-- Similarity score -->
                    <td class="col-score"><?= $scorePercent ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

<?php endif; ?>

<!-- ========================================================= -->
<!-- HJÆLPETEKST                                                -->
<!-- ========================================================= -->
<div class="help-text">
    <strong>Om søgningen:</strong>
    Systemet laver et semantisk "fingeraftryk" af din søgetekst via OpenAI og finder de videoer
    hvis beskrivelse ligner mest. Klik på et Id-felt for at se videobeskrivelsen.
</div>

<!-- ========================================================= -->
<!-- POPUP til introtext                                        -->
<!-- ========================================================= -->
<div id="popup-overlay" onclick="closePopupOnOverlay(event)">
    <div id="popup-box">
        <button id="popup-close" onclick="closePopup()" title="Luk">×</button>
        <div id="popup-title"></div>
        <div id="popup-text"></div>
    </div>
</div>

<!-- ========================================================= -->
<!-- JAVASCRIPT                                                 -->
<!-- ========================================================= -->
<script>
/**
 * Vis popup med videobeskrivelse.
 * Kaldes ved hover og klik på Id-felter i resultattabellen.
 *
 * @param {string} title    Videoens titel (vises som popup-overskrift)
 * @param {string} introtext  Beskrivelse uden HTML (vises som brødtekst)
 */
function showPopup(title, introtext) {
    document.getElementById('popup-title').textContent = title;
    document.getElementById('popup-text').textContent  = introtext;
    document.getElementById('popup-overlay').classList.add('visible');
}

/** Luk popup. */
function closePopup() {
    document.getElementById('popup-overlay').classList.remove('visible');
}

/** Luk popup ved klik uden for popup-boksen. */
function closePopupOnOverlay(event) {
    if (event.target === document.getElementById('popup-overlay')) {
        closePopup();
    }
}

/** Luk popup med Escape-tasten. */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePopup();
});
</script>

</body>
</html>
