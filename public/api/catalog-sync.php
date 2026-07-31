<?php
/**
 * Webhook d'entrée catalogue (PrestaShop → Trouvez Votre Parfum).
 *
 * POST JSON + header Authorization: Bearer <CATALOG_SYNC_API_KEY>
 *
 * Actions :
 *   sync        — créer / mettre à jour un produit (défaut)
 *   deactivate  — désactiver (suppression ou désactivation PrestaShop)
 *   ping        — test de connectivité
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée. Utilisez POST.']);
    exit;
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/PerfumeRepository.php';
require_once __DIR__ . '/../../classes/CatalogSyncService.php';

$configuredKey = defined('CATALOG_SYNC_API_KEY') ? (string)CATALOG_SYNC_API_KEY : '';
if ($configuredKey === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Sync désactivée : CATALOG_SYNC_API_KEY non configurée.']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';
$providedKey = '';

if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authHeader, $m)) {
    $providedKey = $m[1];
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $providedKey = (string)$_SERVER['HTTP_X_API_KEY'];
}

if ($providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Clé API invalide.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide.']);
    exit;
}

$action = strtolower(trim((string)($payload['action'] ?? 'sync')));
$items = isset($payload['products']) && is_array($payload['products'])
    ? $payload['products']
    : [$payload];

try {
    $db = getDb();
    $service = new CatalogSyncService(new PerfumeRepository($db));
    $results = [];

    if ($action === 'ping') {
        echo json_encode(['ok' => true, 'action' => 'ping', 'message' => 'Catalogue sync prêt.']);
        exit;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemAction = strtolower(trim((string)($item['action'] ?? $action)));
        if ($itemAction === 'deactivate' || $itemAction === 'delete') {
            $results[] = $service->deactivate($item);
        } else {
            $results[] = $service->syncProduct($item);
        }
    }

    if (count($results) === 1) {
        echo json_encode(['ok' => true] + $results[0]);
    } else {
        echo json_encode(['ok' => true, 'count' => count($results), 'results' => $results]);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur lors de la synchronisation.']);
}
