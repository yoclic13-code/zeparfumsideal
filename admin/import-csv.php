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
    $lower = mb_strtolower($name);
    if (str_contains($lower, 'homme')) return 'homme';
    if (str_contains($lower, 'femme')) return 'femme';
    return 'mixte';
}

function parsePrice(string $raw): ?float
{
    $raw = str_replace(["\xC2\xA0", ' ', '€'], '', $raw);
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? (float)$raw : null;
}

$db = getDb();
$repo = new PerfumeRepository($db);

$report = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
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
  <nav class="admin-nav">
    <a href="perfumes.php">Parfums</a>
    <a href="import.php">Import API</a>
    <a href="import-csv.php" class="active">Import CSV</a>
    <a href="sync.php">Sync ZeParfums</a>
    <a href="tags.php">Tags</a>
    <a href="settings.php">Réglages</a>
  
    <a href="logout.php" class="admin-logout">D&eacute;connexion</a>
  </nav>

  <div class="admin-card">
    <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($report !== null): ?>
      <p style="color:#2f7a2f;">Import terminé : <?= (int)$report['imported'] ?> parfum(s) importé(s)/mis à jour, <?= (int)$report['skipped'] ?> ligne(s) ignorée(s).</p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="admin-form">
      <label>Fichier CSV (format : name;brand;price;reference;image_url;product_url;description)</label>
      <input type="file" name="csv_file" accept=".csv" required>
      <button type="submit" class="btn-primary" style="margin-top:1.2rem;">Importer le catalogue</button>
    </form>
  </div>

  <div class="admin-card">
    <p style="color:var(--gray);font-size:0.9rem;">
      Cet import utilise vos vraies images, prix et liens produit (contrairement à l'import API qui ne fournit
      que des données olfactives génériques). La marque est déduite automatiquement du nom si la colonne
      <code>brand</code> est vide. Les doublons sont évités grâce au lien produit ; un ré-import met à jour les
      parfums existants. Pensez ensuite à passer par <a href="import.php">Import API</a> en mode enrichissement
      pour ajouter les notes olfactives (nécessaires au moteur de recommandation).
    </p>
  </div>
</div>
</body>
</html>
