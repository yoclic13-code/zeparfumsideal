<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/PerfumApiClient.php';
require_once __DIR__ . '/../classes/ImportService.php';

requireAdmin();

$db = getDb();
$importer = new ImportService($db);
$repo = new PerfumeRepository($db);

$result = null;
$enrichResult = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $limit = max(1, min(500, (int)($_POST['limit'] ?? 100)));
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $action = $_POST['action'] ?? 'import';

    try {
        if ($action === 'enrich') {
            $enrichResult = $importer->enrichCatalogFromApi($limit, $offset);
        } else {
            $result = $importer->importPage($limit, $offset);
        }
    } catch (Throwable $e) {
        $error = 'Opération impossible : ' . $e->getMessage();
    }
}

$totalPerfumes = $repo->count();
$lastOffset = (int)($_POST['offset'] ?? 0);
$lastLimit = (int)($_POST['limit'] ?? 100);
$nextOffset = $lastOffset + $lastLimit;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import API — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Import depuis l'API</h1>
    <span><?= $totalPerfumes ?> parfum(s) en base</span>
  </div>
  <nav class="admin-nav">
    <a href="perfumes.php">Parfums</a>
    <a href="import.php" class="active">Import API</a>
    <a href="import-csv.php">Import CSV</a>
    <a href="sync.php">Sync ZeParfums</a>
    <a href="tags.php">Tags</a>
    <a href="settings.php">Réglages</a>
  
    <a href="logout.php" class="admin-logout">D&eacute;connexion</a>
  </nav>

  <div class="admin-card">
    <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($result !== null): ?>
      <p style="color:#2f7a2f;">Import terminé : <?= (int)$result['imported'] ?> parfum(s) importé(s)/créé(s) sur <?= (int)$result['total_seen'] ?> reçu(s) de l'API.</p>
    <?php endif; ?>
    <?php if ($enrichResult !== null): ?>
      <p style="color:#2f7a2f;">Enrichissement terminé : <?= (int)$enrichResult['matched'] ?> parfum(s) du catalogue enrichi(s) en notes olfactives sur <?= (int)$enrichResult['seen'] ?> reçu(s) de l'API.</p>
    <?php endif; ?>

    <form method="POST" class="admin-form" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:1rem;align-items:end;">
      <div>
        <label>Offset</label>
        <input type="number" name="offset" value="0" min="0">
      </div>
      <div>
        <label>Limite</label>
        <input type="number" name="limit" value="100" min="1" max="500">
      </div>
      <div>
        <label>Action</label>
        <select name="action">
          <option value="import">Créer de nouveaux parfums</option>
          <option value="enrich">Enrichir le catalogue existant (notes olfactives seulement)</option>
        </select>
      </div>
      <div>
        <button type="submit" class="btn-primary">Lancer</button>
      </div>
    </form>

    <?php if ($result !== null || $enrichResult !== null): ?>
      <form method="POST" style="margin-top:1rem;">
        <input type="hidden" name="offset" value="<?= (int)$nextOffset ?>">
        <input type="hidden" name="limit" value="<?= (int)$lastLimit ?>">
        <input type="hidden" name="action" value="<?= $enrichResult !== null ? 'enrich' : 'import' ?>">
        <button type="submit" class="btn-secondary small">Continuer (offset <?= (int)$nextOffset ?>)</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <p style="color:var(--gray);font-size:0.9rem;">
      L'URL de l'API est configurée dans <code>config/app.php</code> (constante <code>PERFUM_API_BASE_URL</code>).
      <strong>Créer de nouveaux parfums</strong> ajoute les parfums reçus de l'API comme nouvelles entrées
      (doublons évités via <code>api_id</code>).
      <strong>Enrichir le catalogue existant</strong> ne crée jamais de nouveau parfum : il recherche une
      correspondance par nom/marque parmi vos parfums déjà importés (ex : via <a href="import-csv.php">Import CSV</a>)
      et complète uniquement leurs notes, accords, genre et tags — vos images, prix et liens produit réels
      restent inchangés.
    </p>
  </div>
</div>
</body>
</html>
