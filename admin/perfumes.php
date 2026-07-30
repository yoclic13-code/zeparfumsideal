<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';

requireAdmin();

$db = getDb();
$repo = new PerfumeRepository($db);

$filters = [
    'name'      => cleanInput($_GET['name'] ?? ''),
    'brand'     => cleanInput($_GET['brand'] ?? ''),
    'gender'    => cleanInput($_GET['gender'] ?? ''),
    'is_active' => $_GET['is_active'] ?? '',
];

$page = max(1, (int)($_GET['page'] ?? 1));
$data = $repo->listForAdmin($filters, $page, 20);
$brands = $repo->distinctBrands();
$totalPages = max(1, (int)ceil($data['total'] / 20));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parfums — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Parfums</h1>
    <span><?= (int)$data['total'] ?> parfum(s)</span>
  </div>
  <nav class="admin-nav">
    <a href="perfumes.php" class="active">Parfums</a>
    <a href="import.php">Import API</a>
    <a href="import-csv.php">Import CSV</a>
    <a href="tags.php">Tags</a>
    <a href="settings.php">Réglages</a>
  
    <a href="logout.php" class="admin-logout">D&eacute;connexion</a>
  </nav>

  <div class="admin-card">
    <form method="GET" class="admin-form" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;align-items:end;">
      <div>
        <label>Nom</label>
        <input type="text" name="name" value="<?= e($filters['name']) ?>">
      </div>
      <div>
        <label>Marque</label>
        <select name="brand">
          <option value="">Toutes</option>
          <?php foreach ($brands as $b): ?>
            <option value="<?= e($b) ?>" <?= $filters['brand'] === $b ? 'selected' : '' ?>><?= e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Genre</label>
        <select name="gender">
          <option value="">Tous</option>
          <option value="homme" <?= $filters['gender']==='homme'?'selected':'' ?>>Homme</option>
          <option value="femme" <?= $filters['gender']==='femme'?'selected':'' ?>>Femme</option>
          <option value="mixte" <?= $filters['gender']==='mixte'?'selected':'' ?>>Mixte</option>
        </select>
      </div>
      <div>
        <label>Statut</label>
        <select name="is_active">
          <option value="">Tous</option>
          <option value="1" <?= $filters['is_active']==='1'?'selected':'' ?>>Actif</option>
          <option value="0" <?= $filters['is_active']==='0'?'selected':'' ?>>Inactif</option>
        </select>
      </div>
      <div style="grid-column:1/-1;">
        <button type="submit" class="btn-secondary small">Filtrer</button>
      </div>
    </form>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr><th></th><th>Nom</th><th>Marque</th><th>Genre</th><th>Rating</th><th>Statut</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($data['items'] as $p): ?>
          <tr>
            <td><?php if (!empty($p['image_url'])): ?><img src="<?= e($p['image_url']) ?>" alt="" onerror="this.onerror=null;this.src='<?= placeholderDataUri() ?>';"><?php endif; ?></td>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['brand']) ?></td>
            <td><?= e($p['gender']) ?></td>
            <td><?= e($p['rating']) ?></td>
            <td><span class="badge <?= $p['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $p['is_active'] ? 'Actif' : 'Inactif' ?></span></td>
            <td><a href="perfume-edit.php?id=<?= (int)$p['id'] ?>">Modifier</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($data['items'])): ?>
          <tr><td colspan="7">Aucun parfum trouvé.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <?php
        $windowStart = max(1, $page - 3);
        $windowEnd = min($totalPages, $page + 3);
      ?>
      <div style="margin-top:1.2rem;display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;">
        <?php if ($windowStart > 1): ?>
          <a href="?<?= http_build_query(array_merge($filters, ['page' => 1])) ?>" style="padding:0.4rem 0.8rem;border:1px solid var(--beige);border-radius:8px;">1</a>
          <?php if ($windowStart > 2): ?><span style="color:var(--gray);">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
          <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>" style="padding:0.4rem 0.8rem;border:1px solid var(--beige);border-radius:8px;<?= $i===$page?'background:var(--black);color:#fff;':'' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($windowEnd < $totalPages): ?>
          <?php if ($windowEnd < $totalPages - 1): ?><span style="color:var(--gray);">…</span><?php endif; ?>
          <a href="?<?= http_build_query(array_merge($filters, ['page' => $totalPages])) ?>" style="padding:0.4rem 0.8rem;border:1px solid var(--beige);border-radius:8px;"><?= $totalPages ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
