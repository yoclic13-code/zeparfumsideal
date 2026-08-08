<?php
/**
 * Enrichit plusieurs marques à la suite via PerfumAPI scrape + match local.
 * Usage: php scripts/scrape-brands-batch.php
 */
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../classes/PerfumeRepository.php';
require __DIR__ . '/../classes/PerfumApiClient.php';
require __DIR__ . '/../classes/ImportService.php';

@set_time_limit(0);

// Marque catalogue locale => nom Fragrantica / PerfumAPI
$brands = [
    'Rabanne' => ['Paco Rabanne', 15],
    'Dior' => ['Dior', 15],
    'Guerlain' => ['Guerlain', 12],
    'Givenchy' => ['Givenchy', 12],
    'Armani' => ['Giorgio Armani', 12],
    'Ysl' => ['Yves Saint Laurent', 12],
    'Chanel' => ['Chanel', 12],
    'Tom Ford' => ['Tom Ford', 10],
    'Hugo Boss' => ['Hugo Boss', 10],
    'Mugler' => ['Mugler', 10],
    'Kenzo' => ['Kenzo', 8],
    'Azzaro' => ['Azzaro', 8],
    'Montblanc' => ['Montblanc', 8],
    'Narciso Rodriguez' => ['Narciso Rodriguez', 8],
    'Valentino' => ['Valentino', 8],
    'Jean Paul Gaultier' => ['Jean Paul Gaultier', 10],
    'Carolina Herrera' => ['Carolina Herrera', 8],
    'Prada' => ['Prada', 8],
];

$importer = new ImportService(getDb());
$db = getDb();

$before = (int)$db->query(
    "SELECT COUNT(*) FROM perfumes WHERE is_active=1
     AND top_notes IS NOT NULL AND top_notes NOT IN ('','[]','null')"
)->fetchColumn();
echo "Notes avant : {$before}\n\n";

$totalScraped = 0;
$totalMatched = 0;

foreach ($brands as $localBrand => $cfg) {
    [$apiBrand, $limit] = $cfg;
    echo "=== {$localBrand} → scrape \"{$apiBrand}\" (limit {$limit}) ===\n";
    try {
        $result = $importer->scrapeBrandAndEnrich($apiBrand, $limit);
        $totalScraped += (int)$result['scraped'];
        $totalMatched += (int)$result['matched'];
        echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n";
    } catch (Throwable $e) {
        echo "ERREUR {$localBrand}: " . $e->getMessage() . "\n\n";
    }
}

$after = (int)$db->query(
    "SELECT COUNT(*) FROM perfumes WHERE is_active=1
     AND top_notes IS NOT NULL AND top_notes NOT IN ('','[]','null')"
)->fetchColumn();

echo "Terminé. Scrapés={$totalScraped} matched={$totalMatched}\n";
echo "Notes après : {$after} (avant {$before}, +" . ($after - $before) . ")\n";
