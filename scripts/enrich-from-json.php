<?php
/**
 * Enrichit le catalogue local depuis le fichier data.json de PerfumAPI.
 * Usage: php scripts/enrich-from-json.php [chemin/vers/data.json]
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/PerfumApiClient.php';
require_once __DIR__ . '/../classes/ImportService.php';

$jsonPath = $argv[1] ?? 'C:\Users\parfu\Downloads\PerfumAPI\data\data.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Fichier introuvable : $jsonPath\n");
    exit(1);
}

$db = getDb();
$importer = new ImportService($db);
$repo = new \PerfumeRepository($db);

$beforeCount = (int)$db->query("SELECT COUNT(*) FROM perfumes WHERE top_notes IS NOT NULL AND top_notes != '' AND top_notes != '[]'")->fetchColumn();

echo "Catalogue avant : {$beforeCount} parfum(s) enrichi(s)" . PHP_EOL;
echo "Source : $jsonPath" . PHP_EOL;

$result = $importer->enrichCatalogFromJsonFile($jsonPath);

$afterCount = (int)$db->query("SELECT COUNT(*) FROM perfumes WHERE top_notes IS NOT NULL AND top_notes != '' AND top_notes != '[]'")->fetchColumn();

echo PHP_EOL;
echo "Vus dans le JSON : {$result['seen']}" . PHP_EOL;
echo "Enrichis : {$result['matched']}" . PHP_EOL;
echo "Ignorés (pas de correspondance) : {$result['skipped']}" . PHP_EOL;
echo "Total enrichis en base : {$afterCount} (avant : {$beforeCount})" . PHP_EOL;
