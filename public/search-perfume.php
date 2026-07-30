<?php
/**
 * Endpoint AJAX : recherche un parfum, d'abord localement, puis via l'API si absent.
 * Le client (JS) n'appelle jamais l'API directement — uniquement ce script.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/PerfumApiClient.php';
require_once __DIR__ . '/../classes/ImportService.php';

header('Content-Type: application/json; charset=utf-8');

$query = cleanInput($_GET['q'] ?? '');

if (mb_strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$db = getDb();
$repo = new PerfumeRepository($db);

$results = $repo->search($query, 8);

if (empty($results)) {
    try {
        $importer = new ImportService($db);
        $ids = $importer->searchAndImport($query, 8);
        $results = [];
        foreach ($ids as $id) {
            $p = $repo->findById($id);
            if ($p) {
                $results[] = $p;
            }
        }
    } catch (Throwable $e) {
        // L'API est indisponible : on retourne simplement un résultat vide, sans casser le site.
        $results = [];
    }
}

$out = array_map(function ($p) {
    return [
        'id'        => (int)$p['id'],
        'name'      => $p['name'],
        'brand'     => $p['brand'],
        'image_url' => $p['image_url'],
        'gender'    => $p['gender'],
        'rating'    => $p['rating'],
    ];
}, $results);

echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
