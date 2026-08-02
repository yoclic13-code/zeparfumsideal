<?php
/**
 * Sync catalogue depuis zeparfums.com via scrape PHP (cookie session CSE).
 * Aucun Python / pip requis (compatible o2switch).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/CatalogSyncService.php';
require_once __DIR__ . '/../classes/ZeparfumsScraper.php';

requireAdmin();

@set_time_limit(0);
@ini_set('max_execution_time', '0');

$message = '';
$error = '';
$report = null;

$cookie = getSetting('zeparfums_cookie', '');
$categories = getSetting('zeparfums_categories', '');
$lastSyncAt = getSetting('zeparfums_last_sync_at', '');
$lastSyncCount = getSetting('zeparfums_last_sync_count', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save' || $action === 'sync') {
        $postedCookie = trim((string)($_POST['zeparfums_cookie'] ?? ''));
        $categories = trim((string)($_POST['zeparfums_categories'] ?? ''));
        setSetting('zeparfums_categories', $categories);
        if ($postedCookie !== '') {
            setSetting('zeparfums_cookie', $postedCookie);
            $cookie = $postedCookie;
        } else {
            $cookie = getSetting('zeparfums_cookie', '');
        }
    }

    if ($action === 'save') {
        $message = $cookie !== '' ? 'Cookie enregistré.' : 'Catégories enregistrées (aucun nouveau cookie).';
    }

    if ($action === 'clear_cookie') {
        setSetting('zeparfums_cookie', '');
        $cookie = '';
        $message = 'Cookie effacé.';
    }

    if ($action === 'sync') {
        if ($cookie === '') {
            $error = 'Collez d’abord le cookie de session de votre navigateur (voir instructions ci-dessous).';
        } else {
            try {
                $report = runZeparfumsScrapeSync($cookie, $categories);
                $message = 'Synchronisation terminée.';
                $lastSyncAt = getSetting('zeparfums_last_sync_at', '');
                $lastSyncCount = getSetting('zeparfums_last_sync_count', '');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$totalPerfumes = 0;
try {
    $totalPerfumes = (new PerfumeRepository(getDb()))->count();
} catch (Throwable $e) {
    // ignore
}

function runZeparfumsScrapeSync(string $cookie, string $categories): array
{
    $catList = [];
    if ($categories !== '') {
        $parts = preg_split('/[\r\n|]+/', $categories) ?: [];
        $catList = array_values(array_filter(array_map('trim', $parts)));
    }

    $scraper = new ZeparfumsScraper($cookie);
    $data = $scraper->scrape($catList);
    $products = $data['products'] ?? [];
    if (!is_array($products) || $products === []) {
        throw new RuntimeException('Aucun produit à importer.');
    }

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

    return [
        'scraped' => count($products),
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
        'categories' => $data['categories'] ?? [],
        'auth' => 'cookie',
        'engine' => 'php',
    ];
}

$cookieMasked = $cookie !== ''
    ? (mb_strlen($cookie) > 24 ? mb_substr($cookie, 0, 18) . '…' . mb_substr($cookie, -6) : '••••')
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sync ZeParfums — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Sync ZeParfums</h1>
    <span><?= (int)$totalPerfumes ?> parfum(s) en base</span>
  </div>
  <?php renderAdminNav('sync'); ?>

  <?php if ($message): ?><p style="color:#2f7a2f;"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>

  <?php if ($report !== null): ?>
  <div class="admin-card">
    <p style="color:#2f7a2f;margin:0;">
      Scrape : <strong><?= (int)$report['scraped'] ?></strong> produit(s) —
      créés <?= (int)$report['created'] ?>,
      mis à jour <?= (int)$report['updated'] ?>,
      erreurs <?= (int)$report['errors'] ?>.
    </p>
  </div>
  <?php endif; ?>

  <div class="admin-card">
    <h2 style="margin-top:0;font-family:Cormorant Garamond,serif;">Cookie de session CSE</h2>
    <p style="color:var(--gray);font-size:0.9rem;">
      Connectez-vous sur <a href="https://zeparfums.com" target="_blank" rel="noopener">zeparfums.com</a>,
      puis collez ici le cookie de votre navigateur. Le scraper réutilise <strong>votre session déjà ouverte</strong>
      (PHP pur — pas besoin de Python ni d’accès PrestaShop).
    </p>

    <ol style="color:var(--gray);line-height:1.7;padding-left:1.2rem;font-size:0.9rem;">
      <li>Ouvrez zeparfums.com et connectez-vous (espace CSE).</li>
      <li>F12 → onglet <strong>Réseau</strong> → cochez « Désactiver le cache » → rechargez (F5).</li>
      <li>Cliquez la requête HTML du site (ex. <code>2-accueil</code>, type <strong>document</strong>) — pas un CSS/font.</li>
      <li>À droite, onglet <strong>En-têtes</strong> (pas Cookies).</li>
      <li>Section <em>En-têtes de la requête</em> → ligne <code>Cookie:</code> → clic droit → Copier la valeur.</li>
      <li>Collez-la ci-dessous → Synchroniser.</li>
    </ol>

    <form method="POST" class="admin-form">
      <label>Cookie navigateur<?= $cookieMasked ? ' — enregistré : ' . e($cookieMasked) : '' ?></label>
      <textarea name="zeparfums_cookie" rows="4" placeholder="PHPSESSID=...; PrestaShop-...=...; ..."<?= $cookie === '' ? ' required' : '' ?>></textarea>
      <p style="color:var(--gray);font-size:0.85rem;margin-top:0.4rem;">
        Laissez vide pour réutiliser le cookie déjà enregistré. Les cookies expirent : si la sync échoue, recollez-en un frais.
      </p>

      <label style="margin-top:1rem;">URLs catégories (optionnel, une par ligne)</label>
      <textarea name="zeparfums_categories" rows="3" placeholder="https://zeparfums.com/2-accueil"><?= e($categories) ?></textarea>

      <div style="display:flex;gap:0.8rem;flex-wrap:wrap;margin-top:1.2rem;">
        <button type="submit" name="action" value="save" class="btn-primary" style="background:#666;">Enregistrer</button>
        <button type="submit" name="action" value="sync" class="btn-primary" id="btn-sync">
          Synchroniser le catalogue
        </button>
        <?php if ($cookie !== ''): ?>
          <button type="submit" name="action" value="clear_cookie" class="btn-primary" style="background:#a33;">Effacer le cookie</button>
        <?php endif; ?>
      </div>
    </form>
    <script>
      document.getElementById('btn-sync')?.addEventListener('click', function () {
        this.textContent = 'Sync en cours (peut prendre plusieurs minutes)…';
      });
    </script>

    <?php if ($lastSyncAt): ?>
      <p style="color:var(--gray);font-size:0.85rem;margin-top:1rem;">
        Dernière sync : <?= e($lastSyncAt) ?>
        <?= $lastSyncCount !== '' ? ' — ' . e($lastSyncCount) . ' produit(s)' : '' ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h2 style="margin-top:0;font-family:Cormorant Garamond,serif;">Important</h2>
    <ul style="color:var(--gray);line-height:1.7;">
      <li>Fonctionne sur o2switch sans <code>pip</code> / Python.</li>
      <li>Le cookie donne accès à votre session CSE : ne le partagez pas.</li>
      <li>Après sync, lancez <a href="import.php">Import API → enrichissement</a> pour les notes / quiz.</li>
    </ul>
  </div>
</div>
</body>
</html>
