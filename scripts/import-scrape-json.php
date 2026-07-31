<?php
/**
 * Import one-shot depuis un JSON scrape (stdout du script Python).
 * Usage: php scripts/import-scrape-json.php path/to/scrape.json
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/CatalogSyncService.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php import-scrape-json.php <fichier.json>\n");
    exit(1);
}

$raw = file_get_contents($path);
// BOM éventuel PowerShell
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['ok'])) {
    fwrite(STDERR, "JSON invalide ou ok=false\n");
    exit(1);
}

$products = $data['products'] ?? [];
$service = new CatalogSyncService(new PerfumeRepository(getDb()));
$created = 0;
$updated = 0;
$errors = 0;

foreach ($products as $product) {
    if (!is_array($product)) {
        $errors++;
        continue;
    }
    try {
        $result = $service->syncProduct($product);
        if (($result['action'] ?? '') === 'created') {
            $created++;
        } else {
            $updated++;
        }
    } catch (Throwable $e) {
        $errors++;
    }
}

setSetting('zeparfums_last_sync_at', date('c'));
setSetting('zeparfums_last_sync_count', (string)count($products));

echo json_encode([
    'scraped' => count($products),
    'created' => $created,
    'updated' => $updated,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
