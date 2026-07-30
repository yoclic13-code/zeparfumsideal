<?php
/**
 * Enrichit le catalogue local depuis PerfumAPI (CLI).
 * Usage: php scripts/run-enrich.php [offset] [limit]
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/PerfumApiClient.php';
require_once __DIR__ . '/../classes/ImportService.php';

$offset = max(0, (int)($argv[1] ?? 750));
$limit = max(1, min(500, (int)($argv[2] ?? 250)));

$db = getDb();
$importer = new ImportService($db);

echo "Enrichissement offset={$offset} limit={$limit}..." . PHP_EOL;

try {
    $result = $importer->enrichCatalogFromApi($limit, $offset);
    echo 'Terminé : ' . (int)$result['matched'] . ' enrichi(s) sur ' . (int)$result['seen'] . ' reçu(s).' . PHP_EOL;
    echo 'Prochain offset : ' . ($offset + $limit) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
