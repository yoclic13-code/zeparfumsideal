<?php
/**
 * Import du catalogue réel (CSV zeparfums.com) : nom, prix, image, lien produit.
 * Source de vérité pour l'inventaire réellement vendu (contrairement à PerfumAPI qui ne fournit
 * que des données olfactives génériques). Le format attendu (séparateur ";") :
 * name;brand;price;reference;image_url;product_url;description
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/CatalogSyncService.php';

requireAdmin();

/** Marques composées de plusieurs mots, reconnues en priorité (ordre = priorité de correspondance). */
const KNOWN_MULTI_WORD_BRANDS = [
    'MAISON FRANCIS KURKDJIAN', 'PARFUMS DE MARLY', 'JEAN PAUL GAULTIER', 'NARCISO RODRIGUEZ',
    'ZADIG & VOLTAIRE', 'VAN CLEEF & ARPELS', 'GIORGIO BEVERLY HILLS', 'GIORGIO ARMANI',
    'ELIZABETH ARDEN', 'ESTÉE LAUDER', 'HUGO BOSS', 'JIMMY CHOO', 'JO MALONE', 'KARL LAGERFELD',
    'PACO RABANNE', 'TOM FORD', 'TOMMY HILFIGER', 'SERGE LUTENS', 'FRANCK BOCLET',
    'PALOMA PICASSO', 'CAROLINA HERRERA', "PENHALIGON'S",
];

/** Préfixes de type de produit à ignorer avant détection de marque (ex: "Coffret Dior..."). */
const IGNORED_PREFIXES = ['COFFRET', 'ETUI'];

function detectBrand(string $name): string
{
    $clean = trim($name);
    $upper = mb_strtoupper($clean);

    foreach (IGNORED_PREFIXES as $prefix) {
        if (str_starts_with($upper, $prefix . ' ')) {
            $clean = trim(mb_substr($clean, mb_strlen($prefix)));
            $upper = mb_strtoupper($clean);
            break;
        }
    }

    foreach (KNOWN_MULTI_WORD_BRANDS as $brand) {
        if (str_starts_with($upper, $brand)) {
            return ucwords(mb_strtolower($brand));
        }
    }

    $firstWord = strtok($clean, ' ');
    return $firstWord !== false ? ucfirst(mb_strtolower($firstWord)) : 'Inconnu';
}

function detectGender(string $name): string
{
    return CatalogSyncService::detectGender($name);
}

function parsePrice(string $raw): ?float
{
    return parseShopPrice($raw);
}

$db = getDb();
$repo = new PerfumeRepository($db);

$report = null;
$genderReport = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gender_csv_file'])) {
    $file = $_FILES['gender_csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors du téléversement du fichier genres.";
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $error = "Impossible de lire le fichier envoyé.";
        } else {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle, 0, ';');
            if ($header === false) {
                $header = fgetcsv($handle, 0, ',');
            }

            $sep = ';';
            $headerMap = [];
            if (is_array($header)) {
                // Détecte le séparateur si Excel a utilisé des virgules.
                if (count($header) === 1 && str_contains((string)$header[0], ',')) {
                    rewind($handle);
                    $bom = fread($handle, 3);
                    if ($bom !== "\xEF\xBB\xBF") {
                        rewind($handle);
                    }
                    $header = fgetcsv($handle, 0, ',');
                    $sep = ',';
                }
                foreach ($header as $i => $col) {
                    $headerMap[strtolower(trim((string)$col))] = $i;
                }
            }

            $updated = 0;
            $skipped = 0;

            while (($row = fgetcsv($handle, 0, $sep)) !== false) {
                if (!is_array($row) || $row === []) {
                    $skipped++;
                    continue;
                }

                $id = 0;
                $gender = '';

                if (isset($headerMap['id'], $headerMap['gender'])) {
                    $id = (int)trim((string)($row[$headerMap['id']] ?? ''));
                    $gender = strtolower(trim((string)($row[$headerMap['gender']] ?? '')));
                } else {
                    // Sans en-tête : id;gender ou id;name;brand;gender
                    $id = (int)trim((string)($row[0] ?? ''));
                    $gender = strtolower(trim((string)($row[3] ?? $row[1] ?? '')));
                }

                if ($gender === 'men' || $gender === 'man' || $gender === 'masculin') {
                    $gender = 'homme';
                } elseif ($gender === 'women' || $gender === 'woman' || $gender === 'feminin' || $gender === 'féminin') {
                    $gender = 'femme';
                } elseif ($gender === 'unisex' || $gender === 'unisexe') {
                    $gender = 'mixte';
                }

                if ($id <= 0 || !in_array($gender, ['homme', 'femme', 'mixte'], true)) {
                    $skipped++;
                    continue;
                }

                $existing = $repo->findById($id);
                if (!$existing) {
                    $skipped++;
                    continue;
                }

                if (strtolower((string)($existing['gender'] ?? '')) === $gender) {
                    $skipped++;
                    continue;
                }

                $repo->updateGenderOnly($id, $gender);

                $existingTags = $repo->getTagsForPerfume($id);
                $tagMap = [];
                foreach ($existingTags as $t) {
                    $n = strtolower((string)$t['name']);
                    if (in_array($n, ['homme', 'femme', 'mixte'], true)) {
                        continue;
                    }
                    $tagMap[$t['name']] = (float)$t['weight'];
                }
                $tagMap[$gender] = 2.0;
                $payload = [];
                foreach ($tagMap as $name => $weight) {
                    $payload[] = ['name' => $name, 'weight' => $weight];
                }
                if ($payload !== []) {
                    $repo->setTags($id, $payload);
                }

                $updated++;
            }

            fclose($handle);
            $genderReport = ['updated' => $updated, 'skipped' => $skipped];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors du téléversement du fichier.";
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $error = "Impossible de lire le fichier envoyé.";
        } else {
            // Retire le BOM UTF-8 éventuel en tête de fichier.
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle, 0, ';');
            $imported = 0;
            $skipped = 0;

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) < 6 || trim($row[0]) === '') {
                    $skipped++;
                    continue;
                }

                [$name, $brandCol, $priceRaw, , $imageUrl, $productUrl] = array_pad($row, 7, '');
                $name = trim($name);
                $brand = trim($brandCol) !== '' ? trim($brandCol) : detectBrand($name);
                $price = parsePrice($priceRaw);
                $gender = detectGender($name);
                $apiId = 'csv-' . md5($productUrl !== '' ? $productUrl : $name);

                $repo->upsert([
                    'api_id'       => $apiId,
                    'name'         => $name,
                    'brand'        => $brand,
                    'gender'       => $gender,
                    'release_year' => null,
                    'top_notes'    => jencode([]),
                    'middle_notes' => jencode([]),
                    'base_notes'   => jencode([]),
                    'accords'      => jencode([]),
                    'rating'       => null,
                    'votes'        => 0,
                    'longevity'    => null,
                    'sillage'      => null,
                    'image_url'    => $imageUrl !== '' ? $imageUrl : null,
                    'source_url'   => null,
                    'description'  => null,
                    'price'        => $price,
                    'product_url'  => $productUrl !== '' ? $productUrl : null,
                    'is_active'    => 1,
                ]);

                $imported++;
            }

            fclose($handle);
            $report = ['imported' => $imported, 'skipped' => $skipped];
        }
    }
}

