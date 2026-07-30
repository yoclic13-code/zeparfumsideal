<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';

requireAdmin();

$db = getDb();
$repo = new PerfumeRepository($db);

$id = (int)($_GET['id'] ?? 0);
$perfume = $repo->findById($id);

if (!$perfume) {
    redirect('perfumes.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name'        => cleanInput($_POST['name'] ?? ''),
        'brand'       => cleanInput($_POST['brand'] ?? ''),
        'image_url'   => cleanInput($_POST['image_url'] ?? ''),
        'top_notes'   => jencode(array_filter(array_map('trim', explode(',', $_POST['top_notes'] ?? '')))),
        'middle_notes'=> jencode(array_filter(array_map('trim', explode(',', $_POST['middle_notes'] ?? '')))),
        'base_notes'  => jencode(array_filter(array_map('trim', explode(',', $_POST['base_notes'] ?? '')))),
        'accords'     => jencode(array_filter(array_map('trim', explode(',', $_POST['accords'] ?? '')))),
        'price'       => $_POST['price'] !== '' ? (float)$_POST['price'] : null,
        'product_url' => cleanInput($_POST['product_url'] ?? ''),
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
    ];

    $repo->update($id, $data);

    // Tags associés (saisis en texte "nom:poids, nom:poids")
    $tagsInput = trim($_POST['tags'] ?? '');
    if ($tagsInput !== '') {
        $tags = [];
        foreach (explode(',', $tagsInput) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            if (str_contains($pair, ':')) {
                [$name, $weight] = array_map('trim', explode(':', $pair, 2));
                $tags[] = ['name' => $name, 'weight' => (float)$weight];
            } else {
                $tags[] = ['name' => $pair, 'weight' => 1.0];
            }
        }
        $repo->setTags($id, $tags);
    }

    $perfume = $repo->findById($id);
    $message = 'Parfum mis à jour.';
}

$currentTags = $repo->getTagsForPerfume($id);
$tagsText = implode(', ', array_map(fn($t) => $t['name'] . ':' . $t['weight'], $currentTags));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Modifier <?= e($perfume['name']) ?> — Administration</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Modifier le parfum</h1>
    <a href="perfumes.php">← Retour à la liste</a>
  </div>

  <?php if ($message): ?><p style="color:#2f7a2f;"><?= e($message) ?></p><?php endif; ?>

  <div class="admin-card">
    <form method="POST" class="admin-form">
      <label>Nom</label>
      <input type="text" name="name" value="<?= e($perfume['name']) ?>" required>

      <label>Marque</label>
      <input type="text" name="brand" value="<?= e($perfume['brand']) ?>">

      <label>Image (URL)</label>
      <input type="text" name="image_url" value="<?= e($perfume['image_url']) ?>">

      <label>Notes de tête (séparées par virgules)</label>
      <input type="text" name="top_notes" value="<?= e(implode(', ', jdecode($perfume['top_notes']))) ?>">

      <label>Notes de cœur (séparées par virgules)</label>
      <input type="text" name="middle_notes" value="<?= e(implode(', ', jdecode($perfume['middle_notes']))) ?>">

      <label>Notes de fond (séparées par virgules)</label>
      <input type="text" name="base_notes" value="<?= e(implode(', ', jdecode($perfume['base_notes']))) ?>">

      <label>Accords (séparés par virgules)</label>
      <input type="text" name="accords" value="<?= e(implode(', ', jdecode($perfume['accords']))) ?>">

      <label>Prix (€)</label>
      <input type="number" step="0.01" name="price" value="<?= e($perfume['price']) ?>">

      <label>Lien produit</label>
      <input type="text" name="product_url" value="<?= e($perfume['product_url']) ?>">

      <label>Tags associés (format "nom:poids", séparés par virgules)</label>
      <input type="text" name="tags" value="<?= e($tagsText) ?>">

      <label style="display:flex;align-items:center;gap:0.6rem;margin-top:1.2rem;">
        <input type="checkbox" name="is_active" style="width:auto;" <?= $perfume['is_active'] ? 'checked' : '' ?>>
        Parfum actif (visible dans les résultats)
      </label>

      <button type="submit" class="btn-primary" style="margin-top:1.6rem;">Enregistrer</button>
    </form>
  </div>
</div>
</body>
</html>
