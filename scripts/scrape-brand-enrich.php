<?php
/**
 * Scrape une marque via PerfumAPI puis enrichit les concordances locales (notes).
 * Usage: php scripts/scrape-brand-enrich.php "Dior" [limit]
 */
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../classes/PerfumeRepository.php';
require __DIR__ . '/../classes/PerfumApiClient.php';
require __DIR__ . '/../classes/ImportService.php';

$brand = trim((string)($argv[1] ?? ''));
$limit = max(1, min(40, (int)($argv[2] ?? 10)));
if ($brand === '') {
    fwrite(STDERR, "Usage: php scripts/scrape-brand-enrich.php \"Dior\" [limit]\n");
    exit(1);
}

@set_time_limit(0);
echo "Scrape+enrich brand={$brand} limit={$limit} via " . PERFUM_API_BASE_URL . PHP_EOL;
$importer = new ImportService(getDb());
$result = $importer->scrapeBrandAndEnrich($brand, $limit);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
