<?php
require_once __DIR__ . '/../config/database.php';
$db = getDb();
echo 'total: ' . $db->query('SELECT COUNT(*) FROM perfumes')->fetchColumn() . PHP_EOL;
echo 'enriched: ' . $db->query("SELECT COUNT(*) FROM perfumes WHERE top_notes IS NOT NULL AND top_notes != '' AND top_notes != '[]'")->fetchColumn() . PHP_EOL;
foreach ($db->query('SELECT endpoint, query, created_at FROM api_logs ORDER BY id DESC LIMIT 3') as $r) {
    echo $r['created_at'] . ' ' . $r['endpoint'] . ' ' . $r['query'] . PHP_EOL;
}
