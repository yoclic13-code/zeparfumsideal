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
$brandResult = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $limit = max(1, min(500, (int)($_POST['limit'] ?? 100)));
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $action = $_POST['action'] ?? 'import';

    try {
        if ($action === 'enrich') {
            $enrichResult = $importer->enrichCatalogFromApi($limit, $offset);
        } elseif ($action === 'scrape_brand_enrich') {
            @set_time_limit(0);
            $brand = trim((string)($_POST['brand_name'] ?? ''));
            $brandLimit = max(1, min(40, (int)($_POST['brand_limit'] ?? 10)));
            $brandResult = $importer->scrapeBrandAndEnrich($brand, $brandLimit);
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

$withNotes = 0;
try {
    $withNotes = (int)getDb()->query(
        "SELECT COUNT(*) FROM perfumes WHERE is_active=1
         AND top_notes IS NOT NULL AND top_notes NOT IN ('','[]','null')"
    )->fetchColumn();
} catch (Throwable $e) {
    // ignore
}

$brandsMissing = [];
try {
    $brandsMissing = getDb()->query(
        "SELECT brand,
                SUM(CASE WHEN top_notes IS NULL OR top_notes IN ('','[]','null') THEN 1 ELSE 0 END) AS sans_notes
         FROM perfumes WHERE is_active=1 AND brand IS NOT NULL AND brand!=''
         GROUP BY brand HAVING sans_notes > 0
         ORDER BY sans_notes DESC LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $brandsMissing = [];
}
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
    <span><?= $totalPerfumes ?> parfum(s) — <?= (int)$withNotes ?> avec notes</span>
  </div>
  <?php renderAdminNav('import'); ?>

  <div class="admin-card">
    <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($result !== null): ?>
      <p style="color:#2f7a2f;">Import terminé : <?= (int)$result['imported'] ?> parfum(s) importé(s)/créé(s) sur <?= (int)$result['total_seen'] ?> reçu(s) de l'API.</p>
    <?php endif; ?>
    <?php if ($enrichResult !== null): ?>
      <p style="color:#2f7a2f;">Enrichissement terminé : <?= (int)$enrichResult['matched'] ?> parfum(s) du catalogue enrichi(s) en notes olfactives sur <?= (int)$enrichResult['seen'] ?> reçu(s) de l'API.</p>
    <?php endif; ?>
    <?php if ($brandResult !== null): ?>
      <p style="color:#2f7a2f;">
        Scrape <strong><?= e($brandResult['brand']) ?></strong> :
        <?= (int)$brandResult['scraped'] ?> reçu(s) de PerfumAPI →
        <?= (int)$brandResult['matched'] ?> concordance(s) enrichie(s) en notes.
        <?php if ($brandResult['message'] !== ''): ?><br><span style="color:var(--gray);font-size:0.9rem;"><?= e($brandResult['message']) ?></span><?php endif; ?>
      </p>
    <?php endif; ?>

    <h2 style="font-size:1.05rem;margin-bottom:0.8rem;">Enrichir les concordances (notes) — marque par marque</h2>
    <p style="color:var(--gray);font-size:0.9rem;margin-bottom:1rem;">
      PerfumAPI scrape Fragrantica pour la marque, puis on matche avec ton catalogue boutique
      (prix/images inchangés). Prérequis : PerfumAPI allumé sur <code><?= e(PERFUM_API_BASE_URL) ?></code>.
      Compte ~15–20 s par parfum scrapé.
    </p>
    <form method="POST" class="admin-form" style="display:grid;grid-template-columns:2fr 1fr auto;gap:1rem;align-items:end;">
      <input type="hidden" name="action" value="scrape_brand_enrich">
      <div>
        <label>Marque (priorité : celles sans notes)</label>
        <select name="brand_name" required>
          <option value="">Choisir…</option>
          <?php foreach ($brandsMissing as $b): ?>
            <option value="<?= e($b['brand']) ?>"><?= e($b['brand']) ?> (<?= (int)$b['sans_notes'] ?> sans notes)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Nb à scraper</label>
        <input type="number" name="brand_limit" value="10" min="1" max="40">
      </div>
      <div>
        <button type="submit" class="btn-primary" style="background:#4a6741;">Scraper + enrichir</button>
      </div>
    </form>
  </div>

  <div class="admin-card">
    <h2 style="font-size:1.05rem;margin-bottom:0.8rem;">Import / enrichissement paginé (base PerfumAPI)</h2>
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
      <strong>Scraper + enrichir</strong> est la voie recommandée tant que Supabase PerfumAPI est vide :
      on utilise la réponse du scrape directement pour les concordances.
      <strong>Enrichir le catalogue existant</strong> (paginé) ne marche que si PerfumAPI a déjà des parfums en base.
    </p>
  </div>
</div>
</body>
</html>
