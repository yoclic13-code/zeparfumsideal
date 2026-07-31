<?php
/**
 * Sync catalogue depuis zeparfums.com via scrape Python.
 * Auth principale = cookie de session navigateur (compte CSE déjà connecté).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/CatalogSyncService.php';

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
    $pythonCmd = findPythonCommand();
    $script = realpath(__DIR__ . '/../scripts/scrape_zeparfums.py');
    if ($script === false) {
        throw new RuntimeException('Script scripts/scrape_zeparfums.py introuvable.');
    }

    $env = buildProcessEnv();
    $env['ZEPARFUMS_COOKIE'] = $cookie;
    $env['ZEPARFUMS_BASE_URL'] = 'https://zeparfums.com';
    // Ne pas réutiliser d'anciens identifiants par erreur
    unset($env['ZEPARFUMS_EMAIL'], $env['ZEPARFUMS_PASSWORD']);

    if ($categories !== '') {
        $parts = preg_split('/[\r\n|]+/', $categories) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));
        $env['ZEPARFUMS_CATEGORIES'] = implode('|', $parts);
    }

    $cmd = $pythonCmd . ' ' . escapeshellarg($script);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, dirname($script), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de démarrer Python. Vérifiez que Python est installé.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $stdout = trim((string)$stdout);
    if ($stdout === '') {
        $hint = trim((string)$stderr);
        throw new RuntimeException(
            'Le scraper n’a renvoyé aucun résultat.'
            . ($hint !== '' ? ' Détail : ' . $hint : '')
        );
    }

    $data = json_decode($stdout, true);
    if (!is_array($data)) {
        throw new RuntimeException('Réponse JSON invalide du scraper.');
    }
    if (empty($data['ok'])) {
        throw new RuntimeException((string)($data['error'] ?? 'Échec du scrape.'));
    }

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
        'auth' => $data['auth'] ?? 'cookie',
        'stderr' => trim((string)$stderr),
    ];
}

function buildProcessEnv(): array
{
    $env = [];
    foreach ([$_ENV, $_SERVER, getenv() ?: []] as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($source as $k => $v) {
            if (is_string($k) && is_string($v) && !array_key_exists($k, $env)) {
                $env[$k] = $v;
            }
        }
    }
    return $env;
}

function findPythonCommand(): string
{
    $candidates = [];
    if (defined('PYTHON_BINARY') && PYTHON_BINARY !== '') {
        $candidates[] = PYTHON_BINARY;
    }
    $candidates = array_merge($candidates, ['py -3', 'python', 'python3']);

    foreach ($candidates as $bin) {
        $out = [];
        $code = 0;
        @exec($bin . ' -c "print(1)" 2>&1', $out, $code);
        if ($code === 0) {
            return $bin;
        }
    }

    throw new RuntimeException(
        'Python 3 introuvable. Installez Python puis : pip install -r scripts/requirements-scrape.txt'
    );
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
  <nav class="admin-nav">
    <a href="perfumes.php">Parfums</a>
    <a href="import.php">Import API</a>
    <a href="import-csv.php">Import CSV</a>
    <a href="sync.php" class="active">Sync ZeParfums</a>
    <a href="tags.php">Tags</a>
    <a href="settings.php">Réglages</a>
    <a href="logout.php" class="admin-logout">Déconnexion</a>
  </nav>

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
      (pas besoin du mot de passe, pas d’accès PrestaShop).
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
      <li>Le cookie donne accès à votre session CSE : ne le partagez pas.</li>
      <li>Après sync, lancez <a href="import.php">Import API → enrichissement</a> pour les notes / quiz.</li>
      <li>Dépendances : <code>pip install -r scripts/requirements-scrape.txt</code></li>
    </ul>
  </div>
</div>
</body>
</html>
