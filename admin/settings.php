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
$bannerDefaults = referralBannerDefaults();
$banner = referralBannerContent();
$showBannerBtn = $banner['show_btn'];
$showBannerLink = $banner['show_link'];
$conditionsDefaults = referralConditionsDefaults();
$conditions = referralConditionsContent();
$conditionsItemsText = getSetting('referral_conditions_items', $conditionsDefaults['items']);
if (trim($conditionsItemsText) === '') {
    $conditionsItemsText = $conditionsDefaults['items'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $opacity = max(0.0, min(1.0, (float)($_POST['hero_overlay_opacity'] ?? HERO_OVERLAY_OPACITY_DEFAULT)));
    setSetting('hero_overlay_opacity', (string)$opacity);
    $postedHeroVideo = basename((string)($_POST['hero_video_file'] ?? HERO_VIDEO_DEFAULT));
    $heroVideoFile = in_array($postedHeroVideo, $heroVideos, true) ? $postedHeroVideo : heroVideoFilename();
    setSetting('hero_video_file', $heroVideoFile);
    setSetting('referral_enabled', isset($_POST['referral_enabled']) ? '1' : '0');
    setSetting('referral_discount', (string)max(0.0, (float)($_POST['referral_discount'] ?? REFERRAL_DISCOUNT_DEFAULT)));
    setSetting('whatsapp_enabled', isset($_POST['whatsapp_enabled']) ? '1' : '0');
    setSetting('referral_banner_show_btn', isset($_POST['referral_banner_show_btn']) ? '1' : '0');
    setSetting('referral_banner_show_link', isset($_POST['referral_banner_show_link']) ? '1' : '0');

    $bannerFields = [
        'referral_banner_title' => 'title',
        'referral_banner_text1' => 'text1',
        'referral_banner_text2' => 'text2',
        'referral_banner_btn_label' => 'btn_label',
        'referral_banner_btn_url' => 'btn_url',
        'referral_banner_link_label' => 'link_label',
        'referral_banner_link_url' => 'link_url',
        'referral_banner_note' => 'note',
    ];
    foreach ($bannerFields as $settingKey => $field) {
        $raw = (string)($_POST[$settingKey] ?? '');
        if ($field === 'btn_url' || $field === 'link_url') {
            $raw = sanitizeExternalUrl(trim($raw), $bannerDefaults[$field]);
        } else {
            $raw = trim($raw);
            if ($raw === '') {
                $raw = $bannerDefaults[$field];
            }
        }
        setSetting($settingKey, $raw);
    }

    $conditionFields = [
        'referral_conditions_title' => 'title',
        'referral_conditions_items' => 'items',
        'referral_conditions_note' => 'note',
        'referral_price_label' => 'price_label',
        'referral_conditions_trigger' => 'trigger',
    ];
    foreach ($conditionFields as $settingKey => $field) {
        $raw = (string)($_POST[$settingKey] ?? '');
        $raw = trim($raw);
        if ($raw === '') {
            $raw = $conditionsDefaults[$field];
        }
        setSetting($settingKey, $raw);
    }

    $referralEnabled = isset($_POST['referral_enabled']);
    $referralDiscount = max(0.0, (float)($_POST['referral_discount'] ?? REFERRAL_DISCOUNT_DEFAULT));
    $whatsappEnabled = isset($_POST['whatsapp_enabled']);
    $banner = referralBannerContent();
    $showBannerBtn = $banner['show_btn'];
    $showBannerLink = $banner['show_link'];
    $conditions = referralConditionsContent();
    $conditionsItemsText = getSetting('referral_conditions_items', $conditionsDefaults['items']);
    if (trim($conditionsItemsText) === '') {
        $conditionsItemsText = $conditionsDefaults['items'];
    }
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
  <?php renderAdminNav('settings'); ?>

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
        Le pourcentage « -<?= (int)$referralDiscount ?>% » s’affiche aussi dans le badge prix et via <code>{discount}</code> dans les textes.
      </p>
  </div>

  <div class="admin-card">
    <h2 style="margin:0 0 0.5rem;">Bulle « Voir conditions » (sous les prix)</h2>
    <p style="color:var(--gray);margin:0 0 1.5rem;font-size:0.92rem;">
      Texte de la vignette ouverte au clic sur « Voir conditions ».
      Une ligne = un point de la liste. Utilisez <code>{discount}</code> pour le pourcentage.
      Balises : <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;sup&gt;</code>.
    </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div>
          <label for="referral_price_label">Libellé prix (ex. Parrain / Filleul)</label>
          <input type="text" id="referral_price_label" name="referral_price_label" value="<?= e($conditions['price_label']) ?>">
        </div>
        <div>
          <label for="referral_conditions_trigger">Lien d’ouverture</label>
          <input type="text" id="referral_conditions_trigger" name="referral_conditions_trigger" value="<?= e($conditions['trigger']) ?>">
        </div>
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_conditions_title">Titre de la bulle</label>
        <input type="text" id="referral_conditions_title" name="referral_conditions_title" value="<?= e($conditions['title']) ?>">
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_conditions_items">Points de la liste (un par ligne)</label>
        <textarea id="referral_conditions_items" name="referral_conditions_items" rows="5"><?= e($conditionsItemsText) ?></textarea>
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_conditions_note">Note en bas de la bulle</label>
        <textarea id="referral_conditions_note" name="referral_conditions_note" rows="3"><?= e($conditions['note']) ?></textarea>
      </div>
  </div>

  <div class="admin-card">
    <h2 style="margin:0 0 0.5rem;">Vignette parrainage (textes &amp; liens)</h2>
    <p style="color:var(--gray);margin:0 0 1.5rem;font-size:0.92rem;">
      Modifiez ici tout le contenu de la vignette affichée sur la page résultat.
      Utilisez <code>{discount}</code> pour insérer automatiquement le pourcentage.
      Balises HTML autorisées : <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;sup&gt;</code>.
    </p>

      <div>
        <label for="referral_banner_title">Titre</label>
        <input type="text" id="referral_banner_title" name="referral_banner_title" value="<?= e($banner['title']) ?>">
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_banner_text1">Paragraphe 1</label>
        <textarea id="referral_banner_text1" name="referral_banner_text1" rows="3"><?= e($banner['text1']) ?></textarea>
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_banner_text2">Paragraphe 2</label>
        <textarea id="referral_banner_text2" name="referral_banner_text2" rows="3"><?= e($banner['text2']) ?></textarea>
      </div>

      <div style="margin-top:1.2rem;display:flex;flex-direction:column;gap:0.7rem;">
        <label class="gift-options-check" style="margin-top:0;">
          <input type="checkbox" name="referral_banner_show_btn" value="1" <?= $showBannerBtn ? 'checked' : '' ?>>
          <span>Afficher le bouton « Créer mon compte »</span>
        </label>
        <label class="gift-options-check" style="margin-top:0;">
          <input type="checkbox" name="referral_banner_show_link" value="1" <?= $showBannerLink ? 'checked' : '' ?>>
          <span>Afficher le lien « Se connecter »</span>
        </label>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;">
        <div>
          <label for="referral_banner_btn_label">Bouton principal — libellé</label>
          <input type="text" id="referral_banner_btn_label" name="referral_banner_btn_label" value="<?= e($banner['btn_label']) ?>">
        </div>
        <div>
          <label for="referral_banner_btn_url">Bouton principal — lien</label>
          <input type="url" id="referral_banner_btn_url" name="referral_banner_btn_url" value="<?= e($banner['btn_url']) ?>" placeholder="https://…">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;">
        <div>
          <label for="referral_banner_link_label">Lien secondaire — libellé</label>
          <input type="text" id="referral_banner_link_label" name="referral_banner_link_label" value="<?= e($banner['link_label']) ?>">
        </div>
        <div>
          <label for="referral_banner_link_url">Lien secondaire — lien</label>
          <input type="url" id="referral_banner_link_url" name="referral_banner_link_url" value="<?= e($banner['link_url']) ?>" placeholder="https://…">
        </div>
      </div>

      <div style="margin-top:1rem;">
        <label for="referral_banner_note">Note en bas de vignette</label>
        <textarea id="referral_banner_note" name="referral_banner_note" rows="2"><?= e($banner['note']) ?></textarea>
      </div>
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
