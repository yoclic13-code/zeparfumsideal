<?php
/**
 * Enrichit les genres mixte via Fragrantica (Algolia).
 * Usage: php scripts/enrich_genders_fragrantica.php [offset] [limit]
 */
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../classes/PerfumeRepository.php';
require __DIR__ . '/../classes/PerfumApiClient.php';
require __DIR__ . '/../classes/ImportService.php';
require __DIR__ . '/../classes/FragranticaClient.php';
require __DIR__ . '/../classes/GenderClassifier.php';

$offset = max(0, (int)($argv[1] ?? 0));
$limit = max(1, min(80, (int)($argv[2] ?? 40)));

echo "Fragrantica gender enrich offset=$offset limit=$limit\n";
$importer = new ImportService(getDb());
$result = $importer->enrichGendersFromFragrantica($limit, $offset);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'Next offset: ' . ($offset + $limit) . PHP_EOL;
