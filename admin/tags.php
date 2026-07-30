<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';

requireAdmin();

$db = getDb();
$repo = new PerfumeRepository($db);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = strtolower(trim(cleanInput($_POST['name'] ?? '')));
    $label = cleanInput($_POST['label_fr'] ?? '');
    $type = cleanInput($_POST['type'] ?? 'general');

    if ($name !== '' && $label !== '') {
        $stmt = $db->prepare("INSERT INTO tags (name, label_fr, type) VALUES (:name, :label, :type)
                               ON DUPLICATE KEY UPDATE label_fr = VALUES(label_fr), type = VALUES(type)");
        $stmt->execute(['name' => $name, 'label' => $label, 'type' => $type]);
        $message = 'Tag enregistré.';
    }
}

$tags = $repo->getAllTags();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tags — Administration</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Tags</h1>
    <span><?= count($tags) ?> tag(s)</span>
  </div>
  <nav class="admin-nav">
    <a href="perfumes.php">Parfums</a>
    <a href="import.php">Import API</a>
    <a href="import-csv.php">Import CSV</a>
    <a href="tags.php" class="active">Tags</a>
    <a href="settings.php">Réglages</a>
  </nav>

  <?php if ($message): ?><p style="color:#2f7a2f;"><?= e($message) ?></p><?php endif; ?>

  <div class="admin-card">
    <form method="POST" class="admin-form" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:1rem;align-items:end;">
      <div>
        <label>Nom technique (ex: frais)</label>
        <input type="text" name="name" required>
      </div>
      <div>
        <label>Libellé FR</label>
        <input type="text" name="label_fr" required>
      </div>
      <div>
        <label>Type</label>
        <select name="type">
          <option value="family">Famille olfactive</option>
          <option value="mood">Ambiance</option>
          <option value="intensity">Intensité</option>
          <option value="season">Saison</option>
          <option value="occasion">Occasion</option>
          <option value="gender">Genre</option>
          <option value="note">Note</option>
          <option value="general">Général</option>
        </select>
      </div>
      <div>
        <button type="submit" class="btn-primary">Ajouter</button>
      </div>
    </form>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead><tr><th>Nom</th><th>Libellé</th><th>Type</th></tr></thead>
      <tbody>
        <?php foreach ($tags as $t): ?>
          <tr><td><?= e($t['name']) ?></td><td><?= e($t['label_fr']) ?></td><td><?= e($t['type']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
