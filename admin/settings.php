<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$message = '';
$opacity = heroOverlayOpacity();
$heroVideos = heroVideoOptions();
$heroVideoFile = heroVideoFilename();
$referralEnabled = referralEnabled();
$referralDiscount = referralDiscountAmount();
$whatsappEnabled = whatsappButtonEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $opacity = max(0.0, min(1.0, (float)($_POST['hero_overlay_opacity'] ?? HERO_OVERLAY_OPACITY_DEFAULT)));
    setSetting('hero_overlay_opacity', (string)$opacity);
    $postedHeroVideo = basename((string)($_POST['hero_video_file'] ?? HERO_VIDEO_DEFAULT));
    $heroVideoFile = in_array($postedHeroVideo, $heroVideos, true) ? $postedHeroVideo : heroVideoFilename();
    setSetting('hero_video_file', $heroVideoFile);
    setSetting('referral_enabled', isset($_POST['referral_enabled']) ? '1' : '0');
    setSetting('referral_discount', (string)max(0.0, (float)($_POST['referral_discount'] ?? REFERRAL_DISCOUNT_DEFAULT)));
    setSetting('whatsapp_enabled', isset($_POST['whatsapp_enabled']) ? '1' : '0');
    $referralEnabled = isset($_POST['referral_enabled']);
    $referralDiscount = max(0.0, (float)($_POST['referral_discount'] ?? REFERRAL_DISCOUNT_DEFAULT));
    $whatsappEnabled = isset($_POST['whatsapp_enabled']);
    $message = 'Réglages enregistrés.';
}

$opacityPercent = (int)round($opacity * 100);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Réglages — Administration</title>
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-wrap">
  <div class="admin-header">
    <h1>Réglages du site</h1>
    <a href="<?= e(rtrim(SITE_URL, '/')) ?>" target="_blank">Voir la page d'accueil ↗</a>
  </div>
  <nav class="admin-nav">
    <a href="perfumes.php">Parfums</a>
    <a href="import.php">Import API</a>
    <a href="import-csv.php">Import CSV</a>
    <a href="tags.php">Tags</a>
    <a href="settings.php" class="active">Réglages</a>
  
    <a href="logout.php" class="admin-logout">Déconnexion</a>
  </nav>

  <?php if ($message): ?><p style="color:#2f7a2f;"><?= e($message) ?></p><?php endif; ?>

  <form method="POST" class="admin-form">
  <div class="admin-card">
    <h2 style="margin:0 0 0.5rem;">Vidéo d'accueil</h2>
    <p style="color:var(--gray);margin:0 0 1.5rem;font-size:0.92rem;">
      Ajustez le voile noir par-dessus la vidéo pour améliorer la lisibilité du texte.
    </p>

      <div>
        <label for="hero_video_file">Vidéo utilisée en arrière-plan</label>
        <select id="hero_video_file" name="hero_video_file">
          <?php if (!empty($heroVideos)): ?>
            <?php foreach ($heroVideos as $videoName): ?>
              <option value="<?= e($videoName) ?>" <?= $heroVideoFile === $videoName ? 'selected' : '' ?>><?= e($videoName) ?></option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="<?= e(HERO_VIDEO_DEFAULT) ?>"><?= e(HERO_VIDEO_DEFAULT) ?> (par défaut)</option>
          <?php endif; ?>
        </select>
        <p style="color:var(--gray);font-size:0.82rem;margin:0.5rem 0 0;">
          Placez vos vidéos dans <code>public/assets/video</code>, puis sélectionnez-la ici.
        </p>
      </div>

      <div style="margin-top:1rem;">
        <label for="hero_overlay_opacity">Opacité du voile noir — <strong id="opacityValue"><?= $opacityPercent ?> %</strong></label>
        <input
          type="range"
          id="hero_overlay_opacity"
          name="hero_overlay_opacity"
          min="0.20"
          max="0.85"
          step="0.01"
          value="<?= e((string)$opacity) ?>"
          style="width:100%;margin-top:0.5rem;"
        >
        <p style="color:var(--gray);font-size:0.82rem;margin:0.5rem 0 0;">
          20 % = vidéo très visible · 55 % = équilibré · 85 % = texte très lisible
        </p>
      </div>

      <div class="settings-preview" aria-hidden="true">
        <div class="settings-preview-video"></div>
        <div class="settings-preview-overlay" id="previewOverlay"></div>
        <span class="settings-preview-label">Aperçu</span>
      </div>
  </div>

  <div class="admin-card">
    <h2 style="margin:0 0 0.5rem;">Parrainage (page résultat)</h2>
    <p style="color:var(--gray);margin:0 0 1.5rem;font-size:0.92rem;">
      Affiche le bandeau et les prix estimés avec réduction sur les recommandations du quiz.
    </p>

      <label class="gift-options-check" style="margin-top:0;">
        <input type="checkbox" name="referral_enabled" value="1" <?= $referralEnabled ? 'checked' : '' ?>>
        <span>Activer le bandeau et les prix parrainage</span>
      </label>

      <div>
        <label for="referral_discount">Pourcentage de réduction affiché (%)</label>
        <input
          type="number"
          id="referral_discount"
          name="referral_discount"
          min="0"
          max="100"
          step="1"
          value="<?= e((string)(int)$referralDiscount) ?>"
        >
      </div>

      <p style="color:var(--gray);font-size:0.82rem;margin:0.8rem 0 0;line-height:1.5;">
        Texte affiché : « Parrain / Filleul -<?= (int)$referralDiscount ?>% »
      </p>
  </div>

  <div class="admin-card">
    <h2 style="margin:0 0 0.5rem;">Bouton WhatsApp (page résultat)</h2>
    <p style="color:var(--gray);margin:0 0 1.5rem;font-size:0.92rem;">
      Affiche le bouton « Commander sur WhatsApp » sur les fiches parfum.
    </p>

      <label class="gift-options-check" style="margin-top:0;">
        <input type="checkbox" name="whatsapp_enabled" value="1" <?= $whatsappEnabled ? 'checked' : '' ?>>
        <span>Activer le bouton WhatsApp</span>
      </label>
  </div>

  <div style="margin-bottom:2rem;">
    <button type="submit" class="btn-primary">Enregistrer les réglages</button>
  </div>
  </form>
</div>

<script>
(function () {
  var input = document.getElementById('hero_overlay_opacity');
  var label = document.getElementById('opacityValue');
  var preview = document.getElementById('previewOverlay');

  function update() {
    var value = parseFloat(input.value);
    label.textContent = Math.round(value * 100) + ' %';
    preview.style.background = 'rgba(0, 0, 0, ' + value + ')';
  }

  input.addEventListener('input', update);
  update();
})();
</script>
</body>
</html>