$totalPerfumes = $repo->count();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import catalogue CSV — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Import catalogue (CSV)</h1>
    <span><?= $totalPerfumes ?> parfum(s) en base</span>
  </div>
  <?php renderAdminNav('import-csv'); ?>

  <div class="admin-card">
    <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($report !== null): ?>
      <p style="color:#2f7a2f;">Import terminé : <?= (int)$report['imported'] ?> parfum(s) importé(s)/mis à jour, <?= (int)$report['skipped'] ?> ligne(s) ignorée(s).</p>
    <?php endif; ?>
    <?php if ($genderReport !== null): ?>
      <p style="color:#2f7a2f;">Genres mis à jour : <?= (int)$genderReport['updated'] ?> — ignorés : <?= (int)$genderReport['skipped'] ?>.</p>
    <?php endif; ?>

    <h2 style="font-size:1.1rem;margin-bottom:0.8rem;">Corriger les genres (export ChatGPT)</h2>
    <form method="POST" enctype="multipart/form-data" class="admin-form">
      <label>CSV corrigé (colonnes <code>id</code> + <code>gender</code> — format export Parfums)</label>
      <input type="file" name="gender_csv_file" accept=".csv,text/csv" required>
      <button type="submit" class="btn-primary" style="margin-top:1.2rem;background:#5a4a6b;">Importer les genres</button>
    </form>
    <p style="color:var(--gray);font-size:0.85rem;margin-top:0.8rem;">
      1) <a href="perfumes.php">Parfums → Exporter CSV</a> (filtrez d’abord les mixte si besoin)<br>
      2) Envoyez le fichier à ChatGPT : ne modifier que la colonne <code>gender</code> (<code>homme</code> / <code>femme</code> / <code>mixte</code>), garder les <code>id</code><br>
      3) Réimportez ici. Prix / images / noms ne sont pas touchés.
    </p>
  </div>

  <div class="admin-card">
    <h2 style="font-size:1.1rem;margin-bottom:0.8rem;">Import catalogue boutique</h2>
    <form method="POST" enctype="multipart/form-data" class="admin-form">
      <label>Fichier CSV (format : name;brand;price;reference;image_url;product_url;description)</label>
      <input type="file" name="csv_file" accept=".csv" required>
      <button type="submit" class="btn-primary" style="margin-top:1.2rem;">Importer le catalogue</button>
    </form>
  </div>

  <div class="admin-card">
    <p style="color:var(--gray);font-size:0.9rem;">
      L’import catalogue utilise vos vraies images, prix et liens produit. La marque est déduite automatiquement du nom si la colonne
      <code>brand</code> est vide. Les doublons sont évités grâce au lien produit ; un ré-import met à jour les
      parfums existants. Pensez ensuite à passer par <a href="import.php">Import API</a> en mode enrichissement
      pour ajouter les notes olfactives (nécessaires au moteur de recommandation).
    </p>
  </div>
</div>
</body>
</html>
