<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';

requireAdmin();
ensurePerfumeSearchesTable();

$db = getDb();
$repo = new PerfumeRepository($db);

function countSafe(PDO $db, string $sql, array $params = []): int
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$stats = [
    'quiz_total' => countSafe($db, 'SELECT COUNT(*) FROM quiz_sessions'),
    'quiz_classic' => countSafe($db, "SELECT COUNT(*) FROM quiz_sessions WHERE path_type = 'quiz'"),
    'quiz_favorite' => countSafe($db, "SELECT COUNT(*) FROM quiz_sessions WHERE path_type = 'favorite'"),
    'quiz_today' => countSafe($db, 'SELECT COUNT(*) FROM quiz_sessions WHERE DATE(created_at) = CURDATE()'),
    'quiz_7d' => countSafe($db, 'SELECT COUNT(*) FROM quiz_sessions WHERE created_at >= (NOW() - INTERVAL 7 DAY)'),
    'quiz_30d' => countSafe($db, 'SELECT COUNT(*) FROM quiz_sessions WHERE created_at >= (NOW() - INTERVAL 30 DAY)'),
    'searches_total' => countSafe($db, 'SELECT COUNT(*) FROM perfume_searches'),
    'searches_today' => countSafe($db, 'SELECT COUNT(*) FROM perfume_searches WHERE DATE(created_at) = CURDATE()'),
    'searches_7d' => countSafe($db, 'SELECT COUNT(*) FROM perfume_searches WHERE created_at >= (NOW() - INTERVAL 7 DAY)'),
    'searches_unique' => countSafe($db, 'SELECT COUNT(DISTINCT query) FROM perfume_searches'),
    'perfumes_active' => countSafe($db, 'SELECT COUNT(*) FROM perfumes WHERE is_active = 1'),
    'perfumes_total' => $repo->count(),
];

$topSearches = [];
try {
    $stmt = $db->query(
        'SELECT query, COUNT(*) AS total, MAX(created_at) AS last_at
         FROM perfume_searches
         GROUP BY query
         ORDER BY total DESC, last_at DESC
         LIMIT 10'
    );
    $topSearches = $stmt->fetchAll();
} catch (Throwable $e) {
    $topSearches = [];
}

$recentQuizzes = [];
try {
    $stmt = $db->query(
        "SELECT qs.id, qs.path_type, qs.created_at, p.name AS result_name, p.brand AS result_brand
         FROM quiz_sessions qs
         LEFT JOIN perfumes p ON p.id = qs.result_perfume_id
         ORDER BY qs.created_at DESC
         LIMIT 10"
    );
    $recentQuizzes = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentQuizzes = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Dashboard</h1>
    <a href="<?= e(rtrim(SITE_URL, '/')) ?>" target="_blank">Voir la page d'accueil ↗</a>
  </div>

  <?php renderAdminNav('dashboard'); ?>

  <div class="admin-stats-grid">
    <div class="admin-stat-card">
      <p class="admin-stat-label">Quiz générés</p>
      <p class="admin-stat-value"><?= number_format($stats['quiz_total'], 0, ',', ' ') ?></p>
      <p class="admin-stat-meta">Aujourd'hui : <?= (int)$stats['quiz_today'] ?> · 7 j : <?= (int)$stats['quiz_7d'] ?></p>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-label">Parfums recherchés</p>
      <p class="admin-stat-value"><?= number_format($stats['searches_total'], 0, ',', ' ') ?></p>
      <p class="admin-stat-meta">Aujourd'hui : <?= (int)$stats['searches_today'] ?> · Uniques : <?= (int)$stats['searches_unique'] ?></p>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-label">Quiz classiques</p>
      <p class="admin-stat-value"><?= number_format($stats['quiz_classic'], 0, ',', ' ') ?></p>
      <p class="admin-stat-meta">Parfum aimé : <?= number_format($stats['quiz_favorite'], 0, ',', ' ') ?></p>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-label">Catalogue</p>
      <p class="admin-stat-value"><?= number_format($stats['perfumes_active'], 0, ',', ' ') ?></p>
      <p class="admin-stat-meta">Actifs / <?= number_format($stats['perfumes_total'], 0, ',', ' ') ?> total</p>
    </div>
  </div>

  <div class="admin-dashboard-columns">
    <div class="admin-card">
      <h2 style="margin:0 0 1rem;">Derniers quiz</h2>
      <?php if (empty($recentQuizzes)): ?>
        <p style="color:var(--gray);margin:0;">Aucun quiz enregistré pour le moment.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Parcours</th>
              <th>Résultat</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentQuizzes as $row): ?>
              <tr>
                <td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td>
                <td><?= $row['path_type'] === 'favorite' ? 'Parfum aimé' : 'Quiz' ?></td>
                <td>
                  <?php if (!empty($row['result_name'])): ?>
                    <?= e($row['result_name']) ?>
                    <?php if (!empty($row['result_brand'])): ?>
                      <span style="color:var(--gray);"> — <?= e($row['result_brand']) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="color:var(--gray);">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="admin-card">
      <h2 style="margin:0 0 1rem;">Top recherches</h2>
      <?php if (empty($topSearches)): ?>
        <p style="color:var(--gray);margin:0;">Aucune recherche enregistrée pour le moment.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Recherche</th>
              <th>Nb</th>
              <th>Dernière</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topSearches as $row): ?>
              <tr>
                <td><?= e($row['query']) ?></td>
                <td><?= (int)$row['total'] ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($row['last_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
